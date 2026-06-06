import 'package:get/get.dart';
import 'package:amyal_pay/data/api/api_client.dart';

/// CRITICAL-001 — Repo للوصول إلى /me/access
class AccessRepo extends GetxService {
  final ApiClient apiClient;
  AccessRepo({required this.apiClient});

  Future<Response> getAccess() => apiClient.getData('/api/v1/amial/me/access');
  Future<Response> updateBusinessType(String businessType) =>
      apiClient.putData('/api/v1/amial/merchant/business-type', {'business_type': businessType});
}
