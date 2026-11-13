import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:amial_pay/features/setting/widgets/usage_limits_bar.dart';
import 'package:amial_pay/theme/amial_colors.dart';

/// AMIAL-USAGE-LIMITS-BAR-001 — شريط حدود الاستخدام في «حسابي».
///
/// الأرقام تأتي من الخادم (`tier.limits` و`tier.usage`)، فالمُختَبَر هنا: ما يُحسب منها (النسبة، المتبقي، اللون)،
/// وما يُعرض، وأنّ الشريط مُوصَل فعلاً بشاشة «حسابي» (شاشةٌ مبنيّةٌ لا يُوصَل إليها ليست مبنيّة).
Map<String, dynamic> tierTwo({String today = '0', String month = '0', String balance = '0'}) => {
      'current': 2,
      'name': 'عميل موثق بهوية',
      'limits': {
        'max_balance': '250000',
        'max_single_transaction': '250000',
        'max_daily_total': '250000',
        'max_monthly_total': '250000',
      },
      'usage': {'today': today, 'month': month, 'year': '0', 'balance': balance},
    };

void main() {
  group('حساب الصفوف من استجابة الخادم', () {
    test('ثلاثة صفوف: اليوم والشهر والرصيد، بنسبها ومتبقّيها', () {
      final rows = UsageLimitsBar.rowsFrom(tierTwo(today: '100000.0000', month: '150000.0000', balance: '50000.0000'));
      expect(rows.map((r) => r.label), ['الحركة اليوم', 'الحركة هذا الشهر', 'رصيد المحفظة']);
      expect(rows[0].fraction, closeTo(0.4, 1e-9));
      expect(rows[0].remaining, 150000);
      expect(rows[2].remaining, 200000);
    });

    test('اللون: عادي ثم تنبيه عند ٨٠٪ ثم أحمر عند الاستنفاد', () {
      Color colorOf(String used) => UsageLimitsBar.rowsFrom(tierTwo(today: used)).first.color;
      expect(colorOf('100000'), AmialColors.primary);
      expect(colorOf('200000'), AmialColors.warning);
      expect(colorOf('250000'), AmialColors.red);
      expect(colorOf('300000'), AmialColors.red, reason: 'تجاوز الحدّ (حساب قديم) يبقى أحمر ولا يتجاوز الشريطُ إطارَه');
      expect(UsageLimitsBar.rowsFrom(tierTwo(today: '300000')).first.fraction, 1.0);
      expect(UsageLimitsBar.rowsFrom(tierTwo(today: '300000')).first.remaining, 0);
    });

    test('«غير معروف» ليس صفراً: خادمٌ لم يرسل الرصيد ⇒ لا صفّ رصيد، ومستوى بلا حدّ ⇒ لا صفوف', () {
      final noBalance = tierTwo();
      (noBalance['usage'] as Map).remove('balance');
      expect(UsageLimitsBar.rowsFrom(noBalance).map((r) => r.label), isNot(contains('رصيد المحفظة')));

      final tierZero = {'current': 0, 'name': 'عميل غير موثق', 'limits': {'max_daily_total': '0', 'max_monthly_total': '0', 'max_balance': '0'}, 'usage': {'today': '0', 'month': '0', 'balance': '0'}};
      expect(UsageLimitsBar.rowsFrom(tierZero), isEmpty);
    });
  });

  group('العرض', () {
    Future<void> pump(WidgetTester t, Map<String, dynamic> tier, {VoidCallback? onUpgrade}) => t.pumpWidget(MaterialApp(
          home: Directionality(
            textDirection: TextDirection.rtl,
            child: Scaffold(body: SingleChildScrollView(child: UsageLimitsBar(tier: tier, onUpgrade: onUpgrade))),
          ),
        ));

    testWidgets('يعرض المستوى والصفوف والمتبقي وحدّ العملية', (t) async {
      await pump(t, tierTwo(today: '100000', month: '150000', balance: '50000'));
      expect(find.text('حدود استخدامك'), findsOneWidget);
      expect(find.text('عميل موثق بهوية'), findsOneWidget);
      expect(find.text('الحركة اليوم'), findsOneWidget);
      expect(find.text('الحركة هذا الشهر'), findsOneWidget);
      expect(find.text('رصيد المحفظة'), findsOneWidget);
      expect(find.textContaining('المتبقي 150,000'), findsOneWidget);
      expect(find.textContaining('حدّ العملية الواحدة: 250,000'), findsOneWidget);
      expect(find.byType(LinearProgressIndicator), findsNWidgets(3));
    });

    testWidgets('الاستنفاد يُقال بالعربية، والشريط لا يتجاوز إطاره', (t) async {
      await pump(t, tierTwo(today: '250000'));
      expect(find.text('استنفدتَ الحدّ'), findsOneWidget);
      final bar = t.widget<LinearProgressIndicator>(find.byKey(const Key('usage-bar-الحركة اليوم')));
      expect(bar.value, 1.0);
    });

    testWidgets('المستوى صفر: رسالة تفعيل بدل أشرطة فارغة', (t) async {
      await pump(t, {'current': 0, 'name': 'عميل غير موثق', 'limits': {}, 'usage': {}});
      expect(find.textContaining('أكمل توثيق هاتفك وسكنك'), findsOneWidget);
      expect(find.byType(LinearProgressIndicator), findsNothing);
    });

    testWidgets('زرّ الرفع يظهر دون المستوى الثالث ويستدعي المعالج، ويختفي في الثالث', (t) async {
      var tapped = 0;
      await pump(t, tierTwo(), onUpgrade: () => tapped++);
      await t.tap(find.text('ارفع حدودك بتوثيق أعلى'));
      expect(tapped, 1);

      final three = tierTwo()..['current'] = 3;
      await pump(t, three, onUpgrade: () => tapped++);
      expect(find.text('ارفع حدودك بتوثيق أعلى'), findsNothing);
    });
  });

  test('الشريط مُوصَل بشاشة «حسابي» ولا يحسب حدّاً بنفسه', () {
    final profile = File('lib/features/setting/screens/profile_screen.dart').readAsStringSync();
    expect(profile, contains('UsageLimitsBarLoader('));
    expect(profile, contains("usage_limits_bar.dart"));
    final bar = File('lib/features/setting/widgets/usage_limits_bar.dart').readAsStringSync();
    expect(bar, contains("'max_daily_total'"));
    expect(bar, isNot(contains('250000')), reason: 'الحدود من الخادم لا مكتوبةٌ في التطبيق');
    expect(bar, isNot(contains('100000')));
  });
}
