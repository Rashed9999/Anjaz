// اختبارات حتمية خفيفة — لا تعتمد على إضافات native ولا على بناء شاشات تحتاج DI.
//
// لماذا لا نُقلع التطبيق كاملاً هنا؟
//   إقلاع `MyApp` يمرّ بـ `di.init()` الذي يستدعي إضافات native
//   (device_info_plus، unique_identifier) غير المتاحة في بيئة `flutter test`
//   الـ headless فتعلّق — وهذا قيد معروف في Flutter. لذلك يُغطّى الإقلاع الكامل
//   والتدفّقات في `integration_test/app_test.dart` (على جهاز/محاكي)، بينما نتحقّق
//   هنا من **منطق التوجيه والتهيئة** بمعزل وحتمياً.

import 'package:flutter_test/flutter_test.dart';

import 'package:amial_pay/util/app_constants.dart';
import 'package:amial_pay/features/auth/screens/role_router.dart';
import 'package:amial_pay/features/home/screens/nav_bar_screen.dart';
import 'package:amial_pay/features/merchant/screens/merchant_dashboard_screen.dart';
import 'package:amial_pay/features/access/screens/home_dispatcher_screen.dart';
import 'package:amial_pay/features/access/screens/web_portal_notice_screen.dart';

void main() {
  const fast = Timeout(Duration(seconds: 30));

  group('التهيئة (config)', () {
    test('اللغات: العربية أساسية وبلا لغاتٍ موروثة (AMIAL-I18N-001)', () {
      final codes = AppConstants.languages.map((l) => l.languageCode).toList();

      // **عقدُ هذا الفحص كما كُتب**: «بلا بنغالي/هندي» — وهي بقايا
      // القالب الموروث، وعودتُها تعني قائمةً بلغاتٍ لا يقرؤها أحد هنا.
      // والعربيّةُ أوّلاً لأنّها الافتراضيّةُ في `fallbackLocale`.
      expect(codes.first, 'ar');
      expect(codes.contains('bn'), isFalse);
      expect(codes.contains('hi'), isFalse);

      // ══════════════════════════════════════════════════════════════
      // **AMIAL-I18N-003 — وحبسُ الإنجليزيّة هنا كان أثراً جانبيّاً.**
      //
      // كان الفحصُ `equals(['ar','en'])`، فيشترط وجودَها. وقال صاحبُ
      // المشروع: «زرُّ الترجمة لا يعمل — عند تغيير الإنجليزيّة لا
      // يتغيّر شيء». وقِيست الآليّةُ فإذا هي سليمةٌ كاملة، **والعطلُ
      // في التغطية**: ٣٥٩ نداءَ `.tr` مقابل ٨٢٥ نصّاً محفوراً، ولوحةُ
      // التجزئة **صفرُ نداءات**.
      //
      // فرُفعت من القائمة — **ووعدٌ لا يُوفى أسوأ من غيابه**. ولم
      // يُهدَم ما بُني: `en.json` و`Messages` و`Get.updateLocale` كما
      // هي، يحرسها `no_english_reaches_the_eye_test`.
      //
      // **ولا تُعاد بتعديل هذا السطر** — تُعاد يومَ تُترجَم شاشاتُ
      // التاجر، فيمرّ الفحصُ من تلقائه.
      // ══════════════════════════════════════════════════════════════
      for (final c in codes) {
        expect(['ar', 'en'].contains(c), isTrue,
            reason: 'لغةٌ ثالثةٌ دخلت القائمة: $c');
      }
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

    // AMIAL-WEB-ONLY-PORTALS-001 — والخطر الذي يحرسه هذا الاختبار ليس
    // «أين يذهب الوكيل» بل **أن يذهب حيث لا يجب**: حذفُ الحالة من
    // `switch` لا يُنتج خطأ ترجمة، بل يُسقط الدور إلى `default` = شاشة
    // العميل. فيهبط موظّف الصرافة في محفظةٍ ليست له بلا رسالة. لذلك
    // يُتحقّق من النوع الصحيح **ومن نفي شاشة العميل معاً**.
    test('الوكيل والأدمن → شاشة الإحالة للمتصفّح، لا شاشة العميل', () {
      for (final role in ['agent', 'admin']) {
        final home = RoleRouter.homeForRole(role);
        expect(home, isA<WebPortalNoticeScreen>(), reason: 'الدور: $role');
        expect(home, isNot(isA<NavBarScreen>()), reason: 'الدور: $role');
        expect((home as WebPortalNoticeScreen).role, equals(role));
      }
    }, timeout: fast);
  });
}
