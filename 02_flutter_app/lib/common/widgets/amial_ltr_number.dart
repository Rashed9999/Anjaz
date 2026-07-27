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
/// يُستعمل لأرقام الحسابات والهواتف والمراجع وأرقام الإيصالات — **ولكل مبلغ
/// تسبقه إشارة**.
///
/// AMIAL-RTL-SIGN-001 — كان مكتوباً هنا أن «المبالغ لا تحتاجه لأن لا مسافات
/// فيها تُقلب»، وهو خطأ ظهر على جهاز مستخدم: «-1,000 ر.ي» تُعرض «1,000- ر.ي».
///
/// السبب أن `-` و`+` محايدتان في خوارزمية الاتجاه، فتتبعان اتجاه الفقرة لا
/// الرقم. والقياس يُثبته: النصّ نفسه بعرض 200 نقطة، تقع فيه الإشارة عند 180
/// في الاتجاه العربي وعند 0 في اللاتيني — أي عند الطرفين المتقابلين.
///
/// وليست مسألة شكل: «1,000-» تُقرأ ألفاً موجباً بعلامةٍ عالقة، والفرق بين
/// خصمٍ وإيداع هو كل شيء في كشف حساب.
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
