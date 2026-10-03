import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

void main() {
  String read(String relative) {
    final root = Directory.current.path;
    return File('$root/$relative').readAsStringSync();
  }

  test('حسابي لا يعرض لوحة KYC الطويلة ويملك باب توثيق واحداً', () {
    final userInfo = read('lib/features/setting/widgets/user_info_widget.dart');
    final profile = read('lib/features/setting/screens/profile_screen.dart');

    expect(
      userInfo.contains('CustomerVerificationPanel()'),
      isFalse,
      reason:
          'عودة CustomerVerificationPanel إلى رأس حسابي تعيد القائمة الطويلة التي أزيلت.',
    );

    expect(
      profile.contains("title: Text(\n                        'customer_verification_title'.tr,"),
      isTrue,
      reason:
          'مدخل التوثيق في حسابي يجب أن يستخدم المفتاح المترجم نفسه، لا نصاً عربياً ثابتاً.',
    );
    expect(
      profile.contains('CustomerVerificationCenterScreen()'),
      isTrue,
    );
  });

  test('خدماتي يفتح مركز التوثيق نفسه لا خطوة KYC متفرقة', () {
    final services = read('lib/features/me/screens/my_services_screen.dart');

    expect(
      services.contains("'customer_verification_title'.tr"),
      isTrue,
    );
    expect(
      services.contains('CustomerVerificationCenterScreen()'),
      isTrue,
    );
    expect(
      services.contains('CompleteMyAccountScreen('),
      isFalse,
      reason:
          'مدخل التوثيق في خدماتي يجب أن يفتح المركز الموحد، لا يقفز مباشرة إلى خطوة واحدة.',
    );
  });

  test('مركز التوثيق هو المكان الذي يحتوي اللوحة الكاملة', () {
    final center = read(
      'lib/features/kyc_verification/screens/customer_verification_center_screen.dart',
    );

    expect(center.contains("AmialScreenHeader(title: 'customer_verification_title'.tr)"), isTrue);
    expect(center.contains('CustomerVerificationPanel()'), isTrue);
  });
}
