import 'package:flutter/material.dart';

import 'custom_theme_colors.dart';


const Color _primaryColor = Color(0xFF4b8793);
const Color _secondaryColor = Color(0xFFaaa818);

ThemeData dark = ThemeData(
  fontFamily: 'Roboto',
  primaryColor: _primaryColor,
  primaryColorLight: _primaryColor,
  brightness: Brightness.dark,
  shadowColor: Colors.black.withValues(alpha:0.4),
  cardColor: const Color(0xFF29292D),
  textTheme: const TextTheme(
    titleLarge: TextStyle(color:Color(0xFF8dbac3)),
    titleSmall: TextStyle(color: Color(0xFF25282D)),
  ),
  dialogTheme: const DialogThemeData(surfaceTintColor: Colors.black),
  dividerColor: const Color(0x2A9E9E9E),
  extensions: <ThemeExtension<CustomThemeColors>>[
    CustomThemeColors.dark(),
  ],
  colorScheme: ColorScheme(
    brightness: Brightness.dark,
    primary: _primaryColor,
    onPrimary: _primaryColor,
    secondary: _secondaryColor,
    onSecondary: _secondaryColor,
    error: Colors.redAccent,
    onError: Colors.redAccent,
    surface: const Color(0xFF212121),
    onSurface:  Colors.white70,
    shadow: Colors.black.withValues(alpha:0.4),
  ),
);
