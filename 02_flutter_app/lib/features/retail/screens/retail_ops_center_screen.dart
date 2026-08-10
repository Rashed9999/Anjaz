import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amial_pay/common/widgets/vertical_state_view.dart';
import 'package:amial_pay/features/retail/controllers/retail_vertical_controller.dart';
import 'package:amial_pay/features/retail/screens/retail_catalog_screen.dart';
import 'package:amial_pay/features/retail/screens/retail_counts_screen.dart';
import 'package:amial_pay/features/retail/screens/retail_locations_screen.dart';
import 'package:amial_pay/features/retail/screens/retail_prices_screen.dart';
import 'package:amial_pay/features/retail/screens/retail_transfers_screen.dart';
import 'package:amial_pay/features/retail/screens/retail_wastes_screen.dart';
import 'package:amial_pay/theme/amial_colors.dart';

/// AMIAL-RETAIL-VERTICAL-001 · المرحلة ١٠ — **مركزُ عمليّات التجزئة**.
///
/// ══════════════════════════════════════════════════════════════════════
/// **والقائمةُ تُبنى من الصلاحيّات لا من نوع النشاط.** فمن لا يملك
/// «اعتماد الجرد» لا يرى بطاقتَه أصلاً — وزرٌّ يُعرض ثمّ يُرفض عند الضغط
/// أسوأ من غيابه: يَعِد ثمّ يُخلف.
///
/// **والحالةُ في نداءٍ واحد** (`/retail/ops`): شاشةٌ تفتح بستّة طلباتٍ
/// تُظهر أرقامَها واحداً بعد واحد، ويقرأ المستعملُ نصفَ الحقيقة ثانيتين.
class RetailOpsCenterScreen extends StatefulWidget {
  const RetailOpsCenterScreen({super.key});

  @override
  State<RetailOpsCenterScreen> createState() => _RetailOpsCenterScreenState();
}

class _RetailOpsCenterScreenState extends State<RetailOpsCenterScreen> {
  RetailVerticalController get c => Get.find<RetailVerticalController>();

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _refresh());
  }

  Future<void> _refresh() async {
    await c.loadPermissions();
    await c.loadOps();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(
        title: const Text('مركز التجزئة'),
        backgroundColor: AmialColors.background,
        elevation: 0,
        actions: [
          IconButton(
            onPressed: _refresh,
            icon: const Icon(Icons.refresh_rounded),
            tooltip: 'تحديث',
          ),
        ],
      ),
      body: RefreshIndicator(
        onRefresh: _refresh,
        child: Obx(() => VerticalStateView(
              c: c,
              isEmpty: c.ops.value == null,
              emptyTitle: 'لا بيانات تجزئة بعد',
              emptyHint: 'أضف موقعاً وأصنافاً لتبدأ.',
              emptyIcon: Icons.storefront_outlined,
              onRetry: _refresh,
              grantedBy: 'مالك المتجر أو المدير',
              child: ListView(
                padding: const EdgeInsets.all(16),
                children: [
                  _locationsStrip(),
                  const SizedBox(height: 16),
                  _pendingGrid(),
                  const SizedBox(height: 16),
                  _inTransit(),
                  const SizedBox(height: 16),
                  _lowStock(),
                  const SizedBox(height: 16),
                  _wasteSummary(),
                  const SizedBox(height: 24),
                  _sections(),
                  const SizedBox(height: 32),
                ],
              ),
            )),
      ),
    );
  }

  // ── المواقع ───────────────────────────────────────────────────────

  Widget _locationsStrip() {
    final locs = ((c.ops.value?['locations'] ?? []) as List)
        .map((e) => Map<String, dynamic>.from(e as Map))
        .toList();

    if (locs.isEmpty) {
      return _panel('المواقع', const Text(
        'لا مواقع — والمخزون بلا موقعٍ رقمٌ واحدٌ لكلّ الفروع',
        style: TextStyle(fontSize: 13, color: AmialColors.textMuted),
      ));
    }

    return _panel(
      'المواقع',
      Column(
        children: locs.map((l) {
          final never = (l['never_counted'] ?? 0) as int;
          return ListTile(
            dense: true,
            contentPadding: EdgeInsets.zero,
            leading: Icon(
              l['kind'] == 'warehouse' ? Icons.warehouse_outlined : Icons.store_outlined,
              color: AmialColors.primary,
            ),
            title: Text('${l['name']}',
                style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14)),
            subtitle: Text(
              '${l['products'] ?? 0} صنفاً'
              // **«لم يُجرَد» ليست «صفر فرق»** (القاعدة ٧).
              '${never > 0 ? ' · $never لم يُجرَد قطّ' : ''}',
              style: const TextStyle(fontSize: 12, color: AmialColors.textMuted),
            ),
            trailing: l['is_default'] == true
                ? const Text('الافتراضي',
                    style: TextStyle(fontSize: 11, color: AmialColors.textMuted))
                : null,
          );
        }).toList(),
      ),
    );
  }

  // ── ما ينتظر قراراً ───────────────────────────────────────────────

  Widget _pendingGrid() {
    final items = <List<dynamic>>[
      ['تحويلات للاعتماد', 'transfers_to_approve', Icons.fact_check_outlined,
        RetailVerticalController.pTransferApprove],
      ['تحويلات للاستلام', 'transfers_to_receive', Icons.local_shipping_outlined,
        RetailVerticalController.pTransferReceive],
      ['جرد للمراجعة', 'counts_in_review', Icons.rule_outlined,
        RetailVerticalController.pCountApprove],
      ['هالك ينتظر', 'wastes_pending', Icons.delete_outline,
        RetailVerticalController.pWasteApprove],
      ['مرتجعات تنتظر', 'returns_pending', Icons.assignment_return_outlined,
        RetailVerticalController.pReturnApprove],
      ['أسعار مقترَحة', 'prices_proposed', Icons.sell_outlined,
        RetailVerticalController.pPriceApprove],
    ].where((e) => c.can(e[3] as String)).toList();

    if (items.isEmpty) return const SizedBox.shrink();

    return _panel(
      'ينتظر قرارك',
      Wrap(
        spacing: 8,
        runSpacing: 8,
        children: items.map((e) {
          final n = c.pendingOf(e[1] as String);
          return Container(
            width: 150,
            padding: const EdgeInsets.all(12),
            decoration: BoxDecoration(
              color: n > 0 ? AmialColors.yellowLight.withValues(alpha: 0.35) : null,
              border: Border.all(color: AmialColors.border),
              borderRadius: BorderRadius.circular(10),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Icon(e[2] as IconData, size: 18, color: AmialColors.primary),
                const SizedBox(height: 8),
                Text('$n',
                    style: TextStyle(
                      fontSize: 22,
                      fontWeight: FontWeight.bold,
                      color: n > 0 ? AmialColors.yellowDark : AmialColors.textMuted,
                    )),
                Text(e[0] as String,
                    style: const TextStyle(fontSize: 11, color: AmialColors.textSecondary)),
              ],
            ),
          );
        }).toList(),
      ),
    );
  }

  // ── في الطريق ─────────────────────────────────────────────────────

  Widget _inTransit() {
    final rows = c.inTransit;
    if (rows.isEmpty) return const SizedBox.shrink();

    return _panel(
      'بضاعة في الطريق',
      Column(
        children: rows.map((t) {
          final days = t['days_in_transit'];
          return ListTile(
            dense: true,
            contentPadding: EdgeInsets.zero,
            leading: const Icon(Icons.local_shipping_outlined, color: Colors.orange),
            title: Text('${t['from']} ← ${t['to']}',
                style: const TextStyle(fontSize: 13, fontWeight: FontWeight.bold)),
            subtitle: Text(
              '${t['code']} · ${t['lines']} صنفاً'
              // **الأيّامُ في الطريق ليست تفصيلاً**: أسبوعٌ في السيّارة
              // هو ضياعٌ لم يُعلَن بعد.
              '${days != null ? ' · $days يوماً في الطريق' : ''}',
              style: const TextStyle(fontSize: 11, color: AmialColors.textMuted),
            ),
          );
        }).toList(),
      ),
    );
  }

  // ── تحت حدّ الطلب ─────────────────────────────────────────────────

  Widget _lowStock() {
    if (!c.can(RetailVerticalController.pStockView)) return const SizedBox.shrink();

    final rows = c.lowStock;
    if (rows.isEmpty) {
      return _panel('تحت حدّ الطلب', const Text(
        'لا صنف تحت حدّه — ومن لم يُضبَط له حدٌّ لا يُعدّ منخفضاً',
        style: TextStyle(fontSize: 12, color: AmialColors.textMuted),
      ));
    }

    return _panel(
      'تحت حدّ الطلب (${rows.length})',
      Column(
        children: rows.take(10).map((s) => ListTile(
              dense: true,
              contentPadding: EdgeInsets.zero,
              leading: const Icon(Icons.warning_amber_rounded, color: Colors.orange),
              title: Text('${s['product']}', style: const TextStyle(fontSize: 13)),
              subtitle: Text('${s['location']} · المتوفر ${s['on_hand']} / الحدّ ${s['reorder_level']}',
                  style: const TextStyle(fontSize: 11, color: AmialColors.textMuted)),
              trailing: s['suggested_order'] != null
                  ? Text('اطلب ${s['suggested_order']}',
                      style: const TextStyle(fontSize: 11, color: AmialColors.primary))
                  : null,
            )).toList(),
      ),
    );
  }

  Widget _wasteSummary() {
    if (!c.can(RetailVerticalController.pStockView)) return const SizedBox.shrink();

    final w = c.ops.value?['waste_30d'];
    if (w is! Map) return const SizedBox.shrink();

    final unknown = (w['unknown_cost_lines'] ?? 0) as int;

    return _panel(
      'الهالك — ٣٠ يوماً',
      Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text('${w['total_cost'] ?? '0'} ر.ي',
              style: const TextStyle(
                  fontSize: 20, fontWeight: FontWeight.bold, color: AmialColors.red)),
          const SizedBox(height: 4),
          Text('${w['approved_count'] ?? 0} معتمَد · ${w['pending_count'] ?? 0} ينتظر',
              style: const TextStyle(fontSize: 12, color: AmialColors.textMuted)),
          if (unknown > 0) ...[
            const SizedBox(height: 6),
            Text('$unknown سطراً بلا تكلفة مُدخَلة — غير محسوبة في المبلغ',
                style: const TextStyle(fontSize: 11, color: AmialColors.yellowDark)),
          ],
        ],
      ),
    );
  }

  // ── الأقسام — **كلٌّ خلف صلاحيّته** ────────────────────────────────

  Widget _sections() {
    final tiles = <Widget>[];

    void add(String perm, String title, String sub, IconData icon, Widget page) {
      if (!c.can(perm)) return;
      tiles.add(Card(
        color: AmialColors.cardSurface,
        margin: const EdgeInsets.only(bottom: 8),
        child: ListTile(
          leading: Icon(icon, color: AmialColors.primary),
          title: Text(title, style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14)),
          subtitle: Text(sub,
              style: const TextStyle(fontSize: 12, color: AmialColors.textMuted)),
          trailing: const Icon(Icons.chevron_left_rounded),
          onTap: () => Get.to(() => page),
        ),
      ));
    }

    add(RetailVerticalController.pProductView, 'الأصناف والتصنيفات',
        'التصنيفات والعلامات والوحدات والباركودات', Icons.category_outlined,
        const RetailCatalogScreen());

    add(RetailVerticalController.pStockView, 'المواقع والمستودعات',
        'مخزون كل موقع على حدة', Icons.warehouse_outlined,
        const RetailLocationsScreen());

    add(RetailVerticalController.pStockView, 'التحويلات',
        'طلب ← اعتماد ← إرسال ← استلام', Icons.swap_horiz_rounded,
        const RetailTransfersScreen());

    add(RetailVerticalController.pStockView, 'الجرد',
        'كامل ودوري وموضعي — بفروقٍ لها أسباب', Icons.rule_outlined,
        const RetailCountsScreen());

    add(RetailVerticalController.pStockView, 'الهالك',
        'تسجيل واعتماد — وتقرير بالسبب والقيمة', Icons.delete_outline,
        const RetailWastesScreen());

    add(RetailVerticalController.pPriceView, 'الأسعار',
        'نسخ بالسريان والاعتماد — لا كتابة فوق السعر', Icons.sell_outlined,
        const RetailPricesScreen());

    if (tiles.isEmpty) {
      return const Padding(
        padding: EdgeInsets.symmetric(vertical: 24),
        child: Text(
          'لا أقسام متاحة بصلاحيّاتك الحالية — اطلب من المالك منحك ما تحتاج',
          textAlign: TextAlign.center,
          style: TextStyle(fontSize: 13, color: AmialColors.textMuted),
        ),
      );
    }

    return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      const Text('الأقسام', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 15)),
      const SizedBox(height: 8),
      ...tiles,
    ]);
  }

  Widget _panel(String title, Widget body) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: AmialColors.cardSurface,
        borderRadius: BorderRadius.circular(12),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(title, style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14)),
          const SizedBox(height: 10),
          body,
        ],
      ),
    );
  }
}
