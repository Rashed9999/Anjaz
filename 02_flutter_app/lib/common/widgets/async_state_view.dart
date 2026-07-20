import 'package:flutter/material.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';

/// AMIAL-UX-STATES-001 — عرض موحّد لحالات الشاشة (المعيار المهني).
///
/// يغطّي معياراً واحداً لكل شاشة تجلب بيانات: التحميل / الخطأ + إعادة المحاولة /
/// الفارغ / التحديث بالسحب. («تعطيل الزر أثناء الطلب» يبقى في الشاشة نفسها عبر
/// علم busy). استخدمه بدل تكرار CircularProgressIndicator و«لا يوجد» يدوياً.
///
///   AsyncStateView(
///     loading: _loading, error: _error, isEmpty: _list.isEmpty,
///     onRetry: _load, emptyText: 'لا عناصر',
///     child: ListView(...),
///   )
class AsyncStateView extends StatelessWidget {
  const AsyncStateView({
    super.key,
    required this.loading,
    required this.child,
    this.error,
    this.isEmpty = false,
    this.emptyText = 'لا توجد بيانات',
    this.emptyIcon = Icons.inbox_outlined,
    this.errorIcon = Icons.wifi_off_rounded,
    this.onRetry,
    this.lockedError = false,
  });

  /// جارٍ التحميل — يعرض مؤشّراً.
  final bool loading;

  /// نصّ الخطأ (إن وُجد) — يعرض حالة خطأ مع «إعادة المحاولة».
  final String? error;

  /// لا بيانات — يعرض حالة فارغة أنيقة.
  final bool isEmpty;

  final String emptyText;
  final IconData emptyIcon;
  final IconData errorIcon;

  /// خطأ صلاحية/باقة (يستبدل أيقونة الشبكة بأيقونة قفل ويُخفي «إعادة المحاولة»).
  final bool lockedError;

  /// إعادة المحاولة + سحب-للتحديث. إن كان null فلا سحب/إعادة.
  final Future<void> Function()? onRetry;

  /// المحتوى عند النجاح.
  final Widget child;

  @override
  Widget build(BuildContext context) {
    if (loading) {
      return const Center(child: CircularProgressIndicator(color: AmyalColors.primary));
    }

    if (error != null && error!.isNotEmpty) {
      return _centered(
        icon: lockedError ? Icons.workspace_premium : errorIcon,
        iconColor: lockedError ? AmyalColors.yellowDark : AmyalColors.red,
        text: error!,
        showRetry: !lockedError && onRetry != null,
        onRetry: onRetry,
      );
    }

    if (isEmpty) {
      // قابل للسحب للتحديث حتى في الحالة الفارغة.
      final content = _centered(icon: emptyIcon, iconColor: AmyalColors.textSecondary, text: emptyText);
      return onRetry == null
          ? content
          : RefreshIndicator(
              onRefresh: onRetry!,
              child: ListView(children: [SizedBox(height: MediaQuery.of(context).size.height * 0.28), content]),
            );
    }

    return onRetry == null ? child : RefreshIndicator(onRefresh: onRetry!, child: child);
  }

  Widget _centered({
    required IconData icon,
    required Color iconColor,
    required String text,
    bool showRetry = false,
    Future<void> Function()? onRetry,
  }) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(28),
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          Icon(icon, size: 56, color: iconColor),
          const SizedBox(height: 14),
          Text(text, textAlign: TextAlign.center,
              style: const TextStyle(fontSize: 15, fontWeight: FontWeight.w600, color: AmyalColors.textSecondary)),
          if (showRetry) ...[
            const SizedBox(height: 18),
            FilledButton.icon(
              onPressed: onRetry,
              icon: const Icon(Icons.refresh),
              label: const Text('إعادة المحاولة'),
              style: FilledButton.styleFrom(backgroundColor: AmyalColors.primary, minimumSize: const Size(200, 46)),
            ),
          ],
        ]),
      ),
    );
  }
}
