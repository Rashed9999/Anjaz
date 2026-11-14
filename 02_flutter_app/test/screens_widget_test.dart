// AMIAL-WEB-ONLY-PORTALS-001 — إحالة الوكيل والأدمن إلى المتصفّح.
//
// حلّت محلّ لوحتَيهما في التطبيق. والاختبار هنا يفحص ما يهمّ فعلاً:
// أنّ العنوان **مكتوبٌ نصّاً على الشاشة** لا مخبوءٌ خلف زرٍّ وحده —
// فمن فتح التطبيق على هاتفه ويعمل على حاسوبٍ آخر لا ينفعه زرُّ فتح.
// وأنّ مخرجاً موجود، فشاشةٌ بلا خروجٍ تحبس من دخل بحسابٍ خاطئ.

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:get/get.dart';

import 'package:amial_pay/features/access/screens/web_portal_notice_screen.dart';
import 'package:amial_pay/util/app_constants.dart';

void main() {
  const fast = Timeout(Duration(seconds: 30));

  tearDown(Get.reset);

  group('إحالة الوكيل والأدمن إلى المتصفّح', () {
    testWidgets('الوكيل: العنوان ظاهر نصّاً + فتح + نسخ + خروج', (t) async {
      await t.pumpWidget(GetMaterialApp(
          home: const WebPortalNoticeScreen(role: 'agent')));
      await t.pump();

      expect(find.byType(ErrorWidget), findsNothing);
      expect(find.text('${AppConstants.productionDomain}/agent/login'),
          findsOneWidget);
      expect(find.text('بوّابة شركات الصرافة'), findsOneWidget);
      for (final l in ['فتح في المتصفّح', 'نسخ الرابط', 'تسجيل الخروج']) {
        expect(find.text(l), findsOneWidget, reason: 'الزر "$l" يجب أن يظهر');
      }
    }, timeout: fast);

    testWidgets('الأدمن: عنوانه هو عنوان لوحة الإدارة لا الوكيل', (t) async {
      await t.pumpWidget(GetMaterialApp(
          home: const WebPortalNoticeScreen(role: 'admin')));
      await t.pump();

      expect(find.byType(ErrorWidget), findsNothing);
      expect(find.text('${AppConstants.productionDomain}/admin/auth/login'),
          findsOneWidget);
      // النفي مقصود: خلطُ العنوانين يُرسل الأدمن إلى بوّابةٍ ترفضه.
      expect(find.text('${AppConstants.productionDomain}/agent/login'),
          findsNothing);
      expect(find.text('لوحة الإدارة'), findsOneWidget);
    }, timeout: fast);
  });
}
