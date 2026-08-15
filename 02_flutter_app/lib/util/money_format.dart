import 'package:flutter/widgets.dart';

/// AMIAL-MONEY-FMT-001 — **رقمُ المال يُكتب بصيغةٍ واحدة.**
///
/// ══════════════════════════════════════════════════════════════════════
/// **العطلُ المقيس:** في شاشة كشف ديون العميل ثلاثُ صيغٍ للرقم نفسِه:
///
///   بطاقةُ الرصيد   `toStringAsFixed(0)`      →  `1200`
///   بطاقتا المجموع  خامٌ من الـAPI            →  `1200.0000`
///   سطرُ الحركة     خامٌ من الـAPI            →  `1200.0000`
///
/// فيرى التاجرُ الرقمَ نفسَه بصيغتين في شاشةٍ واحدة. **ولا منسِّقَ مالٍ
/// موحَّدٌ في التطبيق كلِّه** — بُحث فلم يوجد، فكلُّ شاشةٍ تجتهد.
///
/// وأربعُ خاناتٍ عشريّةٍ للريال اليمنيّ بلا معنى: لا كسورَ متداولةً فيه.
/// والخادمُ يُرجعها لأنّ العمود `decimal(x,4)` — **دقّةُ تخزينٍ لا دقّةُ
/// عرض**. (وتُحفظ كما هي: المالُ يُخزَّن بدقّته ويُعرض بمعناه.)
///
/// ══════════════════════════════════════════════════════════════════════
/// **والأرقامُ تُثبَّت العرض** (`tabular-nums` مكافئُها هنا `FontFeature`):
/// عمودٌ من المبالغ بأرقامٍ متفاوتة العرض يهتزّ كلّما تبدّل رقم.
class Money {
  Money._();

  /// عملةُ العرض. تُقرأ من هنا لا تُكتب في شاشة.
  static const String currency = 'ر.ي';

  /// خصائصُ الخطّ لكلّ رقمٍ ماليّ — أرقامٌ ثابتةُ العرض.
  static const List<FontFeature> features = [FontFeature.tabularFigures()];

  /// `1200.0000` · `1200` · `'1200.5'` ⇒ `1,200` — بلا كسورٍ ولا عملة.
  static String plain(dynamic value) {
    final n = _toNum(value);
    if (n == null) return '—';

    final whole = n.abs().round();
    final sign = n < 0 ? '-' : '';

    return '$sign${_grouped(whole)}';
  }

  /// `1200.0000` ⇒ `1,200 ر.ي` — الصيغةُ المعتمدةُ لأيّ مبلغٍ يُعرض.
  static String format(dynamic value) => '${plain(value)} $currency';

  /// بإشارةٍ صريحة: `+1,200 ر.ي` — لسطر حركةٍ في كشف.
  static String signed(dynamic value) {
    final n = _toNum(value);
    if (n == null) return '—';

    final sign = n < 0 ? '−' : '+';

    return '$sign${_grouped(n.abs().round())} $currency';
  }

  /// **«غير معروف» ليس صفراً** (القاعدة السابعة): الغيابُ يُقال ولا يُملأ.
  static num? _toNum(dynamic v) {
    if (v == null) return null;
    if (v is num) return v;

    return num.tryParse('$v'.trim());
  }

  static String _grouped(int n) {
    final s = '$n';
    final b = StringBuffer();

    for (var i = 0; i < s.length; i++) {
      if (i > 0 && (s.length - i) % 3 == 0) b.write(',');
      b.write(s[i]);
    }

    return b.toString();
  }

  /// **يعزل نصّاً لاتينيّاً داخل سياقٍ عربيّ.**
  ///
  /// قِيس في كشف الديون: الرمزُ `#GHPXVSQZ` يُعرَض `GHPXVSQZ#` — الشرطةُ
  /// تقفز إلى آخره لأنّ خوارزمية الاتّجاهين تضمّه إلى النصّ العربيّ حوله.
  /// **وهو الرمزُ الذي يُقرأ في الهاتف عند نزاع**، فقلبُه ليس تجميلاً.
  static String isolate(String ltr) => '\u2066$ltr\u2069';
}
