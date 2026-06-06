import 'package:flutter/foundation.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:amyal_pay/util/app_constants.dart';

/// AMYAL-SECURITY-002 (v0.7-C)
///
/// SecureStorageHelper — التخزين الآمن للبيانات الحساسة.
///
/// **الأساس:** السياسة في الوثيقة قسم 20:
///   "عدم حفظ token في SharedPreferences في النسخة النهائية واستخدام secure storage"
///
/// **ما يُخزّن هنا (encrypted at-rest):**
///   - `token` — Access token (Bearer)
///   - `transaction_pin_cache` — لو فُعِّل biometric quick PIN (محتوى مُشفّر مزدوجاً)
///   - أي PII حساس آخر
///
/// **ما لا يُخزَّن هنا (يبقى في SharedPreferences):**
///   - `theme`, `locale`, `country_code` (تفضيلات UI، غير حساسة)
///   - `show_tour_widget`, `show_welcome_bottom_sheet` (UI flags)
///   - `biometric_auth` (boolean flag فقط، PIN منفصل)
///
/// **آلية الـ Migration:**
///   عند أول استدعاء لـ [getToken]، نتحقق إن كان موجوداً في SharedPreferences القديم.
///   لو نعم: ننقله لـ Secure Storage ونحذفه من SP. هذا يضمن ترقية صامتة للمستخدمين الحاليين.
///
/// **منصات Backend:**
///   - Android: EncryptedSharedPreferences + Android Keystore (AES-256)
///   - iOS: Keychain Services مع `first_unlock_this_device`
///   - Web: عبر `dart:html` localStorage (ليس آمناً، لكن الـ web غير مدعوم في v0.7)
class SecureStorageHelper {
  SecureStorageHelper._();
  static final SecureStorageHelper _instance = SecureStorageHelper._();
  static SecureStorageHelper get instance => _instance;

  /// آليات الـ secure storage مع إعدادات صارمة.
  static const FlutterSecureStorage _storage = FlutterSecureStorage(
    aOptions: AndroidOptions(
      encryptedSharedPreferences: true,
      resetOnError: true, // لو الـ keystore تلف، نعيد التهيئة بدل crash
    ),
    iOptions: IOSOptions(
      accessibility: KeychainAccessibility.first_unlock_this_device,
      // ⚠️ لا نستخدم `first_unlock` أو `unlocked` لأنها تستثني الـ device-specific
      // ولا نستخدم `passcode` لأنها تتطلب passcode في الجهاز (يكسر devices بدون passcode)
    ),
  );

  // -------- Token --------

  /// يخزّن الـ token. يُحذف من SP القديم تلقائياً للمنع duplication.
  Future<void> setToken(String token) async {
    await _storage.write(key: AppConstants.token, value: token);

    // إزالة من SP القديم (للـ migration)
    try {
      final sp = await SharedPreferences.getInstance();
      if (sp.containsKey(AppConstants.token)) {
        await sp.remove(AppConstants.token);
        if (kDebugMode) {
          debugPrint('[SecureStorage] Migrated token from SharedPreferences');
        }
      }
    } catch (e) {
      // لا نفشل لو SP لم يعمل — الـ token الجديد محفوظ بأمان
      if (kDebugMode) {
        debugPrint('[SecureStorage] Migration cleanup warning: $e');
      }
    }
  }

  /// يجلب الـ token. لو غير موجود في secure storage، يحاول الـ SP (للـ migration).
  Future<String?> getToken() async {
    final fromSecure = await _storage.read(key: AppConstants.token);
    if (fromSecure != null) return fromSecure;

    // Migration path: لو لا يزال في SP القديم، انقله الآن
    try {
      final sp = await SharedPreferences.getInstance();
      final legacy = sp.getString(AppConstants.token);
      if (legacy != null && legacy.isNotEmpty) {
        // مهم: نخزن في secure أولاً ثم نحذف من SP (atomicity)
        await _storage.write(key: AppConstants.token, value: legacy);
        await sp.remove(AppConstants.token);
        if (kDebugMode) {
          debugPrint('[SecureStorage] Auto-migrated legacy token');
        }
        return legacy;
      }
    } catch (_) {
      // تجاهل أخطاء الـ migration
    }

    return null;
  }

  Future<bool> hasToken() async => (await getToken()) != null;

  Future<void> removeToken() async {
    await _storage.delete(key: AppConstants.token);
    // ولـ السلامة، نمسحه من SP أيضاً
    try {
      final sp = await SharedPreferences.getInstance();
      await sp.remove(AppConstants.token);
    } catch (_) {}
  }

  // -------- مفاتيح حساسة أخرى --------

  Future<void> setSecure(String key, String value) =>
      _storage.write(key: key, value: value);

  Future<String?> getSecure(String key) => _storage.read(key: key);

  Future<void> removeSecure(String key) => _storage.delete(key: key);

  /// يمسح كل البيانات الآمنة (logout كامل، تغيير PIN، إلخ).
  Future<void> wipeAll() async {
    await _storage.deleteAll();
    if (kDebugMode) {
      debugPrint('[SecureStorage] All secure data wiped');
    }
  }
}
