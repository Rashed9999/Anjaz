import 'package:flutter/material.dart';
import 'package:amial_pay/theme/amial_colors.dart';

/// AMIAL-OP-STATUS-001 — شارة حالة العملية الموحّدة عبر كل الشاشات.
///
/// توحّد عرض حالة أي عملية (تحويل/سحب/دفع/توثيق/طلب مال…) إلى أربع حالات:
///   completed     → «مكتملة»      (أخضر)
///   cancelled     → «ملغية»       (أحمر)
///   under_review  → «قيد المراجعة» (أزرق)
///   pending       → «قيد التحضير»  (ذهبي)
///
/// تقبل أيضاً مرادفات شائعة من الخادم (paid/success/rejected/processing…)
/// لتفادي ظهور «قيد التحضير» الخاطئ على عمليات مكتملة فعلاً.
class OperationStatus {
  final String label;
  final Color fg;
  final Color bg;
  const OperationStatus(this.label, this.fg, this.bg);

  static OperationStatus of(String? raw) {
    switch (_normalize(raw)) {
      case 'completed':
        return const OperationStatus('مكتملة', AmialColors.success, AmialColors.successSurface);
      case 'cancelled':
        return const OperationStatus('ملغية', AmialColors.red, AmialColors.dangerSurface);
      case 'under_review':
        return const OperationStatus('قيد المراجعة', Color(0xFF1D4FB8), Color(0xFFE4ECFB));
      case 'pending':
      default:
        return const OperationStatus('قيد التحضير', Color(0xFFB8860B), Color(0xFFFBF3D9));
    }
  }

  /// يوحّد مرادفات الخادم إلى إحدى الحالات الأربع.
  static String _normalize(String? raw) {
    final s = (raw ?? '').trim().toLowerCase();
    const completed = {
      'completed', 'complete', 'done', 'success', 'successful',
      'paid', 'released', 'settled', 'approved', 'verified', 'active',
    };
    const cancelled = {
      'cancelled', 'canceled', 'voided', 'void', 'rejected',
      'failed', 'declined', 'refunded', 'reversed', 'expired',
    };
    const underReview = {
      'under_review', 'pending_review', 'in_review', 'review',
      'submitted', 'awaiting_approval', 'pending_seller_acceptance',
      'resubmission_required',
    };
    const pending = {
      'pending', 'pending_pdf', 'processing', 'preparing',
      'initiated', 'created', 'queued', 'in_progress',
    };
    if (completed.contains(s)) return 'completed';
    if (cancelled.contains(s)) return 'cancelled';
    if (underReview.contains(s)) return 'under_review';
    if (pending.contains(s)) return 'pending';
    return 'pending';
  }

  /// شارة جاهزة للعرض.
  Widget chip({double fontSize = 10}) => Container(
        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
        decoration: BoxDecoration(color: bg, borderRadius: BorderRadius.circular(6)),
        child: Text(label,
            style: TextStyle(fontSize: fontSize, color: fg, fontWeight: FontWeight.bold)),
      );
}
