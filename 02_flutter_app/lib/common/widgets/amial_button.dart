import 'package:flutter/material.dart';
import 'package:amial_pay/theme/amial_colors.dart';
import 'package:amial_pay/theme/amial_spacing.dart';

/// AMIAL-DS-001 — الزرّ الموحّد لأميال.
///
/// نوع واحد لكل الأزرار عبر التطبيق ليتوحّد الارتفاع والزوايا والحالة
/// (تحميل/تعطيل). بدل تكرار `ElevatedButton.styleFrom(...)` في كل شاشة.
enum AmialButtonKind { primary, secondary, outline, dark }

class AmialButton extends StatelessWidget {
  final String label;
  final VoidCallback? onPressed;
  final AmialButtonKind kind;
  final IconData? icon;
  final bool loading;
  final bool expand;

  const AmialButton({
    super.key,
    required this.label,
    required this.onPressed,
    this.kind = AmialButtonKind.primary,
    this.icon,
    this.loading = false,
    this.expand = true,
  });

  @override
  Widget build(BuildContext context) {
    final bool disabled = onPressed == null || loading;

    late final Color bg;
    late final Color fg;
    late final BorderSide side;
    switch (kind) {
      case AmialButtonKind.primary:
        bg = AmialColors.primary;
        fg = Colors.white;
        side = BorderSide.none;
        break;
      case AmialButtonKind.secondary:
        bg = AmialColors.yellow;
        fg = AmialColors.primary;
        side = BorderSide.none;
        break;
      case AmialButtonKind.outline:
        bg = Colors.transparent;
        fg = AmialColors.primary;
        side = const BorderSide(color: AmialColors.primary, width: 1.4);
        break;
      case AmialButtonKind.dark:
        bg = const Color(0xFF14171A);
        fg = Colors.white;
        side = BorderSide.none;
        break;
    }

    final child = loading
        ? SizedBox(
            height: 20,
            width: 20,
            child: CircularProgressIndicator(strokeWidth: 2.4, color: fg),
          )
        : Row(
            mainAxisSize: MainAxisSize.min,
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              if (icon != null) ...[
                Icon(icon, size: 20, color: fg),
                const SizedBox(width: AmialSpacing.xs),
              ],
              Flexible(
                child: Text(
                  label,
                  overflow: TextOverflow.ellipsis,
                  style: TextStyle(
                      color: fg, fontSize: 15.5, fontWeight: FontWeight.w700),
                ),
              ),
            ],
          );

    final button = Material(
      color: disabled ? bg.withValues(alpha: 0.5) : bg,
      borderRadius: BorderRadius.circular(AmialSpacing.radiusMd),
      child: InkWell(
        onTap: disabled ? null : onPressed,
        borderRadius: BorderRadius.circular(AmialSpacing.radiusMd),
        child: Container(
          height: AmialSpacing.buttonHeight,
          alignment: Alignment.center,
          padding: const EdgeInsets.symmetric(horizontal: AmialSpacing.lg),
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(AmialSpacing.radiusMd),
            border: side == BorderSide.none
                ? null
                : Border.fromBorderSide(side),
          ),
          child: child,
        ),
      ),
    );

    return expand ? SizedBox(width: double.infinity, child: button) : button;
  }
}
