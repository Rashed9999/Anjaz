import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';
import 'package:amyal_pay/features/wholesale/controllers/wholesale_controller.dart';
import 'package:amyal_pay/features/wholesale/screens/wholesale_screens_secondary.dart';
import 'package:amyal_pay/features/barcode/screens/continuous_scanner_screen.dart';

// =========================================================================
// 1) Dashboard
// =========================================================================
class WholesaleDashboardScreen extends StatefulWidget {
  const WholesaleDashboardScreen({super.key});
  @override
  State<WholesaleDashboardScreen> createState() => _WholesaleDashboardScreenState();
}

class _WholesaleDashboardScreenState extends State<WholesaleDashboardScreen> {
  late final WholesaleController c;

  @override
  void initState() {
    super.initState();
    c = Get.find<WholesaleController>();
    WidgetsBinding.instance.addPostFrameCallback((_) async {
      await c.loadBusiness();
      await c.loadDashboard();
    });
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmyalColors.background,
      appBar: AppBar(
        title: const Text('الجملة'),
        backgroundColor: AmyalColors.primary,
        foregroundColor: Colors.white,
      ),
      body: RefreshIndicator(
        onRefresh: () async { await c.loadBusiness(); await c.loadDashboard(); },
        child: SingleChildScrollView(
          physics: const AlwaysScrollableScrollPhysics(),
          padding: const EdgeInsets.all(16),
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            // بطاقة Business
            Obx(() {
              final b = c.business.value;
              return Container(
                padding: const EdgeInsets.all(16),
                decoration: BoxDecoration(
                  gradient: const LinearGradient(
                    colors: [Color(0xFFE65100), Color(0xFFBF360C)],
                    begin: Alignment.topLeft, end: Alignment.bottomRight,
                  ),
                  borderRadius: BorderRadius.circular(14),
                ),
                child: Row(children: [
                  Container(width: 50, height: 50,
                      decoration: BoxDecoration(color: AmyalColors.yellow, borderRadius: BorderRadius.circular(12)),
                      child: const Icon(Icons.warehouse, color: Colors.black87, size: 28)),
                  const SizedBox(width: 12),
                  Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Text(b?['business_name'] ?? 'متجر جملة',
                        style: const TextStyle(color: Colors.white, fontSize: 18, fontWeight: FontWeight.bold)),
                    if (b?['city'] != null)
                      Text(b!['city'], style: const TextStyle(color: Colors.white70, fontSize: 12)),
                  ])),
                ]),
              );
            }),

            const SizedBox(height: 14),

            // إحصائيات
            Obx(() {
              final d = c.dashboardData.value;
              return Column(children: [
                Row(children: [
                  Expanded(child: _statBox('${d?['today']?['invoices_count'] ?? 0}',
                      'فواتير اليوم', AmyalColors.primary, Icons.receipt)),
                  const SizedBox(width: 6),
                  Expanded(child: _statBox('${d?['today']?['total_amount'] ?? 0}',
                      'مبيعات اليوم', AmyalColors.yellowDark, Icons.attach_money)),
                ]),
                const SizedBox(height: 8),
                Row(children: [
                  Expanded(child: _statBox('${d?['products_count'] ?? 0}',
                      'منتجات', Colors.blue.shade700, Icons.inventory_2)),
                  const SizedBox(width: 6),
                  Expanded(child: _statBox('${d?['customers_count'] ?? 0}',
                      'عملاء', Colors.indigo, Icons.people)),
                ]),
                if ((d?['overdue_count'] ?? 0) > 0) ...[
                  const SizedBox(height: 8),
                  Container(
                    padding: const EdgeInsets.all(12),
                    decoration: BoxDecoration(color: Colors.red.shade50, borderRadius: BorderRadius.circular(10)),
                    child: Row(children: [
                      const Icon(Icons.warning_amber, color: Colors.red),
                      const SizedBox(width: 8),
                      Expanded(child: Text(
                        '${d!['overdue_count']} فواتير متأخّرة بقيمة ${d['overdue_amount']} ر.ي',
                        style: const TextStyle(fontWeight: FontWeight.bold, color: Colors.red),
                      )),
                    ]),
                  ),
                ],
                const SizedBox(height: 8),
                Container(
                  padding: const EdgeInsets.all(12),
                  decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(10)),
                  child: Row(children: [
                    const Icon(Icons.account_balance, color: AmyalColors.primary),
                    const SizedBox(width: 8),
                    Expanded(child: Text('إجمالي المستحقّات: ${d?['total_receivable'] ?? '0'} ر.ي',
                        style: const TextStyle(fontWeight: FontWeight.bold))),
                  ]),
                ),
              ]);
            }),

            const SizedBox(height: 16),

            // الإجراء الأساسي
            _bigAction(Icons.receipt_long, 'فاتورة جديدة', 'إنشاء فاتورة بيع',
                AmyalColors.primary, () => Get.to(() => const WholesaleInvoiceCreateScreen())),

            const SizedBox(height: 10),
            Row(children: [
              Expanded(child: _miniAction(Icons.inventory_2, 'المنتجات',
                  () => Get.to(() => const WholesaleProductsScreen()))),
              const SizedBox(width: 8),
              Expanded(child: _miniAction(Icons.people, 'العملاء',
                  () => Get.to(() => const WholesaleCustomersScreen()))),
            ]),
            const SizedBox(height: 8),
            Row(children: [
              Expanded(child: _miniAction(Icons.list_alt, 'الفواتير',
                  () => Get.to(() => const WholesaleInvoicesListScreen()))),
              const SizedBox(width: 8),
              Expanded(child: _miniAction(Icons.bar_chart, 'التقارير',
                  () => Get.to(() => const WholesaleAgingReportScreen()))),
            ]),
          ]),
        ),
      ),
    );
  }

  Widget _statBox(String value, String label, Color color, IconData icon) => Container(
    padding: const EdgeInsets.all(10),
    decoration: BoxDecoration(color: color.withValues(alpha: 0.1), borderRadius: BorderRadius.circular(10)),
    child: Column(children: [
      Icon(icon, color: color, size: 20),
      const SizedBox(height: 4),
      Text(value, style: TextStyle(color: color, fontWeight: FontWeight.bold, fontSize: 14),
          textAlign: TextAlign.center, overflow: TextOverflow.ellipsis),
      Text(label, style: TextStyle(color: Colors.grey.shade700, fontSize: 10)),
    ]),
  );

  Widget _bigAction(IconData icon, String label, String subtitle, Color color, VoidCallback onTap) {
    return InkWell(
      borderRadius: BorderRadius.circular(14),
      onTap: onTap,
      child: Container(
        padding: const EdgeInsets.all(16),
        decoration: BoxDecoration(color: color, borderRadius: BorderRadius.circular(14)),
        child: Row(children: [
          Container(width: 46, height: 46,
              decoration: BoxDecoration(color: Colors.white.withValues(alpha: 0.2), borderRadius: BorderRadius.circular(12)),
              child: Icon(icon, color: Colors.white, size: 26)),
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

  Widget _miniAction(IconData icon, String label, VoidCallback onTap) => InkWell(
    borderRadius: BorderRadius.circular(12),
    onTap: onTap,
    child: Container(
      padding: const EdgeInsets.symmetric(vertical: 14),
      decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(12)),
      child: Column(children: [
        Icon(icon, color: AmyalColors.primary, size: 22),
        const SizedBox(height: 4),
        Text(label, style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 12)),
      ]),
    ),
  );
}

// =========================================================================
// 2) Products
// =========================================================================
class WholesaleProductsScreen extends StatefulWidget {
  const WholesaleProductsScreen({super.key});
  @override
  State<WholesaleProductsScreen> createState() => _WholesaleProductsScreenState();
}

class _WholesaleProductsScreenState extends State<WholesaleProductsScreen> {
  late final WholesaleController c;

  @override
  void initState() {
    super.initState();
    c = Get.find<WholesaleController>();
    WidgetsBinding.instance.addPostFrameCallback((_) => c.loadProducts());
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmyalColors.background,
      appBar: AppBar(
        title: const Text('المنتجات'),
        backgroundColor: AmyalColors.primary,
        foregroundColor: Colors.white,
      ),
      floatingActionButton: FloatingActionButton.extended(
        backgroundColor: AmyalColors.primary,
        onPressed: _addProductDialog,
        icon: const Icon(Icons.add),
        label: const Text('منتج جديد'),
      ),
      body: Obx(() {
        if (c.isLoading.value && c.products.isEmpty) return const Center(child: CircularProgressIndicator());
        if (c.products.isEmpty) {
          return Center(child: Column(mainAxisAlignment: MainAxisAlignment.center, children: [
            Icon(Icons.inventory_2, size: 64, color: Colors.grey.shade400),
            const SizedBox(height: 12),
            Text('لا توجد منتجات', style: TextStyle(color: Colors.grey.shade600)),
          ]));
        }
        return ListView.builder(
          padding: const EdgeInsets.all(12),
          itemCount: c.products.length,
          itemBuilder: (_, i) => _productCard(c.products[i]),
        );
      }),
    );
  }

  Widget _productCard(Map<String, dynamic> p) {
    final stock = double.tryParse('${p['current_stock']}') ?? 0;
    final isLow = stock <= (p['low_stock_threshold'] ?? 10);

    return Container(
      margin: const EdgeInsets.only(bottom: 8),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(10)),
      child: Row(children: [
        Container(width: 40, height: 40,
            decoration: BoxDecoration(color: AmyalColors.yellow.withValues(alpha: 0.2), borderRadius: BorderRadius.circular(8)),
            child: const Icon(Icons.inventory_2, color: AmyalColors.yellowDark)),
        const SizedBox(width: 10),
        Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(p['name'] ?? '', style: const TextStyle(fontWeight: FontWeight.bold)),
          if (p['barcode'] != null)
            Text(p['barcode'], style: TextStyle(color: Colors.grey.shade600, fontSize: 11, fontFamily: 'monospace')),
          Text('${stock.toStringAsFixed(0)} ${p['unit'] ?? ''} • ${p['base_price']} ر.ي',
              style: TextStyle(
                color: isLow ? AmyalColors.red : Colors.green.shade700,
                fontSize: 12, fontWeight: FontWeight.bold,
              )),
        ])),
      ]),
    );
  }

  void _addProductDialog() {
    final name = TextEditingController();
    final barcode = TextEditingController();
    final price = TextEditingController();
    final stock = TextEditingController(text: '0');

    showDialog(context: context, builder: (ctx) => AlertDialog(
      title: const Text('منتج جديد'),
      content: SingleChildScrollView(child: Column(mainAxisSize: MainAxisSize.min, children: [
        TextField(controller: name, decoration: const InputDecoration(labelText: 'الاسم *')),
        const SizedBox(height: 8),
        TextField(controller: barcode, decoration: const InputDecoration(labelText: 'الباركود (اختياري)')),
        const SizedBox(height: 8),
        TextField(controller: price, keyboardType: TextInputType.number,
            decoration: const InputDecoration(labelText: 'السعر الأساسي *', suffixText: 'ر.ي')),
        const SizedBox(height: 8),
        TextField(controller: stock, keyboardType: TextInputType.number,
            decoration: const InputDecoration(labelText: 'المخزون الابتدائي')),
      ])),
      actions: [
        TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('إلغاء')),
        Obx(() => FilledButton(
          onPressed: c.isSubmitting.value ? null : () async {
            if (name.text.isEmpty || price.text.isEmpty) return;
            final ok = await c.addProduct({
              'name': name.text.trim(),
              if (barcode.text.isNotEmpty) 'barcode': barcode.text.trim(),
              'base_price': price.text,
              'initial_stock': stock.text.isEmpty ? '0' : stock.text,
            });
            if (!mounted) return;
            if (ok) {
              Navigator.pop(ctx);
            } else {
              ScaffoldMessenger.of(context).showSnackBar(
              SnackBar(content: Text(c.lastError.value), backgroundColor: AmyalColors.red));
            }
          },
          child: const Text('إضافة'),
        )),
      ],
    ));
  }
}

// =========================================================================
// 3) Customers
// =========================================================================
class WholesaleCustomersScreen extends StatefulWidget {
  const WholesaleCustomersScreen({super.key});
  @override
  State<WholesaleCustomersScreen> createState() => _WholesaleCustomersScreenState();
}

class _WholesaleCustomersScreenState extends State<WholesaleCustomersScreen> {
  late final WholesaleController c;
  bool _withBalanceOnly = false;

  @override
  void initState() {
    super.initState();
    c = Get.find<WholesaleController>();
    WidgetsBinding.instance.addPostFrameCallback((_) => c.loadCustomers());
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmyalColors.background,
      appBar: AppBar(
        title: const Text('العملاء'),
        backgroundColor: AmyalColors.primary,
        foregroundColor: Colors.white,
        actions: [
          IconButton(
            icon: Icon(_withBalanceOnly ? Icons.filter_alt : Icons.filter_alt_outlined),
            tooltip: 'عليه دين فقط',
            onPressed: () { setState(() => _withBalanceOnly = !_withBalanceOnly);
              c.loadCustomers(withBalanceOnly: _withBalanceOnly); },
          ),
        ],
      ),
      floatingActionButton: FloatingActionButton.extended(
        backgroundColor: AmyalColors.primary,
        onPressed: _addCustomerDialog,
        icon: const Icon(Icons.add),
        label: const Text('عميل جديد'),
      ),
      body: Obx(() {
        if (c.isLoading.value && c.customers.isEmpty) return const Center(child: CircularProgressIndicator());
        if (c.customers.isEmpty) {
          return Center(child: Text('لا يوجد عملاء', style: TextStyle(color: Colors.grey.shade600)));
        }
        return ListView.builder(
          padding: const EdgeInsets.all(12),
          itemCount: c.customers.length,
          itemBuilder: (_, i) => _customerCard(c.customers[i]),
        );
      }),
    );
  }

  Widget _customerCard(Map<String, dynamic> cust) {
    final balance = double.tryParse('${cust['current_balance']}') ?? 0;
    final limit = double.tryParse('${cust['credit_limit']}') ?? 0;

    return InkWell(
      onTap: () => Get.to(() => WholesaleCustomerStatementScreen(customer: cust)),
      child: Container(
        margin: const EdgeInsets.only(bottom: 8),
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(10)),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(cust['full_name'] ?? '', style: const TextStyle(fontWeight: FontWeight.bold)),
          if (cust['company_name'] != null)
            Text(cust['company_name'], style: TextStyle(color: Colors.grey.shade700, fontSize: 12)),
          if (cust['phone'] != null)
            Text(cust['phone'], style: TextStyle(color: Colors.grey.shade600, fontSize: 11)),
          const SizedBox(height: 6),
          Row(children: [
            if (balance > 0) Container(
              padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
              decoration: BoxDecoration(color: AmyalColors.red.withValues(alpha: 0.15), borderRadius: BorderRadius.circular(4)),
              child: Text('عليه: ${balance.toStringAsFixed(0)} ر.ي',
                  style: const TextStyle(color: AmyalColors.red, fontSize: 11, fontWeight: FontWeight.bold)),
            ),
            const SizedBox(width: 6),
            if (limit > 0) Container(
              padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
              decoration: BoxDecoration(color: AmyalColors.primary.withValues(alpha: 0.1), borderRadius: BorderRadius.circular(4)),
              child: Text('حدّ: ${limit.toStringAsFixed(0)}',
                  style: const TextStyle(color: AmyalColors.primary, fontSize: 11, fontWeight: FontWeight.bold)),
            ),
          ]),
        ]),
      ),
    );
  }

  void _addCustomerDialog() {
    final name = TextEditingController();
    final company = TextEditingController();
    final phone = TextEditingController();
    final creditLimit = TextEditingController(text: '0');
    final terms = TextEditingController(text: '30');

    showDialog(context: context, builder: (ctx) => AlertDialog(
      title: const Text('عميل جديد'),
      content: SingleChildScrollView(child: Column(mainAxisSize: MainAxisSize.min, children: [
        TextField(controller: name, decoration: const InputDecoration(labelText: 'الاسم *')),
        const SizedBox(height: 8),
        TextField(controller: company, decoration: const InputDecoration(labelText: 'اسم الشركة')),
        const SizedBox(height: 8),
        TextField(controller: phone, keyboardType: TextInputType.phone,
            decoration: const InputDecoration(labelText: 'الهاتف')),
        const SizedBox(height: 8),
        TextField(controller: creditLimit, keyboardType: TextInputType.number,
            decoration: const InputDecoration(labelText: 'حدّ الائتمان', suffixText: 'ر.ي', helperText: '0 = نقد فقط')),
        const SizedBox(height: 8),
        TextField(controller: terms, keyboardType: TextInputType.number,
            decoration: const InputDecoration(labelText: 'مهلة السداد (يوم)')),
      ])),
      actions: [
        TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('إلغاء')),
        Obx(() => FilledButton(
          onPressed: c.isSubmitting.value ? null : () async {
            if (name.text.isEmpty) return;
            final ok = await c.addCustomer({
              'full_name': name.text.trim(),
              if (company.text.isNotEmpty) 'company_name': company.text.trim(),
              if (phone.text.isNotEmpty) 'phone': phone.text.trim(),
              'credit_limit': creditLimit.text.isEmpty ? '0' : creditLimit.text,
              'payment_terms_days': int.tryParse(terms.text) ?? 30,
            });
            if (!mounted) return;
            if (ok) {
              Navigator.pop(ctx);
            } else {
              ScaffoldMessenger.of(context).showSnackBar(
              SnackBar(content: Text(c.lastError.value), backgroundColor: AmyalColors.red));
            }
          },
          child: const Text('إضافة'),
        )),
      ],
    ));
  }
}

// =========================================================================
// 4) Invoice Create (مع Continuous Scanner)
// =========================================================================
class WholesaleInvoiceCreateScreen extends StatefulWidget {
  const WholesaleInvoiceCreateScreen({super.key});
  @override
  State<WholesaleInvoiceCreateScreen> createState() => _WholesaleInvoiceCreateScreenState();
}

class _WholesaleInvoiceCreateScreenState extends State<WholesaleInvoiceCreateScreen> {
  late final WholesaleController c;
  String _paymentType = 'credit';

  @override
  void initState() {
    super.initState();
    c = Get.find<WholesaleController>();
    c.clearCart();
    WidgetsBinding.instance.addPostFrameCallback((_) async {
      await c.loadProducts();
      await c.loadCustomers();
    });
  }

  Future<void> _openScanner() async {
    final scanned = await Get.to<List<ScannedItem>>(
      () => const ContinuousScannerScreen(context: 'wholesale', allowDuplicates: true),
    );
    if (scanned == null || scanned.isEmpty) return;

    int added = 0;
    for (final item in scanned) {
      final product = c.products.firstWhereOrNull((p) => p['id'] == item.productId);
      if (product != null) {
        c.addToCart(product, item.quantity.toDouble());
        added++;
      }
    }
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
      content: Text('✓ أُضيف $added صنف للسلّة'), backgroundColor: Colors.green));
  }

  Future<void> _submit() async {
    if (c.selectedCustomer.value == null) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('اختر عميلاً أوّلاً'), backgroundColor: AmyalColors.red));
      return;
    }
    if (c.cart.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('السلّة فارغة'), backgroundColor: AmyalColors.red));
      return;
    }
    final ok = await c.createInvoice(paymentType: _paymentType);
    if (!mounted) return;
    if (ok) {
      final inv = c.currentInvoice.value;
      showDialog(context: context, builder: (_) => AlertDialog(
        title: const Row(children: [
          Icon(Icons.check_circle, color: Colors.green, size: 28),
          SizedBox(width: 8), Text('تم إنشاء الفاتورة'),
        ]),
        content: Column(mainAxisSize: MainAxisSize.min, children: [
          Text(inv?['invoice_number'] ?? '',
              style: const TextStyle(fontFamily: 'monospace', fontSize: 16, fontWeight: FontWeight.bold)),
          const SizedBox(height: 8),
          Text('${inv?['total_amount']} ر.ي',
              style: const TextStyle(fontSize: 24, fontWeight: FontWeight.bold, color: AmyalColors.primary)),
        ]),
        actions: [
          FilledButton(onPressed: () { Navigator.pop(context); Get.back(); }, child: const Text('إغلاق')),
        ],
      ));
    } else {
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
        content: Text(c.lastError.value), backgroundColor: AmyalColors.red));
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmyalColors.background,
      appBar: AppBar(
        title: const Text('فاتورة جديدة'),
        backgroundColor: AmyalColors.primary,
        foregroundColor: Colors.white,
        actions: [
          IconButton(icon: const Icon(Icons.qr_code_scanner), tooltip: 'مسح باركود',
              onPressed: _openScanner),
        ],
      ),
      body: Column(children: [
        // اختيار العميل
        Container(
          padding: const EdgeInsets.all(10),
          color: Colors.white,
          child: Obx(() {
            final cust = c.selectedCustomer.value;
            return InkWell(
              onTap: _selectCustomer,
              child: Container(
                padding: const EdgeInsets.all(10),
                decoration: BoxDecoration(
                  color: cust != null ? AmyalColors.primary.withValues(alpha: 0.08) : Colors.grey.shade100,
                  borderRadius: BorderRadius.circular(10),
                ),
                child: Row(children: [
                  Icon(cust != null ? Icons.person : Icons.person_add, color: AmyalColors.primary),
                  const SizedBox(width: 10),
                  Expanded(child: Text(
                    cust?['full_name'] ?? 'اختر العميل *',
                    style: TextStyle(
                      fontWeight: FontWeight.bold,
                      color: cust != null ? AmyalColors.primary : Colors.grey.shade700,
                    ),
                  )),
                  if (cust != null && (double.tryParse('${cust['credit_limit']}') ?? 0) > 0)
                    Text('المتاح: ${(double.parse('${cust['credit_limit']}') - double.parse('${cust['current_balance']}')).toStringAsFixed(0)}',
                        style: TextStyle(color: Colors.grey.shade700, fontSize: 11)),
                ]),
              ),
            );
          }),
        ),

        // قائمة السلّة
        Expanded(child: Obx(() {
          if (c.cart.isEmpty) {
            return Center(child: Column(mainAxisAlignment: MainAxisAlignment.center, children: [
              Icon(Icons.shopping_cart_outlined, size: 64, color: Colors.grey.shade400),
              const SizedBox(height: 12),
              Text('السلّة فارغة', style: TextStyle(color: Colors.grey.shade600)),
              const SizedBox(height: 16),
              Wrap(spacing: 8, children: [
                FilledButton.icon(
                  onPressed: _openScanner,
                  icon: const Icon(Icons.qr_code_scanner),
                  label: const Text('مسح باركود'),
                ),
                OutlinedButton.icon(
                  onPressed: _addProductManually,
                  icon: const Icon(Icons.add),
                  label: const Text('إضافة منتج'),
                ),
              ]),
            ]));
          }
          return ListView.builder(
            padding: const EdgeInsets.all(8),
            itemCount: c.cart.length,
            itemBuilder: (_, i) => _cartItem(i),
          );
        })),

        // الإجمالي + Submit
        Obx(() => c.cart.isEmpty ? const SizedBox.shrink() : Container(
          padding: const EdgeInsets.all(12),
          color: Colors.white,
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            Row(children: [
              const Text('الإجمالي:', style: TextStyle(color: Colors.grey)),
              const Spacer(),
              Text('${c.cartSubtotal.toStringAsFixed(0)} ر.ي',
                  style: const TextStyle(fontSize: 22, fontWeight: FontWeight.bold, color: AmyalColors.primary)),
            ]),
            const SizedBox(height: 8),
            Row(children: [
              Expanded(child: _payTile('cash', Icons.payments, 'نقد')),
              const SizedBox(width: 6),
              Expanded(child: _payTile('credit', Icons.event, 'آجل')),
            ]),
            const SizedBox(height: 8),
            FilledButton.icon(
              onPressed: c.isSubmitting.value ? null : _submit,
              icon: c.isSubmitting.value
                  ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                  : const Icon(Icons.check),
              label: const Text('إنشاء الفاتورة', style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold)),
              style: FilledButton.styleFrom(backgroundColor: AmyalColors.primary, minimumSize: const Size.fromHeight(48)),
            ),
          ]),
        )),
      ]),
    );
  }

  Widget _cartItem(int i) {
    final item = c.cart[i];
    final p = item['product'] as Map;
    final qty = double.tryParse('${item['quantity']}') ?? 0;
    final price = double.tryParse('${p['base_price']}') ?? 0;
    return Container(
      margin: const EdgeInsets.only(bottom: 6),
      padding: const EdgeInsets.all(10),
      decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(8)),
      child: Row(children: [
        IconButton(icon: const Icon(Icons.delete_outline, color: Colors.red, size: 18),
            padding: EdgeInsets.zero, constraints: const BoxConstraints(),
            onPressed: () => c.removeFromCart(item['product_id'])),
        const SizedBox(width: 4),
        Text('${(qty * price).toStringAsFixed(0)} ر.ي',
            style: const TextStyle(fontWeight: FontWeight.bold, color: AmyalColors.primary)),
        const Spacer(),
        Text('× $qty', style: const TextStyle(color: Colors.grey)),
        const SizedBox(width: 8),
        Expanded(child: Text(p['name'] ?? '', textAlign: TextAlign.right,
            overflow: TextOverflow.ellipsis, style: const TextStyle(fontWeight: FontWeight.bold))),
      ]),
    );
  }

  Widget _payTile(String v, IconData icon, String label) {
    final selected = _paymentType == v;
    return InkWell(
      borderRadius: BorderRadius.circular(8),
      onTap: () => setState(() => _paymentType = v),
      child: Container(
        padding: const EdgeInsets.symmetric(vertical: 8),
        decoration: BoxDecoration(
          color: selected ? AmyalColors.primary : Colors.white,
          borderRadius: BorderRadius.circular(8),
          border: Border.all(color: selected ? AmyalColors.primary : Colors.grey.shade300),
        ),
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          Icon(icon, color: selected ? Colors.white : AmyalColors.primary, size: 18),
          Text(label, style: TextStyle(color: selected ? Colors.white : Colors.black, fontSize: 12)),
        ]),
      ),
    );
  }

  void _selectCustomer() {
    showModalBottomSheet(context: context, isScrollControlled: true,
      shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(20))),
      builder: (_) => DraggableScrollableSheet(
        initialChildSize: 0.7, maxChildSize: 0.9, minChildSize: 0.4, expand: false,
        builder: (_, scroll) => Padding(
          padding: const EdgeInsets.all(12),
          child: Obx(() => ListView.builder(
            controller: scroll,
            itemCount: c.customers.length,
            itemBuilder: (_, i) {
              final cust = c.customers[i];
              return ListTile(
                leading: const CircleAvatar(child: Icon(Icons.person)),
                title: Text(cust['full_name'] ?? ''),
                subtitle: Text(cust['phone'] ?? ''),
                onTap: () { c.selectedCustomer.value = cust; Navigator.pop(context); },
              );
            },
          )),
        ),
      ),
    );
  }

  void _addProductManually() {
    showModalBottomSheet(context: context, isScrollControlled: true,
      shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(20))),
      builder: (_) => DraggableScrollableSheet(
        initialChildSize: 0.7, maxChildSize: 0.9, minChildSize: 0.4, expand: false,
        builder: (_, scroll) => Padding(
          padding: const EdgeInsets.all(12),
          child: Obx(() => ListView.builder(
            controller: scroll,
            itemCount: c.products.length,
            itemBuilder: (_, i) {
              final p = c.products[i];
              return ListTile(
                title: Text(p['name'] ?? ''),
                subtitle: Text('${p['base_price']} ر.ي • متوفر: ${p['current_stock']}'),
                onTap: () {
                  Navigator.pop(context);
                  final qtyCtrl = TextEditingController(text: '1');
                  showDialog(context: context, builder: (ctx) => AlertDialog(
                    title: Text(p['name']),
                    content: TextField(controller: qtyCtrl, keyboardType: TextInputType.number,
                        autofocus: true, decoration: const InputDecoration(labelText: 'الكمية')),
                    actions: [
                      TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('إلغاء')),
                      FilledButton(onPressed: () {
                        final qty = double.tryParse(qtyCtrl.text) ?? 0;
                        if (qty > 0) { c.addToCart(p, qty); Navigator.pop(ctx); }
                      }, child: const Text('إضافة')),
                    ],
                  ));
                },
              );
            },
          )),
        ),
      ),
    );
  }
}

// =========================================================================
// Wrappers — يربطون placeholders بـ implementations في wholesale_screens_secondary.dart
// =========================================================================
class WholesaleInvoicesListScreen extends StatelessWidget {
  const WholesaleInvoicesListScreen({super.key});
  @override
  Widget build(BuildContext context) => const WholesaleInvoicesListScreenImpl();
}
class WholesaleAgingReportScreen extends StatelessWidget {
  const WholesaleAgingReportScreen({super.key});
  @override
  Widget build(BuildContext context) => const WholesaleAgingReportScreenImpl();
}
class WholesaleCustomerStatementScreen extends StatelessWidget {
  final Map<String, dynamic> customer;
  const WholesaleCustomerStatementScreen({super.key, required this.customer});
  @override
  Widget build(BuildContext context) => WholesaleCustomerStatementScreenImpl(customer: customer);
}
