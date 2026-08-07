import 'package:get/get.dart';
import 'package:amial_pay/data/api/api_client.dart';

/// AMIAL-NOTIFICATIONS-001 — مستودع الإشعارات.
class NotificationsCenterRepo extends GetxService {
  final ApiClient apiClient;
  NotificationsCenterRepo({required this.apiClient});

  static const _base = '/api/v1/amial/notifications';

  Future<Response> list({int page = 1, bool unreadOnly = false}) {
    final q = <String, dynamic>{'page': page.toString()};
    if (unreadOnly) q['unread_only'] = '1';
    return apiClient.getData(_base, query: q);
  }

  Future<Response> unreadCount() => apiClient.getData('$_base/unread-count');

  Future<Response> markRead(int id) => apiClient.postData('$_base/$id/read', {});

  Future<Response> markAllRead() => apiClient.postData('$_base/read-all', {});
}
