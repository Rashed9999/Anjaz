import 'package:flutter/material.dart';

/// AMYAL-BRANDING-001
///
/// ألوان البراند الرسمية لـ Amyal Pay.
/// مستخرجة بكسل-بكسل من الشعار الرسمي.
///
/// **استخدم هذه الـ class مباشرة في الـ widgets:**
///   color: AmyalColors.primary
///
/// **لا تنسخ القيم في widgets**: لو تغير اللون، نغيّره هنا فقط.
class AmyalColors {
  AmyalColors._(); // private constructor — لا instantiation

  /// الأزرق الرئيسي — للنصوص الكبيرة، الأزرار، الـ AppBar.
  /// مستخرج من حرف "أميال" في الشعار.
  static const Color primary = Color(0xFF053391);

  /// تدرّجات الأزرق
  static const Color primaryDark = Color(0xFF021F60);
  static const Color primaryLight = Color(0xFF3B66C0);

  /// الذهبي الساطع — الخلفية الرئيسية للبراند، Splash، أزرار التأكيد.
  static const Color yellow = Color(0xFFFECA1E);

  /// تدرّجات الأصفر
  static const Color yellowDark = Color(0xFFCFA300);
  static const Color yellowLight = Color(0xFFFFE680);

  /// الأحمر — التحذيرات، الأخطاء، فقط (الوثيقة قسم 7).
  /// لا تستخدمه للزرّ "حذف" الافتراضي إلا في حالات حذف نهائي.
  static const Color red = Color(0xFFDC0A0B);

  /// خلفية الشاشات الفاتحة
  static const Color background = Color(0xFFFFF8E1);

  /// خلفية البطاقات على background
  static const Color cardSurface = Color(0xFFFFFFFF);

  /// النص الأساسي الداكن — للعناوين والنصوص على الخلفيات الفاتحة.
  static const Color textPrimary = Color(0xFF1A2433);

  /// نصوص ثانوية (رمادي يحترم الـ contrast)
  static const Color textSecondary = Color(0xFF5F6B7C);
  static const Color textMuted = Color(0xFF8B97A8);

  /// حدود فاتحة
  static const Color border = Color(0xFFE5E7EB);

  /// Material color seed (Flutter 3+ ColorScheme.fromSeed)
  /// يستخدم في ThemeData لتوليد material 3 palette
  static const Color seedColor = primary;
}
