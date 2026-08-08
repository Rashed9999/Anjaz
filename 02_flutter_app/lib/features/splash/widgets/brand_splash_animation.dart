import 'dart:math' as math;

import 'package:flutter/material.dart';

import 'package:amial_pay/theme/amial_colors.dart';

/// AMIAL-SPLASH-C-001 — حركة الشعار عند الإقلاع (النمط C).
///
/// ══════════════════════════════════════════════════════════════════════
/// **المراحل الستّ كما صمّمها صاحب المشروع:**
///
///   0.0s  نقطةُ ضوءٍ في منتصف الشاشة
///   0.4s  تتحرّك أجزاءُ الشعار من أطراف الشاشة نحو المركز
///   0.9s  يتجمّع الشعارُ كاملاً في المنتصف
///   1.2s  يظهر الخطُّ الأحمر أسفله بحركةٍ سلسة
///   1.5s  يظهر AMIAL PAY أسفل الشعار
///   1.8s  يظهر الشعارُ الفرعيّ «دفع سريع وآمن» ثمّ الانتقال
///
/// ══════════════════════════════════════════════════════════════════════
/// **ولمَ أربعُ صورٍ لا صورةٌ واحدة:**
///
/// الشعارُ المُسلَّم صورةٌ مسطّحةٌ واحدة، والمواصفةُ تُحرّك أجزاءه
/// **منفصلة**. فلا سبيل إلى «يظهر الخطُّ الأحمر أسفله» وهو مطبوعٌ في
/// الصورة نفسِها منذ اللحظة الأولى.
///
/// فقُيست بنيةُ الشعار بالبكسل وفُصلت طبقاته الأربع من الأصل نفسِه —
/// لا رُسمت تقليداً. والحروفُ هي حروفُ الشعار بعينها، لا خطٌّ يشبهها:
///
///   `logo_wordmark` — أميال والهمزة    (٨٠٪ أزرق · ٣٪ أحمر)
///   `logo_swoosh`   — الخطّ الأحمر      (٩٩٪ أحمر — خالصٌ بلا ذيول حروف)
///   `logo_latin`    — AMIAL PAY
///   `logo_tagline`  — دفع سريع وآمن
///
/// **والشفافيّة من بُعد اللون عن الخلفية لا من قناعٍ ثنائيّ** — فالقناعُ
/// الثنائيّ يُنتج حوافَّ مسنّنة تظهر بشاعتُها عند التكبير، والشعارُ يكبر
/// في المرحلة الثانية.
class BrandSplashAnimation extends StatefulWidget {
  const BrandSplashAnimation({super.key, this.onCompleted});

  /// يُنادى مرّةً واحدةً حين تنتهي المراحلُ الستّ.
  final VoidCallback? onCompleted;

  /// المدّةُ الكلّيّة كما في المواصفة.
  static const Duration total = Duration(milliseconds: 1800);

  @override
  State<BrandSplashAnimation> createState() => _BrandSplashAnimationState();
}

class _BrandSplashAnimationState extends State<BrandSplashAnimation>
    with SingleTickerProviderStateMixin {
  late final AnimationController _c;

  bool _announced = false;

  // ═══ المراحل بالنسبة إلى المدّة الكلّيّة (1.8 ثانية) ═══
  late final Animation<double> _spark;      // 0.00 → 0.25   نقطة الضوء
  late final Animation<double> _converge;   // 0.20 → 0.50   الأجزاء تتجمّع
  late final Animation<double> _settle;     // 0.45 → 0.62   الاستقرار
  late final Animation<double> _swoosh;     // 0.62 → 0.80   الخطّ الأحمر
  late final Animation<double> _latin;      // 0.78 → 0.92   AMIAL PAY
  late final Animation<double> _tagline;    // 0.88 → 1.00   الشعار الفرعيّ

  Animation<double> _stage(double a, double b, [Curve curve = Curves.easeOut]) {
    return CurvedAnimation(parent: _c, curve: Interval(a, b, curve: curve));
  }

  @override
  void initState() {
    super.initState();

    _c = AnimationController(vsync: this, duration: BrandSplashAnimation.total);

    _spark = _stage(0.00, 0.25);
    _converge = _stage(0.20, 0.50, Curves.easeOutCubic);
    _settle = _stage(0.45, 0.62, Curves.easeOutBack);
    _swoosh = _stage(0.62, 0.80, Curves.easeOutCubic);
    _latin = _stage(0.78, 0.92);
    _tagline = _stage(0.88, 1.00);

    // **ويُنادى الانتهاءُ مرّةً واحدة.**
    //
    // `addStatusListener` قد يُنادى مرّتين إن أُعيد تشغيل المتحكّم أو
    // أُعيد بناءُ الشجرة — ونداءٌ ثانٍ يعني `Get.offNamed` مرّتين، فتُفتح
    // شاشةُ الدخول فوق نفسها. (زرٌّ يعمل ويفعل الشيء مرّتين.)
    _c.addStatusListener((s) {
      if (s == AnimationStatus.completed && !_announced) {
        _announced = true;
        widget.onCompleted?.call();
      }
    });

    _c.forward();
  }

  @override
  void dispose() {
    _c.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final w = MediaQuery.sizeOf(context).width;

    // الشعارُ محكومٌ بعرض الشاشة لا بارتفاعٍ ثابت — وهو الدرسُ المكتوب في
    // AMIAL-SPLASH-003: ارتفاعٌ ثابتٌ يُقصّ الشعارَ على الشاشات الضيّقة.
    final logoW = math.min(w * 0.66, 320.0);

    return AnimatedBuilder(
      animation: _c,
      builder: (context, _) {
        return Stack(
          alignment: Alignment.center,
          children: [
            // ① نقطةُ الضوء — تتوهّج ثمّ تخفت وقد صار الشعارُ مكانَها.
            Opacity(
              opacity: (1.0 - _converge.value).clamp(0.0, 1.0) * _spark.value,
              child: Container(
                width: 10 + 90 * _spark.value,
                height: 10 + 90 * _spark.value,
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  gradient: RadialGradient(
                    colors: [
                      Colors.white.withValues(alpha: 0.95),
                      Colors.white.withValues(alpha: 0.0),
                    ],
                  ),
                ),
              ),
            ),

            Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                // ②③ الاسمُ يتجمّع من الأطراف ثمّ يستقرّ.
                _Converging(
                  progress: _converge.value,
                  settle: _settle.value,
                  width: logoW,
                ),

                SizedBox(height: logoW * 0.02),

                // ④ الخطُّ الأحمر يُرسم من اليمين إلى اليسار.
                //
                // **ورسمُه كشفٌ لا ظهور**: `ClipRect` بمحاذاةٍ يمينيّة يجعل
                // الخطَّ يُستَّل كما يُخطّ بالقلم، وهو ما تعنيه «حركةٌ
                // سلسة» في المواصفة. والتلاشي وحده يجعله يظهر دفعةً.
                ClipRect(
                  child: Align(
                    alignment: Alignment.centerRight,
                    widthFactor: _swoosh.value.clamp(0.001, 1.0),
                    child: Opacity(
                      opacity: _swoosh.value.clamp(0.0, 1.0),
                      child: Image.asset(
                        'assets/brand/logo_swoosh.png',
                        width: logoW * 0.92,
                        fit: BoxFit.contain,
                      ),
                    ),
                  ),
                ),

                SizedBox(height: logoW * 0.05),

                // ⑤ AMIAL PAY
                _RiseIn(
                  t: _latin.value,
                  child: Image.asset(
                    'assets/brand/logo_latin.png',
                    width: logoW * 0.88,
                    fit: BoxFit.contain,
                  ),
                ),

                SizedBox(height: logoW * 0.04),

                // ⑥ دفع سريع وآمن
                _RiseIn(
                  t: _tagline.value,
                  child: Image.asset(
                    'assets/brand/logo_tagline.png',
                    width: logoW * 0.60,
                    fit: BoxFit.contain,
                  ),
                ),
              ],
            ),
          ],
        );
      },
    );
  }
}

/// الاسمُ يتجمّع: يأتي من أعلى ومن أسفل معاً ثمّ يستقرّ بنبضةٍ خفيفة.
class _Converging extends StatelessWidget {
  const _Converging({
    required this.progress,
    required this.settle,
    required this.width,
  });

  final double progress;
  final double settle;
  final double width;

  @override
  Widget build(BuildContext context) {
    if (progress <= 0.0) {
      return SizedBox(width: width, height: width * 0.38);
    }

    // يبدأ متباعداً ومتلاشياً، ثمّ ينطبق على مركزه.
    final spread = (1.0 - progress) * width * 0.55;
    final scale = 0.72 + 0.28 * progress + 0.05 * settle * (1 - settle) * 4;

    return Opacity(
      opacity: progress.clamp(0.0, 1.0),
      child: Transform.scale(
        scale: scale,
        child: Transform.translate(
          offset: Offset(0, spread * 0.15),
          child: Image.asset(
            'assets/brand/logo_wordmark.png',
            width: width,
            fit: BoxFit.contain,
          ),
        ),
      ),
    );
  }
}

/// ظهورٌ صاعدٌ خفيف — تلاشٍ مع إزاحةٍ لأعلى.
class _RiseIn extends StatelessWidget {
  const _RiseIn({required this.t, required this.child});

  final double t;
  final Widget child;

  @override
  Widget build(BuildContext context) {
    return Opacity(
      opacity: t.clamp(0.0, 1.0),
      child: Transform.translate(
        offset: Offset(0, (1.0 - t) * 14),
        child: child,
      ),
    );
  }
}

/// لونُ خلفيّة السبلاش — من توكِنز العلامة لا رقماً مكتوباً.
const Color kSplashBackground = AmialColors.yellow;
