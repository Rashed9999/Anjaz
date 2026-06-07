import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';
import 'package:amyal_pay/features/withdraw/screens/withdraw_request_screen.dart';
import 'package:amyal_pay/features/me/screens/my_account_number_screen.dart';
import 'package:amyal_pay/features/notification/screens/notifications_center_screen.dart';
import 'package:amyal_pay/features/notification/controllers/notifications_center_controller.dart';
import 'package:amyal_pay/features/me/domain/me_repo.dart';
import 'package:amyal_pay/features/requested_money/screens/payment_request_create_screen.dart';
import 'package:amyal_pay/features/receipts/screens/receipts_list_screen.dart';
import 'package:amyal_pay/features/safe_payment/screens/my_safe_payments_screen.dart';
import 'package:amyal_pay/features/merchant_verification/screens/merchant_verification_screen.dart';
import 'package:amyal_pay/features/fuel_station/screens/fuel_station_dashboard_screen.dart';
import 'package:amyal_pay/features/pharmacy/screens/pharmacy_dashboard_screen.dart';
import 'package:amyal_pay/features/wholesale/screens/wholesale_screens.dart';
import 'package:amyal_pay/features/plans/screens/plans_catalog_screen.dart';
import 'package:amyal_pay/features/plans/screens/my_usage_screen.dart';
import 'package:amyal_pay/features/branches/screens/branches_management_screen.dart';
import 'package:amyal_pay/features/access/widgets/access_gate.dart';
import 'package:amyal_pay/features/access/controllers/access_controller.dart';
import 'package:amyal_pay/features/family_fund/screens/my_funds_screen.dart';
import 'package:amyal_pay/features/bill_pay/screens/bill_pay_providers_screen.dart';
import 'package:amyal_pay/shared/widgets/verified_badge.dart';

/// AMIAL-MY-SERVICES — نقطة وصول موحّدة لميزات أميال باي الجديدة.
///
/// تُفتح من زر في Profile/Settings.
/// تعرض رقم الحساب الحالي ومجموعة الخدمات (سحب، إشعارات، ديون...).
class MyServicesScreen extends StatefulWidget {
  const MyServicesScreen({super.key});

  @override
  State<MyServicesScreen> createState() => _MyServicesScreenState();
}

class _MyServicesScreenState extends State<MyServicesScreen> {
  late final MeController me;
  late final NotificationsCenterController notif;

  @override
  void initState() {
    super.initState();
    me = Get.find<MeController>();
    notif = Get.find<NotificationsCenterController>();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (me.me.value == null) me.load();
      notif.refreshUnreadCount();
    });
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmyalColors.background,
      appBar: AppBar(
        title: const Text('خدماتي'),
        backgroundColor: AmyalColors.primary,
        foregroundColor: Colors.white,
      ),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          // ====== بطاقة رقم الحساب المختصرة ======
          Obx(() {
            final m = me.me.value;
            final acc = m?['account_number']?.toString() ?? '...';
            final verification = (m?['verification'] ?? {}) as Map;
            final tier = verification['tier']?.toString();

            return InkWell(
              borderRadius: BorderRadius.circular(16),
              onTap: () => Get.to(() => const MyAccountNumberScreen()),
              child: Container(
                padding: const EdgeInsets.all(20),
                decoration: BoxDecoration(
                  gradient: const LinearGradient(
                    colors: [AmyalColors.primary, AmyalColors.primaryDark],
                    begin: Alignment.topLeft,
                    end: Alignment.bottomRight,
                  ),
                  borderRadius: BorderRadius.circular(16),
                ),
                child: Row(children: [
                  Container(
                    width: 50, height: 50,
                    decoration: BoxDecoration(
                      color: AmyalColors.yellow,
                      borderRadius: BorderRadius.circular(12),
                    ),
                    child: const Icon(Icons.account_balance_wallet, color: Colors.black87, size: 26),
                  ),
                  const SizedBox(width: 16),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Row(children: [
                          const Text('رقم حسابي', style: TextStyle(color: Colors.white70, fontSize: 12)),
                          if (tier != null) ...[
                            const SizedBox(width: 8),
                            VerifiedBadge(tier: tier, size: VerifiedBadgeSize.small),
                          ],
                        ]),
                        const SizedBox(height: 4),
                        Text(
                          acc,
                          style: const TextStyle(
                            color: Colors.white,
                            fontSize: 24,
                            fontWeight: FontWeight.bold,
                            letterSpacing: 2,
                          ),
                        ),
                      ],
                    ),
                  ),
                  const Icon(Icons.chevron_left, color: Colors.white70),
                ]),
              ),
            );
          }),

          const SizedBox(height: 24),
          const Text(
            'الخدمات',
            textAlign: TextAlign.right,
            style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold),
          ),
          const SizedBox(height: 12),

          // CRITICAL-001 — Banner لمن لم يختر نوع نشاطه (التاجر فقط)
          Obx(() {
            final access = Get.find<AccessController>();
            if (!access.needsBusinessTypeSelection) return const SizedBox.shrink();
            return Padding(
              padding: const EdgeInsets.only(bottom: 12),
              child: InkWell(
                borderRadius: BorderRadius.circular(12),
                onTap: () => Get.to(() => const BusinessTypeSelectionScreen()),
                child: Container(
                  padding: const EdgeInsets.all(12),
                  decoration: BoxDecoration(
                    color: AmyalColors.yellow.withOpacity(0.2),
                    borderRadius: BorderRadius.circular(12),
                    border: Border.all(color: AmyalColors.yellow),
                  ),
                  child: Row(children: [
                    const Icon(Icons.business, color: AmyalColors.yellowDark, size: 28),
                    const SizedBox(width: 10),
                    const Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Text('اختر نوع نشاطك التجاري', style: TextStyle(fontWeight: FontWeight.bold)),
                      Text('لرؤية الميزات المناسبة لنوع عملك', style: TextStyle(fontSize: 12)),
                    ])),
                    const Icon(Icons.arrow_back_ios, size: 16, color: AmyalColors.yellowDark),
                  ]),
                ),
              ),
            );
          }),

          // ====== شبكة الخدمات ======
          GridView.count(
            crossAxisCount: 2,
            shrinkWrap: true,
            physics: const NeverScrollableScrollPhysics(),
            mainAxisSpacing: 12,
            crossAxisSpacing: 12,
            childAspectRatio: 1.1,
            children: [
              // متاحة للجميع تقريباً
              _notificationCard(),
              AccessGate(feature: 'receive', child: _serviceCard(
                icon: Icons.qr_code_2,
                label: 'رقم حسابي',
                subtitle: 'مشاركة + نسخ',
                color: AmyalColors.primary,
                onTap: () => Get.to(() => const MyAccountNumberScreen()),
              )),
              AccessGate(feature: 'receipts', child: _serviceCard(
                icon: Icons.receipt_long,
                label: 'إيصالاتي',
                subtitle: 'سجل كامل بـ PDF',
                color: Colors.indigo,
                onTap: () => Get.to(() => const ReceiptsListScreen()),
              )),

              // ميزات المستخدم
              AccessGate(anyOf: const ['cash_out', 'wallet'], child: _serviceCard(
                icon: Icons.arrow_downward,
                label: 'سحب نقدي',
                subtitle: 'عبر الوكيل',
                color: Colors.green,
                onTap: () => Get.to(() => const WithdrawRequestScreen()),
              )),
              AccessGate(feature: 'payment_requests', child: _serviceCard(
                icon: Icons.request_quote,
                label: 'طلب أموال',
                subtitle: 'QR أو رابط',
                color: AmyalColors.yellowDark,
                onTap: () => Get.to(() => const PaymentRequestCreateScreen()),
              )),
              AccessGate(feature: 'safe_pay', child: _serviceCard(
                icon: Icons.shield,
                label: 'الدفع الآمن',
                subtitle: 'حماية للبيع/الشراء',
                color: Colors.green.shade700,
                onTap: () => Get.to(() => const MySafePaymentsScreen()),
              )),
              AccessGate(feature: 'family_fund', child: _serviceCard(
                icon: Icons.savings,
                label: 'الصناديق المشتركة',
                subtitle: 'صندوق عائلي',
                color: Colors.deepPurple,
                onTap: () => Get.to(() => const MyFundsScreen()),
              )),
              AccessGate(feature: 'bill_pay', child: _serviceCard(
                icon: Icons.flash_on,
                label: 'الفواتير',
                subtitle: 'كهرباء، اتصالات...',
                color: Colors.amber.shade800,
                onTap: () => Get.to(() => const BillPayProvidersScreen()),
              )),

              // ميزات التاجر
              AccessGate(feature: 'merchant_verification', child: _serviceCard(
                icon: Icons.verified_user,
                label: 'توثيق المتجر',
                subtitle: 'KYC + شارة',
                color: Colors.teal,
                onTap: () => Get.to(() => const MerchantVerificationScreen()),
              )),
              AccessGate(feature: 'fuel_pos', child: _serviceCard(
                icon: Icons.local_gas_station,
                label: 'محطة وقود',
                subtitle: 'كاشير متخصّص',
                color: const Color(0xFF1B5E20),
                onTap: () => Get.to(() => const FuelStationDashboardScreen()),
              )),
              AccessGate(feature: 'pharmacy_pos', child: _serviceCard(
                icon: Icons.local_pharmacy,
                label: 'الصيدلية',
                subtitle: 'بيع + Batches',
                color: const Color(0xFF7B1FA2),
                onTap: () => Get.to(() => const PharmacyDashboardScreen()),
              )),
              AccessGate(feature: 'wholesale_invoices', child: _serviceCard(
                icon: Icons.warehouse,
                label: 'الجملة',
                subtitle: 'فواتير + ائتمان',
                color: const Color(0xFFE65100),
                onTap: () => Get.to(() => const WholesaleDashboardScreen()),
              )),
              // CRITICAL-001-PLANS — متاحة لكل التجار
              _serviceCard(
                icon: Icons.workspace_premium,
                label: 'خطّتي',
                subtitle: 'عرض الخطط',
                color: const Color(0xFFEAB308),
                onTap: () => Get.to(() => const PlansCatalogScreen()),
              ),
              _serviceCard(
                icon: Icons.bar_chart,
                label: 'استخدامي',
                subtitle: 'الحدود + العدّاد',
                color: const Color(0xFF0EA5E9),
                onTap: () => Get.to(() => const MyUsageScreen()),
              ),
              // P1-BRANCHES — متاحة لـ PRO + ENTERPRISE
              AccessGate(feature: 'branches', child: _serviceCard(
                icon: Icons.store_mall_directory,
                label: 'الفروع',
                subtitle: 'إدارة + تقارير',
                color: const Color(0xFF7C3AED),
                onTap: () => Get.to(() => const BranchesManagementScreen()),
              )),

              // ثابت
              _serviceCard(
                icon: Icons.help_outline,
                label: 'المساعدة',
                subtitle: 'الأسئلة الشائعة',
                color: Colors.grey.shade700,
                onTap: () => _comingSoon('المساعدة'),
              ),
            ],
          ),
        ],
      ),
    );
  }

  Widget _serviceCard({
    required IconData icon,
    required String label,
    required String subtitle,
    required Color color,
    required VoidCallback onTap,
  }) {
    return InkWell(
      borderRadius: BorderRadius.circular(14),
      onTap: onTap,
      child: Container(
        padding: const EdgeInsets.all(14),
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(14),
        ),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Container(
              width: 48, height: 48,
              decoration: BoxDecoration(
                color: color.withOpacity(0.15),
                borderRadius: BorderRadius.circular(12),
              ),
              child: Icon(icon, color: color, size: 24),
            ),
            const SizedBox(height: 10),
            Text(label, style: const TextStyle(fontSize: 14, fontWeight: FontWeight.bold)),
            const SizedBox(height: 2),
            Text(subtitle, style: TextStyle(fontSize: 11, color: Colors.grey.shade600)),
          ],
        ),
      ),
    );
  }

  Widget _notificationCard() {
    return InkWell(
      borderRadius: BorderRadius.circular(14),
      onTap: () async {
        await Get.to(() => const NotificationsCenterScreen());
        notif.refreshUnreadCount();
      },
      child: Container(
        padding: const EdgeInsets.all(14),
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(14),
        ),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Stack(alignment: Alignment.center, children: [
              Container(
                width: 48, height: 48,
                decoration: BoxDecoration(
                  color: AmyalColors.yellow.withOpacity(0.15),
                  borderRadius: BorderRadius.circular(12),
                ),
                child: const Icon(Icons.notifications_outlined, color: AmyalColors.yellowDark, size: 24),
              ),
              Obx(() {
                final n = notif.unreadCount.value;
                if (n <= 0) return const SizedBox.shrink();
                return Positioned(
                  top: 2, right: 2,
                  child: Container(
                    padding: const EdgeInsets.symmetric(horizontal: 5, vertical: 1),
                    constraints: const BoxConstraints(minWidth: 16, minHeight: 16),
                    decoration: BoxDecoration(
                      color: AmyalColors.red,
                      borderRadius: BorderRadius.circular(10),
                      border: Border.all(color: Colors.white, width: 1.5),
                    ),
                    child: Text(
                      n > 99 ? '99+' : '$n',
                      style: const TextStyle(color: Colors.white, fontSize: 9, fontWeight: FontWeight.bold),
                      textAlign: TextAlign.center,
                    ),
                  ),
                );
              }),
            ]),
            const SizedBox(height: 10),
            const Text('الإشعارات', style: TextStyle(fontSize: 14, fontWeight: FontWeight.bold)),
            const SizedBox(height: 2),
            Obx(() => Text(
              notif.unreadCount.value > 0 ? '${notif.unreadCount.value} غير مقروء' : 'لا جديد',
              style: TextStyle(fontSize: 11, color: Colors.grey.shade600),
            )),
          ],
        ),
      ),
    );
  }

  void _comingSoon(String name) {
    Get.snackbar('قريباً', '$name سيتاح قريباً',
        backgroundColor: AmyalColors.yellow.withOpacity(0.2),
        snackPosition: SnackPosition.BOTTOM);
  }
}
