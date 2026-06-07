import 'package:get/get.dart';
import 'package:amyal_pay/data/api/api_client.dart';

/// P1-RBAC — Repo + Controller للأدوار والصلاحيات (للموظّفين).
class PosRbacRepo extends GetxService {
  final ApiClient apiClient;
  PosRbacRepo({required this.apiClient});

  static const _base = '/api/v1/amial/merchant/rbac';

  Future<Response> permissions() => apiClient.getData('$_base/permissions');
  Future<Response> roles() => apiClient.getData('$_base/roles');
  Future<Response> posUserRoles(int posUserId) =>
      apiClient.getData('$_base/pos-users/$posUserId/roles');
  Future<Response> assignRole(int posUserId, int roleId, {int? branchScopeId}) =>
      apiClient.postData('$_base/pos-users/$posUserId/assign-role', {
        'role_id': roleId,
        'branch_scope_id': ?branchScopeId,
      });
  Future<Response> revokeRole(int posUserId, int roleId, {int? branchScopeId}) =>
      apiClient.postData('$_base/pos-users/$posUserId/revoke-role', {
        'role_id': roleId,
        'branch_scope_id': ?branchScopeId,
      });
}

class PosRbacController extends GetxController implements GetxService {
  final PosRbacRepo repo;
  PosRbacController({required this.repo});

  final RxList<Map<String, dynamic>> roles = <Map<String, dynamic>>[].obs;
  final Rx<Map<String, dynamic>?> permissionsByCategory = Rx<Map<String, dynamic>?>(null);
  final RxList<Map<String, dynamic>> currentPosUserRoles = <Map<String, dynamic>>[].obs;
  final RxBool isLoading = false.obs;
  final RxString lastError = ''.obs;

  Future<void> loadRoles() async {
    try {
      isLoading.value = true;
      final r = await repo.roles();
      if (_ok(r)) {
        roles.assignAll(((r.body['meta']?['roles'] ?? []) as List)
            .map((e) => Map<String, dynamic>.from(e as Map)).toList());
      }
    } catch (_) {} finally { isLoading.value = false; }
  }

  Future<void> loadPermissions() async {
    try {
      final r = await repo.permissions();
      if (_ok(r)) {
        permissionsByCategory.value = Map<String, dynamic>.from(
            (r.body['meta']?['by_category'] ?? {}) as Map);
      }
    } catch (_) {}
  }

  Future<void> loadPosUserRoles(int posUserId) async {
    try {
      isLoading.value = true;
      final r = await repo.posUserRoles(posUserId);
      if (_ok(r)) {
        currentPosUserRoles.assignAll(((r.body['meta']?['roles'] ?? []) as List)
            .map((e) => Map<String, dynamic>.from(e as Map)).toList());
      }
    } catch (_) {} finally { isLoading.value = false; }
  }

  Future<bool> assignRole(int posUserId, int roleId, {int? branchScopeId}) async {
    try {
      final r = await repo.assignRole(posUserId, roleId, branchScopeId: branchScopeId);
      if (_ok(r)) { await loadPosUserRoles(posUserId); return true; }
      lastError.value = _msg(r) ?? 'فشل';
      return false;
    } catch (_) { lastError.value = 'خطأ'; return false; }
  }

  Future<bool> revokeRole(int posUserId, int roleId, {int? branchScopeId}) async {
    try {
      final r = await repo.revokeRole(posUserId, roleId, branchScopeId: branchScopeId);
      if (_ok(r)) { await loadPosUserRoles(posUserId); return true; }
      return false;
    } catch (_) { return false; }
  }

  bool _ok(Response r) =>
      (r.statusCode == 200 || r.statusCode == 201) &&
      r.body is Map && r.body['success'] == true;
  String? _msg(Response r) => r.body is Map ? r.body['message']?.toString() : null;
}
