// اختبار smoke لإقلاع التطبيق.
//
// ملاحظة: استبدل هذا الملفُ قالبَ العدّاد الافتراضي (الذي لم يكن مطابقاً للتطبيق
// وكان سيفشل). يتحقق هنا أن MyApp يُبنى ويصل إلى GetMaterialApp دون انهيار.

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:get/get.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:amyal_pay/helper/get_di.dart' as di;
import 'package:amyal_pay/main.dart';

void main() {
  testWidgets('App boots to GetMaterialApp without crashing', (WidgetTester tester) async {
    SharedPreferences.setMockInitialValues(<String, Object>{});

    // تهيئة حاوية الاعتماديات (GetX controllers + services).
    final languages = await di.init();

    await tester.pumpWidget(MyApp(languages: languages, orderID: null));
    await tester.pump(const Duration(milliseconds: 500));

    expect(find.byType(GetMaterialApp), findsOneWidget);
  });
}
