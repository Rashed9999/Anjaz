// اختبارات واجهة (widget) خفيفة وحتمية — لا تعتمد على إضافات native.
//
// لماذا لا نُقلع التطبيق كاملاً هنا؟
//   إقلاع `MyApp` يمرّ بـ `di.init()` الذي يستدعي إضافات native
//   (device_info_plus، unique_identifier). هذه غير متاحة في بيئة
//   `flutter test` الـ headless فتعلّق/تفشل — وهذا قيد معروف في Flutter.
//   لذلك يُغطّى الإقلاع الكامل في `integration_test/app_test.dart` (على جهاز/محاكي)،
//   بينما نتحقّق هنا من **بناء الشاشات وأزرارها** بمعزل عن native.

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:get/get.dart';

import 'package:amyal_pay/util/app_constants.dart';
import 'package:amyal_pay/features/auth/screens/role_router.dart';

void main() {
  // حدّ زمني صارم: لا نسمح بأي تعليق طويل في CI.
  const fast = Timeout(Duration(seconds: 30));

  group('التهيئة (config)', () {
    test('اللغات: العربية أساسية + الإنجليزية فقط (AMIAL-I18N-001)', () {
      final codes = AppConstants.languages.map((l) => l.languageCode).toList();
      // بالضبط لغتان، العربية أولاً ثم الإنجليزية — بلا بنغالي/هندي.
      expect(codes, equals(['ar', 'en']));
      expect(codes.contains('bn'), isFalse);
      expect(codes.contains('hi'), isFalse);
    }, timeout: fast);
  });

  group('شاشة العميل الرئيسية — البناء والأزرار', () {
    testWidgets('تُبنى وتعرض أزرار الإجراءات الستة وكلّها قابلة للنقر',
        (WidgetTester tester) async {
      // homeForRole('customer') يُرجع شاشة العميل دون الحاجة لأي controller للبناء.
      await tester.pumpWidget(
        GetMaterialApp(home: RoleRouter.homeForRole('customer')),
      );
      await tester.pump(const Duration(milliseconds: 300));

      // لا انهيار في البناء.
      expect(find.byType(ErrorWidget), findsNothing);

      // عناوين أزرار الإجراءات كلّها ظاهرة.
      for (final label in const [
        'تحويل', 'دفع QR', 'فواتير', 'تبرعات', 'صندوق عائلي', 'دفع آمن',
      ]) {
        expect(find.text(label), findsOneWidget, reason: 'الزر "$label" يجب أن يظهر');
      }

      // كل عناصر الشبكة أزرار InkWell فعّالة (onTap != null) — لا أزرار ميتة.
      final inkWells = tester.widgetList<InkWell>(find.byType(InkWell)).toList();
      expect(inkWells.length, greaterThanOrEqualTo(6));
      for (final w in inkWells) {
        expect(w.onTap, isNotNull, reason: 'كل زر يجب أن يملك onTap غير فارغ');
      }
    }, timeout: fast);
  });
}
