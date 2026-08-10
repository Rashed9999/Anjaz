// AMIAL-CART-BAR-001 · AMIAL-STATEMENT-FIX-001 — عطلان أُبلغ عنهما من الشاشة.
//
// ══════════════════════════════════════════════════════════════════════
// ① **شريطُ السلة كان يملأ الشاشة كلَّها.** `Column` بلا
//   `mainAxisSize.min` داخل `Row`، و`Scaffold.bottomSheet` يمنح ارتفاعاً
//   فضفاضاً أقصاه الشاشة — فيأخذ العمودُ كلَّ ما مُنح ويسحب معه الصفَّ.
//
//   **ولا خطأ في أيّ سجلّ**: التخطيطُ صحيحٌ نحويّاً، والنتيجةُ لوحةٌ زرقاء.
//   ولا يمسكه `flutter analyze` ولا اختبارُ «تُبنى دون انهيار» — الشاشةُ
//   تُبنى، وتُبنى خطأً.
//
//   فالحارسُ يقيس **الارتفاع الفعليّ المرسوم**.
//
// ② **كشفُ الحساب** كان يقول «لا توجد عمليات في هذه الفترة» على شاشةٍ
//   تعرض عشرين عمليّة. والسببُ شرطٌ يسأل متحكّماً آخرَ عن حالةٍ لم
//   تُحمَّل، فيردّ `null` قبل أن يُرسَل طلبٌ واحد.

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:get/get.dart';
import 'package:mocktail/mocktail.dart';

import 'package:amial_pay/features/history/controllers/transaction_history_controller.dart';
import 'package:amial_pay/features/history/domain/reposotories/transaction_history_repo.dart';

class _MockHistoryRepo extends Mock implements TransactionHistoryRepo {}

void main() {
  const fast = Timeout(Duration(seconds: 30));

  // ══════════════════════════════════════════════════════════════════
  //  ① شريطُ السلة — يُقاس ارتفاعُه المرسوم
  // ══════════════════════════════════════════════════════════════════

  group('شريط السلة لا يبتلع الشاشة', () {
    /// نسخةٌ مصغَّرة من تخطيط الشريط: **البنيةُ نفسُها** — `bottomNavigationBar`
    /// فيه `Row` بـ`mainAxisSize.min` وفيه `Column` بـ`min`.
    ///
    /// ولا تُستورَد الشاشةُ كاملةً: هي تحتاج متحكّمَ كاشير ومستودعاتٍ
    /// وشبكة، والعطلُ في التخطيط وحدَه. **فيُقاس التخطيط.**
    Widget bar({required MainAxisSize rowSize, required MainAxisSize colSize}) {
      return MaterialApp(
        home: Scaffold(
          body: const SizedBox.expand(),
          bottomNavigationBar: SafeArea(
            child: Material(
              key: const Key('bar'),
              color: Colors.blue,
              child: Padding(
                padding: const EdgeInsets.all(10),
                child: Row(
                  mainAxisSize: rowSize,
                  children: [
                    const Icon(Icons.shopping_cart),
                    const SizedBox(width: 12),
                    Expanded(
                      child: Column(
                        mainAxisSize: colSize,
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: const [
                          Text('200 ر.ي'),
                          Text('2 صنفاً في السلة'),
                        ],
                      ),
                    ),
                    const Text('مراجعة الطلب'),
                  ],
                ),
              ),
            ),
          ),
        ),
      );
    }

    testWidgets('الشريط لا يتجاوز ربع الشاشة', (tester) async {
      await tester.pumpWidget(
          bar(rowSize: MainAxisSize.min, colSize: MainAxisSize.min));
      await tester.pumpAndSettle();

      final barHeight = tester.getSize(find.byKey(const Key('bar'))).height;
      final screenHeight = tester.view.physicalSize.height / tester.view.devicePixelRatio;

      expect(barHeight, lessThan(screenHeight * 0.25),
          reason: 'ارتفاع الشريط $barHeight من أصل $screenHeight — '
              'شريطُ سلةٍ صار لوحةً تملأ الشاشة');
    }, timeout: fast);

    testWidgets('وعمودٌ بلا min هو ما كان يبتلعها — يُقاس الفرق', (tester) async {
      // **إثباتُ السبب لا وصفُه.** ولولا هذا القياس لبقي «الشريط كبير»
      // رأياً، ولجاز إصلاحُه بارتفاعٍ ثابتٍ يخفي السبب ولا يزيله.
      await tester.pumpWidget(
          bar(rowSize: MainAxisSize.max, colSize: MainAxisSize.max));
      await tester.pumpAndSettle();

      final broken = tester.getSize(find.byKey(const Key('bar'))).height;

      await tester.pumpWidget(
          bar(rowSize: MainAxisSize.min, colSize: MainAxisSize.min));
      await tester.pumpAndSettle();

      final fixed = tester.getSize(find.byKey(const Key('bar'))).height;

      expect(fixed, lessThan(broken),
          reason: 'العمودُ بـmin وبلا min أعطيا الارتفاع نفسَه '
              '($fixed مقابل $broken) — القياسُ لا يفرّق بينهما فلا يحرس');
    }, timeout: fast);
  });

  // ══════════════════════════════════════════════════════════════════
  //  ② كشفُ الحساب — «لا عمليات» ليست «فشل»
  // ══════════════════════════════════════════════════════════════════

  group('تنزيل كشف الحساب', () {
    late _MockHistoryRepo repo;
    late TransactionHistoryController c;

    setUp(() {
      Get.testMode = true;
      repo = _MockHistoryRepo();
      c = TransactionHistoryController(transactionHistoryRepo: repo);
      Get.put<TransactionHistoryController>(c);
    });

    tearDown(Get.reset);

    test('التنزيل يُرسل طلباً ولا يُرفض من حالةِ متحكّمٍ لم تُحمَّل', () async {
      // **وهذا هو العطل بعينه**: `_transactionModel` فارغٌ لأنّ شاشةَ
      // التقارير تجلب عمليّاتِها بنفسها. وكان الشرطُ يردّ `null` هنا
      // **قبل أن يُرسَل طلبٌ واحد**.
      when(() => repo.downloadTransactionHistory(
            transactionType: any(named: 'transactionType'),
            balanceType: any(named: 'balanceType'),
            startDate: any(named: 'startDate'),
            endDate: any(named: 'endDate'),
          )).thenAnswer((_) async => const Response(
            statusCode: 200, body: '%PDF-1.4 fake'));

      final pdf = await c.downloadTransactionHistory(transactionType: 'all');

      verify(() => repo.downloadTransactionHistory(
            transactionType: any(named: 'transactionType'),
            balanceType: any(named: 'balanceType'),
            startDate: any(named: 'startDate'),
            endDate: any(named: 'endDate'),
          )).called(1);

      expect(pdf, isNotNull,
          reason: 'التنزيل رُفض والخادمُ ردّ ملفّاً — الشرطُ يسأل حالةً '
              'لا علاقةَ لها بالطلب');

      expect(pdf!.isNotEmpty, isTrue);
    });

    test('والفشلُ يحمل سببَه — لا «لا توجد عمليات»', () async {
      when(() => repo.downloadTransactionHistory(
            transactionType: any(named: 'transactionType'),
            balanceType: any(named: 'balanceType'),
            startDate: any(named: 'startDate'),
            endDate: any(named: 'endDate'),
          )).thenAnswer((_) async => const Response(statusCode: 500, body: ''));

      final pdf = await c.downloadTransactionHistory();

      expect(pdf, isNull);

      expect(c.downloadError.isNotEmpty, isTrue,
          reason: 'فشلٌ بلا سبب — والشاشةُ تترجمه «لا توجد عمليات» '
              'وأمام المستعمل عشرون عمليّة');
    });

    test('وانقطاعُ الشبكة يُقال شبكةً لا فراغَ بيانات', () async {
      when(() => repo.downloadTransactionHistory(
            transactionType: any(named: 'transactionType'),
            balanceType: any(named: 'balanceType'),
            startDate: any(named: 'startDate'),
            endDate: any(named: 'endDate'),
          )).thenAnswer((_) async => const Response(statusCode: 0, body: null));

      await c.downloadTransactionHistory();

      expect(c.downloadError, contains('اتصال'),
          reason: 'انقطاعُ الشبكة يُعرض كأنّه فراغُ بيانات — '
              'فيُرسَل من انقطعت شبكتُه يبحث عن عمليّاته');
    });

    test('ولا يعلق مؤشّرُ التحميل عند الخروج المبكّر', () async {
      when(() => repo.downloadTransactionHistory(
            transactionType: any(named: 'transactionType'),
            balanceType: any(named: 'balanceType'),
            startDate: any(named: 'startDate'),
            endDate: any(named: 'endDate'),
          )).thenAnswer((_) async => const Response(statusCode: 500, body: ''));

      await c.downloadTransactionHistory();

      expect(c.isLoading, isFalse,
          reason: 'المؤشّر بقي دائراً بعد الفشل — يعلق الزرّ في '
              '«جارٍ التنزيل» حتّى تُغلق الشاشة');
    });
  });
}
