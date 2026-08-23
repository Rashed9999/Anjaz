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

  static SharedPreferences get _prefs => Get.find<SharedPreferences>();

  static bool get isEnabled =>
      (_prefs.getBool(_enabledKey) ?? false) &&
      (_prefs.getString(_addressKey) ?? '').trim().isNotEmpty;

  static ({String displayName, String receiveAddress, String ownerPhone})?
      read() {
    if (!isEnabled) return null;

    final address = (_prefs.getString(_addressKey) ?? '').trim();
    if (address.isEmpty) return null;

    return (
      displayName: (_prefs.getString(_nameKey) ?? '').trim(),
      receiveAddress: address,
      ownerPhone: (_prefs.getString(_ownerPhoneKey) ?? '').trim(),
    );
  }

  static Future<bool> enable({
    required String displayName,
    required String receiveAddress,
    required String ownerPhone,
  }) async {
    final address = receiveAddress.trim();
    if (address.isEmpty) return false;

    await _prefs.setString(_addressKey, address);
    await _prefs.setString(_nameKey, displayName.trim());
    await _prefs.setString(_ownerPhoneKey, ownerPhone.trim());
    await _prefs.setBool(_enabledKey, true);
    return true;
  }

  static Future<void> disable() async {
    await _prefs.setBool(_enabledKey, false);
    await _prefs.remove(_addressKey);
    await _prefs.remove(_nameKey);
    await _prefs.remove(_ownerPhoneKey);
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
