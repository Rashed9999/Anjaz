import 'package:get/get.dart';
import 'package:amyal_pay/features/merchant/domain/repositories/merchant_pay_repo.dart';
import 'package:amyal_pay/data/api/idempotency_key_generator.dart';

/// AMIAL-MERCHANT-PAY-001 — متحكّم دفع العميل للتاجر.
class MerchantPayController extends GetxController implements GetxService {
  final MerchantPayRepo repo;
  MerchantPayController({required this.repo});

  final RxBool isQuoting = false.obs;
  final RxBool isSubmitting = false.obs;
  final RxString lastError = ''.obs;

  // نتيجة المعاينة
  final RxString quoteFee = ''.obs;
  final RxString quoteMerchantReceives = ''.obs;

  // نتيجة الدفع (meta من الـ backend)
  final Rx<Map<String, dynamic>?> lastResult = Rx<Map<String, dynamic>?>(null);

  String _idempotencyKey = '';

  /// يُستدعى قبل كل عملية دفع جديدة — مفتاح idempotency جديد + تصفير الحالة.
  void prepareNewPayment() {
    _idempotencyKey = IdempotencyKeyGenerator.forFinancialAction('merchant_pay');
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
        idempotencyKey: _idempotencyKey,
      );

      if (_isOk(r)) {
        lastResult.value = Map<String, dynamic>.from((r.body['meta'] ?? {}) as Map);
        return true;
      }
      lastError.value = _msg(r) ?? 'فشل الدفع';
      return false;
    } catch (e) {
      lastError.value = 'خطأ في الشبكة';
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
