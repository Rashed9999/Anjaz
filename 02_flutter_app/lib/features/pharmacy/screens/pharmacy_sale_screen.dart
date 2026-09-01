import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:get/get.dart';
import 'package:amial_pay/theme/amial_colors.dart';
import 'package:amial_pay/features/pharmacy/controllers/pharmacy_controller.dart';
import 'package:amial_pay/features/barcode/screens/continuous_scanner_screen.dart';
import 'package:amial_pay/features/payments/screens/amial_qr_collect_screen.dart';
import 'package:amial_pay/features/merchant/screens/cashier_receipt_screen.dart';

/// AMIAL-PHARMACY-001 — شاشة بيع الصيدلية (الجوهر).
///
/// Flow:
///   1. (اختياري) اختر العميل بالهاتف لاستحضار حساسياته.
///   2. ابحث عن منتج (اسم/باركود) → أضف للسلّة.
///   3. السلّة تعرض الإجمالي + تحذيرات الحساسية + علم الوصفة.
///   4. اختر طريقة الدفع.
///   5. إن منتج يحتاج وصفة → أدخل رقمها.
///   6. إن حساسية → Dialog يطلب تأكيد إلغاء التحذير.
///   7. تأكيد البيع.
class PharmacySaleScreen extends StatefulWidget {
  const PharmacySaleScreen({super.key});

  @override
  State<PharmacySaleScreen> createState() => _PharmacySaleScreenState();
}

class _PharmacySaleScreenState extends State<PharmacySaleScreen> {
  late final PharmacyController c;
  final _searchCtrl = TextEditingController();
  final _customerPhoneCtrl = TextEditingController();
  final _creditPhoneCtrl = TextEditingController();
  final _creditNameCtrl = TextEditingController();
  final _prescriptionCtrl = TextEditingController();
  final _doctorCtrl = TextEditingController();
  final _discountCtrl = TextEditingController();
  String _paymentMethod = 'cash';
  bool _searching = false;

  @override
  void initState() {
    super.initState();
    c = Get.find<PharmacyController>();
    c.clearCart();
    WidgetsBinding.instance.addPostFrameCallback((_) => c.loadProducts());
  }

  @override
  void dispose() {
    _searchCtrl.dispose();
    _customerPhoneCtrl.dispose();
    _creditPhoneCtrl.dispose();
    _creditNameCtrl.dispose();
    _prescriptionCtrl.dispose();
    _doctorCtrl.dispose();
    _discountCtrl.dispose();
    super.dispose();
  }

  Future<void> _searchProducts(String q) async {
    setState(() => _searching = true);
    await c.loadProducts(search: q.isEmpty ? null : q);
    if (mounted) setState(() => _searching = false);
  }

  Future<void> _lookupCustomer() async {
    final phone = _customerPhoneCtrl.text.trim();
    if (phone.isEmpty) return;
    final found = await c.findCustomerByPhone(phone);
    if (!mounted) return;
    if (found != null) {
      c.selectedCustomer.value = found;
      _showSnack('تم العثور على ${found['full_name']}', Colors.green);
    } else {
      _showSnack('لا يوجد عميل بهذا الهاتف', Colors.orange);
    }
  }

  Future<void> _submitSale() async {
    final selectedCustomer = c.selectedCustomer.value;
    final creditPhone = selectedCustomer?['phone']?.toString().trim().isNotEmpty == true
        ? selectedCustomer!['phone'].toString().trim()
        : _creditPhoneCtrl.text.trim();
    final creditName = selectedCustomer?['full_name']?.toString().trim().isNotEmpty == true
        ? selectedCustomer!['full_name'].toString().trim()
        : _creditNameCtrl.text.trim();

    if (_paymentMethod == 'credit' && creditPhone.isEmpty) {
      return _showSnack('أدخل رقم العميل للبيع الآجل', AmialColors.red);
    }

    // فحص الوصفة
    if (c.cartRequiresPrescription && _prescriptionCtrl.text.trim().isEmpty) {
      return _showSnack('السلّة تحتوي منتجات تستلزم وصفة طبية', AmialColors.red);
    }

    // فحص الحساسية
    final conflicts = c.cartAllergyConflicts;
    List<String>? ackList;
    if (conflicts.isNotEmpty) {
      final confirmed = await _showAllergyDialog(conflicts);
      if (!confirmed) return;
      ackList = conflicts.map((cf) => cf['product_name'] as String).toList();
    }

    // AMIAL-PAY-QR: «أميال باي» يعرض رمز الاستقبال أولاً؛ يدفع العميل من محفظته،
    // ثم يُسجَّل البيع مربوطاً بمرجع الدفع (استقبال حقيقي كباقي القطاعات).
    if (_paymentMethod == 'amial_pay') {
      final total = c.cartTotal;
      Get.to(() => AmialQrCollectScreen(
            amount: total,
            note: 'دفع صيدلية',
            onPaid: (paidTxId) async {
              final ok = await c.recordCurrentSale(
                paymentMethod: 'amial_pay',
                paidTransactionId: paidTxId,
                prescriptionNumber: _prescriptionCtrl.text.trim(),
                prescribingDoctor: _doctorCtrl.text.trim(),
                discountAmount: _discountCtrl.text.trim(),
                acknowledgedWarnings: ackList,
              );
              if (!ok || !mounted) return false;
              Get.back(); // أغلق شاشة QR وعُد لشاشة البيع
              _showSuccessDialog();
              return true;
            },
          ));
      return;
    }

    final ok = await c.recordCurrentSale(
      paymentMethod: _paymentMethod,
      customerPhone: _paymentMethod == 'credit' ? creditPhone : null,
      customerName: _paymentMethod == 'credit' ? creditName : null,
      prescriptionNumber: _prescriptionCtrl.text.trim(),
      prescribingDoctor: _doctorCtrl.text.trim(),
      discountAmount: _discountCtrl.text.trim(),
      acknowledgedWarnings: ackList,
    );
    if (!mounted) return;
    if (ok) {
      _showSuccessDialog();
    } else {
      _showSnack(c.lastError.value, AmialColors.red);
    }
  }

  Future<bool> _showAllergyDialog(List<Map<String, dynamic>> conflicts) async {
    final result = await showDialog<bool>(
      context: context,
      barrierDismissible: false,
      builder: (_) => AlertDialog(
        title: const Row(children: [
          Icon(Icons.warning_amber, color: Colors.red, size: 28),
          SizedBox(width: 8),
          Text('⚠️ تحذير حساسية'),
        ]),
        content: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
          const Text('المريض لديه حساسية من المواد التالية في السلّة:',
              style: TextStyle(fontWeight: FontWeight.bold)),
          const SizedBox(height: 8),
          ...conflicts.map((cf) => Padding(
            padding: const EdgeInsets.symmetric(vertical: 4),
            child: Container(
              padding: const EdgeInsets.all(8),
              decoration: BoxDecoration(
                color: Colors.red.shade50,
                borderRadius: BorderRadius.circular(8),
                border: Border.all(color: Colors.red.shade200),
              ),
              child: Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
                Text(cf['product_name'] ?? '', style: const TextStyle(fontWeight: FontWeight.bold)),
                Text('يحتوي: ${(cf['allergies'] as List).join(', ')}',
                    style: TextStyle(color: Colors.red.shade700, fontSize: 12)),
              ]),
            ),
          )),
          const SizedBox(height: 12),
          Container(
            padding: const EdgeInsets.all(8),
            decoration: BoxDecoration(
              color: Colors.yellow.shade50,
              borderRadius: BorderRadius.circular(8),
            ),
            child: const Text('هل تريد المتابعة رغم التحذير؟ (مسؤوليتك)',
                style: TextStyle(fontSize: 13, fontWeight: FontWeight.bold)),
          ),
        ]),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('إلغاء البيع')),
          FilledButton(
            style: FilledButton.styleFrom(backgroundColor: Colors.red),
            onPressed: () => Navigator.pop(context, true),
            child: const Text('تجاوز التحذير'),
          ),
        ],
      ),
    );
    return result == true;
  }

  void _showSuccessDialog() {
    final sale = c.lastSale.value;
    if (sale == null) return;
    final total = double.tryParse('${sale['total_amount'] ?? c.cartTotal}') ?? 0;
    final customer = sale['customer'] is Map
        ? Map<String, dynamic>.from(sale['customer'] as Map)
        : c.selectedCustomer.value;
    final ulid = '${sale['sale_ulid'] ?? ''}';
    Get.off(() => CashierReceiptScreen(
          sale: sale,
          total: total,
          method: '${sale['payment_method'] ?? _paymentMethod}',
          customerName: customer?['full_name']?.toString(),
          customerPhone: customer?['phone']?.toString(),
          invoiceTitle: _paymentMethod == 'credit' ? 'فاتورة صيدلية آجل' : 'فاتورة صيدلية',
          invoicePath: '/api/v1/amial/merchant/pharmacy/sales/$ulid/invoice',
          nextSalePage: () => const PharmacySaleScreen(),
        ));
  }

  void _showSnack(String msg, Color color) {
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(content: Text(msg), backgroundColor: color, duration: const Duration(seconds: 2)),
    );
  }

  /// يفتح المسح المستمر — كل صنف ممسوح يُضاف للسلّة تلقائياً.
  Future<void> _openScanner() async {
    final scanned = await Get.to<List<ScannedItem>>(
      () => const ContinuousScannerScreen(context: 'pharmacy', allowDuplicates: true),
    );
    if (scanned == null || scanned.isEmpty) return;

    // ابحث عن المنتجات الحقيقية في products list وأضفها للسلّة
    int added = 0;
    for (final item in scanned) {
      final product = c.products.firstWhereOrNull((p) => p['id'] == item.productId);
      if (product != null) {
        c.addToCart(product, item.quantity.toDouble());
        added++;
      }
    }
    if (!mounted) return;
    _showSnack('✓ أُضيف $added صنف للسلّة', Colors.green);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(
        title: const Text('بيع صيدلية'),
        actions: [
          // زر مسح الباركود السريع
          IconButton(
            icon: const Icon(Icons.qr_code_scanner),
            tooltip: 'مسح باركود مستمر',
            onPressed: _openScanner,
          ),
          Obx(() => c.cart.isEmpty ? const SizedBox.shrink() :
            IconButton(icon: const Icon(Icons.delete_sweep), onPressed: c.clearCart, tooltip: 'إفراغ السلّة')),
        ],
      ),
      body: Column(children: [
        // ====== شريط العميل ======
        Container(
          padding: const EdgeInsets.all(10),
          color: Colors.white,
          child: Obx(() {
            final customer = c.selectedCustomer.value;
            if (customer != null) {
              return Container(
                padding: const EdgeInsets.all(10),
                decoration: BoxDecoration(
                  color: AmialColors.primary.withValues(alpha: 0.08),
                  borderRadius: BorderRadius.circular(10),
                ),
                child: Row(children: [
                  const CircleAvatar(radius: 18, backgroundColor: AmialColors.primary,
                      child: Icon(Icons.person, color: Colors.white, size: 18)),
                  const SizedBox(width: 10),
                  Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Text(customer['full_name'] ?? '', style: const TextStyle(fontWeight: FontWeight.bold)),
                    if ((customer['allergies'] as List?)?.isNotEmpty == true)
                      Text('⚠️ حساسية: ${(customer['allergies'] as List).join(', ')}',
                          style: const TextStyle(color: Colors.red, fontSize: 11)),
                  ])),
                  IconButton(icon: const Icon(Icons.close, size: 18),
                      onPressed: () => c.selectedCustomer.value = null),
                ]),
              );
            }
            return Row(children: [
              Expanded(child: TextField(
                controller: _customerPhoneCtrl,
                keyboardType: TextInputType.phone,
                decoration: InputDecoration(
                  hintText: 'هاتف العميل (اختياري — لاستحضار الحساسيات)',
                  prefixIcon: const Icon(Icons.phone, size: 18),
                  isDense: true,
                  border: OutlineInputBorder(borderRadius: BorderRadius.circular(10)),
                ),
              )),
              const SizedBox(width: 8),
              IconButton.filled(
                onPressed: _lookupCustomer,
                icon: const Icon(Icons.search),
                style: IconButton.styleFrom(backgroundColor: AmialColors.primary),
              ),
            ]);
          }),
        ),

        // ====== شريط البحث عن المنتج ======
        Container(
          padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
          color: Colors.white,
          child: TextField(
            controller: _searchCtrl,
            textAlign: TextAlign.right,
            onChanged: (v) => _searchProducts(v),
            decoration: InputDecoration(
              hintText: 'ابحث عن منتج (اسم، باركود، SKU)',
              prefixIcon: _searching
                  ? const Padding(padding: EdgeInsets.all(12), child: SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2)))
                  : const Icon(Icons.search),
              isDense: true,
              filled: true,
              fillColor: AmialColors.background,
              border: OutlineInputBorder(borderRadius: BorderRadius.circular(10), borderSide: BorderSide.none),
            ),
          ),
        ),

        // ====== قائمة المنتجات ======
        Expanded(child: Obx(() {
          if (c.isLoading.value && c.products.isEmpty) {
            return const Center(child: CircularProgressIndicator());
          }
          if (c.products.isEmpty) {
            return Center(child: Column(mainAxisAlignment: MainAxisAlignment.center, children: [
              Icon(Icons.inventory_2, size: 48, color: Colors.grey.shade400),
              const SizedBox(height: 8),
              Text('لا توجد منتجات', style: TextStyle(color: Colors.grey.shade600)),
            ]));
          }
          return ListView.builder(
            padding: const EdgeInsets.all(8),
            itemCount: c.products.length,
            itemBuilder: (_, i) => _productTile(c.products[i]),
          );
        })),

        // ====== شريط السلّة + الإجمالي ======
        Obx(() => c.cart.isEmpty ? const SizedBox.shrink() : _cartBar()),
      ]),
    );
  }

  Widget _productTile(Map<String, dynamic> product) {
    final stock = double.tryParse('${product['current_stock']}') ?? 0;
    final outOfStock = stock <= 0;
    final price = product['sale_price'];
    final requiresPrescription = product['requires_prescription'] == true;

    return InkWell(
      onTap: outOfStock ? null : () => _showQuantityDialog(product),
      child: Container(
        margin: const EdgeInsets.only(bottom: 6),
        padding: const EdgeInsets.all(10),
        decoration: BoxDecoration(
          color: outOfStock ? Colors.grey.shade100 : Colors.white,
          borderRadius: BorderRadius.circular(10),
        ),
        child: Row(children: [
          Container(
            width: 40, height: 40,
            decoration: BoxDecoration(
              color: AmialColors.yellow.withValues(alpha: 0.2),
              borderRadius: BorderRadius.circular(8),
            ),
            child: const Icon(Icons.medication, color: AmialColors.yellowDark, size: 20),
          ),
          const SizedBox(width: 10),
          Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Row(children: [
              if (requiresPrescription) const Padding(
                padding: EdgeInsets.only(left: 4),
                child: Icon(Icons.medical_services, color: Colors.red, size: 14),
              ),
              Expanded(child: Text(product['trade_name'] ?? '',
                  style: TextStyle(fontWeight: FontWeight.bold,
                      color: outOfStock ? Colors.grey : Colors.black87))),
            ]),
            if (product['generic_name'] != null)
              Text(product['generic_name'],
                  style: TextStyle(color: Colors.grey.shade600, fontSize: 11)),
            Text('متوفر: ${stock.toStringAsFixed(0)} ${product['unit'] ?? ''}',
                style: TextStyle(
                  fontSize: 11,
                  color: outOfStock ? AmialColors.red : (stock <= 5 ? Colors.orange : Colors.green),
                  fontWeight: FontWeight.bold,
                )),
          ])),
          Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
            Text('$price', style: const TextStyle(fontWeight: FontWeight.bold, color: AmialColors.primary, fontSize: 15)),
            const Text('ر.ي', style: TextStyle(fontSize: 10, color: Colors.grey)),
          ]),
        ]),
      ),
    );
  }

  void _showQuantityDialog(Map<String, dynamic> product) {
    final stock = double.tryParse('${product['current_stock']}') ?? 0;
    final qtyCtrl = TextEditingController(text: '1');
    showDialog(context: context, builder: (ctx) => AlertDialog(
      title: Text(product['trade_name'] ?? ''),
      content: Column(mainAxisSize: MainAxisSize.min, children: [
        Text('المتوفر: ${stock.toStringAsFixed(0)} ${product['unit'] ?? ''}',
            style: const TextStyle(color: Colors.grey)),
        const SizedBox(height: 12),
        TextField(
          controller: qtyCtrl,
          keyboardType: TextInputType.number,
          inputFormatters: [FilteringTextInputFormatter.digitsOnly],
          autofocus: true,
          decoration: const InputDecoration(labelText: 'الكمية'),
        ),
      ]),
      actions: [
        TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('إلغاء')),
        FilledButton(
          onPressed: () {
            final qty = double.tryParse(qtyCtrl.text) ?? 0;
            if (qty <= 0) return;
            if (qty > stock) {
              _showSnack('الكمية أكبر من المتوفر', AmialColors.red);
              return;
            }
            c.addToCart(product, qty);
            Navigator.pop(ctx);
          },
          child: const Text('إضافة للسلّة'),
        ),
      ],
    ));
  }

  Widget _cartBar() {
    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: Colors.white,
        boxShadow: [BoxShadow(color: Colors.black.withValues(alpha: 0.05), blurRadius: 8, offset: const Offset(0, -2))],
      ),
      child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        // عناصر السلّة
        Container(
          constraints: const BoxConstraints(maxHeight: 120),
          child: ListView.builder(
            shrinkWrap: true,
            itemCount: c.cart.length,
            itemBuilder: (_, i) {
              final item = c.cart[i];
              final p = item['product'] as Map;
              final qty = item['quantity'];
              final price = double.tryParse('${p['sale_price']}') ?? 0;
              final line = price * (double.tryParse('$qty') ?? 0);
              return Padding(
                padding: const EdgeInsets.symmetric(vertical: 2),
                child: Row(children: [
                  IconButton(icon: const Icon(Icons.close, size: 16, color: Colors.red),
                      padding: EdgeInsets.zero, constraints: const BoxConstraints(),
                      onPressed: () => c.removeFromCart(item['product_id'])),
                  const SizedBox(width: 6),
                  Text('${line.toStringAsFixed(0)} ر.ي', style: const TextStyle(fontWeight: FontWeight.bold)),
                  const Spacer(),
                  Text('×$qty', style: const TextStyle(color: Colors.grey)),
                  const SizedBox(width: 8),
                  Expanded(child: Text(p['trade_name'] ?? '', textAlign: TextAlign.right,
                      overflow: TextOverflow.ellipsis)),
                ]),
              );
            },
          ),
        ),
        const Divider(),
        // تحذيرات
        if (c.cartRequiresPrescription || c.cartAllergyConflicts.isNotEmpty)
          Padding(
            padding: const EdgeInsets.symmetric(vertical: 4),
            child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              if (c.cartRequiresPrescription)
                Container(
                  padding: const EdgeInsets.all(6),
                  decoration: BoxDecoration(color: Colors.orange.shade50, borderRadius: BorderRadius.circular(6)),
                  child: const Row(children: [
                    Icon(Icons.medical_services, color: Colors.orange, size: 14),
                    SizedBox(width: 4),
                    Text('السلّة تستلزم وصفة طبية', style: TextStyle(fontSize: 11, color: Colors.orange)),
                  ]),
                ),
              if (c.cartAllergyConflicts.isNotEmpty)
                Container(
                  margin: const EdgeInsets.only(top: 4),
                  padding: const EdgeInsets.all(6),
                  decoration: BoxDecoration(color: Colors.red.shade50, borderRadius: BorderRadius.circular(6)),
                  child: Row(children: [
                    const Icon(Icons.warning_amber, color: Colors.red, size: 14),
                    const SizedBox(width: 4),
                    Expanded(child: Text(
                      '⚠️ تعارض حساسية في ${c.cartAllergyConflicts.length} منتج',
                      style: const TextStyle(fontSize: 11, color: Colors.red, fontWeight: FontWeight.bold),
                    )),
                  ]),
                ),
            ]),
          ),
        // الإجمالي + الدفع
        Row(children: [
          Text(c.cartTotal.toStringAsFixed(0),
              style: const TextStyle(fontSize: 22, fontWeight: FontWeight.bold, color: AmialColors.primary)),
          const SizedBox(width: 4),
          const Text('ر.ي', style: TextStyle(fontSize: 12)),
          const Spacer(),
          const Text('الإجمالي:', style: TextStyle(color: Colors.grey)),
        ]),
        // إن يحتاج وصفة
        if (c.cartRequiresPrescription) ...[
          const SizedBox(height: 6),
          TextField(
            controller: _prescriptionCtrl,
            textAlign: TextAlign.right,
            decoration: InputDecoration(
              labelText: 'رقم الوصفة *',
              isDense: true,
              border: OutlineInputBorder(borderRadius: BorderRadius.circular(8)),
            ),
          ),
        ],
        const SizedBox(height: 8),
        // طرق الدفع
        Row(children: [
          Expanded(child: _payTile('cash', Icons.payments, 'نقد')),
          const SizedBox(width: 4),
          Expanded(child: _payTile('amial_pay', Icons.qr_code, 'أميال')),
          const SizedBox(width: 4),
          Expanded(child: _payTile('credit', Icons.event, 'آجل')),
        ]),
        if (_paymentMethod == 'credit') ...[
          const SizedBox(height: 10),
          if (c.selectedCustomer.value != null)
            Container(
              padding: const EdgeInsets.all(10),
              decoration: BoxDecoration(
                color: AmialColors.primary.withValues(alpha: 0.08),
                borderRadius: BorderRadius.circular(8),
              ),
              child: Text('الفاتورة الآجلة مرتبطة بـ ${c.selectedCustomer.value!['full_name']} — ${c.selectedCustomer.value!['phone']}',
                  textAlign: TextAlign.right),
            )
          else ...[
            TextField(
              controller: _creditPhoneCtrl,
              keyboardType: TextInputType.phone,
              textAlign: TextAlign.right,
              decoration: InputDecoration(
                labelText: 'رقم العميل *',
                helperText: 'تظهر الفاتورة في «فواتيري الآجلة» لهذا الرقم',
                isDense: true,
                border: OutlineInputBorder(borderRadius: BorderRadius.circular(8)),
              ),
            ),
            const SizedBox(height: 8),
            TextField(
              controller: _creditNameCtrl,
              textAlign: TextAlign.right,
              decoration: InputDecoration(
                labelText: 'اسم العميل (اختياري)',
                isDense: true,
                border: OutlineInputBorder(borderRadius: BorderRadius.circular(8)),
              ),
            ),
          ],
        ],
        const SizedBox(height: 8),
        Obx(() => FilledButton.icon(
          onPressed: c.isSubmitting.value ? null : _submitSale,
          icon: c.isSubmitting.value
              ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
              : const Icon(Icons.check),
          label: const Text('تأكيد البيع', style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold)),
          style: FilledButton.styleFrom(
            backgroundColor: AmialColors.primary,
            minimumSize: const Size.fromHeight(48),
          ),
        )),
      ]),
    );
  }

  Widget _payTile(String v, IconData icon, String label) {
    final selected = _paymentMethod == v;
    return InkWell(
      borderRadius: BorderRadius.circular(8),
      onTap: () => setState(() => _paymentMethod = v),
      child: Container(
        padding: const EdgeInsets.symmetric(vertical: 8),
        decoration: BoxDecoration(
          color: selected ? AmialColors.primary : Colors.white,
          borderRadius: BorderRadius.circular(8),
          border: Border.all(color: selected ? AmialColors.primary : Colors.grey.shade300),
        ),
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          Icon(icon, color: selected ? Colors.white : AmialColors.primary, size: 18),
          Text(label, style: TextStyle(color: selected ? Colors.white : Colors.black, fontSize: 11)),
        ]),
      ),
    );
  }
}
