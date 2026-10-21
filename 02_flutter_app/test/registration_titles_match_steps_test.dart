import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

/// AMIAL-PROGRESSIVE-REG-GUARD-001
///
/// التسجيل العام يفتح طلباً لدى موثّق؛ لا محفظة ولا جلسة مالية قبل
/// اكتمال قرار موثّق ثم الاعتماد الإداري. تختلف أدلة العميل الذكر عن
/// المستوى الأول لمسار المرأة، ولذلك عدد المراحل ديناميكي.
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

  test('التسجيل السريع يحرس مراحل موثّق الأساسية ويضيف الهوية للذكر فقط', () {
    expect(
      quick,
      contains("final labels = ['الحساب', 'البريد', 'الهاتف', 'السكن',"),
    );
    expect(
      quick,
      contains("if (_requiresIdentity) 'الهوية'"),
    );
    expect(quick, contains('0 => _basicsStep()'));
    expect(quick, contains('1 => _emailStep()'));
    expect(quick, contains('2 => _phoneStep()'));
    expect(quick, contains('3 => _residenceStep()'));
    expect(quick, contains('4 => _requiresIdentity ? _identityStep() : _successStep()'));
    expect(quick, contains('_ => _successStep()'));
  });

  test('شريط التسجيل يشتق عدد أجزائه من قائمة المراحل', () {
    expect(quick, contains('List.generate(labels.length'));
    expect(quick, contains('labels[index]'));
  });

  test('كلمة الدخول وPIN المالي منفصلان ولا تُنشأ محفظة قبل قرار موثّق', () {
    expect(quick, contains("'registration_password_min'.tr"));
    expect(quick, contains("'registration_transaction_pin_digits'.tr"));
    expect(quick, contains("'password': _password.text"));
    expect(quick, contains("'password_confirmation': _passwordConfirm.text"));
    expect(quick, contains("'transaction_pin': _pin.text"));
    expect(quick, isNot(contains("'password': _pin.text")));
    expect(quick, contains('المتطلبات تختلف حسب مسار موثّق. لا محفظة قبل الاعتماد.'));
    expect(quick, contains('مسار المرأة الأولي لا يشترط الهوية أو السيلفي.'));
    expect(quick, contains('مسار العميل الذكر يتطلب رفع وثيقة هوية'));
    expect(completion, contains("appBar: AppBar(title: const Text('إكمال حسابي'))"));
    expect(completion, contains('widget.targetTier >= 2'));
    expect(completion, contains('widget.targetTier >= 3'));
  });
}
