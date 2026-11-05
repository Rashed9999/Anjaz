import 'package:amial_pay/features/kyc_verification/screens/customer_verification_review_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  testWidgets(
    'شاشة مراجعة التوثيق تعرض البيانات والمستند والإقرار بلا ارتفاع غير محدود',
    (tester) async {
      await tester.binding.setSurfaceSize(const Size(686, 1536));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      await tester.pumpWidget(
        MaterialApp(
          home: Directionality(
            textDirection: TextDirection.rtl,
            child: CustomerVerificationReviewScreen(
              targetTier: 1,
              rows: const [
                VerificationReviewRow('الاسم الكامل', 'أحمد محمد علي'),
                VerificationReviewRow('رقم الهاتف', '967777000111'),
                VerificationReviewRow('محافظة السكن', 'عدن'),
                VerificationReviewRow('المديرية', 'دار سعد'),
              ],
              documents: const [
                VerificationReviewDocument(
                  label: 'إثبات محل السكن',
                  path: '/tmp/amial-review-missing-image.jpg',
                ),
              ],
              onConfirm: () async => true,
            ),
          ),
        ),
      );
      await tester.pump();

      expect(
        tester.takeException(),
        isNull,
        reason:
            'صفوف المراجعة لا يجوز أن تطلب ارتفاعاً غير محدود داخل ScrollView.',
      );

      expect(find.text('أحمد محمد علي'), findsOneWidget);
      expect(find.text('967777000111'), findsOneWidget);
      expect(find.text('عدن'), findsOneWidget);
      expect(find.text('إثبات محل السكن'), findsOneWidget);

      await tester.scrollUntilVisible(
        find.textContaining('أقر بأن البيانات'),
        250,
      );
      await tester.pump();

      final before = tester.widget<FilledButton>(
        find.widgetWithText(FilledButton, 'تأكيد وإرسال للتوثيق'),
      );
      expect(before.onPressed, isNull);

      await tester.tap(find.byType(Checkbox));
      await tester.pump();

      final after = tester.widget<FilledButton>(
        find.widgetWithText(FilledButton, 'تأكيد وإرسال للتوثيق'),
      );
      expect(after.onPressed, isNotNull);
      expect(tester.takeException(), isNull);
    },
  );

  testWidgets(
    'الطلب الفارغ يُقال صراحة ولا يمكن إرساله',
    (tester) async {
      await tester.pumpWidget(
        MaterialApp(
          home: CustomerVerificationReviewScreen(
            targetTier: 1,
            rows: const [],
            documents: const [],
            onConfirm: () async => true,
          ),
        ),
      );

      expect(find.textContaining('تعذر تجهيز بيانات الطلب'), findsOneWidget);
      expect(find.textContaining('لن نسمح بإرسال طلب ناقص'), findsOneWidget);

      final button = tester.widget<FilledButton>(
        find.widgetWithText(FilledButton, 'تأكيد وإرسال للتوثيق'),
      );
      expect(button.onPressed, isNull);
      expect(tester.takeException(), isNull);
    },
  );

  testWidgets(
    'فشل الإرسال يبقي المراجعة قابلة لإعادة المحاولة ولا يغلقها كنجاح',
    (tester) async {
      var confirmCalls = 0;

      await tester.pumpWidget(
        MaterialApp(
          home: Directionality(
            textDirection: TextDirection.rtl,
            child: CustomerVerificationReviewScreen(
              targetTier: 1,
              rows: const [
                VerificationReviewRow('محافظة السكن', 'عدن'),
              ],
              documents: const [
                VerificationReviewDocument(
                  label: 'إثبات محل السكن',
                  path: '/tmp/amial-review-missing-image.jpg',
                ),
              ],
              onConfirm: () async {
                confirmCalls += 1;
                return false;
              },
            ),
          ),
        ),
      );

      await tester.scrollUntilVisible(
        find.textContaining('أقر بأن البيانات'),
        250,
      );
      await tester.tap(find.byType(Checkbox));
      await tester.pump();
      await tester.tap(
        find.widgetWithText(FilledButton, 'تأكيد وإرسال للتوثيق'),
      );
      await tester.pump();

      expect(confirmCalls, 1);
      expect(find.text('مراجعة طلب التوثيق'), findsOneWidget);
      final retryButton = tester.widget<FilledButton>(
        find.widgetWithText(FilledButton, 'تأكيد وإرسال للتوثيق'),
      );
      expect(retryButton.onPressed, isNotNull);
      expect(tester.takeException(), isNull);
    },
  );
}
