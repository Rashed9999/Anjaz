import 'package:get/get.dart';
import 'package:amial_pay/features/suppliers/domain/repositories/suppliers_repo.dart';
import 'package:amial_pay/helper/amial_errors.dart';

/// AMIAL-SUPPLIERS-001 — متحكم الموردين وأوامر الشراء.
class SuppliersController extends GetxController implements GetxService {
  final SuppliersRepo repo;
  SuppliersController({required this.repo});

  final RxList<Map<String, dynamic>> suppliers = <Map<String, dynamic>>[].obs;
  final Rx<Map<String, dynamic>> totals = Rx<Map<String, dynamic>>({});
  final RxList<Map<String, dynamic>> orders = <Map<String, dynamic>>[].obs;

  final RxBool isLoading = false.obs;
  final RxBool isSubmitting = false.obs;
  final RxString lastError = ''.obs;

  Future<void> loadAll() async {
    try {
      isLoading.value = true;
      lastError.value = '';
      final results = await Future.wait([repo.list(), repo.poList()]);
      final s = results[0];
      if (_ok(s)) {
        totals.value =
            Map<String, dynamic>.from((s.body['meta']?['totals'] ?? {}) as Map);
        suppliers.assignAll(((s.body['meta']?['suppliers'] ?? []) as List)
            .map((e) => Map<String, dynamic>.from(e as Map))
            .toList());
      } else {
        lastError.value = _msg(s) ?? 'تعذّر تحميل الموردين';
      }
      final o = results[1];
      if (_ok(o)) {
        orders.assignAll(((o.body['meta']?['orders'] ?? []) as List)
            .map((e) => Map<String, dynamic>.from(e as Map))
            .toList());
      }
    } catch (_) {
      lastError.value = 'خطأ في الشبكة';
    } finally {
      isLoading.value = false;
    }
  }

  Future<bool> createSupplier(Map<String, dynamic> data) =>
      _submit(() => repo.create(data), 'فشل حفظ المورد');

  Future<bool> payment(int id, String amount, {String? note}) =>
      _submit(() => repo.payment(id, amount, note: note), 'فشل السداد');

  Future<bool> poCreate(Map<String, dynamic> data) =>
      _submit(() => repo.poCreate(data), 'فشل إنشاء أمر الشراء');

  Future<bool> poApprove(int id) =>
      _submit(() => repo.poApprove(id), 'فشل الاعتماد');

  /// AMIAL-DAILY-MOVEMENT-001 — `paidNow`: ما دُفع نقداً لحظةَ الاستلام.
  Future<bool> poReceive(int id, List<Map<String, dynamic>> items,
          {String? paidNow}) =>
      _submit(() => repo.poReceive(id, items, paidNow: paidNow),
          'فشل الاستلام');

  Future<bool> poCancel(int id) =>
      _submit(() => repo.poCancel(id), 'فشل الإلغاء');

  // ── مرتجعات الشراء (AMIAL-DAILY-MOVEMENT-001) ──────────────────────

  Future<bool> prCreate(Map<String, dynamic> data) =>
      _submit(() => repo.prCreate(data), 'فشل تسجيل المرتجع');

  Future<bool> prApprove(int id) =>
      _submit(() => repo.prApprove(id), 'فشل اعتماد المرتجع');

  Future<bool> prReject(int id, String reason) =>
      _submit(() => repo.prReject(id, reason), 'فشل رفض المرتجع');

  Future<List<Map<String, dynamic>>> prList({String status = 'all'}) async {
    try {
      final r = await repo.prList(status: status);
      if (_ok(r)) {
        return ((r.body['meta']?['returns'] ?? []) as List)
            .map((e) => Map<String, dynamic>.from(e as Map))
            .toList();
      }
      lastError.value = _msg(r) ?? 'تعذّر تحميل المرتجعات';
    } catch (_) {
      lastError.value = 'خطأ في الشبكة';
    }
    return [];
  }

  Future<Map<String, dynamic>?> poShow(int id) async {
    try {
      final r = await repo.poShow(id);
      if (_ok(r)) {
        return Map<String, dynamic>.from(
            (r.body['meta']?['order'] ?? {}) as Map);
      }
      lastError.value = _msg(r) ?? 'غير موجود';
    } catch (_) {
      lastError.value = 'خطأ في الشبكة';
    }
    return null;
  }

  Future<Map<String, dynamic>?> supplierShow(int id) async {
    try {
      final r = await repo.show(id);
      if (_ok(r)) {
        return Map<String, dynamic>.from((r.body['meta'] ?? {}) as Map);
      }
    } catch (_) {}
    return null;
  }

  Future<bool> _submit(
      Future<Response> Function() call, String fallback) async {
    try {
      isSubmitting.value = true;
      lastError.value = '';
      final r = await call();
      if (_ok(r)) {
        await loadAll();
        return true;
      }
      lastError.value = _msg(r) ?? fallback;
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
      if (r.body is Map) {
        return AmialErrors.arabize(
          r.body['message']?.toString(),
          code: r.body['code']?.toString(),
        );
      }
    } catch (_) {}
    return null;
  }
}
