import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:get/get.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:amial_pay/data/api/api_client.dart';
import 'package:amial_pay/features/auth/controllers/unified_auth_controller.dart';
import 'package:amial_pay/helper/arabic_digits_input_formatter.dart';
import 'package:amial_pay/helper/route_helper.dart';

class _ActivationFakeApiClient implements ApiClient {
  final List<Map<String, dynamic>> requests = [];

  @override
  Future<Response> postData(
    String uri,
    dynamic body, {
    Map<String, String>? headers,
    String? idempotencyKey,
    String? correlationId,
  }) async {
    requests.add({
      'uri': uri,
      'body': body is Map ? Map<String, dynamic>.from(body) : <String, dynamic>{},
    });
    if (uri == '/api/v1/auth/activate') {
      return const Response(
        statusCode: 200,
        body: {
          'success': true,
          'code': 'LOGIN_OK',
          'meta': {
            'token': 'activated-customer-token',
            'role': 'customer',
            'user': {
              'id': 77,
              'role': 'customer',
              'name': 'سالم العولقي',
              'phone': '967771445566',
              'zone_code': 'SOUTH',
              'verification_state': 'verified',
            },
          },
        },
      );
    }
    if (uri == '/api/v1/auth/email-otp/request') {
      return const Response(
        statusCode: 200,
        body: {
          'success': true,
          'code': 'OTP_ACCEPTED',
          'message': 'تم إرسال رمز جديد',
          'meta': {'resend_after_seconds': 60},
        },
      );
    }
    return const Response(statusCode: 404, body: {'success': false});
  }

  @override
  void updateHeader(String token) {}

  @override
  dynamic noSuchMethod(Invocation invocation) => null;
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  setUp(() async {
    SharedPreferences.setMockInitialValues(<String, Object>{});
    Get.put<SharedPreferences>(await SharedPreferences.getInstance());
  });

  tearDown(Get.reset);

  test('تطبيع الأرقام العربية الشرقية والفارسية يعمل لحظة الإدخال', () {
    const formatter = ArabicDigitsNormalizingFormatter();
    final updated = formatter.formatEditUpdate(
      TextEditingValue.empty,
      const TextEditingValue(
        text: '+٩٦٧٧٧١٢٣٤٥٦٧-۰۱۲۳',
        selection: TextSelection.collapsed(offset: 18),
      ),
    );

    expect(updated.text, '+967771234567-0123');
    expect(updated.selection.baseOffset, 18);
  });

  test('مسارات التسجيل والدخول مسجّلة في RouteHelper بلا صفحات مفقودة', () {
    final registered = RouteHelper.routes.map((r) => r.name).toSet();
    expect(registered, contains(RouteHelper.createAccountScreen));
    expect(registered, contains(RouteHelper.loginScreen));
    expect(registered, contains(RouteHelper.unifiedLoginScreen));
  });

  test('تفعيل الحساب يتذكّر هاتف العميل واسمه وإعادة إرسال الرمز تعمل', () async {
    final api = _ActivationFakeApiClient();
    final auth = UnifiedAuthController(repo: UnifiedAuthRepo(apiClient: api));

    final resend = await auth.requestActivationCode('salem@example.test');
    expect(resend.ok, isTrue);
    expect(resend.resendAfterSeconds, 60);

    final ok = await auth.activateAccount(
      email: 'salem@example.test',
      otp: '654321',
      password: 'Amial#2026Pass',
      pin: '482913',
    );
    expect(ok, isTrue);

    final remembered = UnifiedAuthController.readLastUser();
    expect(remembered, isNotNull);
    expect(remembered!.name, 'سالم العولقي');
    expect(remembered.phone, '967771445566');
    expect(remembered.kind, 'customer');
  });
}
