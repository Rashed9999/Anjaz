import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/features/merchant/controllers/cashier_controller.dart';
import 'package:amyal_pay/features/merchant/screens/cashier_products_screen.dart';
import 'package:amyal_pay/features/merchant/screens/cashier_report_screen.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';

/// AMIAL-CASHIER-001 — شاشة البيع: سلة + اختيار طريقة الدفع (نقد/أجل/أميال باي).
class CashierSaleScreen extends StatefulWidget {
  const CashierSaleScreen({super.key});

  @override
  State<CashierSaleScreen> createState() => _CashierSaleScreenState();
}

class _CashierSaleScreenState extends State<CashierSaleScreen> {
  final _freeAmountCtrl = TextEditingController();

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      Get.find<CashierController>().loadProducts();
    });
  }

  @override
  void dispose() {
    _freeAmountCtrl.dispose();
    super.dispose();
  }

  CashierController get c => Get.find<CashierController>();

  double _effectiveTotal() {
    if (c.cart.isNotEmpty) return c.cartTotal;
    return double.tryParse(_freeAmountCtrl.text.trim()) ?? 0;
  }

  Future<void> _choosePayment() async {
    final total = _effectiveTotal();
    if (total <= 0) {
      _snack('أضف منتجات أو أدخل مبلغاً');
      return;
    }

    final method = await showModalBottomSheet<String>(
      context: context,
      builder: (ctx) => SafeArea(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Padding(
              padding: const EdgeInsets.all(16),
              child: Text('الإجمالي: ${total.toStringAsFixed(2)} ر.ي',
                  style: const TextStyle(fontSize: 18, fontWeight: FontWeight.bold)),
            ),
            ListTile(
              leading: const Icon(Icons.payments, color: Colors.green),
              title: const Text('نقد'),
              onTap: () => Navigator.pop(ctx, 'cash'),
            ),
            ListTile(
              leading: const Icon(Icons.schedule, color: AmyalColors.yellowDark),
              title: const Text('أجل (دَين على العميل)'),
              onTap: () => Navigator.pop(ctx, 'credit'),
            ),
            ListTile(
              leading: const Icon(Icons.qr_code_2, color: AmyalColors.primary),
              title: const Text('أميال باي (QR)'),
              subtitle: const Text('العميل يدفع من تطبيقه'),
              onTap: () => Navigator.pop(ctx, 'amial_pay'),
            ),
            const SizedBox(height: 8),
          ],
        ),
      ),
    );
    if (method == null) return;

    if (method == 'cash') {
      await _record(total, 'cash');
    } else if (method == 'credit') {
      await _creditFlow(total);
    } else {
      await _amialPayFlow(total);
    }
  }

  Future<void> _amialPayFlow(double total) async {
    // ننشئ بيعاً معلّقاً (pending_payment) ونعرض مرجعه؛ يُربط تلقائياً
    // عند دفع العميل عبر QR التاجر بتمرير sale_ulid.
    final sale = await c.recordSale(total: total, method: 'amial_pay');
    if (!mounted) return;
    if (sale == null) {
      _snack(c.lastError.value.isNotEmpty ? c.lastError.value : 'تعذّر إنشاء البيع');
      return;
    }
    _freeAmountCtrl.clear();
    showDialog(
      context: context,
      builder: (ctx) => AlertDialog(
        icon: const Icon(Icons.qr_code_2, color: AmyalColors.primary, size: 48),
        title: const Text('بانتظار دفع العميل', textAlign: TextAlign.center),
        content: Text(
          'اطلب من العميل دفع ${total.toStringAsFixed(2)} ر.ي عبر مسح QR التاجر. '
          'تُربط العملية تلقائياً بهذا البيع عند نجاح الدفع.\n\nمرجع البيع: ${sale['sale_ulid'] ?? ''}',
          textAlign: TextAlign.center,
          style: const TextStyle(fontSize: 13),
        ),
        actions: [TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('حسناً'))],
      ),
    );
  }

  Future<void> _creditFlow(double total) async {
    final nameCtrl = TextEditingController();
    final phoneCtrl = TextEditingController();
    DateTime? dueDate;
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setSt) => AlertDialog(
          title: const Text('بيع أجل — بيانات العميل'),
          content: SingleChildScrollView(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                TextField(controller: nameCtrl, textAlign: TextAlign.right, decoration: const InputDecoration(labelText: 'اسم العميل *')),
                const SizedBox(height: 8),
                TextField(controller: phoneCtrl, keyboardType: TextInputType.phone, textAlign: TextAlign.right, decoration: const InputDecoration(labelText: 'رقم العميل *', hintText: '+967 7X XXX XXXX')),
                const SizedBox(height: 12),
                Row(children: [
                  Expanded(
                    child: OutlinedButton.icon(
                      icon: const Icon(Icons.calendar_today, size: 16),
                      label: Text(dueDate == null ? 'تاريخ الاستحقاق (اختياري)' : '${dueDate!.year}-${dueDate!.month.toString().padLeft(2, '0')}-${dueDate!.day.toString().padLeft(2, '0')}'),
                      onPressed: () async {
                        final picked = await showDatePicker(
                          context: ctx,
                          initialDate: DateTime.now().add(const Duration(days: 30)),
                          firstDate: DateTime.now(),
                          lastDate: DateTime.now().add(const Duration(days: 365)),
                        );
                        if (picked != null) setSt(() => dueDate = picked);
                      },
                    ),
                  ),
                  if (dueDate != null)
                    IconButton(icon: const Icon(Icons.clear, size: 18), onPressed: () => setSt(() => dueDate = null)),
                ]),
              ],
            ),
          ),
          actions: [
            TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('إلغاء')),
            ElevatedButton(
              onPressed: () {
                // الـ backend يلزم اسم AND رقم
                if (nameCtrl.text.trim().isEmpty || phoneCtrl.text.trim().isEmpty) {
                  ScaffoldMessenger.of(ctx).showSnackBar(
                    const SnackBar(content: Text('اسم العميل ورقمه مطلوبان'), backgroundColor: Colors.red),
                  );
                  return;
                }
                Navigator.pop(ctx, true);
              },
              child: const Text('تسجيل الأجل'),
            ),
          ],
        ),
      ),
    );
    if (ok == true) {
      await _record(
        total, 'credit',
        customer: {'name': nameCtrl.text.trim(), 'phone': phoneCtrl.text.trim()},
        creditDueDate: dueDate == null ? null : '${dueDate!.year}-${dueDate!.month.toString().padLeft(2, '0')}-${dueDate!.day.toString().padLeft(2, '0')}',
      );
    }
  }

  Future<void> _record(double total, String method, {Map<String, String>? customer, String? creditDueDate}) async {
    final sale = await c.recordSale(total: total, method: method, customer: customer, creditDueDate: creditDueDate);
    if (!mounted) return;
    if (sale != null) {
      _freeAmountCtrl.clear();
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('تم تسجيل البيع'), backgroundColor: Colors.green));
    } else {
      _snack(c.lastError.value.isNotEmpty ? c.lastError.value : 'فشل تسجيل البيع');
    }
  }

  void _snack(String m) => ScaffoldMessenger.of(context)
      .showSnackBar(SnackBar(content: Text(m), backgroundColor: AmyalColors.red));

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmyalColors.background,
      appBar: AppBar(
        backgroundColor: AmyalColors.primary,
        foregroundColor: Colors.white,
        title: const Text('الكاشير'),
        actions: [
          IconButton(
            tooltip: 'المنتجات',
            icon: const Icon(Icons.inventory_2_outlined),
            onPressed: () => Get.to(() => const CashierProductsScreen()),
          ),
          IconButton(
            tooltip: 'تقرير اليوم',
            icon: const Icon(Icons.bar_chart),
            onPressed: () => Get.to(() => const CashierReportScreen()),
          ),
        ],
      ),
      body: Column(
        children: [
          // مبلغ حرّ (عند عدم استخدام منتجات)
          Padding(
            padding: const EdgeInsets.all(12),
            child: TextField(
              controller: _freeAmountCtrl,
              keyboardType: const TextInputType.numberWithOptions(decimal: true),
              enabled: c.cart.isEmpty,
              decoration: const InputDecoration(
                labelText: 'مبلغ حرّ (أو اختر منتجات)',
                prefixIcon: Icon(Icons.edit),
                border: OutlineInputBorder(),
              ),
            ),
          ),
          // المنتجات السريعة
          SizedBox(
            height: 96,
            child: Obx(() {
              if (c.products.isEmpty) {
                return const Center(child: Text('لا منتجات — استخدم المبلغ الحرّ أو أضف منتجات',
                    style: TextStyle(color: AmyalColors.textMuted, fontSize: 12)));
              }
              return ListView.separated(
                scrollDirection: Axis.horizontal,
                padding: const EdgeInsets.symmetric(horizontal: 12),
                itemCount: c.products.length,
                separatorBuilder: (_, __) => const SizedBox(width: 8),
                itemBuilder: (_, i) {
                  final p = c.products[i];
                  return InkWell(
                    onTap: () => c.addToCart((p['name'] ?? '').toString(),
                        double.tryParse((p['price'] ?? '0').toString()) ?? 0),
                    child: Container(
                      width: 110,
                      padding: const EdgeInsets.all(8),
                      decoration: BoxDecoration(
                        color: AmyalColors.cardSurface,
                        borderRadius: BorderRadius.circular(8),
                        border: Border.all(color: AmyalColors.border),
                      ),
                      child: Column(
                        mainAxisAlignment: MainAxisAlignment.center,
                        children: [
                          Text((p['name'] ?? '').toString(),
                              maxLines: 2, overflow: TextOverflow.ellipsis,
                              textAlign: TextAlign.center, style: const TextStyle(fontSize: 12)),
                          const SizedBox(height: 4),
                          Text('${p['price'] ?? ''}',
                              style: const TextStyle(fontWeight: FontWeight.bold, color: AmyalColors.primary)),
                        ],
                      ),
                    ),
                  );
                },
              );
            }),
          ),
          const Divider(),
          // السلة
          Expanded(
            child: Obx(() {
              if (c.cart.isEmpty) {
                return const Center(child: Text('السلة فارغة', style: TextStyle(color: AmyalColors.textMuted)));
              }
              return ListView.builder(
                itemCount: c.cart.length,
                itemBuilder: (_, i) {
                  final l = c.cart[i];
                  return ListTile(
                    title: Text(l.name),
                    subtitle: Text('${l.price} × ${l.qty}'),
                    trailing: Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Text('${l.lineTotal.toStringAsFixed(2)} ر.ي',
                            style: const TextStyle(fontWeight: FontWeight.bold)),
                        IconButton(
                          icon: const Icon(Icons.close, size: 18, color: AmyalColors.red),
                          onPressed: () => c.removeLine(i),
                        ),
                      ],
                    ),
                  );
                },
              );
            }),
          ),
          // الإجمالي + زر الدفع
          Obx(() => Container(
                padding: const EdgeInsets.all(16),
                decoration: const BoxDecoration(
                  color: AmyalColors.cardSurface,
                  border: Border(top: BorderSide(color: AmyalColors.border)),
                ),
                child: Column(
                  children: [
                    Row(
                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                      children: [
                        const Text('الإجمالي', style: TextStyle(fontSize: 16)),
                        Text('${_effectiveTotal().toStringAsFixed(2)} ر.ي',
                            style: const TextStyle(fontSize: 20, fontWeight: FontWeight.bold, color: AmyalColors.primary)),
                      ],
                    ),
                    const SizedBox(height: 10),
                    SizedBox(
                      width: double.infinity,
                      child: ElevatedButton(
                        onPressed: c.isSubmitting.value ? null : _choosePayment,
                        style: ElevatedButton.styleFrom(
                          backgroundColor: AmyalColors.primary,
                          foregroundColor: Colors.white,
                          padding: const EdgeInsets.symmetric(vertical: 14),
                        ),
                        child: c.isSubmitting.value
                            ? const SizedBox(height: 20, width: 20, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                            : const Text('اختر طريقة الدفع', style: TextStyle(fontSize: 16)),
                      ),
                    ),
                  ],
                ),
              )),
        ],
      ),
    );
  }
}
