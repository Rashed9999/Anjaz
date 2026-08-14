import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amial_pay/features/donations/controllers/donations_controller.dart';
import 'package:amial_pay/features/donations/screens/campaign_detail_screen.dart';
import 'package:amial_pay/theme/amial_colors.dart';

/// AMIAL-DONATIONS-001 (v1.2)
class CampaignsListScreen extends StatefulWidget {
  final String categoryCode;
  const CampaignsListScreen({super.key, required this.categoryCode});

  @override
  State<CampaignsListScreen> createState() => _CampaignsListScreenState();
}

class _CampaignsListScreenState extends State<CampaignsListScreen> {
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      Get.find<DonationsController>().loadCampaigns(categoryCode: widget.categoryCode);
    });
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(
        title: const Text('حملات التصنيف'),
      ),
      body: Obx(() {
        final ctrl = Get.find<DonationsController>();
        if (ctrl.isLoading.value && ctrl.campaigns.isEmpty) {
          return const Center(child: CircularProgressIndicator(color: AmialColors.primary));
        }
        if (ctrl.campaigns.isEmpty) {
          return const Center(child: Text('لا توجد حملات نشطة في هذا التصنيف'));
        }
        return ListView.builder(
          padding: const EdgeInsets.all(12),
          itemCount: ctrl.campaigns.length,
          itemBuilder: (context, i) {
            final c = ctrl.campaigns[i];
            return Card(
              margin: const EdgeInsets.symmetric(vertical: 4),
              child: InkWell(
                onTap: () => Get.to(() => CampaignDetailScreen(ulid: c.campaignUlid)),
                borderRadius: BorderRadius.circular(8),
                child: Padding(
                  padding: const EdgeInsets.all(12),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      // AMIAL-CHARITY-META-001 — العلامتان: «عاجل» والتصنيف.
                      // والترتيبُ من الخادم يضع العاجلَ أوّلاً، فالشارةُ
                      // تفسّر لماذا هذه الحملةُ في الصدارة.
                      if (c.isUrgent || c.category != null)
                        Padding(
                          padding: const EdgeInsets.only(bottom: 6),
                          child: Wrap(
                            spacing: 6,
                            children: [
                              if (c.isUrgent) const _Tag('عاجل', Color(0xFFD32F2F)),
                              if (c.category != null)
                                _Tag(c.category!.nameAr, AmialColors.primary),
                            ],
                          ),
                        ),
                      Text(c.titleAr,
                          style: const TextStyle(
                              fontWeight: FontWeight.bold, fontSize: 14)),
                      const SizedBox(height: 6),
                      Text(c.descriptionAr,
                          maxLines: 2,
                          overflow: TextOverflow.ellipsis,
                          style: const TextStyle(
                              fontSize: 11, color: AmialColors.textSecondary)),
                      const SizedBox(height: 8),
                      LinearProgressIndicator(
                        value: c.progressPercentage / 100,
                        color: AmialColors.primary,
                        backgroundColor: AmialColors.border,
                        minHeight: 4,
                      ),
                      const SizedBox(height: 4),
                      Row(
                        mainAxisAlignment: MainAxisAlignment.spaceBetween,
                        children: [
                          Text('${c.currentAmount} / ${c.targetAmount} ر.ي',
                              style: const TextStyle(fontSize: 11)),
                          Text('${c.donorCount} متبرع',
                              style: const TextStyle(
                                  fontSize: 11, color: AmialColors.textMuted)),
                        ],
                      ),
                    ],
                  ),
                ),
              ),
            );
          },
        );
      }),
    );
  }
}

/// شارةٌ صغيرةٌ ملوَّنة — «عاجل» أو اسمُ التصنيف.
class _Tag extends StatelessWidget {
  final String label;
  final Color color;
  const _Tag(this.label, this.color);

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
        decoration: BoxDecoration(
          color: color.withValues(alpha: 0.12),
          borderRadius: BorderRadius.circular(10),
        ),
        child: Text(label,
            style: TextStyle(
                fontSize: 10, fontWeight: FontWeight.bold, color: color)),
      );
}
