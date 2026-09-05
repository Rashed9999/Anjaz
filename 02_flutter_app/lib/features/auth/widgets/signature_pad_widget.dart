import 'dart:convert';
import 'dart:ui' as ui;
import 'package:flutter/gestures.dart';
import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:amial_pay/theme/amial_colors.dart';

/// AMIAL-SIGNATURE-PAD-002 — **التوقيعُ لم يكن بطيئاً، كان مسروقاً.**
///
/// ══════════════════════════════════════════════════════════════════════
/// **ما قِيس، ولم يُفترَض.** المربّعاتُ الثلاثةُ تُعرَض داخل
/// `SingleChildScrollView` (‏`_stepSignature` في معالج التسجيل). و
/// `GestureDetector.onPanUpdate` يدخل **حلبةَ الإيماءات** مع
/// `VerticalDragGestureRecognizer` الخاصِّ بالمُمرِّر — **والمُمرِّرُ يفوز
/// بالسحب الرأسيّ**. فمن وقّع حركةً صاعدةً أو نازلةً: انقطع خطُّه وتمرّرت
/// الصفحةُ تحت إصبعه. وهذا هو «ليست سلسة» — لا بطءَ رسمٍ ولا ضعفَ جهاز.
///
/// وثلاثُ عللٍ أخرى كانت تُراكِم عليه:
///
/// | العلّة | الأثر |
/// |---|---|
/// | `drawLine` بين كلّ نقطتين | الخطُّ **مضلَّعٌ بزوايا** — الحركةُ السريعة تُعطي نقاطاً متباعدة |
/// | `setState` على كلّ حركةِ إصبع | يُعاد بناءُ الشجرة كلِّها ٦٠+ مرّةً في الثانية بدل إعادة **الرسم** وحدَه |
/// | لا قصَّ ولا `strokeJoin` | الخطُّ يخرج من المربّع، ومفاصلُه حادّة |
///
/// **والعلاجُ لكلٍّ في موضعه:** مُميِّزُ سحبٍ **يحسم الحلبةَ فوراً**
/// فلا يُنازعه المُمرِّر · ومنحنياتُ بيزييه عبر منتصفات النقاط ·
/// و`repaint` من `Listenable` فلا `setState` في أثناء الرسم · و`ClipRRect`
/// مع `StrokeJoin.round`.
///
/// **وثمنُ الحسم مقصود**: السحبُ فوق المربّع يوقّع ولا يمرّر — والتمريرُ
/// من خارجه. فمربّعُ توقيعٍ يتمرّر تحت الإصبع ليس مربّعَ توقيع.
///
/// تُصدَّر كـPNG (base64) عبر [SignaturePadState.exportBase64Png]، وتُبلَغ
/// حالتُها بـ`GlobalKey<SignaturePadState>`.
class SignaturePadWidget extends StatefulWidget {
  const SignaturePadWidget({super.key, this.height = 160});

  final double height;

  @override
  State<SignaturePadWidget> createState() => SignaturePadState();
}

class SignaturePadState extends State<SignaturePadWidget> {
  final GlobalKey _boundaryKey = GlobalKey();
  final _Ink _ink = _Ink();

  bool get isEmpty => _ink.isEmpty;

  void clear() {
    if (_ink.isEmpty) return;
    _ink.clear();
    // إعادةُ بناءٍ واحدةٌ لِما يعتمد على `isEmpty` — لا في أثناء الرسم.
    setState(() {});
  }

  @override
  void dispose() {
    _ink.dispose();
    super.dispose();
  }

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

  void _begin(Offset p) {
    final wasEmpty = _ink.isEmpty;
    _ink.begin(p);
    // أوّلُ خطٍّ فقط يستدعي إعادةَ بناء (ليُحدَّث ما يقرأ `isEmpty`).
    if (wasEmpty) setState(() {});
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
            // القصُّ داخل الإطار — فالحبرُ لا يخرج من المربّع.
            child: ClipRRect(
              borderRadius: BorderRadius.circular(9),
              child: RawGestureDetector(
                behavior: HitTestBehavior.opaque,
                gestures: <Type, GestureRecognizerFactory>{
                  _EagerPanRecognizer:
                      GestureRecognizerFactoryWithHandlers<_EagerPanRecognizer>(
                    () => _EagerPanRecognizer(),
                    (_EagerPanRecognizer instance) {
                      instance.onStart = (d) {
                        _begin(d.localPosition);
                      };
                      instance.onUpdate = (d) {
                        _ink.extend(d.localPosition);
                      };
                    },
                  ),
                },
                child: CustomPaint(
                  painter: _SignaturePainter(_ink),
                  size: Size.infinite,
                ),
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

/// **يحسم حلبةَ الإيماءات فور لمس الإصبع** — وإلّا نازعه المُمرِّرُ المحيط
/// على السحب الرأسيّ فخطف التوقيع. (وهو سببُ العطل الأصليّ.)
class _EagerPanRecognizer extends PanGestureRecognizer {
  @override
  void addAllowedPointer(PointerDownEvent event) {
    super.addAllowedPointer(event);
    resolve(GestureDisposition.accepted);
  }
}

/// حبرُ اللوحة — `Listenable` يقود إعادةَ **الرسم** وحدَها.
class _Ink extends ChangeNotifier {
  final List<List<Offset>> strokes = <List<Offset>>[];

  bool get isEmpty => strokes.isEmpty;

  void begin(Offset p) {
    strokes.add(<Offset>[p]);
    notifyListeners();
  }

  void extend(Offset p) {
    if (strokes.isEmpty) return;
    strokes.last.add(p);
    notifyListeners();
  }

  void clear() {
    strokes.clear();
    notifyListeners();
  }
}

class _SignaturePainter extends CustomPainter {
  _SignaturePainter(this.ink) : super(repaint: ink);

  final _Ink ink;

  @override
  void paint(Canvas canvas, Size size) {
    final paint = Paint()
      ..color = const Color(0xFF053391)
      ..strokeWidth = 2.5
      ..strokeCap = StrokeCap.round
      ..strokeJoin = StrokeJoin.round
      ..style = PaintingStyle.stroke
      ..isAntiAlias = true;

    for (final stroke in ink.strokes) {
      if (stroke.isEmpty) continue;
      if (stroke.length == 1) {
        // نقرةٌ واحدة = نقطة (وإلّا لم يظهر شيءٌ لمن وقّع بنقطة).
        canvas.drawPoints(ui.PointMode.points, <Offset>[stroke.first], paint);
        continue;
      }
      canvas.drawPath(_smoothPath(stroke), paint);
    }
  }

  /// منحنىً ناعمٌ يمرّ بمنتصفات النقاط — بدل خطوطٍ مستقيمةٍ تُظهر الزوايا
  /// كلّما تباعدت العيّناتُ في الحركة السريعة.
  static Path _smoothPath(List<Offset> pts) {
    final path = Path()..moveTo(pts.first.dx, pts.first.dy);
    for (int i = 1; i < pts.length - 1; i++) {
      final mid = Offset(
        (pts[i].dx + pts[i + 1].dx) / 2,
        (pts[i].dy + pts[i + 1].dy) / 2,
      );
      path.quadraticBezierTo(pts[i].dx, pts[i].dy, mid.dx, mid.dy);
    }
    path.lineTo(pts.last.dx, pts.last.dy);
    return path;
  }

  // إعادةُ الرسم يقودها `repaint: ink` — لا مقارنةَ هنا.
  @override
  bool shouldRepaint(_SignaturePainter oldDelegate) => false;
}
