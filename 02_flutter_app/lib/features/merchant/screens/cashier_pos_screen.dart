import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:get/get.dart';
import 'package:amial_pay/features/access/controllers/access_controller.dart';
import 'package:amial_pay/features/fuel_station/screens/fuel_sale_screen.dart';
import 'package:amial_pay/features/pharmacy/screens/pharmacy_sale_screen.dart';
import 'package:amial_pay/features/merchant/controllers/cashier_controller.dart';
import 'package:amial_pay/features/merchant/screens/cashier_payment_screen.dart';
import 'package:amial_pay/features/merchant/screens/cashier_scan_screen.dart';
import 'package:amial_pay/features/merchant/screens/offline_sales_screen.dart';
import 'package:amial_pay/features/merchant/services/offline_sale_queue.dart';
import 'package:amial_pay/helper/amial_money.dart';
import 'package:amial_pay/theme/amial_colors.dart';

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

  final _offline = Get.find<OfflineSaleQueue>();

  @override
  void initState() {
    super.initState();
    if (Get.isRegistered<AccessController>() &&
        (Get.find<AccessController>().isFuel || Get.find<AccessController>().isPharmacy)) {
      return;
    }
    WidgetsBinding.instance.addPostFrameCallback((_) {
      c.loadProducts();
      // AMIAL-OFFLINE-POS-001 — حاول مزامنة أي مبيعات معلّقة عند فتح الكاشير.
      _offline.refreshCount().then((n) { if (n > 0) _offline.sync(); });
    });
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
                backgroundColor: AmialColors.primary,
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
            final count = c.cart.fold<int>(0, (s, l) => s + l.qty);
            return Column(mainAxisSize: MainAxisSize.min, children: [
              Container(
                width: 44,
                height: 4,
                decoration: BoxDecoration(
                    color: Colors.grey.shade300,
                    borderRadius: BorderRadius.circular(2)),
              ),
              const SizedBox(height: 14),
              // العنوان + شارة عدد الأصناف
              Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                  decoration: BoxDecoration(
                    color: AmialColors.primary.withValues(alpha: 0.08),
                    borderRadius: BorderRadius.circular(20),
                  ),
                  child: Text('$count صنف',
                      style: const TextStyle(
                          fontSize: 12,
                          fontWeight: FontWeight.bold,
                          color: AmialColors.primary)),
                ),
                const Text('مراجعة الطلب',
                    style: TextStyle(fontSize: 17, fontWeight: FontWeight.bold)),
              ]),
              const SizedBox(height: 14),
              ConstrainedBox(
                constraints: BoxConstraints(
                    maxHeight: MediaQuery.of(ctx).size.height * 0.42),
                child: ListView.separated(
                  shrinkWrap: true,
                  itemCount: c.cart.length,
                  separatorBuilder: (_, _) => const SizedBox(height: 8),
                  itemBuilder: (_, i) => _cartLine(i),
                ),
              ),
              const SizedBox(height: 14),
              // بطاقة الإجمالي
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
                decoration: BoxDecoration(
                  color: AmialColors.primary.withValues(alpha: 0.06),
                  borderRadius: BorderRadius.circular(16),
                ),
                child: Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    Text(AmialMoney.yer(c.cartTotal),
                        style: const TextStyle(
                            fontSize: 22,
                            fontWeight: FontWeight.bold,
                            color: AmialColors.primary)),
                    const Text('الإجمالي المطلوب',
                        style: TextStyle(
                            fontSize: 14, fontWeight: FontWeight.w600)),
                  ],
                ),
              ),
              const SizedBox(height: 14),
              FilledButton.icon(
                onPressed: () {
                  Navigator.pop(ctx);
                  Get.to(() => CashierPaymentScreen(total: c.cartTotal));
                },
                icon: const Icon(Icons.arrow_back),
                label: const Text('متابعة للدفع',
                    style:
                        TextStyle(fontSize: 16, fontWeight: FontWeight.w700)),
                style: FilledButton.styleFrom(
                  backgroundColor: AmialColors.primary,
                  minimumSize: const Size.fromHeight(54),
                  shape: RoundedRectangleBorder(
                      borderRadius: BorderRadius.circular(16)),
                ),
              ),
              const SizedBox(height: 4),
              TextButton.icon(
                onPressed: () {
                  c.clearCart();
                  Navigator.pop(ctx);
                },
                icon: const Icon(Icons.delete_sweep_outlined,
                    size: 18, color: AmialColors.red),
                label: const Text('إفراغ السلة',
                    style: TextStyle(color: AmialColors.red, fontSize: 13)),
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
    // حاجز قطاعي أخير: الكاشير العام للتجزئة والبيع السريع فقط. الصيدلية
    // تحتاج مسارها الذي يحفظ الوصفة والتشغيلة والانتهاء، والوقود له مساره.
    if (Get.isRegistered<AccessController>() &&
        Get.find<AccessController>().isFuel) {
      return const FuelSaleScreen();
    }
    if (Get.isRegistered<AccessController>() &&
        Get.find<AccessController>().isPharmacy) {
      return const PharmacySaleScreen();
    }
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(
        title: const Text('المبيعات'),
        actions: [
          // مؤشّر المبيعات دون اتصال (يظهر عند وجود معلّق)
          Obx(() => _offline.pending.value == 0
              ? const SizedBox.shrink()
              : Padding(
                  padding: const EdgeInsets.only(left: 4),
                  child: Stack(alignment: Alignment.center, children: [
                    IconButton(
                      tooltip: 'مبيعات دون اتصال',
                      icon: const Icon(Icons.cloud_off),
                      onPressed: () => Get.to(() => const OfflineSalesScreen()),
                    ),
                    Positioned(
                      right: 6, top: 8,
                      child: Container(
                        padding: const EdgeInsets.all(4),
                        decoration: const BoxDecoration(color: AmialColors.red, shape: BoxShape.circle),
                        child: Text('${_offline.pending.value}',
                            style: const TextStyle(color: Colors.white, fontSize: 9, fontWeight: FontWeight.bold)),
                      ),
                    ),
                  ]),
                )),
          IconButton(
            tooltip: 'إدخال يدوي',
            icon: const Icon(Icons.edit_note_rounded),
            onPressed: _manualAmount,
          ),
        ],
      ),
      body: Obx(() {
        final items = _visible;
        // ══════════════════════════════════════════════════════════════
        // AMIAL-QUICKSALE-PRIMARY-001 — **بائعُ السمك لا يملك منتجات.**
        //
        // `quick_sale` قطاعُ الأسماك والخضار والبسطات (كما يقول تعريفُه
        // في `AccessConstants`): **لا كتالوجَ فيه — يُدخَل المبلغُ ويُدفَع
        // وتُصدَر الفاتورة**. والبنيةُ لذلك مبنيّةٌ هنا منذ البداية:
        // `_manualAmount()` و`CashierPaymentScreen(freeAmount: true)`.
        //
        // **لكنّها كانت خلف أيقونةٍ صغيرةٍ في الشريط العلويّ**، وجسمُ
        // الشاشة شبكةُ منتجاتٍ — **فارغةٌ أبداً لمن لا منتجاتِ له**. فيفتح
        // بائعُ السمك «بيع جديد» فيرى فراغاً، وما يحتاجه أيقونةٌ بلا اسم.
        //
        // فصار فعلُه الأوّلَ ظاهراً بحجمه. **ولا تُخفى الشبكةُ ولا يُقفَل
        // شيء**: من أضاف صنفاً يجده كما كان — القطاعُ يقرّر ما يتقدّم،
        // لا ما يُمنَع. (والباقةُ لا تدخل هنا: البيعُ السريع مجّانيٌّ
        // بطبعه، والإدخالُ اليدويُّ ليس قدرةً مُسعَّرة.)
        // ══════════════════════════════════════════════════════════════
        final quickSale = Get.isRegistered<AccessController>() &&
            Get.find<AccessController>().isQuickSale;

        return Column(children: [
          if (quickSale)
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 14, 16, 0),
              child: SizedBox(
                width: double.infinity,
                child: ElevatedButton.icon(
                  onPressed: _manualAmount,
                  icon: const Icon(Icons.edit_note_rounded, size: 26),
                  label: const Text('أدخل المبلغ',
                      style: TextStyle(
                          fontSize: 18, fontWeight: FontWeight.bold)),
                  style: ElevatedButton.styleFrom(
                    backgroundColor: AmialColors.primary,
                    foregroundColor: Colors.white,
                    padding: const EdgeInsets.symmetric(vertical: 18),
                    shape: RoundedRectangleBorder(
                        borderRadius: BorderRadius.circular(14)),
                  ),
                ),
              ),
            ),
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
                    color: AmialColors.primary,
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
                    selectedColor: AmialColors.primary,
                    backgroundColor: Colors.white,
                    labelStyle: TextStyle(
                        color: selected ? Colors.white : AmialColors.primary),
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
                        CircularProgressIndicator(color: AmialColors.primary))
                : items.isEmpty
                    ? const Center(
                        child: Text('لا توجد منتجات مطابقة',
                            style: TextStyle(color: AmialColors.textMuted)))
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

      // ====== شريط السلة ======
      //
      // **العطل الذي كان:** الشريطُ يملأ الشاشة كلَّها.
      //
      // و`Column` بلا `mainAxisSize.min` داخل `Row`: و`Scaffold.bottomSheet`
      // يمنح ارتفاعاً فضفاضاً أقصاه الشاشة، فيأخذ العمودُ كلَّ ما مُنح —
      // ويسحب معه الصفَّ والمادّةَ، **فيصير الشريطُ لوحةً زرقاء**.
      //
      // ولا خطأ في أيّ سجلّ: التخطيطُ صحيحٌ نحويّاً، والنتيجةُ شاشةٌ ممتلئة.
      //
      // وصار في `bottomNavigationBar` لا `bottomSheet`: الأوّلُ يُقيّد
      // الارتفاع بمحتواه، والثاني ورقةٌ تُسحب — وهذا شريطُ فعلٍ لا ورقة.
      bottomNavigationBar: Obx(() {
        if (c.cart.isEmpty) return const SizedBox.shrink();

        final count = c.cart.fold<int>(0, (s, l) => s + l.qty);

        return SafeArea(
          minimum: const EdgeInsets.fromLTRB(12, 0, 12, 10),
          child: Material(
            color: AmialColors.primary,
            borderRadius: BorderRadius.circular(18),
            elevation: 8,
            shadowColor: AmialColors.primary.withValues(alpha: 0.4),
            child: InkWell(
              key: const Key('cashier-cart-bar'),
              onTap: _openCartReview,
              borderRadius: BorderRadius.circular(18),
              child: Padding(
                padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
                child: Row(
                  // **`min` هنا هو الإصلاح** — والباقي شكل.
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    // ــ سلّةٌ بعدّادٍ عليها ــ
                    Stack(clipBehavior: Clip.none, children: [
                      Container(
                        padding: const EdgeInsets.all(9),
                        decoration: BoxDecoration(
                          color: Colors.white.withValues(alpha: 0.16),
                          borderRadius: BorderRadius.circular(12),
                        ),
                        child: const Icon(Icons.shopping_cart_rounded,
                            color: Colors.white, size: 20),
                      ),
                      Positioned(
                        top: -5, left: -5,
                        child: Container(
                          padding: const EdgeInsets.symmetric(
                              horizontal: 6, vertical: 2),
                          decoration: BoxDecoration(
                            color: AmialColors.yellow,
                            borderRadius: BorderRadius.circular(10),
                            border: Border.all(color: AmialColors.primary, width: 2),
                          ),
                          child: Text('$count',
                              style: const TextStyle(
                                  color: Color(0xFF053391),
                                  fontSize: 11,
                                  fontWeight: FontWeight.bold)),
                        ),
                      ),
                    ]),

                    const SizedBox(width: 12),

                    // ــ الإجمالي: أكبرُ رقمٍ في الشريط ــ
                    //
                    // فهو ما يقوله الكاشيرُ للعميل، لا عددُ الأصناف.
                    Expanded(
                      child: Column(
                        mainAxisSize: MainAxisSize.min,
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(AmialMoney.yer(c.cartTotal),
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                              style: const TextStyle(
                                  color: Colors.white,
                                  fontWeight: FontWeight.bold,
                                  fontSize: 18,
                                  height: 1.1)),
                          Text('$count صنفاً في السلة',
                              style: TextStyle(
                                  color: Colors.white.withValues(alpha: 0.75),
                                  fontSize: 11)),
                        ],
                      ),
                    ),

                    const SizedBox(width: 8),

                    // ــ الفعلُ الظاهر ــ
                    Container(
                      padding: const EdgeInsets.symmetric(
                          horizontal: 16, vertical: 11),
                      decoration: BoxDecoration(
                        color: AmialColors.yellow,
                        borderRadius: BorderRadius.circular(14),
                      ),
                      child: const Row(mainAxisSize: MainAxisSize.min, children: [
                        Text('مراجعة الطلب',
                            style: TextStyle(
                                fontWeight: FontWeight.bold,
                                fontSize: 14,
                                color: Color(0xFF053391))),
                        SizedBox(width: 2),
                        Icon(Icons.chevron_left_rounded,
                            size: 20, color: Color(0xFF053391)),
                      ]),
                    ),
                  ],
                ),
              ),
            ),
          ),
        );
      }),
    );
  }

  /// سطر سلة أنيق: صورة/أيقونة + الاسم وسعر الوحدة + إجمالي السطر + مُبدّل الكمية.
  Widget _cartLine(int i) {
    final l = c.cart[i];
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: AmialColors.border),
      ),
      child: Row(children: [
        // مُبدّل الكمية (± أو حذف)
        Container(
          decoration: BoxDecoration(
            color: const Color(0xFFF0F4FF),
            borderRadius: BorderRadius.circular(12),
          ),
          child: Row(mainAxisSize: MainAxisSize.min, children: [
            InkWell(
              borderRadius: BorderRadius.circular(12),
              onTap: () => c.incLine(i),
              child: const Padding(
                padding: EdgeInsets.all(6),
                child: Icon(Icons.add, size: 18, color: AmialColors.primary),
              ),
            ),
            SizedBox(
              width: 24,
              child: Text('${l.qty}',
                  textAlign: TextAlign.center,
                  style: const TextStyle(
                      fontWeight: FontWeight.bold, fontSize: 14)),
            ),
            InkWell(
              borderRadius: BorderRadius.circular(12),
              onTap: () => c.decLine(i),
              child: Padding(
                padding: const EdgeInsets.all(6),
                child: Icon(
                    l.qty <= 1 ? Icons.delete_outline : Icons.remove,
                    size: 18,
                    color: l.qty <= 1 ? AmialColors.red : Colors.black87),
              ),
            ),
          ]),
        ),
        const SizedBox(width: 10),
        // إجمالي السطر
        Text(AmialMoney.yer(l.lineTotal),
            style: const TextStyle(
                fontWeight: FontWeight.bold,
                fontSize: 14,
                color: AmialColors.primary)),
        const Spacer(),
        // الاسم + سعر الوحدة
        Expanded(
          flex: 2,
          child: Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
            Text(l.name,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: const TextStyle(
                    fontWeight: FontWeight.w700, fontSize: 13.5)),
            Text('${AmialMoney.fmt(l.price)} ر.ي × ${l.qty}',
                style: const TextStyle(
                    fontSize: 11, color: AmialColors.textMuted)),
          ]),
        ),
        const SizedBox(width: 10),
        // أيقونة المنتج
        Container(
          height: 42,
          width: 42,
          decoration: BoxDecoration(
            color: AmialColors.yellow.withValues(alpha: 0.18),
            borderRadius: BorderRadius.circular(12),
          ),
          child: const Icon(Icons.inventory_2_outlined,
              color: AmialColors.yellowDark, size: 20),
        ),
      ]),
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
                    color: AmialColors.primary,
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
                    color: AmialColors.dangerSurface,
                    borderRadius: BorderRadius.circular(8),
                  ),
                  child: const Text('نفد',
                      style:
                          TextStyle(color: AmialColors.red, fontSize: 9)),
                ),
              const Spacer(),
              Container(
                height: 42,
                width: 42,
                decoration: BoxDecoration(
                  color: AmialColors.primary.withValues(alpha: 0.07),
                  borderRadius: BorderRadius.circular(12),
                ),
                child: const Icon(Icons.inventory_2_outlined,
                    color: AmialColors.primary, size: 20),
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
                    fontSize: 10, color: AmialColors.textMuted)),
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
                    color: out ? Colors.grey.shade300 : AmialColors.yellow,
                    borderRadius: BorderRadius.circular(12),
                  ),
                  child: Icon(Icons.add,
                      size: 20,
                      color: out ? Colors.grey : const Color(0xFF053391)),
                ),
              ),
              const Spacer(),
              Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
                if (hasOffer)
                  Text(AmialMoney.fmt(p['price']),
                      style: const TextStyle(
                          fontSize: 10,
                          color: AmialColors.textMuted,
                          decoration: TextDecoration.lineThrough)),
                Text(AmialMoney.yer(price),
                    style: const TextStyle(
                        fontWeight: FontWeight.bold,
                        fontSize: 14,
                        color: AmialColors.primary)),
              ]),
            ]),
          ],
        ),
      ),
    );
  }
}
