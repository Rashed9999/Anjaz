import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

/// تفعيل الحساب المعتمد من موثّق يتم داخل التطبيق: بريد + رمز + كلمة مرور + PIN، ويفتح الحساب مباشرةً
/// (لا صفحة ويب ولا «نسيت كلمة المرور»). المدخلان: شاشة الدخول وشاشة «فتح حساب».
void main() {
  final screen = File('lib/features/auth/screens/activate_account_screen.dart').readAsStringSync();
  final controller = File('lib/features/auth/controllers/unified_auth_controller.dart').readAsStringSync();
  final login = File('lib/features/auth/screens/unified_login_screen.dart').readAsStringSync();
  final quick = File('lib/features/auth/screens/quick_registration_screen.dart').readAsStringSync();

  test('الشاشة تجمع البريد والرمز وكلمة المرور وPIN وتمرّ بالمتحكم لا بنداءٍ مباشر', () {
    for (final f in ['_email', '_code', '_password', '_pin']) {
      expect(screen, contains(f));
    }
    expect(screen, contains('activateAccount('));
    expect(screen, contains('navigateToHomeForRole'));
    expect(screen, isNot(contains('ApiClient')));
  });

  test('المتحكم يرسل إلى /auth/activate ويمرّ بمخرج الدخول الواحد', () {
    expect(controller, contains("'/api/v1/auth/activate'"));
    expect(controller, contains("r.body['code'] == 'LOGIN_OK'"));
    // مخرجٌ واحد للدخول الناجح: تفعيل الحساب لا يكرّر حفظ الجلسة بل يستدعيه.
    expect(RegExp(r'_onLoginSuccess\(').allMatches(controller).length, greaterThanOrEqualTo(3));
  });

  test('مدخلان إلى التفعيل: شاشة الدخول (عميل) وشاشة فتح الحساب', () {
    expect(login, contains('ActivateAccountScreen'));
    expect(login, contains('لديّ رمز تفعيل'));
    expect(quick, contains('ActivateAccountScreen'));
    expect(quick, contains('لديّ رمز تفعيل'));
  });
}
