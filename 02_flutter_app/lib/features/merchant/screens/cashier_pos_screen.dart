import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/features/merchant/controllers/cashier_controller.dart';
import 'package:amyal_pay/features/merchant/screens/cashier_payment_screen.dart';
import 'package:amyal_pay/features/merchant/screens/cashier_scan_screen.dart';
import 'package:amyal_pay/helper/amial_money.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';

/// AMIAL-POS-001 — «المبيعات» (التصميم 36):
/// بحث + ماسح باركود + تصنيفات + شبكة منتجات ببطاقات وزر ذهبي (+)
/// + شريط سلة عائم «N منتجات — الإجمالي — مراجعة الطلب»
/// ومراجعة الطلب (التصميم 50): أسطر بكمية ± وحذف ثم «دفع الآن».
class CashierPosScreen extends StatefulWidget {
  const CashierPosScreen({super.key});

  @override
  State<CashierPosScreen> createState() => _CashierPosScreenState();
}

class _CashierPosScreenState extends State<CashierPosScreen> {
  CashierController get c => Get.find<CashierController>();
  final _search = TextEditingController();
  String _category = 'الكل';

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
      if (p['is_active'] == false || p['is_active'] == 0) return false;
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

  /// بيع بمبلغ حرّ (بلا منتجات) — «إدخال يدوي» من التصميم 35.
  Future<void> _manualAmount() async {
    final amount = TextEditingController();
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('إدخال مبلغ يدوي'),
        content: TextField(
          controller: amount,
          autofocus: true,
          keyboardType: TextInputType.number,
          inputFormatters: [FilteringTextInputFormatter.digitsOnly],
          textAlign: TextAlign.center,
          style: const TextStyle(fontSize: 24, fontWeight: FontWeight.bold),
          decoration: const InputDecoration(
            hintText: '0',
            suffixText: 'ر.ي',
          ),
        ),
        actions: [
          TextButton(
              onPressed: () => Navigator.pop(ctx, false),
              child: const Text('إلغاء')),
          ElevatedButton(
            onPressed: () => Navigator.pop(ctx, true),
            style: ElevatedButton.styleFrom(
                backgroundColor: AmyalColors.primary,
                foregroundColor: Colors.white),
            child: const Text('متابعة للدفع'),
          ),
        ],
      ),
    );
    final total = double.tryParse(amount.text.trim()) ?? 0;
    if (ok == true && total > 0 && mounted) {
      Get.to(() => CashierPaymentScreen(total: total, freeAmount: true));
    }
  }

  /// مراجعة الطلب (التصميم 50) — ورقة سفلية بأسطر السلة وكمياتها.
  Future<void> _openCartReview() async {
    if (c.cart.isEmpty) return;
    await showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.white,
      shape: const RoundedRectangleBorder(
          borderRadius: BorderRadius.vertical(top: Radius.circular(24))),
      builder: (ctx) => SafeArea(
        child: Padding(
          padding: const EdgeInsets.fromLTRB(16, 12, 16, 16),
          child: Obx(() {
            if (c.cart.isEmpty) {
              // أُفرغت السلة من داخل الورقة
              return const SizedBox(
                  height: 120,
                  child: Center(child: Text('السلة فارغة')));
            }
            return Column(mainAxisSize: MainAxisSize.min, children: [
              Container(
                width: 44,
                height: 4,
                decoration: BoxDecoration(
                    color: Colors.grey.shade300,
                    borderRadius: BorderRadius.circular(2)),
              ),
              const SizedBox(height: 12),
              const Text('مراجعة الطلب',
                  style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold)),
              const SizedBox(height: 12),
              ConstrainedBox(
                constraints: BoxConstraints(
                    maxHeight: MediaQuery.of(ctx).size.height * 0.45),
                child: ListView.separated(
                  shrinkWrap: true,
                  itemCount: c.cart.length,
                  separatorBuilder: (_, _) =>
                      const Divider(height: 1, color: AmyalColors.border),
                  itemBuilder: (_, i) {
                    final l = c.cart[i];
                    return Padding(
                      padding: const EdgeInsets.symmetric(vertical: 8),
                      child: Row(children: [
                        // الكمية ±
                        Container(
                          decoration: BoxDecoration(
                            color: const Color(0xFFF0F4FF),
                            borderRadius: BorderRadius.circular(10),
                          ),
                          child: Row(children: [
                            IconButton(
                              visualDensity: VisualDensity.compact,
                              icon: const Icon(Icons.add, size: 16),
                              onPressed: () => c.incLine(i),
                            ),
                            Text('${l.qty}',
                                style: const TextStyle(
                                    fontWeight: FontWeight.bold)),
                            IconButton(
                              visualDensity: VisualDensity.compact,
                              icon: Icon(
                                  l.qty <= 1
                                      ? Icons.delete_outline
                                      : Icons.remove,
                                  size: 16,
                                  color: l.qty <= 1
                                      ? AmyalColors.red
                                      : Colors.black87),
                              onPressed: () => c.decLine(i),
                            ),
                          ]),
                        ),
                        const SizedBox(width: 10),
                        Text(AmialMoney.yer(l.lineTotal),
                            style: const TextStyle(
                                fontWeight: FontWeight.bold,
                                color: AmyalColors.primary)),
                        const Spacer(),
                        Expanded(
                          flex: 2,
                          child: Column(
                              crossAxisAlignment: CrossAxisAlignment.end,
                              children: [
                                Text(l.name,
                                    maxLines: 1,
                                    overflow: TextOverflow.ellipsis,
                                    style: const TextStyle(
                                        fontWeight: FontWeight.w600,
                                        fontSize: 13)),
                                Text('${AmialMoney.fmt(l.price)} ر.ي للوحدة',
                                    style: const TextStyle(
                                        fontSize: 11,
                                        color: AmyalColors.textMuted)),
                              ]),
                        ),
                      ]),
                    );
                  },
                ),
              ),
              const Divider(height: 24),
              Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  Text(AmialMoney.yer(c.cartTotal),
                      style: const TextStyle(
                          fontSize: 20,
                          fontWeight: FontWeight.bold,
                          color: AmyalColors.primary)),
                  const Text('الإجمالي',
                      style: TextStyle(
                          fontSize: 15, fontWeight: FontWeight.bold)),
                ],
              ),
              const SizedBox(height: 14),
              FilledButton.icon(
                onPressed: () {
                  Navigator.pop(ctx);
                  Get.to(() => CashierPaymentScreen(total: c.cartTotal));
                },
                icon: const Icon(Icons.payments_outlined),
                label: const Text('دفع الآن',
                    style:
                        TextStyle(fontSize: 16, fontWeight: FontWeight.w600)),
                style: FilledButton.styleFrom(
                  backgroundColor: AmyalColors.primary,
                  minimumSize: const Size.fromHeight(54),
                  shape: RoundedRectangleBorder(
                      borderRadius: BorderRadius.circular(16)),
                ),
              ),
              TextButton(
                onPressed: () {
                  c.clearCart();
                  Navigator.pop(ctx);
                },
                child: const Text('مسح السلة',
                    style: TextStyle(color: AmyalColors.red, fontSize: 13)),
              ),
            ]);
          }),
        ),
      ),
    );
    if (mounted) setState(() {});
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmyalColors.background,
      appBar: AppBar(
        backgroundColor: AmyalColors.primary,
        foregroundColor: Colors.white,
        title: const Text('المبيعات'),
        actions: [
          IconButton(
            tooltip: 'إدخال يدوي',
            icon: const Icon(Icons.edit_note_rounded),
            onPressed: _manualAmount,
          ),
        ],
      ),
      body: Obx(() {
        final items = _visible;
        return Column(children: [
          // ====== البحث + الماسح ======
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 14, 16, 8),
            child: Row(children: [
              // زر الماسح (أخضر داكن كما في التصميم)
              InkWell(
                onTap: () async {
                  await Get.to(() => const CashierScanScreen());
                  setState(() {});
                },
                borderRadius: BorderRadius.circular(12),
                child: Container(
                  height: 48,
                  width: 48,
                  decoration: BoxDecoration(
                    color: AmyalColors.primary,
                    borderRadius: BorderRadius.circular(12),
                  ),
                  child: const Icon(Icons.barcode_reader,
                      color: Colors.white, size: 22),
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: TextField(
                  controller: _search,
                  onChanged: (_) => setState(() {}),
                  decoration: InputDecoration(
                    hintText: 'بحث عن منتج أو كود...',
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
              ),
            ]),
          ),

          // ====== التصنيفات ======
          SizedBox(
            height: 42,
            child: ListView(
              scrollDirection: Axis.horizontal,
              padding: const EdgeInsets.symmetric(horizontal: 16),
              children: _categories.map((cat) {
                final selected = _category == cat;
                return Padding(
                  padding: const EdgeInsets.only(left: 8),
                  child: ChoiceChip(
                    label: Text(cat, style: const TextStyle(fontSize: 12)),
                    selected: selected,
                    selectedColor: AmyalColors.primary,
                    backgroundColor: Colors.white,
                    labelStyle: TextStyle(
                        color: selected ? Colors.white : AmyalColors.primary),
                    onSelected: (_) => setState(() => _category = cat),
                  ),
                );
              }).toList(),
            ),
          ),

          // ====== شبكة المنتجات ======
          Expanded(
            child: c.isLoadingProducts.value && c.products.isEmpty
                ? const Center(
                    child:
                        CircularProgressIndicator(color: AmyalColors.primary))
                : items.isEmpty
                    ? const Center(
                        child: Text('لا توجد منتجات مطابقة',
                            style: TextStyle(color: AmyalColors.textMuted)))
                    : GridView.builder(
                        padding: const EdgeInsets.fromLTRB(16, 8, 16, 110),
                        gridDelegate:
                            const SliverGridDelegateWithFixedCrossAxisCount(
                          crossAxisCount: 2,
                          mainAxisSpacing: 12,
                          crossAxisSpacing: 12,
                          childAspectRatio: 0.98,
                        ),
                        itemCount: items.length,
                        itemBuilder: (_, i) => _productTile(items[i]),
                      ),
          ),
        ]);
      }),

      // ====== شريط السلة العائم ======
      bottomSheet: Obx(() {
        if (c.cart.isEmpty) return const SizedBox.shrink();
        final count = c.cart.fold<int>(0, (s, l) => s + l.qty);
        return SafeArea(
          child: Padding(
            padding: const EdgeInsets.fromLTRB(16, 0, 16, 12),
            child: Material(
              color: AmyalColors.primary,
              borderRadius: BorderRadius.circular(30),
              child: InkWell(
                onTap: _openCartReview,
                borderRadius: BorderRadius.circular(30),
                child: Padding(
                  padding:
                      const EdgeInsets.symmetric(horizontal: 8, vertical: 8),
                  child: Row(children: [
                    Container(
                      padding: const EdgeInsets.symmetric(
                          horizontal: 16, vertical: 10),
                      decoration: BoxDecoration(
                        color: AmyalColors.yellow,
                        borderRadius: BorderRadius.circular(24),
                      ),
                      child: const Row(children: [
                        Text('مراجعة الطلب',
                            style: TextStyle(
                                fontWeight: FontWeight.bold,
                                color: Color(0xFF14342A))),
                        SizedBox(width: 4),
                        Icon(Icons.chevron_left,
                            size: 18, color: Color(0xFF14342A)),
                      ]),
                    ),
                    const Spacer(),
                    Column(
                        crossAxisAlignment: CrossAxisAlignment.end,
                        children: [
                          Text('$count منتجات',
                              style: const TextStyle(
                                  color: Colors.white,
                                  fontWeight: FontWeight.bold,
                                  fontSize: 14)),
                          Text(AmialMoney.yer(c.cartTotal),
                              style: const TextStyle(
                                  color: Colors.white70, fontSize: 11)),
                        ]),
                    const SizedBox(width: 10),
                    const Icon(Icons.shopping_cart_outlined,
                        color: AmyalColors.yellow),
                    const SizedBox(width: 8),
                  ]),
                ),
              ),
            ),
          ),
        );
      }),
    );
  }

  Widget _productTile(Map<String, dynamic> p) {
    final qty = double.tryParse('${p['quantity'] ?? 0}') ?? 0;
    final hasOffer = (double.tryParse('${p['offer_price'] ?? ''}') ?? 0) > 0;
    final price = hasOffer ? p['offer_price'] : p['price'];
    final out = qty <= 0;

    return Opacity(
      opacity: out ? 0.55 : 1,
      child: Container(
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(18),
          boxShadow: [
            BoxShadow(
                color: Colors.black.withValues(alpha: 0.04), blurRadius: 8),
          ],
        ),
        padding: const EdgeInsets.all(12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.end,
          children: [
            Row(children: [
              if (hasOffer)
                Container(
                  padding:
                      const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                  decoration: BoxDecoration(
                    color: AmyalColors.primary,
                    borderRadius: BorderRadius.circular(8),
                  ),
                  child: const Text('عرض',
                      style: TextStyle(color: Colors.white, fontSize: 9)),
                ),
              if (out)
                Container(
                  padding:
                      const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                  decoration: BoxDecoration(
                    color: const Color(0xFFFDE7E7),
                    borderRadius: BorderRadius.circular(8),
                  ),
                  child: const Text('نفد',
                      style:
                          TextStyle(color: AmyalColors.red, fontSize: 9)),
                ),
              const Spacer(),
              Container(
                height: 42,
                width: 42,
                decoration: BoxDecoration(
                  color: AmyalColors.primary.withValues(alpha: 0.07),
                  borderRadius: BorderRadius.circular(12),
                ),
                child: const Icon(Icons.inventory_2_outlined,
                    color: AmyalColors.primary, size: 20),
              ),
            ]),
            const Spacer(),
            Text('${p['name']}',
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
                textAlign: TextAlign.right,
                style: const TextStyle(
                    fontWeight: FontWeight.bold, fontSize: 13, height: 1.3)),
            Text('${p['category'] ?? ''}',
                style: const TextStyle(
                    fontSize: 10, color: AmyalColors.textMuted)),
            const SizedBox(height: 8),
            Row(children: [
              // زر الإضافة الذهبي
              InkWell(
                onTap: out
                    ? null
                    : () {
                        c.addProductToCart(p);
                        setState(() {});
                      },
                borderRadius: BorderRadius.circular(12),
                child: Container(
                  height: 34,
                  width: 34,
                  decoration: BoxDecoration(
                    color: out ? Colors.grey.shade300 : AmyalColors.yellow,
                    borderRadius: BorderRadius.circular(12),
                  ),
                  child: Icon(Icons.add,
                      size: 20,
                      color: out ? Colors.grey : const Color(0xFF14342A)),
                ),
              ),
              const Spacer(),
              Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
                if (hasOffer)
                  Text(AmialMoney.fmt(p['price']),
                      style: const TextStyle(
                          fontSize: 10,
                          color: AmyalColors.textMuted,
                          decoration: TextDecoration.lineThrough)),
                Text(AmialMoney.yer(price),
                    style: const TextStyle(
                        fontWeight: FontWeight.bold,
                        fontSize: 14,
                        color: AmyalColors.primary)),
              ]),
            ]),
          ],
        ),
      ),
    );
  }
}
