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
import 'package:amyal_pay/features/agent/controllers/agent_controller.dart';
import 'package:amyal_pay/features/agent/domain/repositories/agent_repo.dart';
import 'package:amyal_pay/features/agent/screens/agent_dashboard_screen.dart';
import 'package:amyal_pay/features/admin/controllers/whatsapp_settings_controller.dart';
import 'package:amyal_pay/features/admin/domain/repositories/admin_repo.dart';
import 'package:amyal_pay/features/admin/screens/whatsapp_settings_screen.dart';

class _MockAccessRepo extends Mock implements AccessRepo {}
class _MockMerchantRepo extends Mock implements MerchantRepo {}
class _MockAgentRepo extends Mock implements AgentRepo {}
class _MockAdminRepo extends Mock implements AdminRepo {}

void main() {
  const fast = Timeout(Duration(seconds: 30));

  // كل الميزات المعروفة في شاشات الـ Home (لإظهار كل الأزرار المحميّة بـ AccessGate).
  const allFeatures = {
    'debts', 'daily_reports', 'products', 'inventory', 'customers', 'barcode',
    'agent_float', 'agent_commissions', 'agent_reports',
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

    testWidgets('الوكيل: شحن/سحب + شبكة', (t) async {
      registerAccess();
      await t.pumpWidget(GetMaterialApp(home: const AgentHomeScreen()));
      await t.pump();
      expect(find.byType(ErrorWidget), findsNothing);
      for (final l in ['شحن', 'سحب']) {
        expect(find.text(l), findsOneWidget, reason: 'الزر "$l" يجب أن يظهر');
      }
      expectLiveButtons(t, 2);
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

    testWidgets('لوحة الوكيل', (t) async {
      registerAccess();
      final repo = _MockAgentRepo();
      when(() => repo.getProfile())
          .thenAnswer((_) async => const Response(statusCode: 503, statusText: 'x'));
      when(() => repo.dailyStats())
          .thenAnswer((_) async => const Response(statusCode: 503, statusText: 'x'));
      Get.put<AgentController>(AgentController(repo: repo));

      await t.pumpWidget(GetMaterialApp(home: const AgentDashboardScreen()));
      await t.pump();
      await t.pump(const Duration(milliseconds: 300));
      expect(find.byType(ErrorWidget), findsNothing);
    }, timeout: fast);
  });

  group('شاشة إعدادات واتساب (أدمن)', () {
    testWidgets('تُبنى وتعرض المزوّدين وأزرارها', (t) async {
      final repo = _MockAdminRepo();
      when(() => repo.whatsappConfig()).thenAnswer((_) async => Response(
            statusCode: 200,
            body: {
              'success': true,
              'meta': {
                'providers': [
                  {'provider': 'ultramsg', 'enabled': true, 'config': {'instance_id': 'i', 'token': '••••t123'}},
                  {'provider': 'meta_cloud', 'enabled': false, 'config': {}},
                ],
                'channel_preference': 'whatsapp_first',
                'channels': ['whatsapp_first', 'sms_first', 'whatsapp_only', 'sms_only'],
              },
            },
          ));
      Get.put<WhatsappSettingsController>(WhatsappSettingsController(repo: repo));

      await t.pumpWidget(GetMaterialApp(home: const WhatsappSettingsScreen()));
      await t.pump();
      await t.pump(const Duration(milliseconds: 300)); // postFrame load

      expect(find.byType(ErrorWidget), findsNothing);
      expect(find.text('ترتيب القناة'), findsOneWidget);
      expect(find.text('ultramsg'), findsOneWidget);
      expect(find.text('إرسال تجريبي'), findsWidgets);
    }, timeout: fast);
  });
}
