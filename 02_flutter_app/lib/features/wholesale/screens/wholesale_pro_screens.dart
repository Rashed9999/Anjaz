import 'package:flutter/material.dart';
import 'package:get/get.dart';

import 'package:amial_pay/features/access/controllers/access_controller.dart';
import 'package:amial_pay/features/entitlements/controllers/entitlements_controller.dart';
import 'package:amial_pay/features/merchant/screens/merchant_pos_devices_screen.dart';
import 'package:amial_pay/features/merchant/screens/merchant_staff_screen.dart';
import 'package:amial_pay/features/payments/screens/amial_qr_collect_screen.dart';
import 'package:amial_pay/features/plans/screens/plans_catalog_screen.dart';
import 'package:amial_pay/features/wholesale/controllers/wholesale_controller.dart';
import 'package:amial_pay/theme/amial_colors.dart';
import 'package:amial_pay/theme/amial_spacing.dart';
import 'package:amial_pay/util/images.dart';

/// AMIAL-WHOLESALE-UI-002 — مساحة عمل الجملة الاحترافية المعتمدة.
///
/// الواجهة متخصصة للجملة، لكن فتح المزايا يأتي من manifest الاستحقاقات:
/// التوحيد في المحرك — التخصص في التطبيق.
class WholesaleProDashboardScreen extends StatefulWidget {
  const WholesaleProDashboardScreen({super.key});

  @override
  State<WholesaleProDashboardScreen> createState() =>
      _WholesaleProDashboardScreenState();
}

class _WholesaleProDashboardScreenState
    extends State<WholesaleProDashboardScreen> {
  WholesaleController get c => Get.find<WholesaleController>();

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _reload());
  }

  Future<void> _reload() async {
    await c.loadBusiness();
    await c.loadDashboard();
    try {
      await Get.find<AccessController>().load();
    } catch (_) {}
    try {
      final e = Get.find<EntitlementsController>();
      if (e.manifest.value == null && !e.isLoading.value) await e.load();
    } catch (_) {}
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(
        backgroundColor: AmialColors.background,
        elevation: 0,
        centerTitle: true,
        title: const Text('الجملة'),
      ),
      body: Obx(() {
        final state = _loadState(c);
        if (state != null && c.dashboardData.value == null) {
          return state;
        }
        final d = c.dashboardData.value;
        if (d == null) {
          return _SurfaceState(
            icon: Icons.query_stats_rounded,
            title: 'البيانات غير معروفة',
            message: 'لم تصل بيانات لوحة الجملة بعد.',
            onRetry: _reload,
          );
        }
        final b = c.business.value;
        return RefreshIndicator(
          onRefresh: _reload,
          color: AmialColors.primary,
          child: ListView(
            physics: const AlwaysScrollableScrollPhysics(),
            padding: const EdgeInsets.fromLTRB(
              AmialSpacing.screen,
              AmialSpacing.sm,
              AmialSpacing.screen,
              AmialSpacing.xxl,
            ),
            children: [
              _businessHero(context, b),
              const SizedBox(height: AmialSpacing.md),
              _todayGrid(context, d),
              if (_num(d['overdue_count']) > 0) ...[
                const SizedBox(height: AmialSpacing.md),
                _overdueBanner(context, d),
              ],
              const SizedBox(height: AmialSpacing.md),
              _receivablesCard(context, d),
              const SizedBox(height: AmialSpacing.md),
              _primaryAction(
                context,
                icon: Icons.receipt_long_rounded,
                title: 'فاتورة جديدة',
                subtitle: 'إنشاء فاتورة بيع جملة بسرعة',
                capability: 'wholesale_invoices',
                onOpen: () => Get.to(() => const WholesaleProInvoiceCreateScreen()),
              ),
              const SizedBox(height: AmialSpacing.lg),
              Text(
                'إدارة الجملة',
                style: Theme.of(context).textTheme.titleMedium?.copyWith(
                      fontWeight: FontWeight.w900,
                      color: AmialColors.textPrimary,
                    ),
              ),
              const SizedBox(height: AmialSpacing.sm),
              _actionGrid(context),
            ],
          ),
        );
      }),
    );
  }

  Widget _businessHero(BuildContext context, Map<String, dynamic>? b) {
    final access = Get.find<AccessController>();
    return Container(
      padding: const EdgeInsets.all(AmialSpacing.lg),
      decoration: BoxDecoration(
        gradient: const LinearGradient(
          colors: [AmialColors.primaryDark, AmialColors.primary],
          begin: Alignment.topRight,
          end: Alignment.bottomLeft,
        ),
        borderRadius: BorderRadius.circular(AmialSpacing.radiusXl),
        boxShadow: AmialSpacing.cardShadow,
      ),
      child: Row(
        children: [
          Container(
            width: 62,
            height: 62,
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
                  '${b?['business_name'] ?? 'متجر جملة'}',
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: Theme.of(context).textTheme.titleLarge?.copyWith(
                        color: AmialColors.cardSurface,
                        fontWeight: FontWeight.w900,
                      ),
                ),
                const SizedBox(height: AmialSpacing.xxs),
                Text(
                  'مرحباً بك في مساحة تجارة الجملة',
                  style: Theme.of(context).textTheme.bodySmall?.copyWith(
                        color: AmialColors.cardSurface.withValues(alpha: 0.82),
                      ),
                ),
                const SizedBox(height: AmialSpacing.xs),
                Container(
                  padding: const EdgeInsets.symmetric(
                    horizontal: AmialSpacing.sm,
                    vertical: AmialSpacing.xxs,
                  ),
                  decoration: BoxDecoration(
                    color: AmialColors.warningSurface,
                    borderRadius: BorderRadius.circular(AmialSpacing.radiusXl),
                  ),
                  child: Text(
                    access.subscriptionPlanLabel.value?.trim().isNotEmpty == true
                        ? access.subscriptionPlanLabel.value!
                        : _planLabel(access.subscriptionPlan.value),
                    style: const TextStyle(
                      color: AmialColors.warning,
                      fontWeight: FontWeight.w800,
                      fontSize: 11,
                    ),
                  ),
                ),
              ],
            ),
          ),
          Container(
            width: 48,
            height: 48,
            decoration: BoxDecoration(
              color: AmialColors.yellow,
              borderRadius: BorderRadius.circular(AmialSpacing.radiusMd),
            ),
            child: const Icon(Icons.warehouse_rounded,
                color: AmialColors.primaryDark),
          ),
        ],
      ),
    );
  }

  Widget _todayGrid(BuildContext context, Map<String, dynamic> d) {
    final today = d['today'] is Map ? d['today'] as Map : const {};
    return LayoutBuilder(builder: (_, constraints) {
      final width = (constraints.maxWidth - AmialSpacing.sm) / 2;
      return Wrap(
        spacing: AmialSpacing.sm,
        runSpacing: AmialSpacing.sm,
        children: [
          _MetricCard(
            width: width,
            icon: Icons.receipt_long_rounded,
            label: 'فواتير اليوم',
            value: '${today['invoices_count'] ?? '—'}',
            tone: AmialColors.info,
            surface: AmialColors.cardSurface,
          ),
          _MetricCard(
            width: width,
            icon: Icons.trending_up_rounded,
            label: 'مبيعات اليوم',
            value: '${_money(today['total_amount'])} ر.ي',
            tone: AmialColors.cash,
            surface: AmialColors.warningSurface,
          ),
          _MetricCard(
            width: width,
            icon: Icons.inventory_2_outlined,
            label: 'المنتجات',
            value: '${d['products_count'] ?? '—'}',
            tone: AmialColors.info,
            surface: AmialColors.cardSurface,
          ),
          _MetricCard(
            width: width,
            icon: Icons.groups_2_outlined,
            label: 'العملاء',
            value: '${d['customers_count'] ?? '—'}',
            tone: AmialColors.primary,
            surface: AmialColors.cardSurface,
          ),
        ],
      );
    });
  }

  Widget _overdueBanner(BuildContext context, Map<String, dynamic> d) {
    return InkWell(
      borderRadius: BorderRadius.circular(AmialSpacing.radiusLg),
      onTap: () => Get.to(() => const WholesaleProInvoicesScreen(
            initialFilter: 'overdue',
          )),
      child: Container(
        padding: const EdgeInsets.all(AmialSpacing.md),
        decoration: BoxDecoration(
          color: AmialColors.dangerSurface,
          borderRadius: BorderRadius.circular(AmialSpacing.radiusLg),
          border: Border.all(color: AmialColors.danger.withValues(alpha: 0.25)),
        ),
        child: Row(
          children: [
            const Icon(Icons.warning_amber_rounded,
                color: AmialColors.danger, size: 30),
            const SizedBox(width: AmialSpacing.sm),
            Expanded(
              child: Text(
                '${d['overdue_count']} فاتورة متأخرة بقيمة ${_money(d['overdue_amount'])} ر.ي',
                style: const TextStyle(
                  color: AmialColors.danger,
                  fontWeight: FontWeight.w800,
                ),
              ),
            ),
            const Icon(Icons.chevron_left_rounded, color: AmialColors.danger),
          ],
        ),
      ),
    );
  }

  Widget _receivablesCard(BuildContext context, Map<String, dynamic> d) {
    return InkWell(
      borderRadius: BorderRadius.circular(AmialSpacing.radiusLg),
      onTap: () => Get.to(() => const WholesaleProAgingScreen()),
      child: Container(
        padding: const EdgeInsets.all(AmialSpacing.lg),
        decoration: BoxDecoration(
          color: AmialColors.cardSurface,
          borderRadius: BorderRadius.circular(AmialSpacing.radiusLg),
          border: Border.all(color: AmialColors.border),
          boxShadow: AmialSpacing.cardShadow,
        ),
        child: Row(
          children: [
            Container(
              width: 52,
              height: 52,
              decoration: BoxDecoration(
                color: AmialColors.primary.withValues(alpha: 0.08),
                shape: BoxShape.circle,
              ),
              child: const Icon(Icons.account_balance_rounded,
                  color: AmialColors.primary),
            ),
            const SizedBox(width: AmialSpacing.md),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const Text('إجمالي المستحقات',
                      style: TextStyle(color: AmialColors.textSecondary)),
                  const SizedBox(height: AmialSpacing.xxs),
                  Text(
                    '${_money(d['total_receivable'])} ر.ي',
                    style: Theme.of(context).textTheme.headlineSmall?.copyWith(
                          color: AmialColors.primary,
                          fontWeight: FontWeight.w900,
                        ),
                  ),
                  Text(
                    '${d['overdue_count'] ?? '—'} فاتورة متأخرة',
                    style: const TextStyle(
                        color: AmialColors.textMuted, fontSize: 11),
                  ),
                ],
              ),
            ),
            const Icon(Icons.chevron_left_rounded,
                color: AmialColors.textSecondary),
          ],
        ),
      ),
    );
  }

  Widget _primaryAction(
    BuildContext context, {
    required IconData icon,
    required String title,
    required String subtitle,
    required String capability,
    required VoidCallback onOpen,
  }) {
    return InkWell(
      borderRadius: BorderRadius.circular(AmialSpacing.radiusLg),
      onTap: () => _openCapability(context, capability, onOpen),
      child: Container(
        padding: const EdgeInsets.all(AmialSpacing.lg),
        decoration: BoxDecoration(
          gradient: const LinearGradient(
            colors: [AmialColors.primary, AmialColors.primaryLight],
          ),
          borderRadius: BorderRadius.circular(AmialSpacing.radiusLg),
          boxShadow: AmialSpacing.cardShadow,
        ),
        child: Row(
          children: [
            Container(
              width: 52,
              height: 52,
              decoration: BoxDecoration(
                color: AmialColors.cardSurface.withValues(alpha: 0.15),
                borderRadius: BorderRadius.circular(AmialSpacing.radiusMd),
              ),
              child: Icon(icon, color: AmialColors.cardSurface, size: 28),
            ),
            const SizedBox(width: AmialSpacing.md),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(title,
                      style: const TextStyle(
                          color: AmialColors.cardSurface,
                          fontSize: 18,
                          fontWeight: FontWeight.w900)),
                  Text(subtitle,
                      style: TextStyle(
                          color: AmialColors.cardSurface.withValues(alpha: 0.78),
                          fontSize: 12)),
                ],
              ),
            ),
            const Icon(Icons.add_circle_rounded,
                color: AmialColors.cardSurface, size: 32),
          ],
        ),
      ),
    );
  }

  Widget _actionGrid(BuildContext context) {
    final actions = <_WholesaleAction>[
      _WholesaleAction('العملاء', Icons.groups_2_outlined, 'customers',
          () => Get.to(() => const WholesaleProCustomersScreen())),
      _WholesaleAction('المنتجات', Icons.inventory_2_outlined, 'products',
          () => Get.to(() => const WholesaleProProductsScreen())),
      _WholesaleAction('الفواتير', Icons.receipt_long_outlined,
          'wholesale_invoices',
          () => Get.to(() => const WholesaleProInvoicesScreen())),
      _WholesaleAction('تقادم الديون', Icons.bar_chart_rounded,
          'advanced_reports', () => Get.to(() => const WholesaleProAgingScreen())),
      _WholesaleAction('أداء المندوبين', Icons.leaderboard_outlined,
          'advanced_reports', () => Get.to(() => const WholesaleProSalesRepsReportScreen())),
      _WholesaleAction('إدارة المندوبين', Icons.badge_outlined,
          'wholesale_invoices', () => Get.to(() => const WholesaleProSalesRepsScreen())),
      _WholesaleAction('الموظفون وصلاحياتهم', Icons.manage_accounts_outlined,
          'employees', () => Get.to(() => const MerchantStaffScreen())),
      _WholesaleAction('أجهزة نقاط البيع', Icons.point_of_sale_outlined,
          'multi_pos', () => Get.to(() => const MerchantPosDevicesScreen())),
      _WholesaleAction('المرتجعات', Icons.keyboard_return_rounded, 'wholesale_invoices',
          () => Get.to(() => const WholesaleProReturnsScreen())),
      _WholesaleAction('تنبيهات المخزون', Icons.notifications_active_outlined,
          'low_stock_alerts',
          () => Get.to(() => const WholesaleStockAlertsScreen())),
      _WholesaleAction('صلاحية المنتجات', Icons.event_busy_outlined, 'inventory',
          () => Get.to(() => const WholesaleExpiryAlertsScreen())),
      _WholesaleAction('أسعار الجملة', Icons.price_change_outlined,
          'wholesale_multi_pricing', () => _openMultiPricing(context)),
    ];

    return GridView.builder(
      shrinkWrap: true,
      physics: const NeverScrollableScrollPhysics(),
      itemCount: actions.length,
      gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
        crossAxisCount: 2,
        crossAxisSpacing: AmialSpacing.sm,
        mainAxisSpacing: AmialSpacing.sm,
        childAspectRatio: 1.55,
      ),
      itemBuilder: (_, i) {
        final a = actions[i];
        return _CapabilityTile(
          action: a,
          onTap: () => _openCapability(context, a.capability, a.onOpen),
        );
      },
    );
  }

  void _openMultiPricing(BuildContext context) {
    Get.to(() => const WholesaleProProductsScreen());
  }
}

class WholesaleProProductsScreen extends StatefulWidget {
  const WholesaleProProductsScreen({super.key});

  @override
  State<WholesaleProProductsScreen> createState() =>
      _WholesaleProProductsScreenState();
}

class _WholesaleProProductsScreenState extends State<WholesaleProProductsScreen> {
  final _search = TextEditingController();
  WholesaleController get c => Get.find<WholesaleController>();

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => c.loadProducts());
  }

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(
        backgroundColor: AmialColors.background,
        elevation: 0,
        centerTitle: true,
        title: const Text('المنتجات'),
      ),
      floatingActionButton: FloatingActionButton.extended(
        backgroundColor: AmialColors.primary,
        foregroundColor: AmialColors.cardSurface,
        onPressed: () => _productSheet(context),
        icon: const Icon(Icons.add_rounded),
        label: const Text('منتج جديد'),
      ),
      body: Obx(() {
        final state = _loadState(c, retry: () => c.loadProducts(search: _search.text));
        if (state != null && c.products.isEmpty) return state;
        return RefreshIndicator(
          onRefresh: () => c.loadProducts(search: _search.text),
          color: AmialColors.primary,
          child: ListView(
            physics: const AlwaysScrollableScrollPhysics(),
            padding: const EdgeInsets.fromLTRB(
              AmialSpacing.screen,
              AmialSpacing.sm,
              AmialSpacing.screen,
              104,
            ),
            children: [
              _SearchBar(
                controller: _search,
                hint: 'ابحث عن منتج أو باركود أو SKU',
                onSubmitted: (v) => c.loadProducts(search: v),
                onClear: () {
                  _search.clear();
                  c.loadProducts();
                },
              ),
              const SizedBox(height: AmialSpacing.md),
              ...c.products.map((p) => _productCard(context, p)),
              if (c.products.isEmpty)
                _SurfaceState(
                  icon: Icons.inventory_2_outlined,
                  title: 'لا توجد منتجات',
                  message: 'أضف أول منتج جملة ليظهر هنا.',
                  onRetry: () => c.loadProducts(),
                ),
            ],
          ),
        );
      }),
    );
  }

  Widget _productCard(BuildContext context, Map<String, dynamic> p) {
    final stock = _num(p['current_stock']);
    final threshold = _num(p['low_stock_threshold']);
    final low = stock <= threshold;
    return Container(
      margin: const EdgeInsets.only(bottom: AmialSpacing.sm),
      padding: const EdgeInsets.all(AmialSpacing.md),
      decoration: BoxDecoration(
        color: AmialColors.cardSurface,
        borderRadius: BorderRadius.circular(AmialSpacing.radiusLg),
        border: Border.all(color: low ? AmialColors.warning : AmialColors.border),
        boxShadow: AmialSpacing.cardShadow,
      ),
      child: Row(
        children: [
          Container(
            width: 58,
            height: 58,
            decoration: BoxDecoration(
              color: AmialColors.warningSurface,
              borderRadius: BorderRadius.circular(AmialSpacing.radiusMd),
            ),
            child: const Icon(Icons.inventory_2_rounded,
                color: AmialColors.cash),
          ),
          const SizedBox(width: AmialSpacing.md),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text('${p['name'] ?? '—'}',
                    style: const TextStyle(
                        fontWeight: FontWeight.w900,
                        color: AmialColors.textPrimary)),
                if ('${p['barcode'] ?? ''}'.isNotEmpty)
                  Text('${p['barcode']}',
                      style: const TextStyle(
                          color: AmialColors.textMuted, fontSize: 11)),
                const SizedBox(height: AmialSpacing.xs),
                Wrap(
                  spacing: AmialSpacing.sm,
                  runSpacing: AmialSpacing.xxs,
                  children: [
                    _miniLabel(Icons.inventory_outlined,
                        '${_qty(stock)} ${p['unit'] ?? 'وحدة'}',
                        low ? AmialColors.warning : AmialColors.success),
                    _miniLabel(Icons.sell_outlined,
                        '${_money(p['base_price'])} ر.ي', AmialColors.primary),
                  ],
                ),
              ],
            ),
          ),
          IconButton(
            tooltip: 'أسعار الجملة',
            onPressed: () => _openCapability(context, 'wholesale_multi_pricing',
                () => _pricingSheet(context, p)),
            icon: const Icon(Icons.price_change_outlined,
                color: AmialColors.cash),
          ),
          IconButton(
            tooltip: 'تعديل',
            onPressed: () => _productSheet(context, product: p),
            icon: const Icon(Icons.edit_outlined,
                color: AmialColors.primary),
          ),
        ],
      ),
    );
  }

  Future<void> _pricingSheet(BuildContext context, Map<String, dynamic> product) async {
    final data = await c.loadProductPrices((product['id'] as num).toInt());
    if (!context.mounted) return;
    if (data == null) {
      _snack(context, c.lastError.value, error: true);
      return;
    }
    final tiers = (data['tiers'] as List? ?? const [])
        .map((e) => Map<String, dynamic>.from(e as Map)).toList();
    final prices = (data['prices'] as List? ?? const [])
        .map((e) => Map<String, dynamic>.from(e as Map)).toList();
    if (tiers.isEmpty) {
      _snack(context, 'لا توجد شرائح أسعار متاحة', error: true);
      return;
    }
    int selectedTier = (tiers.first['id'] as num).toInt();
    final price = TextEditingController(text: '${product['base_price'] ?? ''}');
    final minimum = TextEditingController(text: '1');
    await showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      backgroundColor: AmialColors.cardSurface,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(AmialSpacing.radiusXl))),
      builder: (sheetContext) => StatefulBuilder(builder: (_, setSheet) => Padding(
        padding: EdgeInsets.fromLTRB(AmialSpacing.screen, AmialSpacing.lg, AmialSpacing.screen,
            MediaQuery.viewInsetsOf(sheetContext).bottom + AmialSpacing.lg),
        child: SingleChildScrollView(child: Column(mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Text('أسعار ${product['name']}', textAlign: TextAlign.center,
              style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w900)),
          const SizedBox(height: AmialSpacing.sm),
          ...prices.map((row) => ListTile(
            dense: true,
            leading: const Icon(Icons.sell_outlined, color: AmialColors.primary),
            title: Text('${(row['tier'] as Map?)?['name'] ?? 'شريحة'}'),
            subtitle: Text('من كمية ${_qty(_num(row['min_quantity']))}'),
            trailing: Text('${_money(row['price'])} ر.ي',
                style: const TextStyle(fontWeight: FontWeight.w900, color: AmialColors.primary)),
          )),
          const Divider(),
          DropdownButtonFormField<int>(value: selectedTier,
            decoration: const InputDecoration(labelText: 'شريحة السعر'),
            items: tiers.map((t) => DropdownMenuItem<int>(value: (t['id'] as num).toInt(),
                child: Text('${t['name']}'))).toList(),
            onChanged: (v) => setSheet(() => selectedTier = v ?? selectedTier)),
          const SizedBox(height: AmialSpacing.sm),
          _Field(price, 'السعر', Icons.payments_outlined, number: true),
          _Field(minimum, 'الحد الأدنى للكمية', Icons.numbers_rounded, number: true),
          Obx(() => FilledButton(
            onPressed: c.isSubmitting.value ? null : () async {
              final ok = await c.setProductPrice((product['id'] as num).toInt(), selectedTier,
                  _num(price.text), _num(minimum.text));
              if (!mounted || !sheetContext.mounted) return;
              if (ok) {
                Navigator.pop(sheetContext);
                _snack(context, 'تم حفظ سعر الشريحة');
              } else {
                _snack(context, c.lastError.value, error: true);
              }
            },
            child: const Text('حفظ سعر الشريحة'),
          )),
        ])),
      )),
    );
    price.dispose();
    minimum.dispose();
  }

  Future<void> _productSheet(BuildContext context,
      {Map<String, dynamic>? product}) async {
    final name = TextEditingController(text: '${product?['name'] ?? ''}');
    final sku = TextEditingController(text: '${product?['sku'] ?? ''}');
    final barcode = TextEditingController(text: '${product?['barcode'] ?? ''}');
    final manufacturer =
        TextEditingController(text: '${product?['manufacturer'] ?? ''}');
    final cost = TextEditingController(text: '${product?['cost_price'] ?? ''}');
    final price = TextEditingController(text: '${product?['base_price'] ?? ''}');
    final stock = TextEditingController(
        text: product == null ? '0' : '${product['current_stock'] ?? '0'}');
    final low = TextEditingController(
        text: '${product?['low_stock_threshold'] ?? 10}');
    final description =
        TextEditingController(text: '${product?['description'] ?? ''}');
    String unit = '${product?['unit'] ?? 'قطعة'}';
    const units = [
      'قطعة',
      'شدة',
      'درزن',
      'كرتون',
      'طبلية',
      'عبوة',
      'صندوق',
      'كيس',
      'كيلو',
      'لتر',
      'أخرى'
    ];
    if (!units.contains(unit)) unit = 'أخرى';

    await showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      backgroundColor: AmialColors.cardSurface,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(
          top: Radius.circular(AmialSpacing.radiusXl),
        ),
      ),
      builder: (sheetContext) => StatefulBuilder(
        builder: (_, setSheet) => Padding(
          padding: EdgeInsets.fromLTRB(
            AmialSpacing.screen,
            AmialSpacing.sm,
            AmialSpacing.screen,
            MediaQuery.viewInsetsOf(sheetContext).bottom + AmialSpacing.lg,
          ),
          child: SingleChildScrollView(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Center(
                  child: Container(
                    width: 44,
                    height: 4,
                    decoration: BoxDecoration(
                      color: AmialColors.border,
                      borderRadius: BorderRadius.circular(4),
                    ),
                  ),
                ),
                const SizedBox(height: AmialSpacing.md),
                Row(
                  children: [
                    IconButton(
                      onPressed: () => Navigator.pop(sheetContext),
                      icon: const Icon(Icons.close_rounded),
                    ),
                    const Spacer(),
                    Text(product == null ? 'منتج جديد' : 'تعديل المنتج',
                        style: Theme.of(context).textTheme.titleLarge?.copyWith(
                              fontWeight: FontWeight.w900,
                            )),
                    const Spacer(),
                    const Icon(Icons.add_business_rounded,
                        color: AmialColors.primary),
                  ],
                ),
                const SizedBox(height: AmialSpacing.md),
                _Field(name, 'الاسم *', Icons.sell_outlined),
                _Field(barcode, 'الباركود (اختياري)', Icons.qr_code_rounded),
                _Field(sku, 'SKU (اختياري)', Icons.tag_rounded),
                _Field(manufacturer, 'الشركة المصنعة (اختياري)',
                    Icons.factory_outlined),
                Row(
                  children: [
                    Expanded(
                      child: _Field(cost, 'سعر الشراء', Icons.payments_outlined,
                          number: true),
                    ),
                    const SizedBox(width: AmialSpacing.sm),
                    Expanded(
                      child: _Field(price, 'سعر البيع *', Icons.sell_rounded,
                          number: true),
                    ),
                  ],
                ),
                const SizedBox(height: AmialSpacing.xs),
                const Text('الوحدة الأساسية *',
                    style: TextStyle(fontWeight: FontWeight.w800)),
                const SizedBox(height: AmialSpacing.xs),
                Wrap(
                  spacing: AmialSpacing.xs,
                  runSpacing: AmialSpacing.xs,
                  children: units
                      .map((u) => ChoiceChip(
                            label: Text(u),
                            selected: unit == u,
                            selectedColor:
                                AmialColors.primary.withValues(alpha: 0.12),
                            side: BorderSide(
                                color: unit == u
                                    ? AmialColors.primary
                                    : AmialColors.border),
                            onSelected: (_) => setSheet(() => unit = u),
                          ))
                      .toList(),
                ),
                const SizedBox(height: AmialSpacing.sm),
                Text(
                  'الوحدة الأساسية هي التي سيُقاس بها مخزون هذا المنتج في النظام.',
                  style: Theme.of(context).textTheme.bodySmall?.copyWith(
                        color: AmialColors.textMuted,
                      ),
                ),
                const SizedBox(height: AmialSpacing.md),
                Row(
                  children: [
                    Expanded(
                      child: _Field(stock, 'المخزون الابتدائي',
                          Icons.inventory_outlined,
                          number: true,
                          enabled: product == null),
                    ),
                    const SizedBox(width: AmialSpacing.sm),
                    Expanded(
                      child: _Field(low, 'حد التنبيه قرب النفاد *',
                          Icons.notification_important_outlined,
                          number: true),
                    ),
                  ],
                ),
                Container(
                  padding: const EdgeInsets.all(AmialSpacing.sm),
                  decoration: BoxDecoration(
                    color: AmialColors.warningSurface,
                    borderRadius: BorderRadius.circular(AmialSpacing.radiusMd),
                  ),
                  child: const Row(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Icon(Icons.notifications_active_outlined,
                          color: AmialColors.warning),
                      SizedBox(width: AmialSpacing.xs),
                      Expanded(
                        child: Text(
                          'عند بلوغ المخزون هذا الحد سيظهر المنتج في «تنبيهات المخزون» إذا كانت الميزة متاحة في باقتك.',
                          style: TextStyle(
                              color: AmialColors.warning, fontSize: 12),
                        ),
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: AmialSpacing.md),
                _Field(description, 'الوصف (اختياري)', Icons.notes_rounded,
                    maxLines: 3),
                const SizedBox(height: AmialSpacing.md),
                Obx(() => FilledButton.icon(
                      style: FilledButton.styleFrom(
                        backgroundColor: AmialColors.primary,
                        foregroundColor: AmialColors.cardSurface,
                        minimumSize:
                            const Size.fromHeight(AmialSpacing.buttonHeight),
                      ),
                      onPressed: c.isSubmitting.value
                          ? null
                          : () async {
                              if (name.text.trim().isEmpty ||
                                  _num(price.text) <= 0) {
                                _snack(context,
                                    'أدخل اسم المنتج وسعر بيع أكبر من صفر.',
                                    error: true);
                                return;
                              }
                              final data = <String, dynamic>{
                                'name': name.text.trim(),
                                if (sku.text.trim().isNotEmpty)
                                  'sku': sku.text.trim(),
                                if (barcode.text.trim().isNotEmpty)
                                  'barcode': barcode.text.trim(),
                                if (manufacturer.text.trim().isNotEmpty)
                                  'manufacturer': manufacturer.text.trim(),
                                'unit': unit,
                                'base_price': price.text.trim(),
                                if (cost.text.trim().isNotEmpty)
                                  'cost_price': cost.text.trim(),
                                'low_stock_threshold':
                                    int.tryParse(low.text.trim()) ?? 10,
                                if (product == null)
                                  'initial_stock': stock.text.trim().isEmpty
                                      ? '0'
                                      : stock.text.trim(),
                                if (description.text.trim().isNotEmpty)
                                  'description': description.text.trim(),
                              };
                              final ok = product == null
                                  ? await c.addProduct(data)
                                  : await c.updateProduct(
                                      (product['id'] as num).toInt(), data);
                              // **وسياقٌ فرعيٌّ يُفحَص بنفسه.** `mounted` حالةُ الودجة، و`sheetContext` سياقُ ورقةٍ قد تُغلَق أثناء الانتظار — فـNavigator.pop عليه بعدها يرمي أو يُغلق الصفحةَ تحته.
                              if (!mounted || !sheetContext.mounted) return;
                              if (ok) {
                                Navigator.pop(sheetContext);
                                _snack(context,
                                    product == null
                                        ? 'تمت إضافة المنتج'
                                        : 'تم تحديث المنتج');
                              } else {
                                _snack(context, c.lastError.value, error: true);
                              }
                            },
                      icon: c.isSubmitting.value
                          ? const SizedBox(
                              width: 18,
                              height: 18,
                              child: CircularProgressIndicator(
                                  strokeWidth: 2,
                                  color: AmialColors.cardSurface),
                            )
                          : const Icon(Icons.save_outlined),
                      label: Text(product == null ? 'حفظ المنتج' : 'حفظ التعديل'),
                    )),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class WholesaleProCustomersScreen extends StatefulWidget {
  const WholesaleProCustomersScreen({super.key});

  @override
  State<WholesaleProCustomersScreen> createState() =>
      _WholesaleProCustomersScreenState();
}

class _WholesaleProCustomersScreenState extends State<WholesaleProCustomersScreen> {
  final _search = TextEditingController();
  bool _withBalance = false;
  WholesaleController get c => Get.find<WholesaleController>();

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => c.loadCustomers());
  }

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  Future<void> _load() => c.loadCustomers(
      search: _search.text.trim(), withBalanceOnly: _withBalance);

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(
        backgroundColor: AmialColors.background,
        elevation: 0,
        centerTitle: true,
        title: const Text('العملاء'),
      ),
      floatingActionButton: FloatingActionButton.extended(
        backgroundColor: AmialColors.primary,
        foregroundColor: AmialColors.cardSurface,
        onPressed: () => _customerSheet(context),
        icon: const Icon(Icons.add_rounded),
        label: const Text('عميل جديد'),
      ),
      body: Obx(() {
        final state = _loadState(c, retry: _load);
        if (state != null && c.customers.isEmpty) return state;
        return RefreshIndicator(
          onRefresh: _load,
          color: AmialColors.primary,
          child: ListView(
            physics: const AlwaysScrollableScrollPhysics(),
            padding: const EdgeInsets.fromLTRB(
              AmialSpacing.screen,
              AmialSpacing.sm,
              AmialSpacing.screen,
              104,
            ),
            children: [
              Row(
                children: [
                  Expanded(
                    child: _SearchBar(
                      controller: _search,
                      hint: 'ابحث باسم العميل أو رقم الهاتف',
                      onSubmitted: (_) => _load(),
                      onClear: () {
                        _search.clear();
                        _load();
                      },
                    ),
                  ),
                  const SizedBox(width: AmialSpacing.sm),
                  FilterChip(
                    label: const Text('عليه دين'),
                    selected: _withBalance,
                    selectedColor:
                        AmialColors.dangerSurface,
                    onSelected: (v) {
                      setState(() => _withBalance = v);
                      _load();
                    },
                  ),
                ],
              ),
              const SizedBox(height: AmialSpacing.md),
              ...c.customers.map((cust) => _customerCard(context, cust)),
              if (c.customers.isEmpty)
                _SurfaceState(
                  icon: Icons.groups_2_outlined,
                  title: 'لا يوجد عملاء',
                  message: 'أضف أول عميل جملة أو غيّر التصفية.',
                  onRetry: _load,
                ),
            ],
          ),
        );
      }),
    );
  }

  Widget _customerCard(BuildContext context, Map<String, dynamic> cust) {
    final balance = _num(cust['current_balance']);
    final limit = _num(cust['credit_limit']);
    return InkWell(
      borderRadius: BorderRadius.circular(AmialSpacing.radiusLg),
      onTap: () => Get.to(() => WholesaleProCustomerStatementScreen(
            customer: cust,
          )),
      child: Container(
        margin: const EdgeInsets.only(bottom: AmialSpacing.sm),
        padding: const EdgeInsets.all(AmialSpacing.md),
        decoration: BoxDecoration(
          color: AmialColors.cardSurface,
          borderRadius: BorderRadius.circular(AmialSpacing.radiusLg),
          border: Border.all(color: AmialColors.border),
          boxShadow: AmialSpacing.cardShadow,
        ),
        child: Row(
          children: [
            CircleAvatar(
              radius: 28,
              backgroundColor: AmialColors.primary.withValues(alpha: 0.08),
              child: const Icon(Icons.groups_2_outlined,
                  color: AmialColors.primary),
            ),
            const SizedBox(width: AmialSpacing.md),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text('${cust['full_name'] ?? '—'}',
                      style: const TextStyle(
                          fontWeight: FontWeight.w900,
                          color: AmialColors.textPrimary)),
                  if ('${cust['company_name'] ?? ''}'.isNotEmpty)
                    Text('${cust['company_name']}',
                        style: const TextStyle(
                            color: AmialColors.textSecondary, fontSize: 12)),
                  if ('${cust['phone'] ?? ''}'.isNotEmpty)
                    Text('${cust['phone']}',
                        style: const TextStyle(
                            color: AmialColors.textMuted, fontSize: 11)),
                  const SizedBox(height: AmialSpacing.xs),
                  Wrap(
                    spacing: AmialSpacing.sm,
                    children: [
                      if (balance > 0)
                        _miniLabel(Icons.account_balance_wallet_outlined,
                            'عليه ${_money(balance)} ر.ي', AmialColors.danger),
                      _miniLabel(Icons.credit_score_outlined,
                          'حد ${_money(limit)} ر.ي', AmialColors.primary),
                    ],
                  ),
                ],
              ),
            ),
            IconButton(
              tooltip: 'تعديل',
              onPressed: () => _customerSheet(context, customer: cust),
              icon: const Icon(Icons.more_vert_rounded),
            ),
          ],
        ),
      ),
    );
  }

  Future<void> _customerSheet(BuildContext context,
      {Map<String, dynamic>? customer}) async {
    final name =
        TextEditingController(text: '${customer?['full_name'] ?? ''}');
    final company =
        TextEditingController(text: '${customer?['company_name'] ?? ''}');
    final phone = TextEditingController(text: '${customer?['phone'] ?? ''}');
    final credit =
        TextEditingController(text: '${customer?['credit_limit'] ?? 0}');
    final terms = TextEditingController(
        text: '${customer?['payment_terms_days'] ?? 30}');

    await showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      backgroundColor: AmialColors.cardSurface,
      shape: const RoundedRectangleBorder(
        borderRadius:
            BorderRadius.vertical(top: Radius.circular(AmialSpacing.radiusXl)),
      ),
      builder: (sheetContext) => Padding(
        padding: EdgeInsets.fromLTRB(
          AmialSpacing.screen,
          AmialSpacing.md,
          AmialSpacing.screen,
          MediaQuery.viewInsetsOf(sheetContext).bottom + AmialSpacing.lg,
        ),
        child: SingleChildScrollView(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Row(
                children: [
                  IconButton(
                      onPressed: () => Navigator.pop(sheetContext),
                      icon: const Icon(Icons.close_rounded)),
                  const Spacer(),
                  Text(customer == null ? 'عميل جديد' : 'تعديل العميل',
                      style: Theme.of(context).textTheme.titleLarge?.copyWith(
                            fontWeight: FontWeight.w900,
                          )),
                  const Spacer(),
                  const Icon(Icons.person_add_alt_1_rounded,
                      color: AmialColors.primary),
                ],
              ),
              const SizedBox(height: AmialSpacing.md),
              _Field(name, 'الاسم *', Icons.person_outline_rounded),
              _Field(company, 'اسم الشركة (اختياري)', Icons.business_outlined),
              _Field(phone, 'الهاتف', Icons.phone_outlined,
                  number: true),
              _Field(credit, 'حد الائتمان — 0 = نقد فقط',
                  Icons.credit_score_outlined,
                  number: true),
              _Field(terms, 'مهلة السداد (يوم)', Icons.calendar_month_outlined,
                  number: true),
              const SizedBox(height: AmialSpacing.md),
              Obx(() => FilledButton.icon(
                    style: FilledButton.styleFrom(
                      backgroundColor: AmialColors.primary,
                      foregroundColor: AmialColors.cardSurface,
                      minimumSize:
                          const Size.fromHeight(AmialSpacing.buttonHeight),
                    ),
                    onPressed: c.isSubmitting.value
                        ? null
                        : () async {
                            if (name.text.trim().isEmpty) {
                              _snack(context, 'اسم العميل مطلوب', error: true);
                              return;
                            }
                            final data = <String, dynamic>{
                              'full_name': name.text.trim(),
                              if (company.text.trim().isNotEmpty)
                                'company_name': company.text.trim(),
                              if (phone.text.trim().isNotEmpty)
                                'phone': phone.text.trim(),
                              'credit_limit': credit.text.trim().isEmpty
                                  ? '0'
                                  : credit.text.trim(),
                              'payment_terms_days':
                                  int.tryParse(terms.text.trim()) ?? 30,
                            };
                            final ok = customer == null
                                ? await c.addCustomer(data)
                                : await c.updateCustomer(
                                    (customer['id'] as num).toInt(), data);
                            // **وسياقٌ فرعيٌّ يُفحَص بنفسه.** `mounted` حالةُ الودجة، و`sheetContext` سياقُ ورقةٍ قد تُغلَق أثناء الانتظار — فـNavigator.pop عليه بعدها يرمي أو يُغلق الصفحةَ تحته.
                            if (!mounted || !sheetContext.mounted) return;
                            if (ok) {
                              Navigator.pop(sheetContext);
                              _snack(context,
                                  customer == null
                                      ? 'تمت إضافة العميل'
                                      : 'تم تحديث العميل');
                            } else {
                              _snack(context, c.lastError.value, error: true);
                            }
                          },
                    icon: const Icon(Icons.save_outlined),
                    label: Text(customer == null ? 'إضافة' : 'حفظ'),
                  )),
            ],
          ),
        ),
      ),
    );
  }
}

class WholesaleProInvoicesScreen extends StatefulWidget {
  const WholesaleProInvoicesScreen({super.key, this.initialFilter = 'all'});
  final String initialFilter;

  @override
  State<WholesaleProInvoicesScreen> createState() =>
      _WholesaleProInvoicesScreenState();
}

class _WholesaleProInvoicesScreenState extends State<WholesaleProInvoicesScreen> {
  WholesaleController get c => Get.find<WholesaleController>();
  final _search = TextEditingController();
  late String _filter;

  @override
  void initState() {
    super.initState();
    _filter = widget.initialFilter;
    WidgetsBinding.instance.addPostFrameCallback((_) => _load());
  }

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  Future<void> _load() {
    if (_filter == 'overdue') return c.loadInvoices(overdueOnly: true);
    if (_filter == 'all') return c.loadInvoices();
    return c.loadInvoices(status: _filter);
  }

  List<Map<String, dynamic>> get _visible {
    final q = _search.text.trim().toLowerCase();
    if (q.isEmpty) return c.invoices.toList();
    return c.invoices.where((inv) {
      final cust = inv['customer'] is Map ? inv['customer'] as Map : const {};
      return '${inv['invoice_number'] ?? ''}'.toLowerCase().contains(q) ||
          '${cust['full_name'] ?? ''}'.toLowerCase().contains(q);
    }).toList();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(
        backgroundColor: AmialColors.background,
        elevation: 0,
        centerTitle: true,
        title: const Text('الفواتير'),
      ),
      floatingActionButton: FloatingActionButton.extended(
        backgroundColor: AmialColors.primary,
        foregroundColor: AmialColors.cardSurface,
        onPressed: () => Get.to(() => const WholesaleProInvoiceCreateScreen()),
        icon: const Icon(Icons.add_rounded),
        label: const Text('فاتورة جديدة'),
      ),
      body: Obx(() {
        final state = _loadState(c, retry: _load);
        if (state != null && c.invoices.isEmpty) return state;
        final rows = _visible;
        return RefreshIndicator(
          onRefresh: _load,
          color: AmialColors.primary,
          child: ListView(
            physics: const AlwaysScrollableScrollPhysics(),
            padding: const EdgeInsets.fromLTRB(
              AmialSpacing.screen,
              AmialSpacing.sm,
              AmialSpacing.screen,
              104,
            ),
            children: [
              _SearchBar(
                controller: _search,
                hint: 'ابحث برقم الفاتورة أو اسم العميل',
                onSubmitted: (_) => setState(() {}),
                onClear: () => setState(() => _search.clear()),
              ),
              const SizedBox(height: AmialSpacing.sm),
              SingleChildScrollView(
                scrollDirection: Axis.horizontal,
                child: Row(
                  children: [
                    _filterChip('الكل', 'all'),
                    _filterChip('مدفوعة', 'paid'),
                    _filterChip('جزئية', 'partial_paid'),
                    _filterChip('قيد السداد', 'issued'),
                    _filterChip('متأخرة', 'overdue'),
                  ],
                ),
              ),
              const SizedBox(height: AmialSpacing.md),
              ...rows.map((inv) => _invoiceCard(context, inv)),
              if (rows.isEmpty)
                const _SurfaceState(
                  icon: Icons.receipt_long_outlined,
                  title: 'لا توجد فواتير مطابقة',
                  message: 'غيّر البحث أو حالة الفاتورة.',
                ),
            ],
          ),
        );
      }),
    );
  }

  Widget _filterChip(String label, String code) {
    final selected = _filter == code;
    return Padding(
      padding: const EdgeInsets.only(left: AmialSpacing.xs),
      child: ChoiceChip(
        label: Text(label),
        selected: selected,
        selectedColor: AmialColors.primary,
        labelStyle: TextStyle(
          color: selected ? AmialColors.cardSurface : AmialColors.textPrimary,
          fontWeight: FontWeight.w700,
        ),
        onSelected: (_) {
          setState(() => _filter = code);
          _load();
        },
      ),
    );
  }

  Widget _invoiceCard(BuildContext context, Map<String, dynamic> inv) {
    final status = '${inv['status'] ?? ''}';
    final balance = _num(inv['balance_due']);
    final cust = inv['customer'] is Map ? inv['customer'] as Map : const {};
    final tone = _statusTone(status);
    return InkWell(
      borderRadius: BorderRadius.circular(AmialSpacing.radiusLg),
      onTap: () => Get.to(() => WholesaleProInvoiceDetailsScreen(
            invoiceId: (inv['id'] as num).toInt(),
          )),
      child: Container(
        margin: const EdgeInsets.only(bottom: AmialSpacing.sm),
        padding: const EdgeInsets.all(AmialSpacing.md),
        decoration: BoxDecoration(
          color: AmialColors.cardSurface,
          borderRadius: BorderRadius.circular(AmialSpacing.radiusLg),
          border: Border(right: BorderSide(color: tone, width: 4)),
          boxShadow: AmialSpacing.cardShadow,
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              children: [
                _statusPill(status),
                const Spacer(),
                const Icon(Icons.description_outlined,
                    color: AmialColors.primary),
                const SizedBox(width: AmialSpacing.xs),
                Text('${inv['invoice_number'] ?? '—'}',
                    style: const TextStyle(
                        fontWeight: FontWeight.w900,
                        color: AmialColors.textPrimary)),
              ],
            ),
            const SizedBox(height: AmialSpacing.sm),
            Text('${cust['full_name'] ?? '—'}',
                style: const TextStyle(color: AmialColors.textSecondary)),
            const SizedBox(height: AmialSpacing.xs),
            Row(
              children: [
                if (balance > 0)
                  Text('المتبقي ${_money(balance)} ر.ي',
                      style: const TextStyle(
                          color: AmialColors.danger,
                          fontWeight: FontWeight.w800)),
                const Spacer(),
                Text('${_money(inv['total_amount'])} ر.ي',
                    style: const TextStyle(
                        color: AmialColors.primary,
                        fontSize: 20,
                        fontWeight: FontWeight.w900)),
              ],
            ),
            if ('${inv['invoice_date'] ?? inv['created_at'] ?? ''}'.isNotEmpty)
              Padding(
                padding: const EdgeInsets.only(top: AmialSpacing.xs),
                child: Row(
                  children: [
                    const Icon(Icons.calendar_today_outlined,
                        size: 16, color: AmialColors.textMuted),
                    const SizedBox(width: AmialSpacing.xxs),
                    Text(
                      _date(inv['invoice_date'] ?? inv['created_at']),
                      style: const TextStyle(
                          color: AmialColors.textMuted, fontSize: 11),
                    ),
                  ],
                ),
              ),
          ],
        ),
      ),
    );
  }
}

class WholesaleProInvoiceDetailsScreen extends StatefulWidget {
  const WholesaleProInvoiceDetailsScreen({super.key, required this.invoiceId});
  final int invoiceId;

  @override
  State<WholesaleProInvoiceDetailsScreen> createState() =>
      _WholesaleProInvoiceDetailsScreenState();
}

class _WholesaleProInvoiceDetailsScreenState
    extends State<WholesaleProInvoiceDetailsScreen> {
  WholesaleController get c => Get.find<WholesaleController>();

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance
        .addPostFrameCallback((_) => c.loadInvoiceDetails(widget.invoiceId));
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(
        backgroundColor: AmialColors.background,
        elevation: 0,
        centerTitle: true,
        title: const Text('تفاصيل الفاتورة'),
        actions: [
          Obx(() => IconButton(
                tooltip: 'تحميل PDF',
                onPressed: c.isSubmitting.value
                    ? null
                    : () async {
                        final ok = await c.downloadInvoicePdf(widget.invoiceId);
                        // **والسياقُ المُلتقَط في الإغلاق يُفحَص بنفسه.**
                        // `mounted` حالةُ الودجة، وهذا `context` من باني
                        // `Obx` لا من الحالة — فقد يموت وهي حيّة.
                        if (!mounted || ok || !context.mounted) return;
                        _snack(context, c.lastError.value, error: true);
                      },
                icon: c.isSubmitting.value
                    ? const SizedBox(
                        width: 18,
                        height: 18,
                        child: CircularProgressIndicator(strokeWidth: 2),
                      )
                    : const Icon(Icons.picture_as_pdf_outlined),
              )),
        ],
      ),
      body: Obx(() {
        final state = _loadState(c,
            retry: () => c.loadInvoiceDetails(widget.invoiceId));
        final inv = c.currentInvoice.value;
        if (inv == null) {
          return state ??
              _SurfaceState(
                icon: Icons.receipt_long_outlined,
                title: 'الفاتورة غير متاحة',
                message: 'تعذر قراءة تفاصيل الفاتورة.',
                onRetry: () => c.loadInvoiceDetails(widget.invoiceId),
              );
        }
        final items = (inv['items'] ?? []) as List;
        final collections = (inv['collections'] ?? []) as List;
        final customer = inv['customer'] is Map ? inv['customer'] as Map : const {};
        final balance = _num(inv['balance_due']);
        return RefreshIndicator(
          onRefresh: () async => c.loadInvoiceDetails(widget.invoiceId),
          color: AmialColors.primary,
          child: ListView(
            physics: const AlwaysScrollableScrollPhysics(),
            padding: const EdgeInsets.fromLTRB(
              AmialSpacing.screen,
              AmialSpacing.sm,
              AmialSpacing.screen,
              AmialSpacing.xxl,
            ),
            children: [
              _invoiceHero(context, inv, customer),
              const SizedBox(height: AmialSpacing.md),
              _itemsCard(context, items),
              const SizedBox(height: AmialSpacing.md),
              _totalsCard(inv, balance),
              if (collections.isNotEmpty) ...[
                const SizedBox(height: AmialSpacing.md),
                _collectionsCard(collections),
              ],
              if (inv['status'] != 'voided') ...[
                const SizedBox(height: AmialSpacing.md),
                OutlinedButton.icon(
                  onPressed: items.isEmpty
                      ? null
                      : () => Get.to(() => WholesaleProReturnRequestScreen(invoice: inv)),
                  icon: const Icon(Icons.keyboard_return_rounded),
                  label: const Text('طلب مرتجع من هذه الفاتورة'),
                ),
              ],
              if (balance > 0 && inv['status'] != 'voided') ...[
                const SizedBox(height: AmialSpacing.lg),
                FilledButton.icon(
                  style: FilledButton.styleFrom(
                    backgroundColor: AmialColors.primary,
                    foregroundColor: AmialColors.cardSurface,
                    minimumSize:
                        const Size.fromHeight(AmialSpacing.buttonHeight),
                  ),
                  onPressed: () => _collectSheet(context, balance),
                  icon: const Icon(Icons.payments_outlined),
                  label: Text('تسجيل تحصيل ${_money(balance)} ر.ي'),
                ),
              ],
            ],
          ),
        );
      }),
    );
  }

  Widget _invoiceHero(
      BuildContext context, Map inv, Map customer) {
    return Container(
      padding: const EdgeInsets.all(AmialSpacing.lg),
      decoration: BoxDecoration(
        color: AmialColors.cardSurface,
        borderRadius: BorderRadius.circular(AmialSpacing.radiusLg),
        border: Border.all(color: AmialColors.border),
        boxShadow: AmialSpacing.cardShadow,
      ),
      child: Column(
        children: [
          Row(
            children: [
              _statusPill('${inv['status'] ?? ''}'),
              const Spacer(),
              Text('${inv['invoice_number'] ?? '—'}',
                  style: Theme.of(context).textTheme.titleLarge?.copyWith(
                        fontWeight: FontWeight.w900,
                      )),
            ],
          ),
          const SizedBox(height: AmialSpacing.md),
          Row(
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const Text('إجمالي الفاتورة',
                        style: TextStyle(color: AmialColors.textMuted)),
                    Text('${_money(inv['total_amount'])} ر.ي',
                        style: Theme.of(context).textTheme.headlineSmall?.copyWith(
                              color: AmialColors.primary,
                              fontWeight: FontWeight.w900,
                            )),
                  ],
                ),
              ),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.end,
                  children: [
                    Text('العميل: ${customer['full_name'] ?? '—'}',
                        style: const TextStyle(
                            color: AmialColors.textPrimary,
                            fontWeight: FontWeight.w700)),
                    const SizedBox(height: AmialSpacing.xs),
                    Text('الإصدار: ${_date(inv['invoice_date'])}',
                        style: const TextStyle(
                            color: AmialColors.textMuted, fontSize: 11)),
                    Text('الاستحقاق: ${_date(inv['due_date'])}',
                        style: const TextStyle(
                            color: AmialColors.textMuted, fontSize: 11)),
                  ],
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }

  Widget _itemsCard(BuildContext context, List items) {
    return Container(
      padding: const EdgeInsets.all(AmialSpacing.md),
      decoration: BoxDecoration(
        color: AmialColors.cardSurface,
        borderRadius: BorderRadius.circular(AmialSpacing.radiusLg),
        border: Border.all(color: AmialColors.border),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          const Text('الأصناف',
              style: TextStyle(
                  fontWeight: FontWeight.w900, color: AmialColors.textPrimary)),
          const SizedBox(height: AmialSpacing.sm),
          if (items.isEmpty)
            const Text('لا توجد أصناف في الرد الحالي',
                style: TextStyle(color: AmialColors.textMuted))
          else
            ...items.map((raw) {
              final item = raw as Map;
              return Container(
                padding: const EdgeInsets.symmetric(vertical: AmialSpacing.sm),
                decoration: const BoxDecoration(
                    border: Border(
                        bottom: BorderSide(color: AmialColors.border))),
                child: Row(
                  children: [
                    Text('${_money(item['line_total'])} ر.ي',
                        style: const TextStyle(
                            color: AmialColors.primary,
                            fontWeight: FontWeight.w900)),
                    const Spacer(),
                    Text('${item['quantity']} × ${_money(item['unit_price'])}',
                        style: const TextStyle(
                            color: AmialColors.textMuted, fontSize: 11)),
                    const SizedBox(width: AmialSpacing.sm),
                    Flexible(
                      child: Text('${item['product_name'] ?? '—'}',
                          textAlign: TextAlign.end,
                          overflow: TextOverflow.ellipsis),
                    ),
                  ],
                ),
              );
            }),
          const SizedBox(height: AmialSpacing.xs),
          Text('عدد الأصناف: ${items.length}',
              textAlign: TextAlign.end,
              style: const TextStyle(
                  color: AmialColors.textMuted, fontSize: 11)),
        ],
      ),
    );
  }

  Widget _totalsCard(Map inv, double balance) {
    return Container(
      padding: const EdgeInsets.all(AmialSpacing.md),
      decoration: BoxDecoration(
        color: AmialColors.cardSurface,
        borderRadius: BorderRadius.circular(AmialSpacing.radiusLg),
        border: Border.all(color: AmialColors.border),
      ),
      child: Column(
        children: [
          _totalLine('المجموع الفرعي', inv['subtotal']),
          if (_num(inv['discount_amount']) > 0)
            _totalLine('الخصم', -_num(inv['discount_amount'])),
          if (_num(inv['tax_amount']) > 0)
            _totalLine('الضريبة', inv['tax_amount']),
          const Divider(color: AmialColors.border),
          _totalLine('إجمالي الفاتورة', inv['total_amount'], strong: true),
          _totalLine('المدفوع', inv['paid_amount'], tone: AmialColors.success),
          _totalLine('المتبقي', balance,
              tone: balance > 0 ? AmialColors.danger : AmialColors.success,
              strong: true),
        ],
      ),
    );
  }

  Widget _collectionsCard(List collections) {
    return Container(
      padding: const EdgeInsets.all(AmialSpacing.md),
      decoration: BoxDecoration(
        color: AmialColors.cardSurface,
        borderRadius: BorderRadius.circular(AmialSpacing.radiusLg),
        border: Border.all(color: AmialColors.border),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          const Text('التحصيلات',
              style: TextStyle(fontWeight: FontWeight.w900)),
          const SizedBox(height: AmialSpacing.xs),
          ...collections.map((raw) {
            final col = raw as Map;
            return ListTile(
              dense: true,
              contentPadding: EdgeInsets.zero,
              leading: const Icon(Icons.check_circle_outline_rounded,
                  color: AmialColors.success),
              title: Text('${_money(col['amount'])} ر.ي',
                  style: const TextStyle(
                      color: AmialColors.success,
                      fontWeight: FontWeight.w800)),
              subtitle: Text('${col['payment_method'] ?? ''}'),
              trailing: Text(_date(col['collection_date']),
                  style: const TextStyle(
                      color: AmialColors.textMuted, fontSize: 10)),
            );
          }),
        ],
      ),
    );
  }

  Widget _totalLine(String label, dynamic value,
      {Color? tone, bool strong = false}) {
    final color = tone ?? AmialColors.textPrimary;
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: AmialSpacing.xs),
      child: Row(
        children: [
          Text('${_money(value)} ر.ي',
              style: TextStyle(
                  color: color,
                  fontWeight: strong ? FontWeight.w900 : FontWeight.w600)),
          const Spacer(),
          Text(label,
              style: const TextStyle(color: AmialColors.textSecondary)),
        ],
      ),
    );
  }

  Future<void> _collectSheet(BuildContext context, double maxAmount) async {
    final amount = TextEditingController(text: _qty(maxAmount));
    final reference = TextEditingController();
    String method = 'cash';
    await showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      backgroundColor: AmialColors.cardSurface,
      shape: const RoundedRectangleBorder(
        borderRadius:
            BorderRadius.vertical(top: Radius.circular(AmialSpacing.radiusXl)),
      ),
      builder: (sheetContext) => StatefulBuilder(
        builder: (_, setSheet) => Padding(
          padding: EdgeInsets.fromLTRB(
            AmialSpacing.screen,
            AmialSpacing.lg,
            AmialSpacing.screen,
            MediaQuery.viewInsetsOf(sheetContext).bottom + AmialSpacing.lg,
          ),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text('تسجيل تحصيل',
                  textAlign: TextAlign.center,
                  style: Theme.of(context).textTheme.titleLarge?.copyWith(
                        fontWeight: FontWeight.w900,
                      )),
              const SizedBox(height: AmialSpacing.md),
              _Field(amount, 'المبلغ *', Icons.payments_outlined, number: true),
              Wrap(
                spacing: AmialSpacing.xs,
                children: [
                  for (final m in const [
                    ('cash', 'نقد'),
                    ('bank_transfer', 'تحويل'),
                    ('amial_pay', 'أميال'),
                    ('check', 'شيك')
                  ])
                    ChoiceChip(
                      label: Text(m.$2),
                      selected: method == m.$1,
                      onSelected: (_) => setSheet(() => method = m.$1),
                    ),
                ],
              ),
              const SizedBox(height: AmialSpacing.sm),
              if (method != 'amial_pay')
                _Field(reference, 'رقم المرجع (اختياري)', Icons.tag_rounded),
              if (method == 'amial_pay')
                const Padding(
                  padding: EdgeInsets.symmetric(vertical: AmialSpacing.xs),
                  child: Text('سيُنشأ QR للتحصيل وتُسجّل الحركة تلقائياً في محفظة التاجر.',
                      textAlign: TextAlign.center,
                      style: TextStyle(color: AmialColors.textSecondary, fontSize: 12)),
                ),
              const SizedBox(height: AmialSpacing.md),
              Obx(() => FilledButton(
                    style: FilledButton.styleFrom(
                      backgroundColor: AmialColors.primary,
                      minimumSize:
                          const Size.fromHeight(AmialSpacing.buttonHeight),
                    ),
                    onPressed: c.isSubmitting.value
                        ? null
                        : () async {
                            final v = _num(amount.text);
                            if (v <= 0 || v > maxAmount) {
                              _snack(context,
                                  'المبلغ يجب أن يكون أكبر من صفر ولا يتجاوز المتبقي.',
                                  error: true);
                              return;
                            }
                            if (method == 'amial_pay') {
                              if (!sheetContext.mounted) return;
                              Navigator.pop(sheetContext);
                              await Get.to(() => AmialQrCollectScreen(
                                    amount: v,
                                    title: 'تحصيل دين جملة — أميال باي',
                                    note: 'تحصيل فاتورة جملة',
                                    createPaymentRequest: (amount, note) =>
                                        c.createCollectionPaymentRequest(
                                            widget.invoiceId, amount, note),
                                    cancelPaymentRequest:
                                        c.cancelWholesalePaymentRequest,
                                    onPaid: (paidTransactionId) async {
                                      final ok = await c.recordCollection(widget.invoiceId, {
                                        'amount': v,
                                        'payment_method': 'amial_pay',
                                        'paid_transaction_id': paidTransactionId,
                                      });
                                      if (mounted) {
                                        _snack(context, ok ? 'تم تسجيل تحصيل أميال باي' : c.lastError.value,
                                            error: !ok);
                                      }
                                      if (ok) Get.back();
                                      return ok;
                                    },
                                  ));
                              return;
                            }
                            final ok = await c.recordCollection(widget.invoiceId, {
                              'amount': v,
                              'payment_method': method,
                              if (reference.text.trim().isNotEmpty)
                                'reference_number': reference.text.trim(),
                            });
                            // **وسياقٌ فرعيٌّ يُفحَص بنفسه.** `mounted` حالةُ الودجة، و`sheetContext` سياقُ ورقةٍ قد تُغلَق أثناء الانتظار — فـNavigator.pop عليه بعدها يرمي أو يُغلق الصفحةَ تحته.
                            if (!mounted || !sheetContext.mounted) return;
                            if (ok) {
                              Navigator.pop(sheetContext);
                              _snack(context, 'تم تسجيل التحصيل');
                            } else {
                              _snack(context, c.lastError.value, error: true);
                            }
                          },
                    child: const Text('تأكيد التحصيل'),
                  )),
            ],
          ),
        ),
      ),
    );
  }
}

/// قائمة مرتجعات الجملة: الطلب لا يغيّر مالاً أو مخزوناً قبل قرار المراجع.
class WholesaleProReturnsScreen extends StatefulWidget {
  const WholesaleProReturnsScreen({super.key});

  @override
  State<WholesaleProReturnsScreen> createState() => _WholesaleProReturnsScreenState();
}

class _WholesaleProReturnsScreenState extends State<WholesaleProReturnsScreen> {
  WholesaleController get c => Get.find<WholesaleController>();
  String? _filter;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => c.loadReturns());
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    backgroundColor: AmialColors.background,
    appBar: AppBar(backgroundColor: AmialColors.background, elevation: 0,
      centerTitle: true, title: const Text('مرتجعات الجملة')),
    body: Obx(() {
      final state = _loadState(c, retry: () => c.loadReturns(status: _filter));
      if (c.returns.isEmpty && state != null) return state;
      return RefreshIndicator(
        onRefresh: () => c.loadReturns(status: _filter),
        color: AmialColors.primary,
        child: ListView(physics: const AlwaysScrollableScrollPhysics(),
          padding: const EdgeInsets.all(AmialSpacing.screen), children: [
          const Text('كل طلب يمر بالمراجعة قبل إعادة المخزون أو تخفيض دين العميل.',
              style: TextStyle(color: AmialColors.textSecondary)),
          const SizedBox(height: AmialSpacing.sm),
          Wrap(spacing: AmialSpacing.xs, children: [
            for (final f in const <(String?, String)>[(null, 'الكل'), ('requested', 'بانتظار المراجعة'), ('approved', 'معتمدة'), ('rejected', 'مرفوضة')])
              ChoiceChip(label: Text(f.$2), selected: _filter == f.$1,
                onSelected: (_) { setState(() => _filter = f.$1); c.loadReturns(status: f.$1); }),
          ]),
          const SizedBox(height: AmialSpacing.md),
          if (c.returns.isEmpty) const _SurfaceState(
              icon: Icons.assignment_return_outlined, title: 'لا توجد طلبات مرتجع',
              message: 'افتح فاتورة ثم اختر «طلب مرتجع» لبدء دورة صحيحة.'),
          ...c.returns.map((row) => _returnCard(context, row)),
        ]),
      );
    }),
  );

  Widget _returnCard(BuildContext context, Map<String, dynamic> row) {
    final status = '${row['status'] ?? ''}';
    final tone = switch (status) {
      'approved' => AmialColors.success,
      'rejected' => AmialColors.danger,
      _ => AmialColors.warning,
    };
    final invoice = row['invoice'] is Map ? row['invoice'] as Map : const {};
    final customer = row['customer'] is Map ? row['customer'] as Map : const {};
    return Container(margin: const EdgeInsets.only(bottom: AmialSpacing.sm),
      padding: const EdgeInsets.all(AmialSpacing.md),
      decoration: BoxDecoration(color: AmialColors.cardSurface,
        borderRadius: BorderRadius.circular(AmialSpacing.radiusLg),
        border: Border(right: BorderSide(color: tone, width: 4))),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Row(children: [
          _returnStatus(status), const Spacer(),
          Text('${invoice['invoice_number'] ?? '—'}', style: const TextStyle(fontWeight: FontWeight.w900)),
        ]),
        const SizedBox(height: AmialSpacing.xs),
        Text('${customer['full_name'] ?? '—'} • ${row['reason'] ?? ''}',
            style: const TextStyle(color: AmialColors.textSecondary)),
        const SizedBox(height: AmialSpacing.xs),
        Row(children: [
          Text('${_money(row['total_amount'])} ر.ي', style: TextStyle(color: tone, fontWeight: FontWeight.w900, fontSize: 18)),
          const Spacer(),
          Text('${((row['items'] as List?) ?? const []).length} أصناف', style: const TextStyle(color: AmialColors.textMuted)),
        ]),
        if (status == 'approved' && _num(row['refund_due_amount']) > 0) ...[
          const SizedBox(height: AmialSpacing.xs),
          Text('مبلغ مستحق للرد: ${_money(row['refund_due_amount'])} ر.ي — لا يُدفع تلقائياً.',
              style: const TextStyle(color: AmialColors.danger, fontWeight: FontWeight.w700)),
        ],
        if (status == 'requested') ...[
          const SizedBox(height: AmialSpacing.sm),
          Row(children: [
            Expanded(child: OutlinedButton(onPressed: () => _resolve(context, row, false), child: const Text('رفض'))),
            const SizedBox(width: AmialSpacing.sm),
            Expanded(child: FilledButton(onPressed: () => _resolve(context, row, true), child: const Text('اعتماد'))),
          ]),
        ],
      ]),
    );
  }

  Widget _returnStatus(String status) => _miniLabel(Icons.assignment_return_outlined,
      switch (status) { 'approved' => 'معتمد', 'rejected' => 'مرفوض', _ => 'بانتظار المراجعة' },
      status == 'approved' ? AmialColors.success : status == 'rejected' ? AmialColors.danger : AmialColors.warning);

  Future<void> _resolve(BuildContext context, Map<String, dynamic> row, bool approve) async {
    final note = TextEditingController();
    await showDialog<void>(context: context, builder: (dialogContext) => AlertDialog(
      title: Text(approve ? 'اعتماد المرتجع' : 'رفض المرتجع'),
      content: _Field(note, 'ملاحظة القرار (اختياري)', Icons.notes_outlined, maxLines: 3),
      actions: [
        TextButton(onPressed: () => Navigator.pop(dialogContext), child: const Text('إلغاء')),
        FilledButton(onPressed: () async {
          final ok = await c.resolveReturn((row['id'] as num).toInt(), approve, note: note.text.trim());
          if (!mounted || !dialogContext.mounted) return;
          if (ok) { Navigator.pop(dialogContext); _snack(context, approve ? 'تم اعتماد المرتجع' : 'تم رفض المرتجع'); }
          else { _snack(context, c.lastError.value, error: true); }
        }, child: Text(approve ? 'اعتماد' : 'رفض')),
      ],
    ));
    note.dispose();
  }
}

class WholesaleProReturnRequestScreen extends StatefulWidget {
  const WholesaleProReturnRequestScreen({super.key, required this.invoice});
  final Map<String, dynamic> invoice;

  @override
  State<WholesaleProReturnRequestScreen> createState() => _WholesaleProReturnRequestScreenState();
}

class _WholesaleProReturnRequestScreenState extends State<WholesaleProReturnRequestScreen> {
  WholesaleController get c => Get.find<WholesaleController>();
  final _reason = TextEditingController();
  final Map<int, TextEditingController> _quantities = {};
  final Set<int> _selected = {};

  @override
  void initState() {
    super.initState();
    for (final raw in (widget.invoice['items'] as List? ?? const [])) {
      final item = raw as Map;
      _quantities[(item['id'] as num).toInt()] = TextEditingController(text: '0');
    }
  }

  @override
  void dispose() {
    _reason.dispose();
    for (final ctrl in _quantities.values) { ctrl.dispose(); }
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final items = widget.invoice['items'] as List? ?? const [];
    return Scaffold(backgroundColor: AmialColors.background,
      appBar: AppBar(backgroundColor: AmialColors.background, elevation: 0, title: const Text('طلب مرتجع')),
      body: ListView(padding: const EdgeInsets.all(AmialSpacing.screen), children: [
        Container(padding: const EdgeInsets.all(AmialSpacing.md), decoration: BoxDecoration(
            color: AmialColors.warningSurface, borderRadius: BorderRadius.circular(AmialSpacing.radiusLg)),
          child: Text('الفاتورة ${widget.invoice['invoice_number'] ?? '—'}. لن يتغير المخزون أو الدين حتى يعتمد مسؤول مخول الطلب.',
              style: const TextStyle(color: AmialColors.warning, fontWeight: FontWeight.w700))),
        const SizedBox(height: AmialSpacing.md),
        ...items.map((raw) {
          final item = raw as Map;
          final id = (item['id'] as num).toInt();
          return Card(child: Padding(padding: const EdgeInsets.all(AmialSpacing.sm), child: Row(children: [
            Checkbox(value: _selected.contains(id), onChanged: (v) => setState(() { if (v == true) _selected.add(id); else _selected.remove(id); })),
            Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text('${item['product_name'] ?? '—'}', style: const TextStyle(fontWeight: FontWeight.w800)),
              Text('المباع ${_qty(_num(item['quantity']))} ${item['unit'] ?? ''}', style: const TextStyle(color: AmialColors.textSecondary)),
            ])),
            SizedBox(width: 90, child: _Field(_quantities[id]!, 'كمية', Icons.numbers_rounded, number: true)),
          ])));
        }),
        const SizedBox(height: AmialSpacing.sm),
        _Field(_reason, 'سبب المرتجع *', Icons.notes_outlined, maxLines: 3),
        Obx(() => FilledButton.icon(
          onPressed: c.isSubmitting.value ? null : _submit,
          icon: const Icon(Icons.send_rounded), label: const Text('إرسال للمراجعة'),
        )),
      ]),
    );
  }

  Future<void> _submit() async {
    final items = _selected.map((id) => {'invoice_item_id': id, 'quantity': _quantities[id]!.text.trim()}).toList();
    if (items.isEmpty || _reason.text.trim().isEmpty) {
      _snack(context, 'اختر الأصناف وأدخل سبب المرتجع', error: true); return;
    }
    final ok = await c.requestReturn((widget.invoice['id'] as num).toInt(), {'items': items, 'reason': _reason.text.trim()});
    if (!mounted) return;
    if (ok) { _snack(context, 'تم إرسال طلب المرتجع للمراجعة'); Get.back(); }
    else { _snack(context, c.lastError.value, error: true); }
  }
}

/// واجهة إنشاء الفاتورة تحتفظ بالعقد المالي الحالي ولا تضيف حقولاً وهمية.
class WholesaleProInvoiceCreateScreen extends StatefulWidget {
  const WholesaleProInvoiceCreateScreen({super.key});

  @override
  State<WholesaleProInvoiceCreateScreen> createState() =>
      _WholesaleProInvoiceCreateScreenState();
}

class _WholesaleProInvoiceCreateScreenState
    extends State<WholesaleProInvoiceCreateScreen> {
  WholesaleController get c => Get.find<WholesaleController>();
  String paymentType = 'credit';
  final _invoiceDiscount = TextEditingController();
  final _notes = TextEditingController();
  int? _salesRepId;

  double get _invoiceDiscountValue => _num(_invoiceDiscount.text);
  double get _netBeforeTax => (c.cartSubtotal - _invoiceDiscountValue)
      .clamp(0, double.infinity).toDouble();
  double get _taxRate => _num(c.business.value?['default_tax_rate']);
  double get _invoiceTotal =>
      double.parse((_netBeforeTax * (1 + _taxRate / 100)).toStringAsFixed(4));

  @override
  void initState() {
    super.initState();
    c.clearCart();
    WidgetsBinding.instance.addPostFrameCallback((_) async {
      await c.loadProducts();
      await c.loadCustomers();
      await c.loadSalesReps();
      await c.loadBusiness();
    });
  }

  @override
  void dispose() {
    _invoiceDiscount.dispose();
    _notes.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(
        backgroundColor: AmialColors.background,
        elevation: 0,
        centerTitle: true,
        title: const Text('فاتورة جديدة'),
      ),
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(
                AmialSpacing.screen, AmialSpacing.sm, AmialSpacing.screen, 0),
            child: Obx(() {
              final cust = c.selectedCustomer.value;
              return InkWell(
                borderRadius: BorderRadius.circular(AmialSpacing.radiusLg),
                onTap: () => _selectCustomer(context),
                child: Container(
                  padding: const EdgeInsets.all(AmialSpacing.md),
                  decoration: BoxDecoration(
                    color: AmialColors.cardSurface,
                    borderRadius: BorderRadius.circular(AmialSpacing.radiusLg),
                    border: Border.all(
                        color: cust == null
                            ? AmialColors.border
                            : AmialColors.primary),
                  ),
                  child: Row(
                    children: [
                      Icon(cust == null
                          ? Icons.person_add_alt_1_outlined
                          : Icons.verified_user_outlined,
                          color: AmialColors.primary),
                      const SizedBox(width: AmialSpacing.sm),
                      Expanded(
                        child: Text(
                          cust?['full_name'] ?? 'اختر العميل *',
                          style: const TextStyle(fontWeight: FontWeight.w800),
                        ),
                      ),
                      const Icon(Icons.expand_more_rounded),
                    ],
                  ),
                ),
              );
            }),
          ),
          Expanded(
            child: Obx(() {
              if (c.cart.isEmpty) {
                return _SurfaceState(
                  icon: Icons.shopping_cart_outlined,
                  title: 'السلة فارغة',
                  message: 'أضف المنتجات التي تريد إصدار الفاتورة لها.',
                  actionLabel: 'إضافة منتج',
                  onAction: () => _selectProduct(context),
                );
              }
              return ListView.builder(
                padding: const EdgeInsets.all(AmialSpacing.screen),
                itemCount: c.cart.length,
                itemBuilder: (_, i) {
                  final item = c.cart[i];
                  final p = item['product'] as Map;
                  final qty = _num(item['quantity']);
                  final price = _num(p['quoted_unit_price'] ?? p['base_price']);
                  final lineDiscount = _num(item['discount_per_unit']);
                  return Card(
                    color: AmialColors.cardSurface,
                    child: ListTile(
                      leading: IconButton(
                        onPressed: () => c.removeFromCart(item['product_id']),
                        icon: const Icon(Icons.delete_outline_rounded,
                            color: AmialColors.danger),
                      ),
                      title: Text('${p['name'] ?? '—'}',
                          style: const TextStyle(fontWeight: FontWeight.w800)),
                      subtitle: Text(
                          '${_qty(qty)} ${p['unit'] ?? 'وحدة'} • سعر العميل ${_money(price)}'
                          '${lineDiscount > 0 ? ' • خصم ${_money(lineDiscount)} / وحدة' : ''}'),
                      trailing: Column(
                        mainAxisSize: MainAxisSize.min,
                        crossAxisAlignment: CrossAxisAlignment.end,
                        children: [
                          Text('${_money((price - lineDiscount).clamp(0, double.infinity).toDouble() * qty)} ر.ي',
                              style: const TextStyle(
                                  color: AmialColors.primary,
                                  fontWeight: FontWeight.w900)),
                          TextButton(
                            onPressed: () => _lineDiscountDialog(context, item),
                            child: const Text('خصم الصنف'),
                          ),
                        ],
                      ),
                    ),
                  );
                },
              );
            }),
          ),
          Obx(() => Container(
                padding: const EdgeInsets.all(AmialSpacing.screen),
                decoration: const BoxDecoration(
                  color: AmialColors.cardSurface,
                  border: Border(top: BorderSide(color: AmialColors.border)),
                ),
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Row(
                      children: [
                        OutlinedButton.icon(
                          onPressed: () => _selectProduct(context),
                          icon: const Icon(Icons.add_rounded),
                          label: const Text('إضافة صنف'),
                        ),
                        const Spacer(),
                        Text('${_money(c.cartSubtotal)} ر.ي',
                            style: const TextStyle(
                                color: AmialColors.primary,
                                fontSize: 22,
                                fontWeight: FontWeight.w900)),
                      ],
                    ),
                    const SizedBox(height: AmialSpacing.sm),
                    Row(
                      children: [
                        Expanded(
                          child: Text(
                            _invoiceDiscountValue > 0
                                ? 'خصم الفاتورة ${_money(_invoiceDiscountValue)} ر.ي'
                                : 'لا يوجد خصم إضافي على الفاتورة',
                            style: const TextStyle(color: AmialColors.textSecondary),
                          ),
                        ),
                        IconButton(
                          tooltip: 'تفاصيل وخصم',
                          onPressed: () => _invoiceOptions(context),
                          icon: const Icon(Icons.tune_rounded),
                        ),
                      ],
                    ),
                    Text(_taxRate > 0
                            ? 'الإجمالي شامل ضريبة ${_taxRate.toStringAsFixed(0)}٪: ${_money(_invoiceTotal)} ر.ي'
                            : 'الإجمالي ${_money(_invoiceTotal)} ر.ي',
                        style: const TextStyle(
                            color: AmialColors.primary,
                            fontSize: 18,
                            fontWeight: FontWeight.w900)),
                    const SizedBox(height: AmialSpacing.sm),
                    Row(
                      children: [
                        Expanded(child: _payChoice('cash', 'نقد')),
                        const SizedBox(width: AmialSpacing.sm),
                        Expanded(child: _payChoice('amial_pay', 'أميال باي')),
                        const SizedBox(width: AmialSpacing.sm),
                        Expanded(child: _payChoice('credit', 'آجل')),
                      ],
                    ),
                    const SizedBox(height: AmialSpacing.sm),
                    FilledButton.icon(
                      style: FilledButton.styleFrom(
                        backgroundColor: AmialColors.primary,
                        minimumSize:
                            const Size.fromHeight(AmialSpacing.buttonHeight),
                      ),
                      onPressed: c.isSubmitting.value || c.cart.isEmpty
                          ? null
                          : _submit,
                      icon: const Icon(Icons.check_rounded),
                      label: const Text('إنشاء الفاتورة'),
                    ),
                  ],
                ),
              )),
        ],
      ),
    );
  }

  Widget _payChoice(String value, String label) {
    final selected = paymentType == value;
    return ChoiceChip(
      label: SizedBox(width: double.infinity, child: Text(label, textAlign: TextAlign.center)),
      selected: selected,
      selectedColor: AmialColors.primary,
      labelStyle: TextStyle(
        color: selected ? AmialColors.cardSurface : AmialColors.textPrimary,
        fontWeight: FontWeight.w800,
      ),
      onSelected: (_) => setState(() => paymentType = value),
    );
  }

  Future<void> _submit() async {
    if (c.selectedCustomer.value == null) {
      _snack(context, 'اختر العميل أولاً', error: true);
      return;
    }
    if (_invoiceDiscountValue > c.cartSubtotal) {
      _snack(context, 'خصم الفاتورة لا يمكن أن يتجاوز إجمالي الأصناف', error: true);
      return;
    }
    if (paymentType == 'amial_pay') {
      await Get.to(() => AmialQrCollectScreen(
            amount: _invoiceTotal,
            title: 'تحصيل فاتورة جملة — أميال باي',
            note: _notes.text.trim().isEmpty
                ? 'تحصيل فاتورة جملة'
                : _notes.text.trim(),
            createPaymentRequest: c.createInvoicePaymentRequest,
            cancelPaymentRequest: c.cancelWholesalePaymentRequest,
            onPaid: (paidTransactionId) => _createPaidInvoice(paidTransactionId),
          ));
      return;
    }
    await _createPaidInvoice();
  }

  Future<bool> _createPaidInvoice([String? paidTransactionId]) async {
    final ok = await c.createInvoice(
      paymentType: paymentType,
      paidTransactionId: paidTransactionId,
      discountAmount: _invoiceDiscount.text.trim(),
      salesRepId: _salesRepId,
      notes: _notes.text.trim(),
    );
    if (!mounted) return false;
    if (ok) {
      final inv = c.currentInvoice.value;
      _snack(context, 'تم إنشاء الفاتورة ${inv?['invoice_number'] ?? ''} بنجاح');
      Get.back();
      return true;
    }
    _snack(context, c.lastError.value, error: true);
    return false;
  }

  void _selectCustomer(BuildContext context) {
    showModalBottomSheet<void>(
      context: context,
      backgroundColor: AmialColors.cardSurface,
      shape: const RoundedRectangleBorder(
        borderRadius:
            BorderRadius.vertical(top: Radius.circular(AmialSpacing.radiusXl)),
      ),
      builder: (_) => SafeArea(
        child: Obx(() => ListView(
              shrinkWrap: true,
              padding: const EdgeInsets.all(AmialSpacing.md),
              children: [
                const Text('اختر العميل',
                    textAlign: TextAlign.center,
                    style: TextStyle(fontWeight: FontWeight.w900, fontSize: 18)),
                const SizedBox(height: AmialSpacing.sm),
                ...c.customers.map((cust) => ListTile(
                      leading: const CircleAvatar(
                          child: Icon(Icons.person_outline_rounded)),
                      title: Text('${cust['full_name'] ?? '—'}'),
                      subtitle: Text('${cust['phone'] ?? ''}'),
                      onTap: () {
                        c.clearCart();
                        c.selectedCustomer.value = cust;
                        Navigator.pop(context);
                      },
                    )),
              ],
            )),
      ),
    );
  }

  void _selectProduct(BuildContext context) {
    showModalBottomSheet<void>(
      context: context,
      backgroundColor: AmialColors.cardSurface,
      shape: const RoundedRectangleBorder(
        borderRadius:
            BorderRadius.vertical(top: Radius.circular(AmialSpacing.radiusXl)),
      ),
      builder: (_) => SafeArea(
        child: Obx(() => ListView(
              shrinkWrap: true,
              padding: const EdgeInsets.all(AmialSpacing.md),
              children: [
                const Text('إضافة صنف',
                    textAlign: TextAlign.center,
                    style: TextStyle(fontWeight: FontWeight.w900, fontSize: 18)),
                const SizedBox(height: AmialSpacing.sm),
                ...c.products.map((p) => ListTile(
                      leading: const Icon(Icons.inventory_2_outlined,
                          color: AmialColors.primary),
                      title: Text('${p['name'] ?? '—'}'),
                      subtitle: Text(
                          '${_money(p['base_price'])} ر.ي • متوفر ${_qty(_num(p['current_stock']))} ${p['unit'] ?? 'وحدة'}'),
                      onTap: () {
                        Navigator.pop(context);
                        _quantityDialog(context, p);
                      },
                    )),
              ],
            )),
      ),
    );
  }

  void _quantityDialog(BuildContext context, Map<String, dynamic> p) {
    final q = TextEditingController(text: '1');
    showDialog<void>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        title: Text('${p['name'] ?? 'المنتج'}'),
        content: _Field(q, 'الكمية (${p['unit'] ?? 'وحدة'})',
            Icons.numbers_rounded,
            number: true),
        actions: [
          TextButton(
              onPressed: () => Navigator.pop(dialogContext),
              child: const Text('إلغاء')),
          FilledButton(
            onPressed: () async {
              final qty = _num(q.text);
              if (qty <= 0) return;
              final ok = await c.addToCart(p, qty);
              if (!dialogContext.mounted) return;
              if (!ok) {
                _snack(context, c.lastError.value, error: true);
                return;
              }
              Navigator.pop(dialogContext);
            },
            child: const Text('إضافة'),
          ),
        ],
      ),
    );
  }

  Future<void> _lineDiscountDialog(BuildContext context, Map<String, dynamic> item) async {
    final ctrl = TextEditingController(text: '${item['discount_per_unit'] ?? 0}');
    await showDialog<void>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        title: const Text('خصم الصنف لكل وحدة'),
        content: _Field(ctrl, 'قيمة الخصم', Icons.discount_outlined, number: true),
        actions: [
          TextButton(onPressed: () => Navigator.pop(dialogContext), child: const Text('إلغاء')),
          FilledButton(
            onPressed: () {
              final discount = _num(ctrl.text);
              final price = _num((item['product'] as Map)['quoted_unit_price'] ??
                  (item['product'] as Map)['base_price']);
              if (discount < 0 || discount > price) {
                _snack(context, 'الخصم يجب أن يكون بين صفر وسعر الوحدة', error: true);
                return;
              }
              final index = c.cart.indexWhere((x) => x['product_id'] == item['product_id']);
              if (index >= 0) {
                c.cart[index] = {...c.cart[index], 'discount_per_unit': discount};
                c.cart.refresh();
              }
              Navigator.pop(dialogContext);
            },
            child: const Text('تطبيق الخصم'),
          ),
        ],
      ),
    );
    ctrl.dispose();
  }

  Future<void> _invoiceOptions(BuildContext context) async {
    await showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      backgroundColor: AmialColors.cardSurface,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(AmialSpacing.radiusXl)),
      ),
      builder: (sheetContext) => Padding(
        padding: EdgeInsets.fromLTRB(AmialSpacing.screen, AmialSpacing.lg,
            AmialSpacing.screen, MediaQuery.viewInsetsOf(sheetContext).bottom + AmialSpacing.lg),
        child: SingleChildScrollView(
          child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            const Text('تفاصيل الفاتورة', textAlign: TextAlign.center,
                style: TextStyle(fontSize: 18, fontWeight: FontWeight.w900)),
            const SizedBox(height: AmialSpacing.md),
            _Field(_invoiceDiscount, 'خصم إضافي على الفاتورة', Icons.discount_rounded, number: true),
            _Field(_notes, 'ملاحظات (اختياري)', Icons.notes_outlined, maxLines: 3),
            const Text('مندوب المبيعات (اختياري)', style: TextStyle(fontWeight: FontWeight.w800)),
            const SizedBox(height: AmialSpacing.xs),
            Wrap(spacing: AmialSpacing.xs, runSpacing: AmialSpacing.xs, children: [
              ChoiceChip(label: const Text('بدون مندوب'), selected: _salesRepId == null,
                  onSelected: (_) => setState(() => _salesRepId = null)),
              ...c.salesReps.map((rep) => ChoiceChip(
                label: Text('${rep['full_name'] ?? 'مندوب'}'),
                selected: _salesRepId == (rep['id'] as num?)?.toInt(),
                onSelected: (_) => setState(() => _salesRepId = (rep['id'] as num?)?.toInt()),
              )),
            ]),
            const SizedBox(height: AmialSpacing.md),
            FilledButton(onPressed: () { setState(() {}); Navigator.pop(sheetContext); },
                child: const Text('حفظ التفاصيل')),
          ]),
        ),
      ),
    );
  }
}

class WholesaleProAgingScreen extends StatefulWidget {
  const WholesaleProAgingScreen({super.key});

  @override
  State<WholesaleProAgingScreen> createState() => _WholesaleProAgingScreenState();
}

class _WholesaleProAgingScreenState extends State<WholesaleProAgingScreen> {
  WholesaleController get c => Get.find<WholesaleController>();

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => c.loadAgingReport());
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(
        backgroundColor: AmialColors.background,
        elevation: 0,
        centerTitle: true,
        title: const Text('تقرير تقادم الديون'),
      ),
      body: Obx(() {
        final state = _loadState(c, retry: c.loadAgingReport);
        final r = c.agingReport.value;
        if (r == null) return state ?? const SizedBox.shrink();
        final buckets = r['buckets'] is Map ? r['buckets'] as Map : const {};
        final pct = r['percentages'] is Map ? r['percentages'] as Map : const {};
        final customers = r['by_customer'] is List ? r['by_customer'] as List : const [];
        return RefreshIndicator(
          onRefresh: c.loadAgingReport,
          color: AmialColors.primary,
          child: ListView(
            physics: const AlwaysScrollableScrollPhysics(),
            padding: const EdgeInsets.all(AmialSpacing.screen),
            children: [
              Container(
                padding: const EdgeInsets.all(AmialSpacing.xl),
                decoration: BoxDecoration(
                  gradient: const LinearGradient(
                    colors: [AmialColors.primaryDark, AmialColors.primary],
                  ),
                  borderRadius: BorderRadius.circular(AmialSpacing.radiusXl),
                ),
                child: Column(
                  children: [
                    const Icon(Icons.query_stats_rounded,
                        color: AmialColors.yellow, size: 42),
                    const SizedBox(height: AmialSpacing.sm),
                    const Text('إجمالي المستحقات',
                        style: TextStyle(color: AmialColors.cardSurface)),
                    Text('${_money(r['total_receivable'])} ر.ي',
                        style: const TextStyle(
                            color: AmialColors.cardSurface,
                            fontSize: 32,
                            fontWeight: FontWeight.w900)),
                  ],
                ),
              ),
              const SizedBox(height: AmialSpacing.md),
              _agingBucket('الحالي (0-30 يوم)', buckets['current'], pct['current'],
                  AmialColors.success, Icons.schedule_rounded),
              _agingBucket('30-60 يوم', buckets['30_60'], pct['30_60'],
                  AmialColors.warning, Icons.schedule_rounded),
              _agingBucket('60-90 يوم', buckets['60_90'], pct['60_90'],
                  AmialColors.cash, Icons.schedule_rounded),
              _agingBucket('أكثر من 90 يوم', buckets['over_90'], pct['over_90'],
                  AmialColors.danger, Icons.error_outline_rounded),
              const SizedBox(height: AmialSpacing.lg),
              const Text('بحسب العميل',
                  style: TextStyle(fontWeight: FontWeight.w900, fontSize: 18)),
              const SizedBox(height: AmialSpacing.sm),
              ...customers.map((raw) {
                final m = raw as Map;
                return Card(
                  color: AmialColors.cardSurface,
                  child: ListTile(
                    leading: CircleAvatar(
                      backgroundColor: AmialColors.primary.withValues(alpha: 0.08),
                      child: const Icon(Icons.person_outline_rounded,
                          color: AmialColors.primary),
                    ),
                    title: Text('${m['customer_name'] ?? '—'}'),
                    subtitle: Text('${m['invoices_count'] ?? '—'} فاتورة'),
                    trailing: Text('${_money(m['total'])} ر.ي',
                        style: const TextStyle(
                            color: AmialColors.danger,
                            fontWeight: FontWeight.w900)),
                  ),
                );
              }),
            ],
          ),
        );
      }),
    );
  }

  Widget _agingBucket(
      String label, dynamic amount, dynamic pct, Color tone, IconData icon) {
    return Container(
      margin: const EdgeInsets.only(bottom: AmialSpacing.sm),
      padding: const EdgeInsets.all(AmialSpacing.md),
      decoration: BoxDecoration(
        color: AmialColors.cardSurface,
        borderRadius: BorderRadius.circular(AmialSpacing.radiusLg),
        border: Border(right: BorderSide(color: tone, width: 4)),
      ),
      child: Row(
        children: [
          CircleAvatar(
            backgroundColor: tone.withValues(alpha: 0.08),
            child: Icon(icon, color: tone),
          ),
          const SizedBox(width: AmialSpacing.sm),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(label,
                    style: const TextStyle(fontWeight: FontWeight.w900)),
                Text('${_pct(pct)} من الإجمالي',
                    style: const TextStyle(
                        color: AmialColors.textMuted, fontSize: 11)),
              ],
            ),
          ),
          Text('${_money(amount)} ر.ي',
              style: TextStyle(
                  color: tone, fontWeight: FontWeight.w900, fontSize: 18)),
        ],
      ),
    );
  }
}

/// تقريرٌ تنفيذيٌّ للمندوبين من مصدر تقارير الجملة، وليس بطاقاتٍ ثابتة.
class WholesaleProSalesRepsReportScreen extends StatefulWidget {
  const WholesaleProSalesRepsReportScreen({super.key});

  @override
  State<WholesaleProSalesRepsReportScreen> createState() =>
      _WholesaleProSalesRepsReportScreenState();
}

class WholesaleProSalesRepsScreen extends StatefulWidget {
  const WholesaleProSalesRepsScreen({super.key});

  @override
  State<WholesaleProSalesRepsScreen> createState() => _WholesaleProSalesRepsScreenState();
}

class _WholesaleProSalesRepsScreenState extends State<WholesaleProSalesRepsScreen> {
  WholesaleController get c => Get.find<WholesaleController>();

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => c.loadSalesReps());
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    backgroundColor: AmialColors.background,
    appBar: AppBar(backgroundColor: AmialColors.background, elevation: 0,
      centerTitle: true, title: const Text('إدارة المندوبين')),
    floatingActionButton: FloatingActionButton.extended(
      backgroundColor: AmialColors.primary, foregroundColor: AmialColors.cardSurface,
      onPressed: () => _addSheet(context), icon: const Icon(Icons.person_add_alt_1_outlined),
      label: const Text('مندوب جديد'),
    ),
    body: Obx(() => RefreshIndicator(
      onRefresh: c.loadSalesReps,
      color: AmialColors.primary,
      child: c.salesReps.isEmpty
          ? ListView(physics: const AlwaysScrollableScrollPhysics(), children: const [
              SizedBox(height: 120),
              _SurfaceState(icon: Icons.badge_outlined, title: 'لا يوجد مندوبون',
                  message: 'أضف مندوباً ثم اختره عند إنشاء فاتورة الجملة.'),
            ])
          : ListView.builder(physics: const AlwaysScrollableScrollPhysics(),
              padding: const EdgeInsets.all(AmialSpacing.screen), itemCount: c.salesReps.length,
              itemBuilder: (_, i) {
                final rep = c.salesReps[i];
                return Container(margin: const EdgeInsets.only(bottom: AmialSpacing.sm),
                  padding: const EdgeInsets.all(AmialSpacing.md),
                  decoration: BoxDecoration(color: AmialColors.cardSurface,
                      borderRadius: BorderRadius.circular(AmialSpacing.radiusLg),
                      border: Border.all(color: AmialColors.border)),
                  child: Row(children: [
                    CircleAvatar(backgroundColor: AmialColors.primary.withValues(alpha: 0.1),
                      child: const Icon(Icons.badge_outlined, color: AmialColors.primary)),
                    const SizedBox(width: AmialSpacing.sm),
                    Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Text('${rep['full_name'] ?? '—'}', style: const TextStyle(fontWeight: FontWeight.w900)),
                      Text('${rep['phone'] ?? 'بدون رقم'} • عمولة ${_pct(rep['default_commission_rate'])}',
                          style: const TextStyle(color: AmialColors.textSecondary)),
                    ])),
                    Text('${_money(rep['total_sales'])} ر.ي', style: const TextStyle(color: AmialColors.primary, fontWeight: FontWeight.w900)),
                  ]),
                );
              }),
    )),
  );

  Future<void> _addSheet(BuildContext context) async {
    final name = TextEditingController();
    final phone = TextEditingController();
    final rate = TextEditingController(text: '0');
    await showModalBottomSheet<void>(context: context, isScrollControlled: true,
      backgroundColor: AmialColors.cardSurface,
      shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(AmialSpacing.radiusXl))),
      builder: (sheetContext) => Padding(
        padding: EdgeInsets.fromLTRB(AmialSpacing.screen, AmialSpacing.lg, AmialSpacing.screen,
            MediaQuery.viewInsetsOf(sheetContext).bottom + AmialSpacing.lg),
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          const Text('مندوب جديد', style: TextStyle(fontSize: 18, fontWeight: FontWeight.w900)),
          const SizedBox(height: AmialSpacing.md),
          _Field(name, 'اسم المندوب *', Icons.person_outline),
          _Field(phone, 'رقم الهاتف (اختياري)', Icons.phone_outlined, number: true),
          _Field(rate, 'نسبة العمولة %', Icons.percent_rounded, number: true),
          Obx(() => FilledButton(
            onPressed: c.isSubmitting.value ? null : () async {
              if (name.text.trim().isEmpty || _num(rate.text) < 0 || _num(rate.text) > 100) {
                _snack(context, 'أدخل الاسم ونسبة عمولة بين 0 و100', error: true); return;
              }
              final ok = await c.addSalesRep({
                'full_name': name.text.trim(),
                if (phone.text.trim().isNotEmpty) 'phone': phone.text.trim(),
                'default_commission_rate': rate.text.trim(),
              });
              if (!mounted || !sheetContext.mounted) return;
              if (ok) { Navigator.pop(sheetContext); _snack(context, 'تمت إضافة المندوب'); }
              else { _snack(context, c.lastError.value, error: true); }
            }, child: const Text('إضافة المندوب'),
          )),
        ]),
      ));
    name.dispose(); phone.dispose(); rate.dispose();
  }
}

class _WholesaleProSalesRepsReportScreenState
    extends State<WholesaleProSalesRepsReportScreen> {
  WholesaleController get c => Get.find<WholesaleController>();

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => c.loadSalesRepsReport());
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(
        backgroundColor: AmialColors.background,
        elevation: 0,
        centerTitle: true,
        title: const Text('أداء مندوبي المبيعات'),
      ),
      body: Obx(() {
        final state = _loadState(c, retry: c.loadSalesRepsReport);
        final report = c.salesRepsReport.value;
        if (report == null) return state ?? const SizedBox.shrink();
        final period = report['period'] is Map ? report['period'] as Map : const {};
        final reps = report['reps'] is List ? report['reps'] as List : const [];
        final totalSales = reps.fold<double>(0, (sum, raw) =>
            sum + _num((raw as Map)['period'] is Map ? (raw['period'] as Map)['total_sales'] : 0));
        final totalCommission = reps.fold<double>(0, (sum, raw) =>
            sum + _num((raw as Map)['period'] is Map ? (raw['period'] as Map)['total_commission'] : 0));
        return RefreshIndicator(
          onRefresh: c.loadSalesRepsReport,
          color: AmialColors.primary,
          child: ListView(
            physics: const AlwaysScrollableScrollPhysics(),
            padding: const EdgeInsets.all(AmialSpacing.screen),
            children: [
              Container(
                padding: const EdgeInsets.all(AmialSpacing.lg),
                decoration: BoxDecoration(
                  gradient: const LinearGradient(colors: [AmialColors.primaryDark, AmialColors.primary]),
                  borderRadius: BorderRadius.circular(AmialSpacing.radiusXl),
                ),
                child: Column(children: [
                  const Icon(Icons.leaderboard_rounded, color: AmialColors.yellow, size: 38),
                  const SizedBox(height: AmialSpacing.xs),
                  Text('الفترة ${_date(period['from'])} — ${_date(period['to'])}',
                      style: const TextStyle(color: AmialColors.cardSurface)),
                  const SizedBox(height: AmialSpacing.sm),
                  Text('${_money(totalSales)} ر.ي', style: const TextStyle(
                      color: AmialColors.cardSurface, fontSize: 28, fontWeight: FontWeight.w900)),
                  const Text('إجمالي المبيعات', style: TextStyle(color: AmialColors.cardSurface)),
                ]),
              ),
              const SizedBox(height: AmialSpacing.md),
              Row(children: [
                Expanded(child: _MetricCard(icon: Icons.people_outline, label: 'المندوبون',
                    value: '${reps.length}', tone: AmialColors.primary, surface: AmialColors.cardSurface)),
                const SizedBox(width: AmialSpacing.sm),
                Expanded(child: _MetricCard(icon: Icons.workspace_premium_outlined, label: 'العمولات',
                    value: '${_money(totalCommission)} ر.ي', tone: AmialColors.success, surface: AmialColors.cardSurface)),
              ]),
              const SizedBox(height: AmialSpacing.lg),
              const Text('الترتيب حسب المبيعات', style: TextStyle(fontSize: 18, fontWeight: FontWeight.w900)),
              const SizedBox(height: AmialSpacing.sm),
              if (reps.isEmpty)
                const _SurfaceState(icon: Icons.group_off_outlined, title: 'لا يوجد مندوبون نشطون',
                    message: 'أضف مندوباً عند الحاجة ثم ستظهر نتائجه في هذا التقرير.'),
              ...reps.asMap().entries.map((entry) {
                final rep = entry.value as Map;
                final metrics = rep['period'] is Map ? rep['period'] as Map : const {};
                final allTime = rep['all_time'] is Map ? rep['all_time'] as Map : const {};
                return Container(
                  margin: const EdgeInsets.only(bottom: AmialSpacing.sm),
                  padding: const EdgeInsets.all(AmialSpacing.md),
                  decoration: BoxDecoration(color: AmialColors.cardSurface,
                      borderRadius: BorderRadius.circular(AmialSpacing.radiusLg),
                      border: Border.all(color: AmialColors.border)),
                  child: Row(children: [
                    CircleAvatar(backgroundColor: AmialColors.primary.withValues(alpha: 0.10),
                      child: Text('${entry.key + 1}', style: const TextStyle(color: AmialColors.primary, fontWeight: FontWeight.w900))),
                    const SizedBox(width: AmialSpacing.sm),
                    Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Text('${rep['full_name'] ?? '—'}', style: const TextStyle(fontWeight: FontWeight.w900)),
                      Text('${metrics['invoices_count'] ?? 0} فاتورة • عمولة معلقة ${_money(allTime['pending_commission'])} ر.ي',
                          style: const TextStyle(color: AmialColors.textSecondary, fontSize: 11)),
                    ])),
                    Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
                      Text('${_money(metrics['total_sales'])} ر.ي', style: const TextStyle(color: AmialColors.primary, fontWeight: FontWeight.w900)),
                      Text('عمولة ${_money(metrics['total_commission'])}', style: const TextStyle(color: AmialColors.success, fontSize: 11)),
                    ]),
                  ]),
                );
              }),
            ],
          ),
        );
      }),
    );
  }
}

class WholesaleProCustomerStatementScreen extends StatefulWidget {
  const WholesaleProCustomerStatementScreen({super.key, required this.customer});
  final Map<String, dynamic> customer;

  @override
  State<WholesaleProCustomerStatementScreen> createState() =>
      _WholesaleProCustomerStatementScreenState();
}

class _WholesaleProCustomerStatementScreenState
    extends State<WholesaleProCustomerStatementScreen> {
  WholesaleController get c => Get.find<WholesaleController>();

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback(
        (_) => c.loadCustomerStatement((widget.customer['id'] as num).toInt()));
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(
        backgroundColor: AmialColors.background,
        title: Text('${widget.customer['full_name'] ?? 'كشف الحساب'}'),
      ),
      body: Obx(() {
        final state = _loadState(c,
            retry: () => c.loadCustomerStatement(
                (widget.customer['id'] as num).toInt()));
        final s = c.currentStatement.value;
        if (s == null) return state ?? const SizedBox.shrink();
        final summary = s['summary'] is Map ? s['summary'] as Map : const {};
        final events = s['events'] is List ? s['events'] as List : const [];
        return ListView(
          padding: const EdgeInsets.all(AmialSpacing.screen),
          children: [
            LayoutBuilder(builder: (_, constraints) {
              final width = (constraints.maxWidth - AmialSpacing.sm) / 2;
              return Wrap(
                spacing: AmialSpacing.sm,
                runSpacing: AmialSpacing.sm,
                children: [
                  _MetricCard(width: width, icon: Icons.receipt_long_outlined,
                      label: 'إجمالي الفواتير', value: '${_money(summary['total_invoiced'])} ر.ي',
                      tone: AmialColors.primary, surface: AmialColors.cardSurface),
                  _MetricCard(width: width, icon: Icons.payments_outlined,
                      label: 'إجمالي المدفوع', value: '${_money(summary['total_paid'])} ر.ي',
                      tone: AmialColors.success, surface: AmialColors.cardSurface),
                ],
              );
            }),
            const SizedBox(height: AmialSpacing.md),
            Container(
              padding: const EdgeInsets.all(AmialSpacing.md),
              decoration: BoxDecoration(
                color: AmialColors.dangerSurface,
                borderRadius: BorderRadius.circular(AmialSpacing.radiusLg),
              ),
              child: Row(children: [
                const Text('الرصيد الحالي',
                    style: TextStyle(fontWeight: FontWeight.w800)),
                const Spacer(),
                Text('${_money(summary['closing_balance'])} ر.ي',
                    style: const TextStyle(
                        color: AmialColors.danger,
                        fontWeight: FontWeight.w900,
                        fontSize: 18)),
              ]),
            ),
            const SizedBox(height: AmialSpacing.md),
            ...events.map((raw) {
              final e = raw as Map;
              final invoice = e['type'] == 'invoice';
              return Card(
                color: AmialColors.cardSurface,
                child: ListTile(
                  leading: Icon(invoice ? Icons.receipt_long_outlined : Icons.payments_outlined,
                      color: invoice ? AmialColors.danger : AmialColors.success),
                  title: Text('${e['description'] ?? '—'}'),
                  subtitle: Text(_date(e['date'])),
                  trailing: Text(
                    invoice ? '+ ${_money(e['debit'])}' : '- ${_money(e['credit'])}',
                    style: TextStyle(
                      color: invoice ? AmialColors.danger : AmialColors.success,
                      fontWeight: FontWeight.w900,
                    ),
                  ),
                ),
              );
            }),
          ],
        );
      }),
    );
  }
}

class WholesaleStockAlertsScreen extends StatefulWidget {
  const WholesaleStockAlertsScreen({super.key});

  @override
  State<WholesaleStockAlertsScreen> createState() =>
      _WholesaleStockAlertsScreenState();
}

class _WholesaleStockAlertsScreenState extends State<WholesaleStockAlertsScreen> {
  WholesaleController get c => Get.find<WholesaleController>();

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      _openCapability(context, 'low_stock_alerts',
          () => c.loadProducts(lowStockOnly: true), keepHere: true);
    });
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(
        backgroundColor: AmialColors.background,
        elevation: 0,
        centerTitle: true,
        title: const Text('تنبيهات المخزون'),
      ),
      body: Obx(() {
        if (!_isCapabilityAvailable('low_stock_alerts')) {
          return _CapabilityUnavailableSurface(capability: 'low_stock_alerts');
        }
        final state = _loadState(c,
            retry: () => c.loadProducts(lowStockOnly: true));
        if (state != null && c.products.isEmpty) return state;
        final out = c.products
            .where((p) => _num(p['current_stock']) <= 0)
            .length;
        return RefreshIndicator(
          onRefresh: () => c.loadProducts(lowStockOnly: true),
          color: AmialColors.primary,
          child: ListView(
            physics: const AlwaysScrollableScrollPhysics(),
            padding: const EdgeInsets.all(AmialSpacing.screen),
            children: [
              Row(
                children: [
                  Expanded(
                    child: _MetricCard(
                      icon: Icons.inventory_outlined,
                      label: 'نفد',
                      value: '$out',
                      tone: AmialColors.danger,
                      surface: AmialColors.dangerSurface,
                    ),
                  ),
                  const SizedBox(width: AmialSpacing.sm),
                  Expanded(
                    child: _MetricCard(
                      icon: Icons.schedule_rounded,
                      label: 'قرب النفاد',
                      value: '${c.products.length - out}',
                      tone: AmialColors.warning,
                      surface: AmialColors.warningSurface,
                    ),
                  ),
                ],
              ),
              const SizedBox(height: AmialSpacing.md),
              Container(
                padding: const EdgeInsets.all(AmialSpacing.md),
                decoration: BoxDecoration(
                  color: AmialColors.cardSurface,
                  borderRadius: BorderRadius.circular(AmialSpacing.radiusLg),
                  border: Border.all(color: AmialColors.border),
                ),
                child: const Row(
                  children: [
                    Icon(Icons.notifications_active_outlined,
                        color: AmialColors.primary),
                    SizedBox(width: AmialSpacing.sm),
                    Expanded(
                      child: Text(
                        'هذه القائمة تتحدث من حد التنبيه المحفوظ لكل منتج. غيّر الحد من بطاقة المنتج عند الحاجة.',
                        style: TextStyle(color: AmialColors.textSecondary),
                      ),
                    ),
                  ],
                ),
              ),
              const SizedBox(height: AmialSpacing.md),
              ...c.products.map((p) => _stockAlertCard(context, p)),
              if (c.products.isEmpty)
                const _SurfaceState(
                  icon: Icons.check_circle_outline_rounded,
                  title: 'المخزون ضمن الحدود',
                  message: 'لا توجد منتجات بلغت حد التنبيه في القراءة الحالية.',
                ),
            ],
          ),
        );
      }),
    );
  }

  Widget _stockAlertCard(BuildContext context, Map<String, dynamic> p) {
    final remaining = _num(p['current_stock']);
    final threshold = _num(p['low_stock_threshold']);
    final out = remaining <= 0;
    final tone = out ? AmialColors.danger : AmialColors.warning;
    return Container(
      margin: const EdgeInsets.only(bottom: AmialSpacing.sm),
      padding: const EdgeInsets.all(AmialSpacing.md),
      decoration: BoxDecoration(
        color: AmialColors.cardSurface,
        borderRadius: BorderRadius.circular(AmialSpacing.radiusLg),
        border: Border(right: BorderSide(color: tone, width: 4)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              Container(
                padding: const EdgeInsets.symmetric(
                    horizontal: AmialSpacing.sm, vertical: AmialSpacing.xxs),
                decoration: BoxDecoration(
                  color: out ? AmialColors.dangerSurface : AmialColors.warningSurface,
                  borderRadius: BorderRadius.circular(AmialSpacing.radiusXl),
                ),
                child: Text(out ? 'نفد' : 'قرب النفاد',
                    style: TextStyle(color: tone, fontWeight: FontWeight.w800)),
              ),
              const Spacer(),
              Expanded(
                child: Text('${p['name'] ?? '—'}',
                    textAlign: TextAlign.end,
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(fontWeight: FontWeight.w900)),
              ),
            ],
          ),
          const SizedBox(height: AmialSpacing.sm),
          Row(
            children: [
              _miniLabel(Icons.inventory_outlined,
                  'المتبقي ${_qty(remaining)} ${p['unit'] ?? 'وحدة'}', tone),
              const SizedBox(width: AmialSpacing.sm),
              _miniLabel(Icons.flag_outlined,
                  'الحد ${_qty(threshold)}', AmialColors.primary),
            ],
          ),
          const SizedBox(height: AmialSpacing.sm),
          Row(
            children: [
              Expanded(
                child: OutlinedButton.icon(
                  onPressed: () => _editThreshold(context, p),
                  icon: const Icon(Icons.edit_outlined),
                  label: const Text('تعديل الحد'),
                ),
              ),
              const SizedBox(width: AmialSpacing.sm),
              Expanded(
                child: FilledButton.icon(
                  style: FilledButton.styleFrom(backgroundColor: tone),
                  onPressed: () => _adjustStock(context, p),
                  icon: const Icon(Icons.inventory_2_outlined),
                  label: const Text('تعديل المخزون'),
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }

  Future<void> _editThreshold(
      BuildContext context, Map<String, dynamic> p) async {
    final ctrl = TextEditingController(text: '${p['low_stock_threshold'] ?? 10}');
    await showDialog<void>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        title: const Text('تعديل حد التنبيه'),
        content: _Field(ctrl, 'الحد الجديد', Icons.flag_outlined, number: true),
        actions: [
          TextButton(onPressed: () => Navigator.pop(dialogContext), child: const Text('إلغاء')),
          FilledButton(
            onPressed: () async {
              final v = int.tryParse(ctrl.text.trim());
              if (v == null || v < 0) return;
              final ok = await c.updateProduct((p['id'] as num).toInt(),
                  {'low_stock_threshold': v});
              // **وسياقٌ فرعيٌّ يُفحَص بنفسه.** `mounted` حالةُ الودجة، و`dialogContext` سياقُ ورقةٍ قد تُغلَق أثناء الانتظار — فـNavigator.pop عليه بعدها يرمي أو يُغلق الصفحةَ تحته.
              if (!mounted || !dialogContext.mounted) return;
              Navigator.pop(dialogContext);
              _snack(context, ok ? 'تم تحديث حد التنبيه' : c.lastError.value,
                  error: !ok);
              if (ok) await c.loadProducts(lowStockOnly: true);
            },
            child: const Text('حفظ'),
          ),
        ],
      ),
    );
  }

  Future<void> _adjustStock(BuildContext context, Map<String, dynamic> p) async {
    final stock = TextEditingController(text: '${p['current_stock'] ?? 0}');
    final reason = TextEditingController();
    await showDialog<void>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        title: Text('تعديل مخزون ${p['name'] ?? ''}'),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            _Field(stock, 'المخزون الجديد', Icons.inventory_outlined, number: true),
            _Field(reason, 'سبب التعديل *', Icons.notes_rounded),
          ],
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(dialogContext), child: const Text('إلغاء')),
          FilledButton(
            onPressed: () async {
              final v = _num(stock.text);
              if (v < 0 || reason.text.trim().isEmpty) return;
              final ok = await c.adjustStock((p['id'] as num).toInt(), v,
                  reason.text.trim());
              // **وسياقٌ فرعيٌّ يُفحَص بنفسه.** `mounted` حالةُ الودجة، و`dialogContext` سياقُ ورقةٍ قد تُغلَق أثناء الانتظار — فـNavigator.pop عليه بعدها يرمي أو يُغلق الصفحةَ تحته.
              if (!mounted || !dialogContext.mounted) return;
              Navigator.pop(dialogContext);
              _snack(context, ok ? 'تم تعديل المخزون' : c.lastError.value,
                  error: !ok);
              if (ok) await c.loadProducts(lowStockOnly: true);
            },
            child: const Text('تأكيد'),
          ),
        ],
      ),
    );
  }
}

/// شاشة الصلاحية جاهزة بصرياً لكنها لا تختلق تاريخاً غير موجود في عقد الجملة.
class WholesaleExpiryAlertsScreen extends StatefulWidget {
  const WholesaleExpiryAlertsScreen({super.key});

  @override
  State<WholesaleExpiryAlertsScreen> createState() =>
      _WholesaleExpiryAlertsScreenState();
}

class _WholesaleExpiryAlertsScreenState extends State<WholesaleExpiryAlertsScreen> {
  WholesaleController get c => Get.find<WholesaleController>();

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => c.loadProducts());
  }

  List<Map<String, dynamic>> get _withExpiry => c.products.where((p) {
        final v = p['expiry_date'] ?? p['expires_at'];
        return v != null && '$v'.trim().isNotEmpty;
      }).toList();

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(
        backgroundColor: AmialColors.background,
        elevation: 0,
        centerTitle: true,
        title: const Text('تنبيهات المنتجات المنتهية'),
      ),
      body: Obx(() {
        final state = _loadState(c, retry: c.loadProducts);
        if (state != null && c.products.isEmpty) return state;
        final rows = _withExpiry;
        if (rows.isEmpty) {
          return const _SurfaceState(
            icon: Icons.event_busy_outlined,
            title: 'بيانات الصلاحية غير متاحة',
            message:
                'لم يرسل خادم الجملة تاريخ صلاحية للمنتجات في القراءة الحالية، لذلك لن يعرض أميال أرقاماً أو تنبيهات وهمية.',
          );
        }
        final now = DateTime.now();
        int expired = 0;
        int week = 0;
        int month = 0;
        for (final p in rows) {
          final dt = DateTime.tryParse('${p['expiry_date'] ?? p['expires_at']}');
          if (dt == null) continue;
          final days = dt.difference(now).inDays;
          if (days < 0) {
            expired++;
          } else if (days <= 7) {
            week++;
          } else if (days <= 30) {
            month++;
          }
        }
        return RefreshIndicator(
          onRefresh: c.loadProducts,
          color: AmialColors.primary,
          child: ListView(
            physics: const AlwaysScrollableScrollPhysics(),
            padding: const EdgeInsets.all(AmialSpacing.screen),
            children: [
              Row(children: [
                Expanded(child: _MetricCard(icon: Icons.error_outline_rounded,
                    label: 'منتهية', value: '$expired', tone: AmialColors.danger,
                    surface: AmialColors.dangerSurface)),
                const SizedBox(width: AmialSpacing.xs),
                Expanded(child: _MetricCard(icon: Icons.schedule_rounded,
                    label: 'خلال 7 أيام', value: '$week', tone: AmialColors.warning,
                    surface: AmialColors.warningSurface)),
                const SizedBox(width: AmialSpacing.xs),
                Expanded(child: _MetricCard(icon: Icons.calendar_month_outlined,
                    label: 'خلال 30 يوم', value: '$month', tone: AmialColors.success,
                    surface: AmialColors.successSurface)),
              ]),
              const SizedBox(height: AmialSpacing.md),
              ...rows.map((p) => _expiryCard(p, now)),
            ],
          ),
        );
      }),
    );
  }

  Widget _expiryCard(Map<String, dynamic> p, DateTime now) {
    final raw = '${p['expiry_date'] ?? p['expires_at']}';
    final dt = DateTime.tryParse(raw);
    final days = dt == null ? null : dt.difference(now).inDays;
    final tone = days == null
        ? AmialColors.textMuted
        : days < 0
            ? AmialColors.danger
            : days <= 7
                ? AmialColors.warning
                : AmialColors.success;
    final label = days == null
        ? 'غير معروف'
        : days < 0
            ? 'منتهي منذ ${days.abs()} يوم'
            : 'ينتهي خلال $days يوم';
    return Container(
      margin: const EdgeInsets.only(bottom: AmialSpacing.sm),
      padding: const EdgeInsets.all(AmialSpacing.md),
      decoration: BoxDecoration(
        color: AmialColors.cardSurface,
        borderRadius: BorderRadius.circular(AmialSpacing.radiusLg),
        border: Border(right: BorderSide(color: tone, width: 4)),
      ),
      child: Row(children: [
        Icon(Icons.event_busy_outlined, color: tone),
        const SizedBox(width: AmialSpacing.sm),
        Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text('${p['name'] ?? '—'}', style: const TextStyle(fontWeight: FontWeight.w900)),
          Text('المخزون ${_qty(_num(p['current_stock']))} ${p['unit'] ?? 'وحدة'}',
              style: const TextStyle(color: AmialColors.textMuted, fontSize: 11)),
        ])),
        Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
          Text(label, style: TextStyle(color: tone, fontWeight: FontWeight.w800)),
          Text(_date(raw), style: const TextStyle(color: AmialColors.textMuted, fontSize: 10)),
        ]),
      ]),
    );
  }
}

// ---------------------------------------------------------------------------
// Entitlements / package behavior
// ---------------------------------------------------------------------------

class _CapabilityTile extends StatelessWidget {
  const _CapabilityTile({required this.action, required this.onTap});
  final _WholesaleAction action;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final state = _capabilityState(action.capability);
    final available = state == 'available';
    final locked = state == EntitlementsController.stLockedByPlan;
    return InkWell(
      borderRadius: BorderRadius.circular(AmialSpacing.radiusLg),
      onTap: onTap,
      child: Container(
        padding: const EdgeInsets.all(AmialSpacing.md),
        decoration: BoxDecoration(
          color: AmialColors.cardSurface,
          borderRadius: BorderRadius.circular(AmialSpacing.radiusLg),
          border: Border.all(
            color: available
                ? AmialColors.border
                : locked
                    ? AmialColors.warning
                    : AmialColors.border,
          ),
          boxShadow: AmialSpacing.cardShadow,
        ),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Icon(action.icon,
                color: available ? AmialColors.primary : AmialColors.textMuted,
                size: 28),
            const SizedBox(height: AmialSpacing.xs),
            Text(action.label,
                textAlign: TextAlign.center,
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
                style: const TextStyle(fontWeight: FontWeight.w800)),
            if (!available) ...[
              const SizedBox(height: AmialSpacing.xxs),
              Text(_capabilityBadge(action.capability),
                  textAlign: TextAlign.center,
                  style: TextStyle(
                    fontSize: 9.5,
                    fontWeight: FontWeight.w700,
                    color: locked ? AmialColors.warning : AmialColors.textMuted,
                  )),
            ],
          ],
        ),
      ),
    );
  }
}

class _WholesaleAction {
  const _WholesaleAction(this.label, this.icon, this.capability, this.onOpen);
  final String label;
  final IconData icon;
  final String capability;
  final VoidCallback onOpen;
}

String _capabilityState(String code) {
  try {
    final e = Get.find<EntitlementsController>();
    final row = e.stateOf(code);
    if (row != null) return '${row['state'] ?? 'unknown'}';
  } catch (_) {}
  try {
    if (Get.find<AccessController>().has(code)) return 'available';
  } catch (_) {}
  return 'unknown';
}

bool _isCapabilityAvailable(String code) => _capabilityState(code) == 'available';

String _capabilityBadge(String code) {
  try {
    final e = Get.find<EntitlementsController>();
    final row = e.stateOf(code);
    if (row != null) {
      final state = '${row['state'] ?? ''}';
      if (state == EntitlementsController.stLockedByPlan) {
        final u = row['unlock'] is Map ? row['unlock'] as Map : const {};
        return 'باقة ${u['plan_name'] ?? u['plan'] ?? 'أعلى'}';
      }
      if (state == EntitlementsController.stLockedByRole) return 'تحتاج صلاحية';
      if (state == EntitlementsController.stLimitReached) return 'بلغت الحد';
    }
  } catch (_) {}
  return 'غير معروف';
}

void _openCapability(BuildContext context, String code, VoidCallback onOpen,
    {bool keepHere = false}) {
  final state = _capabilityState(code);
  if (state == 'available') {
    onOpen();
    return;
  }
  try {
    final e = Get.find<EntitlementsController>();
    final row = e.stateOf(code);
    if (row != null) {
      final unlock = row['unlock'] is Map ? row['unlock'] as Map : const {};
      if (state == EntitlementsController.stLockedByPlan) {
        final suggested = '${unlock['plan_code'] ?? unlock['plan'] ?? ''}';
        showModalBottomSheet<void>(
          context: context,
          backgroundColor: AmialColors.cardSurface,
          shape: const RoundedRectangleBorder(
              borderRadius: BorderRadius.vertical(
                  top: Radius.circular(AmialSpacing.radiusXl))),
          builder: (_) => Padding(
            padding: const EdgeInsets.all(AmialSpacing.lg),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                const Icon(Icons.lock_outline_rounded,
                    color: AmialColors.warning, size: 42),
                const SizedBox(height: AmialSpacing.sm),
                Text('متاحة في باقة ${unlock['plan_name'] ?? 'أعلى'}',
                    textAlign: TextAlign.center,
                    style: const TextStyle(
                        fontSize: 18, fontWeight: FontWeight.w900)),
                const SizedBox(height: AmialSpacing.xs),
                const Text('يمكنك مقارنة الباقات قبل اتخاذ قرار الترقية.',
                    textAlign: TextAlign.center,
                    style: TextStyle(color: AmialColors.textSecondary)),
                const SizedBox(height: AmialSpacing.md),
                FilledButton(
                  onPressed: () {
                    Navigator.pop(context);
                    Get.to(() => PlansCatalogScreen(
                        suggestedPlan: suggested.isEmpty ? null : suggested));
                  },
                  child: const Text('مقارنة الباقات'),
                ),
              ],
            ),
          ),
        );
        return;
      }
      if (state == EntitlementsController.stLockedByRole) {
        _snack(context, 'هذه الخدمة تحتاج صلاحية من مالك المنشأة.', error: true);
        return;
      }
      if (state == EntitlementsController.stLimitReached) {
        final usage = row['usage'] is Map ? row['usage'] as Map : const {};
        _snack(context,
            'بلغت الحد: ${usage['used'] ?? '—'} من ${usage['max'] ?? '—'}.',
            error: true);
        return;
      }
    }
  } catch (_) {}
  if (!keepHere) {
    _snack(context, 'تعذر التحقق من توفر هذه الميزة حالياً.', error: true);
  }
}

class _CapabilityUnavailableSurface extends StatelessWidget {
  const _CapabilityUnavailableSurface({required this.capability});
  final String capability;

  @override
  Widget build(BuildContext context) {
    final badge = _capabilityBadge(capability);
    return _SurfaceState(
      icon: Icons.lock_outline_rounded,
      title: badge,
      message: badge.contains('باقة')
          ? 'هذه الخدمة مرتبطة بباقتك الحالية. راجع الباقات لمعرفة طريقة فتحها.'
          : 'هذه الخدمة غير متاحة لهذا الحساب في الوقت الحالي.',
      actionLabel: badge.contains('باقة') ? 'مقارنة الباقات' : null,
      onAction: badge.contains('باقة')
          ? () => Get.to(() => const PlansCatalogScreen())
          : null,
    );
  }
}

// ---------------------------------------------------------------------------
// Shared visual widgets
// ---------------------------------------------------------------------------

class _MetricCard extends StatelessWidget {
  const _MetricCard({
    this.width,
    required this.icon,
    required this.label,
    required this.value,
    required this.tone,
    required this.surface,
  });
  final double? width;
  final IconData icon;
  final String label;
  final String value;
  final Color tone;
  final Color surface;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: width,
      padding: const EdgeInsets.all(AmialSpacing.md),
      decoration: BoxDecoration(
        color: surface,
        borderRadius: BorderRadius.circular(AmialSpacing.radiusLg),
        border: Border.all(color: AmialColors.border),
        boxShadow: AmialSpacing.cardShadow,
      ),
      child: Row(
        children: [
          Container(
            width: 42,
            height: 42,
            decoration: BoxDecoration(
              color: tone.withValues(alpha: 0.08),
              borderRadius: BorderRadius.circular(AmialSpacing.radiusMd),
            ),
            child: Icon(icon, color: tone),
          ),
          const SizedBox(width: AmialSpacing.sm),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(value,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: TextStyle(
                        color: tone,
                        fontSize: 18,
                        fontWeight: FontWeight.w900)),
                Text(label,
                    style: const TextStyle(
                        color: AmialColors.textSecondary, fontSize: 11)),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _SearchBar extends StatelessWidget {
  const _SearchBar({
    required this.controller,
    required this.hint,
    required this.onSubmitted,
    required this.onClear,
  });
  final TextEditingController controller;
  final String hint;
  final ValueChanged<String> onSubmitted;
  final VoidCallback onClear;

  @override
  Widget build(BuildContext context) {
    return TextField(
      controller: controller,
      textInputAction: TextInputAction.search,
      onSubmitted: onSubmitted,
      decoration: InputDecoration(
        hintText: hint,
        prefixIcon: const Icon(Icons.search_rounded),
        suffixIcon: IconButton(
          onPressed: onClear,
          icon: const Icon(Icons.close_rounded),
        ),
        filled: true,
        fillColor: AmialColors.cardSurface,
        border: OutlineInputBorder(
          borderRadius: BorderRadius.circular(AmialSpacing.radiusLg),
          borderSide: const BorderSide(color: AmialColors.border),
        ),
        enabledBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(AmialSpacing.radiusLg),
          borderSide: const BorderSide(color: AmialColors.border),
        ),
      ),
    );
  }
}

class _Field extends StatelessWidget {
  const _Field(this.controller, this.label, this.icon,
      {this.number = false, this.maxLines = 1, this.enabled = true});
  final TextEditingController controller;
  final String label;
  final IconData icon;
  final bool number;
  final int maxLines;
  final bool enabled;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: AmialSpacing.sm),
      child: TextField(
        controller: controller,
        enabled: enabled,
        maxLines: maxLines,
        keyboardType: number
            ? const TextInputType.numberWithOptions(decimal: true)
            : TextInputType.text,
        decoration: InputDecoration(
          labelText: label,
          prefixIcon: Icon(icon, color: AmialColors.primary),
          filled: true,
          fillColor: enabled ? AmialColors.cardSurface : AmialColors.background,
          border: OutlineInputBorder(
            borderRadius: BorderRadius.circular(AmialSpacing.radiusMd),
          ),
        ),
      ),
    );
  }
}

class _SurfaceState extends StatelessWidget {
  const _SurfaceState({
    required this.icon,
    required this.title,
    required this.message,
    this.onRetry,
    this.actionLabel,
    this.onAction,
  });
  final IconData icon;
  final String title;
  final String message;
  final Future<void> Function()? onRetry;
  final String? actionLabel;
  final VoidCallback? onAction;

  @override
  Widget build(BuildContext context) {
    return Center(
      child: SingleChildScrollView(
        physics: const AlwaysScrollableScrollPhysics(),
        padding: const EdgeInsets.all(AmialSpacing.xl),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Icon(icon, size: 58, color: AmialColors.textMuted),
            const SizedBox(height: AmialSpacing.md),
            Text(title,
                textAlign: TextAlign.center,
                style: const TextStyle(
                    fontSize: 18,
                    fontWeight: FontWeight.w900,
                    color: AmialColors.textPrimary)),
            const SizedBox(height: AmialSpacing.xs),
            Text(message,
                textAlign: TextAlign.center,
                style: const TextStyle(color: AmialColors.textSecondary)),
            if (onRetry != null) ...[
              const SizedBox(height: AmialSpacing.md),
              OutlinedButton.icon(
                onPressed: () => onRetry!(),
                icon: const Icon(Icons.refresh_rounded),
                label: const Text('إعادة المحاولة'),
              ),
            ],
            if (onAction != null && actionLabel != null) ...[
              const SizedBox(height: AmialSpacing.sm),
              FilledButton(onPressed: onAction, child: Text(actionLabel!)),
            ],
          ],
        ),
      ),
    );
  }
}

Widget? _loadState(WholesaleController c, {Future<void> Function()? retry}) {
  if (c.isLoading.value && c.loadState.value == 'loading') {
    return const Center(child: CircularProgressIndicator());
  }
  switch (c.loadState.value) {
    case 'permission':
      return _SurfaceState(
        icon: Icons.lock_outline_rounded,
        title: 'لا تملك صلاحية الوصول',
        message: c.lastError.value.isEmpty
            ? 'اطلب الصلاحية من مالك المنشأة.'
            : c.lastError.value,
        onRetry: retry,
      );
    case 'offline':
      return _SurfaceState(
        icon: Icons.cloud_off_outlined,
        title: 'لا يوجد اتصال',
        message: c.lastError.value.isEmpty
            ? 'تحقق من اتصال الإنترنت ثم أعد المحاولة.'
            : c.lastError.value,
        onRetry: retry,
      );
    case 'maintenance':
      return _SurfaceState(
        icon: Icons.construction_outlined,
        title: 'الخدمة تحت الصيانة',
        message: c.lastError.value.isEmpty
            ? 'أعد المحاولة بعد قليل.'
            : c.lastError.value,
        onRetry: retry,
      );
    case 'error':
      return _SurfaceState(
        icon: Icons.error_outline_rounded,
        title: 'تعذر تحميل البيانات',
        message: c.lastError.value.isEmpty
            ? 'حدث خطأ غير متوقع.'
            : c.lastError.value,
        onRetry: retry,
      );
  }
  return null;
}

Widget _miniLabel(IconData icon, String text, Color tone) => Container(
      padding: const EdgeInsets.symmetric(
          horizontal: AmialSpacing.xs, vertical: AmialSpacing.xxs),
      decoration: BoxDecoration(
        color: tone.withValues(alpha: 0.08),
        borderRadius: BorderRadius.circular(AmialSpacing.radiusSm),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(icon, color: tone, size: 15),
          const SizedBox(width: AmialSpacing.xxs),
          Text(text,
              style: TextStyle(
                  color: tone, fontSize: 11, fontWeight: FontWeight.w700)),
        ],
      ),
    );

Widget _statusPill(String status) {
  final tone = _statusTone(status);
  final label = switch (status) {
    'paid' => 'مدفوعة',
    'partial_paid' => 'جزئية',
    'issued' => 'قيد السداد',
    'overdue' => 'متأخرة',
    'voided' => 'ملغاة',
    _ => status.isEmpty ? 'غير معروف' : status,
  };
  return Container(
    padding: const EdgeInsets.symmetric(
        horizontal: AmialSpacing.sm, vertical: AmialSpacing.xxs),
    decoration: BoxDecoration(
      color: tone.withValues(alpha: 0.08),
      borderRadius: BorderRadius.circular(AmialSpacing.radiusXl),
    ),
    child: Text(label,
        style: TextStyle(color: tone, fontWeight: FontWeight.w800, fontSize: 11)),
  );
}

Color _statusTone(String status) => switch (status) {
      'paid' => AmialColors.success,
      'partial_paid' => AmialColors.warning,
      'issued' => AmialColors.info,
      'overdue' => AmialColors.danger,
      'voided' => AmialColors.textMuted,
      _ => AmialColors.textMuted,
    };

String _planLabel(String code) => switch (code) {
      'starter' => 'STARTER',
      'business' => 'BUSINESS',
      'merchant_pro' => 'MERCHANT PRO',
      'enterprise' => 'ENTERPRISE',
      _ => 'FREE',
    };

double _num(dynamic value) {
  if (value is num) return value.toDouble();
  return double.tryParse('$value') ?? 0;
}

String _qty(double value) => value == value.roundToDouble()
    ? value.toInt().toString()
    : value.toStringAsFixed(2);

String _money(dynamic value) {
  final n = _num(value);
  final whole = n.round();
  final text = whole.abs().toString();
  final b = StringBuffer();
  for (var i = 0; i < text.length; i++) {
    if (i > 0 && (text.length - i) % 3 == 0) b.write(',');
    b.write(text[i]);
  }
  return '${n < 0 ? '-' : ''}${b.toString()}';
}

String _date(dynamic raw) {
  final text = '$raw';
  if (text.isEmpty || text == 'null') return 'غير معروف';
  final dt = DateTime.tryParse(text);
  if (dt == null) return text.split('T').first;
  return '${dt.year}-${dt.month.toString().padLeft(2, '0')}-${dt.day.toString().padLeft(2, '0')}';
}

String _pct(dynamic value) {
  final n = _num(value);
  return '${n.toStringAsFixed(1)}%';
}

void _snack(BuildContext context, String message, {bool error = false}) {
  ScaffoldMessenger.of(context).showSnackBar(SnackBar(
    content: Text(message.isEmpty ? 'تعذر إتمام العملية' : message),
    backgroundColor: error ? AmialColors.danger : AmialColors.success,
  ));
}
