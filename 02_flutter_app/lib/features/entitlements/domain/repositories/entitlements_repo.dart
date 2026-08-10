import 'package:get/get.dart';
import 'package:amial_pay/data/api/api_client.dart';

/// AMIAL-ENTITLEMENTS-001 — مستودعُ ملفّ الخدمات.
class EntitlementsRepo extends GetxService {
  final ApiClient apiClient;
  EntitlementsRepo({required this.apiClient});

  static const _base = '/api/v1/amial/me';

  /// **نداءٌ واحدٌ يردّ كلَّ قدرات المنصّة بحالة كلٍّ لهذا الحساب.**
  Future<Response> manifest() => apiClient.getData('$_base/entitlements');

  Future<Response> one(String code) => apiClient.getData('$_base/entitlements/$code');

  /// جدولُ المقارنة — مولَّدٌ من السجلّ لا مكتوبٌ في التطبيق.
  Future<Response> plans({String? businessType}) => apiClient.getData(
        '/api/v1/amial/plans/capabilities',
        query: businessType != null ? {'business_type': businessType} : null,
      );
}
