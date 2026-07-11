import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/data/api/api_client.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';
import 'package:amyal_pay/features/transaction_money/screens/transaction_money_screen.dart';
import 'package:amyal_pay/features/bill_pay/screens/bill_pay_providers_screen.dart';
import 'package:amyal_pay/features/withdraw/screens/withdraw_request_screen.dart';
import 'package:amyal_pay/features/safe_payment/screens/my_safe_payments_screen.dart';
import 'package:amyal_pay/features/family_fund/screens/my_funds_screen.dart';
import 'package:amyal_pay/features/donations/screens/donations_home_screen.dart';
import 'package:amyal_pay/features/receipts/screens/receipts_list_screen.dart';
import 'package:amyal_pay/features/notification/screens/notifications_center_screen.dart';
import 'package:amyal_pay/features/setting/screens/profile_screen.dart';
import 'package:amyal_pay/features/plans/screens/plans_catalog_screen.dart';
import 'package:amyal_pay/features/kyc_verification/screens/kyc_verify_screen.dart';

/// AMIAL-CUSTOMER-HOME-001
///
/// الشاشة الرئيسية للعميل بهوية «أميال باي» (أخضر + ذهبي) — تعرض الرصيد،
/// إجراءات سريعة، وشبكة خدمات أميال (الدفع الآمن، صندوق العائلة، الفواتير،
/// التبرعات…). تجلب الرصيد من /api/v1/customer/get-customer دفاعياً (لا
/// تُظهر «Server Error»؛ عند الخطأ تبقى واجهة نظيفة).
class AmialCustomerHomeScreen extends StatefulWidget {
  const AmialCustomerHomeScreen({super.key});

  @override
  State<AmialCustomerHomeScreen> createState() =>
      _AmialCustomerHomeScreenState();
}

class _AmialCustomerHomeScreenState extends State<AmialCustomerHomeScreen> {
  String _name = '';
  String _balance = '0';
  bool _hideBalance = false;
  bool _loading = true;
  List<Map<String, dynamic>> _recent = [];

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    if (mounted) setState(() => _loading = true);
    try {
      final api = Get.find<ApiClient>();
      final r = await api.getData('/api/v1/customer/get-customer');
      if (r.statusCode == 200 && r.body is Map) {
        final b = r.body as Map;
        final fn = (b['f_name'] ?? '').toString();
        final ln = (b['l_name'] ?? '').toString();
        _name = ('$fn $ln').trim();
        _balance = (b['balance'] ?? '0').toString();
      }
    } catch (_) {/* دفاعي: نُبقي الواجهة نظيفة */}

    try {
      // AMIAL-UNIFY: «الإيصالات» هي السجلّ الموحّد لكل النشاط (تحويلات 6cash
      // + خدمات أميال: مساهمات/دفع آمن/تبرعات…) لأنّ كلّها تُصدر Receipt.
      final api = Get.find<ApiClient>();
      final r = await api.getData('/api/v1/amial/receipts');
      if (r.statusCode == 200 && r.body is Map) {
        final meta = (r.body as Map)['meta'];
        final list = meta is Map ? meta['items'] : null;
        if (list is List) {
          _recent = list
              .whereType<Map>()
              .map((e) => Map<String, dynamic>.from(e))
              .take(5)
              .toList();
        }
      }
    } catch (_) {/* دفاعي */}

    if (mounted) setState(() => _loading = false);
  }

  String get _balanceText {
    if (_hideBalance) return '••••••';
    // تنسيق بفواصل آلاف بسيط
    final parts = _balance.split('.');
    final intPart = parts.first.replaceAllMapped(
      RegExp(r'\B(?=(\d{3})+(?!\d))'),
      (m) => ',',
    );
    return intPart;
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: const Color(0xFFF5F4EF),
      body: RefreshIndicator(
        color: AmyalColors.primary,
        onRefresh: _load,
        child: ListView(
          padding: EdgeInsets.zero,
          children: [
            _header(),
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 0, 16, 24),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  _balanceCard(),
                  const SizedBox(height: 20),
                  _quickActions(),
                  const SizedBox(height: 24),
                  _sectionTitle('خدمات أميال'),
                  const SizedBox(height: 12),
                  _servicesGrid(),
                  const SizedBox(height: 24),
                  _recentSection(),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }

  // ============ Header ============
  Widget _header() {
    return Container(
      padding: const EdgeInsets.fromLTRB(16, 52, 16, 16),
      color: Colors.transparent,
      child: Row(
        children: [
          IconButton(
            onPressed: () => Get.to(() => const NotificationsCenterScreen()),
            icon: const Icon(Icons.notifications_none_rounded, size: 28),
            color: AmyalColors.primary,
          ),
          const Spacer(),
          Column(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              Text(
                _name.isEmpty ? 'أهلاً بك' : 'أهلاً، $_name',
                style: const TextStyle(
                    fontSize: 13, color: Color(0xFF5F6B62)),
              ),
              const Text(
                'أميال باي',
                style: TextStyle(
                    fontSize: 18,
                    fontWeight: FontWeight.bold,
                    color: AmyalColors.primary),
              ),
            ],
          ),
          const SizedBox(width: 12),
          GestureDetector(
            onTap: () => Get.to(() => const ProfileScreen()),
            child: CircleAvatar(
              radius: 24,
              backgroundColor: AmyalColors.primary,
              child: const Icon(Icons.person, color: Colors.white),
            ),
          ),
        ],
      ),
    );
  }

  // ============ Balance card ============
  Widget _balanceCard() {
    return Container(
      padding: const EdgeInsets.all(22),
      decoration: BoxDecoration(
        gradient: const LinearGradient(
          colors: [Color(0xFF1B4A38), Color(0xFF0E241C)],
          begin: Alignment.topRight,
          end: Alignment.bottomLeft,
        ),
        borderRadius: BorderRadius.circular(22),
        boxShadow: [
          BoxShadow(
            color: AmyalColors.primary.withValues(alpha: 0.25),
            blurRadius: 18,
            offset: const Offset(0, 8),
          ),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              InkWell(
                onTap: () => setState(() => _hideBalance = !_hideBalance),
                child: Icon(
                  _hideBalance
                      ? Icons.visibility_off_outlined
                      : Icons.visibility_outlined,
                  color: Colors.white70,
                  size: 22,
                ),
              ),
              const Spacer(),
              const Text(
                'الرصيد الحالي',
                style: TextStyle(color: Colors.white70, fontSize: 13),
              ),
            ],
          ),
          const SizedBox(height: 14),
          Row(
            mainAxisAlignment: MainAxisAlignment.end,
            crossAxisAlignment: CrossAxisAlignment.baseline,
            textBaseline: TextBaseline.alphabetic,
            children: [
              Text(
                _loading ? '...' : _balanceText,
                style: const TextStyle(
                    color: Colors.white,
                    fontSize: 30,
                    fontWeight: FontWeight.bold),
              ),
              const SizedBox(width: 8),
              const Padding(
                padding: EdgeInsets.only(bottom: 4),
                child: Text('YER',
                    style: TextStyle(
                        color: Color(0xFFE6B84C),
                        fontSize: 14,
                        fontWeight: FontWeight.w600)),
              ),
            ],
          ),
          const SizedBox(height: 16),
          Row(
            mainAxisAlignment: MainAxisAlignment.end,
            children: [
              _badge('حساب موثّق', Icons.verified_outlined),
            ],
          ),
        ],
      ),
    );
  }

  Widget _badge(String text, IconData icon) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
      decoration: BoxDecoration(
        color: Colors.white.withValues(alpha: 0.12),
        borderRadius: BorderRadius.circular(20),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Text(text,
              style: const TextStyle(color: Colors.white, fontSize: 12)),
          const SizedBox(width: 4),
          Icon(icon, color: const Color(0xFFE6B84C), size: 16),
        ],
      ),
    );
  }

  // ============ Quick actions ============
  Widget _quickActions() {
    final items = [
      // مهم: TransactionMoneyScreen يتطلّب transactionType (وإلّا widget.transactionType!.tr تنهار)
      _Qa('إرسال', Icons.send_rounded, () => Get.to(() => const TransactionMoneyScreen(fromEdit: false, transactionType: 'send_money'))),
      _Qa('سحب نقدي', Icons.account_balance_wallet_outlined, () => Get.to(() => const TransactionMoneyScreen(fromEdit: false, transactionType: 'cash_out'))),
      _Qa('الفواتير', Icons.receipt_long_rounded, () => Get.to(() => const BillPayProvidersScreen())),
      _Qa('السجل', Icons.history_rounded, () => Get.to(() => const ReceiptsListScreen())),
    ];
    return Row(
      mainAxisAlignment: MainAxisAlignment.spaceBetween,
      children: items.map((q) {
        return Expanded(
          child: InkWell(
            onTap: q.onTap,
            borderRadius: BorderRadius.circular(16),
            child: Column(
              children: [
                Container(
                  height: 56,
                  width: 56,
                  decoration: BoxDecoration(
                    color: Colors.white,
                    borderRadius: BorderRadius.circular(16),
                    boxShadow: [
                      BoxShadow(
                        color: Colors.black.withValues(alpha: 0.05),
                        blurRadius: 8,
                        offset: const Offset(0, 3),
                      ),
                    ],
                  ),
                  child: Icon(q.icon, color: AmyalColors.primary, size: 26),
                ),
                const SizedBox(height: 8),
                Text(q.label,
                    style: const TextStyle(
                        fontSize: 12, color: Color(0xFF2A3B33))),
              ],
            ),
          ),
        );
      }).toList(),
    );
  }

  // ============ Services grid ============
  Widget _servicesGrid() {
    final services = [
      _Svc('الدفع الآمن', Icons.shield_outlined, () => Get.to(() => const MySafePaymentsScreen())),
      _Svc('صندوق العائلة', Icons.groups_outlined, () => Get.to(() => const MyFundsScreen())),
      _Svc('التبرعات', Icons.volunteer_activism_outlined, () => Get.to(() => const DonationsHomeScreen())),
      _Svc('طلب سحب', Icons.account_balance_outlined, () => Get.to(() => const WithdrawRequestScreen())),
      _Svc('الإيصالات', Icons.description_outlined, () => Get.to(() => const ReceiptsListScreen())),
      _Svc('توثيق الحساب', Icons.verified_user_outlined, () => Get.to(() => const KycVerifyScreen())),
      _Svc('الباقات', Icons.workspace_premium_outlined, () => Get.to(() => const PlansCatalogScreen())),
      _Svc('حسابي', Icons.person_outline_rounded, () => Get.to(() => const ProfileScreen())),
    ];
    return GridView.count(
      crossAxisCount: 3,
      shrinkWrap: true,
      physics: const NeverScrollableScrollPhysics(),
      mainAxisSpacing: 12,
      crossAxisSpacing: 12,
      childAspectRatio: 0.95,
      children: services.map((s) {
        return InkWell(
          onTap: s.onTap,
          borderRadius: BorderRadius.circular(18),
          child: Container(
            decoration: BoxDecoration(
              color: Colors.white,
              borderRadius: BorderRadius.circular(18),
              boxShadow: [
                BoxShadow(
                  color: Colors.black.withValues(alpha: 0.04),
                  blurRadius: 8,
                  offset: const Offset(0, 3),
                ),
              ],
            ),
            child: Column(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                Container(
                  height: 46,
                  width: 46,
                  decoration: BoxDecoration(
                    color: AmyalColors.primary.withValues(alpha: 0.08),
                    borderRadius: BorderRadius.circular(14),
                  ),
                  child: Icon(s.icon, color: AmyalColors.primary, size: 24),
                ),
                const SizedBox(height: 10),
                Text(s.label,
                    textAlign: TextAlign.center,
                    style: const TextStyle(
                        fontSize: 12, color: Color(0xFF2A3B33))),
              ],
            ),
          ),
        );
      }).toList(),
    );
  }

  // ============ Recent ============
  Widget _recentSection() {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Row(
          children: [
            InkWell(
              onTap: () => Get.to(() => const ReceiptsListScreen()),
              child: const Text('عرض الكل',
                  style: TextStyle(
                      color: AmyalColors.primary,
                      fontWeight: FontWeight.w600,
                      fontSize: 13)),
            ),
            const Spacer(),
            _sectionTitle('العمليات الأخيرة'),
          ],
        ),
        const SizedBox(height: 12),
        if (_loading)
          const Padding(
            padding: EdgeInsets.symmetric(vertical: 24),
            child: Center(child: CircularProgressIndicator()),
          )
        else if (_recent.isEmpty)
          Container(
            padding: const EdgeInsets.symmetric(vertical: 28),
            alignment: Alignment.center,
            decoration: BoxDecoration(
              color: Colors.white,
              borderRadius: BorderRadius.circular(16),
            ),
            child: const Text('لا توجد عمليات بعد',
                style: TextStyle(color: Color(0xFF8B97A8))),
          )
        else
          ..._recent.map(_recentTile),
      ],
    );
  }

  // AMIAL-UNIFY: تسمية عربية لأنواع الإيصالات (كل النشاط الموحّد)
  static const Map<String, String> _receiptLabels = {
    'send_money': 'تحويل أموال',
    'received_money': 'مبلغ مستلَم',
    'cash_out': 'سحب نقدي',
    'cash_in': 'إيداع نقدي',
    'add_money': 'إضافة رصيد',
    'withdraw': 'طلب سحب',
    'pay_merchant': 'دفع لتاجر',
    'pos_payment': 'دفع نقطة بيع',
    'qr_payment': 'دفع QR',
    'refund': 'استرجاع',
    'safe_payment_funded': 'دفع آمن (حجز)',
    'safe_payment_released': 'دفع آمن (تحرير)',
    'safe_payment_refunded': 'دفع آمن (استرجاع)',
    'family_fund_contribute': 'مساهمة عائلية',
    'donation': 'تبرع',
    'bill_payment': 'دفع فاتورة',
  };

  Widget _recentTile(Map<String, dynamic> t) {
    final type = (t['receipt_type'] ?? '').toString();
    final title = _receiptLabels[type] ?? (type.isEmpty ? 'عملية' : type);
    final amountRaw = (t['amount'] ?? '').toString();
    final amount = amountRaw.contains('.')
        ? amountRaw.replaceAll(RegExp(r'\.?0+$'), '')
        : amountRaw;
    final direction = (t['direction'] ?? '').toString(); // debit / credit
    final isDebit = direction == 'debit';
    final date = (t['issued_at'] ?? t['created_at'] ?? '').toString();
    // نعرض التاريخ فقط (بدون الوقت) إن كان طويلاً
    final shortDate = date.length >= 10 ? date.substring(0, 10) : date;
    return Container(
      margin: const EdgeInsets.only(bottom: 10),
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(16),
      ),
      child: Row(
        children: [
          Container(
            height: 42,
            width: 42,
            decoration: BoxDecoration(
              color: (isDebit ? const Color(0xFFDC0A0B) : AmyalColors.primary)
                  .withValues(alpha: 0.08),
              borderRadius: BorderRadius.circular(12),
            ),
            child: Icon(
              isDebit ? Icons.arrow_upward_rounded : Icons.arrow_downward_rounded,
              color: isDebit ? const Color(0xFFDC0A0B) : AmyalColors.primary,
              size: 20,
            ),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(title,
                    style: const TextStyle(
                        fontWeight: FontWeight.w600, fontSize: 14)),
                if (shortDate.isNotEmpty)
                  Text(shortDate,
                      style: const TextStyle(
                          color: Color(0xFF8B97A8), fontSize: 11)),
              ],
            ),
          ),
          if (amount.isNotEmpty)
            Text('${isDebit ? '-' : '+'}$amount ر.ي',
                style: TextStyle(
                    fontWeight: FontWeight.bold,
                    fontSize: 13,
                    color: isDebit
                        ? const Color(0xFFDC0A0B)
                        : const Color(0xFF12694E))),
        ],
      ),
    );
  }

  Widget _sectionTitle(String t) {
    return Text(t,
        textAlign: TextAlign.right,
        style: const TextStyle(
            fontSize: 16,
            fontWeight: FontWeight.bold,
            color: Color(0xFF1A2433)));
  }
}

class _Qa {
  final String label;
  final IconData icon;
  final VoidCallback onTap;
  _Qa(this.label, this.icon, this.onTap);
}

class _Svc {
  final String label;
  final IconData icon;
  final VoidCallback onTap;
  _Svc(this.label, this.icon, this.onTap);
}
