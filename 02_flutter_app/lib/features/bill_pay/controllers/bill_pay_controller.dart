import 'package:flutter/foundation.dart';
import 'package:get/get.dart';
import 'package:amial_pay/data/api/idempotency_key_generator.dart';
import 'package:amial_pay/features/bill_pay/domain/models/bill_pay_models.dart';
import 'package:amial_pay/features/bill_pay/domain/repositories/bill_pay_repo.dart';

/// AMIAL-BILL-PAY-001 (v0.9-D)
class BillPayController extends GetxController implements GetxService {
  final BillPayRepo repo;
  BillPayController({required this.repo});

  final RxList<AmialBillProvider> providers = <AmialBillProvider>[].obs;
  final Rx<AmialBillService?> selectedService = Rx<AmialBillService?>(null);
  final RxList<AmialBillProduct> selectedServiceProducts = <AmialBillProduct>[].obs;
  final Rx<AmialBillOrder?> lastOrder = Rx<AmialBillOrder?>(null);
  final RxList<AmialBillOrder> orders = <AmialBillOrder>[].obs;
  final RxBool isLoading = false.obs;
  final RxBool isSubmitting = false.obs;
  final RxString lastError = ''.obs;

  // AMIAL-SECURITY-002 critical: idempotency key يُحفظ للـ retry
  // كل عملية pay تولّد key واحد، يبقى نفسه لو الـ user حاول مرتين.
  String? _pendingIdempotencyKey;

  Future<void> loadProviders() async {
    try {
      isLoading.value = true;
      final r = await repo.listProviders();
      if (r.statusCode == 200 && r.body is Map) {
        final items = ((r.body['meta'] ?? {})['providers'] as List? ?? []);
        providers.value = items
            .map((j) => AmialBillProvider.fromJson(Map<String, dynamic>.from(j)))
            .toList();
        lastError.value = '';
      } else {
        lastError.value = _msg(r) ?? 'Failed';
      }
    } catch (e) {
      if (kDebugMode) debugPrint('loadProviders: $e');
      lastError.value = 'Network error';
    } finally {
      isLoading.value = false;
    }
  }

  Future<void> loadServiceProducts(int serviceId) async {
    try {
      isLoading.value = true;
      final r = await repo.listProducts(serviceId);
      if (r.statusCode == 200 && r.body is Map) {
        final items = ((r.body['meta'] ?? {})['products'] as List? ?? []);
        selectedServiceProducts.value = items
            .map((j) => AmialBillProduct.fromJson(Map<String, dynamic>.from(j)))
            .toList();
        lastError.value = '';
      } else {
        lastError.value = _msg(r) ?? 'Failed';
      }
    } catch (e) {
      lastError.value = 'Network error';
    } finally {
      isLoading.value = false;
    }
  }

  /// **مهم:** يُستدعى مرة واحدة لكل عملية pay من الـ UI.
  /// إعادة الضغط على نفس "ادفع" تستخدم نفس الـ key (idempotency).
  void prepareNewPayment() {
    _pendingIdempotencyKey = IdempotencyKeyGenerator.forFinancialAction('bill_pay');
  }

  Future<bool> pay({
    required int serviceId,
    int? productId,
    required String subscriberAccount,
    required String amount,
    Map<String, dynamic>? subscriberExtra,
  }) async {
    if (_pendingIdempotencyKey == null) {
      prepareNewPayment(); // safeguard
    }

    try {
      isSubmitting.value = true;
      final r = await repo.pay(
        serviceId: serviceId,
        productId: productId,
        subscriberAccount: subscriberAccount,
        amount: amount,
        subscriberExtra: subscriberExtra,
        idempotencyKey: _pendingIdempotencyKey!,
      );

      if (r.body is Map) {
        final meta = (r.body['meta'] ?? {}) as Map;
        if (meta['order'] is Map) {
          lastOrder.value = AmialBillOrder.fromJson(Map<String, dynamic>.from(meta['order']));
        }
      }

      if ((r.statusCode == 200 || r.statusCode == 201) && r.body is Map && r.body['success'] == true) {
        _pendingIdempotencyKey = null; // success → reset
        lastError.value = '';
        return true;
      }

      // فشل قابل للـ retry — نحتفظ بـ idempotencyKey
      lastError.value = _msg(r) ?? 'Failed';
      return false;
    } catch (e) {
      if (kDebugMode) debugPrint('pay error: $e');
      lastError.value = 'Network error';
      return false;
    } finally {
      isSubmitting.value = false;
    }
  }

  Future<void> loadOrders() async {
    try {
      isLoading.value = true;
      final r = await repo.listOrders();
      if (r.statusCode == 200 && r.body is Map) {
        final items = ((r.body['meta'] ?? {})['items'] as List? ?? []);
        orders.value = items
            .map((j) => AmialBillOrder.fromJson(Map<String, dynamic>.from(j)))
            .toList();
      }
    } catch (e) {
      lastError.value = 'Network error';
    } finally {
      isLoading.value = false;
    }
  }

  String? _msg(Response r) {
    try {
      if (r.body is Map) return r.body['message'] as String?;
    } catch (_) {}
    return null;
  }
}
