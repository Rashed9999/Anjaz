import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amial_pay/features/merchant/controllers/cashier_controller.dart';
import 'package:amial_pay/features/merchant/screens/product_editor_screen.dart';
import 'package:amial_pay/features/merchant/screens/stock_alerts_screen.dart';
import 'package:amial_pay/features/merchant/screens/inventory_audit_screen.dart';
import 'package:amial_pay/helper/amial_money.dart';
import 'package:amial_pay/theme/amial_colors.dart';

/// AMIAL-INVENTORY-001 — «إدارة المخزون» (التصميم 42/54):
/// ملخص (إجمالي المنتجات/قيمة المخزون/تنبيهات النقص) + بحث + تصنيفات +
/// بطاقات منتجات بشارة حالة المخزون (متوفر/بقي N فقط/نفد) + تعديل/تعطيل.
class InventoryScreen extends StatefulWidget {
  const InventoryScreen({super.key});

  @override
  State<InventoryScreen> createState() => _InventoryScreenState();
}

class _InventoryScreenState extends State<InventoryScreen> {
  CashierController get c => Get.find<CashierController>();
  final _search = TextEditingController();
  String _category = 'الكل';

  /// حدّ «مخزون منخفض».
  static const _lowStock = 5;

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

  double _qty(Map<String, dynamic> p) =>
      double.tryParse('${p['quantity'] ?? 0}') ?? 0;
  double _price(Map<String, dynamic> p) =>
      double.tryParse('${p['effective_price'] ?? p['price'] ?? 0}') ?? 0;

  List<String> get _categories {
    final set = <String>{};
    for (final p in c.products) {
      final cat = '${p['category'] ?? ''}'.trim();
      if (cat.isNotEmpty) set.add(cat);
    }
    return ['الكل', ...set];
  }

  List<Map<String, dynamic>> get _visible {
    final q = _search.text.trim();
    return c.products.where((p) {
      if (_category != 'الكل' && '${p['category'] ?? ''}' != _category) {
        return false;
      }
      if (q.isNotEmpty &&
          !'${p['name']}'.contains(q) &&
          !'${p['barcode'] ?? ''}'.contains(q)) {
        return false;
      }
      return true;
    }).toList();
  }

  (String, Color, Color) _stockBadge(double qty) {
    if (qty <= 0) return ('نفد المخزون', Colors.white, const Color(0xFF5F6B7C));
    if (qty < _lowStock) {
      return ('بقي ${qty.toStringAsFixed(0)} فقط', const Color(0xFFDC0A0B),
          const Color(0xFFFDE7E7));
    }
    return ('متوفر', const Color(0xFF2E7D32), const Color(0xFFE3F3E5));
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(
        title: const Text('إدارة المخزون'),
        actions: [
          // AMIAL-AUDIT-001: جرد المخزون
          IconButton(
            tooltip: 'تدقيق المخزون (جرد)',
            icon: const Icon(Icons.checklist_rounded),
            onPressed: () async {
              await Get.to(() => const InventoryAuditScreen());
              c.loadProducts();
            },
          ),
        ],
      ),
      floatingActionButton: FloatingActionButton.extended(
        heroTag: 'inv-add',
        backgroundColor: AmialColors.primary,
        foregroundColor: Colors.white,
        onPressed: () async {
          final saved =
              await Get.to<bool>(() => const ProductEditorScreen());
          if (saved == true) c.loadProducts();
        },
        icon: const Icon(Icons.add),
        label: const Text('إضافة منتج'),
      ),
      body: Obx(() {
        final items = _visible;
        double totalValue = 0;
        int lowCount = 0;
        for (final p in c.products) {
          totalValue += _qty(p) * _price(p);
          if (_qty(p) < _lowStock) lowCount++;
        }

        return RefreshIndicator(
          onRefresh: () => c.loadProducts(),
          color: AmialColors.primary,
          child: CustomScrollView(slivers: [
            SliverToBoxAdapter(
              child: Padding(
                padding: const EdgeInsets.all(16),
                child: Column(children: [
                  // ====== الملخّص ======
                  Row(children: [
                    Expanded(
                      child: _statCard('إجمالي المنتجات',
                          '${c.products.length}', AmialColors.primary,
                          Icons.inventory_2_outlined),
                    ),
                    const SizedBox(width: 10),
                    Expanded(
                      child: _statCard('قيمة المخزون',
                          AmialMoney.yer(totalValue), AmialColors.yellowDark,
                          Icons.payments_outlined),
                    ),
                    const SizedBox(width: 10),
                    Expanded(
                      child: InkWell(
                        // AMIAL-STOCK-ALERTS-001: يفتح شاشة التنبيهات والحدود
                        onTap: () =>
                            Get.to(() => const StockAlertsScreen()),
                        borderRadius: BorderRadius.circular(14),
                        child: _statCard('تنبيهات النقص', '$lowCount',
                            const Color(0xFFDC0A0B),
                            Icons.warning_amber_rounded),
                      ),
                    ),
                  ]),
                  const SizedBox(height: 14),

                  // ====== البحث ======
                  TextField(
                    controller: _search,
                    onChanged: (_) => setState(() {}),
                    decoration: InputDecoration(
                      hintText: 'بحث عن منتج أو باركود...',
                      hintStyle: const TextStyle(fontSize: 13),
                      prefixIcon: const Icon(Icons.search, size: 20),
                      filled: true,
                      fillColor: Colors.white,
                      contentPadding: EdgeInsets.zero,
                      border: OutlineInputBorder(
                        borderRadius: BorderRadius.circular(14),
                        borderSide: BorderSide.none,
                      ),
                    ),
                  ),
                  const SizedBox(height: 10),

                  // ====== التصنيفات ======
                  SizedBox(
                    height: 40,
                    child: ListView(
                      scrollDirection: Axis.horizontal,
                      children: _categories.map((cat) {
                        final selected = _category == cat;
                        return Padding(
                          padding: const EdgeInsets.only(left: 8),
                          child: ChoiceChip(
                            label: Text(cat,
                                style: const TextStyle(fontSize: 12)),
                            selected: selected,
                            selectedColor: AmialColors.primary,
                            backgroundColor: Colors.white,
                            labelStyle: TextStyle(
                                color: selected
                                    ? Colors.white
                                    : AmialColors.primary),
                            onSelected: (_) =>
                                setState(() => _category = cat),
                          ),
                        );
                      }).toList(),
                    ),
                  ),
                ]),
              ),
            ),

            // ====== المنتجات ======
            if (c.isLoadingProducts.value && c.products.isEmpty)
              const SliverFillRemaining(
                child: Center(
                    child:
                        CircularProgressIndicator(color: AmialColors.primary)),
              )
            else if (items.isEmpty)
              const SliverFillRemaining(
                hasScrollBody: false,
                child: Center(
                    child: Text('لا توجد منتجات — أضف أول منتج',
                        style: TextStyle(color: AmialColors.textMuted))),
              )
            else
              SliverPadding(
                padding: const EdgeInsets.fromLTRB(16, 0, 16, 90),
                sliver: SliverList.separated(
                  itemCount: items.length,
                  separatorBuilder: (_, _) => const SizedBox(height: 10),
                  itemBuilder: (_, i) => _productCard(items[i]),
                ),
              ),
          ]),
        );
      }),
    );
  }

  Widget _statCard(String label, String value, Color color, IconData icon) {
    return Container(
      padding: const EdgeInsets.symmetric(vertical: 12, horizontal: 8),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(14),
        // ملاحظة Flutter: حدّ جانبي واحد لا يجتمع مع زوايا دائرية
        border: Border.all(color: color.withValues(alpha: 0.35)),
      ),
      child: Column(children: [
        Icon(icon, color: color, size: 18),
        const SizedBox(height: 6),
        FittedBox(
          child: Text(value,
              style: TextStyle(
                  fontSize: 15, fontWeight: FontWeight.bold, color: color)),
        ),
        const SizedBox(height: 2),
        Text(label,
            style:
                const TextStyle(fontSize: 10, color: AmialColors.textMuted)),
      ]),
    );
  }

  Widget _productCard(Map<String, dynamic> p) {
    final qty = _qty(p);
    final (label, fg, bg) = _stockBadge(qty);
    final hasOffer = p['offer_price'] != null &&
        double.tryParse('${p['offer_price']}') != null &&
        (double.tryParse('${p['offer_price']}') ?? 0) > 0;
    final active = p['is_active'] != false && p['is_active'] != 0;

    return Opacity(
      opacity: active ? 1 : 0.5,
      child: Container(
        padding: const EdgeInsets.all(14),
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(14),
        ),
        child: Row(children: [
          // قائمة الإجراءات
          PopupMenuButton<String>(
            icon: const Icon(Icons.more_vert,
                size: 20, color: AmialColors.textMuted),
            onSelected: (v) async {
              if (v == 'edit') {
                final saved = await Get.to<bool>(
                    () => ProductEditorScreen(product: p));
                if (saved == true) c.loadProducts();
              } else if (v == 'toggle') {
                await c.updateProduct(
                    p['id'] as int, {'is_active': !active});
              }
            },
            itemBuilder: (_) => [
              const PopupMenuItem(value: 'edit', child: Text('تعديل')),
              PopupMenuItem(
                  value: 'toggle',
                  child: Text(active ? 'تعطيل' : 'تفعيل')),
            ],
          ),
          const SizedBox(width: 4),
          Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
              Text('${p['name']}',
                  style: const TextStyle(
                      fontWeight: FontWeight.bold, fontSize: 14)),
              const SizedBox(height: 2),
              Text('${p['category'] ?? ''}',
                  style: const TextStyle(
                      fontSize: 11, color: AmialColors.textMuted)),
              const SizedBox(height: 8),
              Row(mainAxisAlignment: MainAxisAlignment.end, children: [
                Container(
                  padding:
                      const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
                  decoration: BoxDecoration(
                    color: bg,
                    borderRadius: BorderRadius.circular(8),
                  ),
                  child: Text(label,
                      style: TextStyle(
                          fontSize: 10,
                          color: fg,
                          fontWeight: FontWeight.w600)),
                ),
                const SizedBox(width: 8),
                Text('المخزون: ${qty.toStringAsFixed(0)}',
                    style: const TextStyle(
                        fontSize: 11, color: AmialColors.textSecondary)),
                const Spacer(),
                if (hasOffer) ...[
                  Text(AmialMoney.fmt(p['price']),
                      style: const TextStyle(
                          fontSize: 11,
                          color: AmialColors.textMuted,
                          decoration: TextDecoration.lineThrough)),
                  const SizedBox(width: 6),
                ],
                Text(AmialMoney.yer(hasOffer ? p['offer_price'] : p['price']),
                    style: const TextStyle(
                        fontSize: 14,
                        fontWeight: FontWeight.bold,
                        color: AmialColors.primary)),
              ]),
            ]),
          ),
        ]),
      ),
    );
  }
}
