import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/features/merchant/controllers/cashier_controller.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';

/// AMIAL-CASHIER-001 — إدارة كتالوج المنتجات (اختياري).
/// يدعم: الكمية، التكلفة، سعر البيع، سعر العرض، تاريخ الإنتاج والانتهاء.
class CashierProductsScreen extends StatefulWidget {
  const CashierProductsScreen({super.key});

  @override
  State<CashierProductsScreen> createState() => _CashierProductsScreenState();
}

class _CashierProductsScreenState extends State<CashierProductsScreen> {
  CashierController get c => Get.find<CashierController>();

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => c.loadProducts());
  }

  Future<void> _addDialog() async {
    final name = TextEditingController();
    final price = TextEditingController();      // سعر البيع
    final cost = TextEditingController();        // التكلفة
    final offer = TextEditingController();        // العرض
    final qty = TextEditingController();          // الكمية
    final category = TextEditingController();
    DateTime? production;
    DateTime? expiry;

    Future<DateTime?> pick(DateTime? init) => showDatePicker(
          context: context,
          initialDate: init ?? DateTime.now(),
          firstDate: DateTime(2020),
          lastDate: DateTime(2040),
        );

    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setLocal) => AlertDialog(
          title: const Text('منتج جديد'),
          content: SingleChildScrollView(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                TextField(controller: name, decoration: const InputDecoration(labelText: 'الاسم *')),
                TextField(controller: price, keyboardType: const TextInputType.numberWithOptions(decimal: true), decoration: const InputDecoration(labelText: 'سعر البيع *')),
                TextField(controller: cost, keyboardType: const TextInputType.numberWithOptions(decimal: true), decoration: const InputDecoration(labelText: 'سعر التكلفة')),
                TextField(controller: offer, keyboardType: const TextInputType.numberWithOptions(decimal: true), decoration: const InputDecoration(labelText: 'سعر العرض (اختياري)')),
                TextField(controller: qty, keyboardType: const TextInputType.numberWithOptions(decimal: true), decoration: const InputDecoration(labelText: 'الكمية (المخزون)')),
                TextField(controller: category, decoration: const InputDecoration(labelText: 'التصنيف (اختياري)')),
                const SizedBox(height: 8),
                Row(children: [
                  Expanded(
                    child: TextButton.icon(
                      icon: const Icon(Icons.event, size: 18),
                      label: Text(production == null ? 'الإنتاج' : '${production!.year}-${production!.month}-${production!.day}'),
                      onPressed: () async {
                        final d = await pick(production);
                        if (d != null) setLocal(() => production = d);
                      },
                    ),
                  ),
                  Expanded(
                    child: TextButton.icon(
                      icon: const Icon(Icons.event_busy, size: 18),
                      label: Text(expiry == null ? 'الانتهاء' : '${expiry!.year}-${expiry!.month}-${expiry!.day}'),
                      onPressed: () async {
                        final d = await pick(expiry);
                        if (d != null) setLocal(() => expiry = d);
                      },
                    ),
                  ),
                ]),
              ],
            ),
          ),
          actions: [
            TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('إلغاء')),
            ElevatedButton(
              onPressed: () {
                if (name.text.trim().isEmpty || double.tryParse(price.text.trim()) == null) return;
                Navigator.pop(ctx, true);
              },
              child: const Text('حفظ'),
            ),
          ],
        ),
      ),
    );

    if (ok == true) {
      String? fmt(DateTime? d) => d == null ? null : '${d.year.toString().padLeft(4, '0')}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';
      final added = await c.addProduct({
        'name': name.text.trim(),
        'price': price.text.trim(),
        if (cost.text.trim().isNotEmpty) 'cost_price': cost.text.trim(),
        if (offer.text.trim().isNotEmpty) 'offer_price': offer.text.trim(),
        if (qty.text.trim().isNotEmpty) 'quantity': qty.text.trim(),
        if (category.text.trim().isNotEmpty) 'category': category.text.trim(),
        if (fmt(production) != null) 'production_date': fmt(production),
        if (fmt(expiry) != null) 'expiry_date': fmt(expiry),
      });
      if (!mounted) return;
      if (!added) {
        ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(content: Text(c.lastError.value), backgroundColor: AmyalColors.red));
      }
    }
  }

  bool _isExpiringSoon(String? expiry) {
    if (expiry == null || expiry.isEmpty) return false;
    final d = DateTime.tryParse(expiry);
    if (d == null) return false;
    return d.isBefore(DateTime.now().add(const Duration(days: 30)));
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmyalColors.background,
      appBar: AppBar(
        backgroundColor: AmyalColors.primary,
        foregroundColor: Colors.white,
        title: const Text('المنتجات'),
      ),
      floatingActionButton: FloatingActionButton.extended(
        backgroundColor: AmyalColors.primary,
        onPressed: _addDialog,
        icon: const Icon(Icons.add, color: Colors.white),
        label: const Text('إضافة', style: TextStyle(color: Colors.white)),
      ),
      body: Obx(() {
        if (c.isLoadingProducts.value && c.products.isEmpty) {
          return const Center(child: CircularProgressIndicator());
        }
        if (c.products.isEmpty) {
          return const Center(
              child: Text('لا منتجات بعد — أضف منتجاتك أو استخدم البيع بمبلغ حرّ',
                  style: TextStyle(color: AmyalColors.textSecondary)));
        }
        return ListView.separated(
          padding: const EdgeInsets.all(12),
          itemCount: c.products.length,
          separatorBuilder: (_, _) => const Divider(height: 1),
          itemBuilder: (_, i) {
            final p = c.products[i];
            final expiry = p['expiry_date']?.toString();
            final soon = _isExpiringSoon(expiry);
            final hasOffer = p['offer_price'] != null && (double.tryParse(p['offer_price'].toString()) ?? 0) > 0;
            return ListTile(
              leading: const Icon(Icons.shopping_bag_outlined, color: AmyalColors.primary),
              title: Text((p['name'] ?? '').toString()),
              subtitle: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(children: [
                    if (p['category'] != null) Text('${p['category']}  ', style: const TextStyle(fontSize: 11, color: AmyalColors.textMuted)),
                    Text('مخزون: ${p['quantity'] ?? 0}', style: const TextStyle(fontSize: 11, color: AmyalColors.textSecondary)),
                  ]),
                  if (expiry != null && expiry.isNotEmpty)
                    Text('ينتهي: $expiry', style: TextStyle(fontSize: 11, color: soon ? AmyalColors.red : AmyalColors.textMuted, fontWeight: soon ? FontWeight.bold : FontWeight.normal)),
                ],
              ),
              trailing: Column(
                mainAxisAlignment: MainAxisAlignment.center,
                crossAxisAlignment: CrossAxisAlignment.end,
                children: [
                  if (hasOffer)
                    Text('${p['price']}', style: const TextStyle(fontSize: 11, decoration: TextDecoration.lineThrough, color: AmyalColors.textMuted)),
                  Text('${hasOffer ? p['offer_price'] : p['price']} ر.ي',
                      style: const TextStyle(fontWeight: FontWeight.bold, color: AmyalColors.primary)),
                ],
              ),
            );
          },
        );
      }),
    );
  }
}
