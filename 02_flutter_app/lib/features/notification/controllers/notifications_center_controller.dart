import 'package:get/get.dart';
import 'package:amial_pay/features/notification/domain/reposotories/notifications_center_repo.dart';

/// AMIAL-NOTIFICATIONS-001 — متحكّم مركز الإشعارات.
class NotificationsCenterController extends GetxController implements GetxService {
  final NotificationsCenterRepo repo;
  NotificationsCenterController({required this.repo});

  final RxList<Map<String, dynamic>> items = <Map<String, dynamic>>[].obs;
  final RxInt unreadCount = 0.obs;
  final RxBool isLoading = false.obs;
  final RxBool isSubmitting = false.obs;
  final RxInt currentPage = 1.obs;
  final RxInt lastPage = 1.obs;
  final RxBool unreadOnly = false.obs;

  Future<void> load({bool reset = true}) async {
    try {
      isLoading.value = true;
      final page = reset ? 1 : currentPage.value;
      final r = await repo.list(page: page, unreadOnly: unreadOnly.value);
      if (_ok(r)) {
        final meta = (r.body['meta'] ?? {}) as Map;
        final list = (meta['notifications'] ?? []) as List;
        final p = (meta['pagination'] ?? {}) as Map;
        final mapped = list.map((e) => Map<String, dynamic>.from(e as Map)).toList();
        if (reset) {
          items.assignAll(mapped);
        } else {
          items.addAll(mapped);
        }
        currentPage.value = int.tryParse('${p['current_page'] ?? 1}') ?? 1;
        lastPage.value = int.tryParse('${p['last_page'] ?? 1}') ?? 1;
        unreadCount.value = int.tryParse('${p['unread_count'] ?? 0}') ?? 0;
      }
    } catch (_) {} finally {
      isLoading.value = false;
    }
  }

  Future<void> loadMore() async {
    if (currentPage.value >= lastPage.value || isLoading.value) return;
    currentPage.value += 1;
    await load(reset: false);
  }

  Future<void> refreshUnreadCount() async {
    try {
      final r = await repo.unreadCount();
      if (_ok(r)) {
        unreadCount.value = int.tryParse('${r.body['meta']?['unread_count'] ?? 0}') ?? 0;
      }
    } catch (_) {}
  }

  Future<bool> markRead(int id) async {
    try {
      isSubmitting.value = true;
      final r = await repo.markRead(id);
      if (_ok(r)) {
        // حدّث محلياً
        final idx = items.indexWhere((e) => e['id'] == id);
        if (idx != -1) {
          items[idx] = {...items[idx], 'read_at': DateTime.now().toIso8601String()};
          items.refresh();
        }
        unreadCount.value = int.tryParse('${r.body['meta']?['unread_count'] ?? unreadCount.value}') ?? unreadCount.value;
        return true;
      }
      return false;
    } catch (_) {
      return false;
    } finally {
      isSubmitting.value = false;
    }
  }

  Future<bool> markAllRead() async {
    try {
      isSubmitting.value = true;
      final r = await repo.markAllRead();
      if (_ok(r)) {
        for (var i = 0; i < items.length; i++) {
          if (items[i]['read_at'] == null) {
            items[i] = {...items[i], 'read_at': DateTime.now().toIso8601String()};
          }
        }
        items.refresh();
        unreadCount.value = 0;
        return true;
      }
      return false;
    } catch (_) {
      return false;
    } finally {
      isSubmitting.value = false;
    }
  }

  void toggleUnreadOnly() {
    unreadOnly.value = !unreadOnly.value;
    load();
  }

  bool _ok(Response r) =>
      (r.statusCode == 200 || r.statusCode == 201) &&
      r.body is Map &&
      r.body['success'] == true;
}
