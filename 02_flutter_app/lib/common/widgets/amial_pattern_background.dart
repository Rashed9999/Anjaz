import 'package:flutter/material.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';

/// AMIAL-PATTERN-001 — نقشة الهوية الخلفية.
///
/// المحافظ المهنية لا تترك الخلفية لوناً مسطّحاً: تضع زخرفة مائية خفيفة
/// مشتقّة من الشعار، فتُعطي الشاشة عمقاً وهويّة بلا أن تنافس المحتوى.
///
/// نرسمها بالكود لا بصورة: لا وزن إضافي في الحزمة، وتتكيّف مع أي مقاس شاشة،
/// ويمكن تغيير كثافتها بسطر واحد.
///
/// الشكل مشتقّ من انحناءة الشعار (الخطّ الأحمر تحت «أميال») — أقواس متكرّرة
/// بشفافية شديدة الانخفاض.
class AmialPatternBackground extends StatelessWidget {
  final Widget child;

  /// شدّة الظهور: 0 = مخفية، 1 = واضحة. الافتراضي خافت عمداً.
  final double intensity;

  const AmialPatternBackground({
    super.key,
    required this.child,
    this.intensity = 1.0,
  });

  @override
  Widget build(BuildContext context) {
    return Stack(
      children: [
        Positioned.fill(
          child: IgnorePointer(
            child: CustomPaint(
              painter: _ChevronPatternPainter(intensity: intensity),
            ),
          ),
        ),
        child,
      ],
    );
  }
}

class _ChevronPatternPainter extends CustomPainter {
  final double intensity;
  const _ChevronPatternPainter({required this.intensity});

  // تباعد الوحدة الزخرفية — كبير عمداً كي تبقى النقشة همساً لا ضجيجاً.
  static const double _tile = 58;

  @override
  void paint(Canvas canvas, Size size) {
    if (intensity <= 0) return;

    final paint = Paint()
      ..style = PaintingStyle.stroke
      ..strokeWidth = 1.6
      ..strokeCap = StrokeCap.round
      ..color = AmyalColors.primary.withValues(alpha: 0.035 * intensity);

    // النقشة تغطّي أعلى الشاشة فقط ثم تتلاشى — كما في المراجع، حيث تظهر
    // خلف الترويسة وبطاقة الرصيد ولا تمتدّ خلف القوائم.
    final maxY = size.height * 0.42;

    for (double y = -_tile; y < maxY; y += _tile) {
      // صفوف متبادلة الإزاحة لكسر الرتابة الشبكية
      final rowIndex = ((y + _tile) / _tile).round();
      final offsetX = rowIndex.isEven ? 0.0 : _tile / 2;

      for (double x = -_tile; x < size.width + _tile; x += _tile) {
        final path = Path()
          ..moveTo(x + offsetX, y + _tile * 0.62)
          ..lineTo(x + offsetX + _tile * 0.30, y + _tile * 0.30)
          ..lineTo(x + offsetX + _tile * 0.60, y + _tile * 0.62);
        canvas.drawPath(path, paint);
      }
    }
  }

  @override
  bool shouldRepaint(covariant _ChevronPatternPainter oldDelegate) =>
      oldDelegate.intensity != intensity;
}
