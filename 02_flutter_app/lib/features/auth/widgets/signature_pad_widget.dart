import 'dart:convert';
import 'dart:ui' as ui;
import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:amial_pay/theme/amial_colors.dart';

/// AMIAL-SIGNATURE-PAD-001
///
/// لوحة توقيع تُرسَم بالإصبع (Canvas أصيل بلا حزمة خارجية). تُصدّر التوقيع
/// كـ PNG (base64) عبر [SignaturePadState.exportBase64Png] باستخدام
/// RepaintBoundary. استعمل GlobalKey<SignaturePadState> للوصول لحالتها.
class SignaturePadWidget extends StatefulWidget {
  const SignaturePadWidget({super.key, this.height = 160});

  final double height;

  @override
  State<SignaturePadWidget> createState() => SignaturePadState();
}

class SignaturePadState extends State<SignaturePadWidget> {
  final GlobalKey _boundaryKey = GlobalKey();
  final List<List<Offset>> _strokes = [];

  bool get isEmpty => _strokes.isEmpty;

  void clear() => setState(() => _strokes.clear());

  /// يُصدّر التوقيع كـ base64 لصورة PNG (أو null إن كان فارغاً/فشل).
  Future<String?> exportBase64Png() async {
    if (isEmpty) return null;
    try {
      final boundary = _boundaryKey.currentContext?.findRenderObject()
          as RenderRepaintBoundary?;
      if (boundary == null) return null;
      final image = await boundary.toImage(pixelRatio: 2.0);
      final byteData = await image.toByteData(format: ui.ImageByteFormat.png);
      if (byteData == null) return null;
      return base64Encode(byteData.buffer.asUint8List());
    } catch (_) {
      return null;
    }
  }

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        RepaintBoundary(
          key: _boundaryKey,
          child: Container(
            height: widget.height,
            decoration: BoxDecoration(
              color: Colors.white,
              border: Border.all(color: const Color(0xFFCBD5D1)),
              borderRadius: BorderRadius.circular(10),
            ),
            child: GestureDetector(
              onPanStart: (d) =>
                  setState(() => _strokes.add([d.localPosition])),
              onPanUpdate: (d) => setState(() {
                if (_strokes.isNotEmpty) _strokes.last.add(d.localPosition);
              }),
              child: CustomPaint(
                painter: _SignaturePainter(_strokes),
                size: Size.infinite,
              ),
            ),
          ),
        ),
        Align(
          alignment: Alignment.centerLeft,
          child: TextButton.icon(
            onPressed: clear,
            icon: const Icon(Icons.refresh, size: 18),
            label: const Text('مسح التوقيع'),
            style: TextButton.styleFrom(foregroundColor: AmialColors.primary),
          ),
        ),
      ],
    );
  }
}

class _SignaturePainter extends CustomPainter {
  _SignaturePainter(this.strokes);

  final List<List<Offset>> strokes;

  @override
  void paint(Canvas canvas, Size size) {
    final paint = Paint()
      ..color = const Color(0xFF053391)
      ..strokeWidth = 2.5
      ..strokeCap = StrokeCap.round
      ..style = PaintingStyle.stroke;
    for (final stroke in strokes) {
      for (int i = 0; i < stroke.length - 1; i++) {
        canvas.drawLine(stroke[i], stroke[i + 1], paint);
      }
    }
  }

  @override
  bool shouldRepaint(_SignaturePainter oldDelegate) => true;
}
