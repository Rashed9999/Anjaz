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

  // ══════════════════════════════════════════════════════════════════
  //  AMIAL-STATE-001 — ألوانُ الحالات: طبقةٌ كانت تُخترع في كلّ ملفّ.
  // ══════════════════════════════════════════════════════════════════
  //  **قِيس قبل كتابتها:** ٤١١ لوناً خاماً في ٩٩ ملفّاً، وستّةُ أخضرَ
  //  مختلفةٍ تعني «نجح» — ‎0xFF2E7D32 وحدَه ٩٩ مرّة، ومعه ‎0xFF16A34A
  //  و‎0xFF008926 و‎0xFF0F9D58 و‎0xFF12694E و‎0xFF059669. ولا واحدَ منها
  //  في هذا الملفّ. فمن احتاج أخضرَ نجاحٍ لم يجده فاخترعه — ويتكرّر بلا
  //  نهايةٍ ما لم يوجد الثابت.
  //
  //  والقيمُ مطابقةٌ لِـ`amial-tokens.css` حرفاً، ويحرسها
  //  `BrandIdentityGuardTest`. وكلُّها مقيسةٌ فوق حدّ WCAG AA.
  // ══════════════════════════════════════════════════════════════════

  /// نجحت · مُعتمَدة · متاح — تباين 5.39:1 على الأبيض.
  static const Color success = Color(0xFF0F7A46);

  /// معلَّقة · تحتاج مراجعة — 5.93:1.
  static const Color warning = Color(0xFF8A5A00);

  /// فشلت · مرفوضة · مجمَّدة — 5.13:1. (هي حمراءُ العلامة نفسُها.)
  static const Color danger = red;

  /// معلومةٌ محايدة — 7.34:1.
  static const Color info = Color(0xFF1D4FB8);

  /// أسطحُ الحالات — خلفيّةُ الشارة أو البطاقة، والنصُّ فوقها لونُ الحالة.
  /// كانت هي الأخرى تُخترع: ‎E3F3E5 تسع مرّات و‎FDE7E7 ثمانياً و‎FFF8E1 ثمانياً.
  static const Color successSurface = Color(0xFFE3F3E5);
  static const Color warningSurface = Color(0xFFFFF8E1);
  static const Color dangerSurface = Color(0xFFFDE7E7);

  // ══════════════════════════════════════════════════════════════════
  //  AMIAL-SIGNATURE-001 — الزوجُ الذي يميّز هذا المنتج عن أيّ محفظة.
  // ══════════════════════════════════════════════════════════════════
  //  للفرع قيدان متعاكسان: النقدُ الورقيّ يحدّ **السحب**، والرصيدُ
  //  الإلكترونيّ يحدّ **الإيداع**. وموظّفٌ يرى رقماً واحداً كبيراً يَعِد
  //  العميلَ بما لا يستطيع.
  //
  //  ويُحمَل بلونَي العلامة نفسِهما لا بلونين جديدين: **النقدُ ذهبُها،
  //  والإلكترونيُّ أزرقُها** — والورقةُ النقديّة اليمنيّة ذهبيّة.
  //  ويفترقان بالشكل أيضاً (مصمتٌ مقابل محدَّد) فعمى الألوان لا يُلغيه.
  // ══════════════════════════════════════════════════════════════════

  /// نقدٌ ورقيّ في الدرج — ذهبُ العلامة داكناً، 6.25:1.
  static const Color cash = Color(0xFF7A5C00);

  /// رصيدٌ إلكترونيّ في النظام — أزرقُ العلامة، 7.34:1.
  static const Color emoney = Color(0xFF1D4FB8);

  /// Material color seed (Flutter 3+ ColorScheme.fromSeed)
  /// يستخدم في ThemeData لتوليد material 3 palette
  static const Color seedColor = primary;
}
