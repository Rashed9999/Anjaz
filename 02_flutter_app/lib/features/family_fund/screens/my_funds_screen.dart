import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amial_pay/features/family_fund/controllers/funds_controller.dart';
import 'package:amial_pay/features/family_fund/domain/models/fund_models.dart';
import 'package:amial_pay/features/family_fund/screens/create_fund_screen.dart';
import 'package:amial_pay/features/family_fund/screens/fund_detail_screen.dart';
import 'package:amial_pay/helper/amial_money.dart';
import 'package:amial_pay/theme/amial_colors.dart';

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

  Future<void> _acceptInvite(AmialFundMembership m) async {
    final ctrl = Get.find<FundsController>();
    final ok = await ctrl.acceptInvitation(m.membershipId);
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(ok
            ? 'تم قبول الدعوة'
            : (ctrl.lastError.value.isNotEmpty
                ? ctrl.lastError.value
                : 'فشل قبول الدعوة')),
        backgroundColor: ok ? Colors.green.shade700 : AmialColors.red,
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(
        title: const Text('الصناديق العائلية'),
      ),
      floatingActionButton: FloatingActionButton.extended(
        backgroundColor: AmialColors.primary,
        foregroundColor: Colors.white,
        onPressed: () async {
          // createFund يحدّث القائمة تلقائياً، فلا حاجة لتكرار loadMyFunds() هنا.
          await Get.to<bool>(() => const CreateFundScreen());
        },
        icon: const Icon(Icons.add),
        label: const Text('صندوق جديد'),
      ),
      body: RefreshIndicator(
        onRefresh: () => Get.find<FundsController>().loadMyFunds(),
        color: AmialColors.primary,
        child: Obx(() {
          final ctrl = Get.find<FundsController>();
          if (ctrl.isLoading.value && ctrl.myMemberships.isEmpty) {
            return const Center(
                child: CircularProgressIndicator(color: AmialColors.primary));
          }
          if (ctrl.myMemberships.isEmpty && ctrl.lastError.value.isNotEmpty) {
            return ListView(
              physics: const AlwaysScrollableScrollPhysics(),
              padding: const EdgeInsets.all(24),
              children: [
                SizedBox(height: MediaQuery.of(context).size.height * 0.15),
                const Icon(Icons.error_outline,
                    size: 64, color: AmialColors.red),
                const SizedBox(height: 16),
                Center(
                  child: Text(
                    ctrl.lastError.value,
                    textAlign: TextAlign.center,
                    style: const TextStyle(fontSize: 14),
                  ),
                ),
                const SizedBox(height: 16),
                Center(
                  child: ElevatedButton.icon(
                    onPressed: () => ctrl.loadMyFunds(),
                    icon: const Icon(Icons.refresh),
                    label: const Text('إعادة المحاولة'),
                    style: ElevatedButton.styleFrom(
                      backgroundColor: AmialColors.primary,
                      foregroundColor: Colors.white,
                    ),
                  ),
                ),
              ],
            );
          }
          if (ctrl.myMemberships.isEmpty) {
            return ListView(
              physics: const AlwaysScrollableScrollPhysics(),
              children: [
                SizedBox(height: MediaQuery.of(context).size.height * 0.15),
                Icon(Icons.diversity_3,
                    size: 80,
                    color: AmialColors.textMuted.withValues(alpha: 0.5)),
                const SizedBox(height: 16),
                const Center(
                    child: Text('لا توجد صناديق بعد',
                        style: TextStyle(fontSize: 14))),
                const SizedBox(height: 8),
                const Center(
                  child: Text(
                    'أنشئ صندوقاً جديداً أو انتظر دعوة',
                    style: TextStyle(
                        color: AmialColors.textSecondary, fontSize: 12),
                  ),
                ),
              ],
            );
          }

          final invited = ctrl.myMemberships.where((m) => m.isInvited).toList();
          final active = ctrl.myMemberships.where((m) => m.isActive).toList();

          return ListView(
            physics: const AlwaysScrollableScrollPhysics(),
            padding: const EdgeInsets.all(12),
            children: [
              if (invited.isNotEmpty) ...[
                const _SectionHeader(
                    title: 'دعوات مُعلَّقة', icon: Icons.mail_outline),
                ...invited.map((m) =>
                    _InviteCard(membership: m, onAccept: () => _acceptInvite(m))),
                const SizedBox(height: 16),
              ],
              if (active.isNotEmpty) ...[
                const _SectionHeader(
                    title: 'صناديقك النشطة', icon: Icons.diversity_3),
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

String _arabicRole(String role) {
  switch (role) {
    case 'owner':
      return 'المالك';
    case 'admin':
      return 'مشرف';
    case 'member':
      return 'عضو';
    case 'viewer':
      return 'مشاهد';
    default:
      return role;
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
          Icon(icon, size: 18, color: AmialColors.textSecondary),
          const SizedBox(width: 8),
          Text(title,
              style: const TextStyle(
                  fontWeight: FontWeight.bold,
                  fontSize: 13,
                  color: AmialColors.textSecondary)),
        ],
      ),
    );
  }
}

class _InviteCard extends StatelessWidget {
  final AmialFundMembership membership;
  final VoidCallback onAccept;
  const _InviteCard({required this.membership, required this.onAccept});

  @override
  Widget build(BuildContext context) {
    final fund = membership.fund;
    return Card(
      margin: const EdgeInsets.symmetric(vertical: 4),
      color: AmialColors.yellow.withValues(alpha: 0.15),
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                const Icon(Icons.mail, color: AmialColors.primary, size: 20),
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
                    color: AmialColors.primary,
                    borderRadius: BorderRadius.circular(4),
                  ),
                  child: Text(
                    _arabicRole(membership.role),
                    style: const TextStyle(color: Colors.white, fontSize: 10),
                  ),
                ),
              ],
            ),
            if (fund?.description != null && fund!.description!.isNotEmpty) ...[
              const SizedBox(height: 6),
              Text(fund.description!,
                  style: const TextStyle(
                      fontSize: 12, color: AmialColors.textSecondary)),
            ],
            const SizedBox(height: 12),
            Row(
              children: [
                Expanded(
                  child: ElevatedButton(
                    onPressed: onAccept,
                    style: ElevatedButton.styleFrom(
                      backgroundColor: AmialColors.primary,
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
  final AmialFundMembership membership;
  const _FundCard({required this.membership});

  @override
  Widget build(BuildContext context) {
    final fund = membership.fund;
    if (fund == null) return const SizedBox.shrink();

    final targetVal = double.tryParse(fund.targetAmount ?? '') ?? 0;
    final balanceVal = double.tryParse(fund.balance) ?? 0;
    final hasTarget = targetVal > 0;
    final progress = hasTarget ? (balanceVal / targetVal).clamp(0.0, 1.0) : 0.0;

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
                backgroundColor: AmialColors.yellow.withValues(alpha: 0.25),
                child:
                    const Icon(Icons.diversity_3, color: AmialColors.primary),
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
                      'الرصيد: ${AmialMoney.yer(fund.balance)}',
                      style: const TextStyle(
                          color: AmialColors.primary, fontSize: 13),
                    ),
                    if (hasTarget) ...[
                      const SizedBox(height: 6),
                      ClipRRect(
                        borderRadius: BorderRadius.circular(4),
                        child: LinearProgressIndicator(
                          value: progress,
                          minHeight: 6,
                          backgroundColor: const Color(0xFFF0EFEA),
                          color: AmialColors.primary,
                        ),
                      ),
                      const SizedBox(height: 3),
                      Text(
                        'الهدف: ${AmialMoney.yer(fund.targetAmount!)} (${(progress * 100).toStringAsFixed(0)}%)',
                        style: const TextStyle(
                            fontSize: 11, color: AmialColors.textSecondary),
                      ),
                    ],
                  ],
                ),
              ),
              const SizedBox(width: 8),
              Column(
                crossAxisAlignment: CrossAxisAlignment.end,
                children: [
                  Container(
                    padding:
                        const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
                    decoration: BoxDecoration(
                      color: membership.isOwner
                          ? AmialColors.primary
                          : AmialColors.textSecondary,
                      borderRadius: BorderRadius.circular(4),
                    ),
                    child: Text(_arabicRole(membership.role),
                        style: const TextStyle(
                            color: Colors.white, fontSize: 10)),
                  ),
                  const SizedBox(height: 4),
                  const Icon(Icons.chevron_left,
                      size: 16, color: AmialColors.textMuted),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }
}
