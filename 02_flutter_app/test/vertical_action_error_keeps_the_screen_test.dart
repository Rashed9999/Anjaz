// AMIAL-VERTICAL-ACTION-ERROR-001 — **خطأُ الفعل لا يمحو الشاشة.**
//
// ══════════════════════════════════════════════════════════════════════
// **الثمنُ الذي دُفع.** أرسل صاحبُ المشروع صورةَ شاشة الخزّانات وقد امتلأت
// كلُّها برسالة «تعذّر إتمام العملية · نوع الوقود غير موجود في هذه المحطة»،
// وقال: **«لا استطيع انشاء خزان او مضخة، ليس هناك طريقة للعمل»**.
//
// وقِيس: `VerticalStateView` تحجب الشاشةَ كلَّها متى كان `lastError` غيرَ
// فارغ، و`_submit` يكتب فيه عند فشل **فعل**. فمحاولةُ إضافةٍ واحدةٌ فاشلة
// تمحو القائمةَ وزرَّ الإضافة معاً — **ولا طريقَ للتصحيح إلّا الخروجُ من
// الشاشة والعودةُ إليها، ولا شيءَ يقول ذلك**.
//
// **وثلاثُ حالاتٍ لا واحدة، والثالثةُ هي التي تحفظ الحاجزَ الأصليّ:**
//
//   ① فشلُ فعلٍ ⇒ المحتوى باقٍ، والرسالةُ شريطٌ فوقه.
//   ② فشلُ تحميلٍ ⇒ الشاشةُ تُحجَب كما كانت — **فحجبٌ أُلغي كلُّه يعرض
//      قائمةً فارغةً على أنّها «لا بيانات» وهي عطل**.
//   ③ ونجاحُ فعلٍ بعد فشلٍ يُنزل الرايةَ — وإلّا بقي الشريطُ يكذب.

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:get/get.dart';

import 'package:amial_pay/common/controllers/vertical_state_mixin.dart';
import 'package:amial_pay/common/widgets/vertical_state_view.dart';

class _Ctrl extends GetxController with VerticalStateMixin {}

Widget _host(_Ctrl c) => GetMaterialApp(
      home: Scaffold(
        body: VerticalStateView(
          c: c,
          isEmpty: false,
          emptyTitle: 'لا شيء',
          onRetry: () async {},
          child: const Center(
            child: Text('القائمة', key: Key('screen-content')),
          ),
        ),
      ),
    );

void main() {
  testWidgets('① فشلُ فعلٍ يُبقي المحتوى ويعرض الرسالةَ شريطاً',
      (tester) async {
    final c = _Ctrl();
    await tester.pumpWidget(_host(c));

    c.failAction('نوع الوقود غير موجود في هذه المحطة');
    await tester.pump();

    expect(find.byKey(const Key('screen-content')), findsOneWidget,
        reason: 'مُحيت الشاشةُ عند فشل إضافة — فذهب زرُّ الإضافة معها، '
            'ولا طريقَ للتصحيح إلّا الخروجُ والعودة');

    expect(find.byKey(const Key('vertical-action-error')), findsOneWidget,
        reason: 'حُفظ المحتوى ولم تُقَل الرسالةُ — والصمتُ عن سبب الفشل '
            'أسوأ من حجب الشاشة');
  });

  testWidgets('② فشلُ تحميلٍ ما زال يحجب الشاشة', (tester) async {
    // **الحاجزُ الأصليُّ يُحفَظ** — لا بياناتٍ في الذاكرة، فعرضُ فراغٍ
    // بلا سببٍ يُقرأ «لا بيانات» والحقيقةُ عطل.
    final c = _Ctrl();
    await tester.pumpWidget(_host(c));

    c.failLoad('تعذّر تحميل الخزانات');
    await tester.pump();

    expect(find.byKey(const Key('screen-content')), findsNothing,
        reason: 'عُرض المحتوى فوق فشلِ تحميل — فما يُرى قديمٌ أو فارغٌ '
            'ولا شيءَ يقول ذلك');
    expect(find.text('تعذّر إتمام العملية'), findsOneWidget);
  });

  testWidgets('③ نجاحُ فعلٍ بعد فشلٍ يُنزل الراية', (tester) async {
    final c = _Ctrl();
    await tester.pumpWidget(_host(c));

    c.failAction('فشل');
    await tester.pump();
    expect(find.byKey(const Key('vertical-action-error')), findsOneWidget);

    // ردٌّ ناجحٌ لاحق — و`classify` تُنزل الراية.
    c.classify(const Response(statusCode: 200, body: {'success': true}));
    c.lastError.value = '';
    await tester.pump();

    expect(find.byKey(const Key('vertical-action-error')), findsNothing,
        reason: 'بقي شريطُ خطأٍ بعد نجاح — وشريطٌ يكذب يُعوّد القارئَ '
            'تجاهلَه يومَ يصدق');
    expect(find.byKey(const Key('screen-content')), findsOneWidget);
  });

  testWidgets('④ الشريطُ يُغلَق بضغطةٍ ولا يُحبَس صاحبُه فيه',
      (tester) async {
    final c = _Ctrl();
    await tester.pumpWidget(_host(c));

    c.failAction('فشل');
    await tester.pump();

    await tester.tap(find.byKey(const Key('vertical-action-error-dismiss')));
    await tester.pump();

    expect(find.byKey(const Key('vertical-action-error')), findsNothing);
    expect(c.lastError.value, '');
  });
}
