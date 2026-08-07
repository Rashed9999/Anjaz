import 'package:flutter/material.dart';

/// AMIAL-BRANDING-001
///
/// ألوان البراند الرسمية لـ Amial Pay.
/// مستخرجة بكسل-بكسل من الشعار الرسمي.
///
/// **استخدم هذه الـ class مباشرة في الـ widgets:**
///   color: AmialColors.primary
///
/// **لا تنسخ القيم في widgets**: لو تغير اللون، نغيّره هنا فقط.
class AmialColors {
  AmialColors._(); // private constructor — لا instantiation

  /// الأزرق الملكي الرئيسي — من نص «أميال» في الشعار الرسمي.
  /// AMIAL-BRANDING-003: عودة لألوان الشعار (ذهبي + أزرق + أحمر)
  /// بدل الأخضر السابق — بطلب المالك لمطابقة الهوية.
  static const Color primary = Color(0xFF053391);

  /// تدرّجات الأزرق
  static const Color primaryDark = Color(0xFF021F5C);
  static const Color primaryLight = Color(0xFF1D4FB8);

  /// الذهبي الساطع — الخلفية الرئيسية للبراند، Splash، أزرار التأكيد.
  static const Color yellow = Color(0xFFFECA1E);

  /// تدرّجات الأصفر
  static const Color yellowDark = Color(0xFFCFA300);
  static const Color yellowLight = Color(0xFFFFE680);

  /// الأحمر — التحذيرات، الأخطاء، فقط (الوثيقة قسم 7).
  /// لا تستخدمه للزرّ "حذف" الافتراضي إلا في حالات حذف نهائي.
  static const Color red = Color(0xFFDC0A0B);

  /// خلفية الشاشات الفاتحة — المصدر الوحيد لخلفية الصفحات.
  /// AMIAL-COLOR-002: كانت 0xFFFFF8E1 (كريمي دافئ) بينما theme الافتراضي
  /// 0xFFF5F4EF وشاشات أخرى 0xFFF2F3F7 و0xFFF2F5F3 — أربع خلفيات متنافسة
  /// في تطبيق واحد. وُحّدت على رمادي محايد فاتح كما في المراجع المهنية،
  /// ليبرز الأصفر والأزرق كلونَي براند لا كخلفية.
  static const Color background = Color(0xFFF2F3F7);

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
