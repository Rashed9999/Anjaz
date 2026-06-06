import 'package:flutter/foundation.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/features/safe_payment/domain/models/safe_payment_models.dart';
import 'package:amyal_pay/features/safe_payment/domain/repositories/safe_payment_repo.dart';

/// AMIAL-SAFE-PAYMENT-001 (v1.1)
class SafePaymentController extends GetxController implements GetxService {
  final SafePaymentRepo repo;
  SafePaymentController({required this.repo});

  final RxList<AmyalSafePayment> payments = <AmyalSafePayment>[].obs;
  final Rx<AmyalSafePayment?> selectedPayment = Rx<AmyalSafePayment?>(null);
  final Rx<AmyalSafePaymentActions> availableActions =
      AmyalSafePaymentActions.empty().obs;
  final RxString yourRole = ''.obs; // buyer | seller

  final RxBool isLoading = false.obs;
  final RxBool isSubmitting = false.obs;
  final RxString lastError = ''.obs;

  Future<void> loadList({String role = 'all', String? status}) async {
    try {
      isLoading.value = true;
      final r = await repo.list(role: role, status: status);
      if (r.statusCode == 200 && r.body is Map) {
        final items = ((r.body['meta'] ?? {})['items'] as List? ?? []);
        payments.value = items
            .map((j) => AmyalSafePayment.fromJson(Map<String, dynamic>.from(j)))
            .toList();
        lastError.value = '';
      } else {
        lastError.value = _msg(r) ?? 'فشل التحميل';
      }
    } catch (e) {
      if (kDebugMode) debugPrint('loadList: $e');
      lastError.value = 'خطأ في الشبكة';
    } finally {
      isLoading.value = false;
    }
  }

  Future<bool> loadDetail(String ulid) async {
    try {
      isLoading.value = true;
      final r = await repo.show(ulid);
      if (r.statusCode == 200 && r.body is Map) {
        final meta = Map<String, dynamic>.from(r.body['meta'] ?? {});
        selectedPayment.value = AmyalSafePayment.fromJson(
            Map<String, dynamic>.from(meta['payment'] ?? {}));
        yourRole.value = (meta['your_role'] ?? '').toString();
        availableActions.value = AmyalSafePaymentActions.fromJson(
            Map<String, dynamic>.from(meta['can_actions'] ?? {}));
        lastError.value = '';
        return true;
      }
      lastError.value = _msg(r) ?? 'غير موجود';
      return false;
    } catch (e) {
      lastError.value = 'خطأ في الشبكة';
      return false;
    } finally {
      isLoading.value = false;
    }
  }

  Future<bool> create({
    required String sellerPhone,
    required String title,
    required String description,
    required String amount,
    String? deliveryTerms,
  }) async {
    try {
      isSubmitting.value = true;
      final r = await repo.create(
        sellerPhone: sellerPhone,
        title: title,
        description: description,
        amount: amount,
        deliveryTerms: deliveryTerms,
      );
      if ((r.statusCode == 200 || r.statusCode == 201) &&
          r.body is Map &&
          r.body['success'] == true) {
        await loadList();
        return true;
      }
      lastError.value = _msg(r) ?? 'فشل الإنشاء';
      return false;
    } catch (e) {
      lastError.value = 'خطأ في الشبكة';
      return false;
    } finally {
      isSubmitting.value = false;
    }
  }

  // ============ Seller actions ============
  Future<bool> sellerAccept(String ulid, {String? note}) =>
      _executeAction(() => repo.sellerAccept(ulid, note: note), ulid);

  Future<bool> sellerReject(String ulid, String reason) =>
      _executeAction(() => repo.sellerReject(ulid, reason), ulid);

  Future<bool> sellerMarkInDelivery(String ulid, {String? note}) =>
      _executeAction(() => repo.sellerMarkInDelivery(ulid, note: note), ulid);

  Future<bool> sellerMarkDelivered(String ulid, {String? note}) =>
      _executeAction(() => repo.sellerMarkDelivered(ulid, note: note), ulid);

  // ============ Buyer actions ============
  Future<bool> buyerConfirm(String ulid) =>
      _executeAction(() => repo.buyerConfirm(ulid), ulid);

  Future<bool> buyerCancel(String ulid, String reason) =>
      _executeAction(() => repo.buyerCancel(ulid, reason), ulid);

  Future<bool> buyerDispute(String ulid, String reason, {List<String>? attachments}) =>
      _executeAction(() => repo.buyerDispute(ulid, reason, attachments: attachments), ulid);

  Future<bool> _executeAction(
      Future<Response> Function() action, String ulid) async {
    try {
      isSubmitting.value = true;
      final r = await action();
      if ((r.statusCode == 200 || r.statusCode == 201) &&
          r.body is Map &&
          r.body['success'] == true) {
        await loadDetail(ulid);
        await loadList();
        return true;
      }
      lastError.value = _msg(r) ?? 'فشل التنفيذ';
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
      if (r.body is Map) return r.body['message'] as String?;
    } catch (_) {}
    return null;
  }
}
