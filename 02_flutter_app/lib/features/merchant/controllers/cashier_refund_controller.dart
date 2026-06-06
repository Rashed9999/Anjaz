import 'package:get/get.dart';
import 'package:amyal_pay/features/merchant/domain/repositories/cashier_refund_repo.dart';

/// AMIAL-CASHIER-REFUND-001 — متحكّم المرتجعات.
class CashierRefundController extends GetxController implements GetxService {
  final CashierRefundRepo repo;
  CashierRefundController({required this.repo});

  /// معلومات الاسترداد (sale، refunded_so_far، remaining، available_methods)
  final Rx<Map<String, dynamic>?> refundableInfo = Rx<Map<String, dynamic>?>(null);

  /// قائمة المرتجعات
  final RxList<Map<String, dynamic>> refunds = <Map<String, dynamic>>[].obs;

  final RxBool isLoadingInfo = false.obs;
  final RxBool isLoadingList = false.obs;
  final RxBool isSubmitting = false.obs;
  final RxString lastError = ''.obs;

  /// آخر مرتجع تم إنشاؤه (لعرض النتيجة)
  final Rx<Map<String, dynamic>?> lastRefund = Rx<Map<String, dynamic>?>(null);

  Future<void> loadRefundable(String saleUlid) async {
    try {
      isLoadingInfo.value = true;
      refundableInfo.value = null;
      final r = await repo.refundable(saleUlid);
      if (_ok(r)) {
        refundableInfo.value = Map<String, dynamic>.from((r.body['meta'] ?? {}) as Map);
      } else {
        lastError.value = _msg(r) ?? 'فشل جلب البيانات';
      }
    } catch (_) {
      lastError.value = 'خطأ في الشبكة';
    } finally {
      isLoadingInfo.value = false;
    }
  }

  /// أنشئ مرتجع. يرجع true إن نجح (مكتمل أو معلّق).
  Future<bool> create({
    required String saleUlid,
    required String amount,
    required String refundMethod,
    List<Map<String, dynamic>>? items,
    String? reason,
  }) async {
    try {
      isSubmitting.value = true;
      lastError.value = '';
      final body = <String, dynamic>{
        'amount': amount,
        'refund_method': refundMethod,
        if (items != null && items.isNotEmpty) 'items': items,
        if (reason != null && reason.isNotEmpty) 'reason': reason,
      };
      final r = await repo.create(saleUlid, body);
      if (_ok(r) || r.statusCode == 202) {
        lastRefund.value = Map<String, dynamic>.from((r.body['meta']?['refund'] ?? {}) as Map);
        return true;
      }
      lastError.value = _msg(r) ?? 'فشل إنشاء المرتجع';
      return false;
    } catch (_) {
      lastError.value = 'خطأ في الشبكة';
      return false;
    } finally {
      isSubmitting.value = false;
    }
  }

  Future<void> loadList() async {
    try {
      isLoadingList.value = true;
      final r = await repo.list();
      if (_ok(r)) {
        final list = (r.body['meta']?['refunds'] ?? []) as List;
        refunds.assignAll(list.map((e) => Map<String, dynamic>.from(e as Map)).toList());
      }
    } catch (_) {} finally {
      isLoadingList.value = false;
    }
  }

  bool _ok(Response r) =>
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
