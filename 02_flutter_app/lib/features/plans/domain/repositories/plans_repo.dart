import 'package:get/get.dart';
import 'package:amial_pay/data/api/api_client.dart';

/// CRITICAL-001-PLANS — Repo كتالوج الخطط + Usage.
class PlansRepo extends GetxService {
  final ApiClient apiClient;
  PlansRepo({required this.apiClient});

  /// المقارنة تأتي من سجل القدرات، ومقيّدة بنوع نشاط التاجر.
  /// بذلك لا ترى الصيدلية مركز التجزئة ولا تخفي باقة الأعمال وصفاتها.
  Future<Response> catalog({String? businessType}) => apiClient.getData(
        '/api/v1/amial/plans/capabilities',
        query: businessType == null ? null : {'business_type': businessType},
      );

  /// GET /usage — snapshot الاستخدام (للتاجر).
  Future<Response> myUsage() => apiClient.getData('/api/v1/amial/usage');
}
