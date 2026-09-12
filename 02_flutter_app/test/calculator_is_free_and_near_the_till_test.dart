// AMIAL-CALCULATOR-001 — **حاسبةٌ بجوار الشبّاك، مجّانيّةٌ للجميع.**
//
// ══════════════════════════════════════════════════════════════════════
// **بنصّ صاحب المشروع:** «أريد إضافة ميزةٍ مجّانيّةٍ لكلّ التجّار وكلّ
// الباقات، وهي الآلةُ الحاسبة، **وتكون قريبةً من الكاشير**».
//
// **وثلاثةُ شروطٍ في الطلب، ولكلٍّ حالة**: أن تحسب صحيحاً، وأن تكون
// **قريبةً من الكاشير** لا في قائمةٍ بعيدة، وأن تكون **مجّانيّةً لكلّ
// الباقات** — أي بلا `AccessGate` يحجبها عن أحد.
//
// **والرابعةُ تحفظ المال**: حاسبةٌ تكتب في حقل المبلغ من تلقائها تُنتج
// بيعةً برقمٍ لم يقصده أحد. فهي تنسخ ولا تُلصِق.

import 'dart:io';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:amial_pay/features/merchant/widgets/quick_calculator_sheet.dart';

String _code(String p) {
  final f = File(p);
  expect(f.existsSync(), isTrue, reason: 'غيرُ موجود: $p');

  return f
      .readAsStringSync()
      .split('\n')
      .where((l) => !l.trimLeft().startsWith('//') && !l.trimLeft().startsWith('///'))
      .join('\n');
}

Future<void> _press(WidgetTester t, List<String> keys) async {
  for (final k in keys) {
    await t.tap(find.byKey(Key('calc-key-$k')));
    await t.pump();
  }
}

String _shown(WidgetTester t) =>
    t.widget<Text>(find.byKey(const Key('calc-display'))).data ?? '';

void main() {
  Widget host() => const MaterialApp(
        home: Scaffold(body: QuickCalculatorSheet()),
      );

  testWidgets('① تحسب، ولا تترك أصفاراً عشريّةً زائدة', (t) async {
    await t.pumpWidget(host());

    await _press(t, ['1', '2', '0', '0', '×', '3', '=']);
    expect(_shown(t), '3600',
        reason: 'ناتجٌ خاطئ، أو «3600.0» — ورقمٌ بكسرٍ فارغٍ يُقرأ على '
            'أنّه قياسٌ لا حساب');
  });

  testWidgets('② والقسمةُ على صفرٍ تُقال بالعربيّة لا بـInfinity', (t) async {
    // **رمزٌ لا يُقرأ يُوقف صاحبَه ولا يخبره بشيء** (القاعدة السابعة).
    await t.pumpWidget(host());

    await _press(t, ['9', '÷', '0', '=']);
    expect(_shown(t).contains('صفر'), isTrue,
        reason: 'خرجت «Infinity» أو «NaN» — ولا يقرؤها بائعُ خضار');
  });

  testWidgets('③ و«C» تمسح كلَّ شيءٍ لا آخرَ رقم', (t) async {
    await t.pumpWidget(host());

    await _press(t, ['5', '0', '+', '7', 'C']);
    expect(_shown(t), '0');

    // وبعد المسح يبدأ حسابٌ جديدٌ بلا بقايا الطرف المحفوظ.
    await _press(t, ['4', '=']);
    expect(_shown(t), '4',
        reason: 'بقيت العمليّةُ معلّقةً بعد «C» — فالناتجُ يحمل ما مُسح');
  });

  testWidgets('④ وتنسخ الناتجَ ولا تُلصقه في حقل المبلغ', (t) async {
    // **أداةُ راحةٍ لا تلمس مالاً** — وحاسبةٌ تكتب في حقل البيع تُنتج
    // بيعةً برقمٍ لم يقصده أحد.
    await t.pumpWidget(host());
    expect(find.byKey(const Key('calc-copy')), findsOneWidget);

    final src = _code('lib/features/merchant/widgets/quick_calculator_sheet.dart');
    expect(src.contains('TextEditingController'), isFalse,
        reason: 'تمسك الحاسبةُ حقلَ إدخالٍ — فهي تكتب في مكانٍ ما، '
            'والمطلوبُ أن تنسخ ويقرّر صاحبُها');
  });

  test('⑤ وهي قريبةٌ من الكاشير — في شاشة البيع نفسِها', () {
    for (final p in const [
      'lib/features/merchant/screens/cashier_pos_screen.dart',
      'lib/features/pharmacy/screens/pharmacy_sale_screen.dart',
    ]) {
      expect(_code(p).contains("Key('calc-open')"), isTrue,
          reason: 'لا حاسبةَ في $p — ومن يحسب وهو على الشبّاك يحسب '
              'والسلّةُ أمامه، فشاشةٌ مستقلّةٌ تُخرجه منها');
    }
  });

  test('⑥ وفي اللوحات الثلاث، بلا بوّابةِ باقة', () {
    for (final p in const [
      'lib/features/access/screens/role_based_home_screens.dart',
      'lib/features/pharmacy/screens/pharmacy_dashboard_screen.dart',
    ]) {
      final code = _code(p);

      expect(code.contains('QuickCalculatorSheet'), isTrue,
          reason: 'لا بلاطةَ حاسبةٍ في $p');

      // **مجّانيّةٌ لكلّ الباقات** — فلا `AccessGate` يلفّها.
      final i = code.indexOf("Key('calc-tile')");
      expect(i, greaterThan(-1));

      final before = code.substring((i - 300).clamp(0, i), i);
      expect(before.contains('AccessGate'), isFalse,
          reason: 'حُجبت الحاسبةُ خلف قدرةٍ في $p — وهي أداةٌ لا تُحرّك '
              'ريالاً، وبيعُها بيعُ آلةٍ حاسبةٍ في هاتفٍ فيه واحدةٌ مجّاناً');
    }
  });
}
