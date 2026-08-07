import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:get/get.dart';
import 'package:amial_pay/features/access/controllers/access_controller.dart';
import 'package:amial_pay/theme/amial_colors.dart';
import 'package:amial_pay/features/pharmacy/controllers/pharmacy_controller.dart';
import 'package:amial_pay/features/pharmacy/screens/pharmacy_sale_screen.dart';

/// AMIAL-PHARMACY-001 — لوحة الصيدلية (Entry point).
class PharmacyDashboardScreen extends StatefulWidget {
  const PharmacyDashboardScreen({super.key});
  @override
  State<PharmacyDashboardScreen> createState() => _PharmacyDashboardScreenState();
}

class _PharmacyDashboardScreenState extends State<PharmacyDashboardScreen> {
  late final PharmacyController c;

  @override
  void initState() {
    super.initState();
    c = Get.find<PharmacyController>();
    WidgetsBinding.instance.addPostFrameCallback((_) async {
      await c.loadPharmacy();
      await c.loadDashboard();
      // AMIAL-SUB-GATING: أعِد تحميل الصلاحيات لتنعكس ترقية الخطة
      try { await Get.find<AccessController>().load(); } catch (_) {}
    });
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(
        title: const Text('الصيدلية'),
      ),
      body: RefreshIndicator(
        onRefresh: () async { await c.loadPharmacy(); await c.loadDashboard(); },
        child: SingleChildScrollView(
          physics: const AlwaysScrollableScrollPhysics(),
          padding: const EdgeInsets.all(16),
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            // بطاقة الصيدلية
            Obx(() {
              final p = c.pharmacy.value;
              return Container(
                padding: const EdgeInsets.all(16),
                decoration: BoxDecoration(
                  gradient: const LinearGradient(
                    colors: [AmialColors.primary, Color(0xFF021A55)],
                    begin: Alignment.topLeft, end: Alignment.bottomRight,
                  ),
                  borderRadius: BorderRadius.circular(14),
                ),
                child: Row(children: [
                  Container(
                    width: 50, height: 50,
                    decoration: BoxDecoration(color: AmialColors.yellow, borderRadius: BorderRadius.circular(12)),
                    child: const Icon(Icons.local_pharmacy, color: Colors.black87, size: 28),
                  ),
                  const SizedBox(width: 12),
                  Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Text(p?['pharmacy_name'] ?? 'صيدليتي',
                        style: const TextStyle(color: Colors.white, fontSize: 18, fontWeight: FontWeight.bold)),
                    if (p?['pharmacist_name'] != null)
                      Text('الصيدلي: ${p!['pharmacist_name']}',
                          style: const TextStyle(color: Colors.white70, fontSize: 12)),
                  ])),
                ]),
              );
            }),

            const SizedBox(height: 14),

            // إحصائيات اليوم
            Obx(() {
              final d = c.dashboardData.value;
              final today = (d?['today'] ?? {}) as Map?;
              final alerts = (d?['alerts_summary'] ?? {}) as Map?;
              return Container(
                padding: const EdgeInsets.all(14),
                decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(14)),
                child: Column(children: [
                  Row(children: [
                    Expanded(child: _statBox('${today?['sales_count'] ?? 0}', 'بيوع اليوم',
                        AmialColors.primary, Icons.receipt_long)),
                    const SizedBox(width: 6),
                    Expanded(child: _statBox('${today?['total_amount'] ?? 0}', 'الإجمالي (ر.ي)',
                        AmialColors.yellowDark, Icons.attach_money)),
                  ]),
                  const SizedBox(height: 8),
                  Row(children: [
                    Expanded(child: _statBox('${d?['products_count'] ?? 0}', 'منتجات',
                        Colors.green.shade700, Icons.medication)),
                    const SizedBox(width: 6),
                    Expanded(child: _statBox('${d?['customers_count'] ?? 0}', 'عملاء',
                        Colors.indigo, Icons.people)),
                  ]),
                  if ((alerts?['total_active'] ?? 0) > 0) ...[
                    const SizedBox(height: 8),
                    Container(
                      padding: const EdgeInsets.all(10),
                      decoration: BoxDecoration(color: Colors.orange.shade50, borderRadius: BorderRadius.circular(8)),
                      child: Row(children: [
                        const Icon(Icons.warning_amber, color: Colors.orange),
                        const SizedBox(width: 8),
                        Expanded(child: Text(
                          '${alerts!['total_active']} تنبيه نشط (${alerts['critical'] ?? 0} حرج)',
                          style: const TextStyle(fontWeight: FontWeight.bold, color: Colors.orange),
                        )),
                      ]),
                    ),
                  ],
                ]),
              );
            }),

            const SizedBox(height: 16),

            // الإجراء الأساسي
            _bigAction(Icons.point_of_sale, 'بيع جديد', 'تسجيل عملية بيع',
                AmialColors.primary, () => Get.to(() => const PharmacySaleScreen())),

            const SizedBox(height: 10),
            Row(children: [
              Expanded(child: _miniAction(Icons.medication, 'المنتجات',
                  () => Get.to(() => const PharmacyProductsScreen()))),
              const SizedBox(width: 8),
              Expanded(child: _miniAction(Icons.people, 'العملاء',
                  () => Get.to(() => const PharmacyCustomersScreen()))),
            ]),
            const SizedBox(height: 8),
            Row(children: [
              Expanded(child: _miniAction(Icons.warning_amber, 'التنبيهات',
                  () => Get.to(() => const PharmacyAlertsScreen()))),
              const SizedBox(width: 8),
              Expanded(child: _miniAction(Icons.history, 'سجل المبيعات',
                  () => Get.to(() => const PharmacySalesHistoryScreen()))),
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
        Icon(icon, color: AmialColors.primary, size: 22),
        const SizedBox(height: 4),
        Text(label, style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 12)),
      ]),
    ),
  );
}

// =========================================================================
// شاشة المنتجات + Batches
// =========================================================================

class PharmacyProductsScreen extends StatefulWidget {
  const PharmacyProductsScreen({super.key});
  @override
  State<PharmacyProductsScreen> createState() => _PharmacyProductsScreenState();
}

class _PharmacyProductsScreenState extends State<PharmacyProductsScreen> {
  late final PharmacyController c;
  bool _lowStockOnly = false;

  @override
  void initState() {
    super.initState();
    c = Get.find<PharmacyController>();
    WidgetsBinding.instance.addPostFrameCallback((_) => c.loadProducts());
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(
        title: const Text('المنتجات'),
        actions: [
          IconButton(
            icon: Icon(_lowStockOnly ? Icons.filter_alt : Icons.filter_alt_outlined),
            onPressed: () { setState(() => _lowStockOnly = !_lowStockOnly); c.loadProducts(lowStockOnly: _lowStockOnly); },
            tooltip: 'مخزون منخفض فقط',
          ),
        ],
      ),
      floatingActionButton: FloatingActionButton.extended(
        backgroundColor: AmialColors.primary,
        onPressed: _addProductDialog,
        icon: const Icon(Icons.add),
        label: const Text('منتج جديد'),
      ),
      body: Obx(() {
        if (c.isLoading.value && c.products.isEmpty) {
          return const Center(child: CircularProgressIndicator());
        }
        if (c.products.isEmpty) {
          return Center(child: Column(mainAxisAlignment: MainAxisAlignment.center, children: [
            Icon(Icons.inventory_2, size: 64, color: Colors.grey.shade400),
            const SizedBox(height: 12),
            Text(_lowStockOnly ? 'لا توجد منتجات منخفضة' : 'لا توجد منتجات',
                style: TextStyle(color: Colors.grey.shade600)),
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
    final threshold = p['low_stock_threshold'] ?? 10;
    final isLow = stock <= threshold;

    return InkWell(
      onTap: () => _showBatches(p),
      child: Container(
        margin: const EdgeInsets.only(bottom: 8),
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(10)),
        child: Row(children: [
          Container(width: 40, height: 40,
            decoration: BoxDecoration(color: AmialColors.yellow.withValues(alpha: 0.2), borderRadius: BorderRadius.circular(8)),
            child: const Icon(Icons.medication, color: AmialColors.yellowDark)),
          const SizedBox(width: 10),
          Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Row(children: [
              if (p['requires_prescription'] == true) const Padding(
                padding: EdgeInsets.only(left: 4),
                child: Icon(Icons.medical_services, color: Colors.red, size: 14),
              ),
              Expanded(child: Text(p['trade_name'] ?? '',
                  style: const TextStyle(fontWeight: FontWeight.bold))),
            ]),
            if (p['generic_name'] != null)
              Text(p['generic_name'], style: TextStyle(color: Colors.grey.shade600, fontSize: 11)),
            Text('${stock.toStringAsFixed(0)} ${p['unit'] ?? ''} • ${p['sale_price']} ر.ي',
                style: TextStyle(
                  color: isLow ? AmialColors.red : Colors.green.shade700,
                  fontSize: 12, fontWeight: FontWeight.bold,
                )),
          ])),
          const Icon(Icons.chevron_left, color: Colors.grey),
        ]),
      ),
    );
  }

  void _showBatches(Map<String, dynamic> product) {
    c.loadBatches(product['id']);
    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(20))),
      builder: (_) => DraggableScrollableSheet(
        initialChildSize: 0.7, maxChildSize: 0.9, minChildSize: 0.5, expand: false,
        builder: (_, scroll) => Padding(
          padding: const EdgeInsets.all(16),
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            Row(children: [
              Expanded(child: Text('Batches: ${product['trade_name']}',
                  style: const TextStyle(fontSize: 16, fontWeight: FontWeight.bold))),
              FilledButton.icon(
                onPressed: () { Navigator.pop(context); _addBatchDialog(product); },
                icon: const Icon(Icons.add, size: 16),
                label: const Text('جديد'),
              ),
            ]),
            const SizedBox(height: 12),
            Expanded(child: Obx(() {
              if (c.batches.isEmpty) {
                return Center(child: Column(mainAxisAlignment: MainAxisAlignment.center, children: [
                  Icon(Icons.inventory, size: 50, color: Colors.grey.shade400),
                  const SizedBox(height: 8),
                  Text('لا توجد Batches', style: TextStyle(color: Colors.grey.shade600)),
                ]));
              }
              return ListView.builder(
                controller: scroll,
                itemCount: c.batches.length,
                itemBuilder: (_, i) => _batchTile(c.batches[i]),
              );
            })),
          ]),
        ),
      ),
    );
  }

  Widget _batchTile(Map<String, dynamic> b) {
    final status = b['status']?.toString() ?? '';
    final expiry = b['expiry_date']?.toString() ?? '';
    final isExpired = status == 'expired';
    final isExhausted = status == 'exhausted';

    Color cardColor = Colors.white;
    if (isExpired) {
      cardColor = Colors.red.shade50;
    } else if (isExhausted) {
      cardColor = Colors.grey.shade100;
    }

    return Container(
      margin: const EdgeInsets.only(bottom: 6),
      padding: const EdgeInsets.all(10),
      decoration: BoxDecoration(color: cardColor, borderRadius: BorderRadius.circular(8)),
      child: Row(children: [
        Icon(isExpired ? Icons.dangerous : Icons.inventory,
            color: isExpired ? Colors.red : (isExhausted ? Colors.grey : AmialColors.primary), size: 22),
        const SizedBox(width: 10),
        Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text('#${b['batch_number']}', style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13)),
          Text('انتهاء: $expiry', style: TextStyle(color: isExpired ? Colors.red : Colors.grey.shade700, fontSize: 11)),
        ])),
        Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
          Text('${b['quantity_remaining']}', style: const TextStyle(fontWeight: FontWeight.bold)),
          Text('من ${b['quantity_received']}', style: TextStyle(color: Colors.grey.shade600, fontSize: 10)),
        ]),
      ]),
    );
  }

  void _addBatchDialog(Map<String, dynamic> product) {
    final number = TextEditingController();
    final qty = TextEditingController();
    final expiry = TextEditingController();
    final supplier = TextEditingController();
    final cost = TextEditingController();

    showDialog(context: context, builder: (ctx) => AlertDialog(
      title: Text('Batch جديد: ${product['trade_name']}'),
      content: SingleChildScrollView(child: Column(mainAxisSize: MainAxisSize.min, children: [
        TextField(controller: number, decoration: const InputDecoration(labelText: 'رقم الـ Batch *')),
        const SizedBox(height: 8),
        TextField(controller: qty, keyboardType: TextInputType.number,
            inputFormatters: [FilteringTextInputFormatter.digitsOnly],
            decoration: const InputDecoration(labelText: 'الكمية *')),
        const SizedBox(height: 8),
        TextField(
          controller: expiry,
          readOnly: true,
          decoration: const InputDecoration(labelText: 'تاريخ الصلاحية *', hintText: 'YYYY-MM-DD'),
          onTap: () async {
            final d = await showDatePicker(
              context: context,
              firstDate: DateTime.now().add(const Duration(days: 1)),
              lastDate: DateTime.now().add(const Duration(days: 365 * 10)),
              initialDate: DateTime.now().add(const Duration(days: 365)),
            );
            if (d != null) expiry.text = '${d.year}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';
          },
        ),
        const SizedBox(height: 8),
        TextField(controller: supplier, decoration: const InputDecoration(labelText: 'المورّد (اختياري)')),
        const SizedBox(height: 8),
        TextField(controller: cost, keyboardType: TextInputType.number,
            decoration: const InputDecoration(labelText: 'تكلفة الوحدة', suffixText: 'ر.ي')),
      ])),
      actions: [
        TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('إلغاء')),
        Obx(() => FilledButton(
          onPressed: c.isSubmitting.value ? null : () async {
            if (number.text.isEmpty || qty.text.isEmpty || expiry.text.isEmpty) return;
            final ok = await c.addBatch(product['id'], {
              'batch_number': number.text.trim(),
              'quantity_received': qty.text,
              'expiry_date': expiry.text,
              if (supplier.text.isNotEmpty) 'supplier_name': supplier.text.trim(),
              if (cost.text.isNotEmpty) 'cost_per_unit': cost.text,
            });
            if (!mounted) return;
            if (ok) {
              Navigator.pop(ctx);
            } else {
              ScaffoldMessenger.of(context).showSnackBar(
              SnackBar(content: Text(c.lastError.value), backgroundColor: AmialColors.red));
            }
          },
          child: const Text('إضافة'),
        )),
      ],
    ));
  }

  void _addProductDialog() {
    final trade = TextEditingController();
    final generic = TextEditingController();
    final price = TextEditingController();
    final barcode = TextEditingController();
    bool requiresPrescription = false;

    showDialog(context: context, builder: (ctx) => StatefulBuilder(builder: (_, setSt) => AlertDialog(
      title: const Text('منتج جديد'),
      content: SingleChildScrollView(child: Column(mainAxisSize: MainAxisSize.min, children: [
        TextField(controller: trade, decoration: const InputDecoration(labelText: 'الاسم التجاري *')),
        const SizedBox(height: 8),
        TextField(controller: generic, decoration: const InputDecoration(labelText: 'الاسم العلمي (اختياري)')),
        const SizedBox(height: 8),
        TextField(controller: barcode, decoration: const InputDecoration(labelText: 'الباركود (اختياري)')),
        const SizedBox(height: 8),
        TextField(controller: price, keyboardType: TextInputType.number,
            decoration: const InputDecoration(labelText: 'سعر البيع *', suffixText: 'ر.ي')),
        const SizedBox(height: 8),
        SwitchListTile(
          title: const Text('يستلزم وصفة طبية', style: TextStyle(fontSize: 13)),
          value: requiresPrescription,
          onChanged: (v) => setSt(() => requiresPrescription = v),
          contentPadding: EdgeInsets.zero,
        ),
      ])),
      actions: [
        TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('إلغاء')),
        Obx(() => FilledButton(
          onPressed: c.isSubmitting.value ? null : () async {
            if (trade.text.isEmpty || price.text.isEmpty) return;
            final ok = await c.addProduct({
              'trade_name': trade.text.trim(),
              if (generic.text.isNotEmpty) 'generic_name': generic.text.trim(),
              if (barcode.text.isNotEmpty) 'barcode': barcode.text.trim(),
              'sale_price': price.text,
              'requires_prescription': requiresPrescription,
            });
            if (!mounted) return;
            if (ok) {
              Navigator.pop(ctx);
            } else {
              ScaffoldMessenger.of(context).showSnackBar(
              SnackBar(content: Text(c.lastError.value), backgroundColor: AmialColors.red));
            }
          },
          child: const Text('إضافة'),
        )),
      ],
    )));
  }
}

// =========================================================================
// شاشة العملاء + الحساسيات
// =========================================================================

class PharmacyCustomersScreen extends StatefulWidget {
  const PharmacyCustomersScreen({super.key});
  @override
  State<PharmacyCustomersScreen> createState() => _PharmacyCustomersScreenState();
}

class _PharmacyCustomersScreenState extends State<PharmacyCustomersScreen> {
  late final PharmacyController c;

  @override
  void initState() {
    super.initState();
    c = Get.find<PharmacyController>();
    WidgetsBinding.instance.addPostFrameCallback((_) => c.loadCustomers());
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(
        title: const Text('العملاء/المرضى'),
      ),
      floatingActionButton: FloatingActionButton.extended(
        backgroundColor: AmialColors.primary,
        onPressed: () => _customerDialog(null),
        icon: const Icon(Icons.add),
        label: const Text('عميل جديد'),
      ),
      body: Obx(() {
        if (c.isLoading.value && c.customers.isEmpty) return const Center(child: CircularProgressIndicator());
        if (c.customers.isEmpty) {
          return Center(child: Column(mainAxisAlignment: MainAxisAlignment.center, children: [
            Icon(Icons.people_outline, size: 64, color: Colors.grey.shade400),
            const SizedBox(height: 12),
            Text('لا يوجد عملاء', style: TextStyle(color: Colors.grey.shade600)),
          ]));
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
    final allergies = (cust['allergies'] as List?)?.cast<String>() ?? [];
    final chronic = (cust['chronic_conditions'] as List?)?.cast<String>() ?? [];

    return InkWell(
      onTap: () => _customerDialog(cust),
      child: Container(
        margin: const EdgeInsets.only(bottom: 8),
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(10)),
        child: Row(children: [
          CircleAvatar(
            radius: 22,
            backgroundColor: cust['gender'] == 'female' ? Colors.pink.shade100 : Colors.blue.shade100,
            child: Icon(cust['gender'] == 'female' ? Icons.woman : Icons.man,
                color: cust['gender'] == 'female' ? Colors.pink.shade700 : Colors.blue.shade700),
          ),
          const SizedBox(width: 12),
          Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(cust['full_name'] ?? '', style: const TextStyle(fontWeight: FontWeight.bold)),
            if (cust['phone'] != null)
              Text(cust['phone'], style: TextStyle(color: Colors.grey.shade600, fontSize: 12)),
            if (allergies.isNotEmpty)
              Padding(
                padding: const EdgeInsets.only(top: 2),
                child: Text('⚠️ ${allergies.join(', ')}',
                    style: const TextStyle(color: Colors.red, fontSize: 11, fontWeight: FontWeight.bold)),
              ),
            if (chronic.isNotEmpty)
              Text('🩺 ${chronic.join(', ')}',
                  style: TextStyle(color: Colors.orange.shade800, fontSize: 11)),
          ])),
          const Icon(Icons.edit, color: Colors.grey, size: 18),
        ]),
      ),
    );
  }

  void _customerDialog(Map<String, dynamic>? existing) {
    final name = TextEditingController(text: existing?['full_name']?.toString() ?? '');
    final phone = TextEditingController(text: existing?['phone']?.toString() ?? '');
    final allergiesCtrl = TextEditingController(
      text: ((existing?['allergies'] as List?)?.cast<String>().join(', ')) ?? '',
    );
    final chronicCtrl = TextEditingController(
      text: ((existing?['chronic_conditions'] as List?)?.cast<String>().join(', ')) ?? '',
    );
    String gender = existing?['gender']?.toString() ?? 'male';

    showDialog(context: context, builder: (ctx) => StatefulBuilder(builder: (_, setSt) => AlertDialog(
      title: Text(existing == null ? 'عميل جديد' : 'تعديل ${existing['full_name']}'),
      content: SingleChildScrollView(child: Column(mainAxisSize: MainAxisSize.min, children: [
        TextField(controller: name, decoration: const InputDecoration(labelText: 'الاسم الكامل *')),
        const SizedBox(height: 8),
        TextField(controller: phone, keyboardType: TextInputType.phone,
            decoration: const InputDecoration(labelText: 'الهاتف')),
        const SizedBox(height: 8),
        Row(children: [
          Expanded(child: RadioListTile<String>(
            title: const Text('ذكر', style: TextStyle(fontSize: 12)),
            value: 'male', groupValue: gender,
            onChanged: (v) => setSt(() => gender = v!),
            contentPadding: EdgeInsets.zero, dense: true,
          )),
          Expanded(child: RadioListTile<String>(
            title: const Text('أنثى', style: TextStyle(fontSize: 12)),
            value: 'female', groupValue: gender,
            onChanged: (v) => setSt(() => gender = v!),
            contentPadding: EdgeInsets.zero, dense: true,
          )),
        ]),
        const SizedBox(height: 8),
        TextField(
          controller: allergiesCtrl,
          decoration: const InputDecoration(
            labelText: 'الحساسيات',
            helperText: 'افصل بفاصلة (Penicillin, Aspirin)',
          ),
        ),
        const SizedBox(height: 8),
        TextField(
          controller: chronicCtrl,
          decoration: const InputDecoration(
            labelText: 'أمراض مزمنة',
            helperText: 'افصل بفاصلة (Diabetes, Hypertension)',
          ),
        ),
      ])),
      actions: [
        TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('إلغاء')),
        Obx(() => FilledButton(
          onPressed: c.isSubmitting.value ? null : () async {
            if (name.text.isEmpty) return;
            final allergies = allergiesCtrl.text.split(',').map((s) => s.trim()).where((s) => s.isNotEmpty).toList();
            final chronic = chronicCtrl.text.split(',').map((s) => s.trim()).where((s) => s.isNotEmpty).toList();
            final data = {
              'full_name': name.text.trim(),
              if (phone.text.isNotEmpty) 'phone': phone.text.trim(),
              'gender': gender,
              if (allergies.isNotEmpty) 'allergies': allergies,
              if (chronic.isNotEmpty) 'chronic_conditions': chronic,
            };
            final ok = existing == null
                ? await c.addCustomer(data)
                : await c.updateCustomer(existing['id'], data);
            if (!mounted) return;
            if (ok) {
              Navigator.pop(ctx);
            } else {
              ScaffoldMessenger.of(context).showSnackBar(
              SnackBar(content: Text(c.lastError.value), backgroundColor: AmialColors.red));
            }
          },
          child: Text(existing == null ? 'إضافة' : 'حفظ'),
        )),
      ],
    )));
  }
}

// =========================================================================
// شاشة التنبيهات
// =========================================================================

class PharmacyAlertsScreen extends StatefulWidget {
  const PharmacyAlertsScreen({super.key});
  @override
  State<PharmacyAlertsScreen> createState() => _PharmacyAlertsScreenState();
}

class _PharmacyAlertsScreenState extends State<PharmacyAlertsScreen> {
  late final PharmacyController c;

  @override
  void initState() {
    super.initState();
    c = Get.find<PharmacyController>();
    WidgetsBinding.instance.addPostFrameCallback((_) => c.loadAlerts());
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(
        title: const Text('تنبيهات المخزون'),
        actions: [
          IconButton(
            icon: const Icon(Icons.refresh),
            tooltip: 'فحص التواريخ',
            onPressed: () async {
              await c.scanExpiring();
              if (!mounted) return;
              ScaffoldMessenger.of(context).showSnackBar(
                const SnackBar(content: Text('تم الفحص'), backgroundColor: Colors.green));
            },
          ),
        ],
      ),
      body: Obx(() {
        if (c.isLoading.value && c.alerts.isEmpty) return const Center(child: CircularProgressIndicator());
        return Column(children: [
          if (c.alertsSummary.value != null) _summaryBar(c.alertsSummary.value!),
          Expanded(child: c.alerts.isEmpty
              ? Center(child: Column(mainAxisAlignment: MainAxisAlignment.center, children: [
                  const Icon(Icons.check_circle, size: 64, color: Colors.green),
                  const SizedBox(height: 12),
                  Text('لا توجد تنبيهات نشطة', style: TextStyle(color: Colors.grey.shade600, fontSize: 15)),
                ]))
              : ListView.builder(
                  padding: const EdgeInsets.all(12),
                  itemCount: c.alerts.length,
                  itemBuilder: (_, i) => _alertCard(c.alerts[i]),
                )),
        ]);
      }),
    );
  }

  Widget _summaryBar(Map<String, dynamic> s) {
    final byType = (s['by_type'] ?? {}) as Map;
    return Container(
      padding: const EdgeInsets.all(12),
      color: Colors.white,
      child: Row(children: [
        Expanded(child: _summaryItem('${byType['low_stock'] ?? 0}', 'منخفض', Colors.orange)),
        Expanded(child: _summaryItem('${byType['near_expiry'] ?? 0}', 'قرب انتهاء', Colors.amber)),
        Expanded(child: _summaryItem('${byType['expired'] ?? 0}', 'منتهي', Colors.red)),
      ]),
    );
  }

  Widget _summaryItem(String value, String label, Color color) => Column(children: [
    Text(value, style: TextStyle(color: color, fontSize: 18, fontWeight: FontWeight.bold)),
    Text(label, style: TextStyle(color: Colors.grey.shade700, fontSize: 11)),
  ]);

  Widget _alertCard(Map<String, dynamic> a) {
    final severity = a['severity']?.toString() ?? 'info';
    final type = a['alert_type']?.toString() ?? '';

    Color color = switch (severity) {
      'critical' => Colors.red,
      'warning' => Colors.orange,
      _ => Colors.blue,
    };
    IconData icon = switch (type) {
      'low_stock' => Icons.inventory_2,
      'near_expiry' => Icons.access_time,
      'expired' => Icons.dangerous,
      _ => Icons.warning,
    };

    return Container(
      margin: const EdgeInsets.only(bottom: 8),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(10),
        border: Border(right: BorderSide(color: color, width: 4)),
      ),
      child: Row(children: [
        Icon(icon, color: color, size: 28),
        const SizedBox(width: 12),
        Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(a['message'] ?? '', style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13)),
          const SizedBox(height: 2),
          Text(severity.toUpperCase(),
              style: TextStyle(color: color, fontSize: 10, fontWeight: FontWeight.bold)),
        ])),
        IconButton(
          icon: const Icon(Icons.close, color: Colors.grey, size: 18),
          onPressed: () => c.dismissAlert(a['id']),
          tooltip: 'إغلاق',
        ),
      ]),
    );
  }
}

// =========================================================================
// شاشة سجل المبيعات
// =========================================================================

class PharmacySalesHistoryScreen extends StatefulWidget {
  const PharmacySalesHistoryScreen({super.key});
  @override
  State<PharmacySalesHistoryScreen> createState() => _PharmacySalesHistoryScreenState();
}

class _PharmacySalesHistoryScreenState extends State<PharmacySalesHistoryScreen> {
  late final PharmacyController c;

  @override
  void initState() {
    super.initState();
    c = Get.find<PharmacyController>();
    WidgetsBinding.instance.addPostFrameCallback((_) => c.loadSales());
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(
        title: const Text('سجل المبيعات'),
      ),
      body: Obx(() {
        if (c.isLoading.value && c.sales.isEmpty) return const Center(child: CircularProgressIndicator());
        if (c.sales.isEmpty) {
          return Center(child: Column(mainAxisAlignment: MainAxisAlignment.center, children: [
            Icon(Icons.receipt_long, size: 64, color: Colors.grey.shade400),
            const SizedBox(height: 12),
            Text('لا توجد مبيعات', style: TextStyle(color: Colors.grey.shade600)),
          ]));
        }
        return RefreshIndicator(
          onRefresh: c.loadSales,
          child: ListView.builder(
            padding: const EdgeInsets.all(12),
            itemCount: c.sales.length,
            itemBuilder: (_, i) => _saleCard(c.sales[i]),
          ),
        );
      }),
    );
  }

  Widget _saleCard(Map<String, dynamic> s) {
    final method = s['payment_method']?.toString() ?? '';
    final methodLabel = method == 'cash' ? 'نقد' : (method == 'amial_pay' ? 'أميال' : 'آجل');
    final methodColor = method == 'cash' ? Colors.green : (method == 'amial_pay' ? Colors.blue : Colors.orange);
    final items = (s['items'] as List?) ?? [];
    final customer = s['customer'] as Map?;

    return Container(
      margin: const EdgeInsets.only(bottom: 8),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(10)),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Row(children: [
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
            decoration: BoxDecoration(color: methodColor.withValues(alpha: 0.15), borderRadius: BorderRadius.circular(4)),
            child: Text(methodLabel, style: TextStyle(color: methodColor, fontSize: 11, fontWeight: FontWeight.bold)),
          ),
          const SizedBox(width: 8),
          Text('${items.length} عنصر', style: TextStyle(color: Colors.grey.shade600, fontSize: 12)),
          const Spacer(),
          Text('${s['total_amount']} ر.ي',
              style: const TextStyle(fontWeight: FontWeight.bold, color: AmialColors.primary, fontSize: 15)),
        ]),
        if (customer != null) ...[
          const SizedBox(height: 6),
          Text('العميل: ${customer['full_name']}',
              style: TextStyle(color: Colors.grey.shade700, fontSize: 12)),
        ],
        if (s['prescription_number'] != null) ...[
          const SizedBox(height: 4),
          Text('وصفة: ${s['prescription_number']}',
              style: TextStyle(color: Colors.red.shade700, fontSize: 11)),
        ],
      ]),
    );
  }
}
