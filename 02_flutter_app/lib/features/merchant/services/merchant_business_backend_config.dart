import 'package:get/get.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// AMIAL-BUSINESS-UNIFY-U2 — إعداد التوجيه الاختياري لكل تاجر نحو خادم أميال أعمال.
///
/// الضوابط:
/// - الافتراض لجميع التجار هو `false` (لا يُحوَّل أي تاجر تلقائياً).
/// - رمز مصادقة أميال أعمال منفصل تماماً عن رمز محفظة أميال باي، فلا يمسّ
///   تسجيل الدخول ولا المحفظة ولا خدمات العملاء.
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
  final bool allowInsecureHttpForTesting;

  MerchantBusinessBackendConfig({
    required this.sharedPreferences,
    this.allowInsecureHttpForTesting = false,
  });

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
    final token = sharedPreferences.getString('$_tokenPrefix$id');
    if (token == null || token.trim().isEmpty) return null;
    return token.trim();
  }

  bool get isReadyForActiveMerchant {
    final id = activeMerchantId;
    if (id == null || !merchantBusinessBackendEnabled(id)) return false;
    final token = businessTokenFor(id);
    if (token == null || token.isEmpty) return false;
    final url = businessBaseUrlFor(id);
    return allowInsecureHttpForTesting ? url.startsWith('http://') || url.startsWith('https://') : url.startsWith('https://');
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
    await sharedPreferences.setString('$_tokenPrefix$id', token);
    await sharedPreferences.setBool('$_enabledPrefix$id', true);
  }

  /// تراجع آمن وفوري للخادم القديم للتاجر المحدد دون المساس بجلسة أميال باي أو طابور الأوفلاين.
  Future<void> disableForMerchant(String merchantId, {bool clearToken = false}) async {
    final id = merchantId.trim();
    if (id.isEmpty) return;
    await sharedPreferences.setBool('$_enabledPrefix$id', false);
    if (clearToken) {
      await sharedPreferences.remove('$_tokenPrefix$id');
    }
  }

  String _stripTrailingSlash(String url) =>
      url.endsWith('/') ? url.substring(0, url.length - 1) : url;
}
