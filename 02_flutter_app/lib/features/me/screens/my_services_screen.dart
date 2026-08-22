import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amial_pay/theme/amial_colors.dart';
import 'package:amial_pay/features/withdraw/screens/withdraw_request_screen.dart';
import 'package:amial_pay/common/widgets/amial_ltr_number.dart';
import 'package:amial_pay/features/me/screens/my_account_number_screen.dart';
import 'package:amial_pay/features/installments/screens/my_installments_screen.dart';
import 'package:amial_pay/features/kyc_verification/screens/my_profile_changes_screen.dart';
import 'package:amial_pay/features/gift_cards/screens/my_gift_cards_screen.dart';
import 'package:amial_pay/features/notification/screens/notifications_center_screen.dart';
import 'package:amial_pay/features/notification/controllers/notifications_center_controller.dart';
import 'package:amial_pay/features/me/domain/me_repo.dart';
import 'package:amial_pay/features/requested_money/screens/payment_request_create_screen.dart';
import 'package:amial_pay/features/requested_money/screens/outgoing_requests_screen.dart';
import 'package:amial_pay/features/requested_money/screens/incoming_requests_screen.dart';
import 'package:amial_pay/features/receipts/screens/receipts_list_screen.dart';
import 'package:amial_pay/features/safe_payment/screens/my_safe_payments_screen.dart';
import 'package:amial_pay/features/merchant_verification/screens/merchant_verification_screen.dart';
import 'package:amial_pay/features/fuel_station/screens/fuel_station_dashboard_screen.dart';
import 'package:amial_pay/features/pharmacy/screens/pharmacy_dashboard_screen.dart';
import 'package:amial_pay/features/wholesale/screens/wholesale_screens.dart';
import 'package:amial_pay/features/plans/screens/my_usage_screen.dart';
import 'package:amial_pay/features/branches/screens/branches_management_screen.dart';
import 'package:amial_pay/features/merchant/screens/merchant_services_hub_screen.dart';
import 'package:amial_pay/features/printer/screens/printer_settings_screen.dart';
import 'package:amial_pay/features/merchant/screens/receipt_settings_screen.dart';
// ملاحظة: access_gate.dart يُصدّر أيضاً BusinessTypeSelectionScreen المستخدَمة
// في لافتة «اختر نوع نشاطك» — فلا يُحذف الاستيراد حتى لو لم يُستخدم AccessGate.
import 'package:amial_pay/features/access/widgets/access_gate.dart';
import 'package:amial_pay/features/access/controllers/access_controller.dart';
import 'package:amial_pay/features/family_fund/screens/my_funds_screen.dart';
import 'package:amial_pay/features/bill_pay/screens/bill_pay_providers_screen.dart';
import 'package:amial_pay/shared/widgets/verified_badge.dart';
import 'package:amial_pay/features/credit/screens/my_credits_screen.dart';
import 'package:amial_pay/features/donations/screens/donations_home_screen.dart';
import 'package:amial_pay/features/merchant/screens/split_bill_my_shares_screen.dart';
import 'package:amial_pay/features/kyc_verification/screens/kyc_verify_screen.dart';
import 'package:amial_pay/common/widgets/amial_form.dart';
import 'package:amial_pay/features/reports/screens/amial_account_statement_screen.dart';

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
    // AMIAL-DS-002: ترويسة خفيفة موحّدة بدل AppBar أزرق صلب.
    return Scaffold(
      backgroundColor: AmialColors.background,
      body: SafeArea(
        child: Column(children: [
          const AmialScreenHeader(title: 'خدماتي'),
          Expanded(
            child: ListView(
        padding: const EdgeInsets.fromLTRB(16, 0, 16, 16),
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
                    colors: [AmialColors.primary, AmialColors.primaryDark],
                    begin: Alignment.topLeft,
                    end: Alignment.bottomRight,
                  ),
                  borderRadius: BorderRadius.circular(16),
                ),
                child: Row(children: [
                  Container(
                    width: 50, height: 50,
                    decoration: BoxDecoration(
                      color: AmialColors.yellow,
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
                        AmialLtrNumber(
                          acc,
                          textAlign: TextAlign.start,
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
                    color: AmialColors.yellow.withValues(alpha: 0.2),
                    borderRadius: BorderRadius.circular(12),
                    border: Border.all(color: AmialColors.yellow),
                  ),
                  child: Row(children: [
                    const Icon(Icons.business, color: AmialColors.yellowDark, size: 28),
                    const SizedBox(width: 10),
                    const Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Text('اختر نوع نشاطك التجاري', style: TextStyle(fontWeight: FontWeight.bold)),
                      Text('لرؤية الميزات المناسبة لنوع عملك', style: TextStyle(fontSize: 12)),
                    ])),
                    const Icon(Icons.arrow_back_ios, size: 16, color: AmialColors.yellowDark),
                  ]),
                ),
              ),
            );
          }),

          // ====== شبكة الخدمات ======
          // AMIAL-UI-FIX(SERVICES-GRID): كانت البطاقات المقفلة (AccessGate)
          // تحجز خلايا فارغة في الشبكة فتظهر فجوات ضخمة. الآن نبني قائمة
          // البطاقات المرئية فقط (حسب الصلاحيات) قبل الشبكة — تخطيط متراصّ.
          Obx(() {
            final access = Get.find<AccessController>();
            // ══════════════════════════════════════════════════════════
            //  **الهويّةُ تحدّد النطاق، لا العكس** (القاعدة الثامنة).
            //
            //  كان: `hasAny(['products','inventory','fuel_pos', …])` —
            //  أي «من عنده `products` فهو تاجر». وهذا مقلوب، ونتيجتاه
            //  مقيستان:
            //
            //   • عميلٌ يُمنح `inventory` أو `daily_reports` لأيّ سبب
            //     ⇒ تنفتح له كتلةُ التاجر كاملةً.
            //   • وتاجرٌ في باقة البداية لا يملك أيّاً منها ⇒ **تختفي
            //     عنه «خطّتي» و«استخدامي»** — فمن يحتاج الترقيةَ أكثرَ
            //     من غيره لا يرى زرَّها.
            //
            //  و`access.isMerchant` موجودٌ أصلاً ويقرأ `role` من الخادم.
            final isMerchant = access.isMerchant;

            final cards = <Widget>[
              _notificationCard(),
              if (access.has('receive'))
                _serviceCard(icon: Icons.qr_code_2, label: 'رقم حسابي', subtitle: 'مشاركة + نسخ',
                    color: AmialColors.primary, onTap: () => Get.to(() => const MyAccountNumberScreen())),
              if (access.has('receipts'))
                _serviceCard(icon: Icons.receipt_long, label: 'إيصالاتي', subtitle: 'سجل كامل بـ PDF',
                    color: Colors.indigo, onTap: () => Get.to(() => const ReceiptsListScreen())),
              if (access.hasAny(const ['cash_out', 'wallet']))
                _serviceCard(icon: Icons.arrow_downward, label: 'سحب نقدي', subtitle: 'عبر الوكيل',
                    color: Colors.green, onTap: () => Get.to(() => const WithdrawRequestScreen())),
              // AMIAL-REQUEST-DIRECT-003 — **لا يُسأل «كيف تريد أن تطلب؟».**
              //
              // كان الزرُّ يفتح ورقةً تسأل: «من شخص برقم هاتفه» أم «رابط
              // أو رمز QR». والسؤالُ نفسُه كان العطل: يضع الرابطَ نِدّاً
              // للطريق المباشر في أوّل خطوة، ونصفُ الناس يختار الأوّلَ
              // المعروض. **وطريقان لطلبٍ واحدٍ يعني صندوقَي واردٍ لا
              // يلتقيان.**
              //
              // فصار البابُ واحداً: يُكتب الرقمُ فيظهر صاحبُه، ويُرسَل
              // الطلبُ إليه مباشرةً. والرابطُ لا يُذكر إلّا حين يتبيّن أنّ
              // الرقم ليس على أميال — استثناءٌ معلَّل لا خيارٌ أوّل.
              if (access.has('payment_requests'))
                _serviceCard(icon: Icons.request_quote, label: 'طلب أموال', subtitle: 'يصل لمن تطلب منه',
                    color: AmialColors.yellowDark,
                    onTap: () => Get.to(() => const PaymentRequestCreateScreen())),
              // AMIAL-REQUEST-DIRECT-001: الطلبات الواردة — كانت القائمة مبنيّة
              // في المتحكّم والخلفية معاً، ولا شاشة تقرؤها. فمن طُلب منه مالٌ
              // لم يكن يراه أبداً.
              if (access.has('payment_requests'))
                _serviceCard(icon: Icons.inbox, label: 'طلبات واردة', subtitle: 'وافق أو ارفض',
                    color: AmialColors.primary, onTap: () => Get.to(() => const IncomingRequestsScreen())),
              // والصادرةُ كذلك — `outgoing` كانت مبنيّةً بلا شاشة، فمن
              // أرسل طلباً لم يعرف أوُوفق عليه أم رُفض.
              if (access.has('payment_requests'))
                _serviceCard(icon: Icons.outbox, label: 'طلباتي المرسلة', subtitle: 'ما طلبتَه من غيرك',
                    color: AmialColors.primary, onTap: () => Get.to(() => const OutgoingRequestsScreen())),
              if (access.has('safe_pay'))
                _serviceCard(icon: Icons.shield, label: 'الدفع الآمن', subtitle: 'حماية للبيع/الشراء',
                    color: Colors.green.shade700, onTap: () => Get.to(() => const MySafePaymentsScreen())),
              // AMIAL-HOME-005: انتقلت هذه الثلاثة من شبكة الرئيسية (التي
              // قُلّصت إلى تسعة مدخلات) — أُضيفت هنا *قبل* إزالتها من هناك
              // حتى لا تصبح شاشة يتيمة لا يفتحها زرّ.
              // AMIAL-STATEMENT-001: كشف الحساب — بيان محاسبي لفترة، غير
              // «الإيصالات» التي هي مستندات عمليات مفردة.
              if (access.has('receipts'))
                _serviceCard(icon: Icons.account_balance_wallet_outlined,
                  label: 'كشف حساب',
                  subtitle: 'مدين ودائن ورصيد',
                  color: AmialColors.primary,
                  onTap: () => Get.to(() => const AmialAccountStatementScreen())),
              if (access.has('debts') || !isMerchant)
                _serviceCard(icon: Icons.receipt_long_outlined, label: 'فواتيري الآجلة',
                  subtitle: 'ما عليك من دين',
                  color: const Color(0xFFB45309),
                  onTap: () => Get.to(() => const MyCreditsScreen())),
              if (access.has('donations') || !isMerchant)
                _serviceCard(icon: Icons.volunteer_activism_outlined, label: 'التبرعات',
                  subtitle: 'تبرّع لجهة موثوقة',
                  color: const Color(0xFFDB2777),
                  onTap: () => Get.to(() => const DonationsHomeScreen())),
              if (!isMerchant)
                _serviceCard(icon: Icons.call_split_rounded, label: 'تقسيم فاتورة مع أصدقاء',
                  subtitle: 'حصّتي مع الأصدقاء',
                  color: const Color(0xFF0E7C7B),
                  onTap: () => Get.to(() => const SplitBillMySharesScreen())),
              // **توثيقان في شاشةٍ واحدة**: «توثيق الحساب» كان بلا سور
              // و«توثيق المتجر» مسوَّرٌ بقدرة، فيظهران معاً للتاجر فلا
              // يعرف أيَّهما يخصّه. فالشخصيُّ لغير التاجر.
              if (!isMerchant)
                Obx(() {
                  final verification = (me.me.value?['verification'] ?? {}) as Map;
                  final updateRequired = verification['update_required'] == true;
                  return _serviceCard(
                    icon: updateRequired ? Icons.warning_amber_rounded : Icons.verified_user_outlined,
                    label: updateRequired ? 'تحديث الهوية مطلوب' : 'توثيق الحساب',
                    subtitle: updateRequired
                        ? 'العمليات الحساسة مقيّدة حتى يكتمل الاعتماد'
                        : 'ارفع هويتك',
                    color: updateRequired ? const Color(0xFFD97706) : const Color(0xFF1B9E4B),
                    onTap: () => Get.to(() => const KycVerifyScreen()),
                  );
                }),
              // AMIAL-PROFILE-CHANGE-006 — **وشاشةٌ لا يُوصل إليها ليست
              // مبنيّة.** الخادمُ يفتح الطلبَ واللوحةُ تعرض الطابور،
              // وبلا هذا الرابط يبقى الطلبُ `PENDING_CUSTOMER` أبداً —
              // لأنّ العميلَ لا يراه.
              _serviceCard(icon: Icons.manage_accounts_outlined,
                  label: 'تحديث بياناتي', subtitle: 'طلباتُك وصلاحيّةُ هويّتك',
                  color: const Color(0xFF455A64),
                  onTap: () => Get.to(() => const MyProfileChangesScreen())),
              if (!isMerchant)
                _serviceCard(icon: Icons.handshake, label: 'أقساطي', subtitle: 'سداد التقسيط',
                  color: const Color(0xFF00695C), onTap: () => Get.to(() => const MyInstallmentsScreen())),
              if (!isMerchant)
                _serviceCard(icon: Icons.redeem, label: 'بطاقات هديتي', subtitle: 'رصيد المتجر',
                  color: const Color(0xFF7B1FA2), onTap: () => Get.to(() => const MyGiftCardsScreen())),
              if (access.has('family_fund'))
                _serviceCard(icon: Icons.savings, label: 'الصناديق المشتركة', subtitle: 'صندوق عائلي',
                    color: Colors.deepPurple, onTap: () => Get.to(() => const MyFundsScreen())),
              if (access.has('bill_pay'))
                _serviceCard(icon: Icons.flash_on, label: 'الفواتير', subtitle: 'كهرباء، اتصالات...',
                    color: Colors.amber.shade800, onTap: () => Get.to(() => const BillPayProvidersScreen())),
              if (isMerchant)
                _serviceCard(icon: Icons.grid_view_rounded, label: 'خدمات التاجر', subtitle: 'كل الميزات وباقاتها',
                    color: AmialColors.primary, onTap: () => Get.to(() => const MerchantServicesHubScreen())),
              if (isMerchant)
                _serviceCard(icon: Icons.storefront, label: 'إعدادات المتجر', subtitle: 'الاسم والشعار والفاتورة',
                    color: const Color(0xFF00695C), onTap: () => Get.to(() => const ReceiptSettingsScreen())),
              if (isMerchant)
                _serviceCard(icon: Icons.print, label: 'إعدادات الطابعة', subtitle: 'طابعة حرارية بلوتوث',
                    color: const Color(0xFF455A64), onTap: () => Get.to(() => const PrinterSettingsScreen())),
              if (access.has('merchant_verification'))
                _serviceCard(icon: Icons.verified_user, label: 'توثيق المتجر', subtitle: 'KYC + شارة',
                    color: Colors.teal, onTap: () => Get.to(() => const MerchantVerificationScreen())),
              if (access.has('fuel_pos'))
                _serviceCard(icon: Icons.local_gas_station, label: 'محطة وقود', subtitle: 'كاشير متخصّص',
                    color: const Color(0xFF1B5E20), onTap: () => Get.to(() => const FuelStationDashboardScreen())),
              if (access.has('pharmacy_pos'))
                _serviceCard(icon: Icons.local_pharmacy, label: 'الصيدلية', subtitle: 'بيع + Batches',
                    color: const Color(0xFF7B1FA2), onTap: () => Get.to(() => const PharmacyDashboardScreen())),
              if (access.has('wholesale_invoices'))
                _serviceCard(icon: Icons.warehouse, label: 'الجملة', subtitle: 'فواتير + ائتمان',
                    color: const Color(0xFFE65100), onTap: () => Get.to(() => const WholesaleDashboardScreen())),
              // AMIAL-FIX(PLANS-SCOPE): الباقات والاستخدام مفهوم خاصّ بالتاجر
              // (حصص المنتجات/الموظفين/الفروع). كان التعليق يقول «لكل التجار»
              // لكن بلا فحص صلاحية فعليّ — فكان المواطن العادي يراها، وهو ما
              // يوحي خطأً بأن المحفظة تتطلّب اشتراكاً. الآن مقصورة على التاجر.
              // **كانت ثلاثةَ مداخلَ للباقة نفسِها** — «خدمات التاجر» و
              // «خطّتي» و«استخدامي» ومعها زرُّ «ترقية» داخل الشاشة.
              // فصار «خدمات التاجر» بابَها، و«استخدامي» يبقى لأنّه رقمٌ
              // يوميٌّ لا صفحةَ تسويق.
              if (isMerchant)
                _serviceCard(icon: Icons.bar_chart, label: 'استخدامي', subtitle: 'الحدود + العدّاد',
                    color: const Color(0xFF0EA5E9), onTap: () => Get.to(() => const MyUsageScreen())),
              if (access.has('branches'))
                _serviceCard(icon: Icons.store_mall_directory, label: 'الفروع', subtitle: 'إدارة + تقارير',
                    color: const Color(0xFF7C3AED), onTap: () => Get.to(() => const BranchesManagementScreen())),
              _serviceCard(icon: Icons.help_outline, label: 'المساعدة', subtitle: 'الأسئلة الشائعة',
                  color: Colors.grey.shade700, onTap: () => _comingSoon('المساعدة')),
            ];

            return GridView.count(
              crossAxisCount: 2,
              shrinkWrap: true,
              physics: const NeverScrollableScrollPhysics(),
              mainAxisSpacing: 12,
              crossAxisSpacing: 12,
              childAspectRatio: 1.1,
              children: cards,
            );
          }),
        ],
            ),
          ),
        ]),
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
            // AMIAL-COLOR-003: لون واحد في الشبكة.
            // كانت كل بطاقة بلونها الخاصّ (ثمانية ألوان في شاشة واحدة) —
            // واللون في شبكة متجاورة ينافس شكل الأيقونة فيصير ضوضاء بدل أن
            // يُميّز. نُبقي معامل `color` في التوقيع كي لا تنكسر 25 نداءً،
            // ونتجاهله هنا عمداً — التمييز بالأيقونة والاسم لا باللون.
            Container(
              width: 48, height: 48,
              decoration: BoxDecoration(
                color: AmialColors.primary.withValues(alpha: 0.09),
                borderRadius: BorderRadius.circular(14),
              ),
              child: Icon(icon, color: AmialColors.primary, size: 24),
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
                  color: AmialColors.yellow.withValues(alpha: 0.15),
                  borderRadius: BorderRadius.circular(12),
                ),
                child: const Icon(Icons.notifications_outlined, color: AmialColors.yellowDark, size: 24),
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
                      color: AmialColors.red,
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
        backgroundColor: AmialColors.yellow.withValues(alpha: 0.2),
        snackPosition: SnackPosition.BOTTOM);
  }
}
