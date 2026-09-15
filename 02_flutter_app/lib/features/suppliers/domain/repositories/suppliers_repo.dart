import 'package:get/get.dart';
import 'package:amial_pay/data/api/api_client.dart';
import 'package:amial_pay/data/api/idempotency_key_generator.dart';

/// AMIAL-SUPPLIERS-001 — مستودع الموردين وأوامر الشراء.
class SuppliersRepo extends GetxService {
  final ApiClient apiClient;
  SuppliersRepo({required this.apiClient});

  static const _s = '/api/v1/amial/merchant/suppliers';
  static const _po = '/api/v1/amial/merchant/purchase-orders';

  Future<Response> list() => apiClient.getData(_s);

  Future<Response> create(Map<String, dynamic> data) =>
      apiClient.postData(_s, data);

  Future<Response> show(int id) => apiClient.getData('$_s/$id');

  Future<Response> payment(int id, String amount, {String? note}) =>
      apiClient.postData(
        '$_s/$id/payment',
        {'amount': amount, if (note != null && note.isNotEmpty) 'note': note},
        idempotencyKey:
            IdempotencyKeyGenerator.forFinancialAction('supplier_payment'),
      );

  Future<Response> poList({String status = 'all'}) =>
      apiClient.getData('$_po?status=$status');

  Future<Response> poCreate(Map<String, dynamic> data) => apiClient.postData(
        _po,
        data,
        idempotencyKey: IdempotencyKeyGenerator.forFinancialAction('po_create'),
      );

  Future<Response> poShow(int id) => apiClient.getData('$_po/$id');

  Future<Response> poApprove(int id) =>
      apiClient.postData('$_po/$id/approve', {});

  /// AMIAL-DAILY-MOVEMENT-001 — **و`paidNow` تجعل الاستلامَ شراءً نقديّاً.**
  ///
  /// كان كلُّ استلامٍ يرفع دينَ المورد بلا استثناء، فمن اشترى نقداً من
  /// مورّدٍ عابرٍ لا يجد إلّا خمسَ خطوات — أو لا يسجّل. **والصفرُ يُرسَل
  /// ولا يُرسَل `null`**: الخادمُ يقرأ الغيابَ صفراً هنا عمداً، لأنّ
  /// «لم يُدفَع شيءٌ عند الاستلام» معنىً مقصودٌ لا مجهول.
  Future<Response> poReceive(int id, List<Map<String, dynamic>> items,
          {String? paidNow, int? locationId}) =>
      apiClient.postData(
        '$_po/$id/receive',
        {
          'items': items,
          if (paidNow != null && paidNow.isNotEmpty) 'paid_now': paidNow,
          // **وصفرٌ ليس موقعاً** — والشرطُ المركّب يقول ذلك ويُرضي
          // المُحلِّل معاً.
          if (locationId != null && locationId > 0)
            'location_id': locationId,
        },
        idempotencyKey:
            IdempotencyKeyGenerator.forFinancialAction('po_receive'),
      );

  Future<Response> poCancel(int id) =>
      apiClient.postData('$_po/$id/cancel', {});

  // ── مرتجعات الشراء (AMIAL-DAILY-MOVEMENT-001) ──────────────────────

  static const _pr = '/api/v1/amial/merchant/purchase-returns';

  Future<Response> prList({String status = 'all'}) =>
      apiClient.getData('$_pr?status=$status');

  Future<Response> prShow(int id) => apiClient.getData('$_pr/$id');

  Future<Response> prCreate(Map<String, dynamic> data) => apiClient.postData(
        _pr,
        data,
        idempotencyKey:
            IdempotencyKeyGenerator.forFinancialAction('purchase_return'),
      );

  Future<Response> prApprove(int id) =>
      apiClient.postData('$_pr/$id/approve', {});

  Future<Response> prReject(int id, String reason) =>
      apiClient.postData('$_pr/$id/reject', {'reason': reason});
}
