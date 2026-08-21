import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amial_pay/features/donations/controllers/donations_controller.dart';
import 'package:amial_pay/theme/amial_colors.dart';

/// AMIAL-DONATIONS-001 (v1.2)
class MyDonationsScreen extends StatefulWidget {
  const MyDonationsScreen({super.key});

  @override
  State<MyDonationsScreen> createState() => _MyDonationsScreenState();
}

class _MyDonationsScreenState extends State<MyDonationsScreen> {
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      Get.find<DonationsController>().loadMyDonations();
    });
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(
        title: const Text('تبرعاتي'),
      ),
      body: RefreshIndicator(
        onRefresh: () => Get.find<DonationsController>().loadMyDonations(),
        color: AmialColors.primary,
        child: Obx(() {
          final ctrl = Get.find<DonationsController>();
          if (ctrl.isLoading.value && ctrl.myDonations.isEmpty) {
            return const Center(child: CircularProgressIndicator(color: AmialColors.primary));
          }
          if (ctrl.lastError.value.isNotEmpty && ctrl.myDonations.isEmpty) {
            return Center(child: Column(mainAxisSize: MainAxisSize.min, children: [
              Text(ctrl.lastError.value),
              const SizedBox(height: 12),
              ElevatedButton(onPressed: ctrl.loadMyDonations, child: const Text('إعادة المحاولة')),
            ]));
          }
          if (ctrl.myDonations.isEmpty) {
            return ListView(
              children: [
                SizedBox(height: MediaQuery.of(context).size.height * 0.15),
                Icon(Icons.volunteer_activism, size: 80, color: AmialColors.textMuted.withValues(alpha: 0.5)),
                const SizedBox(height: 16),
                const Center(child: Text('لم تتبرع بعد')),
                const SizedBox(height: 8),
                const Center(
                  child: Text('ابدأ بمساهمة صغيرة في حملة تختارها',
                      style: TextStyle(fontSize: 12, color: AmialColors.textSecondary)),
                ),
              ],
            );
          }

          // إحصاءات صغيرة في الأعلى
          final totalAmount = ctrl.myDonations
              .fold<double>(0, (sum, d) => sum + (double.tryParse(d.amount) ?? 0));
          final count = ctrl.myDonations.length;

          return ListView(
            padding: const EdgeInsets.all(12),
            children: [
              Container(
                padding: const EdgeInsets.all(16),
                decoration: BoxDecoration(
                  color: AmialColors.yellow.withValues(alpha: 0.2),
                  borderRadius: BorderRadius.circular(8),
                ),
                child: Row(
                  children: [
                    const Icon(Icons.favorite, color: AmialColors.primary, size: 32),
                    const SizedBox(width: 12),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text('${totalAmount.toStringAsFixed(2)} ر.ي',
                              style: const TextStyle(
                                  fontSize: 20, fontWeight: FontWeight.bold,
                                  color: AmialColors.primary)),
                          Text('في $count تبرع معروض — جزاك الله خيراً',
                              style: const TextStyle(fontSize: 12)),
                        ],
                      ),
                    ),
                  ],
                ),
              ),
              const SizedBox(height: 16),
              ...ctrl.myDonations.map((d) => Card(
                margin: const EdgeInsets.symmetric(vertical: 4),
                child: Padding(
                  padding: const EdgeInsets.all(12),
                  child: Row(
                    children: [
                      const CircleAvatar(
                        radius: 20,
                        backgroundColor: AmialColors.background,
                        child: Icon(Icons.favorite, color: AmialColors.primary),
                      ),
                      const SizedBox(width: 12),
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(d.campaign?.titleAr ?? '-',
                                style: const TextStyle(
                                    fontWeight: FontWeight.w600, fontSize: 13)),
                            if (d.organization != null)
                              Text(d.organization!.nameAr,
                                  style: const TextStyle(
                                      fontSize: 11, color: AmialColors.textSecondary)),
                            if (d.donatedAt != null)
                              Text(
                                  '${d.donatedAt!.year}-${d.donatedAt!.month.toString().padLeft(2, '0')}-${d.donatedAt!.day.toString().padLeft(2, '0')}',
                                  style: const TextStyle(
                                      fontSize: 10, color: AmialColors.textMuted)),
                          ],
                        ),
                      ),
                      Column(
                        crossAxisAlignment: CrossAxisAlignment.end,
                        children: [
                          Text('${d.amount} ر.ي',
                              style: const TextStyle(
                                  fontWeight: FontWeight.bold,
                                  color: AmialColors.primary)),
                          if (d.isAnonymous)
                            const Icon(Icons.visibility_off,
                                size: 14, color: AmialColors.textMuted),
                        ],
                      ),
                    ],
                  ),
                ),
              )),
            ],
          );
        }),
      ),
    );
  }
}
