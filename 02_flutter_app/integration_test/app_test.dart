// اختبار E2E (تكامل) لتطبيق أميال باي — يُشغَّل على جهاز/محاكي حقيقي.
//
// التشغيل على جهاز/محاكي:
//   flutter test integration_test/app_test.dart -d <device> \
//     --dart-define=BASE_URL=https://api.amialpay.com
//
// تشغيل headless على سطح مكتب Linux (CI بلا شاشة):
//   xvfb-run -a flutter test integration_test/app_test.dart -d linux \
//     --dart-define=BASE_URL=https://api.amialpay.com
//
// يتحقّق أن التطبيق الكامل (DI + كل الإضافات) يُقلع على جهاز حقيقي، يبني شجرة
// الواجهة (GetMaterialApp)، ولا يعرض ErrorWidget (انهيار بناء). نتجنّب
// pumpAndSettle لأن شاشات Splash/Login تحوي حركات مستمرّة (spinner/مؤشّر نص)
// لا تستقرّ أبداً.

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:get/get.dart';
import 'package:integration_test/integration_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:amyal_pay/helper/get_di.dart' as di;
import 'package:amyal_pay/main.dart';

void main() {
  IntegrationTestWidgetsFlutterBinding.ensureInitialized();

  testWidgets('التطبيق يُقلع على جهاز حقيقي ويبني الواجهة دون انهيار',
      (WidgetTester tester) async {
    SharedPreferences.setMockInitialValues(<String, Object>{});

    // تهيئة الاعتماديات الكاملة (controllers + services + إضافات native).
    final languages = await di.init();

    await tester.pumpWidget(MyApp(languages: languages, orderID: null));

    // إطارات محدودة للسماح للـ Splash بالظهور دون انتظار استقرار لا يحدث أبداً.
    await tester.pump();
    for (var i = 0; i < 10; i++) {
      await tester.pump(const Duration(milliseconds: 200));
    }

    // الشجرة الجذرية ظهرت، وبلا انهيار بناء.
    expect(find.byType(GetMaterialApp), findsOneWidget);
    expect(find.byType(ErrorWidget), findsNothing);
  });
}
