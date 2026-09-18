import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amial_pay/common/widgets/amial_form.dart';
import 'package:amial_pay/features/access/controllers/access_controller.dart';
import 'package:amial_pay/features/bill_pay/screens/bill_pay_providers_screen.dart';
import 'package:amial_pay/features/donations/screens/donations_home_screen.dart';
import 'package:amial_pay/features/family_fund/screens/my_funds_screen.dart';
import 'package:amial_pay/features/kyc_verification/screens/complete_my_account_screen.dart';
import 'package:amial_pay/features/me/domain/me_repo.dart';
import 'package:amial_pay/features/safe_payment/screens/my_safe_payments_screen.dart';
import 'package:amial_pay/theme/amial_colors.dart';

/// AMIAL-CUSTOMER-SERVICES-HUB-001
///
/// مركز واحد لخدمات العميل المالية غير اليومية.
///
/// مصدر مستويات التوثيق هو KycTierService في الخادم:
///   Tier 1: bill_pay
///   Tier 2: safe_payment + donations + family_fund
///
/// الواجهة لا تستبدل حماية الخادم؛ هي تشرحها قبل الضغط وتفتح طريق
/// استكمال التوثيق بدل أن تخفي الخدمة عن العميل.
class CustomerServicesHubScreen extends StatefulWidget {
  const CustomerServicesHubScreen({super.key});

  @override
  State<CustomerServicesHubScreen> createState() =>
      _CustomerServicesHubScreenState();
}

class _CustomerServicesHubScreenState
    extends State<CustomerServicesHubScreen> {
  late final MeController me;

  @override
  void initState() {
    super.initState();
    me = Get.find<MeController>();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (me.me.value == null) me.load();
    });
  }

  int _currentTier() {
    final data = me.me.value;
    final verification = data?['verification'];
    if (verification is! Map) return 0;
    return int.tryParse('${verification['tier'] ?? 0}') ?? 0;
  }

  Future<void> _openService(_CustomerService service) async {
    final tier = _currentTier();
    if (tier < service.requiredTier) {
      await _showVerificationRequired(service);
      return;
    }

    final access = Get.find<AccessController>();
    if (service.featureCode != null && !access.has(service.featureCode!)) {
      Get.snackbar(
        'الخدمة غير متاحة',
        'هذه الخدمة غير مفعّلة لحسابك حالياً.',
        snackPosition: SnackPosition.BOTTOM,
      );
      return;
    }

    await Get.to(service.destination);
    if (mounted) setState(() {});
  }

  Future<void> _showVerificationRequired(_CustomerService service) async {
    await Get.dialog<void>(
      AlertDialog(
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(18)),
        title: Row(
          children: [
            const Icon(Icons.verified_user_outlined,
                color: AmialColors.primary),
            const SizedBox(width: 10),
            const Expanded(
              child: Text(
                'مستوى توثيق أعلى مطلوب',
                style: TextStyle(fontSize: 17, fontWeight: FontWeight.w800),
              ),
            ),
          ],
        ),
        content: Text(
          '${service.title} تتطلب مستوى التوثيق '
          '${service.requiredTier}. مستواك الحالي هو ${_currentTier()}. '
          'أكمل التوثيق ثم عد إلى الخدمة.',
          style: const TextStyle(height: 1.6),
        ),
        actions: [
          TextButton(
            onPressed: () => Get.back(),
            child: const Text('لاحقاً'),
          ),
          FilledButton(
            onPressed: () async {
              Get.back();
              await Get.to(() =>
                  CompleteMyAccountScreen(targetTier: service.requiredTier));
              await me.load();
              if (mounted) setState(() {});
            },
            child: const Text('إكمال التوثيق'),
          ),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final services = <_CustomerService>[
      _CustomerService(
        title: 'السداد',
        subtitle: 'سداد فواتير وخدمات الاتصالات والإنترنت وغيرها',
        icon: Icons.receipt_long_outlined,
        requiredTier: 1,
        featureCode: 'bill_pay',
        destination: () => const BillPayProvidersScreen(),
      ),
      _CustomerService(
        title: 'الدفع الآمن',
        subtitle: 'حماية المشتري والبائع حتى اكتمال الاتفاق',
        icon: Icons.shield_outlined,
        requiredTier: 2,
        featureCode: 'safe_pay',
        destination: () => const MySafePaymentsScreen(),
      ),
      _CustomerService(
        title: 'التبرعات',
        subtitle: 'التبرع للجهات والحملات الموثوقة',
        icon: Icons.volunteer_activism_outlined,
        requiredTier: 2,
        destination: () => const DonationsHomeScreen(),
      ),
      _CustomerService(
        title: 'الصندوق العائلي',
        subtitle: 'ادخار ومساهمات مشتركة للعائلة',
        icon: Icons.savings_outlined,
        requiredTier: 2,
        featureCode: 'family_fund',
        destination: () => const MyFundsScreen(),
      ),
    ];

    return Scaffold(
      backgroundColor: AmialColors.background,
      body: SafeArea(
        child: Column(
          children: [
            const AmialScreenHeader(title: 'الخدمات'),
            Expanded(
              child: Obx(() {
                final tier = _currentTier();

                return RefreshIndicator(
                  onRefresh: () async {
                    await me.load();
                  },
                  color: AmialColors.primary,
                  child: ListView(
                    padding: const EdgeInsets.fromLTRB(16, 8, 16, 24),
                    children: [
                      _tierBanner(tier),
                      const SizedBox(height: 18),
                      ...services.map(
                        (service) => Padding(
                          padding: const EdgeInsets.only(bottom: 12),
                          child: _serviceCard(service, tier),
                        ),
                      ),
                    ],
                  ),
                );
              }),
            ),
          ],
        ),
      ),
    );
  }

  Widget _tierBanner(int tier) {
    return Container(
      padding: const EdgeInsets.all(15),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: AmialColors.border),
      ),
      child: Row(
        children: [
          Container(
            width: 46,
            height: 46,
            decoration: BoxDecoration(
              color: AmialColors.primary.withValues(alpha: 0.08),
              borderRadius: BorderRadius.circular(13),
            ),
            child: const Icon(Icons.verified_user_outlined,
                color: AmialColors.primary),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const Text(
                  'مستوى التوثيق الحالي',
                  style: TextStyle(
                    fontSize: 12,
                    color: AmialColors.textSecondary,
                  ),
                ),
                const SizedBox(height: 3),
                Text(
                  'المستوى $tier',
                  style: const TextStyle(
                    fontSize: 16,
                    fontWeight: FontWeight.w800,
                  ),
                ),
              ],
            ),
          ),
          const Text(
            'كل خدمة توضح مستواها المطلوب',
            style: TextStyle(
              fontSize: 10.5,
              color: AmialColors.textSecondary,
            ),
          ),
        ],
      ),
    );
  }

  Widget _serviceCard(_CustomerService service, int tier) {
    final unlocked = tier >= service.requiredTier;

    return InkWell(
      onTap: () => _openService(service),
      borderRadius: BorderRadius.circular(18),
      child: Container(
        padding: const EdgeInsets.all(16),
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(18),
          border: Border.all(
            color: unlocked
                ? AmialColors.border
                : AmialColors.yellow.withValues(alpha: 0.8),
          ),
        ),
        child: Row(
          children: [
            Container(
              width: 52,
              height: 52,
              decoration: BoxDecoration(
                color: unlocked
                    ? AmialColors.primary.withValues(alpha: 0.08)
                    : AmialColors.yellow.withValues(alpha: 0.15),
                borderRadius: BorderRadius.circular(15),
              ),
              child: Icon(
                service.icon,
                color: unlocked
                    ? AmialColors.primary
                    : AmialColors.yellowDark,
              ),
            ),
            const SizedBox(width: 13),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      Flexible(
                        child: Text(
                          service.title,
                          style: const TextStyle(
                            fontSize: 15,
                            fontWeight: FontWeight.w800,
                          ),
                        ),
                      ),
                      const SizedBox(width: 8),
                      _tierChip(service.requiredTier, unlocked),
                    ],
                  ),
                  const SizedBox(height: 5),
                  Text(
                    service.subtitle,
                    style: const TextStyle(
                      fontSize: 11.5,
                      height: 1.45,
                      color: AmialColors.textSecondary,
                    ),
                  ),
                ],
              ),
            ),
            const SizedBox(width: 8),
            Icon(
              unlocked ? Icons.chevron_left_rounded : Icons.lock_outline_rounded,
              color: unlocked
                  ? AmialColors.textSecondary
                  : AmialColors.yellowDark,
            ),
          ],
        ),
      ),
    );
  }

  Widget _tierChip(int requiredTier, bool unlocked) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
      decoration: BoxDecoration(
        color: unlocked
            ? AmialColors.primary.withValues(alpha: 0.08)
            : AmialColors.yellow.withValues(alpha: 0.16),
        borderRadius: BorderRadius.circular(20),
      ),
      child: Text(
        unlocked ? 'متاح' : 'يتطلب مستوى $requiredTier',
        style: TextStyle(
          fontSize: 10,
          fontWeight: FontWeight.w700,
          color: unlocked
              ? AmialColors.primary
              : AmialColors.yellowDark,
        ),
      ),
    );
  }
}

class _CustomerService {
  final String title;
  final String subtitle;
  final IconData icon;
  final int requiredTier;
  final String? featureCode;
  final Widget Function() destination;

  const _CustomerService({
    required this.title,
    required this.subtitle,
    required this.icon,
    required this.requiredTier,
    this.featureCode,
    required this.destination,
  });
}
