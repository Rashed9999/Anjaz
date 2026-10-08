import 'package:get/get.dart';
import 'package:amial_pay/data/api/api_client.dart';

/// CRITICAL-001 — Repo للوصول إلى /me/access
class AccessRepo extends GetxService {
  final ApiClient apiClient;
  AccessRepo({required this.apiClient});

  Future<Response> getAccess() => apiClient.getData('/api/v1/amial/me/access');
  Future<Response> updateBusinessType(String businessType) =>
      apiClient.putData('/api/v1/amial/merchant/business-type', {'business_type': businessType});

  /// AMIAL-VERTICAL-COMPOSE-001 — **قائمةُ القطاعات تُسأل ولا تُكتب هنا.**
  ///
  /// كانت ستَّ بطاقاتٍ محفورةً في ملفّ Dart، فقطاعٌ تُنشئه الإدارةُ اليومَ
  /// لا يستطيع تاجرٌ اختيارَه حتّى نشرةِ متجرٍ جديدة — **مبنيٌّ ولا
  /// يُوصَل إليه.**
  ///
  /// **وبلا مصادقة عمداً**: تُقرأ في شاشة التسجيل قبل وجود حساب.
  Future<Response> businessTypes() =>
      apiClient.getData('/api/v1/amial/business-types');
}
