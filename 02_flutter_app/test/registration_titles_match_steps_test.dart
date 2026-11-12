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

  test('شاشة التسجيل تفتح موثّق ولا تجمع بياناتٍ ولا تنشئ مستخدماً في أميال باي', () {
    expect(quick, contains('AppConstants.muwathaqOpenCustomerAccountUrl'));
    expect(quick, contains('launchUrl('));
    expect(quick, contains('LaunchMode.externalApplication'));
    // لا حقول ولا نداءات: إنشاء الهوية والحساب في موثّق وحده.
    expect(quick, isNot(contains('TextEditingController')));
    expect(quick, isNot(contains('ApiClient')));
    expect(quick, isNot(contains('postData')));
    expect(quick, isNot(contains('/api/v1/auth/register')));
    expect(quick, isNot(contains('transaction_pin')));
    expect(quick, isNot(contains("'password'")));
  });

  test('المعالج القديم الميت (بريد/هاتف/سكن/هوية) لا يعود', () {
    expect(File('lib/features/auth/screens/legacy/amial_full_registration_wizard_screen.dart').existsSync(), isFalse);
    // الشاشة صغيرة: ما يطول عن 120 سطراً عاد إليه كود تسجيل.
    expect('\n'.allMatches(quick).length, lessThan(120));
  });

  test('الاستعادة وإكمال الحساب يبقيان في التطبيق', () {
    expect(completion, contains("appBar: AppBar(title: const Text('إكمال حسابي'))"));
    expect(completion, contains('widget.targetTier >= 2'));
    expect(completion, contains('widget.targetTier >= 3'));
  });
}
