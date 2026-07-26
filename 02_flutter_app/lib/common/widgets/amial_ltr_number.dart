import 'package:flutter/material.dart';

/// AMIAL-RTL-NUMBER-001 — رقم يُعرض بترتيبه الصحيح داخل واجهة عربية.
///
/// **الخلل الذي يعالجه:**
///   نصّ عربي الاتجاه يُعيد ترتيب المقاطع اللاتينية المفصولة بمسافات. فرقم
///   حساب مثل «12 34 5678» يظهر «5678 34 12» — مقلوباً. الأرقام داخل كل
///   مقطع تبقى صحيحة، فيبدو الرقم سليماً للوهلة الأولى ولا يُكتشف إلا حين
///   ينسخه العميل ويجده مختلفاً. وقعت العلّة نفسها في إيصال PDF قبلاً.
///
/// `textDirection: TextDirection.ltr` وحده لا يكفي دائماً حين يكون الودجت
/// داخل شجرة RTL، فنغلّفه بـ [Directionality] أيضاً: الأوّل يضبط اتجاه
/// الفقرة، والثاني يضمن ألّا يُعيد المحيط ترتيب ما بداخله.
///
/// يُستعمل لكل ما هو معرّف لا عدد: أرقام الحسابات، الهواتف، المراجع،
/// أرقام الإيصالات. المبالغ لا تحتاجه — لا مسافات فيها تُقلب.
class AmialLtrNumber extends StatelessWidget {
  const AmialLtrNumber(
    this.value, {
    super.key,
    this.style,
    this.textAlign = TextAlign.center,
    this.selectable = false,
  });

  final String value;
  final TextStyle? style;
  final TextAlign textAlign;

  /// أرقام الحسابات تُنسخ باللمس المطوّل — لا بالنظر وإعادة الكتابة.
  final bool selectable;

  @override
  Widget build(BuildContext context) {
    return Directionality(
      textDirection: TextDirection.ltr,
      child: selectable
          ? SelectableText(
              value,
              style: style,
              textAlign: textAlign,
              textDirection: TextDirection.ltr,
            )
          : Text(
              value,
              style: style,
              textAlign: textAlign,
              textDirection: TextDirection.ltr,
            ),
    );
  }
}
