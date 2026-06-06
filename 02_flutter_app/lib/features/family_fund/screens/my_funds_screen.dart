import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/features/family_fund/controllers/funds_controller.dart';
import 'package:amyal_pay/features/family_fund/domain/models/fund_models.dart';
import 'package:amyal_pay/features/family_fund/screens/create_fund_screen.dart';
import 'package:amyal_pay/features/family_fund/screens/fund_detail_screen.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';

/// AMIAL-FUND-FAMILY-001 (v0.9-D)
class MyFundsScreen extends StatefulWidget {
  const MyFundsScreen({super.key});

  @override
  State<MyFundsScreen> createState() => _MyFundsScreenState();
}

class _MyFundsScreenState extends State<MyFundsScreen> {
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      Get.find<FundsController>().loadMyFunds();
    });
  }

  Future<void> _acceptInvite(AmyalFundMembership m) async {
    final ok = await Get.find<FundsController>().acceptInvitation(m.membershipId);
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(ok ? 'تم قبول الدعوة' : 'فشل قبول الدعوة'),
        backgroundColor: ok ? Colors.green.shade700 : AmyalColors.red,
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmyalColors.background,
      appBar: AppBar(
        backgroundColor: AmyalColors.primary,
        foregroundColor: Colors.white,
        title: const Text('الصناديق العائلية'),
        elevation: 0,
      ),
      floatingActionButton: FloatingActionButton.extended(
        backgroundColor: AmyalColors.primary,
        foregroundColor: Colors.white,
        onPressed: () async {
          final created = await Get.to<bool>(() => const CreateFundScreen());
          if (created == true && mounted) {
            Get.find<FundsController>().loadMyFunds();
          }
        },
        icon: const Icon(Icons.add),
        label: const Text('صندوق جديد'),
      ),
      body: RefreshIndicator(
        onRefresh: () => Get.find<FundsController>().loadMyFunds(),
        color: AmyalColors.primary,
        child: Obx(() {
          final ctrl = Get.find<FundsController>();
          if (ctrl.isLoading.value && ctrl.myMemberships.isEmpty) {
            return const Center(
                child: CircularProgressIndicator(color: AmyalColors.primary));
          }
          if (ctrl.myMemberships.isEmpty) {
            return ListView(
              children: [
                SizedBox(height: MediaQuery.of(context).size.height * 0.15),
                Icon(Icons.diversity_3, size: 80, color: AmyalColors.textMuted.withOpacity(0.5)),
                const SizedBox(height: 16),
                const Center(child: Text('لا توجد صناديق بعد', style: TextStyle(fontSize: 14))),
                const SizedBox(height: 8),
                const Center(
                  child: Text(
                    'أنشئ صندوقاً جديداً أو انتظر دعوة',
                    style: TextStyle(color: AmyalColors.textSecondary, fontSize: 12),
                  ),
                ),
              ],
            );
          }

          final invited = ctrl.myMemberships.where((m) => m.isInvited).toList();
          final active = ctrl.myMemberships.where((m) => m.isActive).toList();

          return ListView(
            padding: const EdgeInsets.all(12),
            children: [
              if (invited.isNotEmpty) ...[
                const _SectionHeader(title: 'دعوات مُعلَّقة', icon: Icons.mail_outline),
                ...invited.map((m) => _InviteCard(membership: m, onAccept: () => _acceptInvite(m))),
                const SizedBox(height: 16),
              ],
              if (active.isNotEmpty) ...[
                const _SectionHeader(title: 'صناديقك النشطة', icon: Icons.diversity_3),
                ...active.map((m) => _FundCard(membership: m)),
              ],
              const SizedBox(height: 80), // FAB spacing
            ],
          );
        }),
      ),
    );
  }
}

class _SectionHeader extends StatelessWidget {
  final String title;
  final IconData icon;
  const _SectionHeader({required this.title, required this.icon});

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(4, 8, 4, 8),
      child: Row(
        children: [
          Icon(icon, size: 18, color: AmyalColors.textSecondary),
          const SizedBox(width: 8),
          Text(title,
              style: const TextStyle(
                  fontWeight: FontWeight.bold,
                  fontSize: 13,
                  color: AmyalColors.textSecondary)),
        ],
      ),
    );
  }
}

class _InviteCard extends StatelessWidget {
  final AmyalFundMembership membership;
  final VoidCallback onAccept;
  const _InviteCard({required this.membership, required this.onAccept});

  @override
  Widget build(BuildContext context) {
    final fund = membership.fund;
    return Card(
      margin: const EdgeInsets.symmetric(vertical: 4),
      color: AmyalColors.yellow.withOpacity(0.15),
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                const Icon(Icons.mail, color: AmyalColors.primary, size: 20),
                const SizedBox(width: 8),
                Expanded(
                  child: Text(
                    fund?.name ?? 'صندوق غير معروف',
                    style: const TextStyle(
                        fontWeight: FontWeight.bold, fontSize: 15),
                  ),
                ),
                Container(
                  padding:
                      const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
                  decoration: BoxDecoration(
                    color: AmyalColors.primary,
                    borderRadius: BorderRadius.circular(4),
                  ),
                  child: Text(
                    membership.role,
                    style: const TextStyle(color: Colors.white, fontSize: 10),
                  ),
                ),
              ],
            ),
            if (fund?.description != null && fund!.description!.isNotEmpty) ...[
              const SizedBox(height: 6),
              Text(fund.description!,
                  style: const TextStyle(
                      fontSize: 12, color: AmyalColors.textSecondary)),
            ],
            const SizedBox(height: 12),
            Row(
              children: [
                Expanded(
                  child: ElevatedButton(
                    onPressed: onAccept,
                    style: ElevatedButton.styleFrom(
                      backgroundColor: AmyalColors.primary,
                      foregroundColor: Colors.white,
                    ),
                    child: const Text('قبول'),
                  ),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

class _FundCard extends StatelessWidget {
  final AmyalFundMembership membership;
  const _FundCard({required this.membership});

  @override
  Widget build(BuildContext context) {
    final fund = membership.fund;
    if (fund == null) return const SizedBox.shrink();

    return Card(
      margin: const EdgeInsets.symmetric(vertical: 4),
      child: InkWell(
        borderRadius: BorderRadius.circular(8),
        onTap: () {
          Get.to(() => FundDetailScreen(fundUlid: fund.fundUlid));
        },
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Row(
            children: [
              CircleAvatar(
                backgroundColor: AmyalColors.yellow.withOpacity(0.25),
                child: const Icon(Icons.diversity_3, color: AmyalColors.primary),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(fund.name,
                        style: const TextStyle(
                            fontWeight: FontWeight.bold, fontSize: 15)),
                    Text(
                      'الرصيد: ${fund.balance} ر.س',
                      style: const TextStyle(
                          color: AmyalColors.primary, fontSize: 13),
                    ),
                  ],
                ),
              ),
              Column(
                crossAxisAlignment: CrossAxisAlignment.end,
                children: [
                  Container(
                    padding:
                        const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
                    decoration: BoxDecoration(
                      color: membership.isOwner
                          ? AmyalColors.primary
                          : AmyalColors.textSecondary,
                      borderRadius: BorderRadius.circular(4),
                    ),
                    child: Text(membership.role,
                        style: const TextStyle(
                            color: Colors.white, fontSize: 10)),
                  ),
                  const SizedBox(height: 4),
                  const Icon(Icons.chevron_left,
                      size: 16, color: AmyalColors.textMuted),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }
}
