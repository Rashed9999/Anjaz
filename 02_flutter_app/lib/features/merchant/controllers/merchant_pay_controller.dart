import 'package:get/get.dart';
import 'package:amial_pay/features/merchant/domain/repositories/merchant_pay_repo.dart';
import 'package:amial_pay/data/api/idempotency_key_generator.dart';

/// AMIAL-MERCHANT-PAY-001 — متحكّم دفع العميل للتاجر.
class MerchantPayController extends GetxController implements GetxService {
  final MerchantPayRepo repo;
  MerchantPayController({required this.repo});

  final RxBool isQuoting = false.obs;
  final RxBool isSubmitting = false.obs;
  final RxString lastError = ''.obs;
  final RxString lastDiagnosticId = ''.obs;

  // نتيجة المعاينة
  final RxString quoteFee = ''.obs;
  final RxString quoteMerchantReceives = ''.obs;

  // نتيجة الدفع (meta من الـ backend)
  final Rx<Map<String, dynamic>?> lastResult = Rx<Map<String, dynamic>?>(null);

  String _idempotencyKey = '';
  String _correlationId = '';

  /// يُستدعى قبل كل عملية دفع جديدة — مفتاح idempotency جديد + تصفير الحالة.
  void prepareNewPayment() {
    _idempotencyKey = IdempotencyKeyGenerator.forFinancialAction('merchant_pay');
    _correlationId = IdempotencyKeyGenerator.generate();
    lastDiagnosticId.value = _correlationId;
    lastError.value = '';
    lastResult.value = null;
    quoteFee.value = '';
    quoteMerchantReceives.value = '';
  }

  Future<void> getQuote({required String amount, String channel = 'qr'}) async {
    if (amount.trim().isEmpty) return;
    try {
      isQuoting.value = true;
      final r = await repo.quote(amount: amount, channel: channel);
      if (_isOk(r)) {
        final meta = (r.body['meta'] ?? {}) as Map;
        quoteFee.value = (meta['fee'] ?? '').toString();
        quoteMerchantReceives.value = (meta['merchant_receives'] ?? '').toString();
      }
    } catch (_) {
      // المعاينة غير حرجة — نتجاهل أخطاءها بصمت
    } finally {
      isQuoting.value = false;
    }
  }

  Future<bool> pay({
    String? merchantPhone,
    int? merchantUserId,
    required String amount,
    String channel = 'qr',
    int? posUserId,
    String? note,
    required String pin,
  }) async {
    try {
      isSubmitting.value = true;
      lastError.value = '';
      if (_idempotencyKey.isEmpty) prepareNewPayment();

      final r = await repo.pay(
        merchantPhone: merchantPhone,
        merchantUserId: merchantUserId,
        amount: amount,
        channel: channel,
        posUserId: posUserId,
        note: note,
        pin: pin,
        idempotencyKey: _idempotencyKey,
        correlationId: _correlationId,
      );

      final responseTrace =
          r.headers?['x-correlation-id'] ??
          r.headers?['X-Correlation-Id'] ??
          _correlationId;
      lastDiagnosticId.value = responseTrace;

      if (_isOk(r)) {
        final meta = Map<String, dynamic>.from((r.body['meta'] ?? {}) as Map);
        meta['diagnostic_reference'] = responseTrace;
        lastResult.value = meta;
        return true;
      }

      final message = _msg(r) ?? 'فشل الدفع';
      if (r.statusCode == 1 || r.statusCode == 0) {
        lastError.value =
            'تعذر تأكيد نتيجة الدفع بسبب انقطاع الاتصال. '
            'لا تبدأ عملية دفع جديدة قبل التحقق أو إعادة المحاولة من نفس الشاشة. '
            'رقم التتبع: $responseTrace';
      } else {
        lastError.value = '$message\nرقم التتبع: $responseTrace';
      }
      return false;
    } catch (e) {
      // قد يكون الخادم نفّذ العملية ثم تعثرت معالجة الرد داخل التطبيق؛
      // لذلك لا نصفها بأنها «مشكلة إنترنت» ولا نطلب دفعاً جديداً.
      final trace = _correlationId.isEmpty
          ? IdempotencyKeyGenerator.generate()
          : _correlationId;
      lastDiagnosticId.value = trace;
      lastError.value =
          'تعذر تأكيد نتيجة الدفع داخل التطبيق. '
          'لا تنشئ دفعة جديدة قبل التحقق. رقم التتبع: $trace';
      return false;
    } finally {
      isSubmitting.value = false;
    }
  }

  bool _isOk(Response r) =>
      (r.statusCode == 200 || r.statusCode == 201) &&
      r.body is Map &&
      r.body['success'] == true;

  String? _msg(Response r) {
    try {
      if (r.body is Map) return r.body['message']?.toString();
    } catch (_) {}
    return null;
  }
}
