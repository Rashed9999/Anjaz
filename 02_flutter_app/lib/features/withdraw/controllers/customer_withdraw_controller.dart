import 'package:get/get.dart';
import 'package:amial_pay/features/withdraw/domain/repositories/customer_withdraw_repo.dart';
import 'package:amial_pay/helper/amial_errors.dart';

/// AMIAL-CUSTOMER-WITHDRAW-001 — متحكّم السحب من جهة العميل.
class CustomerWithdrawController extends GetxController implements GetxService {
  final CustomerWithdrawRepo repo;
  CustomerWithdrawController({required this.repo});

  /// الطلب النشط الحالي (إن وُجد).
  /// المفاتيح: op_code, amount, fee, total_debit, status, expires_at, request_id.
  final Rx<Map<String, dynamic>?> currentRequest = Rx<Map<String, dynamic>?>(null);

  /// قائمة الطلبات المعلّقة (تشمل currentRequest).
  final RxList<Map<String, dynamic>> pendingRequests = <Map<String, dynamic>>[].obs;

  final RxBool isSubmitting = false.obs;
  final RxBool isLoadingList = false.obs;
  final RxString lastError = ''.obs;
  final RxString lastErrorCode = ''.obs;

  /// إنشاء طلب سحب. يرجع true إن نجح.
  Future<bool> requestWithdraw(String amount) async {
    try {
      isSubmitting.value = true;
      lastError.value = '';
      lastErrorCode.value = '';
      final r = await repo.request(amount);
      if (_ok(r)) {
        final meta = Map<String, dynamic>.from((r.body['meta'] ?? {}) as Map);
        currentRequest.value = meta;
        return true;
      }
      lastError.value = _msg(r) ?? 'فشل إنشاء طلب السحب';
      lastErrorCode.value = '${r.body is Map ? r.body['code'] ?? '' : ''}';
      return false;
    } catch (_) {
      lastError.value = 'خطأ في الشبكة';
      return false;
    } finally {
      isSubmitting.value = false;
    }
  }

  /// جلب الطلبات المعلّقة (متعدّدة محتملاً إن كان عدّة في وقت واحد).
  Future<void> loadPending() async {
    try {
      isLoadingList.value = true;
      final r = await repo.mine();
      if (_ok(r)) {
        final list = (r.body['meta']?['requests'] ?? []) as List;
        pendingRequests.assignAll(
          list.map((e) => Map<String, dynamic>.from(e as Map)).toList(),
        );
        // إن لم يكن هناك currentRequest، اختر آخر طلب نشط
        if (currentRequest.value == null && pendingRequests.isNotEmpty) {
          currentRequest.value = pendingRequests.first;
        }
      }
    } catch (_) {} finally {
      isLoadingList.value = false;
    }
  }

  Future<bool> cancelRequest(int requestId) async {
    try {
      isSubmitting.value = true;
      lastError.value = '';
      final r = await repo.cancel(requestId);
      if (_ok(r)) {
        pendingRequests.removeWhere((e) => e['id'] == requestId || e['request_id'] == requestId);
        if (currentRequest.value != null &&
            (currentRequest.value!['request_id'] == requestId || currentRequest.value!['id'] == requestId)) {
          currentRequest.value = null;
        }
        return true;
      }
      lastError.value = _msg(r) ?? 'فشل الإلغاء';
      return false;
    } catch (_) {
      lastError.value = 'خطأ في الشبكة';
      return false;
    } finally {
      isSubmitting.value = false;
    }
  }

  void clearCurrent() => currentRequest.value = null;

  /// AMIAL-WD-HISTORY-001: سجلّ طلبات السحب (كل الحالات).
  final RxList<Map<String, dynamic>> historyRequests = <Map<String, dynamic>>[].obs;
  final RxBool isLoadingHistory = false.obs;

  Future<void> loadHistory({String status = 'all'}) async {
    try {
      isLoadingHistory.value = true;
      final r = await repo.history(status: status);
      if (_ok(r)) {
        final list = (r.body['meta']?['requests'] ?? []) as List;
        historyRequests.assignAll(
          list.map((e) => Map<String, dynamic>.from(e as Map)).toList(),
        );
      }
    } catch (_) {} finally {
      isLoadingHistory.value = false;
    }
  }

  bool _ok(Response r) =>
      (r.statusCode == 200 || r.statusCode == 201) &&
      r.body is Map &&
      r.body['success'] == true;

  String? _msg(Response r) {
    try {
      if (r.body is Map) {
        // AMIAL-ERRORS-001: تعريب رسائل الخادم الإنجليزية
        return AmialErrors.arabize(
          r.body['message']?.toString(),
          code: r.body['code']?.toString(),
        );
      }
    } catch (_) {}
    return null;
  }
}
