import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/features/donations/controllers/donations_controller.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';

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
      backgroundColor: AmyalColors.background,
      appBar: AppBar(
        backgroundColor: AmyalColors.primary,
        foregroundColor: Colors.white,
        title: const Text('تبرعاتي'),
      ),
      body: RefreshIndicator(
        onRefresh: () => Get.find<DonationsController>().loadMyDonations(),
        color: AmyalColors.primary,
        child: Obx(() {
          final ctrl = Get.find<DonationsController>();
          if (ctrl.isLoading.value && ctrl.myDonations.isEmpty) {
            return const Center(child: CircularProgressIndicator(color: AmyalColors.primary));
          }
          if (ctrl.myDonations.isEmpty) {
            return ListView(
              children: [
                SizedBox(height: MediaQuery.of(context).size.height * 0.15),
                Icon(Icons.volunteer_activism, size: 80, color: AmyalColors.textMuted.withValues(alpha: 0.5)),
                const SizedBox(height: 16),
                const Center(child: Text('لم تتبرع بعد')),
                const SizedBox(height: 8),
                const Center(
                  child: Text('ابدأ بمساهمة صغيرة في حملة تختارها',
                      style: TextStyle(fontSize: 12, color: AmyalColors.textSecondary)),
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
                  color: AmyalColors.yellow.withValues(alpha: 0.2),
                  borderRadius: BorderRadius.circular(8),
                ),
                child: Row(
                  children: [
                    const Icon(Icons.favorite, color: AmyalColors.primary, size: 32),
                    const SizedBox(width: 12),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text('${totalAmount.toStringAsFixed(2)} ر.ي',
                              style: const TextStyle(
                                  fontSize: 20, fontWeight: FontWeight.bold,
                                  color: AmyalColors.primary)),
                          Text('في $count تبرع — جزاك الله خيراً',
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
                        backgroundColor: AmyalColors.background,
                        child: Icon(Icons.favorite, color: AmyalColors.primary),
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
                                      fontSize: 11, color: AmyalColors.textSecondary)),
                            if (d.donatedAt != null)
                              Text(
                                  '${d.donatedAt!.year}-${d.donatedAt!.month.toString().padLeft(2, '0')}-${d.donatedAt!.day.toString().padLeft(2, '0')}',
                                  style: const TextStyle(
                                      fontSize: 10, color: AmyalColors.textMuted)),
                          ],
                        ),
                      ),
                      Column(
                        crossAxisAlignment: CrossAxisAlignment.end,
                        children: [
                          Text('${d.amount} ر.ي',
                              style: const TextStyle(
                                  fontWeight: FontWeight.bold,
                                  color: AmyalColors.primary)),
                          if (d.isAnonymous)
                            const Icon(Icons.visibility_off,
                                size: 14, color: AmyalColors.textMuted),
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
