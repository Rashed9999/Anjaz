import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/features/donations/controllers/donations_controller.dart';
import 'package:amyal_pay/features/donations/screens/campaign_detail_screen.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';

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
      backgroundColor: AmyalColors.background,
      appBar: AppBar(
        backgroundColor: AmyalColors.primary,
        foregroundColor: Colors.white,
        title: const Text('حملات التصنيف'),
      ),
      body: Obx(() {
        final ctrl = Get.find<DonationsController>();
        if (ctrl.isLoading.value && ctrl.campaigns.isEmpty) {
          return const Center(child: CircularProgressIndicator(color: AmyalColors.primary));
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
                      Text(c.titleAr,
                          style: const TextStyle(
                              fontWeight: FontWeight.bold, fontSize: 14)),
                      const SizedBox(height: 6),
                      Text(c.descriptionAr,
                          maxLines: 2,
                          overflow: TextOverflow.ellipsis,
                          style: const TextStyle(
                              fontSize: 11, color: AmyalColors.textSecondary)),
                      const SizedBox(height: 8),
                      LinearProgressIndicator(
                        value: c.progressPercentage / 100,
                        color: AmyalColors.primary,
                        backgroundColor: AmyalColors.border,
                        minHeight: 4,
                      ),
                      const SizedBox(height: 4),
                      Row(
                        mainAxisAlignment: MainAxisAlignment.spaceBetween,
                        children: [
                          Text('${c.currentAmount} / ${c.targetAmount} ر.س',
                              style: const TextStyle(fontSize: 11)),
                          Text('${c.donorCount} متبرع',
                              style: const TextStyle(
                                  fontSize: 11, color: AmyalColors.textMuted)),
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
