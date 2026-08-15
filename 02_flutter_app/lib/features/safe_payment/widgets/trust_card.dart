import 'package:flutter/material.dart';
import 'package:amial_pay/features/safe_payment/domain/models/safe_payment_models.dart';
import 'package:amial_pay/theme/amial_colors.dart';

/// AMIAL-SAFEPAY-TRUST-001 — سجلّ الطرف المقابل.
///
/// أرخص وسيلة لخفض النزاعات ليست حسمها بعد وقوعها بل منعها قبلها: من يرى
/// «أتمّ 14 صفقة، نزاع واحد، عضو منذ 8 أشهر» يقرّر على بيّنة، ومن يعرف أن
/// سجلّه معروض يحرص عليه.
///
/// أرقام مجرّدة بلا أسماء ولا مبالغ — تكفي للحكم ولا تكشف تعاملات أحد.
class TrustCard extends StatelessWidget {
  const TrustCard({super.key, required this.trust, required this.counterpartyName});

  final AmialTrustSummary trust;
  final String counterpartyName;

  @override
  Widget build(BuildContext context) {
    final (Color color, IconData icon) = switch (true) {
      _ when trust.isTrusted => (AmialColors.success, Icons.verified_rounded),
      _ when trust.isRisky => (AmialColors.red, Icons.report_gmailerrorred_rounded),
      _ when trust.isNew => (AmialColors.textMuted, Icons.person_outline_rounded),
      _ => (AmialColors.primary, Icons.history_rounded),
    };

    final roleLabel = trust.role == 'buyer' ? 'كمشترٍ' : 'كبائع';

    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: color.withValues(alpha: 0.35)),
      ),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(children: [
          Icon(icon, color: color, size: 20),
          const SizedBox(width: 8),
          Expanded(
            child: Text('سجلّ $counterpartyName $roleLabel',
                style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13)),
          ),
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
            decoration: BoxDecoration(
              color: color.withValues(alpha: 0.1),
              borderRadius: BorderRadius.circular(20),
            ),
            child: Text(trust.badge,
                style: TextStyle(fontSize: 11, fontWeight: FontWeight.bold, color: color)),
          ),
        ]),
        const SizedBox(height: 12),

        if (trust.isNew)
          const Text(
            'لا سجلّ سابق لهذا الطرف في الدفع الآمن. هذا لا يعني سوءاً — لكنه '
            'يعني أن الأدلّة ورمز التسليم هما حمايتك الوحيدة هنا.',
            style: TextStyle(fontSize: 11.5, height: 1.7, color: AmialColors.textSecondary),
          )
        else
          Row(children: [
            _stat('أتمّ', '${trust.completedDeals}', AmialColors.success),
            _divider(),
            _stat('نزاعات', '${trust.disputedDeals}',
                trust.disputedDeals > 0 ? AmialColors.red : AmialColors.textSecondary),
            _divider(),
            _stat('نسبة النزاع', '${trust.disputeRate}٪',
                trust.isRisky ? AmialColors.red : AmialColors.textSecondary),
          ]),

        if (trust.memberSince != null) ...[
          const SizedBox(height: 8),
          Text('عضو منذ ${trust.memberSince}',
              textDirection: TextDirection.ltr,
              style: const TextStyle(fontSize: 10.5, color: AmialColors.textMuted)),
        ],

        if (trust.isRisky) ...[
          const SizedBox(height: 10),
          Container(
            padding: const EdgeInsets.all(9),
            decoration: BoxDecoration(
              color: AmialColors.red.withValues(alpha: 0.06),
              borderRadius: BorderRadius.circular(8),
            ),
            child: const Text(
              'نسبة نزاعات مرتفعة. وثّق كل خطوة بالصور، ولا تُفرج عن المبلغ '
              'قبل التأكّد من السلعة.',
              style: TextStyle(fontSize: 11, height: 1.6, color: AmialColors.red),
            ),
          ),
        ],
      ]),
    );
  }

  Widget _stat(String label, String value, Color color) => Expanded(
        child: Column(children: [
          Text(value,
              textDirection: TextDirection.ltr,
              style: TextStyle(fontSize: 17, fontWeight: FontWeight.bold, color: color)),
          const SizedBox(height: 2),
          Text(label,
              style: const TextStyle(fontSize: 10.5, color: AmialColors.textMuted)),
        ]),
      );

  Widget _divider() =>
      Container(width: 1, height: 28, color: AmialColors.border);
}
