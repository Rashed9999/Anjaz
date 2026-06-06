import 'package:flutter/foundation.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/features/merchant/domain/models/merchant_models.dart';
import 'package:amyal_pay/features/merchant/domain/repositories/merchant_repo.dart';

/// AMIAL-MERCHANT-APP-001 (v1.6)
class MerchantController extends GetxController implements GetxService {
  final MerchantRepo repo;
  MerchantController({required this.repo});

  final Rx<AmyalMerchant?> merchant = Rx<AmyalMerchant?>(null);
  final Rx<AmyalMerchantDashboardStats> stats =
      AmyalMerchantDashboardStats.empty().obs;
  final RxList<AmyalMerchantTransaction> transactions =
      <AmyalMerchantTransaction>[].obs;

  final RxBool isLoading = false.obs;
  final RxBool isSubmitting = false.obs;
  final RxString lastError = ''.obs;

  // ====== State for payment request (QR) ======
  final RxString lastPaymentRequestId = ''.obs;
  final RxString lastPaymentAmount = ''.obs;

  // ====== Profile ======
  Future<void> loadProfile() async {
    try {
      isLoading.value = true;
      final r = await repo.getProfile();
      if (r.statusCode == 200 && r.body is Map) {
        final body = r.body as Map;
        final data = body['meta'] ?? body['data'] ?? body;
        if (data is Map) {
          merchant.value = AmyalMerchant.fromJson(Map<String, dynamic>.from(data));
        }
      }
      await _loadDailyStats();
    } catch (e) {
      if (kDebugMode) debugPrint('loadProfile (merchant): $e');
      lastError.value = 'خطأ في الشبكة';
    } finally {
      isLoading.value = false;
    }
  }

  Future<void> _loadDailyStats() async {
    try {
      final r = await repo.dailyStats();
      if (r.statusCode == 200 && r.body is Map) {
        final meta = (r.body as Map)['meta'];
        if (meta is Map) {
          stats.value = AmyalMerchantDashboardStats.fromJson(
              Map<String, dynamic>.from(meta));
        }
      }
    } catch (e) {
      if (kDebugMode) debugPrint('_loadDailyStats (merchant): $e');
    }
  }

  // ====== Generate Payment Request ======
  Future<bool> requestPayment({
    required String amount,
    String? note,
    String? customerPhone,
  }) async {
    try {
      isSubmitting.value = true;
      lastError.value = '';
      final r = await repo.requestPayment(
        amount: amount,
        note: note,
        customerPhone: customerPhone,
      );
      if ((r.statusCode == 200 || r.statusCode == 201) &&
          r.body is Map &&
          (r.body['success'] == true ||
              r.body['message']?.toString().toLowerCase().contains('success') ==
                  true)) {
        final meta = r.body['meta'] ?? r.body['data'] ?? {};
        if (meta is Map) {
          lastPaymentRequestId.value = (meta['id'] ?? meta['transaction_id'] ?? '').toString();
        }
        lastPaymentAmount.value = amount;
        return true;
      }
      lastError.value = _msg(r) ?? 'فشلت العملية';
      return false;
    } catch (e) {
      lastError.value = 'خطأ في الشبكة';
      return false;
    } finally {
      isSubmitting.value = false;
    }
  }

  Future<void> loadTransactions() async {
    try {
      isLoading.value = true;
      final r = await repo.transactionHistory();
      if (r.statusCode == 200 && r.body is Map) {
        final list = (r.body['data'] ?? r.body['meta']?['data'] ?? []) as List;
        transactions.value = list
            .map((j) => AmyalMerchantTransaction.fromJson(
                Map<String, dynamic>.from(j)))
            .toList();
      }
    } catch (e) {
      if (kDebugMode) debugPrint('loadTransactions (merchant): $e');
    } finally {
      isLoading.value = false;
    }
  }

  Future<bool> processRefund({
    required String originalTransactionId,
    required String amount,
    String? reason,
  }) async {
    try {
      isSubmitting.value = true;
      lastError.value = '';
      final r = await repo.processRefund(
        originalTransactionId: originalTransactionId,
        amount: amount,
        reason: reason,
      );
      if ((r.statusCode == 200 || r.statusCode == 201) &&
          r.body is Map &&
          r.body['success'] == true) {
        await loadProfile();
        return true;
      }
      lastError.value = _msg(r) ?? 'فشل الاسترجاع';
      return false;
    } catch (e) {
      lastError.value = 'خطأ في الشبكة';
      return false;
    } finally {
      isSubmitting.value = false;
    }
  }

  String? _msg(Response r) {
    try {
      if (r.body is Map) return r.body['message']?.toString();
    } catch (_) {}
    return null;
  }
}
