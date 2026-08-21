import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amial_pay/features/donations/controllers/donations_controller.dart';
import 'package:amial_pay/features/donations/domain/models/donation_models.dart';
import 'package:amial_pay/theme/amial_colors.dart';
import 'package:amial_pay/features/shared/widgets/amial_pin_gate.dart';

/// AMIAL-DONATIONS-001 (v1.2)
class CampaignDetailScreen extends StatefulWidget {
  final String ulid;
  const CampaignDetailScreen({super.key, required this.ulid});

  @override
  State<CampaignDetailScreen> createState() => _CampaignDetailScreenState();
}

class _CampaignDetailScreenState extends State<CampaignDetailScreen> {
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      Get.find<DonationsController>().loadCampaign(widget.ulid);
    });
  }

  Future<void> _openDonateSheet(AmialCharityCampaign campaign) async {
    final amountCtrl = TextEditingController();
    final messageCtrl = TextEditingController();
    final formKey = GlobalKey<FormState>();
    bool isAnonymous = false;
    final quickAmounts = ['10', '50', '100', '200', '500'];

    final ok = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.white,
      shape: const RoundedRectangleBorder(
          borderRadius: BorderRadius.vertical(top: Radius.circular(16))),
      builder: (ctx) {
        return StatefulBuilder(
          builder: (ctx, setSheetState) => Padding(
            padding: EdgeInsets.only(
              bottom: MediaQuery.of(ctx).viewInsets.bottom,
              left: 16, right: 16, top: 16,
            ),
            child: SingleChildScrollView(
              child: Form(
                key: formKey,
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Row(
                      children: [
                        const Icon(Icons.volunteer_activism,
                            color: AmialColors.primary, size: 24),
                        const SizedBox(width: 8),
                        Expanded(
                          child: Text(
                            'تبرع لـ "${campaign.titleAr}"',
                            style: const TextStyle(
                                fontSize: 16, fontWeight: FontWeight.bold),
                            maxLines: 2,
                            overflow: TextOverflow.ellipsis,
                          ),
                        ),
                      ],
                    ),
                    const SizedBox(height: 16),

                    // Quick amounts
                    const Text('مبالغ مقترحة:',
                        style: TextStyle(fontSize: 12, color: AmialColors.textSecondary)),
                    const SizedBox(height: 8),
                    Wrap(
                      spacing: 8,
                      children: quickAmounts.map((amt) => InkWell(
                        onTap: () {
                          amountCtrl.text = amt;
                        },
                        child: Container(
                          padding: const EdgeInsets.symmetric(
                              horizontal: 14, vertical: 8),
                          decoration: BoxDecoration(
                            color: AmialColors.yellow.withValues(alpha: 0.2),
                            borderRadius: BorderRadius.circular(8),
                            border: Border.all(color: AmialColors.yellow),
                          ),
                          child: Text('$amt ر.ي',
                              style: const TextStyle(
                                  color: AmialColors.primary,
                                  fontWeight: FontWeight.w600)),
                        ),
                      )).toList(),
                    ),
                    const SizedBox(height: 12),

                    TextFormField(
                      controller: amountCtrl,
                      keyboardType: const TextInputType.numberWithOptions(decimal: true),
                      decoration: const InputDecoration(
                        labelText: 'المبلغ (ر.ي) *',
                        border: OutlineInputBorder(),
                      ),
                      validator: (v) {
                        final n = double.tryParse(v ?? '');
                        if (n == null || n < 1) return 'الحد الأدنى 1 ر.ي';
                        return null;
                      },
                    ),
                    const SizedBox(height: 12),

                    TextFormField(
                      controller: messageCtrl,
                      maxLength: 500,
                      decoration: const InputDecoration(
                        labelText: 'رسالة دعاء/تعليق (اختياري)',
                        border: OutlineInputBorder(),
                      ),
                    ),

                    CheckboxListTile(
                      title: const Text('تبرع باسم مجهول'),
                      subtitle: const Text(
                          'لن يظهر اسمك في قائمة المتبرعين العامة',
                          style: TextStyle(fontSize: 11)),
                      value: isAnonymous,
                      activeColor: AmialColors.primary,
                      onChanged: (v) => setSheetState(() => isAnonymous = v ?? false),
                      controlAffinity: ListTileControlAffinity.leading,
                      dense: true,
                    ),
                    const SizedBox(height: 8),

                    Obx(() {
                      final ctrl = Get.find<DonationsController>();
                      return ElevatedButton.icon(
                        onPressed: ctrl.isSubmitting.value
                            ? null
                            : () async {
                                if (!formKey.currentState!.validate()) return;
                                final pin = await askAmialPinInput(title: 'تأكيد التبرع');
                                if (pin == null || pin.isEmpty) return;
                                final success = await ctrl.donate(
                                  campaignUlid: widget.ulid,
                                  amount: amountCtrl.text.trim(),
                                  pin: pin,
                                  isAnonymous: isAnonymous,
                                  message: messageCtrl.text.trim().isEmpty
                                      ? null
                                      : messageCtrl.text.trim(),
                                );
                                if (success) {
                                  Navigator.pop(ctx, true);
                                } else {
                                  Get.snackbar('تعذّر التبرع', ctrl.lastError.value,
                                      snackPosition: SnackPosition.BOTTOM);
                                }
                              },
                        icon: ctrl.isSubmitting.value
                            ? const SizedBox(
                                width: 18, height: 18,
                                child: CircularProgressIndicator(
                                    color: Colors.white, strokeWidth: 2))
                            : const Icon(Icons.favorite),
                        label: const Text('تأكيد التبرع',
                            style: TextStyle(
                                fontSize: 15, fontWeight: FontWeight.w600)),
                        style: ElevatedButton.styleFrom(
                          backgroundColor: AmialColors.primary,
                          foregroundColor: Colors.white,
                          padding: const EdgeInsets.symmetric(vertical: 14),
                        ),
                      );
                    }),
                    const SizedBox(height: 16),
                  ],
                ),
              ),
            ),
          ),
        );
      },
    );

    if (ok == true && mounted) {
      showDialog(
        context: context,
        builder: (ctx) => AlertDialog(
          icon: const Icon(Icons.favorite, color: Colors.red, size: 56),
          title: const Text('تقبل الله منك'),
          content: const Text(
              'جزاك الله خيراً على تبرعك. ستجد الإيصال في قائمة الإيصالات.',
              textAlign: TextAlign.center),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(ctx),
              child: const Text('حسناً'),
            ),
          ],
        ),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(
        title: const Text('تفاصيل الحملة'),
      ),
      body: Obx(() {
        final ctrl = Get.find<DonationsController>();
        final campaign = ctrl.selectedCampaign.value;
        if (ctrl.isLoading.value) {
          return const Center(child: CircularProgressIndicator(color: AmialColors.primary));
        }
        if (campaign == null) {
          return Center(child: Padding(
            padding: const EdgeInsets.all(24),
            child: Column(mainAxisSize: MainAxisSize.min, children: [
              Text(ctrl.lastError.value.isEmpty ? 'تعذّر تحميل الحملة' : ctrl.lastError.value,
                  textAlign: TextAlign.center),
              const SizedBox(height: 12),
              ElevatedButton(onPressed: () => ctrl.loadCampaign(widget.ulid), child: const Text('إعادة المحاولة')),
            ]),
          ));
        }

        return Column(
          children: [
            Expanded(
              child: ListView(
                children: [
                  // ====== Cover ======
                  if (campaign.coverImageUrl != null)
                    Image.network(
                      campaign.coverImageUrl!,
                      height: 200,
                      width: double.infinity,
                      fit: BoxFit.cover,
                      errorBuilder: (_, _, _) => _placeholderBanner(),
                    )
                  else
                    _placeholderBanner(),

                  Padding(
                    padding: const EdgeInsets.all(16),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        // Title + verification badge
                        Row(
                          children: [
                            Expanded(
                              child: Text(
                                campaign.titleAr,
                                style: const TextStyle(
                                    fontSize: 18, fontWeight: FontWeight.bold),
                              ),
                            ),
                            if (campaign.organization?.isVerified == true)
                              const Icon(Icons.verified,
                                  color: AmialColors.primary, size: 20),
                          ],
                        ),
                        const SizedBox(height: 6),

                        // Org name
                        if (campaign.organization != null)
                          Text('بواسطة ${campaign.organization!.nameAr}',
                              style: const TextStyle(
                                  fontSize: 12, color: AmialColors.textSecondary)),

                        const SizedBox(height: 16),

                        // ====== Progress ======
                        Container(
                          padding: const EdgeInsets.all(12),
                          decoration: BoxDecoration(
                            color: Colors.white,
                            borderRadius: BorderRadius.circular(8),
                            border: Border.all(color: AmialColors.border),
                          ),
                          child: Column(
                            children: [
                              Row(
                                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                                children: [
                                  Column(
                                    crossAxisAlignment: CrossAxisAlignment.start,
                                    children: [
                                      Text(
                                        '${campaign.currentAmount} ر.ي',
                                        style: const TextStyle(
                                            fontSize: 20,
                                            fontWeight: FontWeight.bold,
                                            color: AmialColors.primary),
                                      ),
                                      Text('من ${campaign.targetAmount} ر.ي',
                                          style: const TextStyle(
                                              fontSize: 11,
                                              color: AmialColors.textMuted)),
                                    ],
                                  ),
                                  Column(
                                    crossAxisAlignment: CrossAxisAlignment.end,
                                    children: [
                                      Text('${campaign.progressPercentage.toStringAsFixed(1)}%',
                                          style: const TextStyle(
                                              fontSize: 18,
                                              fontWeight: FontWeight.bold)),
                                      Text('${campaign.donorCount} متبرع',
                                          style: const TextStyle(
                                              fontSize: 11,
                                              color: AmialColors.textMuted)),
                                    ],
                                  ),
                                ],
                              ),
                              const SizedBox(height: 8),
                              LinearProgressIndicator(
                                value: campaign.progressPercentage / 100,
                                backgroundColor: AmialColors.border,
                                color: AmialColors.primary,
                                minHeight: 8,
                              ),
                              if (campaign.daysRemaining != null) ...[
                                const SizedBox(height: 8),
                                Row(
                                  children: [
                                    const Icon(Icons.schedule,
                                        size: 14, color: AmialColors.textMuted),
                                    const SizedBox(width: 4),
                                    Text(
                                      campaign.daysRemaining! > 0
                                          ? 'متبقي ${campaign.daysRemaining} يوم'
                                          : 'انتهت',
                                      style: const TextStyle(
                                          fontSize: 11, color: AmialColors.textMuted),
                                    ),
                                  ],
                                ),
                              ],
                            ],
                          ),
                        ),

                        const SizedBox(height: 16),

                        // ====== Description ======
                        const Text('عن الحملة',
                            style: TextStyle(
                                fontWeight: FontWeight.bold, fontSize: 14)),
                        const SizedBox(height: 6),
                        Text(campaign.descriptionAr,
                            style: const TextStyle(fontSize: 13)),

                        if (campaign.beneficiaryCount != null) ...[
                          const SizedBox(height: 12),
                          Container(
                            padding: const EdgeInsets.all(10),
                            decoration: BoxDecoration(
                              color: AmialColors.yellow.withValues(alpha: 0.2),
                              borderRadius: BorderRadius.circular(8),
                            ),
                            child: Row(
                              children: [
                                const Icon(Icons.groups,
                                    color: AmialColors.primary),
                                const SizedBox(width: 8),
                                Expanded(
                                  child: Text(
                                    'المستفيدون: ${campaign.beneficiaryCount} '
                                    '${campaign.beneficiaryDescriptionAr ?? "شخص"}',
                                    style: const TextStyle(fontSize: 12),
                                  ),
                                ),
                              ],
                            ),
                          ),
                        ],

                        if (campaign.locationAr != null) ...[
                          const SizedBox(height: 8),
                          Row(
                            children: [
                              const Icon(Icons.location_on,
                                  size: 16, color: AmialColors.textMuted),
                              const SizedBox(width: 4),
                              Text(campaign.locationAr!,
                                  style: const TextStyle(
                                      fontSize: 12, color: AmialColors.textSecondary)),
                            ],
                          ),
                        ],

                        // ====== Recent donations ======
                        if (campaign.recentDonations != null &&
                            campaign.recentDonations!.isNotEmpty) ...[
                          const SizedBox(height: 24),
                          const Text('آخر المتبرعين',
                              style: TextStyle(
                                  fontWeight: FontWeight.bold, fontSize: 14)),
                          const SizedBox(height: 8),
                          ...campaign.recentDonations!.map((d) => _DonorTile(donation: d)),
                        ],

                        const SizedBox(height: 80),
                      ],
                    ),
                  ),
                ],
              ),
            ),

            // ====== Sticky donate button ======
            if (campaign.isAcceptingNow)
              Container(
                padding: const EdgeInsets.all(12),
                decoration: BoxDecoration(
                  color: Colors.white,
                  border: Border(top: BorderSide(color: AmialColors.border)),
                ),
                child: ElevatedButton.icon(
                  onPressed: () => _openDonateSheet(campaign),
                  icon: const Icon(Icons.favorite),
                  label: const Text('تبرع الآن',
                      style: TextStyle(fontSize: 16, fontWeight: FontWeight.w600)),
                  style: ElevatedButton.styleFrom(
                    backgroundColor: AmialColors.primary,
                    foregroundColor: Colors.white,
                    minimumSize: const Size(double.infinity, 50),
                  ),
                ),
              ),
            if (!campaign.isAcceptingNow)
              Container(
                width: double.infinity,
                padding: const EdgeInsets.all(16),
                color: AmialColors.yellow.withValues(alpha: 0.16),
                child: const Text('هذه الحملة لا تستقبل التبرعات حالياً', textAlign: TextAlign.center),
              ),
          ],
        );
      }),
    );
  }

  Widget _placeholderBanner() {
    return Container(
      height: 200,
      color: AmialColors.yellow.withValues(alpha: 0.25),
      child: const Center(
        child: Icon(Icons.volunteer_activism,
            size: 64, color: AmialColors.primary),
      ),
    );
  }
}

class _DonorTile extends StatelessWidget {
  final AmialRecentDonation donation;
  const _DonorTile({required this.donation});

  @override
  Widget build(BuildContext context) {
    return Container(
      margin: const EdgeInsets.only(bottom: 6),
      padding: const EdgeInsets.all(8),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(6),
        border: Border.all(color: AmialColors.border),
      ),
      child: Row(
        children: [
          const CircleAvatar(
            radius: 16,
            backgroundColor: AmialColors.background,
            child: Icon(Icons.person, color: AmialColors.primary, size: 18),
          ),
          const SizedBox(width: 8),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(donation.donorName,
                    style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w600)),
                if (donation.message != null && donation.message!.isNotEmpty)
                  Text(donation.message!,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(fontSize: 11, color: AmialColors.textMuted)),
              ],
            ),
          ),
          Text('${donation.amount} ر.ي',
              style: const TextStyle(
                  fontSize: 12, color: AmialColors.primary, fontWeight: FontWeight.bold)),
        ],
      ),
    );
  }
}
