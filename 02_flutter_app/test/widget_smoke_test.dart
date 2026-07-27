import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:amyal_pay/features/shared/widgets/amial_numpad.dart';
import 'package:amyal_pay/features/shared/widgets/amial_pin_dots.dart';
import 'package:amyal_pay/common/widgets/amial_ltr_number.dart';
import 'package:amyal_pay/features/safe_payment/domain/models/safe_payment_models.dart';
import 'package:amyal_pay/features/safe_payment/widgets/trust_card.dart';
import 'package:amyal_pay/features/safe_payment/widgets/delivery_code_card.dart';

/// AMIAL-SMOKE-001 — الودجات تُبنى فعلاً، لا تُحلَّل فقط.
///
/// **الفجوة التي يسدّها هذا الملفّ:**
/// `flutter analyze` يقرأ الأنواع ولا يُشغّل شيئاً، والاختبارات السابقة
/// كانت كلّها على دوالّ نقيّة. فأي انهيار داخل `build()` — تأكيد عدم عدم
/// على قيمة فارغة، قسمة على صفر في حساب مقاس، أصل مفقود، تجاوز تخطيط —
/// لا يظهر في أيّهما. يظهر على جهاز المستخدم وحده.
///
/// هذه الاختبارات تبني الودجات في شجرة حقيقية وتتحقّق من ظهورها. لا
/// تُغني عن التشغيل على جهاز، لكنها تلتقط صنف الانهيارات الذي كان يصل
/// المستخدم مباشرةً.
void main() {
  /// يلفّ الودجت في MaterialApp عربيّ الاتجاه — كما يعمل في التطبيق.
  Widget wrap(Widget child) => MaterialApp(
        home: Directionality(
          textDirection: TextDirection.rtl,
          child: Scaffold(body: SingleChildScrollView(child: child)),
        ),
      );

  group('لوحة الأرقام', () {
    testWidgets('تُبنى وتعرض الأرقام العشرة', (tester) async {
      final controller = TextEditingController();
      await tester.pumpWidget(wrap(AmialNumpad(controller: controller)));

      for (var d = 0; d <= 9; d++) {
        expect(find.text('$d'), findsOneWidget, reason: 'الرقم $d غائب');
      }
    });

    testWidgets('الضغط يكتب في المتحكّم ويحترم الحدّ', (tester) async {
      final controller = TextEditingController();
      await tester.pumpWidget(wrap(
        AmialNumpad(controller: controller, maxLength: 4, compact: true),
      ));

      for (final d in ['1', '2', '3', '4', '5']) {
        await tester.tap(find.text(d));
        await tester.pump();
      }

      expect(controller.text, '1234', reason: 'الحدّ الأقصى لم يُحترم');
    });

    testWidgets('المسح يحذف رقماً واحداً', (tester) async {
      final controller = TextEditingController(text: '123');
      await tester.pumpWidget(wrap(AmialNumpad(controller: controller)));

      await tester.tap(find.byIcon(Icons.backspace_outlined));
      await tester.pump();

      expect(controller.text, '12');
    });

    testWidgets('الترتيب على الشاشة: 1 يساراً ثم 2 ثم 3', (tester) async {
      // هذا ما اشتكى منه المستخدم ولم يلتقطه أي اختبار: كانت الأرقام
      // موجودة كلّها — فيمرّ فحص الوجود — لكنها تظهر «3 2 1» لأن الواجهة
      // العربية تعكس الصفوف. الوجود ليس ترتيباً، فيُقاس الموضع نفسه.
      final controller = TextEditingController();
      await tester.pumpWidget(wrap(AmialNumpad(controller: controller)));

      double x(String d) => tester.getCenter(find.text(d)).dx;

      expect(x('1'), lessThan(x('2')), reason: 'ظهر «2 1» معكوساً');
      expect(x('2'), lessThan(x('3')), reason: 'ظهر «3 2» معكوساً');
      expect(x('4'), lessThan(x('6')));
      expect(x('7'), lessThan(x('9')));

      // والصفوف تنزل تصاعدياً: 1 فوق 4 فوق 7 — كلوحة الاتصال.
      double y(String d) => tester.getCenter(find.text(d)).dy;
      expect(y('1'), lessThan(y('4')));
      expect(y('4'), lessThan(y('7')));
      expect(y('7'), lessThan(y('0')));
    });

    testWidgets('وضع الرمز السري بلا زرّ 000', (tester) async {
      final controller = TextEditingController();
      await tester.pumpWidget(wrap(
        AmialNumpad(controller: controller, compact: true),
      ));

      // «000» يمنح المتلصّص ضغطةً مميّزة الشكل يقرؤها من بعيد.
      expect(find.text('000'), findsNothing);
    });
  });

  group('نقاط الرمز', () {
    testWidgets('تُبنى وتتبع طول المُدخل', (tester) async {
      final controller = TextEditingController();
      await tester.pumpWidget(wrap(AmialPinDots(controller: controller)));

      expect(tester.takeException(), isNull);

      controller.text = '12';
      await tester.pump();
      expect(tester.takeException(), isNull);
    });
  });

  group('الأرقام في واجهة عربية', () {
    testWidgets('رقم الحساب يُعرض باتجاه لاتيني داخل RTL', (tester) async {
      await tester.pumpWidget(wrap(const AmialLtrNumber('12 34 5678')));

      final text = tester.widget<Text>(find.text('12 34 5678'));
      expect(text.textDirection, TextDirection.ltr,
          reason: 'بلا اتجاه صريح ينعكس الترتيب فيظهر «5678 34 12»');
    });
  });

  group('بطاقات الدفع الآمن', () {
    testWidgets('سجلّ الثقة يُبنى لحساب جديد بلا صفقات', (tester) async {
      // الحساب الجديد أخطر حالة: كل الأرقام صفر، وأي قسمة عليها تنهار.
      await tester.pumpWidget(wrap(TrustCard(
        trust: AmyalTrustSummary.fromJson(const {
          'role': 'seller',
          'completed_deals': 0,
          'disputed_deals': 0,
          'total_deals': 0,
          'dispute_rate': 0,
          'badge': 'جديد',
        }),
        counterpartyName: 'أحمد',
      )));

      expect(tester.takeException(), isNull);
      expect(find.textContaining('جديد'), findsWidgets);
    });

    testWidgets('سجلّ الثقة يُبنى لحساب بسجلّ', (tester) async {
      await tester.pumpWidget(wrap(TrustCard(
        trust: AmyalTrustSummary.fromJson(const {
          'role': 'seller',
          'completed_deals': 14,
          'disputed_deals': 1,
          'total_deals': 15,
          'dispute_rate': 6.7,
          'member_since': '2025-11',
          'badge': 'عادي',
        }),
        counterpartyName: 'محمد',
      )));

      expect(tester.takeException(), isNull);
      expect(find.text('14'), findsOneWidget);
    });

    testWidgets('بطاقة رمز التسليم تعرض الرمز مفصولاً', (tester) async {
      await tester.pumpWidget(wrap(const BuyerDeliveryCodeCard(code: '481037')));

      expect(tester.takeException(), isNull);
      // الفصل كل ثلاثة: العين تلتقط 481 037 أسرع، والصوت كذلك.
      expect(find.text('481 037'), findsOneWidget);
    });
  });
}
