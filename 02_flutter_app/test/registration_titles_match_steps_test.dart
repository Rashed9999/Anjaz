import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

/// AMIAL-PROGRESSIVE-REG-GUARD-001
///
/// التسجيل العام لم يعد معالج KYC الكامل. العميل يبدأ بمحفظة بسيطة ثم
/// يكمل التوثيق من حسابه. هذا الحارس يحمي الرحلة الحالية بدل مطالبتها
/// بإعادة PageView القديم.
void main() {
  final entry = File(
    'lib/features/auth/screens/amial_registration_wizard_screen.dart',
  ).readAsStringSync();
  final quick = File(
    'lib/features/auth/screens/quick_registration_screen.dart',
  ).readAsStringSync();
  final completion = File(
    'lib/features/kyc_verification/screens/complete_my_account_screen.dart',
  ).readAsStringSync();

  test('مدخل إنشاء الحساب يفتح التسجيل السريع لا معالج KYC القديم', () {
    expect(entry, contains('QuickRegistrationScreen'));
    expect(entry, isNot(contains('PageView(')));
  });

  test('التسجيل السريع يحرس المراحل الأربع بترتيبها ثم النجاح', () {
    expect(
      quick,
      contains("const labels = ['الحساب', 'البريد', 'الهاتف', 'السكن'];"),
    );
    expect(quick, contains('0 => _basicsStep()'));
    expect(quick, contains('1 => _emailStep()'));
    expect(quick, contains('2 => _phoneStep()'));
    expect(quick, contains('3 => _residenceStep()'));
    expect(quick, contains('_ => _successStep()'));
  });

  test('شريط التسجيل يشتق عدد أجزائه من قائمة المراحل', () {
    expect(quick, contains('List.generate(labels.length'));
    expect(quick, contains('labels[index]'));
  });

  test('كلمة الدخول وPIN المالي منفصلان وKYC الكامل مؤجل', () {
    expect(quick, contains("'registration_password_min'.tr"));
    expect(quick, contains("'registration_transaction_pin_digits'.tr"));
    expect(quick, contains("'password': _password.text"));
    expect(quick, contains("'password_confirmation': _passwordConfirm.text"));
    expect(quick, contains("'transaction_pin': _pin.text"));
    expect(quick, isNot(contains("'password': _pin.text")));
    expect(quick, contains('لا هوية ولا سيلفي في التسجيل الأساسي'));
    expect(completion, contains("appBar: AppBar(title: const Text('إكمال حسابي'))"));
    expect(completion, contains('widget.targetTier >= 2'));
    expect(completion, contains('widget.targetTier >= 3'));
  });
}
