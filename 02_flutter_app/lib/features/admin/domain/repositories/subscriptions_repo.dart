import 'package:get/get.dart';
import 'package:amyal_pay/data/api/api_client.dart';

/// CRITICAL-001-SUBS — Repo إدارة الاشتراكات (للأدمن).
class SubscriptionsRepo extends GetxService {
  final ApiClient apiClient;
  SubscriptionsRepo({required this.apiClient});

  static const _base = '/api/v1/amial/admin/subscriptions';

  // Analytics
  Future<Response> summary() => apiClient.getData('$_base/summary');

  // Expiring soon
  Future<Response> expiring({int days = 7}) =>
      apiClient.getData('$_base/expiring?days=$days');

  // Audit log
  Future<Response> log({String? action, int? merchantId, int page = 1, int perPage = 20}) {
    final params = <String, String>{
      'page': '$page', 'per_page': '$perPage',
    };
    if (action != null) params['action'] = action;
    if (merchantId != null) params['merchant_id'] = '$merchantId';
    final qs = params.entries.map((e) => '${e.key}=${e.value}').join('&');
    return apiClient.getData('$_base/log?$qs');
  }

  // Merchant-specific history
  Future<Response> merchantHistory(int merchantId) =>
      apiClient.getData('$_base/merchant/$merchantId/history');

  // Renew (30 days)
  Future<Response> renew(int merchantId, Map<String, dynamic> data) =>
      apiClient.postData('$_base/merchant/$merchantId/renew', data);

  // Extend (custom days)
  Future<Response> extend(int merchantId, Map<String, dynamic> data) =>
      apiClient.postData('$_base/merchant/$merchantId/extend', data);

  // Manual trigger للـ cron
  Future<Response> processExpired() =>
      apiClient.postData('$_base/process-expired', {});
}
