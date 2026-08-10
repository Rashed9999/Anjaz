import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amial_pay/common/controllers/vertical_state_mixin.dart';
import 'package:amial_pay/theme/amial_colors.dart';

/// **الحالاتُ الستّ في مكانٍ واحد** — لكلّ القطاعات.
///
/// ══════════════════════════════════════════════════════════════════════
/// تفرض `amial-flutter` ستّ حالاتٍ لكلّ شاشة: تحميل · فارغ · خطأ · رفضُ
/// صلاحيّة · بلا اتّصال · صيانة.
///
/// **وتكرارُها في كلّ شاشةٍ يعني نسيانَ واحدةٍ في شاشة.** وأكثرُ ما يُنسى
/// «رفضُ الصلاحيّة»، فيظهر للمستعمل «لا بيانات» حيث الصواب «لا تملك
/// صلاحية» — **فيبحث عن عطلٍ لا وجود له**.
///
/// وكذلك «بلا اتّصال» تُخلط بـ«خطأ»: فيُرسَل من انقطعت شبكتُه إلى الدعم.
///
/// **وعُمّمت في AMIAL-RETAIL-VERTICAL-001 · المرحلة ١٠**: كانت خاصّةً
/// بمتحكّم الوقود بحكم نوعها، وليس فيها شيءٌ من الوقود.
class VerticalStateView extends StatelessWidget {
  final VerticalStateMixin c;
  final bool isEmpty;
  final String emptyTitle;
  final String? emptyHint;
  final IconData emptyIcon;
  final Future<void> Function() onRetry;
  final Widget child;

  /// من يمنح الصلاحيّة — يختلف بالقطاع، والرسالةُ بلا اسمِه بلا فائدة.
  final String grantedBy;

  const VerticalStateView({
    super.key,
    required this.c,
    required this.isEmpty,
    required this.emptyTitle,
    required this.onRetry,
    required this.child,
    this.emptyHint,
    this.emptyIcon = Icons.inbox_outlined,
    this.grantedBy = 'مالك المنشأة أو المدير',
  });

  @override
  Widget build(BuildContext context) {
    return Obx(() {
      // ① بلا اتّصال — **قبل الخطأ**، فهو تشخيصٌ أدقّ.
      if (c.isOffline.value) {
        return _message(
          icon: Icons.wifi_off_rounded,
          color: Colors.orange,
          title: 'لا اتصال بالخادم',
          body: 'تحقّق من الشبكة ثم أعد المحاولة. البيانات المعروضة قد تكون قديمة.',
          showRetry: true,
        );
      }

      // ② رفضُ الصلاحيّة — **ليس فراغاً ولا عطلاً**.
      if (c.permissionDenied.value) {
        return _message(
          icon: Icons.lock_outline_rounded,
          color: AmialColors.textMuted,
          title: 'لا تملك صلاحية هذه الشاشة',
          body: 'اطلب من $grantedBy منحك الصلاحية المناسبة.',
          showRetry: false,
        );
      }

      // ③ خطأ — برسالته.
      if (c.lastError.isNotEmpty) {
        return _message(
          icon: Icons.error_outline_rounded,
          color: Colors.red,
          title: 'تعذّر إتمام العملية',
          body: c.lastError.value,
          showRetry: true,
        );
      }

      // ④ تحميل — ولا يُعرض فوق بياناتٍ قائمة.
      if (c.isLoading.value && isEmpty) {
        return const Center(child: Padding(
          padding: EdgeInsets.all(48), child: CircularProgressIndicator()));
      }

      // ⑤ فارغ — **بسببه وبطريقِ الخروج منه**.
      if (isEmpty) {
        return _message(
          icon: emptyIcon,
          color: AmialColors.textMuted,
          title: emptyTitle,
          body: emptyHint ?? '',
          showRetry: true,
        );
      }

      // ⑥ المحتوى.
      return child;
    });
  }

  Widget _message({
    required IconData icon,
    required Color color,
    required String title,
    required String body,
    required bool showRetry,
  }) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(32),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(icon, size: 56, color: color),
            const SizedBox(height: 16),
            Text(title,
                textAlign: TextAlign.center,
                style: const TextStyle(fontSize: 17, fontWeight: FontWeight.bold)),
            if (body.isNotEmpty) ...[
              const SizedBox(height: 8),
              Text(body,
                  textAlign: TextAlign.center,
                  style: const TextStyle(fontSize: 14, color: AmialColors.textMuted)),
            ],
            if (showRetry) ...[
              const SizedBox(height: 20),
              OutlinedButton.icon(
                onPressed: () async {
                  c.clearState();
                  await onRetry();
                },
                icon: const Icon(Icons.refresh_rounded),
                label: const Text('إعادة المحاولة'),
              ),
            ],
          ],
        ),
      ),
    );
  }
}

/// زرُّ فعلٍ **يعرف صلاحيّته وحالةَ تحميله**.
///
/// **ولا يُرسم أصلاً لمن لا يملكه.** وزرٌّ يُعرض ثمّ يُرفض عند الضغط أسوأ
/// من غيابه: يَعِد ثمّ يُخلف.
class VerticalActionButton extends StatelessWidget {
  final VerticalStateMixin c;
  final String permission;
  final String label;
  final IconData icon;
  final Future<void> Function() onPressed;
  final Color? color;

  const VerticalActionButton({
    super.key,
    required this.c,
    required this.permission,
    required this.label,
    required this.icon,
    required this.onPressed,
    this.color,
  });

  @override
  Widget build(BuildContext context) {
    return Obx(() {
      if (!c.can(permission)) return const SizedBox.shrink();

      final busy = c.isSubmitting.value;

      return ElevatedButton.icon(
        onPressed: busy ? null : () async => onPressed(),
        style: ElevatedButton.styleFrom(
          backgroundColor: color ?? AmialColors.primary,
          foregroundColor: Colors.white,
        ),
        icon: busy
            ? const SizedBox(
                width: 16, height: 16,
                child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
            : Icon(icon, size: 18),
        label: Text(busy ? 'جارٍ التنفيذ…' : label),
      );
    });
  }
}
