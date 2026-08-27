Warning: truncated output (original token count: 43155)
Total output lines: 4036

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
            tooltip: 'الوحدات والدفعات',
            onPressed: () => _inventorySheet(context, p),
            icon: const Icon(Icons.layers_outlined, color: AmialColors.success),
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

  /// إدارة تحويلات الوحدات والاستلام من المصدر الخادمي. لا يُخزَّن عامل
  /// التحويل أو الصلاحية داخل الهاتف، ولا تتحول شاشة الصلاحية إلى رقم صفر
  /// عند غياب دفعات حقيقية.
  Future<void> _inventorySheet(BuildContext context, Map<String, dynamic> product) async {
    final productId = (product['id'] as num).toInt();
    final results = await Future.wait([
      c.loadProductUnits(productId),
      c.loadProductLots(productId),
    ]);
    if (!context.mounted) return;
    final unitData = results[0];
    final lotData = results[1];
    if (unitData == null || lotData == null) {
      _snack(context, c.lastError.value.isEmpty ? 'تعذر تحميل بيانات المخزون' : c.lastError.value,
          error: true);
      return;
    }
    var units = (unitData['units'] as List? ?? const [])
        .map((e) => Map<String, dynamic>.from(e as Map)).toList();
    var lots = (lotData['lots'] as List? ?? const [])
        .map((e) => Map<String, dynamic>.from(e as Map)).toList();

    await showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      backgroundColor: AmialColors.cardSurface,
      shape: const RoundedRectangleBorder(
          borderRadius: BorderRadius.vertical(top: Radius.circular(AmialSpacing.radiusXl))),
      builder: (sheetContext) => StatefulBuilder(builder: (_, setSheet) => SizedBox(
        height: MediaQuery.sizeOf(sheetContext).height * .78,
        child: DefaultTabController(
          length: 2,
          child: Column(children: [
            const SizedBox(height: AmialSpacing.sm),
            Row(children: [
              IconButton(onPressed: () => Navigator.pop(sheetContext), icon: const Icon(Icons.close_rounded)),
              Expanded(child: Text('مخزون ${product['name']}', textAlign: TextAlign.center,
                  style: const TextStyle(fontWeight: FontWeight.w900, fontSize: 18))),
              const SizedBox(width: 48),
            ]),
            const TabBar(tabs: [Tab(text: 'وحدات البيع'), Tab(text: 'دفعات وصلاحية')]),
            Expanded(child: TabBarView(children: [
              ListView(padding: const EdgeInsets.all(AmialSpacing.md), children: [
                const Text('عامل التحويل إلى وحدة الأساس. الفاتورة والمخزون يحسبان في الخادم بهذه القيمة.',
                    style: TextStyle(color: AmialColors.textSecondary, height: 1.5)),
                const SizedBox(height: AmialSpacing.sm),
                ...units.map((u) => ListTile(
                  leading: Icon(u['is_base'] == true ? Icons.straighten_rounded : Icons.layers_outlined,
                      color: u['is_base'] == true ? AmialColors.primary : AmialColors.success),
                  title: Text('${u['name']}'),
                  subtitle: Text('عامل التحويل: ${_qty(_num(u['factor_to_base']))}'),
                  trailing: u['is_base'] == true ? const Text('أساس') : null,
                )),
                OutlinedButton.icon(
                  icon: const Icon(Icons.add_rounded), label: const Text('إضافة وحدة بيع'),
                  onPressed: () async {
                    final code = TextEditingController();
                    final name = TextEditingController();
                    final factor = TextEditingController();
                    final ok = await showDialog<bool>(context: sheetContext, builder: (d) => AlertDialog(
                      title: const Text('وحدة بيع جديدة'),
                      content: Column(mainAxisSize: MainAxisSize.min, children: [
                        _Field(code, 'رمز مختصر مثل carton', Icons.code_rounded),
                        _Field(name, 'الاسم مثل كرتون', Icons.inventory_2_outlined),
                        _Field(factor, 'عدد وحدات الأساس فيها', Icons.numbers_rounded, number: true),
                      ]),
                      actions: [TextButton(onPressed: () => Navigator.pop(d, false), child: const Text('إلغاء')),
                        FilledButton(onPressed: () => Navigator.pop(d, true), child: const Text('حفظ'))],
                    ));
                    if (ok == true) {
                      final saved = await c.saveProductUnit(productId, {'code': code.text.trim(), 'name': name.text.trim(), 'factor_to_base': factor.text.trim()});
                      if (!sheetContext.mounted) return;
                      if (!saved) { _snack(context, c.lastError.value, error: true); return; }
                      final refreshed = await c.loadProductUnits(productId);
                      if (refreshed != null && sheetContext.mounted) setSheet(() => units = (refreshed['units'] as List).map((e) => Map<String, dynamic>.from(e as Map)).toList());
                    }
                    code.dispose(); name.dispose(); factor.dispose();
                  },
                ),
              ]),
              ListView(padding: const EdgeInsets.all(AmialSpacing.md), children: [
                const Text('تُسحب الدفعات الصالحة بالأقدم انتهاءً أولاً. الدفعة المنتهية أو المحجوزة لا تدخل الفاتورة.',
                    style: TextStyle(color: AmialColors.textSecondary, height: 1.5)),
                const SizedBox(height: AmialSpacing.sm),
                ...lots.map((lot) => ListTile(
                  leading: const Icon(Icons.medication_liquid_outlined, color: AmialColors.primary),
                  title: Text('دفعة ${lot['lot_number']}'),
                  subtitle: Text('المتاح ${_qty(_num(lot['quantity_available']))} • الصلاحية ${lot['expiry_date'] ?? 'غير محددة'}'),
                  trailing: Text('${lot['status'] ?? ''}'),
                )),
                FilledButton.icon(
                  icon: const Icon(Icons.add_box_outlined), label: const Text('استلام دفعة'),
                  onPressed: () async {
                    final lot = TextEditingController(); final qty = TextEditingController();
                    final expiry = TextEditingController(); final supplier = TextEditingController();
                    var selectedUnit = units.firstWhere((u) => u['is_base'] == true, orElse: () => units.first);
                    final ok = await showDialog<bool>(context: sheetContext, builder: (d) => StatefulBuilder(builder: (_, setDialog) => AlertDialog(
                      title: const Text('استلام دفعة'), content: SingleChildScrollView(child: Column(mainAxisSize: MainAxisSize.min, children: [
                        _Field(lot, 'رقم الدفعة *', Icons.confirmation_number_outlined),
                        DropdownButtonFormField<int>(value: (selectedUnit['id'] as num).toInt(), decoration: const InputDecoration(labelText: 'الوحدة'),
                          items: units.map((u) => DropdownMenuItem<int>(value: (u['id'] as num).toInt(), child: Text('${u['name']}'))).toList(),
                          onChanged: (id) => setDialog(() => selectedUnit = units.firstWhere((u) => (u['id'] as num).toInt() == id))),
                        _Field(qty, 'الكمية *', Icons.numbers_rounded, number: true),
                        _Field(expiry, 'الصلاحية YYYY-MM-DD (اختياري)', Icons.event_outlined),
                        _Field(supplier, 'مرجع المورد/الفاتورة', Icons.receipt_long_outlined),
                      ])), actions: [TextButton(onPressed: () => Navigator.pop(d, false), child: const Text('إلغاء')),
                        FilledButton(onPressed: () => Navigator.pop(d, true), child: const Text('استلام'))],
                    )));
                    if (ok == true) {
                      final received = await c.receiveProductLot(productId, {'lot_number': lot.text.trim(), 'quantity': qty.text.trim(), 'unit_id': selectedUnit['id'], if (expiry.text.trim().isNotEmpty) 'expiry_date': expiry.text.trim(), if (supplier.text.trim().isNotEmpty) 'supplier_reference': supplier.text.trim()});
                      if (!sheetContext.mounted) return;
                      if (!received) { _snack(context, c.lastError.value, error: true); return; }
                      final refreshed = await c.loadProductLots(productId);
                      if (refreshed != null && sheetContext.mounted) setSheet(() => lots = (refreshed['lots'] as List).map((e) => Map<String, dynamic>.from(e as Map)).toList());
                    }
                    lot.dispose(); qty.dispose(); expiry.dispose(); supplier.dispose();
                  },
                ),
              ]),
            ])),
          ]),
        ),
      )),
    );
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
                  spacing:…23155 tokens truncated…        const _SurfaceState(icon: Icons.group_off_outlined, title: 'لا يوجد مندوبون نشطون',
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
      'business' => 'الأعمال',
      'enterprise' => 'مؤسسة',
      _ => 'مجاني',
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
