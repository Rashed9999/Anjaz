import 'package:get/get.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// AMIAL-QUICK-RECEIVE-002
///
/// إعدادٌ محليٌّ صريح لميزة «الاستلام السريع» على هذا الجهاز.
///
/// الميزة لا تخزّن كلمة مرور ولا PIN ولا رمز جلسة. ما يُحفظ هو عنوان استقبال
/// عام (`unique_id`) + اسم عرض مختصر فقط، لأن الشاشة نفسها مصممة لتُفتح قبل
/// تسجيل الدخول. لا نستخدم رقم الهاتف كحمولة QR؛ إن لم يأتِ `unique_id`
/// من ملف العميل فلا تُفعَّل الميزة أصلاً.
class QuickReceivePreferences {
  QuickReceivePreferences._();

  static const _enabledKey = 'amial_quick_receive_enabled';
  static const _addressKey = 'amial_quick_receive_address';
  static const _nameKey = 'amial_quick_receive_name';
  static const _ownerPhoneKey = 'amial_quick_receive_owner_phone';

  static SharedPreferences? get _prefs {
    try {
      return Get.find<SharedPreferences>();
    } catch (_) {
      return null;
    }
  }

  static bool get isEnabled {
    final prefs = _prefs;
    if (prefs == null) return false;
    return (prefs.getBool(_enabledKey) ?? false) &&
        (prefs.getString(_addressKey) ?? '').trim().isNotEmpty;
  }

  static ({String displayName, String receiveAddress, String ownerPhone})?
      read() {
    final prefs = _prefs;
    if (prefs == null) return null;

    final enabled = prefs.getBool(_enabledKey) ?? false;
    final address = (prefs.getString(_addressKey) ?? '').trim();
    if (!enabled || address.isEmpty) return null;

    return (
      displayName: (prefs.getString(_nameKey) ?? '').trim(),
      receiveAddress: address,
      ownerPhone: (prefs.getString(_ownerPhoneKey) ?? '').trim(),
    );
  }

  static Future<bool> enable({
    required String displayName,
    required String receiveAddress,
    required String ownerPhone,
  }) async {
    final prefs = _prefs;
    final address = receiveAddress.trim();
    if (prefs == null || address.isEmpty) return false;

    try {
      await prefs.setString(_addressKey, address);
      await prefs.setString(_nameKey, displayName.trim());
      await prefs.setString(_ownerPhoneKey, ownerPhone.trim());
      await prefs.setBool(_enabledKey, true);
      return true;
    } catch (_) {
      return false;
    }
  }

  static Future<void> disable() async {
    final prefs = _prefs;
    if (prefs == null) return;

    try {
      await prefs.setBool(_enabledKey, false);
      await prefs.remove(_addressKey);
      await prefs.remove(_nameKey);
      await prefs.remove(_ownerPhoneKey);
    } catch (_) {
      // Fail closed: read() still requires enabled=true + non-empty address.
    }
  }

  /// يمنع عرض عنوان حسابٍ سابق بعد أن يصبح الجهاز لحساب عميل آخر.
  static Future<void> disableIfOwnedByAnother(String currentPhone) async {
    final data = read();
    if (data == null) return;

    final current = currentPhone.trim();
    if (current.isEmpty || data.ownerPhone.isEmpty) return;
    if (data.ownerPhone != current) {
      await disable();
    }
  }
}
