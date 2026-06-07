import 'package:get/get.dart';
import 'package:amyal_pay/data/api/api_client.dart';

/// CRITICAL-001-ADMIN — Repo لوحة الإدارة.
class AdminRepo extends GetxService {
  final ApiClient apiClient;
  AdminRepo({required this.apiClient});

  static const _base = '/api/v1/amial/admin';

  Future<Response> dashboard() => apiClient.getData('$_base/dashboard');

  Future<Response> listMerchants({
    String? search, String? plan, String? businessType,
    String? verificationStatus, int page = 1,
  }) {
    final q = <String, dynamic>{'per_page': '20'};
    if (search != null && search.isNotEmpty) q['search'] = search;
    if (plan != null && plan.isNotEmpty) q['plan'] = plan;
    if (businessType != null && businessType.isNotEmpty) q['business_type'] = businessType;
    if (verificationStatus != null && verificationStatus.isNotEmpty) q['verification_status'] = verificationStatus;
    return apiClient.getData('$_base/merchants', query: q);
  }

  Future<Response> pendingVariances() => apiClient.getData('$_base/variances/pending');

  Future<Response> resolveVariance(int id, String resolution, String? note) =>
      apiClient.postData('$_base/variances/$id/resolve', {
        'resolution': resolution,
        if (note != null && note.isNotEmpty) 'note': note,
      });

  Future<Response> verifyMerchant(int id, String action, String? note) =>
      apiClient.postData('$_base/merchants/$id/verify', {
        'action': action,
        if (note != null && note.isNotEmpty) 'note': note,
      });

  // إعادة استخدام endpoints القديمة من AccessController
  Future<Response> updateMerchantPlan(int id, String plan, {String? expiresAt, String? notes}) =>
      apiClient.postData('$_base/merchants/$id/plan', {
        'plan': plan,
        'expires_at': ?expiresAt,
        'notes': ?notes,
      });
}
