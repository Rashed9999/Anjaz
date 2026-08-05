import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/features/access/screens/home_dispatcher_screen.dart';
import 'package:amyal_pay/features/access/screens/web_portal_notice_screen.dart';
import 'package:amyal_pay/features/merchant/screens/merchant_dashboard_screen.dart';
import 'package:amyal_pay/features/home/screens/nav_bar_screen.dart';

/// AMIAL-UNIFIED-AUTH-001 (v1.7)
///
/// RoleRouter — يوجّه المستخدم للشاشة المناسبة حسب دوره بعد تسجيل الدخول.
///
/// AMIAL-SECTOR-ROUTING: التاجر يمرّ عبر HomeDispatcher الذي يقرأ نوع نشاطه
/// من الخادم (me/access) ويفتح لوحة قطاعه: محطة الوقود / الصيدلية / الجملة /
/// البيع السريع (بائع السمك والبسطات) — ومن لا قطاعَ خاصاً له تفتح له لوحة
/// التاجر العامة. (كان يذهب الجميع للوحة العامة متجاوزاً الموزّع.)
///
/// AMIAL-WEB-ONLY-PORTALS-001: `agent` و`admin` لم تعد لهما لوحاتٌ في
/// التطبيق — بوّابتاهما على المتصفّح (`/agent/login` و`/admin/auth/login`).
/// **ولا يسقطان إلى `default`**: تلك شاشةُ العميل، فيهبط الوكيل في محفظةٍ
/// ليست لوحته بلا رسالة. حالتاهما صريحتان تفتحان شاشة الإحالة.
class RoleRouter {
  /// توجيه للشاشة الرئيسية حسب الدور.
  static void navigateToHome(String role) {
    switch (role) {
      case 'merchant':
      case 'pos':
        Get.offAll(() => const HomeDispatcherScreen(
              userHomeFallback: MerchantDashboardScreen(),
            ));
        break;
      case 'agent':
      case 'admin':
        Get.offAll(() => WebPortalNoticeScreen(role: role));
        break;
      case 'customer':
      default:
        // الشاشة الرئيسية للعميل الكاملة من Cash6 (تحويل/QR/رصيد/سجل…).
        Get.offAll(() => const NavBarScreen());
    }
  }

  /// واجهة الشاشة الرئيسية حسب الدور (بدون navigation - للـ embedding).
  static Widget homeForRole(String role) {
    return switch (role) {
      'merchant' || 'pos' => const HomeDispatcherScreen(
          userHomeFallback: MerchantDashboardScreen(),
        ),
      'agent' || 'admin' => WebPortalNoticeScreen(role: role),
      _ => const NavBarScreen(),
    };
  }
}
