import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';
import 'package:amyal_pay/features/notification/controllers/notifications_center_controller.dart';

/// AMIAL-NOTIFICATIONS-001 — شاشة مركز الإشعارات.
class NotificationsCenterScreen extends StatefulWidget {
  const NotificationsCenterScreen({super.key});

  @override
  State<NotificationsCenterScreen> createState() => _NotificationsCenterScreenState();
}

class _NotificationsCenterScreenState extends State<NotificationsCenterScreen> {
  late final NotificationsCenterController c;
  final ScrollController _scroll = ScrollController();

  @override
  void initState() {
    super.initState();
    c = Get.find<NotificationsCenterController>();
    WidgetsBinding.instance.addPostFrameCallback((_) => c.load());
    _scroll.addListener(() {
      if (_scroll.position.pixels > _scroll.position.maxScrollExtent - 200) {
        c.loadMore();
      }
    });
  }

  @override
  void dispose() {
    _scroll.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmyalColors.background,
      appBar: AppBar(
        title: const Text('الإشعارات'),
        actions: [
          Obx(() => c.unreadCount.value > 0
              ? TextButton.icon(
                  onPressed: c.isSubmitting.value ? null : c.markAllRead,
                  icon: const Icon(Icons.done_all, color: Colors.white, size: 18),
                  label: const Text('تعليم الكل', style: TextStyle(color: Colors.white)),
                )
              : const SizedBox.shrink()),
        ],
      ),
      body: Column(children: [
        // فلتر "غير المقروء فقط"
        Container(
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
          color: Colors.white,
          child: Obx(() => Row(children: [
            Switch(
              value: c.unreadOnly.value,
              onChanged: (_) => c.toggleUnreadOnly(),
              activeThumbColor: AmyalColors.primary,
            ),
            const SizedBox(width: 8),
            const Text('غير المقروءة فقط'),
            const Spacer(),
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
              decoration: BoxDecoration(
                color: c.unreadCount.value > 0 ? AmyalColors.red : Colors.grey.shade300,
                borderRadius: BorderRadius.circular(20),
              ),
              child: Text(
                '${c.unreadCount.value} غير مقروء',
                style: TextStyle(
                  color: c.unreadCount.value > 0 ? Colors.white : Colors.grey.shade700,
                  fontSize: 11, fontWeight: FontWeight.bold,
                ),
              ),
            ),
          ])),
        ),
        // القائمة
        Expanded(child: Obx(() {
          if (c.isLoading.value && c.items.isEmpty) {
            return const Center(child: CircularProgressIndicator());
          }
          if (c.items.isEmpty) {
            return RefreshIndicator(
              onRefresh: c.load,
              child: ListView(children: const [
                SizedBox(height: 100),
                Icon(Icons.notifications_off, size: 64, color: Colors.grey),
                SizedBox(height: 12),
                Center(child: Text('لا توجد إشعارات', style: TextStyle(fontSize: 16))),
              ]),
            );
          }
          return RefreshIndicator(
            onRefresh: c.load,
            child: ListView.separated(
              controller: _scroll,
              padding: const EdgeInsets.symmetric(vertical: 8),
              itemCount: c.items.length + (c.currentPage.value < c.lastPage.value ? 1 : 0),
              separatorBuilder: (_, _) => const SizedBox(height: 4),
              itemBuilder: (_, i) {
                if (i >= c.items.length) {
                  return const Padding(
                    padding: EdgeInsets.all(16),
                    child: Center(child: CircularProgressIndicator()),
                  );
                }
                return _tile(c.items[i]);
              },
            ),
          );
        })),
      ]),
    );
  }

  Widget _tile(Map<String, dynamic> n) {
    final unread = n['read_at'] == null;
    final iconKey = (n['icon'] ?? 'notifications') as String;
    final color = _typeColor(n['type'] as String? ?? 'system');
    final ts = _formatTime(n['created_at']);

    return InkWell(
      onTap: () async {
        if (unread) await c.markRead(n['id'] as int);
      },
      child: Container(
        margin: const EdgeInsets.symmetric(horizontal: 12, vertical: 2),
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(
          color: unread ? Colors.white : Colors.white.withValues(alpha: 0.6),
          borderRadius: BorderRadius.circular(12),
          border: unread ? Border.all(color: color.withValues(alpha: 0.4), width: 1) : null,
        ),
        child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
          // أيقونة دائرية
          Container(
            width: 44, height: 44,
            decoration: BoxDecoration(color: color.withValues(alpha: 0.15), shape: BoxShape.circle),
            child: Icon(_resolveIcon(iconKey), color: color, size: 22),
          ),
          const SizedBox(width: 12),
          // المحتوى
          Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
              Row(children: [
                if (unread)
                  Container(width: 8, height: 8, margin: const EdgeInsets.only(left: 6),
                      decoration: BoxDecoration(color: AmyalColors.red, shape: BoxShape.circle)),
                const Spacer(),
                Text(n['title'] ?? '',
                    style: TextStyle(fontSize: 15, fontWeight: unread ? FontWeight.bold : FontWeight.w500)),
              ]),
              const SizedBox(height: 4),
              Text(n['body'] ?? '',
                  textAlign: TextAlign.right,
                  style: TextStyle(fontSize: 13, color: Colors.grey.shade700)),
              const SizedBox(height: 6),
              Text(ts, style: TextStyle(fontSize: 11, color: Colors.grey.shade500)),
            ]),
          ),
        ]),
      ),
    );
  }

  Color _typeColor(String type) {
    if (type.contains('transfer')) return Colors.blue;
    if (type.contains('withdrawal')) return Colors.green;
    if (type == 'credit_over_limit') return AmyalColors.red;
    if (type.contains('credit')) return AmyalColors.yellowDark;
    if (type == 'promo') return Colors.purple;
    return AmyalColors.primary;
  }

  IconData _resolveIcon(String key) {
    switch (key) {
      case 'swap_horiz': return Icons.swap_horiz;
      case 'arrow_downward': return Icons.arrow_downward;
      case 'receipt_long': return Icons.receipt_long;
      case 'store': return Icons.store;
      case 'local_offer': return Icons.local_offer;
      case 'description': return Icons.description;
      default: return Icons.notifications;
    }
  }

  String _formatTime(dynamic raw) {
    if (raw == null) return '';
    try {
      final t = DateTime.parse(raw.toString()).toLocal();
      final now = DateTime.now();
      final diff = now.difference(t);
      if (diff.inMinutes < 1) return 'الآن';
      if (diff.inMinutes < 60) return 'منذ ${diff.inMinutes} دقيقة';
      if (diff.inHours < 24) return 'منذ ${diff.inHours} ساعة';
      if (diff.inDays < 7) return 'منذ ${diff.inDays} أيام';
      return '${t.year}-${t.month.toString().padLeft(2, '0')}-${t.day.toString().padLeft(2, '0')}';
    } catch (_) {
      return '';
    }
  }
}
