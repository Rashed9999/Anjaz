import 'package:flutter/material.dart';
import 'package:amial_pay/theme/amial_colors.dart';
import 'package:amial_pay/helper/amial_money.dart';

/// AMIAL-DS-001 — شريحة «مبلغ سريع» موحّدة (كما في المراجع الاحترافية).
///
/// تُستخدم أعلى/أسفل حقل المبلغ في كل الشاشات المالية ليملأ المستخدم
/// المبلغ بضغطة واحدة بدل الكتابة.
class AmialQuickAmounts extends StatelessWidget {
  final List<int> values;
  final void Function(int value) onPick;
  final Alignment alignment;

  const AmialQuickAmounts({
    super.key,
    required this.values,
    required this.onPick,
    this.alignment = Alignment.centerRight,
  });

  @override
  Widget build(BuildContext context) {
    return Align(
      alignment: alignment,
      child: Wrap(
        spacing: 8,
        runSpacing: 8,
        children: values.map((v) => _chip(v)).toList(),
      ),
    );
  }

  Widget _chip(int v) {
    return Builder(
      builder: (context) => InkWell(
        onTap: () => onPick(v),
        borderRadius: BorderRadius.circular(10),
        child: Container(
          padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
          decoration: BoxDecoration(
            color: AmialColors.primary.withValues(alpha: 0.06),
            borderRadius: BorderRadius.circular(10),
            border:
                Border.all(color: AmialColors.primary.withValues(alpha: 0.18)),
          ),
          child: Text(
            AmialMoney.fmt(v),
            style: const TextStyle(
              color: AmialColors.primary,
              fontWeight: FontWeight.w600,
              fontSize: 13,
            ),
          ),
        ),
      ),
    );
  }
}
