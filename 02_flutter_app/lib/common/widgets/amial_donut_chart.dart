import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:amial_pay/theme/amial_colors.dart';

/// AMIAL-CHART-001 — مخطّط حلقي بقيمة في المركز.
///
/// يعرض توزيع مبالغ حسب النوع كقوس واحد مقسّم، والمجموع في وسط الحلقة.
/// مرسوم بالكود عبر `CustomPainter` — بلا مكتبة رسوم بيانية إضافية، فلا
/// وزن على الحزمة ولا تبعية جديدة تُصان.
///
/// الحلقة **ناقصة عمداً** (تبدأ من أعلى وتترك فجوة أسفل): شكل «العدّاد»
/// أوضح للقراءة من دائرة كاملة، ويترك مساحة للقيمة في المركز.
class AmialDonutChart extends StatelessWidget {
  /// الشرائح: التسمية ← القيمة. تُرسم بترتيب ورودها.
  final List<MapEntry<String, double>> slices;

  /// عنوان يظهر فوق القيمة في المركز.
  final String centerLabel;

  /// القيمة المعروضة في المركز (منسّقة مسبقاً).
  final String centerValue;

  final double size;

  const AmialDonutChart({
    super.key,
    required this.slices,
    required this.centerLabel,
    required this.centerValue,
    this.size = 190,
  });

  /// لوحة الشرائح — ألوان متمايزة بوضوح حتى لمن لديه عمى ألوان جزئي،
  /// وتبدأ بلون البراند.
  static const List<Color> palette = [
    AmialColors.primary,
    Color(0xFF16A34A),
    Color(0xFFE08A00),
    Color(0xFF7C3AED),
    Color(0xFF0EA5E9),
    Color(0xFFDB2777),
    Color(0xFF0E7C7B),
    Color(0xFFB45309),
  ];

  @override
  Widget build(BuildContext context) {
    final total = slices.fold<double>(0, (s, e) => s + e.value);

    return SizedBox(
      width: size,
      height: size * 0.78,
      child: CustomPaint(
        painter: _DonutPainter(slices: slices, total: total),
        child: Center(
          child: Padding(
            padding: EdgeInsets.only(top: size * 0.10),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                Text(centerLabel,
                    style: const TextStyle(
                        fontSize: 12, color: AmialColors.textSecondary)),
                const SizedBox(height: 3),
                Text(centerValue,
                    style: const TextStyle(
                        fontSize: 19,
                        fontWeight: FontWeight.bold,
                        color: Color(0xFF1A2433))),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class _DonutPainter extends CustomPainter {
  final List<MapEntry<String, double>> slices;
  final double total;

  const _DonutPainter({required this.slices, required this.total});

  // القوس يبدأ من أعلى اليسار ويمتدّ 260° — يترك فجوة سفلية.
  static const double _startAngle = math.pi * 0.87;
  static const double _sweepTotal = math.pi * 1.44;

  @override
  void paint(Canvas canvas, Size size) {
    final stroke = size.width * 0.13;
    final rect = Rect.fromLTWH(
      stroke / 2,
      stroke / 2,
      size.width - stroke,
      size.width - stroke,
    );

    // مسار الخلفية — يُظهر حجم الحلقة حتى حين لا توجد بيانات.
    final bg = Paint()
      ..style = PaintingStyle.stroke
      ..strokeWidth = stroke
      ..strokeCap = StrokeCap.round
      ..color = const Color(0xFFE6E9EF);
    canvas.drawArc(rect, _startAngle, _sweepTotal, false, bg);

    if (total <= 0 || slices.isEmpty) return;

    double angle = _startAngle;
    for (var i = 0; i < slices.length; i++) {
      final ratio = (slices[i].value / total).clamp(0.0, 1.0);
      final sweep = _sweepTotal * ratio;
      if (sweep <= 0) continue;

      final p = Paint()
        ..style = PaintingStyle.stroke
        ..strokeWidth = stroke
        ..strokeCap = StrokeCap.butt
        ..color = AmialDonutChart.palette[i % AmialDonutChart.palette.length];

      canvas.drawArc(rect, angle, sweep, false, p);
      angle += sweep;
    }
  }

  @override
  bool shouldRepaint(covariant _DonutPainter old) =>
      old.total != total || old.slices.length != slices.length;
}
