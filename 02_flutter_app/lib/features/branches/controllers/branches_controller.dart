import 'package:get/get.dart';
import 'package:amyal_pay/data/api/api_client.dart';
import 'package:amyal_pay/features/branches/domain/repositories/branches_repo.dart';
import 'package:amyal_pay/features/plans/screens/my_usage_screen.dart';

/// P1-BRANCHES — متحكّم الفروع.
class BranchesController extends GetxController implements GetxService {
  final BranchesRepo repo;
  BranchesController({required this.repo});

  final RxList<Map<String, dynamic>> branches = <Map<String, dynamic>>[].obs;
  final Rx<Map<String, dynamic>?> currentReport = Rx<Map<String, dynamic>?>(null);
  final RxBool isLoading = false.obs;
  final RxString lastError = ''.obs;

  // الفرع النشط حالياً (لاستخدامه في X-Amial-Branch-ID header)
  final RxInt activeBranchId = 0.obs; // 0 = الافتراضي

  Future<void> loadBranches({bool activeOnly = false}) async {
    try {
      isLoading.value = true;
      final r = await repo.list(activeOnly: activeOnly);
      if (_ok(r)) {
        branches.assignAll(((r.body['meta']?['branches'] ?? []) as List)
            .map((e) => Map<String, dynamic>.from(e as Map)).toList());
        // الافتراضي إذا لم يُختر فرع
        if (activeBranchId.value == 0) {
          final def = branches.firstWhereOrNull((b) => b['is_default'] == true);
          if (def != null) activeBranchId.value = def['id'] as int;
        }
      }
    } catch (_) { lastError.value = 'خطأ في الشبكة'; }
    finally { isLoading.value = false; }
  }

  Future<bool> create(Map<String, dynamic> data) async {
    return _action(() => repo.create(data));
  }

  Future<bool> update(int id, Map<String, dynamic> data) async {
    return _action(() => repo.update(id, data));
  }

  Future<bool> delete(int id) async {
    return _action(() => repo.destroy(id));
  }

  Future<bool> setDefault(int id) async {
    return _action(() => repo.setDefault(id));
  }

  Future<void> loadReport(int branchId, {String? from, String? to}) async {
    try {
      isLoading.value = true;
      final r = await repo.report(branchId, from: from, to: to);
      if (_ok(r)) currentReport.value = Map<String, dynamic>.from(r.body['meta'] as Map);
    } catch (_) { lastError.value = 'خطأ'; }
    finally { isLoading.value = false; }
  }

  void switchBranch(int branchId) {
    activeBranchId.value = branchId;
    // P1-BRANCHES — حقن في كل request قادم
    try {
      Get.find<ApiClient>().setActiveBranchId(branchId);
    } catch (_) {}
  }

  Map<String, dynamic>? get activeBranch =>
      branches.firstWhereOrNull((b) => b['id'] == activeBranchId.value);

  // ============ Helpers ============

  Future<bool> _action(Future<Response> Function() fn) async {
    try {
      isLoading.value = true;
      lastError.value = '';
      final r = await fn();
      // 402 = حدّ الخطّة → نعرض UsageLimitDialog
      if (await UsageLimitDialog.handleIfLimitExceeded(r)) return false;
      if (_ok(r)) {
        await loadBranches();
        return true;
      }
      lastError.value = _msg(r) ?? 'فشل';
      return false;
    } catch (_) { lastError.value = 'خطأ في الشبكة'; return false; }
    finally { isLoading.value = false; }
  }

  bool _ok(Response r) =>
      (r.statusCode == 200 || r.statusCode == 201) &&
      r.body is Map && r.body['success'] == true;

  String? _msg(Response r) =>
      r.body is Map ? r.body['message']?.toString() : null;
}
