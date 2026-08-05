import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';
import 'package:amyal_pay/features/access/controllers/access_controller.dart';
import 'package:amyal_pay/features/access/widgets/access_gate.dart';
import 'package:amyal_pay/features/access/screens/role_based_home_screens.dart';
import 'package:amyal_pay/features/fuel_station/screens/fuel_station_dashboard_screen.dart';
import 'package:amyal_pay/features/pharmacy/screens/pharmacy_dashboard_screen.dart';
import 'package:amyal_pay/features/wholesale/screens/wholesale_screens.dart';
import 'package:amyal_pay/features/access/screens/web_portal_notice_screen.dart';

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

  @override
  Widget build(BuildContext context) {
    return Obx(() {
      // 1) ما زال يُحمَّل
      if (_access.isLoading.value && !_access.isLoaded.value) {
        return const Scaffold(
          backgroundColor: AmyalColors.background,
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
      // Merchant + fuel → Fuel Dashboard مباشرة
      if (_access.isMerchant && _access.isFuel) {
        return const FuelStationDashboardScreen();
      }

      // Merchant + pharmacy → Pharmacy Dashboard
      if (_access.isMerchant && _access.isPharmacy) {
        return const PharmacyDashboardScreen();
      }

      // Merchant + wholesale → Wholesale Dashboard
      if (_access.isMerchant && _access.isWholesale) {
        return const WholesaleDashboardScreen();
      }

      // Merchant + quick_sale → بسيط جداً
      if (_access.isMerchant && _access.isQuickSale) {
        return const MerchantQuickSaleHomeScreen();
      }

      // Merchant + retail → POS متوسط
      if (_access.isMerchant && _access.isRetail) {
        return const MerchantRetailHomeScreen();
      }

      // Merchant + restaurant → placeholder للآن
      if (_access.isMerchant && _access.isRestaurant) {
        return _placeholderScreen('قطاع المطاعم قريباً');
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

  Widget _placeholderScreen(String title) {
    return Scaffold(
      backgroundColor: AmyalColors.background,
      appBar: AppBar(
        title: Text(title),
      ),
      body: Center(
        child: Padding(
          padding: const EdgeInsets.all(32),
          child: Column(mainAxisAlignment: MainAxisAlignment.center, children: [
            const Icon(Icons.construction, size: 80, color: AmyalColors.yellowDark),
            const SizedBox(height: 16),
            Text(title, textAlign: TextAlign.center,
                style: const TextStyle(fontSize: 18, fontWeight: FontWeight.bold)),
            const SizedBox(height: 8),
            Text('سيتم إضافة هذا القطاع في الإصدار القادم',
                style: TextStyle(color: Colors.grey.shade600), textAlign: TextAlign.center),
          ]),
        ),
      ),
    );
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
