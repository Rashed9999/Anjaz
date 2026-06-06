import 'package:flutter/material.dart';

class CustomThemeColors extends ThemeExtension<CustomThemeColors> {
  final Color success;
  final Color accept;
  final Color addMoney;
  final Color cashOut;
  final Color requestMoney;
  final List<Color> qrLineGradientColors;
  final Color screenShortBackgroundColor;
  final Color screenShortTextColor;
  final List<Color> floatingGradientColor;
  final Color sliderColor;
  final Color info;


  const CustomThemeColors({
    required this.success,
    required this.accept,
    required this.addMoney,
    required this.cashOut,
    required this.requestMoney,
    required this.qrLineGradientColors,
    required this.screenShortBackgroundColor,
    required this.screenShortTextColor,
    required this.floatingGradientColor,
    required this.sliderColor,
    required this.info,
  });

  // Predefined themes for light and dark modes
  factory CustomThemeColors.light() => const CustomThemeColors(
    success: Color(0xFF9BE4CA),
    accept: Color(0xFF95CD41),
    addMoney: Color(0xFFACD9B3),
    cashOut: Color(0xFFFFCB66),
    requestMoney: Color(0xFFF6BDE9),
    qrLineGradientColors: [
      Color(0xFF008926),
      Color(0xFF5CAE7F),
      Color(0xFF008926),
      Color(0xFF008926),
      Color(0xFF5CAE7D),
      Color(0xFF008926),
    ],
    screenShortBackgroundColor: Color(0xFFE5E5E5),
    screenShortTextColor: Color(0xFF484848),
    floatingGradientColor: [
      Color(0xFF45A735),           // gradientColor - 100% opacity
      Color(0x8045A735),           // gradientColor - 50% opacity
      Color(0x4DE0EC53),           // secondaryColor - 30% opacity
      Color(0x0D45A735),           // gradientColor - 5% opacity
      Color(0x0045A735),           // gradientColor - 0% opacity (fully transparent)
    ],
    sliderColor: Color(0xFFF2F2F7),
    info: Color(0xFF3C76F1),

  );

  factory CustomThemeColors.dark() => const CustomThemeColors(
    success: Color(0xFF019463),
    accept: Color(0xFF065802),
    addMoney: Color(0xFF398343),
    cashOut: Color(0xFFf57a00),
    requestMoney: Color(0xFFa900a0),
    qrLineGradientColors: [
      Color(0xFF008926),
      Color(0xFF5CAE7F),
      Color(0xFF008926),
      Color(0xFF008926),
      Color(0xFF5CAE7D),
      Color(0xFF008926),
    ],
    screenShortBackgroundColor: Color(0xFFE5E5E5),
    screenShortTextColor: Color(0xFF484848),
    floatingGradientColor: [
      Color(0xFF45A735),           // gradientColor - 100% opacity
      Color(0x8045A735),           // gradientColor - 50% opacity
      Color(0x4DE0EC53),           // secondaryColor - 30% opacity
      Color(0x0D45A735),           // gradientColor - 5% opacity
      Color(0x0045A735),           // gradientColor - 0% opacity (fully transparent)
    ],
    sliderColor: Color(0xFF6a6e81),
    info: Color(0xFF9965f4),

  );

  @override
  CustomThemeColors copyWith({
    Color? success,
    Color? accept,
    Color? addMoney,
    Color? cashOut,
    Color? requestMoney,
  }) {
    return CustomThemeColors(
      success: success ?? this.success,
      accept: accept ?? this.accept,
      addMoney: addMoney ?? this.addMoney,
      cashOut: cashOut ?? this.cashOut,
      requestMoney: requestMoney ?? this.requestMoney,
      qrLineGradientColors: qrLineGradientColors,
      screenShortBackgroundColor: screenShortBackgroundColor,
      screenShortTextColor: screenShortTextColor,
      floatingGradientColor: floatingGradientColor,
      sliderColor: sliderColor,
      info: info,
    );
  }

  @override
  CustomThemeColors lerp(ThemeExtension<CustomThemeColors>? other, double t) {
    if (other is! CustomThemeColors) return this;

    return CustomThemeColors(
      success: Color.lerp(success, other.success, t)!,
      accept: Color.lerp(accept, other.accept, t)!,
      addMoney: Color.lerp(addMoney, other.addMoney, t)!,
      cashOut: Color.lerp(cashOut, other.cashOut, t)!,
      requestMoney: Color.lerp(requestMoney, other.requestMoney, t)!,
      qrLineGradientColors: List<Color>.generate(
        qrLineGradientColors.length,
        (index) => Color.lerp(qrLineGradientColors[index], other.qrLineGradientColors[index], t)!,
      ),
      screenShortBackgroundColor: Color.lerp(screenShortBackgroundColor, other.screenShortBackgroundColor, t)!,
      screenShortTextColor: Color.lerp(screenShortTextColor, other.screenShortTextColor, t)!,
      floatingGradientColor: List<Color>.generate(
        floatingGradientColor.length,
        (index) => Color.lerp(floatingGradientColor[index], other.floatingGradientColor[index], t)!,
      ),
      sliderColor: Color.lerp(sliderColor, other.sliderColor, t)!,
      info: Color.lerp(info, other.info, t)!,
    );
  }
}