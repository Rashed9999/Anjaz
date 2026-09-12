import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:amial_pay/features/auth/widgets/signature_pad_widget.dart';

/// AMIAL-SIGNATURE-PAD-002 — **التوقيعُ داخل صفحةٍ تتمرّر.**
///
/// خطوةُ التوقيع في معالج التسجيل تعرض ثلاثةَ مربّعاتٍ داخل
/// `SingleChildScrollView`. و`onPanUpdate` ينازع مُمرِّضَ الصفحة في حلبة
/// الإيماءات — **والمُمرِّرُ يفوز بالسحب الرأسيّ**. فمن وقّع حركةً صاعدةً
/// أو نازلةً انقطع خطُّه وتمرّرت الصفحةُ تحت إصبعه.
///
/// **ولا يمسك هذا تحليلٌ ولا مُصرِّف**: الشيفرةُ سليمةٌ نحويّاً، والرسمُ
/// يعمل تماماً في شاشةٍ لا تتمرّر. لا يظهر إلّا حيث يُستعمل فعلاً.
class _Harness extends StatelessWidget {
  const _Harness({required this.padKey, required this.controller});

  final GlobalKey<SignaturePadState> padKey;
  final ScrollController controller;

  @override
  Widget build(BuildContext context) => MaterialApp(
        home: Scaffold(
          body: SingleChildScrollView(
            controller: controller,
            child: Column(
              children: [
                const SizedBox(height: 300),
                SignaturePadWidget(key: padKey, height: 150),
                const SizedBox(height: 900),
              ],
            ),
          ),
        ),
      );
}

void main() {
  testWidgets('السحبُ الرأسيُّ فوق المربّع يوقّع ولا يمرّر الصفحة',
      (tester) async {
    final padKey = GlobalKey<SignaturePadState>();
    final controller = ScrollController();

    await tester.pumpWidget(_Harness(padKey: padKey, controller: controller));

    expect(padKey.currentState!.isEmpty, isTrue,
        reason: 'المربّعُ يبدأ فارغاً');
    final offsetBefore = controller.offset;

    // الحركةُ التي كانت تُسرَق: سحبٌ رأسيٌّ فوق المربّع نفسِه.
    await tester.drag(find.byType(SignaturePadWidget), const Offset(6, -70));
    await tester.pumpAndSettle();

    expect(padKey.currentState!.isEmpty, isFalse,
        reason: '**المُمرِّرُ خطف الإيماءةَ فلم يُسجَّل توقيع** — '
            'وهذا بعينه «مربّعاتُ التوقيع ليست سلسة».');

    expect(controller.offset, offsetBefore,
        reason: 'تمرّرت الصفحةُ تحت الإصبع أثناء التوقيع');
  });

  testWidgets('ويبقى التمريرُ عاملاً خارج المربّع', (tester) async {
    final padKey = GlobalKey<SignaturePadState>();
    final controller = ScrollController();

    await tester.pumpWidget(_Harness(padKey: padKey, controller: controller));

    // **الثمنُ المقصودُ يُقاس هو أيضاً**: الحسمُ داخل المربّع لا يشلّ
    // الصفحة — فمن سحب من خارجه يتمرّر كالمعتاد.
    await tester.drag(find.byType(SingleChildScrollView), const Offset(0, -120),
        warnIfMissed: false);
    await tester.pumpAndSettle();

    expect(controller.offset, greaterThan(0.0),
        reason: 'الصفحةُ لم تعد تتمرّر إطلاقاً — الحسمُ ابتلع التمريرَ كلَّه');
    expect(padKey.currentState!.isEmpty, isTrue,
        reason: 'سحبٌ خارج المربّع رسم فيه');
  });

  testWidgets('«مسح التوقيع» يُفرغ المربّع', (tester) async {
    final padKey = GlobalKey<SignaturePadState>();
    final controller = ScrollController();

    await tester.pumpWidget(_Harness(padKey: padKey, controller: controller));

    await tester.drag(find.byType(SignaturePadWidget), const Offset(6, -70));
    await tester.pumpAndSettle();
    expect(padKey.currentState!.isEmpty, isFalse);

    await tester.tap(find.text('مسح التوقيع'));
    await tester.pumpAndSettle();

    expect(padKey.currentState!.isEmpty, isTrue,
        reason: 'الزرُّ لا يمسح — فمن أخطأ توقيعَه حُبس عليه');
  });
}
