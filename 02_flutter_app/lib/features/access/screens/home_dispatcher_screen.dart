import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amial_pay/theme/amial_colors.dart';
import 'package:amial_pay/features/access/controllers/access_controller.dart';
import 'package:amial_pay/features/access/screens/web_portal_notice_screen.dart';

/// CRITICAL-001 — Home Dispatcher.
///
/// نقطة الدخول الموحّدة بعد تسجيل الدخول.
/// يفحص access ويعرض الشاشة المناسبة:
///   - Merchant owner → WebPortalNoticeScreen (لوحة المنشأة على المتصفّح)
///   - POS staff → PosEmployeeHomeScreen (البيع والوردية في التطبيق)
///   - Agent/Admin → WebPortalNoticeScreen (لوحتاهما على المتصفّح)
///   - User → الـ Home الأصلي (existing)
class HomeDispatcherScreen extends StatefulWidget {
  /// Home الأصلي (للمستخدم العادي) — يُمرَّر من الخارج.
  final Widget userHomeFallback;

  const HomeDispatcherScreen({super.key, required this.userHomeFallback});

  @override
  State<HomeDispatcherScreen> createState() => _HomeDispatcherScreenState();
}

class _HomeDispatcherScreenState extends State<HomeDispatcherScreen> {
  late final AccessController _access;

  @override
  void initState() {
    super.initState();
    _access = Get.find<AccessController>();
    WidgetsBinding.instance.addPostFrameCallback((_) async {
      if (!_access.isLoaded.value && !_access.isLoading.value) {
        await _access.load();
      }
    });
  }

  @override
  Widget build(BuildContext context) {
    return Obx(() {
      // 1) ما زال يُحمَّل
      if (_access.isLoading.value && !_access.isLoaded.value) {
        return const Scaffold(
          backgroundColor: AmialColors.background,
          body: Center(child: CircularProgressIndicator()),
        );
      }

      // 2) فشل التحميل → fallback للـ Home الأصلي
      if (!_access.isLoaded.value) {
        return widget.userHomeFallback;
      }

      if (_access.isPosStaff || _access.isMerchant) {
        return const WebPortalNoticeScreen(role: 'merchant');
      }

      // 5) AMIAL-WEB-ONLY-PORTALS-001: الوكيل والأدمن لوحتاهما على المتصفّح.
      // يُفحصان هنا أيضاً لا في RoleRouter وحده — لهذه الشاشة مدخلان:
      // بعد الدخول مباشرةً، وعند العودة إليها لاحقاً بحسابٍ محفوظ.
      // (القاعدة الرابعة: ميزةٌ لها مدخلان تُختبَر من مدخليها.)
      if (_access.isAgent) {
        return const WebPortalNoticeScreen(role: 'agent');
      }
      if (_access.isAdmin) {
        return const WebPortalNoticeScreen(role: 'admin');
      }

      // User أو Distributor → الـ Home الأصلي
      return widget.userHomeFallback;
    });
  }
}

/// Helper: غلاف لاستخدام Dispatcher مع home_screen الأصلي بدون الحاجة لتغيير routing.
///
/// الاستخدام في routes:
///   Get.toNamed(RouteHelper.getDashboardRoute());
/// لكن Dashboard يُلفّ تلقائياً بـ HomeDispatcherScreen.
class HomeDispatcherWrapper extends StatelessWidget {
  final Widget child; // الـ home_screen الأصلي
  const HomeDispatcherWrapper({super.key, required this.child});

  @override
  Widget build(BuildContext context) {
    return HomeDispatcherScreen(userHomeFallback: child);
  }
}
