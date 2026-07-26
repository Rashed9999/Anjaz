import 'package:flutter/material.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';

/// AMIAL-PIN-UI-002 — نقاط الرمز السري.
///
/// أربع نقاط تمتلئ مع الإدخال بدل حقل نصّ. الفرق ليس زينةً:
///   - الحقل الفارغ بتباعد حروف 18 يظهر صندوقاً أبيض ضخماً بلا معنى قبل
///     الكتابة، ولا يقول للمستخدم كم رقماً يُنتظر منه.
///   - النقاط تقول العدد قبل أن يبدأ، وتُظهر تقدّمه، ولا تكشف رقماً.
///   - ولا لوحة مفاتيح نظام تظهر فوقها فتُخفي نصف الشاشة.
class AmialPinDots extends StatelessWidget {
  const AmialPinDots({
    super.key,
    required this.controller,
    this.length = 4,
    this.error = false,
  });

  final TextEditingController controller;
  final int length;

  /// يصبغ النقاط بالأحمر — يُستعمل بعد محاولة فاشلة.
  final bool error;

  @override
  Widget build(BuildContext context) {
    final color = error ? AmyalColors.red : AmyalColors.primary;

    return ValueListenableBuilder<TextEditingValue>(
      valueListenable: controller,
      builder: (context, value, _) {
        final n = value.text.length;

        return Row(
          mainAxisAlignment: MainAxisAlignment.center,
          children: List.generate(length, (i) {
            final filled = i < n;
            return AnimatedContainer(
              duration: const Duration(milliseconds: 160),
              width: filled ? 18 : 16,
              height: filled ? 18 : 16,
              margin: const EdgeInsets.symmetric(horizontal: 11),
              decoration: BoxDecoration(
                color: filled ? color : Colors.transparent,
                shape: BoxShape.circle,
                border: Border.all(
                  color: filled ? color : color.withValues(alpha: 0.30),
                  width: 1.6,
                ),
              ),
            );
          }),
        );
      },
    );
  }
}
