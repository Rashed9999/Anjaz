import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amial_pay/theme/amial_colors.dart';
import 'package:amial_pay/features/merchant/controllers/customer_credit_controller.dart';
import 'package:amial_pay/features/merchant/screens/credit_customers_screen.dart';

/// AMIAL-CUSTOMER-CREDIT-001 — Dashboard نظام الديون للتاجر.
class CreditDashboardScreen extends StatefulWidget {
  const CreditDashboardScreen({super.key});

  @override
  State<CreditDashboardScreen> createState() => _CreditDashboardScreenState();
}

class _CreditDashboardScreenState extends State<CreditDashboardScreen> {
  late final CustomerCreditController c;

  @override
  void initState() {
    super.initState();
    c = Get.find<CustomerCreditController>();
    WidgetsBinding.instance.addPostFrameCallback((_) => c.loadDashboard());
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(
        title: const Text('إدارة العملاء (الديون)'),
      ),
      body: Obx(() {
        if (c.isLoadingDashboard.value) {
          return const Center(child: CircularProgressIndicator());
        }
        final d = c.dashboardData.value;
        if (d == null) {
          return RefreshIndicator(
            onRefresh: c.loadDashboard,
            child: ListView(children: const [
              SizedBox(height: 200),
              Center(child: Text('اسحب للتحديث')),
            ]),
          );
        }
        final byCls = (d['by_classification'] ?? {}) as Map;
        return RefreshIndicator(
          onRefresh: c.loadDashboard,
          child: ListView(
            padding: const EdgeInsets.all(16),
            children: [
              _bigCard(
                title: 'إجمالي الديون المستحقّة',
                value: '${d['total_due']} ر.ي',
                color: AmialColors.primary,
                textColor: Colors.white,
              ),
              const SizedBox(height: 12),
              Row(children: [
                Expanded(child: _smallCard('عملاء مدينون', '${d['debtors_count']}',
                    AmialColors.yellow, Colors.black87)),
                const SizedBox(width: 12),
                Expanded(child: _smallCard('تجاوزوا الحد', '${d['over_limit_count']}',
                    AmialColors.red, Colors.white)),
              ]),
              const SizedBox(height: 20),
              const Text('تصنيف العملاء',
                  style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold)),
              const SizedBox(height: 8),
              _clsRow('ذهبي ⭐', byCls['gold'] ?? 0, AmialColors.yellowDark),
              _clsRow('فضّي', byCls['silver'] ?? 0, Colors.grey.shade600),
              _clsRow('برونزي', byCls['bronze'] ?? 0, Colors.brown.shade400),
              const SizedBox(height: 24),
              FilledButton.icon(
                onPressed: () => Get.to(() => const CreditCustomersScreen()),
                icon: const Icon(Icons.people),
                label: const Text('عرض كل العملاء'),
                style: FilledButton.styleFrom(
                  backgroundColor: AmialColors.primary,
                  minimumSize: const Size.fromHeight(50),
                ),
              ),
            ],
          ),
        );
      }),
    );
  }

  Widget _bigCard({required String title, required String value, required Color color, required Color textColor}) {
    return Container(
      padding: const EdgeInsets.all(20),
      decoration: BoxDecoration(color: color, borderRadius: BorderRadius.circular(16)),
      child: Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
        Text(title, style: TextStyle(color: textColor.withValues(alpha: 0.85), fontSize: 14)),
        const SizedBox(height: 8),
        Text(value, style: TextStyle(color: textColor, fontSize: 30, fontWeight: FontWeight.bold)),
      ]),
    );
  }

  Widget _smallCard(String title, String value, Color color, Color textColor) {
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(color: color, borderRadius: BorderRadius.circular(12)),
      child: Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
        Text(title, style: TextStyle(color: textColor.withValues(alpha: 0.85), fontSize: 12)),
        const SizedBox(height: 6),
        Text(value, style: TextStyle(color: textColor, fontSize: 22, fontWeight: FontWeight.bold)),
      ]),
    );
  }

  Widget _clsRow(String label, dynamic count, Color color) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 6),
      child: Row(children: [
        Container(width: 14, height: 14, decoration: BoxDecoration(color: color, shape: BoxShape.circle)),
        const SizedBox(width: 12),
        Expanded(child: Text(label, style: const TextStyle(fontSize: 15))),
        Text('$count عميل', style: const TextStyle(fontWeight: FontWeight.bold)),
      ]),
    );
  }
}
