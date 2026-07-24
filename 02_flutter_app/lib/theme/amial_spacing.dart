import 'package:flutter/widgets.dart';

/// AMYAL-DS-001 — نظام تصميم أميال: مقاييس المسافات والزوايا والظلال.
///
/// مصدر واحد للمسافات عبر كل الشاشات ليكون الإيقاع البصري موحّداً (كما في
/// المراجع: مساحات سخيّة، بطاقات بزوايا ناعمة، أزرار كاملة العرض).
/// استخدمها بدل الأرقام الثابتة: `EdgeInsets.all(AmialSpacing.lg)`.
class AmialSpacing {
  AmialSpacing._();

  /// مقياس مسافات بسلّم 4pt.
  static const double xxs = 4;
  static const double xs = 8;
  static const double sm = 12;
  static const double md = 16;
  static const double lg = 20;
  static const double xl = 24;
  static const double xxl = 32;

  /// حشوة الشاشة القياسية (يسار/يمين).
  static const double screen = 20;

  /// نصف قطر الزوايا.
  static const double radiusSm = 10;
  static const double radiusMd = 14;
  static const double radiusLg = 18;
  static const double radiusXl = 24;

  /// ارتفاع الزرّ الأساسي القياسي.
  static const double buttonHeight = 52;

  /// ظلّ بطاقة ناعم موحّد.
  static List<BoxShadow> get cardShadow => [
        BoxShadow(
          color: const Color(0xFF1A2433).withValues(alpha: 0.06),
          blurRadius: 18,
          offset: const Offset(0, 6),
        ),
      ];
}
