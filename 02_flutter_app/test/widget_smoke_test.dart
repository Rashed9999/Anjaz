import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:package_info_plus/package_info_plus.dart';

import 'package:amial_pay/common/widgets/amial_build_stamp.dart';

import 'package:amial_pay/features/shared/widgets/amial_numpad.dart';
import 'package:amial_pay/features/shared/widgets/amial_pin_dots.dart';
import 'package:amial_pay/common/widgets/amial_ltr_number.dart';
import 'package:amial_pay/features/safe_payment/domain/models/safe_payment_models.dart';
import 'package:amial_pay/features/safe_payment/widgets/trust_card.dart';
import 'package:amial_pay/features/safe_payment/widgets/delivery_code_card.dart';

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

  group('شاشة التشخيص (ضغطة مطوّلة على رقم الإصدار)', () {
    setUp(() {
      // بلا هذا يفشل قارئ الإصدار فتُخفي البصمةُ نفسها، فلا يبقى ما يُضغط
      // ويمرّ الاختبار على العدم.
      PackageInfo.setMockInitialValues(
        appName: 'أميال باي', packageName: 'com.amialpay.app',
        version: '1.97.0', buildNumber: '1970', buildSignature: '',
      );
    });

    testWidgets('البصمة تعرض الإصدار المشغَّل', (tester) async {
      await tester.pumpWidget(wrap(const AmialBuildStamp()));
      await tester.pumpAndSettle();

      expect(find.textContaining('1.97.0'), findsOneWidget);
      expect(find.textContaining('1970'), findsOneWidget);
    });

    testWidgets('الضغطة المطوّلة تفتح التشخيص وتُظهر حالة التبليغ', (tester) async {
      await tester.pumpWidget(wrap(const AmialBuildStamp()));
      await tester.pumpAndSettle();

      await tester.longPress(find.textContaining('1.97.0'));
      await tester.pumpAndSettle();

      expect(tester.takeException(), isNull);

      // بلا Firebase في الاختبار: يجب أن تُقال الحقيقة صراحةً بدل عرض زرّ
      // إرسال لا يُرسل شيئاً — وهذا بالضبط ما يجب أن يراه المستخدم لو تعطّل
      // التبليغ على جهازه.
      expect(find.textContaining('غير فعّالة'), findsOneWidget);
      expect(find.text('إرسال تقرير اختبار'), findsNothing,
          reason: 'زرّ إرسال وهو معطّل يجعل المستخدم ينتظر تقريراً لن يصل');
    });

    testWidgets('لا يُفتح التشخيص بضغطة عادية', (tester) async {
      // العميل يمرّ على هذه الشاشة يومياً؛ فتحُه بالخطأ يعرض له زرّ انهيار.
      await tester.pumpWidget(wrap(const AmialBuildStamp()));
      await tester.pumpAndSettle();

      await tester.tap(find.textContaining('1.97.0'));
      await tester.pumpAndSettle();

      expect(find.textContaining('غير فعّالة'), findsNothing);
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

    test('علامة السالب تقع عند الطرف الخطأ في الاتجاه العربي', () {
      // AMIAL-RTL-SIGN-001 — قياسٌ لسلوك المنصّة نفسها، لا لودجتنا.
      //
      // هذا ما ظهر على جهاز المستخدم: «-1,000 ر.ي» عُرضت «1,000- ر.ي».
      // و«1,000-» تُقرأ ألفاً موجباً بعلامة عالقة — والفرق بين خصمٍ وإيداع
      // هو كل شيء في كشف حساب.
      //
      // يُقاس موضع أوّل حرف («-») في الاتجاهين. الاختبار يوثّق السبب: لو
      // تبدّل سلوك المحرّك يوماً سقط هنا فنراجع الحلّ بدل أن نحمله إرثاً.
      double signLeft(TextDirection dir) {
        final tp = TextPainter(
          text: const TextSpan(text: '-1,000 ر.ي', style: TextStyle(fontSize: 20)),
          textDirection: dir,
        )..layout();
        return tp
            .getBoxesForSelection(
                const TextSelection(baseOffset: 0, extentOffset: 1))
            .first
            .left;
      }

      expect(signLeft(TextDirection.ltr), lessThan(10),
          reason: 'في اللاتيني يجب أن تسبق الإشارةُ الرقمَ');
      expect(signLeft(TextDirection.rtl), greaterThan(100),
          reason: 'في العربي تنزاح إلى الطرف المقابل — وهذا سبب الإصلاح');
    });

    testWidgets('المبلغ ذو الإشارة يُلفّ باتجاه لاتيني', (tester) async {
      await tester.pumpWidget(wrap(const AmialLtrNumber('-1,000 ر.ي')));

      final text = tester.widget<Text>(find.text('-1,000 ر.ي'));
      expect(text.textDirection, TextDirection.ltr);
      expect(
          tester
              .widget<Directionality>(find.ancestor(
                  of: find.text('-1,000 ر.ي'),
                  matching: find.byType(Directionality)).first)
              .textDirection,
          TextDirection.ltr,
          reason: 'الاتجاه على Text وحده لا يكفي داخل شجرة عربية');
    });
  });

  group('بطاقات الدفع الآمن', () {
    testWidgets('سجلّ الثقة يُبنى لحساب جديد بلا صفقات', (tester) async {
      // الحساب الجديد أخطر حالة: كل الأرقام صفر، وأي قسمة عليها تنهار.
      await tester.pumpWidget(wrap(TrustCard(
        trust: AmialTrustSummary.fromJson(const {
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
        trust: AmialTrustSummary.fromJson(const {
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
