import 'package:flutter/material.dart';
import 'package:amial_pay/common/widgets/amial_brand_logo.dart';

/// واجهة التوافق القديمة صارت بوابةً للهوية الرسمية الجديدة بدلاً من
/// `assets/image/logo.png`. بذلك كل شاشة تستعمل CustomLogoWidget تحصل على
/// نفس الطبقات الرسمية الشفافة، ويمكن طلب الرمز وحده صراحةً عند الحاجة.
class CustomLogoWidget extends StatelessWidget {
  final double? height, width;
  final AmialBrandLogoVariant variant;

  const CustomLogoWidget({
    super.key,
    this.height,
    this.width,
    this.variant = AmialBrandLogoVariant.full,
  });

  @override
  Widget build(BuildContext context) {
    return AmialBrandLogo(
      height: height,
      width: width,
      variant: variant,
    );
  }
}
