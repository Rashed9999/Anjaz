// اختبارات حتمية خفيفة — لا تعتمد على إضافات native ولا على بناء شاشات تحتاج DI.
//
// لماذا لا نُقلع التطبيق كاملاً هنا؟
//   إقلاع `MyApp` يمرّ بـ `di.init()` الذي يستدعي إضافات native
//   (device_info_plus، unique_identifier) غير المتاحة في بيئة `flutter test`
//   الـ headless فتعلّق — وهذا قيد معروف في Flutter. لذلك يُغطّى الإقلاع الكامل
//   والتدفّقات في `integration_test/app_test.dart` (على جهاز/محاكي)، بينما نتحقّق
//   هنا من **منطق التوجيه والتهيئة** بمعزل وحتمياً.

import 'package:flutter_test/flutter_test.dart';

import 'package:amyal_pay/util/app_constants.dart';
import 'package:amyal_pay/features/auth/screens/role_router.dart';
import 'package:amyal_pay/features/home/screens/nav_bar_screen.dart';
import 'package:amyal_pay/features/merchant/screens/merchant_dashboard_screen.dart';
import 'package:amyal_pay/features/access/screens/home_dispatcher_screen.dart';
import 'package:amyal_pay/features/agent/screens/agent_dashboard_screen.dart';

void main() {
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

  group('توجيه الأدوار (RoleRouter)', () {
    test('العميل → شاشة Cash6 الكاملة (NavBarScreen)، لا placeholder', () {
      // الإصلاح الأساسي: العميل يصل لشاشته الحقيقية (تحويل/QR/رصيد…) لا شاشة مؤقتة.
      expect(RoleRouter.homeForRole('customer'), isA<NavBarScreen>());
      // أي دور غير معروف يقع افتراضياً على شاشة العميل أيضاً.
      expect(RoleRouter.homeForRole('unknown'), isA<NavBarScreen>());
    }, timeout: fast);

    test('التاجر/الـ POS → موزّع القطاعات، ومرجعه لوحة التاجر', () {
      // AMIAL-SECTOR-ROUTING: التاجر لم يعد يذهب إلى لوحة واحدة، بل يمرّ
      // بموزّع يقرأ نوع نشاطه (صيدلية/مطعم/وقود/جملة) ويفتح لوحته. ولوحة
      // التاجر العامّة هي المرجع حين لا يكون النشاط من القطاعات المعروفة.
      for (final role in ['merchant', 'pos']) {
        final home = RoleRouter.homeForRole(role);
        expect(home, isA<HomeDispatcherScreen>(), reason: 'الدور: $role');
        expect((home as HomeDispatcherScreen).userHomeFallback,
            isA<MerchantDashboardScreen>());
      }
    }, timeout: fast);

    test('الوكيل → لوحة الوكيل', () {
      expect(RoleRouter.homeForRole('agent'), isA<AgentDashboardScreen>());
    }, timeout: fast);
  });
}
