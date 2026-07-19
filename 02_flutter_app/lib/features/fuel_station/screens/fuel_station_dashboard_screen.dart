import 'dart:async';
import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';
import 'package:amyal_pay/features/fuel_station/controllers/fuel_station_controller.dart';
import 'package:amyal_pay/features/fuel_station/screens/fuel_sale_screen.dart';
import 'package:amyal_pay/features/fuel_station/screens/fuel_settings_screen.dart';
import 'package:amyal_pay/features/fuel_station/screens/fuel_companies_screen.dart';
import 'package:amyal_pay/features/fuel_station/screens/fuel_shifts_screen.dart';
import 'package:amyal_pay/features/fuel_station/screens/fuel_sales_history_screen.dart';
import 'package:amyal_pay/features/fuel_station/screens/fuel_cashier_screen.dart';
import 'package:amyal_pay/features/merchant/screens/cashier_pos_screen.dart';
import 'package:amyal_pay/features/merchant/screens/profit_report_screen.dart';
import 'package:amyal_pay/features/merchant/screens/merchant_staff_screen.dart';
import 'package:amyal_pay/features/merchant/screens/merchant_audit_log_screen.dart';
import 'package:amyal_pay/features/merchant/screens/merchant_excel_export_screen.dart';
import 'package:amyal_pay/features/merchant/screens/receipt_settings_screen.dart';
import 'package:amyal_pay/features/branches/screens/branches_management_screen.dart';
import 'package:amyal_pay/features/access/controllers/access_controller.dart';
import 'package:amyal_pay/features/access/widgets/access_gate.dart';
import 'package:amyal_pay/features/plans/screens/plans_catalog_screen.dart';

/// AMIAL-FUEL-001 — لوحة محطة الوقود.
/// نقطة الدخول الرئيسية: تعرض إحصائيات اليوم + 4 أزرار رئيسية.
class FuelStationDashboardScreen extends StatefulWidget {
  const FuelStationDashboardScreen({super.key});

  @override
  State<FuelStationDashboardScreen> createState() => _FuelStationDashboardScreenState();
}

class _FuelStationDashboardScreenState extends State<FuelStationDashboardScreen> {
  late final FuelStationController c;
  Timer? _ticker; // مؤقّت مدّة المناوبة الحيّة

  @override
  void dispose() {
    _ticker?.cancel();
    super.dispose();
  }

  @override
  void initState() {
    super.initState();
    c = Get.find<FuelStationController>();
    // تحديث عدّاد مدّة المناوبة كل ثانية
    _ticker = Timer.periodic(const Duration(seconds: 1), (_) {
      if (mounted && c.currentShift.value != null) setState(() {});
    });
    WidgetsBinding.instance.addPostFrameCallback((_) async {
      await c.loadStation();
      await c.loadDashboard();
      await c.loadCurrentShift();
      // AMIAL-SUB-GATING: أعِد تحميل الصلاحيات كي تنعكس أي ترقية خطة فوراً
      try { await Get.find<AccessController>().load(); } catch (_) {}
    });
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmyalColors.background,
      appBar: AppBar(
        title: const Text('محطة الوقود'),
        backgroundColor: AmyalColors.primary,
        foregroundColor: Colors.white,
        actions: [
          IconButton(
            icon: const Icon(Icons.settings),
            onPressed: () => Get.to(() => const FuelSettingsScreen()),
            tooltip: 'الإعدادات',
          ),
        ],
      ),
      body: RefreshIndicator(
        onRefresh: () async {
          await c.loadStation();
          await c.loadDashboard();
          await c.loadCurrentShift();
          try { await Get.find<AccessController>().load(); } catch (_) {}
        },
        child: SingleChildScrollView(
          physics: const AlwaysScrollableScrollPhysics(),
          padding: const EdgeInsets.all(16),
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            // ====== بطاقة المحطة ======
            Obx(() {
              final station = c.station.value;
              return Container(
                padding: const EdgeInsets.all(18),
                decoration: BoxDecoration(
                  gradient: const LinearGradient(
                    colors: [AmyalColors.primary, Color(0xFF021A55)],
                    begin: Alignment.topLeft, end: Alignment.bottomRight,
                  ),
                  borderRadius: BorderRadius.circular(16),
                ),
                child: Row(children: [
                  Container(
                    width: 56, height: 56,
                    decoration: BoxDecoration(
                      color: AmyalColors.yellow,
                      borderRadius: BorderRadius.circular(14),
                    ),
                    child: const Icon(Icons.local_gas_station, color: Colors.black87, size: 32),
                  ),
                  const SizedBox(width: 14),
                  Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Text(station?['station_name'] ?? 'محطّتي',
                        style: const TextStyle(color: Colors.white, fontSize: 18, fontWeight: FontWeight.bold)),
                    if (station?['city'] != null)
                      Text('${station!['city']}',
                          style: const TextStyle(color: Colors.white70, fontSize: 13)),
                    // AMIAL-SUB-GATING: شارة الخطّة الحالية + ترقية
                    const SizedBox(height: 6),
                    _planBadge(),
                  ])),
                ]),
              );
            }),

            const SizedBox(height: 16),

            // ====== المناوبة الحالية (حيّة) ======
            Obx(() => _currentShiftCard()),

            // ====== إحصائيات اليوم ======
            Obx(() {
              final d = c.dashboardData.value?['today'] as Map?;
              return Container(
                padding: const EdgeInsets.all(16),
                decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(14)),
                child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                  Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
                    const Icon(Icons.today, color: AmyalColors.primary, size: 18),
                    const Text('إحصائيات اليوم',
                        style: TextStyle(fontWeight: FontWeight.bold, fontSize: 14)),
                  ]),
                  const SizedBox(height: 12),
                  Row(children: [
                    Expanded(child: _statBox(
                      'الإجمالي', '${_fmt(d?['total_amount'])} ر.ي',
                      AmyalColors.primary, Icons.attach_money,
                    )),
                    const SizedBox(width: 8),
                    Expanded(child: _statBox(
                      'اللترات', _fmt(d?['total_liters']),
                      AmyalColors.yellowDark, Icons.local_gas_station,
                    )),
                    const SizedBox(width: 8),
                    Expanded(child: _statBox(
                      'عمليات', '${d?['sales_count'] ?? 0}',
                      Colors.green.shade700, Icons.receipt_long,
                    )),
                  ]),
                  if (d?['by_payment'] != null && (d!['by_payment'] as List).isNotEmpty) ...[
                    const SizedBox(height: 12),
                    const Divider(),
                    const SizedBox(height: 8),
                    const Text('حسب طريقة الدفع', textAlign: TextAlign.right,
                        style: TextStyle(fontWeight: FontWeight.bold, fontSize: 13)),
                    const SizedBox(height: 6),
                    ...((d['by_payment'] as List).map((row) {
                      final m = row as Map;
                      final method = m['payment_method'] ?? '';
                      final label = method == 'cash' ? 'نقدي' :
                                    method == 'amial_pay' ? 'أميال باي' : 'حسابات الشركات';
                      return Padding(
                        padding: const EdgeInsets.symmetric(vertical: 3),
                        child: Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
                          Text('${_fmt(m['total'])} ر.ي (${m['c']})',
                              style: const TextStyle(fontWeight: FontWeight.bold)),
                          Text(label, style: TextStyle(color: Colors.grey.shade700)),
                        ]),
                      );
                    })),
                  ],
                ]),
              );
            }),

            const SizedBox(height: 20),

            // ====== الأزرار الكبيرة ======
            _bigAction(
              icon: Icons.point_of_sale,
              label: 'كاشير الوقود',
              subtitle: 'بيع سريع باللوحة الرقمية — بالريال أو باللتر',
              color: AmyalColors.primary,
              onTap: () => Get.to(() => const FuelCashierScreen()),
            ),
            const SizedBox(height: 10),
            _miniAction(
              Icons.receipt_long, 'بيع وقود (نموذج مفصّل)',
              () => Get.to(() => const FuelSaleScreen()),
            ),
            const SizedBox(height: 10),
            // AMIAL-SUB-GATING: كاشير متجر المحطة يتطلّب ميزة «المنتجات» (باقة
            // البداية فأعلى). على المجاني يظهر مقفلاً مع دعوة للترقية — وبمجرد
            // ترقية الأدمن للخطة يُفتح تلقائياً (يقرأ الميزات من /me/access).
            AccessGate(
              feature: 'products',
              fallback: _lockedAction(
                icon: Icons.storefront,
                label: 'كاشير المتجر',
                subtitle: 'يتطلّب ترقية الباقة (البداية فأعلى)',
              ),
              child: _bigAction(
                icon: Icons.storefront,
                label: 'كاشير المتجر',
                subtitle: 'بيع سلع المحطة (زيوت، إضافات، مقتنيات)',
                color: AmyalColors.yellowDark,
                onTap: () => Get.to(() => const CashierPosScreen()),
              ),
            ),
            const SizedBox(height: 10),
            // 4 اختصارات في صفّين
            Row(children: [
              Expanded(child: _miniAction(
                Icons.event_note, 'النوبات', () => Get.to(() => const FuelShiftsScreen()),
              )),
              const SizedBox(width: 8),
              Expanded(child: _miniAction(
                Icons.business, 'الشركات', () => Get.to(() => const FuelCompaniesScreen()),
              )),
            ]),
            const SizedBox(height: 8),
            Row(children: [
              Expanded(child: _miniAction(
                Icons.history, 'سجل المبيعات', () => Get.to(() => const FuelSalesHistoryScreen()),
              )),
              const SizedBox(width: 8),
              Expanded(child: _miniAction(
                Icons.tune, 'الإعدادات', () => Get.to(() => const FuelSettingsScreen()),
              )),
            ]),
            const SizedBox(height: 8),
            Row(children: [
              Expanded(child: _miniAction(
                Icons.receipt_long, 'إعدادات الفاتورة', () => Get.to(() => const ReceiptSettingsScreen()),
              )),
              const SizedBox(width: 8),
              const Expanded(child: SizedBox()),
            ]),
            const SizedBox(height: 18),

            // ====== ميزات متقدّمة (حسب باقتك) ======
            // AMIAL-SUB-GATING: تظهر مقفلة على المجاني ومفتوحة فور ترقية الأدمن
            // للخطة — دليل مرئي مباشر أن الاشتراك يفتح الميزات في التطبيق.
            _advancedFeatures(),
          ]),
        ),
      ),
    );
  }

  String _fmt(dynamic value) {
    if (value == null) return '0';
    final n = double.tryParse(value.toString()) ?? 0;
    return n.toStringAsFixed(n == n.roundToDouble() ? 0 : 2);
  }

  // ====== المناوبة الحالية (تصميم #105/#106) ======
  Widget _currentShiftCard() {
    final shift = c.currentShift.value;
    final today = c.dashboardData.value?['today'] as Map?;

    // لا مناوبة مفتوحة → دعوة لفتح مناوبة
    if (shift == null) {
      return Padding(
        padding: const EdgeInsets.only(bottom: 16),
        child: InkWell(
          borderRadius: BorderRadius.circular(14),
          onTap: () => Get.to(() => const FuelShiftsScreen()),
          child: Container(
            padding: const EdgeInsets.all(16),
            decoration: BoxDecoration(
              color: Colors.white,
              borderRadius: BorderRadius.circular(14),
              border: Border.all(color: AmyalColors.border),
            ),
            child: Row(children: [
              const Icon(Icons.play_circle_outline, color: AmyalColors.primary, size: 28),
              const SizedBox(width: 12),
              const Expanded(child: Text('لا توجد مناوبة مفتوحة — افتح مناوبة لبدء العمل',
                  style: TextStyle(fontWeight: FontWeight.w600))),
              const Icon(Icons.chevron_left, color: AmyalColors.textMuted),
            ]),
          ),
        ),
      );
    }

    // مناوبة مفتوحة → بطاقة حيّة
    final openedAt = DateTime.tryParse('${shift['opened_at'] ?? ''}')?.toLocal();
    final dur = openedAt == null ? Duration.zero : DateTime.now().difference(openedAt);
    final durStr = _fmtDuration(dur);
    final openingCash = double.tryParse('${shift['opening_cash'] ?? 0}') ?? 0;
    final todayCash = double.tryParse('${(today?['by_payment'] as List?)?.firstWhere(
        (m) => (m as Map)['payment_method'] == 'cash', orElse: () => {'total': 0})['total'] ?? 0}') ?? 0;
    final expectedCash = openingCash + todayCash;
    final liters = _fmt(today?['total_liters']);

    return Padding(
      padding: const EdgeInsets.only(bottom: 16),
      child: Container(
        padding: const EdgeInsets.all(16),
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(16),
          boxShadow: [BoxShadow(color: Colors.black.withValues(alpha: 0.05), blurRadius: 10)],
        ),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Row(children: [
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
              decoration: BoxDecoration(
                color: Colors.green.withValues(alpha: 0.15),
                borderRadius: BorderRadius.circular(20),
              ),
              child: Row(mainAxisSize: MainAxisSize.min, children: [
                Container(width: 7, height: 7, decoration: const BoxDecoration(
                    color: Colors.green, shape: BoxShape.circle)),
                const SizedBox(width: 5),
                const Text('مباشر الآن', style: TextStyle(fontSize: 11, color: Colors.green, fontWeight: FontWeight.bold)),
              ]),
            ),
            const Spacer(),
            const Text('المناوبة الحالية', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 15)),
          ]),
          const SizedBox(height: 14),
          // العدّاد الحيّ
          Center(child: Column(children: [
            Text(durStr, style: const TextStyle(fontSize: 32, fontWeight: FontWeight.bold,
                color: AmyalColors.primary, fontFeatures: [FontFeature.tabularFigures()])),
            const Text('مدّة المناوبة (ساعة : دقيقة : ثانية)',
                style: TextStyle(fontSize: 11, color: AmyalColors.textMuted)),
          ])),
          const SizedBox(height: 14),
          Row(children: [
            Expanded(child: _shiftStat('النقد المتوقّع', '${_fmt(expectedCash)} ر.ي', Colors.green.shade700)),
            const SizedBox(width: 8),
            Expanded(child: _shiftStat('مبيعات العدّادات', '$liters لتر', AmyalColors.primary)),
          ]),
          const SizedBox(height: 12),
          FilledButton.icon(
            onPressed: () => Get.to(() => const FuelShiftsScreen()),
            icon: const Icon(Icons.lock_outline, size: 18),
            label: const Text('إغلاق وتسوية المناوبة'),
            style: FilledButton.styleFrom(
              backgroundColor: AmyalColors.yellowDark,
              foregroundColor: Colors.black87,
              minimumSize: const Size.fromHeight(46),
            ),
          ),
        ]),
      ),
    );
  }

  Widget _shiftStat(String label, String value, Color color) {
    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.08),
        borderRadius: BorderRadius.circular(10),
      ),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Text(label, style: const TextStyle(fontSize: 11, color: AmyalColors.textSecondary)),
        const SizedBox(height: 4),
        Text(value, style: TextStyle(fontWeight: FontWeight.bold, fontSize: 15, color: color)),
      ]),
    );
  }

  String _fmtDuration(Duration d) {
    String two(int n) => n.toString().padLeft(2, '0');
    return '${two(d.inHours)}:${two(d.inMinutes % 60)}:${two(d.inSeconds % 60)}';
  }

  Widget _statBox(String label, String value, Color color, IconData icon) {
    return Container(
      padding: const EdgeInsets.all(10),
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.1),
        borderRadius: BorderRadius.circular(10),
      ),
      child: Column(children: [
        Icon(icon, color: color, size: 20),
        const SizedBox(height: 4),
        Text(value, style: TextStyle(color: color, fontWeight: FontWeight.bold, fontSize: 14),
            textAlign: TextAlign.center, overflow: TextOverflow.ellipsis),
        const SizedBox(height: 2),
        Text(label, style: TextStyle(color: Colors.grey.shade700, fontSize: 10)),
      ]),
    );
  }

  // AMIAL-SUB-GATING: شارة الخطّة الحالية — تفتح كتالوج الباقات للترقية
  Widget _planBadge() {
    final access = Get.find<AccessController>();
    return Obx(() {
      final label = access.subscriptionPlanLabel.value ?? 'مجاني';
      final isFree = access.subscriptionPlan.value == 'free';
      return InkWell(
        onTap: () => Get.to(() => const PlansCatalogScreen()),
        borderRadius: BorderRadius.circular(20),
        child: Container(
          padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
          decoration: BoxDecoration(
            color: isFree ? Colors.white.withValues(alpha: 0.15) : AmyalColors.yellow,
            borderRadius: BorderRadius.circular(20),
          ),
          child: Row(mainAxisSize: MainAxisSize.min, children: [
            Icon(isFree ? Icons.lock_open_outlined : Icons.workspace_premium,
                size: 13, color: isFree ? Colors.white : Colors.black87),
            const SizedBox(width: 4),
            Text('باقتك: $label${isFree ? ' • ترقية' : ''}',
                style: TextStyle(
                    fontSize: 11, fontWeight: FontWeight.bold,
                    color: isFree ? Colors.white : Colors.black87)),
          ]),
        ),
      );
    });
  }

  // ====== ميزات متقدّمة (حسب باقتك) ======
  Widget _advancedFeatures() {
    return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      const Text('ميزات متقدّمة (حسب باقتك)',
          style: TextStyle(fontSize: 15, fontWeight: FontWeight.bold)),
      const SizedBox(height: 4),
      const Text('تُفتح تلقائياً عند ترقية باقتك من لوحة الإدارة',
          style: TextStyle(fontSize: 11, color: AmyalColors.textMuted)),
      const SizedBox(height: 10),
      Row(children: [
        Expanded(child: _featTile(
          icon: Icons.trending_up, label: 'تقارير الأرباح', feature: 'profit_reports',
          planLabel: 'الأعمال', onOpen: () => Get.to(() => const ProfitReportScreen()),
        )),
        const SizedBox(width: 8),
        Expanded(child: _featTile(
          icon: Icons.account_tree, label: 'الفروع', feature: 'branches',
          planLabel: 'التاجر برو', onOpen: () => Get.to(() => const BranchesManagementScreen()),
        )),
      ]),
      const SizedBox(height: 8),
      Row(children: [
        Expanded(child: _featTile(
          icon: Icons.inventory_2, label: 'المخزون', feature: 'inventory',
          planLabel: 'البداية', onOpen: () => Get.to(() => const CashierPosScreen()),
        )),
        const SizedBox(width: 8),
        Expanded(child: _featTile(
          icon: Icons.credit_card, label: 'بطاقات آجل', feature: 'fuel_cards',
          planLabel: 'الأعمال', onOpen: () => Get.to(() => const FuelCompaniesScreen()),
        )),
      ]),
      const SizedBox(height: 8),
      Row(children: [
        Expanded(child: _featTile(
          icon: Icons.badge, label: 'الموظفون', feature: 'employees',
          planLabel: 'الأعمال', onOpen: () => Get.to(() => const MerchantStaffScreen()),
        )),
        const SizedBox(width: 8),
        Expanded(child: _featTile(
          icon: Icons.grid_on, label: 'تصدير Excel', feature: 'excel_export',
          planLabel: 'الأعمال', onOpen: () => Get.to(() => const MerchantExcelExportScreen()),
        )),
      ]),
      const SizedBox(height: 8),
      Row(children: [
        Expanded(child: _featTile(
          icon: Icons.fact_check, label: 'سجلّ التدقيق', feature: 'audit_log',
          planLabel: 'التاجر برو', onOpen: () => Get.to(() => const MerchantAuditLogScreen()),
        )),
        const SizedBox(width: 8),
        const Expanded(child: SizedBox()),
      ]),
    ]);
  }

  /// بطاقة ميزة: مفتوحة (تفتح شاشتها) أو مقفلة (قفل + دعوة ترقية).
  Widget _featTile({
    required IconData icon, required String label, required String feature,
    required String planLabel, required VoidCallback onOpen,
  }) {
    Widget tile({required bool locked}) => InkWell(
          borderRadius: BorderRadius.circular(12),
          onTap: locked ? () => Get.to(() => const PlansCatalogScreen()) : onOpen,
          child: Container(
            padding: const EdgeInsets.symmetric(vertical: 14, horizontal: 10),
            decoration: BoxDecoration(
              color: locked ? const Color(0xFFEDEFF3) : Colors.white,
              borderRadius: BorderRadius.circular(12),
              border: Border.all(
                  color: locked ? AmyalColors.border : AmyalColors.primary.withValues(alpha: 0.35)),
            ),
            child: Column(children: [
              Stack(alignment: Alignment.topRight, children: [
                Icon(icon, size: 26,
                    color: locked ? Colors.grey.shade500 : AmyalColors.primary),
                if (locked)
                  const Icon(Icons.lock, size: 13, color: AmyalColors.textMuted),
              ]),
              const SizedBox(height: 6),
              Text(label,
                  textAlign: TextAlign.center,
                  style: TextStyle(
                      fontSize: 12.5, fontWeight: FontWeight.w600,
                      color: locked ? Colors.grey.shade700 : AmyalColors.textPrimary)),
              if (locked) ...[
                const SizedBox(height: 2),
                Text('باقة $planLabel',
                    style: const TextStyle(fontSize: 10, color: AmyalColors.yellowDark, fontWeight: FontWeight.bold)),
              ],
            ]),
          ),
        );
    return AccessGate(
      feature: feature,
      child: tile(locked: false),
      fallback: tile(locked: true),
    );
  }

  // AMIAL-SUB-GATING: إجراء مقفل (خطّة أعلى) — يدعو للترقية بدل الفتح
  Widget _lockedAction({
    required IconData icon, required String label, required String subtitle,
  }) {
    return InkWell(
      borderRadius: BorderRadius.circular(14),
      onTap: () => Get.to(() => const PlansCatalogScreen()),
      child: Container(
        padding: const EdgeInsets.all(16),
        decoration: BoxDecoration(
          color: const Color(0xFFEDEFF3),
          borderRadius: BorderRadius.circular(14),
          border: Border.all(color: AmyalColors.border),
        ),
        child: Row(children: [
          Container(
            width: 48, height: 48,
            decoration: BoxDecoration(
              color: Colors.grey.shade300, borderRadius: BorderRadius.circular(12)),
            child: Icon(icon, color: Colors.grey.shade600, size: 26),
          ),
          const SizedBox(width: 12),
          Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Row(children: [
              Text(label, style: TextStyle(
                  color: Colors.grey.shade800, fontSize: 16, fontWeight: FontWeight.bold)),
              const SizedBox(width: 6),
              const Icon(Icons.lock, size: 15, color: AmyalColors.textMuted),
            ]),
            Text(subtitle, style: const TextStyle(color: AmyalColors.textMuted, fontSize: 12)),
          ])),
          const Icon(Icons.workspace_premium, color: AmyalColors.yellowDark),
        ]),
      ),
    );
  }

  Widget _bigAction({
    required IconData icon, required String label, required String subtitle,
    required Color color, required VoidCallback onTap,
  }) {
    return InkWell(
      borderRadius: BorderRadius.circular(14),
      onTap: onTap,
      child: Container(
        padding: const EdgeInsets.all(16),
        decoration: BoxDecoration(color: color, borderRadius: BorderRadius.circular(14)),
        child: Row(children: [
          Container(
            width: 48, height: 48,
            decoration: BoxDecoration(
              color: Colors.white.withValues(alpha: 0.2),
              borderRadius: BorderRadius.circular(12),
            ),
            child: Icon(icon, color: Colors.white, size: 28),
          ),
          const SizedBox(width: 12),
          Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(label, style: const TextStyle(color: Colors.white, fontSize: 17, fontWeight: FontWeight.bold)),
            Text(subtitle, style: const TextStyle(color: Colors.white70, fontSize: 12)),
          ])),
          const Icon(Icons.chevron_left, color: Colors.white),
        ]),
      ),
    );
  }

  Widget _miniAction(IconData icon, String label, VoidCallback onTap) {
    return InkWell(
      borderRadius: BorderRadius.circular(12),
      onTap: onTap,
      child: Container(
        padding: const EdgeInsets.symmetric(vertical: 16),
        decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(12)),
        child: Column(children: [
          Icon(icon, color: AmyalColors.primary, size: 24),
          const SizedBox(height: 6),
          Text(label, style: const TextStyle(fontWeight: FontWeight.bold)),
        ]),
      ),
    );
  }
}
