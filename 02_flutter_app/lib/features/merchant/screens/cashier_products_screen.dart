import 'package:amial_pay/features/entitlements/controllers/entitlements_controller.dart';
import 'package:amial_pay/features/retail/screens/product_variants_screen.dart';
import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amial_pay/features/merchant/controllers/cashier_controller.dart';
import 'package:amial_pay/theme/amial_colors.dart';

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

  Future<void> _addDialog({String? prefillBarcode}) async {
    final name = TextEditingController();
    final price = TextEditingController();      // سعر البيع
    final cost = TextEditingController();        // التكلفة
    final offer = TextEditingController();        // العرض
    final qty = TextEditingController();          // الكمية
    final category = TextEditingController();
    final barcode = TextEditingController(text: prefillBarcode ?? ''); // AMIAL-CASHIER-BARCODE-001
    DateTime? production;
    DateTime? expiry;

    // AMIAL-CATALOG-001 — حالةُ البحث في الكتالوج داخل الحوار.
    bool catalogBusy = false;
    String catalogNote = '';
    Map<String, dynamic>? catalogFound;

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
                // AMIAL-CATALOG-001 — **الباركود يملأ الاسم من الكتالوج.**
                //
                // فباركودُ EAN عالميٌّ بحكم تعريفه: علبةُ حليبٍ تحمل الرقمَ
                // نفسَه عند كلّ تاجر. وإدخالُ اسمها عشرين مرّةً عند عشرين
                // تاجراً عملٌ مكرّرٌ بلا سبب.
                //
                // **ولا يُدهَس ما كتبه التاجر:** إن كان الاسمُ مكتوباً
                // يُترك، ويُعرض ما في الكتالوج كاقتراحٍ يضغطه إن شاء.
                TextField(
                  controller: barcode,
                  keyboardType: TextInputType.number,
                  decoration: InputDecoration(
                    labelText: 'الباركود (اختياري)',
                    hintText: 'امسحه من شاشة البيع أو أدخله يدوياً',
                    suffixIcon: catalogBusy
                        ? const Padding(
                            padding: EdgeInsets.all(12),
                            child: SizedBox(
                                width: 18, height: 18,
                                child: CircularProgressIndicator(strokeWidth: 2)),
                          )
                        : IconButton(
                            tooltip: 'ابحث في الكتالوج',
                            icon: const Icon(Icons.travel_explore),
                            onPressed: () async {
                              final code = barcode.text.trim();
                              if (code.isEmpty) return;

                              setLocal(() { catalogBusy = true; catalogNote = ''; });

                              final hit = await c.catalogLookup(code);

                              setLocal(() {
                                catalogBusy = false;

                                if (hit == null) {
                                  // **الغيابُ يُقال ولا يُترك صمتاً** — ومعه
                                  // ما يجعل التاجر يُدخل الاسم راضياً.
                                  catalogNote = 'غير موجود في الكتالوج — أدخل الاسم وسيفيد من بعدك';
                                  catalogFound = null;
                                  return;
                                }

                                catalogFound = hit;

                                if (name.text.trim().isEmpty) {
                                  name.text = '${hit['name'] ?? ''}';
                                  if ((hit['category'] ?? '').toString().isNotEmpty &&
                                      category.text.trim().isEmpty) {
                                    category.text = '${hit['category']}';
                                  }
                                }

                                catalogNote = (hit['is_verified'] == true)
                                    ? '✓ موثّق — تبنّاه ${hit['adoption_count'] ?? 0} تاجراً'
                                    : '⚠ مقترَح من تاجر آخر ولم يُراجَع بعد — تحقّق من الاسم';
                              });
                            },
                          ),
                  ),
                ),

                if (catalogNote.isNotEmpty)
                  Padding(
                    padding: const EdgeInsets.only(top: 6),
                    child: Row(children: [
                      Expanded(
                        child: Text(catalogNote,
                            style: TextStyle(
                                fontSize: 11,
                                color: catalogFound == null
                                    ? Colors.grey
                                    : (catalogFound!['is_verified'] == true
                                        ? Colors.green.shade700
                                        : Colors.orange.shade800))),
                      ),
                      if (catalogFound != null && name.text.trim() != '${catalogFound!['name']}')
                        TextButton(
                          onPressed: () => setLocal(() => name.text = '${catalogFound!['name']}'),
                          child: const Text('استعمل اسم الكتالوج', style: TextStyle(fontSize: 11)),
                        ),

                      // ══════════════════════════════════════════════════
                      //  AMIAL-CATALOG-ADOPT-001 — **الضغطةُ الواحدة.**
                      //
                      //  الاتّفاقُ المسبق: «يستطيع التاجرُ تحميلَه إلى
                      //  منتجاته بدل إضافته يدويّاً». وكان نصفُه مبنيّاً —
                      //  البحثُ يملأ الاسمَ ثمّ يكتب التاجرُ الباقيَ ويحفظ.
                      //  **والنصفُ الناقصُ هذا الزرّ.**
                      //
                      //  والسعرُ شرطُه: الكتالوجُ بلا سعرٍ عمداً (رؤيةُ سعرِ
                      //  منافسٍ تسريبٌ تجاريّ)، فلا يُتبنّى صنفٌ بلا سعرٍ
                      //  يُباع به.
                      // ══════════════════════════════════════════════════
                      if (catalogFound != null)
                        FilledButton.icon(
                          key: const Key('catalog-adopt'),
                          icon: const Icon(Icons.download_for_offline, size: 16),
                          label: const Text('أضِفه لمنتجاتي', style: TextStyle(fontSize: 11)),
                          style: FilledButton.styleFrom(
                            backgroundColor: AmialColors.primary,
                            visualDensity: VisualDensity.compact,
                          ),
                          onPressed: catalogBusy
                              ? null
                              : () async {
                                  final priceText = price.text.trim();

                                  if (priceText.isEmpty ||
                                      (double.tryParse(priceText) ?? 0) <= 0) {
                                    setLocal(() => catalogNote =
                                        'أدخل سعرَ البيع أوّلاً — الكتالوجُ بلا أسعار');
                                    return;
                                  }

                                  setLocal(() => catalogBusy = true);

                                  final err = await c.adoptFromCatalog({
                                    'barcode': barcode.text.trim(),
                                    'price': priceText,
                                    if (cost.text.trim().isNotEmpty)
                                      'cost_price': cost.text.trim(),
                                    if (qty.text.trim().isNotEmpty)
                                      'quantity': qty.text.trim(),
                                  });

                                  if (!ctx.mounted) return;

                                  setLocal(() => catalogBusy = false);

                                  if (err == null) {
                                    Navigator.pop(ctx);
                                    Get.snackbar('تمّ', 'أُضيف «${catalogFound!['name']}» من الكتالوج');
                                  } else {
                                    setLocal(() => catalogNote = err);
                                  }
                                },
                        ),
                    ]),
                  ),

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
        if (barcode.text.trim().isNotEmpty) 'barcode': barcode.text.trim(),
        if (fmt(production) != null) 'production_date': fmt(production),
        if (fmt(expiry) != null) 'expiry_date': fmt(expiry),
      });
      if (!mounted) return;
      if (!added) {
        ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(content: Text(c.lastError.value), backgroundColor: AmialColors.red));
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
      backgroundColor: AmialColors.background,
      appBar: AppBar(
        title: const Text('المنتجات'),
      ),
      floatingActionButton: FloatingActionButton.extended(
        backgroundColor: AmialColors.primary,
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
                  style: TextStyle(color: AmialColors.textSecondary)));
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
              leading: const Icon(Icons.shopping_bag_outlined, color: AmialColors.primary),
              title: Text((p['name'] ?? '').toString()),
              subtitle: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(children: [
                    if (p['category'] != null) Text('${p['category']}  ', style: const TextStyle(fontSize: 11, color: AmialColors.textMuted)),
                    Text('مخزون: ${p['quantity'] ?? 0}', style: const TextStyle(fontSize: 11, color: AmialColors.textSecondary)),
                  ]),
                  if (expiry != null && expiry.isNotEmpty)
                    Text('ينتهي: $expiry', style: TextStyle(fontSize: 11, color: soon ? AmialColors.red : AmialColors.textMuted, fontWeight: soon ? FontWeight.bold : FontWeight.normal)),
                ],
              ),
              trailing: Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Column(
                    mainAxisAlignment: MainAxisAlignment.center,
                    crossAxisAlignment: CrossAxisAlignment.end,
                    children: [
                      if (hasOffer)
                        Text('${p['price']}', style: const TextStyle(fontSize: 11, decoration: TextDecoration.lineThrough, color: AmialColors.textMuted)),
                      Text('${hasOffer ? p['offer_price'] : p['price']} ر.ي',
                          style: const TextStyle(fontWeight: FontWeight.bold, color: AmialColors.primary)),
                    ],
                  ),
                  // AMIAL-VARIANTS-REACH-001 — **المدخلُ الذي كان مفقوداً.**
                  // الخادمُ والمستودعُ كانا يحملان توليدَ المتغيّرات، ولا
                  // زرَّ في التطبيق يفتحه. (القاعدة ١٢.)
                  // **الزرُّ يظهر لمن يفتحه فقط.** المسارُ محروسٌ بقدرة
                  // `retail.variants`، فظهورُه لتاجرِ صيدليّةٍ أو مطعمٍ
                  // يقود إلى رفضٍ ٤٠٢ — زرٌّ يعمل ويصل إلى طريقٍ مسدود.
                  // (كشفه محورُ المواصفة في مراجعةٍ آليّة.)
                  if (Get.isRegistered<EntitlementsController>() &&
                      Get.find<EntitlementsController>().isAvailable('retail.variants'))
                  IconButton(
                    icon: const Icon(Icons.auto_awesome_motion,
                        color: AmialColors.textMuted, size: 20),
                    tooltip: 'متغيّرات (لون · مقاس)',
                    onPressed: () => Get.to(() => ProductVariantsScreen(
                          productId: (p['id'] as num).toInt(),
                          productName: (p['name'] ?? '').toString(),
                        )),
                  ),
                ],
              ),
            );
          },
        );
      }),
    );
  }
}
