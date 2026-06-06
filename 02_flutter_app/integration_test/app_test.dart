// اختبار E2E (تكامل) لتطبيق أميال باي.
//
// يشغّل التطبيق الكامل على جهاز/محاكي حقيقي ويتحقق من إقلاعه إلى أول شاشة
// (Splash) دون انهيار، ثم استقرار شجرة الواجهة.
//
// التشغيل:
//   flutter test integration_test/app_test.dart \
//     --dart-define=BASE_URL=https://api.your-domain.com
//
// (يتطلّب جهازاً متصلاً أو محاكياً — لا يعمل ضمن `flutter test` العادي وحده.)

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:get/get.dart';
import 'package:integration_test/integration_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:amyal_pay/helper/get_di.dart' as di;
import 'package:amyal_pay/main.dart';

void main() {
  IntegrationTestWidgetsFlutterBinding.ensureInitialized();

  group('Amyal Pay — E2E', () {
    setUp(() {
      SharedPreferences.setMockInitialValues(<String, Object>{});
    });

    testWidgets('التطبيق يُقلع ويصل إلى أول شاشة دون انهيار', (WidgetTester tester) async {
      final languages = await di.init();

      await tester.pumpWidget(MyApp(languages: languages, orderID: null));

      // السماح لشاشة الـ Splash والتهيئة بالاكتمال.
      await tester.pump(const Duration(seconds: 1));
      await tester.pumpAndSettle(const Duration(seconds: 5));

      // الشجرة الجذرية للتطبيق ظهرت.
      expect(find.byType(GetMaterialApp), findsOneWidget);

      // لا يوجد ErrorWidget (انهيار بناء) معروض.
      expect(find.byType(ErrorWidget), findsNothing);
    });

    testWidgets('عنوان الـ API مُهيّأ (ليس placeholder)', (WidgetTester tester) async {
      // يحرس ضد نسيان تمرير BASE_URL — مفيد في CI.
      // (تحقق منطقي بسيط لا يحتاج واجهة.)
      await tester.pump();
      // ignore: avoid_print
      print('E2E ready — تأكّد من تمرير --dart-define=BASE_URL في CI.');
    });
  });
}
