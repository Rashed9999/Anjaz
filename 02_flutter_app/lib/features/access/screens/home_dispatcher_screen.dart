import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amial_pay/theme/amial_colors.dart';
import 'package:amial_pay/features/access/controllers/access_controller.dart';
import 'package:amial_pay/features/access/widgets/access_gate.dart';
import 'package:amial_pay/features/access/screens/role_based_home_screens.dart';
import 'package:amial_pay/features/fuel_station/screens/fuel_station_dashboard_screen.dart';
import 'package:amial_pay/features/fuel_station/screens/fuel_owner_console_screen.dart';
import 'package:amial_pay/features/pharmacy/screens/pharmacy_dashboard_screen.dart';
import 'package:amial_pay/features/wholesale/screens/wholesale_screens.dart';
import 'package:amial_pay/features/restaurant/screens/restaurant_screen.dart';
import 'package:amial_pay/features/access/screens/web_portal_notice_screen.dart';
import 'package:amial_pay/features/merchant/screens/merchant_adaptive_shell.dart';

/// CRITICAL-001 — Home Dispatcher.
///
/// نقطة الدخول الموحّدة بعد تسجيل الدخول.
/// يفحص access ويعرض الشاشة المناسبة:
///   - تاجر بدون business_type → BusinessTypeSelectionScreen (إلزامي)
///   - Fuel → FuelStationDashboardScreen
///   - Pharmacy → PharmacyDashboardScreen
///   - Quick Sale → MerchantQuickSaleHomeScreen
///   - Retail/Wholesale → MerchantRetailHomeScreen
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

  Widget _merchantShell(Widget child) => MerchantAdaptiveShell(child: child);

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

      // 3) تاجر لم يختر نوع نشاطه → إجبار الاختيار
      if (_access.needsBusinessTypeSelection) {
        return const BusinessTypeSelectionScreen(mandatory: true);
      }

      // 4) Route حسب الدور + business_type
      // Merchant + fuel → **لوحة المحطة** (AMIAL-FUEL-VERTICAL-001 · ٨)
      //
      // وكانت تقود إلى لوحةٍ واحدةٍ للجميع، فيرى الكاشيرُ ما يراه المالك.
      // ولوحةُ المحطة تُبنى من صلاحيّات الداخل: المالكُ يرى الأقسام
      // السبعة، والكاشيرُ يرى البيعَ وورديّتَه، وموظّفُ المخزون يرى
      // الخزّاناتِ ولا يرى ريالاً.
      if (_access.isMerchant && _access.isFuel) {
        return _merchantShell(const FuelOwnerConsoleScreen());
      }

      // Merchant + pharmacy → Pharmacy Dashboard
      if (_access.isMerchant && _access.isPharmacy) {
        return _merchantShell(const PharmacyDashboardScreen());
      }

      // Merchant + wholesale → Wholesale Dashboard
      if (_access.isMerchant && _access.isWholesale) {
        return _merchantShell(const WholesaleDashboardScreen());
      }

      // Merchant + quick_sale → بسيط جداً
      if (_access.isMerchant && _access.isQuickSale) {
        return _merchantShell(const MerchantQuickSaleHomeScreen());
      }

      // Merchant + retail → POS متوسط
      if (_access.isMerchant && _access.isRetail) {
        return _merchantShell(const MerchantRetailHomeScreen());
      }

      // Merchant + restaurant → operational restaurant workspace.
      if (_access.isMerchant && _access.isRestaurant) {
        return _merchantShell(const RestaurantScreen());
      }

      // أي نشاط تاجر جديد لم يُضف له Dispatcher متخصص بعد يحصل على القائمة
      // الذكية أيضاً، لكن يبقى محتوى Home هو fallback الحقيقي القائم.
      if (_access.isMerchant) {
        return _merchantShell(widget.userHomeFallback);
      }

      // AMIAL-WEB-ONLY-PORTALS-001: الوكيل والأدمن لوحتاهما على المتصفّح.
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
