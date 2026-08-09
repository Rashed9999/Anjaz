import 'package:get/get.dart';
import 'package:amial_pay/data/api/api_client.dart';

/// AMIAL-FUEL-VERTICAL-001 · المرحلة ٨ — مستودعُ القطاع.
///
/// **ولا نداءَ HTTP خارج هذه الطبقة.** الشاشةُ تنادي المتحكّم، والمتحكّم
/// ينادي المستودع، والمستودع وحدَه يعرف المسارات.
class FuelVerticalRepo extends GetxService {
  final ApiClient apiClient;
  FuelVerticalRepo({required this.apiClient});

  static const _base = '/api/v1/amial/merchant/fuel';

  // ── مركز العمليّات ───────────────────────────────────────────────
  Future<Response> ops() => apiClient.getData('$_base/ops');

  // ── الصلاحيّات ───────────────────────────────────────────────────
  //
  // **تُبنى منها القائمةُ كلُّها**: زرٌّ لا صلاحيّةَ له لا يُرسم.
  Future<Response> myPermissions() => apiClient.getData('$_base/me/permissions');
  Future<Response> roles() => apiClient.getData('$_base/roles');
  Future<Response> seedRoles() => apiClient.postData('$_base/roles/seed', {});

  // ── الخزّانات والمسدسات ──────────────────────────────────────────
  Future<Response> tanks() => apiClient.getData('$_base/tanks');

  Future<Response> addTank(Map<String, dynamic> data) =>
      apiClient.postData('$_base/tanks', data);

  Future<Response> recordDip(int tankId, Map<String, dynamic> data) =>
      apiClient.postData('$_base/tanks/$tankId/dip', data);

  Future<Response> addNozzle(int pumpId, Map<String, dynamic> data) =>
      apiClient.postData('$_base/pumps/$pumpId/nozzles', data);

  Future<Response> linkNozzleToTank(int nozzleId, int tankId) =>
      apiClient.postData('$_base/nozzles/$nozzleId/tank', {'tank_id': tankId});

  // ── التوريدات ────────────────────────────────────────────────────
  Future<Response> deliveries() => apiClient.getData('$_base/deliveries');

  Future<Response> receiveDelivery(Map<String, dynamic> data) =>
      apiClient.postData('$_base/deliveries', data);

  Future<Response> verifyDelivery(int id, String dipBefore, String dipAfter) =>
      apiClient.postData('$_base/deliveries/$id/verify', {
        'dip_before_liters': dipBefore,
        'dip_after_liters': dipAfter,
      });

  Future<Response> postDelivery(int id) =>
      apiClient.postData('$_base/deliveries/$id/post', {});

  Future<Response> addSupplier(Map<String, dynamic> data) =>
      apiClient.postData('$_base/suppliers', data);

  // ── المصالحة ─────────────────────────────────────────────────────
  Future<Response> reconciliationPreview(int tankId,
          {String? from, String? to, String? actual}) =>
      apiClient.getData('$_base/tanks/$tankId/reconciliation', query: {
        if (from != null) 'from': from,
        if (to != null) 'to': to,
        if (actual != null) 'actual_closing_liters': actual,
      });

  Future<Response> reconcile(int tankId, Map<String, dynamic> data) =>
      apiClient.postData('$_base/tanks/$tankId/reconcile', data);

  Future<Response> stockVariances() => apiClient.getData('$_base/stock-variances');

  Future<Response> resolveVariance(int id, String note, String status) =>
      apiClient.postData('$_base/stock-variances/$id/resolve',
          {'note': note, 'status': status});

  // ── الأسعار ──────────────────────────────────────────────────────
  Future<Response> proposePrice(Map<String, dynamic> data) =>
      apiClient.postData('$_base/prices/propose', data);

  Future<Response> pendingPrices() => apiClient.getData('$_base/prices/pending');

  Future<Response> approvePrice(int id) =>
      apiClient.postData('$_base/prices/$id/approve', {});

  // ── نقد الوردية ──────────────────────────────────────────────────
  Future<Response> shiftCash(int shiftId) =>
      apiClient.getData('$_base/shifts/$shiftId/cash');

  Future<Response> addCashMovement(int shiftId, Map<String, dynamic> data) =>
      apiClient.postData('$_base/shifts/$shiftId/cash', data);
}
