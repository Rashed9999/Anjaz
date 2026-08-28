import 'package:flutter/material.dart';
import 'package:get/get.dart';

import 'package:amial_pay/features/access/controllers/access_controller.dart';
import 'package:amial_pay/features/auth/controllers/auth_controller.dart';
import 'package:amial_pay/features/entitlements/screens/my_capabilities_screen.dart';
import 'package:amial_pay/features/merchant/screens/merchant_capability_hub_screen.dart';
import 'package:amial_pay/features/plans/screens/plans_catalog_screen.dart';
import 'package:amial_pay/features/setting/screens/profile_screen.dart';
import 'package:amial_pay/features/setting/screens/support_screen.dart';
import 'package:amial_pay/features/fuel_station/screens/fuel_companies_screen.dart';
import 'package:amial_pay/features/fuel_station/screens/fuel_deliveries_screen.dart';
import 'package:amial_pay/features/fuel_station/screens/fuel_ops_center_screen.dart';
import 'package:amial_pay/features/fuel_station/screens/fuel_prices_screen.dart';
import 'package:amial_pay/features/fuel_station/screens/fuel_roles_screen.dart';
import 'package:amial_pay/features/fuel_station/screens/fuel_sale_screen.dart';
import 'package:amial_pay/features/fuel_station/screens/fuel_sales_history_screen.dart';
import 'package:amial_pay/features/fuel_station/screens/fuel_settings_screen.dart';
import 'package:amial_pay/features/fuel_station/screens/fuel_shifts_screen.dart';
import 'package:amial_pay/features/fuel_station/screens/fuel_tanks_screen.dart';
import 'package:amial_pay/theme/amial_colors.dart';
import 'package:amial_pay/theme/amial_spacing.dart';
import 'package:amial_pay/util/images.dart';

/// AMIAL-MERCHANT-NAV-001
///
/// غلاف واجهة التاجر المعتمَد: لوحة القطاع تبقى متخصصة، بينما القائمة الجانبية
/// موحّدة ومختصرة. محتوى المراكز الداخلية يأتي من manifest الاستحقاقات، لذلك
/// تختلف Free/Starter/Business/Merchant Pro/Enterprise بلا خمس قوائم Dart.
class MerchantAdaptiveShell extends StatelessWidget {
  const MerchantAdaptiveShell({
    super.key,
    required this.child,
  });

  final Widget child;

  @override
  Widget build(BuildContext context) {
    return Directionality(
      textDirection: TextDirection.rtl,
      child: Scaffold(
        backgroundColor: AmialColors.background,
        drawer: const MerchantAdaptiveDrawer(),
        body: Stack(
          children: [
            Positioned.fill(child: child),
            SafeArea(
              child: Align(
                alignment: Alignment.topLeft,
                child: Padding(
                  padding: const EdgeInsets.all(AmialSpacing.sm),
                  child: Builder(
                    builder: (buttonContext) => Material(
                      color: AmialColors.primary,
                      shape: const CircleBorder(),
                      elevation: 2,
                      child: IconButton(
                        tooltip: 'القائمة',
                        onPressed: () => Scaffold.of(buttonContext).openDrawer(),
                        icon: const Icon(
                          Icons.menu_rounded,
                          color: AmialColors.cardSurface,
                        ),
                      ),
                    ),
                  ),
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class MerchantAdaptiveDrawer extends StatelessWidget {
  const MerchantAdaptiveDrawer({super.key});

  AccessController get access => Get.find<AccessController>();

  String _planLabel() {
    final server = access.subscriptionPlanLabel.value?.trim();
    if (server != null && server.isNotEmpty) return server;
    switch (access.subscriptionPlan.value) {
      case 'business':
        return 'الأعمال';
      case 'enterprise':
        return 'مؤسسة';
      default:
        return 'مجاني';
    }
  }

  List<String> _verticalGroups() {
    switch (access.businessType.value) {
      case 'fuel':
        return const ['الوقود'];
      case 'pharmacy':
        return const ['الصيدلية'];
      case 'wholesale':
        return const ['الجملة'];
      case 'restaurant':
        return const ['المطاعم'];
      case 'quick_sale':
        return const ['البيع'];
      case 'retail':
        return const ['البيع', 'الأصناف', 'المخزون'];
      default:
        return const [];
    }
  }

  String _expiryText() {
    final raw = access.subscriptionExpiresAt.value?.trim() ?? '';
    if (raw.isEmpty) return '';
    return raw.split('T').first;
  }

  @override
  Widget build(BuildContext context) {
    return Drawer(
      backgroundColor: AmialColors.cardSurface,
      width: MediaQuery.sizeOf(context).width * 0.86,
      child: SafeArea(
        child: Obx(() {
          final expiry = _expiryText();
          final verticalGroups = _verticalGroups();
          final business = access.businessTypeLabel.value?.trim();
          final businessLabel =
              business == null || business.isEmpty ? 'نشاط تجاري' : business;

          return Column(
            children: [
              _header(context, businessLabel, expiry),
              Expanded(
                child: ListView(
                  padding: const EdgeInsets.fromLTRB(
                    AmialSpacing.sm,
                    AmialSpacing.md,
                    AmialSpacing.sm,
                    AmialSpacing.xl,
                  ),
                  children: [
                    _item(
                      context,
                      icon: Icons.home_rounded,
                      label: 'الرئيسية',
                      selected: true,
                      onTap: () => Navigator.of(context).pop(),
                    ),
                    ..._activityItems(context, businessLabel, verticalGroups),
                    const Padding(
                      padding: EdgeInsets.symmetric(vertical: AmialSpacing.xs),
                      child: Divider(color: AmialColors.border),
                    ),
                    _highlightItem(
                      context,
                      icon: Icons.diamond_outlined,
                      label: 'مزايا الباقة',
                      onTap: () => _open(context, const MyCapabilitiesScreen()),
                    ),
                    _highlightItem(
                      context,
                      icon: Icons.upgrade_rounded,
                      label: 'الترقية',
                      onTap: () => _open(
                        context,
                        PlansCatalogScreen(
                          suggestedPlan: access.isEnterprisePlan
                              ? null
                              : _nextPlan(access.subscriptionPlan.value),
                        ),
                      ),
                    ),
                    const Padding(
                      padding: EdgeInsets.symmetric(vertical: AmialSpacing.xs),
                      child: Divider(color: AmialColors.border),
                    ),
                    _item(
                      context,
                      icon: Icons.settings_outlined,
                      label: 'الإعدادات',
                      onTap: () => _open(context, const ProfileScreen()),
                    ),
                    _item(
                      context,
                      icon: Icons.support_agent_outlined,
                      label: 'الدعم',
                      onTap: () => _open(context, const SupportScreen()),
                    ),
                    _item(
                      context,
                      icon: Icons.logout_rounded,
                      label: 'تسجيل الخروج',
                      danger: true,
                      onTap: () => _confirmLogout(context),
                    ),
                  ],
                ),
              ),
            ],
          );
        }),
      ),
    );
  }

  /// محطّة الوقود ليست متجراً عاماً. القائمة العامة كانت تقود «البيع» إلى
  /// مجموعة entitlement اسمها «البيع» (الفارغة لحساب الوقود) وتعرض مسارات
  /// مخزون وعملاء لا تخصّ محطة الوقود. لهذه المحطة مسارات تشغيل حقيقية،
  /// لذلك نعرضها مباشرةً بدلاً من مركز عام لا يطابق قطاعها.
  List<Widget> _activityItems(
    BuildContext context,
    String businessLabel,
    List<String> verticalGroups,
  ) {
    if (access.businessType.value == 'fuel') {
      return [
        _item(
          context,
          icon: Icons.local_gas_station_rounded,
          label: 'بيع الوقود',
          onTap: () => _open(context, const FuelSaleScreen()),
        ),
        _item(
          context,
          icon: Icons.dashboard_rounded,
          label: 'تشغيل المضخات والخزانات',
          onTap: () => _open(context, const FuelOpsCenterScreen()),
        ),
        _item(
          context,
          icon: Icons.propane_tank_outlined,
          label: 'الخزانات والقياسات',
          onTap: () => _open(context, const FuelTanksScreen()),
        ),
        _item(
          context,
          icon: Icons.local_shipping_outlined,
          label: 'توريدات الوقود',
          onTap: () => _open(context, const FuelDeliveriesScreen()),
        ),
        _item(
          context,
          icon: Icons.access_time_rounded,
          label: 'الورديات وسجل البيع',
          onTap: () => _open(context, const FuelShiftsScreen()),
        ),
        _item(
          context,
          icon: Icons.price_change_outlined,
          label: 'أسعار الوقود',
          onTap: () => _open(context, const FuelPricesScreen()),
        ),
        _item(
          context,
          icon: Icons.business_outlined,
          label: 'حسابات الشركات والبطاقات',
          onTap: () => _open(context, const FuelCompaniesScreen()),
        ),
        _item(
          context,
          icon: Icons.receipt_long_outlined,
          label: 'فواتير ومبيعات الوقود',
          onTap: () => _open(context, const FuelSalesHistoryScreen()),
        ),
        _item(
          context,
          icon: Icons.manage_accounts_outlined,
          label: 'فريق المحطة وصلاحياته',
          onTap: () => _open(context, const FuelRolesScreen()),
        ),
        _item(
          context,
          icon: Icons.settings_outlined,
          label: 'إعدادات المحطة',
          onTap: () => _open(context, const FuelSettingsScreen()),
        ),
      ];
    }

    return [
      _item(
        context,
        icon: Icons.point_of_sale_outlined,
        label: 'البيع',
        onTap: () => _openHub(
          context,
          title: 'البيع',
          subtitle: 'عمليات البيع والتحصيل والمرتجعات حسب باقتك.',
          groups: const ['البيع'],
          icon: Icons.point_of_sale_outlined,
        ),
      ),
      _item(
        context,
        icon: Icons.inventory_2_outlined,
        label: 'المنتجات والمخزون',
        onTap: () => _openHub(
          context,
          title: 'المنتجات والمخزون',
          subtitle:
              'الأصناف والباركود والمخزون والموردون والمواقع — يظهر المتاح والمقفل بسببه الحقيقي.',
          groups: const ['الأصناف', 'المخزون'],
          icon: Icons.inventory_2_outlined,
        ),
      ),
      _item(
        context,
        icon: Icons.groups_2_outlined,
        label: 'العملاء والفريق',
        onTap: () => _openHub(
          context,
          title: 'العملاء والفريق',
          subtitle:
              'العملاء والموظفون والورديات والأدوار والأجهزة حسب صلاحيات الحساب وباقته.',
          groups: const ['الناس'],
          icon: Icons.groups_2_outlined,
        ),
      ),
      _item(
        context,
        icon: Icons.analytics_outlined,
        label: 'التقارير والمالية',
        onTap: () => _openHub(
          context,
          title: 'التقارير والمالية',
          subtitle:
              'التقارير والمصروفات والتدقيق والعملات والنسخ الاحتياطي بلا خلط بين غير المتاح والصفر.',
          groups: const ['التقارير'],
          icon: Icons.analytics_outlined,
        ),
      ),
      _item(
        context,
        icon: Icons.apps_rounded,
        label: 'خدمات نشاطي',
        onTap: verticalGroups.isEmpty
            ? () => _open(context, const MyCapabilitiesScreen())
            : () => _openHub(
                  context,
                  title: 'خدمات $businessLabel',
                  subtitle:
                      'تظهر هنا خدمات نشاطك فقط؛ الباقة تحدد عمق المزايا ولا تغيّر نوع النشاط.',
                  groups: verticalGroups,
                  icon: _businessIcon(),
                ),
      ),
    ];
  }

  Widget _header(BuildContext context, String businessLabel, String expiry) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(AmialSpacing.lg),
      decoration: const BoxDecoration(
        gradient: LinearGradient(
          colors: [AmialColors.primaryDark, AmialColors.primary],
          begin: Alignment.topRight,
          end: Alignment.bottomLeft,
        ),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Container(
                width: AmialSpacing.xxl * 2,
                height: AmialSpacing.xxl * 2,
                padding: const EdgeInsets.all(AmialSpacing.xs),
                decoration: BoxDecoration(
                  color: AmialColors.cardSurface,
                  borderRadius: BorderRadius.circular(AmialSpacing.radiusLg),
                ),
                child: Image.asset(Images.logo, fit: BoxFit.contain),
              ),
              const SizedBox(width: AmialSpacing.md),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      access.businessName,
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                      style: Theme.of(context).textTheme.titleLarge?.copyWith(
                            color: AmialColors.cardSurface,
                            fontWeight: FontWeight.w900,
                          ),
                    ),
                    const SizedBox(height: AmialSpacing.xxs),
                    Text(
                      businessLabel,
                      style: Theme.of(context).textTheme.bodySmall?.copyWith(
                            color: AmialColors.cardSurface.withValues(alpha: 0.80),
                          ),
                    ),
                  ],
                ),
              ),
            ],
          ),
          const SizedBox(height: AmialSpacing.md),
          Row(
            children: [
              Container(
                padding: const EdgeInsets.symmetric(
                  horizontal: AmialSpacing.sm,
                  vertical: AmialSpacing.xs,
                ),
                decoration: BoxDecoration(
                  color: AmialColors.warningSurface,
                  borderRadius: BorderRadius.circular(AmialSpacing.radiusXl),
                ),
                child: Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    const Icon(
                      Icons.workspace_premium_outlined,
                      color: AmialColors.warning,
                      size: AmialSpacing.lg,
                    ),
                    const SizedBox(width: AmialSpacing.xxs),
                    Text(
                      _planLabel(),
                      style: Theme.of(context).textTheme.labelLarge?.copyWith(
                            color: AmialColors.warning,
                            fontWeight: FontWeight.w900,
                          ),
                    ),
                  ],
                ),
              ),
              if (expiry.isNotEmpty) ...[
                const SizedBox(width: AmialSpacing.sm),
                Expanded(
                  child: Text(
                    'التجديد: $expiry',
                    textAlign: TextAlign.end,
                    style: Theme.of(context).textTheme.bodySmall?.copyWith(
                          color: AmialColors.cardSurface.withValues(alpha: 0.76),
                        ),
                  ),
                ),
              ],
            ],
          ),
        ],
      ),
    );
  }

  Widget _item(
    BuildContext context, {
    required IconData icon,
    required String label,
    required VoidCallback onTap,
    bool selected = false,
    bool danger = false,
  }) {
    final color = danger
        ? AmialColors.danger
        : selected
            ? AmialColors.primary
            : AmialColors.textPrimary;

    return Padding(
      padding: const EdgeInsets.only(bottom: AmialSpacing.xxs),
      child: ListTile(
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(AmialSpacing.radiusMd),
        ),
        tileColor: selected
            ? AmialColors.primary.withValues(alpha: 0.07)
            : AmialColors.cardSurface,
        leading: Icon(icon, color: color),
        title: Text(
          label,
          style: Theme.of(context).textTheme.bodyLarge?.copyWith(
                color: color,
                fontWeight: selected ? FontWeight.w800 : FontWeight.w600,
              ),
        ),
        trailing: danger
            ? null
            : const Icon(
                Icons.chevron_left_rounded,
                color: AmialColors.textMuted,
              ),
        onTap: onTap,
      ),
    );
  }

  Widget _highlightItem(
    BuildContext context, {
    required IconData icon,
    required String label,
    required VoidCallback onTap,
  }) {
    return Padding(
      padding: const EdgeInsets.only(bottom: AmialSpacing.xs),
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(AmialSpacing.radiusLg),
        child: Container(
          padding: const EdgeInsets.symmetric(
            horizontal: AmialSpacing.md,
            vertical: AmialSpacing.sm,
          ),
          decoration: BoxDecoration(
            color: AmialColors.warningSurface,
            borderRadius: BorderRadius.circular(AmialSpacing.radiusLg),
            border: Border.all(
              color: AmialColors.yellow.withValues(alpha: 0.55),
            ),
          ),
          child: Row(
            children: [
              Icon(icon, color: AmialColors.warning),
              const SizedBox(width: AmialSpacing.sm),
              Expanded(
                child: Text(
                  label,
                  style: Theme.of(context).textTheme.bodyLarge?.copyWith(
                        color: AmialColors.textPrimary,
                        fontWeight: FontWeight.w800,
                      ),
                ),
              ),
              const Icon(
                Icons.chevron_left_rounded,
                color: AmialColors.warning,
              ),
            ],
          ),
        ),
      ),
    );
  }

  IconData _businessIcon() {
    switch (access.businessType.value) {
      case 'fuel':
        return Icons.local_gas_station_outlined;
      case 'pharmacy':
        return Icons.local_pharmacy_outlined;
      case 'wholesale':
        return Icons.inventory_outlined;
      case 'restaurant':
        return Icons.restaurant_outlined;
      case 'quick_sale':
        return Icons.bolt_outlined;
      default:
        return Icons.storefront_outlined;
    }
  }

  String? _nextPlan(String current) {
    switch (current) {
      case 'free':
        return 'business';
      case 'business':
        return 'enterprise';
      default:
        return null;
    }
  }

  void _openHub(
    BuildContext context, {
    required String title,
    required String subtitle,
    required List<String> groups,
    required IconData icon,
  }) {
    Navigator.of(context).pop();
    Get.to(() => MerchantCapabilityHubScreen(
          title: title,
          subtitle: subtitle,
          groups: groups,
          icon: icon,
        ));
  }

  void _open(BuildContext context, Widget screen) {
    Navigator.of(context).pop();
    Get.to(() => screen);
  }

  Future<void> _confirmLogout(BuildContext context) async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        title: const Text('تسجيل الخروج'),
        content: const Text('هل تريد تسجيل الخروج من حساب التاجر على هذا الجهاز؟'),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(dialogContext).pop(false),
            child: const Text('إلغاء'),
          ),
          FilledButton(
            onPressed: () => Navigator.of(dialogContext).pop(true),
            style: FilledButton.styleFrom(
              backgroundColor: AmialColors.danger,
              foregroundColor: AmialColors.cardSurface,
            ),
            child: const Text('تسجيل الخروج'),
          ),
        ],
      ),
    );

    if (confirmed != true) return;
    if (context.mounted) Navigator.of(context).pop();

    final response = await Get.find<AuthController>().logout();
    if (response.statusCode == 200) {
      access.reset();
    }
  }
}
