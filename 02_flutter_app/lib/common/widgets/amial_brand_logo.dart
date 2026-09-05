import 'package:flutter/material.dart';

/// AMIAL-BRAND-UI-001 — مصدر عرض الهوية داخل Flutter.
///
/// لا يعيد رسم الشعار ولا يستخدم نسخة legacy المسطّحة. النسخة الرمزية
/// تستخدم الـ foreground الشفاف بلا خلفية صفراء أو مستطيل أبيض.
///
/// ══════════════════════════════════════════════════════════════════════
/// **AMIAL-BRAND-LOCKUP-001 — شعارٌ واحدٌ يُسلَّم، لا أربعُ طبقاتٍ تُركَّب.**
///
/// أرسل صاحبُ المشروع الشعارَ النهائيَّ وقال: «غيّره إلى هذا في أغلب أماكن
/// التطبيق، **ما عدا شعارات فتح بداية التطبيق**».
///
/// وكانت النسخةُ الكاملةُ هنا تُركَّب من أربعة ملفّاتٍ بمقاساتٍ وفواصلَ
/// مكتوبةٍ يدويّاً (562 · 517 · 495 · 337). **وهذا ينتج شعاراً قريباً من
/// الأصل لا الأصلَ نفسَه**: أيُّ فرقٍ في تباعدٍ أو مقاسٍ يتراكم عبر أربع
/// طبقات، ولا شيءَ يكشفه لأنّ النتيجةَ «تبدو صحيحة».
///
/// **فصار المصدرُ ملفّاً واحداً** — هو ما أقرّه صاحبُ المشروع بعينه.
///
/// **وقُصّ هامشُه قبل التركيب، وهذا مقيسٌ لا تجميليّ:** الأصلُ ١٤٤٨×١٠٨٦
/// ومحتواه ١١٤٧×٦٩٤ — أي **هامشٌ شفّافٌ يبتلع ٣٦٪ من كلّ صندوقٍ يوضع
/// فيه**. فشعارٌ في مربّعٍ ٧٠×٧٠ يُرسَم بـ٤٥ بكسلاً فعليّاً ويبدو صغيراً
/// بلا سبب، ولا خطأَ في أيّ سجلّ. والمقصوصُ ١٢١٥×٧٦٢ (نسبة ١٫٥٩).
///
/// **وشاشةُ الافتتاح لا تُمَسّ** — `BrandSplashAnimation` تحرّك الطبقاتِ
/// الأربعَ كلاًّ على حدة (انزلاقُ الخطّ الأحمر ثمّ ظهورُ اللاتينيّ)، فطبقةٌ
/// واحدةٌ مسطَّحةٌ تُلغي الحركةَ نفسَها. ولذلك بقيت ملفّاتُ الطبقات.
enum AmialBrandLogoVariant { full, symbol }

class AmialBrandLogo extends StatelessWidget {
  const AmialBrandLogo({
    super.key,
    this.variant = AmialBrandLogoVariant.full,
    this.width,
    this.height,
    this.fit = BoxFit.contain,
  });

  final AmialBrandLogoVariant variant;
  final double? width;
  final double? height;
  final BoxFit fit;

  static const String symbolAsset =
      'assets/branding/icon_foreground.png';

  /// **الشعارُ النهائيُّ مقصوصَ الهامش** — مصدرٌ واحدٌ لكلّ الشاشات.
  static const String lockupAsset = 'assets/brand/logo_lockup.png';

  /// نسبةُ العرض إلى الارتفاع للملفّ المقصوص (1215 ÷ 762).
  ///
  /// **تُقرأ ولا تُخمَّن**: من مرّر ارتفاعاً وحدَه يحصل على العرض الصحيح،
  /// فلا يُحشَر شعارٌ عريضٌ في مربّعٍ ويُرسَم نصفَه.
  static const double lockupAspect = 1215 / 762;

  @override
  Widget build(BuildContext context) {
    if (variant == AmialBrandLogoVariant.symbol) {
      return SizedBox(
        width: width,
        height: height,
        child: Image.asset(symbolAsset, fit: fit),
      );
    }

    // **والمقاسُ يُكمَّل من النسبة حين يُمرَّر ضلعٌ واحد.** كثيرٌ من
    // النداءات القديمة تمرّر مربّعاً (70×70) لأنّ الشعارَ القديم كان
    // طوليّاً — ومربّعٌ على شعارٍ عرضُه ضعفُ ارتفاعِه يترك ثلثَيه فراغاً.
    final w = width ?? (height == null ? null : height! * lockupAspect);
    final h = height ?? (width == null ? null : width! / lockupAspect);

    return SizedBox(
      width: w,
      height: h,
      child: Image.asset(lockupAsset, fit: fit),
    );
  }
}
