import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';
import 'package:amyal_pay/features/merchant/controllers/customer_credit_controller.dart';
import 'package:amyal_pay/features/merchant/screens/credit_customer_statement_screen.dart';
import 'package:amyal_pay/features/merchant/screens/credit_add_customer_dialog.dart';

/// AMIAL-CUSTOMER-CREDIT-001 — قائمة عملاء الديون.
class CreditCustomersScreen extends StatefulWidget {
  const CreditCustomersScreen({super.key});

  @override
  State<CreditCustomersScreen> createState() => _CreditCustomersScreenState();
}

class _CreditCustomersScreenState extends State<CreditCustomersScreen> {
  late final CustomerCreditController c;
  final _searchCtrl = TextEditingController();
  String _filter = '';

  @override
  void initState() {
    super.initState();
    c = Get.find<CustomerCreditController>();
    WidgetsBinding.instance.addPostFrameCallback((_) => c.loadCustomers());
  }

  @override
  void dispose() {
    _searchCtrl.dispose();
    super.dispose();
  }

  void _applyFilter(String f) {
    if (!mounted) return; // AMIAL-FIX-006
    setState(() => _filter = f);
    c.loadCustomers(search: _searchCtrl.text, filter: f.isEmpty ? null : f);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmyalColors.background,
      appBar: AppBar(
        title: const Text('عملاء الديون'),
      ),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: () async {
          final ok = await Get.dialog<bool>(const CreditAddCustomerDialog());
          if (ok == true) c.loadCustomers(search: _searchCtrl.text, filter: _filter.isEmpty ? null : _filter);
        },
        backgroundColor: AmyalColors.yellow,
        foregroundColor: Colors.black87,
        icon: const Icon(Icons.person_add),
        label: const Text('إضافة عميل'),
      ),
      body: Column(children: [
        // البحث
        Padding(
          padding: const EdgeInsets.all(12),
          child: TextField(
            controller: _searchCtrl,
            textAlign: TextAlign.right,
            decoration: InputDecoration(
              hintText: 'ابحث بالاسم أو رقم الهاتف...',
              prefixIcon: const Icon(Icons.search),
              filled: true,
              fillColor: Colors.white,
              border: OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: BorderSide.none),
            ),
            onSubmitted: (v) => c.loadCustomers(search: v, filter: _filter.isEmpty ? null : _filter),
          ),
        ),
        // الفلاتر
        SizedBox(
          height: 48,
          child: ListView(
            scrollDirection: Axis.horizontal,
            padding: const EdgeInsets.symmetric(horizontal: 12),
            children: [
              _chip('الكل', ''),
              _chip('مدينون', 'debtors'),
              _chip('تجاوزوا الحد', 'over_limit'),
              _chip('مسدّدون', 'paid_up'),
            ],
          ),
        ),
        // القائمة
        Expanded(
          child: Obx(() {
            if (c.isLoadingCustomers.value) {
              return const Center(child: CircularProgressIndicator());
            }
            if (c.customers.isEmpty) {
              return const Center(child: Text('لا توجد نتائج', style: TextStyle(fontSize: 16)));
            }
            return RefreshIndicator(
              onRefresh: () => c.loadCustomers(search: _searchCtrl.text, filter: _filter.isEmpty ? null : _filter),
              child: ListView.separated(
                padding: const EdgeInsets.all(12),
                itemCount: c.customers.length,
                separatorBuilder: (_, _) => const SizedBox(height: 8),
                itemBuilder: (_, i) => _customerCard(c.customers[i]),
              ),
            );
          }),
        ),
      ]),
    );
  }

  Widget _chip(String label, String value) {
    final selected = _filter == value;
    return Padding(
      padding: const EdgeInsets.only(left: 8),
      child: ChoiceChip(
        label: Text(label),
        selected: selected,
        onSelected: (_) => _applyFilter(value),
        selectedColor: AmyalColors.primary,
        labelStyle: TextStyle(color: selected ? Colors.white : Colors.black87, fontWeight: FontWeight.w600),
        backgroundColor: Colors.white,
      ),
    );
  }

  Widget _customerCard(Map<String, dynamic> cust) {
    final bal = double.tryParse('${cust['current_balance'] ?? 0}') ?? 0;
    final lim = double.tryParse('${cust['credit_limit'] ?? 0}') ?? 0;
    final util = lim > 0 ? ((bal / lim) * 100).clamp(0, 200).toDouble() : 0.0;
    final overLimit = lim > 0 && bal > lim;
    final cls = cust['classification'] ?? 'bronze';
    final clsColor = cls == 'gold' ? AmyalColors.yellowDark
        : cls == 'silver' ? Colors.grey.shade600
        : Colors.brown.shade400;

    return InkWell(
      borderRadius: BorderRadius.circular(12),
      onTap: () => Get.to(() => CreditCustomerStatementScreen(customer: cust)),
      child: Container(
        padding: const EdgeInsets.all(14),
        decoration: BoxDecoration(
          color: AmyalColors.cardSurface,
          borderRadius: BorderRadius.circular(12),
          border: Border.all(color: overLimit ? AmyalColors.red.withValues(alpha: 0.4) : Colors.transparent),
        ),
        child: Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
          Row(children: [
            // التصنيف
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
              decoration: BoxDecoration(color: clsColor.withValues(alpha: 0.15), borderRadius: BorderRadius.circular(8)),
              child: Text(cls == 'gold' ? '⭐ ذهبي' : cls == 'silver' ? 'فضّي' : 'برونزي',
                  style: TextStyle(color: clsColor, fontSize: 11, fontWeight: FontWeight.bold)),
            ),
            const Spacer(),
            // الاسم والهاتف
            Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
              Text('${cust['customer_name'] ?? ''}', style: const TextStyle(fontSize: 16, fontWeight: FontWeight.bold)),
              const SizedBox(height: 2),
              Text('${cust['customer_phone'] ?? ''}', style: TextStyle(color: Colors.grey.shade600, fontSize: 12)),
            ]),
          ]),
          const SizedBox(height: 12),
          // الرصيد + الحد
          Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
            Text(lim > 0 ? 'الحد: ${lim.toStringAsFixed(0)} ر.ي' : 'بلا حد',
                style: TextStyle(color: Colors.grey.shade600, fontSize: 12)),
            Text('${bal.toStringAsFixed(0)} ر.ي',
                style: TextStyle(
                    fontSize: 18, fontWeight: FontWeight.bold,
                    color: bal > 0 ? AmyalColors.red : Colors.green.shade700)),
          ]),
          if (lim > 0) ...[
            const SizedBox(height: 8),
            LinearProgressIndicator(
              value: (util / 100).clamp(0, 1).toDouble(),
              backgroundColor: Colors.grey.shade200,
              valueColor: AlwaysStoppedAnimation(
                util < 60 ? Colors.green : util < 90 ? AmyalColors.yellow : AmyalColors.red,
              ),
              minHeight: 6,
            ),
            const SizedBox(height: 4),
            Text('استهلاك ${util.toStringAsFixed(0)}%${overLimit ? ' — تجاوز' : ''}',
                style: TextStyle(fontSize: 11, color: overLimit ? AmyalColors.red : Colors.grey.shade600)),
          ],
        ]),
      ),
    );
  }
}
