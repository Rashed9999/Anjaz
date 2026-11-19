import 'package:get/get.dart';
import 'package:amial_pay/data/api/api_client.dart';
import 'package:amial_pay/features/merchant/services/merchant_business_api_adapter.dart';

/// AMIAL-CASHIER-REFUND-001 — مستودع المرتجعات.
class CashierRefundRepo extends GetxService {
  final ApiClient apiClient;
  final MerchantBusinessApiAdapter? _injectedBusinessAdapter;
  CashierRefundRepo({
    required this.apiClient,
    MerchantBusinessApiAdapter? businessAdapter,
  }) : _injectedBusinessAdapter = businessAdapter;

  static const _base = '/api/v1/amial/merchant/cashier';

  MerchantBusinessApiAdapter? get _business {
    final adapter = _injectedBusinessAdapter ??
        (Get.isRegistered<MerchantBusinessApiAdapter>()
            ? Get.find<MerchantBusinessApiAdapter>()
            : null);
    return (adapter != null && adapter.isEnabledForActiveMerchant) ? adapter : null;
  }

  /// كم متبقّي للاسترداد + طرق الاسترداد المتاحة
  Future<Response> refundable(String saleUlid) {
    final biz = _business;
    if (biz != null) {
      return biz.saleDetail(saleUlid);
    }
    return apiClient.getData('$_base/sales/$saleUlid/refundable');
  }

  /// إنشاء مرتجع
  Future<Response> create(String saleUlid, Map<String, dynamic> body) {
    final biz = _business;
    if (biz != null) {
      return biz.createReturn(saleUlid, body);
    }
    return apiClient.postData('$_base/sales/$saleUlid/refund', body);
  }

  /// قائمة المرتجعات
  Future<Response> list({int page = 1}) =>
      apiClient.getData('$_base/refunds', query: {'page': page.toString()});

  /// تفاصيل مرتجع
  Future<Response> show(int id) => apiClient.getData('$_base/refunds/$id');
}
