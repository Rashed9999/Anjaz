import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/features/merchant/controllers/merchant_controller.dart';
import 'package:amyal_pay/features/merchant/screens/merchant_accept_payment_screen.dart';
import 'package:amyal_pay/features/merchant/screens/merchant_refund_screen.dart';
import 'package:amyal_pay/features/merchant/screens/merchant_transactions_screen.dart';
import 'package:amyal_pay/features/merchant/screens/credit_dashboard_screen.dart';
import 'package:amyal_pay/features/merchant/screens/inventory_screen.dart';
import 'package:amyal_pay/features/merchant/screens/cashier_pos_screen.dart';
import 'package:amyal_pay/features/merchant/screens/profit_report_screen.dart';
import 'package:amyal_pay/features/suppliers/screens/suppliers_screen.dart';
import 'package:amyal_pay/features/access/widgets/access_gate.dart';
import 'package:amyal_pay/features/plans/screens/plans_catalog_screen.dart';
import 'package:amyal_pay/features/merchant/screens/split_bill_create_screen.dart';
import 'package:amyal_pay/features/merchant/screens/split_bill_my_shares_screen.dart';
import 'package:amyal_pay/features/merchant_verification/screens/merchant_verification_screen.dart';
import 'package:amyal_pay/features/notification/screens/notifications_center_screen.dart';
import 'package:amyal_pay/features/notification/controllers/notifications_center_controller.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';

/// AMIAL-MERCHANT-APP-001 (v1.6)
class MerchantDashboardScreen extends StatefulWidget {
  const MerchantDashboardScreen({super.key});

  @override
  State<MerchantDashboardScreen> createState() => _MerchantDashboardScreenState();
}

class _MerchantDashboardScreenState extends State<MerchantDashboardScreen> {
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      Get.find<MerchantController>().loadProfile();
    });
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmyalColors.background,
      body: RefreshIndicator(
        onRefresh: () => Get.find<MerchantController>().loadProfile(),
        color: AmyalColors.primary,
        child: Obx(() {
          final ctrl = Get.find<MerchantController>();
          final merchant = ctrl.merchant.value;

          return ListView(
            children: [
              // ====== Header ======
              Container(
                padding: const EdgeInsets.fromLTRB(20, 40, 20, 20),
                decoration: const BoxDecoration(
                  gradient: LinearGradient(
                    colors: [AmyalColors.primary, Color(0xFF1D4FB8)],
                    begin: Alignment.topLeft,
                    end: Alignment.bottomRight,
                  ),
                  borderRadius:
                      BorderRadius.vertical(bottom: Radius.circular(24)),
                ),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        CircleAvatar(
                          radius: 26,
                          backgroundColor: Colors.white24,
                          backgroundImage: merchant?.logoUrl != null
                              ? NetworkImage(merchant!.logoUrl!)
                              : null,
                          child: merchant?.logoUrl == null
                              ? const Icon(Icons.store,
                                  color: Colors.white, size: 28)
                              : null,
                        ),
                        const SizedBox(width: 12),
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              const Text('تاجر أميال باي',
                                  style: TextStyle(
                                      color: Colors.white70, fontSize: 12)),
                              const SizedBox(height: 2),
                              Row(
                                children: [
                                  Flexible(
                                    child: Text(
                                      merchant?.displayName ?? '...',
                                      style: const TextStyle(
                                        color: Colors.white,
                                        fontSize: 18,
                                        fontWeight: FontWeight.bold,
                                      ),
                                    ),
                                  ),
                                  if (merchant?.verified == true) ...[
                                    const SizedBox(width: 4),
                                    const Icon(Icons.verified,
                                        color: AmyalColors.yellow, size: 16),
                                  ],
                                ],
                              ),
                              if (merchant?.merchantNumber != null)
                                Text(
                                  'رقم التاجر: ${merchant!.merchantNumber}',
                                  style: const TextStyle(
                                      color: Colors.white70, fontSize: 11),
                                ),
                            ],
                          ),
                        ),
                        // AMIAL-NOTIFICATIONS-001 — جرس الإشعارات مع badge
                        _NotificationBell(),
                      ],
                    ),
                    const SizedBox(height: 24),
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
                                  style: TextStyle(
                                      color: Colors.white70, fontSize: 12)),
                              const SizedBox(height: 2),
                              Text(
                                '${ctrl.stats.value.balance} ر.ي',
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

              // AMIAL-MERCHANT-VERIFY-001 — Banner لغير الموثَّقين
              if (merchant?.verified != true)
                Padding(
                  padding: const EdgeInsets.fromLTRB(16, 16, 16, 0),
                  child: InkWell(
                    borderRadius: BorderRadius.circular(12),
                    onTap: () => Get.to(() => const MerchantVerificationScreen()),
                    child: Container(
                      padding: const EdgeInsets.all(14),
                      decoration: BoxDecoration(
                        color: AmyalColors.yellow.withValues(alpha: 0.2),
                        borderRadius: BorderRadius.circular(12),
                        border: Border.all(color: AmyalColors.yellowDark),
                      ),
                      child: Row(children: [
                        Container(
                          width: 40, height: 40,
                          decoration: BoxDecoration(
                            color: AmyalColors.yellowDark,
                            borderRadius: BorderRadius.circular(10),
                          ),
                          child: const Icon(Icons.verified_user, color: Colors.white, size: 22),
                        ),
                        const SizedBox(width: 12),
                        Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                          const Text('وثّق متجرك',
                              style: TextStyle(fontWeight: FontWeight.bold, fontSize: 15)),
                          Text('احصل على شارة موثَّق وزِد ثقة عملائك',
                              style: TextStyle(fontSize: 12, color: Colors.grey.shade700)),
                        ])),
                        const Icon(Icons.chevron_left, color: AmyalColors.yellowDark),
                      ]),
                    ),
                  ),
                ),

              // ====== Quick Actions ======
              Padding(
                padding: const EdgeInsets.fromLTRB(16, 24, 16, 8),
                child: Row(
                  children: [
                    Expanded(
                      child: _ActionCard(
                        icon: Icons.qr_code,
                        color: AmyalColors.primary,
                        label: 'استلام دفعة',
                        onTap: () =>
                            Get.to(() => const MerchantAcceptPaymentScreen()),
                      ),
                    ),
                    const SizedBox(width: 12),
                    Expanded(
                      child: _ActionCard(
                        icon: Icons.undo,
                        color: const Color(0xFFEF4444),
                        label: 'استرجاع',
                        onTap: () => Get.to(() => const MerchantRefundScreen()),
                      ),
                    ),
                  ],
                ),
              ),

              // ====== Today's stats ======
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
                              style: TextStyle(
                                  fontWeight: FontWeight.bold, fontSize: 14)),
                        ],
                      ),
                      const SizedBox(height: 16),
                      _StatRow(
                        label: 'المبيعات',
                        value: '${ctrl.stats.value.todaySales} ر.ي',
                        icon: Icons.trending_up,
                        color: const Color(0xFF10B981),
                      ),
                      const Divider(height: 24),
                      _StatRow(
                        label: 'الاسترجاعات',
                        value: '${ctrl.stats.value.todayRefunds} ر.ي',
                        icon: Icons.undo,
                        color: const Color(0xFFEF4444),
                      ),
                      const Divider(height: 24),
                      _StatRow(
                        label: 'الصافي',
                        value: '${ctrl.stats.value.todayNet} ر.ي',
                        icon: Icons.account_balance_wallet,
                        color: AmyalColors.primary,
                      ),
                      const Divider(height: 24),
                      _StatRow(
                        label: 'عدد العمليات',
                        value: '${ctrl.stats.value.todayTransactionsCount}',
                        icon: Icons.receipt_long,
                        color: AmyalColors.yellow,
                      ),
                    ],
                  ),
                ),
              ),

              // ====== View all transactions ======
              Padding(
                padding: const EdgeInsets.fromLTRB(16, 16, 16, 8),
                child: Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    const Text('المبيعات',
                        style: TextStyle(
                            fontWeight: FontWeight.bold, fontSize: 14)),
                    TextButton(
                      onPressed: () =>
                          Get.to(() => const MerchantTransactionsScreen()),
                      child: const Text('عرض الكل'),
                    ),
                  ],
                ),
              ),

              // ====== Verification status alert ======
              if (merchant != null && !merchant.verified)
                Container(
                  margin: const EdgeInsets.all(16),
                  padding: const EdgeInsets.all(12),
                  decoration: BoxDecoration(
                    color: AmyalColors.yellow.withValues(alpha: 0.2),
                    borderRadius: BorderRadius.circular(8),
                    border: Border.all(
                        color: AmyalColors.yellow.withValues(alpha: 0.5)),
                  ),
                  child: const Row(
                    children: [
                      Icon(Icons.warning_amber, color: AmyalColors.primary),
                      SizedBox(width: 8),
                      Expanded(
                        child: Text(
                          'حسابك غير موثق بعد. أكمل عملية التوثيق للحصول على شارة التاجر الموثق.',
                          style: TextStyle(fontSize: 12),
                        ),
                      ),
                    ],
                  ),
                ),

              // ====== Quick links ======
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: 16),
                child: Column(
                  children: [
                    _LinkTile(
                      icon: Icons.receipt_long,
                      label: 'دفتر العملاء والديون',
                      onTap: () => Get.to(() => const CreditDashboardScreen()),
                    ),
                    // AMIAL-POS-001 (التصميم 36): كاشير بشبكة منتجات وسلة ووسائل دفع
                    _LinkTile(
                      icon: Icons.point_of_sale,
                      label: 'الكاشير',
                      onTap: () => Get.to(() => const CashierPosScreen()),
                    ),
                    // AMIAL-INVENTORY-001 (التصميم 42): إدارة المخزون — باقة البداية فأعلى
                    AccessGate(
                      feature: 'inventory',
                      fallback: _LinkTile(
                        icon: Icons.inventory_2_outlined,
                        label: 'إدارة المخزون 🔒 (ترقية)',
                        onTap: () => Get.to(() => const PlansCatalogScreen()),
                      ),
                      child: _LinkTile(
                        icon: Icons.inventory_2_outlined,
                        label: 'إدارة المخزون',
                        onTap: () => Get.to(() => const InventoryScreen()),
                      ),
                    ),
                    // AMIAL-PROFIT-001 (التصميم 48): تقارير الربحية — باقة الأعمال فأعلى
                    AccessGate(
                      feature: 'profit_reports',
                      fallback: _LinkTile(
                        icon: Icons.trending_up_rounded,
                        label: 'تقارير الربحية 🔒 (ترقية)',
                        onTap: () => Get.to(() => const PlansCatalogScreen()),
                      ),
                      child: _LinkTile(
                        icon: Icons.trending_up_rounded,
                        label: 'تقارير الربحية',
                        onTap: () => Get.to(() => const ProfitReportScreen()),
                      ),
                    ),
                    // AMIAL-SUPPLIERS-001 (68): الموردون — لباقات Business فأعلى
                    AccessGate(
                      feature: 'suppliers',
                      child: _LinkTile(
                        icon: Icons.local_shipping_outlined,
                        label: 'الموردون وأوامر الشراء',
                        onTap: () => Get.to(() => const SuppliersScreen()),
                      ),
                    ),
                    // AMIAL-AUDIT-FIX-001: وصل شاشتَي تقسيم الفاتورة (كانتا غير موصولتين)
                    _LinkTile(
                      icon: Icons.call_split,
                      label: 'تقسيم فاتورة',
                      onTap: () => Get.to(() => const SplitBillCreateScreen()),
                    ),
                    _LinkTile(
                      icon: Icons.groups,
                      label: 'حصصي في الفواتير المقسّمة',
                      onTap: () => Get.to(() => const SplitBillMySharesScreen()),
                    ),
                    _LinkTile(
                      icon: Icons.account_balance,
                      label: 'سحب للبنك',
                      onTap: () => _comingSoon(context),
                    ),
                    _LinkTile(
                      icon: Icons.people,
                      label: 'موظفو نقاط البيع',
                      onTap: () => _comingSoon(context),
                    ),
                    _LinkTile(
                      icon: Icons.settings,
                      label: 'إعدادات المتجر',
                      onTap: () => _comingSoon(context),
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

  void _comingSoon(BuildContext context) {
    ScaffoldMessenger.of(context).showSnackBar(
      const SnackBar(content: Text('قريباً')),
    );
  }
}

class _ActionCard extends StatelessWidget {
  final IconData icon;
  final Color color;
  final String label;
  final VoidCallback onTap;
  const _ActionCard(
      {required this.icon, required this.color, required this.label, required this.onTap});

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
  const _StatRow(
      {required this.label, required this.value, required this.icon, required this.color});

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

/// AMIAL-NOTIFICATIONS-001 — أيقونة الجرس مع badge للإشعارات غير المقروءة.
class _NotificationBell extends StatefulWidget {
  @override
  State<_NotificationBell> createState() => _NotificationBellState();
}

class _NotificationBellState extends State<_NotificationBell> {
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      try {
        Get.find<NotificationsCenterController>().refreshUnreadCount();
      } catch (_) {}
    });
  }

  @override
  Widget build(BuildContext context) {
    NotificationsCenterController? c;
    try {
      c = Get.find<NotificationsCenterController>();
    } catch (_) {}

    return Stack(
      alignment: Alignment.center,
      children: [
        IconButton(
          icon: const Icon(Icons.notifications_outlined, color: Colors.white, size: 26),
          onPressed: () async {
            await Get.to(() => const NotificationsCenterScreen());
            // عند العودة، حدّث العدد
            c?.refreshUnreadCount();
          },
        ),
        if (c != null)
          Obx(() {
            final n = c!.unreadCount.value;
            if (n <= 0) return const SizedBox.shrink();
            return Positioned(
              right: 6,
              top: 6,
              child: Container(
                padding: const EdgeInsets.symmetric(horizontal: 5, vertical: 2),
                constraints: const BoxConstraints(minWidth: 18, minHeight: 18),
                decoration: BoxDecoration(
                  color: AmyalColors.red,
                  borderRadius: BorderRadius.circular(10),
                  border: Border.all(color: AmyalColors.primary, width: 1),
                ),
                child: Text(
                  n > 99 ? '99+' : '$n',
                  textAlign: TextAlign.center,
                  style: const TextStyle(color: Colors.white, fontSize: 10, fontWeight: FontWeight.bold),
                ),
              ),
            );
          }),
      ],
    );
  }
}
