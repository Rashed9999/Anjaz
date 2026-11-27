import 'dart:async';
import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amial_pay/features/access/screens/home_dispatcher_screen.dart';
import 'package:amial_pay/features/access/screens/web_portal_notice_screen.dart';
import 'package:amial_pay/features/auth/controllers/auth_controller.dart';
import 'package:amial_pay/features/home/screens/nav_bar_screen.dart';
import 'package:amial_pay/features/notification/controllers/notifications_center_controller.dart';
import 'package:amial_pay/helper/notification_helper.dart';

/// AMIAL-UNIFIED-AUTH-001 (v1.7)
///
/// RoleRouter — يوجّه المستخدم للشاشة المناسبة حسب دوره بعد تسجيل الدخول.
///
/// AMIAL-MERCHANT-WEB-ONLY-001: التاجر يمرّ عبر HomeDispatcher لكي يميّز
/// مالك المنشأة من موظف نقطة البيع. المالك يُحال إلى بوابة الويب، وموظف POS
/// وحده يبقى في التطبيق؛ فلا يتسرب رصيد المالك أو إعدادات منشأته إلى جهاز بيع.
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
              userHomeFallback: WebPortalNoticeScreen(role: 'merchant'),
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

    // AMIAL-FCM-UNIFIED-001: بعد استقرار الشاشة الرئيسية لأي دور، نطلب إذن
    // الإشعارات على Android 13+ / iOS ونرفع fcm_token ونحدّث عدّاد الإشعارات
    // ونستهلك أي إشعار فتح التطبيق من الخلفية المغلقة.
    WidgetsBinding.instance.addPostFrameCallback((_) {
      try {
        if (Get.isRegistered<AuthController>()) {
          unawaited(Get.find<AuthController>().updateToken());
        }
      } catch (_) {}
      try {
        if (Get.isRegistered<NotificationsCenterController>()) {
          unawaited(Get.find<NotificationsCenterController>().refreshUnreadCount());
        }
      } catch (_) {}
      try {
        unawaited(NotificationHelper.consumePendingInitialNotification());
      } catch (_) {}
    });
  }

  /// واجهة الشاشة الرئيسية حسب الدور (بدون navigation - للـ embedding).
  static Widget homeForRole(String role) {
    return switch (role) {
      'merchant' || 'pos' => const HomeDispatcherScreen(
          userHomeFallback: WebPortalNoticeScreen(role: 'merchant'),
        ),
      'agent' || 'admin' => WebPortalNoticeScreen(role: role),
      _ => const NavBarScreen(),
    };
  }
}
