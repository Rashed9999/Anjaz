// اختبارات واجهة لكل شاشة رئيسية: تُبنى دون انهيار وأزرارها موصولة (onTap != null).
//
// المنهج: نسجّل المتحكّمات في حاوية GetX مع **مستودعات مزيّفة** (mocktail) فلا
// حاجة لشبكة ولا إضافات native. شاشات Home حسب النوع تُقاد بـ AccessController
// (نضبط الميزات يدوياً)، واللوحات تُبنى بحالة فارغة (المستودع يُرجع غير 200).

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:get/get.dart';
import 'package:mocktail/mocktail.dart';

import 'package:amyal_pay/features/access/controllers/access_controller.dart';
import 'package:amyal_pay/features/access/domain/repositories/access_repo.dart';
import 'package:amyal_pay/features/access/screens/role_based_home_screens.dart';
import 'package:amyal_pay/features/merchant/controllers/merchant_controller.dart';
import 'package:amyal_pay/features/merchant/domain/repositories/merchant_repo.dart';
import 'package:amyal_pay/features/merchant/screens/merchant_dashboard_screen.dart';
import 'package:amyal_pay/features/access/screens/web_portal_notice_screen.dart';
import 'package:amyal_pay/util/app_constants.dart';

class _MockAccessRepo extends Mock implements AccessRepo {}
class _MockMerchantRepo extends Mock implements MerchantRepo {}

void main() {
  const fast = Timeout(Duration(seconds: 30));

  // كل الميزات المعروفة في شاشات الـ Home (لإظهار كل الأزرار المحميّة بـ AccessGate).
  const allFeatures = {
    'debts', 'daily_reports', 'products', 'inventory', 'customers', 'barcode',
  };

  tearDown(Get.reset);

  AccessController registerAccess() {
    final c = AccessController(repo: _MockAccessRepo());
    Get.put<AccessController>(c, permanent: true);
    c.userName.value = 'مستخدم تجريبي';
    c.businessTypeLabel.value = 'تجريبي';
    c.features.addAll(allFeatures);
    c.isLoaded.value = true;
    return c;
  }

  void expectLiveButtons(WidgetTester t, int atLeast) {
    final inkwells = t.widgetList<InkWell>(find.byType(InkWell)).toList();
    final live = inkwells.where((w) => w.onTap != null).length;
    expect(live, greaterThanOrEqualTo(atLeast),
        reason: 'يجب وجود $atLeast زر فعّال (onTap != null) على الأقل');
  }

  group('شاشات Home حسب نوع النشاط — الأزرار', () {
    testWidgets('بيع سريع: بيع جديد/الديون/تقرير اليوم', (t) async {
      registerAccess();
      await t.pumpWidget(GetMaterialApp(home: const MerchantQuickSaleHomeScreen()));
      await t.pump();
      expect(find.byType(ErrorWidget), findsNothing);
      for (final l in ['بيع جديد', 'الديون', 'تقرير اليوم']) {
        expect(find.text(l), findsOneWidget, reason: 'الزر "$l" يجب أن يظهر');
      }
      expectLiveButtons(t, 3);
    }, timeout: fast);

    testWidgets('تجزئة: الكاشير + شبكة الأدوات', (t) async {
      registerAccess();
      await t.pumpWidget(GetMaterialApp(home: const MerchantRetailHomeScreen()));
      await t.pump();
      expect(find.byType(ErrorWidget), findsNothing);
      for (final l in ['الكاشير', 'المنتجات', 'العملاء', 'مسح باركود', 'التقارير']) {
        expect(find.text(l), findsOneWidget, reason: 'الزر "$l" يجب أن يظهر');
      }
      expectLiveButtons(t, 5);
    }, timeout: fast);
  });

  group('لوحات التحكم — تُبنى دون انهيار', () {
    testWidgets('لوحة التاجر', (t) async {
      registerAccess();
      final repo = _MockMerchantRepo();
      when(() => repo.getProfile())
          .thenAnswer((_) async => const Response(statusCode: 503, statusText: 'x'));
      when(() => repo.dailyStats())
          .thenAnswer((_) async => const Response(statusCode: 503, statusText: 'x'));
      Get.put<MerchantController>(MerchantController(repo: repo));

      await t.pumpWidget(GetMaterialApp(home: const MerchantDashboardScreen()));
      await t.pump();
      await t.pump(const Duration(milliseconds: 300)); // postFrame loadProfile
      expect(find.byType(ErrorWidget), findsNothing);
    }, timeout: fast);
  });

  // AMIAL-WEB-ONLY-PORTALS-001 — نقطة هبوط الوكيل والأدمن.
  //
  // حلّت محلّ لوحتَيهما في التطبيق. والاختبار هنا يفحص ما يهمّ فعلاً:
  // أنّ العنوان **مكتوبٌ نصّاً على الشاشة** لا مخبوءٌ خلف زرٍّ وحده —
  // فمن فتح التطبيق على هاتفه ويعمل على حاسوبٍ آخر لا ينفعه زرُّ فتح.
  // وأنّ مخرجاً موجود، فشاشةٌ بلا خروجٍ تحبس من دخل بحسابٍ خاطئ.
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
