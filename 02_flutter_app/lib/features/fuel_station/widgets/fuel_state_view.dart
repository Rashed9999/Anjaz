import 'package:flutter/material.dart';
import 'package:amial_pay/common/widgets/vertical_state_view.dart';
import 'package:amial_pay/features/fuel_station/controllers/fuel_vertical_controller.dart';

/// AMIAL-FUEL-VERTICAL-001 · المرحلة ٨ — الحالاتُ الستّ لشاشات المحطّة.
///
/// ══════════════════════════════════════════════════════════════════════
/// **والمحرّكُ لم يعد هنا** — AMIAL-RETAIL-VERTICAL-001 · المرحلة ١٠.
///
/// فليس في هذه الحالات سطرٌ خاصٌّ بالوقود، والتجزئةُ تحتاجها حرفاً بحرف.
/// فانتقلت إلى `VerticalStateView`، وبقي هذا اسماً يعرفه شاشاتُ المحطّة
/// ويُمرّر «مالك المحطة» في رسالة رفض الصلاحيّة.
class FuelStateView extends StatelessWidget {
  final FuelVerticalController c;
  final bool isEmpty;
  final String emptyTitle;
  final String? emptyHint;
  final IconData emptyIcon;
  final Future<void> Function() onRetry;
  final Widget child;

  const FuelStateView({
    super.key,
    required this.c,
    required this.isEmpty,
    required this.emptyTitle,
    required this.onRetry,
    required this.child,
    this.emptyHint,
    this.emptyIcon = Icons.inbox_outlined,
  });

  @override
  Widget build(BuildContext context) {
    return VerticalStateView(
      c: c,
      isEmpty: isEmpty,
      emptyTitle: emptyTitle,
      emptyHint: emptyHint,
      emptyIcon: emptyIcon,
      onRetry: onRetry,
      grantedBy: 'مالك المحطة أو المدير',
      child: child,
    );
  }
}

/// زرُّ فعلٍ يعرف صلاحيّته — **ولا يُرسم لمن لا يملكه**.
class FuelActionButton extends StatelessWidget {
  final FuelVerticalController c;
  final String permission;
  final String label;
  final IconData icon;
  final Future<void> Function() onPressed;
  final Color? color;

  const FuelActionButton({
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
    return VerticalActionButton(
      c: c,
      permission: permission,
      label: label,
      icon: icon,
      onPressed: onPressed,
      color: color,
    );
  }
}
