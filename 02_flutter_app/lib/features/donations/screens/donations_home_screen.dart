import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/features/donations/controllers/donations_controller.dart';
import 'package:amyal_pay/features/donations/domain/models/donation_models.dart';
import 'package:amyal_pay/features/donations/screens/campaign_detail_screen.dart';
import 'package:amyal_pay/features/donations/screens/campaigns_list_screen.dart';
import 'package:amyal_pay/features/donations/screens/my_donations_screen.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';

/// AMIAL-DONATIONS-001 (v1.2)
///
/// الصفحة الرئيسية لمنصة التبرع داخل التطبيق.
class DonationsHomeScreen extends StatefulWidget {
  const DonationsHomeScreen({super.key});

  @override
  State<DonationsHomeScreen> createState() => _DonationsHomeScreenState();
}

class _DonationsHomeScreenState extends State<DonationsHomeScreen> {
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      final ctrl = Get.find<DonationsController>();
      ctrl.loadCategories();
      ctrl.loadFeatured();
      ctrl.loadCampaigns();
    });
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmyalColors.background,
      appBar: AppBar(
        backgroundColor: AmyalColors.primary,
        foregroundColor: Colors.white,
        title: const Text('إحسان — التبرعات'),
        actions: [
          IconButton(
            icon: const Icon(Icons.favorite),
            tooltip: 'تبرعاتي',
            onPressed: () => Get.to(() => const MyDonationsScreen()),
          ),
        ],
      ),
      body: RefreshIndicator(
        onRefresh: () async {
          final ctrl = Get.find<DonationsController>();
          await Future.wait([
            ctrl.loadCategories(),
            ctrl.loadFeatured(),
            ctrl.loadCampaigns(),
          ]);
        },
        color: AmyalColors.primary,
        child: Obx(() {
          final ctrl = Get.find<DonationsController>();
          return ListView(
            padding: const EdgeInsets.symmetric(vertical: 8),
            children: [
              // ====== Hero banner ======
              Container(
                margin: const EdgeInsets.all(12),
                padding: const EdgeInsets.all(20),
                decoration: BoxDecoration(
                  gradient: const LinearGradient(
                    colors: [AmyalColors.primary, Color(0xFF1D4FB8)],
                    begin: Alignment.topLeft,
                    end: Alignment.bottomRight,
                  ),
                  borderRadius: BorderRadius.circular(12),
                ),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: const [
                    Icon(Icons.volunteer_activism, color: Colors.white, size: 32),
                    SizedBox(height: 8),
                    Text(
                      'تبرع لمن يحتاج',
                      style: TextStyle(
                          color: Colors.white,
                          fontSize: 22,
                          fontWeight: FontWeight.bold),
                    ),
                    SizedBox(height: 4),
                    Text(
                      'ساهم في حملات خيرية موثقة بأي مبلغ يناسبك',
                      style: TextStyle(color: Colors.white70, fontSize: 13),
                    ),
                  ],
                ),
              ),

              // ====== Categories ======
              if (ctrl.categories.isNotEmpty) ...[
                const Padding(
                  padding: EdgeInsets.fromLTRB(16, 16, 16, 8),
                  child: Text('التصنيفات',
                      style: TextStyle(fontSize: 14, fontWeight: FontWeight.bold)),
                ),
                SizedBox(
                  height: 100,
                  child: ListView.builder(
                    scrollDirection: Axis.horizontal,
                    padding: const EdgeInsets.symmetric(horizontal: 8),
                    itemCount: ctrl.categories.length,
                    itemBuilder: (context, i) {
                      final cat = ctrl.categories[i];
                      return _CategoryChip(category: cat, onTap: () {
                        Get.to(() => CampaignsListScreen(categoryCode: cat.code));
                      });
                    },
                  ),
                ),
              ],

              // ====== Featured ======
              if (ctrl.featuredCampaigns.isNotEmpty) ...[
                const Padding(
                  padding: EdgeInsets.fromLTRB(16, 16, 16, 8),
                  child: Row(
                    children: [
                      Icon(Icons.star, color: AmyalColors.yellow, size: 18),
                      SizedBox(width: 4),
                      Text('حملات مميزة',
                          style: TextStyle(fontSize: 14, fontWeight: FontWeight.bold)),
                    ],
                  ),
                ),
                SizedBox(
                  height: 220,
                  child: ListView.builder(
                    scrollDirection: Axis.horizontal,
                    padding: const EdgeInsets.symmetric(horizontal: 8),
                    itemCount: ctrl.featuredCampaigns.length,
                    itemBuilder: (context, i) {
                      final c = ctrl.featuredCampaigns[i];
                      return _FeaturedCard(campaign: c);
                    },
                  ),
                ),
              ],

              // ====== All active campaigns ======
              const Padding(
                padding: EdgeInsets.fromLTRB(16, 16, 16, 8),
                child: Text('كل الحملات النشطة',
                    style: TextStyle(fontSize: 14, fontWeight: FontWeight.bold)),
              ),
              if (ctrl.isLoading.value && ctrl.campaigns.isEmpty)
                const Center(
                    child: Padding(
                  padding: EdgeInsets.all(40),
                  child: CircularProgressIndicator(color: AmyalColors.primary),
                ))
              else if (ctrl.campaigns.isEmpty)
                const Center(
                    child: Padding(
                  padding: EdgeInsets.all(40),
                  child: Text('لا توجد حملات نشطة حالياً',
                      style: TextStyle(color: AmyalColors.textMuted)),
                ))
              else
                ...ctrl.campaigns.map((c) => _CampaignListItem(campaign: c)),

              const SizedBox(height: 24),
            ],
          );
        }),
      ),
    );
  }
}

class _CategoryChip extends StatelessWidget {
  final AmyalCharityCategory category;
  final VoidCallback onTap;
  const _CategoryChip({required this.category, required this.onTap});

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 4),
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(12),
        child: Container(
          width: 80,
          padding: const EdgeInsets.all(8),
          decoration: BoxDecoration(
            color: Colors.white,
            borderRadius: BorderRadius.circular(12),
            border: Border.all(color: AmyalColors.border),
          ),
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              CircleAvatar(
                radius: 24,
                backgroundColor: AmyalColors.yellow.withValues(alpha: 0.25),
                child: const Icon(Icons.favorite,
                    color: AmyalColors.primary, size: 22),
              ),
              const SizedBox(height: 4),
              Text(
                category.nameAr,
                textAlign: TextAlign.center,
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
                style: const TextStyle(fontSize: 11, fontWeight: FontWeight.w500),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _FeaturedCard extends StatelessWidget {
  final AmyalCharityCampaign campaign;
  const _FeaturedCard({required this.campaign});

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.all(6),
      child: InkWell(
        onTap: () => Get.to(() => CampaignDetailScreen(ulid: campaign.campaignUlid)),
        borderRadius: BorderRadius.circular(12),
        child: Container(
          width: 270,
          decoration: BoxDecoration(
            color: Colors.white,
            borderRadius: BorderRadius.circular(12),
            border: Border.all(color: AmyalColors.border),
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Container(
                height: 110,
                width: double.infinity,
                decoration: BoxDecoration(
                  borderRadius: const BorderRadius.vertical(top: Radius.circular(12)),
                  image: campaign.coverImageUrl != null
                      ? DecorationImage(
                          image: NetworkImage(campaign.coverImageUrl!),
                          fit: BoxFit.cover,
                        )
                      : null,
                  color: AmyalColors.yellow.withValues(alpha: 0.25),
                ),
                child: campaign.coverImageUrl == null
                    ? const Center(
                        child: Icon(Icons.volunteer_activism,
                            size: 40, color: AmyalColors.primary))
                    : null,
              ),
              Padding(
                padding: const EdgeInsets.all(10),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      campaign.titleAr,
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(
                          fontSize: 13, fontWeight: FontWeight.bold),
                    ),
                    const SizedBox(height: 6),
                    LinearProgressIndicator(
                      value: campaign.progressPercentage / 100,
                      backgroundColor: AmyalColors.border,
                      color: AmyalColors.primary,
                      minHeight: 6,
                    ),
                    const SizedBox(height: 4),
                    Text(
                      '${campaign.progressPercentage.toStringAsFixed(1)}% — ${campaign.currentAmount} / ${campaign.targetAmount} ر.ي',
                      style: const TextStyle(fontSize: 10, color: AmyalColors.textSecondary),
                    ),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _CampaignListItem extends StatelessWidget {
  final AmyalCharityCampaign campaign;
  const _CampaignListItem({required this.campaign});

  @override
  Widget build(BuildContext context) {
    return Card(
      margin: const EdgeInsets.symmetric(horizontal: 12, vertical: 4),
      child: InkWell(
        onTap: () => Get.to(() => CampaignDetailScreen(ulid: campaign.campaignUlid)),
        borderRadius: BorderRadius.circular(8),
        child: Padding(
          padding: const EdgeInsets.all(12),
          child: Row(
            children: [
              ClipRRect(
                borderRadius: BorderRadius.circular(8),
                child: campaign.coverImageUrl != null
                    ? Image.network(campaign.coverImageUrl!,
                        width: 70, height: 70, fit: BoxFit.cover,
                        errorBuilder: (_, _, _) => _placeholder())
                    : _placeholder(),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(campaign.titleAr,
                        style: const TextStyle(
                            fontWeight: FontWeight.bold, fontSize: 14),
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis),
                    const SizedBox(height: 4),
                    if (campaign.organization != null)
                      Text(campaign.organization!.nameAr,
                          style: const TextStyle(
                              fontSize: 11, color: AmyalColors.textSecondary)),
                    const SizedBox(height: 6),
                    LinearProgressIndicator(
                      value: campaign.progressPercentage / 100,
                      backgroundColor: AmyalColors.border,
                      color: AmyalColors.primary,
                      minHeight: 4,
                    ),
                    const SizedBox(height: 4),
                    Row(
                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                      children: [
                        Text('${campaign.currentAmount} ر.ي',
                            style: const TextStyle(
                                fontSize: 11, fontWeight: FontWeight.bold)),
                        Text(
                            '${campaign.progressPercentage.toStringAsFixed(0)}%',
                            style: const TextStyle(
                                fontSize: 11, color: AmyalColors.textMuted)),
                      ],
                    ),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _placeholder() => Container(
        width: 70, height: 70,
        color: AmyalColors.yellow.withValues(alpha: 0.2),
        child: const Icon(Icons.volunteer_activism, color: AmyalColors.primary),
      );
}
