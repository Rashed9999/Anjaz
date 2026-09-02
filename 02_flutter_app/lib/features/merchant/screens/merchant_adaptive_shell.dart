import 'package:flutter/material.dart';
import 'package:get/get.dart';

import 'package:amial_pay/features/access/controllers/access_controller.dart';
import 'package:amial_pay/features/auth/controllers/auth_controller.dart';
import 'package:amial_pay/features/entitlements/screens/my_capabilities_screen.dart';
import 'package:amial_pay/features/merchant/screens/merchant_capability_hub_screen.dart';
import 'package:amial_pay/features/plans/screens/plans_catalog_screen.dart';
import 'package:amial_pay/features/merchant/screens/merchant_account_screen.dart';
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
import 'package:amial_pay/features/merchant/screens/merchant_wallet_screen.dart';
import 'package:amial_pay/features/fuel_station/screens/fuel_cashier_screen.dart';

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
                    ..._activityItems(context),
                    const Padding(
                      padding: EdgeInsets.symmetric(vertical: AmialSpacing.xs),
                      child: Divider(color: AmialColors.border),
                    ),
                    // ══════════════════════════════════════════════════
                    // **بابُ المال في الدرج — وهو ما يُفتَح من كلّ شاشة.**
                    //
                    // المحفظةُ كانت مبنيّةً **بلا مدخلٍ واحد**: لا لوحةٌ
                    // ولا درجٌ ولا خدماتٌ ولا رئيسيّة. وشاشةٌ لا يُوصل
                    // إليها ليست مبنيّة.
                    // ══════════════════════════════════════════════════
                    _highlightItem(
                      context,
                      icon: Icons.account_balance_wallet_rounded,
                      label: 'محفظة المتجر',
                      onTap: () => _open(context, const MerchantWalletScreen()),
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
                      label: 'إعدادات المنشأة',
                      onTap: () => _open(context, const MerchantAccountScreen()),
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

  /// AMIAL-MERCHANT-NAV-002
  ///
  /// لا توجد «قائمة متجر» واحدة تُعاد تسميتها لكل الأنشطة. البيع السريع
  /// لا يدير طاولات، والجملة لا تدير مضخات، والصيدلية تحتاج وصفات وصلاحية.
  /// الباقة تحدد عمق كل قسم، بينما هذا الـ manifest يحدد الأقسام المنطبقة
  /// على نوع المنشأة. والبطاقات داخل المركز تبقى مربوطة بحالة الاستحقاق
  /// الفعلية (متاح / صلاحية / حد / باقة) ولا يُستبدل ذلك بواجهة فقط.
  List<Widget> _activityItems(BuildContext context) {
    if (access.businessType.value == 'fuel') {
      return [
        _item(
          context,
          icon: Icons.local_gas_station_rounded,
          label: 'بيع الوقود',
          onTap: () => _open(context, const FuelSaleScreen()),
        ),
        // **ولوحةُ الأرقام بابٌ مستقلّ.** مبنيّةٌ كاملةً ولا يُوصل إليها
        // من الدرج — فتُصرَّف ولا تُفتح، ولا يقول ذلك مُصرِّفٌ ولا محلِّل.
        // وهي ما يستعمله الكاشيرُ حين تكون المضخّةُ غيرَ موصولة.
        _item(
          context,
          icon: Icons.dialpad_rounded,
          label: 'لوحة الأرقام',
          onTap: () => _open(context, const FuelCashierScreen()),
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

    return _sectionsForBusiness().map((section) {
      return _item(
        context,
        icon: section.icon,
        label: section.title,
        onTap: () => _openHub(
          context,
          title: section.title,
          subtitle: section.subtitle,
          groups: section.groups,
          icon: section.icon,
          codes: section.codes,
        ),
      );
    }).toList();
  }

  List<_MerchantDrawerSection> _sectionsForBusiness() {
    const sale = _MerchantDrawerSection(
      title: 'البيع والتحصيل',
      subtitle: 'البيع والتحصيل والمرتجعات والبيع الآجل بحسب استحقاق حسابك.',
      groups: ['البيع'],
      icon: Icons.point_of_sale_outlined,
    );
    const people = _MerchantDrawerSection(
      title: 'العملاء والفريق',
      subtitle: 'العملاء والموظفون والأدوار والأجهزة والورديات وفق صلاحياتك.',
      groups: ['الناس'],
      icon: Icons.groups_2_outlined,
    );
    const reports = _MerchantDrawerSection(
      title: 'التقارير والمالية',
      subtitle: 'التقارير والمصروفات والتدقيق والنسخ الاحتياطي حسب الباقة والدور.',
      groups: ['التقارير'],
      icon: Icons.analytics_outlined,
    );

    switch (access.businessType.value) {
      case 'pharmacy':
        return [
          _MerchantDrawerSection(
            title: 'تشغيل الصيدلية',
            subtitle: 'البيع والوصفات والأدوية والدفعات وتواريخ الصلاحية والتنبيهات من نظام الصيدلية.',
            groups: ['الصيدلية'],
            icon: Icons.local_pharmacy_outlined,
          ),
          people,
          reports,
        ];
            // ══════════════════════════════════════════════════════════════
      // **قائمةُ الجملة كما يصفها دليلُها — سبعةُ أقسامٍ لا خمسة.**
      //
      // المرجعُ مستندُ صاحب المشروع «دليل تاجر الجملة» القسم ٥. ودُمجت
      // الأقسامُ لاحقاً إلى خمسةٍ فعادت الحالةُ التي رفضها الدليلُ نصّاً:
      // سقط «التسعير» (وهو ما يميّز الجملةَ عن التجزئة: سعرُ عميلٍ
      // وسعرُ كمّيّةٍ وسعرُ شركة)، ودُمج «العملاء والديون» مع «الفريق
      // والأجهزة»، **وعاد قسمُ «البيع والتحصيل» العامّ** — والدليلُ يجعل
      // بيعَ الجملة **فاتورةً لا سلّةَ كاشير**.
      //
      // **والأقسامُ تُعرَّف برموزها لا بمجموعاتها** حيث اقتضى الدليلُ
      // تفصيلاً أدقَّ ممّا تجمعه المجموعةُ الواحدة.
      // ══════════════════════════════════════════════════════════════
      case 'wholesale':
        return const [
          _MerchantDrawerSection(
            title: 'فواتير الجملة والتحصيل',
            subtitle: 'فواتير، حالة السداد، تحصيلات ومرتجعات.',
            groups: [],
            codes: [
              'wholesale_invoices',
              'wholesale_collections',
              'refunds',
              'offline_pos',
            ],
            icon: Icons.request_quote_outlined,
          ),
          _MerchantDrawerSection(
            title: 'العملاء والديون',
            subtitle: 'العملاء، كشف الحساب، آجال الاستحقاق.',
            groups: [],
            codes: [
              'customers',
              'debts',
              'corporate_accounts',
              'corporate_credit_limits',
            ],
            icon: Icons.groups_2_outlined,
          ),
          _MerchantDrawerSection(
            title: 'الأصناف ومخزون الجملة',
            subtitle: 'أصناف، باركود، مخزون، موردون وطلبات شراء.',
            groups: ['الأصناف', 'المخزون'],
            icon: Icons.inventory_2_outlined,
          ),
          // **والتسعيرُ قسمٌ قائمٌ بذاته** — كان مدفوناً داخل «إعدادات
          // الجملة»، وقدرتُه بلا شاشةٍ معلَنةٍ أصلاً فلا تُفتح من «مزايا
          // باقتي». وهو ما يميّز الجملةَ عن التجزئة: سعرُ عميلٍ وسعرُ
          // كمّيّةٍ وسعرُ شركة.
          _MerchantDrawerSection(
            title: 'التسعير',
            subtitle: 'قوائم أسعار وشرائح كمية وأسعار شركات.',
            groups: [],
            codes: ['wholesale_multi_pricing'],
            icon: Icons.price_change_outlined,
          ),
          _MerchantDrawerSection(
            title: 'التقارير والمالية',
            subtitle: 'المبيعات والربح والذمم والتدقيق.',
            groups: ['التقارير'],
            icon: Icons.analytics_outlined,
          ),
          _MerchantDrawerSection(
            title: 'الفريق والأجهزة',
            subtitle: 'الموظفون، الأدوار، أجهزة نقاط البيع، الفروع.',
            groups: [],
            codes: [
              'employees',
              'employee_permissions',
              'rbac',
              'multi_pos',
              'shift_close',
              'branches',
            ],
            icon: Icons.manage_accounts_outlined,
          ),
        ];
      case 'restaurant':
        return [
          _MerchantDrawerSection(
            title: 'الطلبات والطاولات',
            subtitle: 'الطاولات والطلبات وتشغيل المطعم فقط.',
            groups: ['المطاعم'],
            icon: Icons.restaurant_outlined,
          ),
          sale,
          _MerchantDrawerSection(
            title: 'الفريق والعملاء',
            subtitle: 'الفريق والعملاء والأدوار والأجهزة بحسب الصلاحية.',
            groups: ['الناس'],
            icon: Icons.groups_2_outlined,
          ),
          reports,
        ];
      case 'quick_sale':
        return [
          _MerchantDrawerSection(
            title: 'البيع السريع',
            subtitle: 'مبيعات سريعة وتحصلات ومرتجعات وبيع آجل عند توافره.',
            groups: ['البيع'],
            icon: Icons.bolt_outlined,
          ),
          people,
          _MerchantDrawerSection(
            title: 'الوردية والتقارير',
            subtitle: 'إقفال الوردية والتقارير والمالية المتاحة لحسابك.',
            groups: ['التقارير'],
            icon: Icons.receipt_long_outlined,
          ),
        ];
      case 'retail':
        return [
          _MerchantDrawerSection(
            title: 'نقطة البيع والمرتجعات',
            subtitle: 'الكاشير والبيع والمرتجعات والبيع الآجل.',
            groups: ['البيع'],
            icon: Icons.point_of_sale_outlined,
          ),
          _MerchantDrawerSection(
            title: 'الأصناف والباركود',
            subtitle: 'الأصناف والتصنيفات والباركود والأسعار والعروض.',
            groups: ['الأصناف'],
            icon: Icons.qr_code_scanner_outlined,
          ),
          _MerchantDrawerSection(
            title: 'المخزون والموردون',
            subtitle: 'المخزون والجرد والموردون وأوامر الشراء والتحويلات.',
            groups: ['المخزون'],
            icon: Icons.warehouse_outlined,
          ),
          people,
          reports,
        ];
      default:
        return [
          sale,
          _MerchantDrawerSection(
            title: 'المنتجات والمخزون',
            subtitle: 'الأصناف والباركود والمخزون والموردون والمواقع.',
            groups: ['الأصناف', 'المخزون'],
            icon: Icons.inventory_2_outlined,
          ),
          people,
          reports,
        ];
    }
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
    List<String>? codes,
  }) {
    Navigator.of(context).pop();
    Get.to(() => MerchantCapabilityHubScreen(
          title: title,
          subtitle: subtitle,
          groups: groups,
          icon: icon,
          codes: codes,
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

/// تعريفٌ صغير للقسم الظاهر في القائمة. لا يحمل صلاحيةً محلية: الحسم يبقى
/// في manifest الاستحقاقات والخادم عند فتح البطاقة أو تنفيذ الفعل.
class _MerchantDrawerSection {
  const _MerchantDrawerSection({
    required this.title,
    required this.subtitle,
    required this.groups,
    required this.icon,
    this.codes,
  });

  final String title;
  final String subtitle;
  final List<String> groups;
  final IconData icon;

  /// رموزُ قدراتٍ بعينها حين لا تعبّر المجموعةُ عن القسم — انظر
  /// `MerchantCapabilityHubScreen.codes`.
  ///
  /// **استُعيد بعد أن نُزع**، وبنزعه سقط قسمُ «التسعير» من قائمة الجملة
  /// وعادت خمسةً بعد أن كانت سبعةً بحسب دليلها.
  final List<String>? codes;
}
