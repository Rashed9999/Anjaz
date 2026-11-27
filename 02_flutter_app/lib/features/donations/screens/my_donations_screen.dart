import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amial_pay/features/donations/controllers/donations_controller.dart';
import 'package:amial_pay/features/donations/domain/models/donation_models.dart';
import 'package:amial_pay/features/donations/screens/campaign_detail_screen.dart';
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

  Color _statusColor(String status) {
    switch (status) {
      case 'completed':
        return AmialColors.primary;
      case 'settled':
        return const Color(0xFF2E7D32);
      case 'refunded':
        return const Color(0xFFD32F2F);
      default:
        return AmialColors.textSecondary;
    }
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
          if (ctrl.isMyDonationsLoading.value && ctrl.myDonations.isEmpty) {
            return const Center(child: CircularProgressIndicator(color: AmialColors.primary));
          }
          if (ctrl.myDonationsError.value.isNotEmpty && ctrl.myDonations.isEmpty) {
            return Center(child: Column(mainAxisSize: MainAxisSize.min, children: [
              Text(ctrl.myDonationsError.value),
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

          // إحصاءات صغيرة في الأعلى مع استبعاد التبرعات المستردّة
          final activeDonations = ctrl.myDonations
              .where((d) => d.status != 'refunded')
              .toList();
          final totalAmount = activeDonations
              .fold<double>(0, (sum, d) => sum + (double.tryParse(d.amount) ?? 0));
          final formattedTotal = formatDonationAmount(totalAmount.toStringAsFixed(2));
          final count = activeDonations.length;

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
                          Text('$formattedTotal ر.ي',
                              style: const TextStyle(
                                  fontSize: 20, fontWeight: FontWeight.bold,
                                  color: AmialColors.primary)),
                          Text('في $count تبرع فعّال — جزاك الله خيراً',
                              style: const TextStyle(fontSize: 12)),
                        ],
                      ),
                    ),
                  ],
                ),
              ),
              const SizedBox(height: 16),
              ...ctrl.myDonations.map((d) {
                final campaignUlid = d.campaign?.campaignUlid;
                final statusColor = _statusColor(d.status);
                return Card(
                  margin: const EdgeInsets.symmetric(vertical: 4),
                  child: InkWell(
                    borderRadius: BorderRadius.circular(8),
                    onTap: (campaignUlid != null && campaignUlid.isNotEmpty)
                        ? () => Get.to(() => CampaignDetailScreen(ulid: campaignUlid))
                        : null,
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
                                const SizedBox(height: 4),
                                Wrap(
                                  spacing: 6,
                                  crossAxisAlignment: WrapCrossAlignment.center,
                                  children: [
                                    Container(
                                      padding: const EdgeInsets.symmetric(
                                          horizontal: 8, vertical: 2),
                                      decoration: BoxDecoration(
                                        color: statusColor.withValues(alpha: 0.12),
                                        borderRadius: BorderRadius.circular(10),
                                      ),
                                      child: Text(
                                        d.arabicStatusLabel,
                                        style: TextStyle(
                                            fontSize: 10,
                                            fontWeight: FontWeight.bold,
                                            color: statusColor),
                                      ),
                                    ),
                                    if (d.donatedAt != null)
                                      Text(
                                          '${d.donatedAt!.year}-${d.donatedAt!.month.toString().padLeft(2, '0')}-${d.donatedAt!.day.toString().padLeft(2, '0')}',
                                          style: const TextStyle(
                                              fontSize: 10, color: AmialColors.textMuted)),
                                  ],
                                ),
                              ],
                            ),
                          ),
                          Column(
                            crossAxisAlignment: CrossAxisAlignment.end,
                            children: [
                              Text('${d.formattedAmount} ر.ي',
                                  style: TextStyle(
                                      fontWeight: FontWeight.bold,
                                      color: d.status == 'refunded'
                                          ? AmialColors.textMuted
                                          : AmialColors.primary,
                                      decoration: d.status == 'refunded'
                                          ? TextDecoration.lineThrough
                                          : null)),
                              if (d.isAnonymous)
                                const Icon(Icons.visibility_off,
                                    size: 14, color: AmialColors.textMuted),
                            ],
                          ),
                        ],
                      ),
                    ),
                  ),
                );
              }),
              if (ctrl.hasMoreMyDonations.value)
                Padding(
                  padding: const EdgeInsets.symmetric(vertical: 12),
                  child: OutlinedButton(
                    onPressed: ctrl.isMyDonationsLoadingMore.value
                        ? null
                        : () => ctrl.loadMyDonations(loadMore: true),
                    child: ctrl.isMyDonationsLoadingMore.value
                        ? const SizedBox(
                            width: 18,
                            height: 18,
                            child: CircularProgressIndicator(strokeWidth: 2),
                          )
                        : const Text('تحميل المزيد'),
                  ),
                ),
            ],
          );
        }),
      ),
    );
  }
}
