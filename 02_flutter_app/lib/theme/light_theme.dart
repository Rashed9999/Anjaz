import 'package:flutter/material.dart';
import 'package:amyal_pay/theme/custom_theme_colors.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';

// AMIAL-BRANDING-003: ألوان هوية أميال باي = أزرق الشعار + أصفر الشعار.
// موحّدة تماماً مع AmyalColors (primary/yellow) ليتطابق النظامان عبر كل الشاشات.
const Color _primaryColor = Color(0xFF053391);   // أزرق الشعار (بطاقة الرصيد/الأزرار)
const Color _secondaryColor = Color(0xFFFECA1E); // أصفر الشعار (إبرازات/أزرار ثانوية)
const Color _onSecondaryColor = Color(0xFF053391); // نصّ/أيقونة أزرق على الأصفر

ThemeData light = ThemeData(
  brightness: Brightness.light,
  fontFamily: 'Rubik',
  primaryColor:  _primaryColor,
  primaryColorLight: const Color(0xFF1D4FB8),
  scaffoldBackgroundColor: AmyalColors.background,

  // AMIAL-DS-003 — سمة شريط العنوان: مصدر واحد لـ134 شاشة.
  //
  // لم تكن هناك appBarTheme إطلاقاً، فكانت كل شاشة تُلوّن شريطها بنفسها
  // (`backgroundColor: primary` + `foregroundColor: white`) — 141 ملفاً
  // يكرّر نفس السطرين. النتيجة شريط أزرق صلب يبتلع أعلى كل شاشة، وهو
  // التوقيع البصري الأوضح لقالب 6cash.
  //
  // المحافظ المهنية تجعل الشريط جزءاً من الصفحة لا كتلة فوقها: نفس خلفية
  // الصفحة، عنوان داكن، بلا ظلّ. تغييره لاحقاً = هذا الموضع وحده.
  appBarTheme: const AppBarTheme(
    backgroundColor: AmyalColors.background,
    foregroundColor: Color(0xFF1A2433),
    surfaceTintColor: Colors.transparent,
    elevation: 0,
    scrolledUnderElevation: 0,
    centerTitle: true,
    titleTextStyle: TextStyle(
      fontFamily: 'Rubik',
      fontSize: 16,
      fontWeight: FontWeight.bold,
      color: Color(0xFF1A2433),
    ),
    iconTheme: IconThemeData(color: Color(0xFF1A2433), size: 22),
  ),
  // highlightColor: const Color(0xFF003E47),
  cardColor: const Color(0xFFFAFAFA),
  shadowColor: Colors.grey[300],
  dialogTheme: const DialogThemeData(surfaceTintColor: Colors.white),
  dividerColor: const Color(0x1A9E9E9E),
  extensions: <ThemeExtension<CustomThemeColors>>[
    CustomThemeColors.light(),
  ],
  colorScheme: ColorScheme(
    brightness: Brightness.light,
    primary: _primaryColor,
    onPrimary: Colors.white,
    secondary: _secondaryColor,
    onSecondary: _onSecondaryColor,
    error: const Color(0xFFDC0A0B),
    onError: const Color(0xFFFFD1D1),
    surface: Colors.white,
    onSurface:  const Color(0xFF222324), // textTheme.titleLarge?.color
    shadow: Colors.grey[300],
  ),

);









// ThemeData light = ThemeData(
//   brightness: Brightness.light,
//   fontFamily: 'Rubik',
//   primaryColor: const Color(0xFF003E47),
//   primaryColorLight: const Color(0xFF14684E),
//   secondaryHeaderColor: const Color(0xFFE0EC53),
//   scaffoldBackgroundColor: const Color(0xFFf7f7f7),
//   highlightColor: const Color(0xFF003E47),
//   cardColor: Colors.white,
//   shadowColor: Colors.grey[300],
//   textTheme: const TextTheme(
//     titleLarge: TextStyle(color: Color(0xFF003E47)),
//     titleSmall: TextStyle(color: Color(0xFF25282D)),
//   ),
//   dialogTheme: const DialogThemeData(surfaceTintColor: Colors.white),
//   bottomNavigationBarTheme: BottomNavigationBarThemeData(
//     backgroundColor: Colors.white, selectedItemColor: ColorResources.themeLightBackgroundColor,
//   ),
//   dividerColor: const Color(0x1A9E9E9E),
//   colorScheme: ColorScheme(
//     brightness: Brightness.light,
//     primary: const Color(0xFF003E47),
//     onPrimary: const Color(0xFF562E9C),
//     secondary: const Color(0xFFE0EC53),
//     onSecondary: const Color(0xFFE0EC53),
//     error: Colors.redAccent,
//     onError: Colors.redAccent,
//     surface: Colors.white,
//     onSurface:  const Color(0xFF002349),
//     onSecondaryContainer: const Color(0xFFff8672),
//     shadow: Colors.grey[300],
//   ),
//
// );