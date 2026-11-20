import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:get/get.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// مخزن رموز أميال أعمال (منفصل عن SharedPreferences ومُشفّر في الإنتاج عبر FlutterSecureStorage).
abstract class BusinessTokenStore {
  Future<String?> read(String key);
  Future<void> write(String key, String value);
  Future<void> delete(String key);
}

class SecureBusinessTokenStore implements BusinessTokenStore {
  static const FlutterSecureStorage _storage = FlutterSecureStorage(
    aOptions: AndroidOptions(
      encryptedSharedPreferences: true,
      resetOnError: true,
    ),
    iOptions: IOSOptions(
      accessibility: KeychainAccessibility.first_unlock_this_device,
    ),
  );

  const SecureBusinessTokenStore();

  @override
  Future<String?> read(String key) => _storage.read(key: key);

  @override
  Future<void> write(String key, String value) => _storage.write(key: key, value: value);

  @override
  Future<void> delete(String key) => _storage.delete(key: key);
}

class InMemoryBusinessTokenStore implements BusinessTokenStore {
  final Map<String, String> _values = <String, String>{};

  @override
  Future<String?> read(String key) async => _values[key];

  @override
  Future<void> write(String key, String value) async {
    _values[key] = value;
  }

  @override
  Future<void> delete(String key) async {
    _values.remove(key);
  }
}

/// AMIAL-BUSINESS-UNIFY-U2 — إعداد التوجيه الاختياري لكل تاجر نحو خادم أميال أعمال.
///
/// الضوابط:
/// - الافتراض لجميع التجار هو `false` (لا يُحوَّل أي تاجر تلقائياً).
/// - رمز Bearer لأميال أعمال يُخزَّن في [BusinessTokenStore] المشفّر (`FlutterSecureStorage`)
///   وليس في `SharedPreferences` (B4)، ومنفصل تماماً عن رمز محفظة أميال باي.
/// - التراجع عن التحويل فوري عبر [disableForMerchant] ويعيد جميع الطلبات
///   اللاحقة إلى مسارات أميال باي القديمة دون فقدان الطابور غير المتصل.
class MerchantBusinessBackendConfig extends GetxService {
  static const String defaultBaseUrl = String.fromEnvironment(
    'AMIAL_BUSINESS_BASE_URL',
    defaultValue: 'https://merchant.amialpay.com',
  );

  static const String _activeMerchantKey = 'amial_merchant_biz_active_merchant_v1';
  static const String _enabledPrefix = 'amial_merchant_biz_enabled_v1_';
  static const String _baseUrlPrefix = 'amial_merchant_biz_url_v1_';
  static const String _tokenPrefix = 'amial_merchant_biz_token_v1_';

  final SharedPreferences sharedPreferences;
  final BusinessTokenStore tokenStore;
  final bool allowInsecureHttpForTesting;
  final Map<String, String> _cachedTokens = <String, String>{};

  MerchantBusinessBackendConfig({
    required this.sharedPreferences,
    BusinessTokenStore? tokenStore,
    this.allowInsecureHttpForTesting = false,
  }) : tokenStore = tokenStore ?? const SecureBusinessTokenStore();

  String? get activeMerchantId {
    final raw = sharedPreferences.getString(_activeMerchantKey);
    if (raw == null || raw.trim().isEmpty) return null;
    return raw.trim();
  }

  Future<void> setActiveMerchant(String? merchantId) async {
    final clean = merchantId?.trim() ?? '';
    if (clean.isEmpty) {
      await sharedPreferences.remove(_activeMerchantKey);
      return;
    }
    await sharedPreferences.setString(_activeMerchantKey, clean);
    await primeTokenCache(merchantId: clean);
  }

  /// يهيّئ ذاكرة التوكن المشفّر عند الإقلاع، ويزيل أي بقايا غير مشفّرة إن وُجدت في SharedPreferences.
  Future<void> primeTokenCache({String? merchantId}) async {
    final id = (merchantId ?? activeMerchantId)?.trim() ?? '';
    if (id.isEmpty) return;
    final key = '$_tokenPrefix$id';
    try {
      if (sharedPreferences.containsKey(key)) {
        final legacyPlain = sharedPreferences.getString(key)?.trim();
        await sharedPreferences.remove(key);
        if (legacyPlain != null && legacyPlain.isNotEmpty) {
          await tokenStore.write(key, legacyPlain);
        }
      }
      final stored = (await tokenStore.read(key))?.trim();
      if (stored != null && stored.isNotEmpty) {
        _cachedTokens[id] = stored;
      } else {
        _cachedTokens.remove(id);
      }
    } catch (_) {
      // في حال تعذر مخزن النظام مؤقتاً يبقى التاجر آمناً على الوضع القديم.
      _cachedTokens.remove(id);
    }
  }

  /// القيمة الافتراضية `false` دائماً: لا تحويل تلقائياً لأي تاجر.
  bool merchantBusinessBackendEnabled(String? merchantId) {
    final id = (merchantId ?? activeMerchantId)?.trim() ?? '';
    if (id.isEmpty) return false;
    return sharedPreferences.getBool('$_enabledPrefix$id') ?? false;
  }

  bool get isActiveMerchantEnabled => merchantBusinessBackendEnabled(activeMerchantId);

  String businessBaseUrlFor(String? merchantId) {
    final id = (merchantId ?? activeMerchantId)?.trim() ?? '';
    final stored = id.isNotEmpty ? sharedPreferences.getString('$_baseUrlPrefix$id') : null;
    final candidate = (stored != null && stored.trim().isNotEmpty) ? stored.trim() : defaultBaseUrl;
    return _stripTrailingSlash(candidate);
  }

  String? businessTokenFor(String? merchantId) {
    final id = (merchantId ?? activeMerchantId)?.trim() ?? '';
    if (id.isEmpty) return null;
    final token = _cachedTokens[id];
    if (token == null || token.trim().isEmpty) return null;
    return token.trim();
  }

  bool get isReadyForActiveMerchant {
    final id = activeMerchantId;
    if (id == null || !merchantBusinessBackendEnabled(id)) return false;
    final token = businessTokenFor(id);
    if (token == null || token.isEmpty) return false;
    final url = businessBaseUrlFor(id);
    return allowInsecureHttpForTesting
        ? (url.startsWith('http://') || url.startsWith('https://'))
        : url.startsWith('https://');
  }

  Future<void> enableForMerchant({
    required String merchantId,
    required String bearerToken,
    String? baseUrl,
  }) async {
    final id = merchantId.trim();
    if (id.isEmpty) {
      throw ArgumentError.value(merchantId, 'merchantId', 'Merchant ID is required');
    }
    final token = bearerToken.trim();
    if (token.isEmpty) {
      throw ArgumentError.value(bearerToken, 'bearerToken', 'Business Bearer token is required');
    }
    final resolvedUrl = _stripTrailingSlash((baseUrl ?? defaultBaseUrl).trim());
    final validScheme = allowInsecureHttpForTesting
        ? (resolvedUrl.startsWith('https://') || resolvedUrl.startsWith('http://'))
        : resolvedUrl.startsWith('https://');
    if (!validScheme) {
      throw ArgumentError.value(resolvedUrl, 'baseUrl', 'Business backend URL must use HTTPS');
    }

    await sharedPreferences.setString(_activeMerchantKey, id);
    await sharedPreferences.setString('$_baseUrlPrefix$id', resolvedUrl);
    // عدم كتابة أي رمز Bearer في SharedPreferences أبداً — يُحفظ في المخزن المشفّر فقط.
    await sharedPreferences.remove('$_tokenPrefix$id');
    await tokenStore.write('$_tokenPrefix$id', token);
    _cachedTokens[id] = token;
    await sharedPreferences.setBool('$_enabledPrefix$id', true);
  }

  /// تراجع آمن وفوري للخادم القديم للتاجر المحدد دون المساس بجلسة أميال باي أو طابور الأوفلاين.
  Future<void> disableForMerchant(String merchantId, {bool clearToken = false}) async {
    final id = merchantId.trim();
    if (id.isEmpty) return;
    await sharedPreferences.setBool('$_enabledPrefix$id', false);
    if (clearToken) {
      _cachedTokens.remove(id);
      await tokenStore.delete('$_tokenPrefix$id');
      await sharedPreferences.remove('$_tokenPrefix$id');
    }
  }

  String _stripTrailingSlash(String url) =>
      url.endsWith('/') ? url.substring(0, url.length - 1) : url;
}
