import 'package:get/get.dart';
import 'package:amial_pay/features/merchant/domain/repositories/customer_credit_repo.dart';

/// AMIAL-CUSTOMER-CREDIT-001 — متحكّم نظام الديون.
class CustomerCreditController extends GetxController implements GetxService {
  final CustomerCreditRepo repo;
  CustomerCreditController({required this.repo});

  // Dashboard
  final Rx<Map<String, dynamic>?> dashboardData = Rx<Map<String, dynamic>?>(null);
  final RxBool isLoadingDashboard = false.obs;

  // قائمة العملاء
  final RxList<Map<String, dynamic>> customers = <Map<String, dynamic>>[].obs;
  final RxBool isLoadingCustomers = false.obs;
  final RxString currentFilter = ''.obs; // '', debtors, over_limit, paid_up
  final RxString currentSearch = ''.obs;

  // كشف الحساب
  final Rx<Map<String, dynamic>?> statement = Rx<Map<String, dynamic>?>(null);
  final RxBool isLoadingStatement = false.obs;

  // الحالة العامة
  final RxBool isSubmitting = false.obs;
  final RxString lastError = ''.obs;

  // ---- Dashboard ----
  Future<void> loadDashboard() async {
    try {
      isLoadingDashboard.value = true;
      final r = await repo.dashboard();
      if (_ok(r)) dashboardData.value = Map<String, dynamic>.from((r.body['meta'] ?? {}) as Map);
    } catch (_) {} finally {
      isLoadingDashboard.value = false;
    }
  }

  // ---- العملاء ----
  Future<void> loadCustomers({String? search, String? filter}) async {
    try {
      isLoadingCustomers.value = true;
      currentSearch.value = search ?? '';
      currentFilter.value = filter ?? '';
      final r = await repo.listCustomers(search: search, filter: filter);
      if (_ok(r)) {
        final list = (r.body['meta']?['customers'] ?? []) as List;
        customers.assignAll(list.map((e) => Map<String, dynamic>.from(e as Map)).toList());
      }
    } catch (_) {} finally {
      isLoadingCustomers.value = false;
    }
  }

  Future<bool> upsertCustomer(Map<String, dynamic> data) async {
    try {
      isSubmitting.value = true;
      lastError.value = '';
      final r = await repo.upsertCustomer(data);
      if (_ok(r)) {
        await loadCustomers(search: currentSearch.value.isEmpty ? null : currentSearch.value,
                            filter: currentFilter.value.isEmpty ? null : currentFilter.value);
        return true;
      }
      lastError.value = _msg(r) ?? 'فشل حفظ العميل';
      return false;
    } catch (_) {
      lastError.value = 'خطأ في الشبكة';
      return false;
    } finally {
      isSubmitting.value = false;
    }
  }

  // ---- كشف الحساب ----
  Future<void> loadStatement(int customerId, {String? from, String? to}) async {
    try {
      isLoadingStatement.value = true;
      final r = await repo.statement(customerId, from: from, to: to);
      if (_ok(r)) statement.value = Map<String, dynamic>.from((r.body['meta'] ?? {}) as Map);
    } catch (_) {} finally {
      isLoadingStatement.value = false;
    }
  }

  // ---- العمليات ----
  Future<bool> recordPayment(int customerId, String amount, {String? note}) async {
    return _runMovement(() => repo.recordPayment(customerId, amount, note: note));
  }

  Future<bool> recordReturn(int customerId, String amount, {String? note}) async {
    return _runMovement(() => repo.recordReturn(customerId, amount, note: note));
  }

  Future<bool> recordAdjustment(int customerId, String signedAmount, String note) async {
    return _runMovement(() => repo.recordAdjustment(customerId, signedAmount, note));
  }

  Future<bool> _runMovement(Future<Response> Function() action) async {
    try {
      isSubmitting.value = true;
      lastError.value = '';
      final r = await action();
      if (_ok(r)) return true;
      lastError.value = _msg(r) ?? 'فشلت العملية';
      return false;
    } catch (_) {
      lastError.value = 'خطأ في الشبكة';
      return false;
    } finally {
      isSubmitting.value = false;
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
