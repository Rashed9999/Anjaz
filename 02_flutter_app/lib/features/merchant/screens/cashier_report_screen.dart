import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/features/merchant/controllers/cashier_controller.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';

/// AMIAL-CASHIER-001 — تقرير المبيعات اليومي.
class CashierReportScreen extends StatefulWidget {
  const CashierReportScreen({super.key});

  @override
  State<CashierReportScreen> createState() => _CashierReportScreenState();
}

class _CashierReportScreenState extends State<CashierReportScreen> {
  CashierController get c => Get.find<CashierController>();

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => c.loadReport());
  }

  Widget _card(String label, String value, Color color) {
    return Expanded(
      child: Container(
        margin: const EdgeInsets.all(4),
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(
          color: AmyalColors.cardSurface,
          borderRadius: BorderRadius.circular(8),
          border: Border(bottom: BorderSide(color: color, width: 3)),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(label, style: const TextStyle(fontSize: 12, color: AmyalColors.textSecondary)),
            const SizedBox(height: 6),
            Text(value, style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold, color: color)),
          ],
        ),
      ),
    );
  }

  String _n(dynamic v) => (double.tryParse(v?.toString() ?? '0') ?? 0).toStringAsFixed(2);

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmyalColors.background,
      appBar: AppBar(
        backgroundColor: AmyalColors.primary,
        foregroundColor: Colors.white,
        title: const Text('تقرير اليوم'),
      ),
      body: RefreshIndicator(
        onRefresh: () => c.loadReport(),
        child: Obx(() {
          if (c.isLoadingReport.value && c.report.value == null) {
            return const Center(child: CircularProgressIndicator());
          }
          final r = c.report.value;
          if (r == null) {
            return ListView(children: const [SizedBox(height: 120), Center(child: Text('لا بيانات'))]);
          }
          final byMethod = (r['by_method'] ?? {}) as Map;
          final top = (r['top_products'] ?? []) as List;

          return ListView(
            padding: const EdgeInsets.all(12),
            children: [
              Text('تاريخ: ${r['date'] ?? ''}', style: const TextStyle(color: AmyalColors.textSecondary)),
              const SizedBox(height: 8),
              Row(children: [
                _card('الإيراد الفعلي', '${_n(r['realized_revenue'])} ر.ي', AmyalColors.primary),
                _card('عدد المبيعات', '${r['sales_count'] ?? 0}', AmyalColors.yellowDark),
              ]),
              Row(children: [
                _card('نقد', '${_n(byMethod['cash'])} ر.ي', Colors.green),
                _card('أميال باي', '${_n(byMethod['amial_pay'])} ر.ي', AmyalColors.primary),
              ]),
              Row(children: [
                _card('أجل اليوم', '${_n(byMethod['credit'])} ر.ي', AmyalColors.textSecondary),
                _card('إجمالي الأجل المستحق', '${_n(r['outstanding_credit_total'])} ر.ي', AmyalColors.red),
              ]),
              const SizedBox(height: 16),
              if (top.isNotEmpty) ...[
                const Text('الأكثر مبيعاً', style: TextStyle(fontWeight: FontWeight.bold)),
                const SizedBox(height: 8),
                ...top.map((p) => Card(
                      color: AmyalColors.cardSurface,
                      child: ListTile(
                        dense: true,
                        leading: const Icon(Icons.star_outline, color: AmyalColors.yellowDark),
                        title: Text((p['name'] ?? '').toString()),
                        trailing: Text('×${p['qty'] ?? 0}', style: const TextStyle(fontWeight: FontWeight.bold)),
                      ),
                    )),
              ],
            ],
          );
        }),
      ),
    );
  }
}
