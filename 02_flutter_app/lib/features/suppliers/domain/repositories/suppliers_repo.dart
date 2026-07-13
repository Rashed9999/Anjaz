import 'package:get/get.dart';
import 'package:amyal_pay/data/api/api_client.dart';
import 'package:amyal_pay/data/api/idempotency_key_generator.dart';

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

  Future<Response> poReceive(int id, List<Map<String, dynamic>> items) =>
      apiClient.postData(
        '$_po/$id/receive',
        {'items': items},
        idempotencyKey:
            IdempotencyKeyGenerator.forFinancialAction('po_receive'),
      );

  Future<Response> poCancel(int id) =>
      apiClient.postData('$_po/$id/cancel', {});
}
