import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amial_pay/common/widgets/amial_form.dart';
import 'package:amial_pay/common/widgets/amial_ltr_number.dart';
import 'package:amial_pay/features/access/controllers/access_controller.dart';
import 'package:amial_pay/features/access/widgets/access_gate.dart';
import 'package:amial_pay/features/credit/screens/my_credits_screen.dart';
import 'package:amial_pay/features/me/domain/me_repo.dart';
import 'package:amial_pay/features/me/screens/my_account_number_screen.dart';
import 'package:amial_pay/features/merchant/screens/merchant_services_hub_screen.dart';
import 'package:amial_pay/features/merchant/screens/merchant_pos_home_screen.dart';
import 'package:amial_pay/features/notification/controllers/notifications_center_controller.dart';
import 'package:amial_pay/features/notification/screens/notifications_center_screen.dart';
import 'package:amial_pay/features/receipts/screens/receipts_list_screen.dart';
import 'package:amial_pay/features/reports/screens/amial_account_statement_screen.dart';
import 'package:amial_pay/features/requested_money/screens/incoming_requests_screen.dart';
import 'package:amial_pay/features/requested_money/screens/payment_request_create_screen.dart';
import 'package:amial_pay/features/setting/screens/support_screen.dart';
import 'package:amial_pay/features/withdraw/screens/withdraw_request_screen.dart';
import 'package:amial_pay/shared/widgets/verified_badge.dart';
import 'package:amial_pay/theme/amial_colors.dart';

/// خدمات العميل اليومية فقط. الميزات التخصصية لا تُعرض هنا حتى لا تتحول
/// الصفحة إلى كتالوج طويل أو إلى أزرار بلا رحلة مكتملة.
class MyServicesScreen extends StatefulWidget {
  const MyServicesScreen({super.key});

  @override
  State<MyServicesScreen> createState() => _MyServicesScreenState();
}

class _MyServicesScreenState extends State<MyServicesScreen> {
  late final MeController me;
  late final NotificationsCenterController notifications;
  late final bool _merchantAtEntry;

  @override
  void initState() {
    super.initState();
    // لا نطلب بيانات المحفظة الشخصية أصلاً عند دخول تاجر من route قديم.
    // الفصل هنا قبل أي API، وليس فقط في build بعد أن تكون البيانات حُمّلت.
    _merchantAtEntry = Get.find<AccessController>().isMerchantSession;
    if (_merchantAtEntry) return;
    me = Get.find<MeController>();
    notifications = Get.find<NotificationsCenterController>();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (me.me.value == null) me.load();
      notifications.refreshUnreadCount();
    });
  }

  @override
  Widget build(BuildContext context) {
    // هذا آخر خط دفاع، لا مجرد إخفاء بطاقات. قد يصل التاجر إلى هذه الشاشة
    // من رابط قديم أو من نافذةٍ بقيت في stack بعد تغيير الدور؛ عندئذٍ لا
    // يجوز عرض رقم محفظته الشخصية أو السحب/كشف الحساب الخاص بالعميل.
    final access = Get.find<AccessController>();
    if (access.isPos) {
      return const MerchantPosHomeScreen();
    }
    if (_merchantAtEntry || access.isMerchantSession) {
      return const MerchantServicesHubScreen();
    }
    return Scaffold(
      backgroundColor: AmialColors.background,
      body: SafeArea(
        child: Column(
          children: [
            const AmialScreenHeader(title: 'خدماتي'),
            Expanded(
              child: ListView(
                padding: const EdgeInsets.fromLTRB(16, 0, 16, 16),
                children: [
                  _accountCard(),
                  const SizedBox(height: 24),
                  const Text(
                    'الخدمات اليومية',
                    textAlign: TextAlign.right,
                    style: TextStyle(fontSize: 16, fontWeight: FontWeight.w800),
                  ),
                  const SizedBox(height: 12),
                  Obx(_servicesGrid),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _accountCard() {
    return Obx(() {
      final data = me.me.value;
      final account = data?['account_number']?.toString() ?? '...';
      final verification = (data?['verification'] ?? {}) as Map;
      final tier = verification['tier']?.toString();

      return InkWell(
        onTap: () => Get.to(() => const MyAccountNumberScreen()),
        borderRadius: BorderRadius.circular(16),
        child: Container(
          padding: const EdgeInsets.all(20),
          decoration: BoxDecoration(
            gradient: const LinearGradient(
              colors: [AmialColors.primary, AmialColors.primaryDark],
            ),
            borderRadius: BorderRadius.circular(16),
          ),
          child: Row(
            children: [
              Container(
                width: 50,
                height: 50,
                decoration: BoxDecoration(
                  color: AmialColors.yellow,
                  borderRadius: BorderRadius.circular(12),
                ),
                child: const Icon(
                  Icons.account_balance_wallet_rounded,
                  color: Colors.black87,
                ),
              ),
              const SizedBox(width: 16),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        const Text('رقم حسابي',
                            style: TextStyle(color: Colors.white70)),
                        if (tier != null) ...[
                          const SizedBox(width: 8),
                          VerifiedBadge(tier: tier, size: VerifiedBadgeSize.small),
                        ],
                      ],
                    ),
                    const SizedBox(height: 5),
                    AmialLtrNumber(
                      account,
                      textAlign: TextAlign.start,
                      style: const TextStyle(
                        color: Colors.white,
                        fontSize: 24,
                        fontWeight: FontWeight.w800,
                        letterSpacing: 2,
                      ),
                    ),
                  ],
                ),
              ),
              const Icon(Icons.chevron_left, color: Colors.white70),
            ],
          ),
        ),
      );
    });
  }

  Widget _servicesGrid() {
    final access = Get.find<AccessController>();
    final cards = <Widget>[
      _notificationCard(),
      _serviceCard(
        icon: Icons.qr_code_2,
        label: 'رقم حسابي',
        subtitle: 'نسخ ومشاركة',
        onTap: () => Get.to(() => const MyAccountNumberScreen()),
      ),
      _serviceCard(
        icon: Icons.receipt_long,
        label: 'إيصالاتي',
        subtitle: 'سجل العمليات',
        onTap: () => Get.to(() => const ReceiptsListScreen()),
      ),
      if (access.hasAny(const ['cash_out', 'wallet']))
        _serviceCard(
          icon: Icons.arrow_downward,
          label: 'سحب نقدي',
          subtitle: 'عبر الوكيل',
          onTap: () => Get.to(() => const WithdrawRequestScreen()),
        ),
      if (access.has('payment_requests'))
        _serviceCard(
          icon: Icons.request_quote,
          label: 'طلب أموال',
          subtitle: 'من شخص آخر',
          onTap: () => Get.to(() => const PaymentRequestCreateScreen()),
        ),
      if (access.has('payment_requests'))
        _serviceCard(
          icon: Icons.inbox,
          label: 'طلبات واردة',
          subtitle: 'وافق أو ارفض',
          onTap: () => Get.to(() => const IncomingRequestsScreen()),
        ),
      _serviceCard(
        icon: Icons.account_balance_wallet_outlined,
        label: 'كشف حساب',
        subtitle: 'مدين ودائن ورصيد',
        onTap: () => Get.to(() => const AmialAccountStatementScreen()),
      ),
      _serviceCard(
        icon: Icons.receipt_long_outlined,
        label: 'فواتيري الآجلة',
        subtitle: 'ما عليك من دين',
        onTap: () => Get.to(() => const MyCreditsScreen()),
      ),
      _serviceCard(
        icon: Icons.support_agent_outlined,
        label: 'الدعم والمساعدة',
        subtitle: 'تواصل معنا',
        onTap: () => Get.to(() => const SupportScreen()),
      ),
    ];

    return Column(
      children: [
        if (access.needsBusinessTypeSelection)
          Padding(
            padding: const EdgeInsets.only(bottom: 12),
            child: InkWell(
              onTap: () => Get.to(() => const BusinessTypeSelectionScreen()),
              borderRadius: BorderRadius.circular(12),
              child: Container(
                padding: const EdgeInsets.all(12),
                decoration: BoxDecoration(
                  color: AmialColors.warningSurface,
                  borderRadius: BorderRadius.circular(12),
                  border: Border.all(color: AmialColors.yellow),
                ),
                child: const Row(
                  children: [
                    Icon(Icons.business_rounded, color: AmialColors.yellowDark),
                    SizedBox(width: 10),
                    Expanded(
                      child: Text(
                        'اختر نوع نشاطك لرؤية إعدادات نشاطك.',
                        style: TextStyle(fontWeight: FontWeight.w700),
                      ),
                    ),
                    Icon(Icons.chevron_left_rounded),
                  ],
                ),
              ),
            ),
          ),
        GridView.count(
          crossAxisCount: 2,
          shrinkWrap: true,
          physics: const NeverScrollableScrollPhysics(),
          mainAxisSpacing: 12,
          crossAxisSpacing: 12,
          childAspectRatio: 1.1,
          children: cards,
        ),
      ],
    );
  }

  Widget _serviceCard({
    required IconData icon,
    required String label,
    required String subtitle,
    required VoidCallback onTap,
  }) {
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(14),
      child: Container(
        padding: const EdgeInsets.all(14),
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(14),
          border: Border.all(color: AmialColors.border),
        ),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Container(
              width: 48,
              height: 48,
              decoration: BoxDecoration(
                color: AmialColors.primary.withValues(alpha: 0.09),
                borderRadius: BorderRadius.circular(14),
              ),
              child: Icon(icon, color: AmialColors.primary, size: 24),
            ),
            const SizedBox(height: 10),
            Text(label,
                textAlign: TextAlign.center,
                style: const TextStyle(fontSize: 14, fontWeight: FontWeight.bold)),
            const SizedBox(height: 2),
            Text(
              subtitle,
              textAlign: TextAlign.center,
              style: const TextStyle(
                fontSize: 11,
                color: AmialColors.textSecondary,
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _notificationCard() {
    return InkWell(
      onTap: () async {
        await Get.to(() => const NotificationsCenterScreen());
        notifications.refreshUnreadCount();
      },
      borderRadius: BorderRadius.circular(14),
      child: Container(
        padding: const EdgeInsets.all(14),
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(14),
          border: Border.all(color: AmialColors.border),
        ),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Container(
              width: 48,
              height: 48,
              decoration: BoxDecoration(
                color: AmialColors.yellow.withValues(alpha: 0.16),
                borderRadius: BorderRadius.circular(14),
              ),
              child: const Icon(
                Icons.notifications_outlined,
                color: AmialColors.yellowDark,
                size: 24,
              ),
            ),
            const SizedBox(height: 10),
            const Text('الإشعارات',
                style: TextStyle(fontSize: 14, fontWeight: FontWeight.bold)),
            const SizedBox(height: 2),
            Obx(() {
              final count = notifications.unreadCount.value;
              return Text(
                count > 0 ? count.toString() + ' غير مقروء' : 'لا جديد',
                style: const TextStyle(
                  fontSize: 11,
                  color: AmialColors.textSecondary,
                ),
              );
            }),
          ],
        ),
      ),
    );
  }
}
