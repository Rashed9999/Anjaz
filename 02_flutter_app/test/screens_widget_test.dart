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
import 'package:amyal_pay/features/agent/controllers/agent_portal_controller.dart';
import 'package:amyal_pay/features/agent/domain/repositories/agent_repo.dart';
import 'package:amyal_pay/features/agent/screens/agent_dashboard_screen.dart';
import 'package:amyal_pay/features/agent/screens/agent_portal_screen.dart';
import 'package:amyal_pay/features/admin/controllers/whatsapp_settings_controller.dart';
import 'package:amyal_pay/features/admin/controllers/settings_center_controller.dart';
import 'package:amyal_pay/features/admin/domain/repositories/admin_repo.dart';
import 'package:amyal_pay/features/admin/screens/whatsapp_settings_screen.dart';
import 'package:amyal_pay/features/admin/screens/settings_center_screen.dart';

class _MockAccessRepo extends Mock implements AccessRepo {}
class _MockMerchantRepo extends Mock implements MerchantRepo {}
class _MockAgentRepo extends Mock implements AgentRepo {}
class _MockAdminRepo extends Mock implements AdminRepo {}
class _MockAgentPortalRepo extends Mock implements AgentRepo {}

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

  group('مركز الإعدادات (أدمن)', () {
    testWidgets('يُبنى ويعرض كل الأقسام وأزرارها', (t) async {
      final repo = _MockAdminRepo();
      when(() => repo.smsConfig()).thenAnswer((_) async => Response(statusCode: 200, body: {
            'success': true,
            'meta': {
              'providers': [
                {'provider': 'twilio', 'enabled': false,
                 'fields': ['sid', 'token'], 'config': {}},
              ],
            },
          }));
      when(() => repo.notificationsConfig()).thenAnswer((_) async => Response(statusCode: 200, body: {
            'success': true,
            'meta': {'enabled': true, 'types': 'all', 'known_types': ['transfer_received']},
          }));
      when(() => repo.contactConfig()).thenAnswer((_) async => Response(statusCode: 200, body: {
            'success': true,
            'meta': {'contact': {'whatsapp_number': '967777000000',
                                 'phone_number': '+967777000000',
                                 'support_email': 'support@amyalpay.com'}},
          }));
      when(() => repo.feesList()).thenAnswer((_) async => Response(statusCode: 200, body: {
            'success': true,
            'meta': {
              'schemes': [
                {'id': 1, 'code': 'SEND_MONEY', 'fee_type': 'percent',
                 'percent_rate': '2.5', 'fixed_amount': '0', 'bearer': 'sender', 'version': 1},
              ],
              'codes': ['SEND_MONEY'], 'fee_types': ['percent'], 'bearers': ['sender'],
            },
          }));
      when(() => repo.opsStatus()).thenAnswer((_) async => Response(statusCode: 200, body: {
            'success': true,
            'meta': {
              'maintenance': {'enabled': false, 'message': 'صيانة'},
              'app_version': {'min_version': '1.0.0', 'latest_version': '1.1.0', 'message': ''},
            },
          }));
      Get.put<SettingsCenterController>(SettingsCenterController(repo: repo));

      await t.pumpWidget(GetMaterialApp(home: const SettingsCenterScreen()));
      await t.pump();
      await t.pump(const Duration(milliseconds: 300));

      expect(find.byType(ErrorWidget), findsNothing);
      // الـ ListView يبني العناصر الظاهرة فقط — نمرّر إلى كل قسم قبل التحقق.
      final list = find.byType(ListView);
      for (final s in ['إعدادات واتساب', 'مزوّدو رسائل SMS', 'إشعارات واتساب',
                       'بيانات التواصل والدعم', 'الرسوم ونسب الأرباح', 'التشغيل',
                       'وضع الصيانة', 'فحص صحّة النظام', 'تنظيف الكاش']) {
        await t.scrollUntilVisible(find.text(s), 200, scrollable: find.descendant(
            of: list, matching: find.byType(Scrollable)).first);
        expect(find.text(s), findsOneWidget, reason: 'القسم "$s" يجب أن يظهر');
      }
      // الرسم المعروض + زرّ الإنشاء (في أسفل القائمة بعد التمرير)
      await t.scrollUntilVisible(find.text('رسم جديد / تعديل نسبة'), 200,
          scrollable: find.descendant(of: list, matching: find.byType(Scrollable)).first);
      expect(find.textContaining('تحويل أموال'), findsOneWidget);
      expect(find.text('رسم جديد / تعديل نسبة'), findsOneWidget);
    }, timeout: fast);
  });

  group('لوحة ويب الوكيل (Agent Portal)', () {
    testWidgets('تُبنى وتعرض KPIs + إدارة السيولة + كشف الحركة', (t) async {
      final repo = _MockAgentPortalRepo();
      when(() => repo.floatDashboard()).thenAnswer((_) async => Response(statusCode: 200, body: {
            'success': true,
            'meta': {
              'current_float': '30000',
              'today': {
                'cash_in_total': '2000', 'cash_out_total': '1500', 'topup_total': '5000',
                'commission_earned': '50', 'transaction_count': 12,
              },
              'limits': {'daily_cash_in_remaining': '98000'},
              'low_float_warning': true,
              'agent_level': 'independent',
            },
          }));
      when(() => repo.floatStatement(from: any(named: 'from'), to: any(named: 'to')))
          .thenAnswer((_) async => Response(statusCode: 200, body: {
                'success': true,
                'meta': {
                  'from': '2026-05-01', 'to': '2026-06-01',
                  'rows': [
                    {'date': '2026-06-01', 'opening_float': '10000', 'cash_in_total': '2000',
                     'cash_out_total': '1500', 'topup_total': '5000', 'commission_earned': '50',
                     'closing_float': '13550', 'transaction_count': 7},
                  ],
                  'totals': {'cash_in_total': '2000', 'cash_out_total': '1500', 'topup_total': '5000',
                             'commission_earned': '50', 'transaction_count': 7},
                },
              }));
      when(() => repo.settlements()).thenAnswer((_) async => Response(statusCode: 200, body: {
            'success': true,
            'meta': {'settlements': [
              {'settlement_ulid': 'x', 'settlement_type': 'topup', 'amount': '5000',
               'status': 'pending', 'created_at': '2026-06-01T10:00:00Z'},
            ]},
          }));
      Get.put<AgentPortalController>(AgentPortalController(repo: repo));

      await t.pumpWidget(GetMaterialApp(home: const AgentPortalScreen()));
      await t.pump();
      await t.pump(const Duration(milliseconds: 300));

      expect(find.byType(ErrorWidget), findsNothing);
      final scrollable = find.byType(Scrollable).first;
      // الأقسام والعناصر (نمرّر إليها لأن ListView يبني الظاهر فقط)
      for (final s in ['لوحة التحكم', 'سيولتك منخفضة', 'عدد العمليات', 'إدارة السيولة',
                       'طلب زيادة الرصيد من الإدارة', 'سجل الشحن والتسويات', 'كشف حركة الرصيد']) {
        await t.scrollUntilVisible(find.textContaining(s), 150, scrollable: scrollable);
        expect(find.textContaining(s), findsWidgets, reason: 'العنصر "$s" يجب أن يظهر');
      }
    }, timeout: fast);
  });
}
