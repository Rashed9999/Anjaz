import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/features/agent/controllers/agent_controller.dart';
import 'package:amyal_pay/features/agent/screens/agent_cash_in_screen.dart';
import 'package:amyal_pay/features/agent/screens/agent_cash_out_screen.dart';
import 'package:amyal_pay/features/agent/screens/agent_float_screen.dart';
import 'package:amyal_pay/features/agent/screens/agent_transactions_screen.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';

/// AMIAL-AGENT-APP-001 (v1.6)
class AgentDashboardScreen extends StatefulWidget {
  const AgentDashboardScreen({super.key});

  @override
  State<AgentDashboardScreen> createState() => _AgentDashboardScreenState();
}

class _AgentDashboardScreenState extends State<AgentDashboardScreen> {
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      Get.find<AgentController>().loadProfile();
    });
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmyalColors.background,
      body: RefreshIndicator(
        onRefresh: () => Get.find<AgentController>().loadProfile(),
        color: AmyalColors.primary,
        child: Obx(() {
          final ctrl = Get.find<AgentController>();
          final agent = ctrl.agent.value;

          return ListView(
            children: [
              // ============ Header ============
              Container(
                padding: const EdgeInsets.fromLTRB(20, 40, 20, 20),
                decoration: const BoxDecoration(
                  gradient: LinearGradient(
                    colors: [AmyalColors.primary, Color(0xFF1A56C2)],
                    begin: Alignment.topLeft,
                    end: Alignment.bottomRight,
                  ),
                  borderRadius: BorderRadius.vertical(bottom: Radius.circular(24)),
                ),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        const CircleAvatar(
                          radius: 26,
                          backgroundColor: Colors.white24,
                          child: Icon(Icons.business_center,
                              color: Colors.white, size: 28),
                        ),
                        const SizedBox(width: 12),
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              const Text('وكيل أميال باي',
                                  style: TextStyle(color: Colors.white70, fontSize: 12)),
                              const SizedBox(height: 2),
                              Text(
                                agent?.displayName ?? '...',
                                style: const TextStyle(
                                  color: Colors.white,
                                  fontSize: 18,
                                  fontWeight: FontWeight.bold,
                                ),
                              ),
                              if (agent?.agentNumber != null)
                                Text(
                                  'رقم الوكيل: ${agent!.agentNumber}',
                                  style: const TextStyle(color: Colors.white70, fontSize: 11),
                                ),
                            ],
                          ),
                        ),
                      ],
                    ),
                    const SizedBox(height: 24),

                    // Balance
                    Container(
                      padding: const EdgeInsets.all(16),
                      decoration: BoxDecoration(
                        color: Colors.white.withValues(alpha: 0.15),
                        borderRadius: BorderRadius.circular(12),
                      ),
                      child: Row(
                        children: [
                          const Icon(Icons.account_balance_wallet,
                              color: Colors.white, size: 28),
                          const SizedBox(width: 12),
                          Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              const Text('الرصيد المتاح',
                                  style: TextStyle(color: Colors.white70, fontSize: 12)),
                              const SizedBox(height: 2),
                              Text(
                                '${ctrl.stats.value.balance} ر.س',
                                style: const TextStyle(
                                  color: Colors.white,
                                  fontSize: 22,
                                  fontWeight: FontWeight.bold,
                                ),
                              ),
                            ],
                          ),
                        ],
                      ),
                    ),
                  ],
                ),
              ),

              // ============ Quick Actions ============
              Padding(
                padding: const EdgeInsets.fromLTRB(16, 24, 16, 8),
                child: Row(
                  children: [
                    Expanded(
                      child: _ActionCard(
                        icon: Icons.arrow_downward,
                        color: const Color(0xFF10B981),
                        label: 'إيداع للعميل',
                        sublabel: 'Cash-In',
                        onTap: () => Get.to(() => const AgentCashInScreen()),
                      ),
                    ),
                    const SizedBox(width: 12),
                    Expanded(
                      child: _ActionCard(
                        icon: Icons.arrow_upward,
                        color: const Color(0xFFEF4444),
                        label: 'سحب من عميل',
                        sublabel: 'Cash-Out',
                        onTap: () => Get.to(() => const AgentCashOutScreen()),
                      ),
                    ),
                  ],
                ),
              ),

              // ============ Today's Stats ============
              Padding(
                padding: const EdgeInsets.fromLTRB(16, 16, 16, 8),
                child: Container(
                  padding: const EdgeInsets.all(16),
                  decoration: BoxDecoration(
                    color: Colors.white,
                    borderRadius: BorderRadius.circular(12),
                    border: Border.all(color: AmyalColors.border),
                  ),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      const Row(
                        children: [
                          Icon(Icons.bar_chart, color: AmyalColors.primary),
                          SizedBox(width: 8),
                          Text('إحصاءات اليوم',
                              style: TextStyle(fontWeight: FontWeight.bold, fontSize: 14)),
                        ],
                      ),
                      const SizedBox(height: 16),
                      _StatRow(
                        label: 'إيداعات للعملاء',
                        value: '${ctrl.stats.value.todayCashIn} ر.س',
                        icon: Icons.arrow_downward,
                        color: const Color(0xFF10B981),
                      ),
                      const Divider(height: 24),
                      _StatRow(
                        label: 'سحوبات من العملاء',
                        value: '${ctrl.stats.value.todayCashOut} ر.س',
                        icon: Icons.arrow_upward,
                        color: const Color(0xFFEF4444),
                      ),
                      const Divider(height: 24),
                      _StatRow(
                        label: 'العمولة',
                        value: '${ctrl.stats.value.todayCommission} ر.س',
                        icon: Icons.star,
                        color: AmyalColors.yellow,
                      ),
                      const Divider(height: 24),
                      _StatRow(
                        label: 'عدد العمليات',
                        value: '${ctrl.stats.value.todayTransactionsCount}',
                        icon: Icons.receipt_long,
                        color: AmyalColors.primary,
                      ),
                    ],
                  ),
                ),
              ),

              // ============ Recent Actions ============
              Padding(
                padding: const EdgeInsets.fromLTRB(16, 16, 16, 8),
                child: Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    const Text('عملياتي',
                        style: TextStyle(fontWeight: FontWeight.bold, fontSize: 14)),
                    TextButton(
                      onPressed: () => Get.to(() => const AgentTransactionsScreen()),
                      child: const Text('عرض الكل'),
                    ),
                  ],
                ),
              ),

              // ============ Quick links ============
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: 16),
                child: Column(
                  children: [
                    _LinkTile(
                      icon: Icons.account_balance_wallet,
                      label: 'سيولتي وشراء رصيد',
                      onTap: () => Get.to(() => const AgentFloatScreen()),
                    ),
                    _LinkTile(
                      icon: Icons.account_balance,
                      label: 'سحب إلى البنك',
                      onTap: () => _showComingSoon(context),
                    ),
                    _LinkTile(
                      icon: Icons.lock_outline,
                      label: 'تغيير رمز الأمان',
                      onTap: () => _showComingSoon(context),
                    ),
                    _LinkTile(
                      icon: Icons.notifications_outlined,
                      label: 'الإشعارات',
                      onTap: () => _showComingSoon(context),
                    ),
                  ],
                ),
              ),

              const SizedBox(height: 32),
            ],
          );
        }),
      ),
    );
  }

  void _showComingSoon(BuildContext context) {
    ScaffoldMessenger.of(context).showSnackBar(
      const SnackBar(content: Text('قريباً')),
    );
  }
}

class _ActionCard extends StatelessWidget {
  final IconData icon;
  final Color color;
  final String label;
  final String sublabel;
  final VoidCallback onTap;
  const _ActionCard({
    required this.icon,
    required this.color,
    required this.label,
    required this.sublabel,
    required this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(12),
      child: Container(
        padding: const EdgeInsets.all(16),
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(12),
          border: Border.all(color: AmyalColors.border),
        ),
        child: Column(
          children: [
            CircleAvatar(
              radius: 24,
              backgroundColor: color.withValues(alpha: 0.15),
              child: Icon(icon, color: color, size: 24),
            ),
            const SizedBox(height: 8),
            Text(label,
                style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13)),
            Text(sublabel,
                style: const TextStyle(fontSize: 10, color: AmyalColors.textMuted)),
          ],
        ),
      ),
    );
  }
}

class _StatRow extends StatelessWidget {
  final String label;
  final String value;
  final IconData icon;
  final Color color;
  const _StatRow({
    required this.label,
    required this.value,
    required this.icon,
    required this.color,
  });

  @override
  Widget build(BuildContext context) {
    return Row(
      children: [
        CircleAvatar(
          radius: 16,
          backgroundColor: color.withValues(alpha: 0.15),
          child: Icon(icon, color: color, size: 16),
        ),
        const SizedBox(width: 12),
        Expanded(child: Text(label, style: const TextStyle(fontSize: 13))),
        Text(value,
            style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14)),
      ],
    );
  }
}

class _LinkTile extends StatelessWidget {
  final IconData icon;
  final String label;
  final VoidCallback onTap;
  const _LinkTile({required this.icon, required this.label, required this.onTap});

  @override
  Widget build(BuildContext context) {
    return Card(
      elevation: 0,
      margin: const EdgeInsets.symmetric(vertical: 4),
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(8),
        side: const BorderSide(color: AmyalColors.border),
      ),
      child: ListTile(
        leading: Icon(icon, color: AmyalColors.primary),
        title: Text(label, style: const TextStyle(fontSize: 13)),
        trailing: const Icon(Icons.chevron_left, size: 20),
        onTap: onTap,
        dense: true,
      ),
    );
  }
}
