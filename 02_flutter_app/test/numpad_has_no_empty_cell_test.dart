// AMIAL-NUMPAD-CLEAR-001 — **خانةٌ فارغةٌ ليست تصميماً.**
//
// ══════════════════════════════════════════════════════════════════════
// قال صاحبُ المشروع: «لوحةُ إدخال رمز Pin هناك **خانةٌ ناقصة** أحتاج إلى
// تكملتها من أجل لوحةٍ نظيفة».
//
// وقِيس: `compact` — وهي وضعُ شاشات الرمز السرّيّ كلِّها — كانت ترسم
// `SizedBox` فارغاً مكانَ «000». والفجوةُ في الصفّ الأخير تُقرأ **زرّاً
// معطَّلاً** لا فراغاً مقصوداً: يُضغط فلا يحدث شيءٌ ولا رسالة، وهو بعينه
// ما تحاربه القاعدة التاسعة.
//
// **وأربعُ حالات، والرابعةُ هي التي تمنع «الإصلاح» بالحشو:** زرٌّ يملأ
// الفراغَ ولا يفعل شيئاً هو الفجوةُ نفسُها بثوبٍ جديد.

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:amial_pay/features/shared/widgets/amial_numpad.dart';

Widget _host(TextEditingController c, {required bool compact}) => MaterialApp(
      home: Scaffold(
        body: AmialNumpad(controller: c, compact: compact, maxLength: 4),
      ),
    );

void main() {
  testWidgets('① لا خانةَ فارغةً في وضع الرمز السرّيّ', (tester) async {
    final c = TextEditingController();
    await tester.pumpWidget(_host(c, compact: true));

    expect(find.byKey(const Key('numpad-clear')), findsOneWidget,
        reason: 'الصفُّ الأخيرُ فيه فجوةٌ تُقرأ زرّاً معطَّلاً — '
            'تُضغَط فلا يحدث شيءٌ ولا رسالة');

    // والصفُّ يبقى ثلاثيّاً: «مسح» · «0» · تراجُع.
    expect(find.text('0'), findsOneWidget);
    expect(find.byKey(const Key('numpad-backspace')), findsOneWidget);
  });

  testWidgets('② و«000» تبقى في إدخال المبالغ ولا يحلّ محلّها المسح',
      (tester) async {
    // **الحاجزُ الأصليُّ يُحفَظ**: «000» توفّر ضغطاتٍ حقيقيّةً في المبالغ،
    // واستبدالُها هناك يُصلح شاشةً بكسر أخرى.
    final c = TextEditingController();
    await tester.pumpWidget(_host(c, compact: false));

    expect(find.text('000'), findsOneWidget);
    expect(find.byKey(const Key('numpad-clear')), findsNothing);
  });

  testWidgets('③ والمسحُ يمسح الرمزَ كلَّه لا محرفاً', (tester) async {
    final c = TextEditingController();
    await tester.pumpWidget(_host(c, compact: true));

    for (final d in ['1', '2', '3', '4']) {
      await tester.tap(find.text(d));
      await tester.pump();
    }
    expect(c.text, '1234');

    await tester.tap(find.byKey(const Key('numpad-clear')));
    await tester.pump();

    expect(c.text, '',
        reason: 'زرُّ «مسح» لم يمسح — فهو حشوٌ يملأ الفجوة، '
            'وهي الفجوةُ نفسُها بثوبٍ جديد');
  });

  testWidgets('④ ويُنبَّه المستمعُ فتتبعه النقاطُ على الشاشة',
      (tester) async {
    // **بلا هذا تُمسَح القيمةُ والنقاطُ الأربعُ باقيةٌ معروضة** — فيقرأ
    // صاحبُها رمزاً مكتملاً وهو فارغ، ويضغط «تأكيد» فيُردّ.
    final c = TextEditingController();
    var seen = '—';

    await tester.pumpWidget(MaterialApp(
      home: Scaffold(
        body: AmialNumpad(
          controller: c, compact: true, maxLength: 4,
          onChanged: (v) => seen = v,
        ),
      ),
    ));

    await tester.tap(find.text('7'));
    await tester.pump();
    expect(seen, '7');

    await tester.tap(find.byKey(const Key('numpad-clear')));
    await tester.pump();

    expect(seen, '',
        reason: 'مُسحت القيمةُ ولم يُنبَّه المستمع — فتبقى النقاطُ معروضةً '
            'على رمزٍ لم يعد موجوداً');
  });
}
