import 'package:flutter/material.dart';

/// AMIAL-BRAND-UI-001 — مصدر عرض الهوية داخل Flutter.
///
/// لا يعيد رسم الشعار ولا يستخدم نسخة legacy المسطّحة. النسخة الكاملة
/// تُركّب من الطبقات الرسمية المفصولة من الأصل نفسه، والنسخة الرمزية
/// تستخدم الـ foreground الشفاف بلا خلفية صفراء أو مستطيل أبيض.
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
  static const String arabicWordmarkAsset =
      'assets/brand/logo_wordmark.png';
  static const String swooshAsset = 'assets/brand/logo_swoosh.png';
  static const String latinAsset = 'assets/brand/logo_latin.png';
  static const String taglineAsset = 'assets/brand/logo_tagline.png';

  @override
  Widget build(BuildContext context) {
    if (variant == AmialBrandLogoVariant.symbol) {
      return SizedBox(
        width: width,
        height: height,
        child: Image.asset(symbolAsset, fit: fit),
      );
    }

    // نفس نسب وترتيب الطبقات المستعملة في BrandSplashAnimation؛ بذلك
    // تبقى الهوية الساكنة والحركة من المصدر الرسمي نفسه.
    final mark = SizedBox(
      width: 562,
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Image.asset(arabicWordmarkAsset, width: 562, fit: BoxFit.contain),
          const SizedBox(height: 11),
          Image.asset(swooshAsset, width: 517, fit: BoxFit.contain),
          const SizedBox(height: 28),
          Image.asset(latinAsset, width: 495, fit: BoxFit.contain),
          const SizedBox(height: 22),
          Image.asset(taglineAsset, width: 337, fit: BoxFit.contain),
        ],
      ),
    );

    return SizedBox(
      width: width,
      height: height,
      child: FittedBox(fit: fit, child: mark),
    );
  }
}
