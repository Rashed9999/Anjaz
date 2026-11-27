import 'package:amial_pay/data/api/api_client.dart';
import 'package:amial_pay/data/api/secure_storage_helper.dart';
import 'package:amial_pay/features/auth/screens/amial_biometric_setup_screen.dart';
import 'package:amial_pay/features/family_fund/controllers/funds_controller.dart';
import 'package:amial_pay/features/family_fund/domain/repositories/funds_repo.dart';
import 'package:amial_pay/features/withdraw/controllers/customer_withdraw_controller.dart';
import 'package:amial_pay/features/withdraw/domain/repositories/customer_withdraw_repo.dart';
import 'package:amial_pay/helper/arabic_digits_input_formatter.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:get/get.dart';
import 'package:shared_preferences/shared_preferences.dart';

class _CapturingApiClient extends ApiClient {
  _CapturingApiClient(SharedPreferences prefs)
      : super(appBaseUrl: 'https://example.invalid', sharedPreferences: prefs);

  final List<({String uri, dynamic body, String? idempotencyKey})> calls = [];
  Response nextResponse = const Response(
    statusCode: 200,
    body: {'success': true, 'code': 'OK', 'meta': {}},
  );

  @override
  Future<Response> postData(
    String uri,
    dynamic body, {
    Map<String, String>? headers,
    String? idempotencyKey,
    String? correlationId,
  }) async {
    calls.add((uri: uri, body: body, idempotencyKey: idempotencyKey));
    return nextResponse;
  }

  @override
  Future<Response> getData(
    String uri, {
    Map<String, dynamic>? query,
    String? contentType,
    Map<String, String>? headers,
    dynamic Function(dynamic)? decoder,
  }) async {
    return const Response(
      statusCode: 200,
      body: {'success': true, 'code': 'OK', 'meta': {'items': []}},
    );
  }
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  const secureChannel = MethodChannel(
    'plugins.it_nomads.com/flutter_secure_storage',
  );
  final Map<String, String> fakeSecureStore = <String, String>{};

  setUp(() async {
    fakeSecureStore.clear();
    SharedPreferences.setMockInitialValues(<String, Object>{});
    Get.testMode = true;
    Get.reset();

    TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger
        .setMockMethodCallHandler(secureChannel, (MethodCall call) async {
      final args = (call.arguments as Map?) ?? const {};
      final key = args['key']?.toString() ?? '';
      switch (call.method) {
        case 'read':
          return fakeSecureStore[key];
        case 'write':
          final val = args['value']?.toString();
          if (val == null) {
            fakeSecureStore.remove(key);
          } else {
            fakeSecureStore[key] = val;
          }
          return null;
        case 'delete':
          fakeSecureStore.remove(key);
          return null;
        case 'deleteAll':
          fakeSecureStore.clear();
          return null;
        default:
          return null;
      }
    });
  });

  tearDown(() {
    TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger
        .setMockMethodCallHandler(secureChannel, null);
    Get.reset();
  });

  test(
    'AmialBiometricSetupScreen.clearSavedCredentials wipes stale password after recovery',
    () async {
      final storage = SecureStorageHelper.instance;
      await storage.setSecure(AmialBiometricSetupScreen.kPhone, '+967771112233');
      await storage.setSecure(AmialBiometricSetupScreen.kPassword, 'OldPass#1');
      await storage.setSecure(AmialBiometricSetupScreen.kEnabled, '1');

      expect(await AmialBiometricSetupScreen.isEnabled(), isTrue);
      expect(await AmialBiometricSetupScreen.savedCredentials(), isNotNull);

      await AmialBiometricSetupScreen.clearSavedCredentials();

      expect(await AmialBiometricSetupScreen.isEnabled(), isFalse);
      expect(await AmialBiometricSetupScreen.savedCredentials(), isNull);
    },
  );

  test(
    'CustomerWithdrawController forwards PIN and preserves Idempotency-Key across transient failures',
    () async {
      final prefs = await SharedPreferences.getInstance();
      final api = _CapturingApiClient(prefs);
      final repo = CustomerWithdrawRepo(apiClient: api);
      final ctrl = CustomerWithdrawController(repo: repo);

      // فشل شبكي عابر (503): يجب الاحتفاظ بنفس مفتاح عدم التكرار لإعادة المحاولة بأمان
      api.nextResponse = const Response(
        statusCode: 503,
        body: {'success': false, 'message': 'Service Unavailable'},
      );
      final firstOk = await ctrl.requestWithdraw('8000', pin: '739582');
      expect(firstOk, isFalse);
      final firstKey = api.calls.last.idempotencyKey;
      expect(firstKey, isNotNull);
      expect(api.calls.last.body, equals({'amount': '8000', 'pin': '739582'}));

      // إعادة المحاولة بنفس المبلغ تعيد استخدام المفتاح ذاته ثم تديره بعد النجاح
      api.nextResponse = const Response(
        statusCode: 200,
        body: {
          'success': true,
          'code': 'WITHDRAW_PENDING',
          'meta': {
            'op_code': '918273645',
            'amount': '8000.0000',
            'fee': '400.0000',
            'total_debit': '8400.0000',
            'status': 'pending',
            'request_id': 42,
          },
        },
      );
      final secondOk = await ctrl.requestWithdraw('8000', pin: '739582');
      expect(secondOk, isTrue);
      expect(api.calls.last.idempotencyKey, equals(firstKey));
      expect(ctrl.currentRequest.value?['op_code'], equals('918273645'));

      // طلب جديد لاحقاً بنفس المبلغ يولّد مفتاحاً جديداً بعد استقرار السابق
      await ctrl.requestWithdraw('8000', pin: '739582');
      expect(api.calls.last.idempotencyKey, isNot(equals(firstKey)));
    },
  );

  test(
    'FundsController forwards transaction PIN for contribute, proposeDisbursement, and approveDisbursement',
    () async {
      final prefs = await SharedPreferences.getInstance();
      final api = _CapturingApiClient(prefs);
      final repo = FundsRepo(apiClient: api);
      final ctrl = FundsController(repo: repo);

      api.nextResponse = const Response(
        statusCode: 201,
        body: {
          'success': true,
          'code': 'CONTRIBUTE_OK',
          'meta': {'transaction': {'status': 'completed'}},
        },
      );
      expect(
        await ctrl.contribute(
          fundUlid: '01JZZZZZZZZZZZZZZZZZZZZZZZ',
          amount: '15000',
          pin: '739582',
          note: 'قسط الأسرة',
        ),
        isTrue,
      );
      expect(
        api.calls.first.body,
        equals({'amount': '15000', 'note': 'قسط الأسرة', 'pin': '739582'}),
      );

      api.calls.clear();
      api.nextResponse = const Response(
        statusCode: 201,
        body: {
          'success': true,
          'code': 'DISBURSEMENT_PENDING',
          'meta': {'transaction': {'status': 'pending_approval'}},
        },
      );
      final status = await ctrl.proposeDisbursement(
        fundUlid: '01JZZZZZZZZZZZZZZZZZZZZZZZ',
        beneficiaryUserId: 9,
        amount: '5000',
        pin: '4927',
      );
      expect(status, equals('pending_approval'));
      expect(
        api.calls.first.body,
        equals({'beneficiary_user_id': 9, 'amount': '5000', 'pin': '4927'}),
      );

      api.calls.clear();
      api.nextResponse = const Response(
        statusCode: 200,
        body: {
          'success': true,
          'code': 'DISBURSEMENT_OK',
          'meta': {'approved': true},
        },
      );
      expect(
        await ctrl.approveDisbursement(
          '01JZZZZZZZZZZZZZZZZZZZZZZZ',
          '01JYYYYYYYYYYYYYYYYYYYYYYY',
          '739582',
        ),
        isTrue,
      );
      expect(api.calls.first.body, equals({'pin': '739582'}));
    },
  );

  test(
    'ArabicDigitsNormalizingFormatter normalizes decimal amounts and Yemeni phone numbers',
    () {
      const formatter = ArabicDigitsNormalizingFormatter();
      final amountOut = formatter.formatEditUpdate(
        TextEditingValue.empty,
        const TextEditingValue(text: '١٥٢٥٠.٥٠'),
      );
      expect(amountOut.text, equals('15250.50'));

      final phoneOut = formatter.formatEditUpdate(
        TextEditingValue.empty,
        const TextEditingValue(text: '+٩٦٧٧٧١٢٣٤٥٦٧'),
      );
      expect(phoneOut.text, equals('+967771234567'));
    },
  );
}
