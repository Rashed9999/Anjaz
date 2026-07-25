import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/data/api/api_client.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';
import 'package:amyal_pay/util/app_constants.dart';
import 'package:amyal_pay/features/requested_money/screens/payment_request_create_screen.dart';
import 'package:amyal_pay/features/bill_pay/screens/bill_pay_providers_screen.dart';
import 'package:amyal_pay/features/withdraw/screens/withdraw_request_screen.dart';
import 'package:amyal_pay/features/credit/screens/my_credits_screen.dart';
import 'package:amyal_pay/features/safe_payment/screens/my_safe_payments_screen.dart';
import 'package:amyal_pay/features/family_fund/screens/my_funds_screen.dart';
import 'package:amyal_pay/features/donations/screens/donations_home_screen.dart';
import 'package:amyal_pay/features/receipts/screens/receipts_list_screen.dart';
import 'package:amyal_pay/features/notification/screens/notifications_center_screen.dart';
import 'package:amyal_pay/features/notification/controllers/notifications_center_controller.dart';
import 'package:amyal_pay/features/setting/screens/profile_screen.dart';
import 'package:amyal_pay/features/plans/screens/plans_catalog_screen.dart';
import 'package:amyal_pay/features/kyc_verification/screens/kyc_verify_screen.dart';
import 'package:amyal_pay/features/setting/screens/qr_code_download_or_share_screen.dart';
import 'package:amyal_pay/features/requested_money/screens/requested_money_list_screen.dart';
import 'package:amyal_pay/features/me/screens/my_services_screen.dart';
import 'package:amyal_pay/features/transaction_money/controllers/contact_controller.dart';
import 'package:amyal_pay/features/transaction_money/screens/amial_send_money_screen.dart';
import 'package:amyal_pay/features/reports/screens/amial_reports_screen.dart';
import 'package:amyal_pay/features/merchant/screens/split_bill_my_shares_screen.dart';
import 'package:amyal_pay/features/merchant/screens/merchant_pay_screen.dart';
import 'package:amyal_pay/features/access/controllers/access_controller.dart';

/// AMIAL-CUSTOMER-HOME-002 — الرئيسية بتصميم «المحفظة».
///
/// إعادة تصميم كاملة بلغة بصرية حديثة (مستوحاة من تصاميم المحافظ العالمية)
/// بهوية أميال (أزرق #053391 + أصفر #FECA1E):
///   • محفظة بطبقات وعمق: بطاقة تُطلّ من جيب المحفظة + الرصيد + استلام QR.
///   • «تحويل سريع»: المحوَّل لهم مؤخراً بنقرة واحدة (يعبّئ الرقم تلقائياً).
///   • «آخر العمليات»: بطاقات أفقية أنيقة (±مبلغ ملوّن).
///   • شبكة الخدمات كاملة كما هي (كل الوظائف محفوظة).
class AmialCustomerHomeScreen extends StatefulWidget {
  const AmialCustomerHomeScreen({super.key});

  @override
  State<AmialCustomerHomeScreen> createState() =>
      _AmialCustomerHomeScreenState();
}

class _AmialCustomerHomeScreenState extends State<AmialCustomerHomeScreen> {
  String _name = '';
  String _balance = '0';
  String _qrCode = ''; // SVG رمز العميل لاستقبال المال
  String _phone = '';
  bool _hideBalance = false;
  bool _loading = true;
  List<Map<String, dynamic>> _recent = [];

  @override
  void initState() {
    super.initState();
    _load();
    // «تحويل سريع» + عدّاد الإشعارات — دفاعياً
    try {
      Get.find<ContactController>().getSuggestList(type: AppConstants.sendMoney);
    } catch (_) {}
    try {
      Get.find<NotificationsCenterController>().refreshUnreadCount();
    } catch (_) {}
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
        _qrCode = (b['qr_code'] ?? '').toString();
        _phone = (b['phone'] ?? '').toString();
      }
    } catch (_) {/* دفاعي: نُبقي الواجهة نظيفة */}

    try {
      // «الإيصالات» = السجلّ الموحّد لكل النشاط (تحويلات + خدمات أميال)
      final api = Get.find<ApiClient>();
      final r = await api.getData('/api/v1/amial/receipts');
      if (r.statusCode == 200 && r.body is Map) {
        final meta = (r.body as Map)['meta'];
        final list = meta is Map ? meta['items'] : null;
        if (list is List) {
          _recent = list
              .whereType<Map>()
              .map((e) => Map<String, dynamic>.from(e))
              .take(6)
              .toList();
        }
      }
    } catch (_) {/* دفاعي */}

    if (mounted) setState(() => _loading = false);
  }

  void _openReceiveQr() {
    if (_qrCode.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('جارٍ تجهيز رمز الاستلام...')),
      );
      _load();
      return;
    }
    Get.to(() => QrCodeDownloadOrShareScreen(qrCode: _qrCode, phoneNumber: _phone));
  }

  String get _balanceText {
    if (_hideBalance) return '••••••';
    final parts = _balance.split('.');
    final intPart = parts.first.replaceAllMapped(
      RegExp(r'\B(?=(\d{3})+(?!\d))'),
      (m) => ',',
    );
    return intPart;
  }

  /// رقم مقنَّع للبطاقة المُطلّة (آخر 4 أرقام).
  String get _maskedPhone {
    final digits = _phone.replaceAll(RegExp(r'[^0-9]'), '');
    if (digits.length < 4) return '•••• ••••';
    return '•••• •••• ${digits.substring(digits.length - 4)}';
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmyalColors.background,
      body: RefreshIndicator(
        color: AmyalColors.primary,
        onRefresh: _load,
        child: ListView(
          padding: EdgeInsets.zero,
          children: [
            _header(),
            Padding(
              // AMIAL-FIX(HOME-BOTTOM): كانت الحشوة 24 فقط فيغطّي شريط التنقّل
              // والزرّ العائم آخر صفّ من أيقونات الخدمات. الشريط يحتاج ~110.
              padding: const EdgeInsets.fromLTRB(16, 4, 16, 110),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  _walletHero(),
                  const SizedBox(height: 22),
                  _quickSendSection(),
                  const SizedBox(height: 22),
                  _recentSection(),
                  const SizedBox(height: 22),
                  _sectionHeader('خدمات أميال',
                      trailing: '', onTrailing: null),
                  const SizedBox(height: 12),
                  _servicesGrid(),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }

  // ============ Header — أزرار دائرية بيضاء + عنوان مركزي ============
  Widget _header() {
    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 54, 16, 14),
      child: Row(
        children: [
          _circleBtn(
            child: const Icon(Icons.person_outline_rounded,
                color: AmyalColors.primary, size: 24),
            onTap: () => Get.to(() => const ProfileScreen()),
          ),
          Expanded(
            child: Column(
              children: [
                const Text('أميال باي',
                    style: TextStyle(
                        fontSize: 18,
                        fontWeight: FontWeight.bold,
                        color: Color(0xFF1A2433))),
                Text(
                  _name.isEmpty ? 'محفظتي وعملياتي' : 'أهلاً، $_name',
                  style: const TextStyle(
                      fontSize: 12, color: AmyalColors.textSecondary),
                ),
              ],
            ),
          ),
          _circleBtn(
            child: Obx(() {
              int unread = 0;
              try {
                unread = Get.find<NotificationsCenterController>().unreadCount.value;
              } catch (_) {}
              return Stack(clipBehavior: Clip.none, children: [
                const Icon(Icons.notifications_none_rounded,
                    color: AmyalColors.primary, size: 24),
                if (unread > 0)
                  Positioned(
                    top: -2, left: -2,
                    child: Container(
                      width: 9, height: 9,
                      decoration: const BoxDecoration(
                          color: AmyalColors.red, shape: BoxShape.circle),
                    ),
                  ),
              ]);
            }),
            onTap: () => Get.to(() => const NotificationsCenterScreen()),
          ),
        ],
      ),
    );
  }

  Widget _circleBtn({required Widget child, required VoidCallback onTap}) {
    return InkWell(
      onTap: onTap,
      customBorder: const CircleBorder(),
      child: Container(
        width: 46, height: 46,
        decoration: BoxDecoration(
          color: Colors.white,
          shape: BoxShape.circle,
          boxShadow: [
            BoxShadow(
                color: Colors.black.withValues(alpha: 0.06),
                blurRadius: 10, offset: const Offset(0, 3)),
          ],
        ),
        child: Center(child: child),
      ),
    );
  }

  // ============ محفظة بطبقات (بطاقة تُطلّ من الجيب) ============
  Widget _walletHero() {
    return Stack(
      clipBehavior: Clip.none,
      children: [
        // البطاقة المُطلّة من أعلى المحفظة
        Positioned(
          top: 0, left: 22, right: 22,
          child: Container(
            height: 96,
            padding: const EdgeInsets.fromLTRB(16, 10, 16, 0),
            decoration: BoxDecoration(
              gradient: const LinearGradient(
                colors: [Color(0xFF1D4FB8), Color(0xFF3B6FD8)],
                begin: Alignment.topRight, end: Alignment.bottomLeft,
              ),
              borderRadius: BorderRadius.circular(18),
            ),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(_name.isEmpty ? 'عميل أميال' : _name,
                          maxLines: 1, overflow: TextOverflow.ellipsis,
                          style: const TextStyle(
                              color: Colors.white,
                              fontSize: 13,
                              fontWeight: FontWeight.w600)),
                      const SizedBox(height: 2),
                      Text(_maskedPhone,
                          textDirection: TextDirection.ltr,
                          style: const TextStyle(
                              color: Colors.white70, fontSize: 11)),
                    ],
                  ),
                ),
                const Text('AMYAL',
                    style: TextStyle(
                        color: AmyalColors.yellow,
                        fontSize: 13,
                        fontWeight: FontWeight.bold,
                        letterSpacing: 2)),
              ],
            ),
          ),
        ),

        // جيب المحفظة الأمامي
        Container(
          margin: const EdgeInsets.only(top: 54),
          padding: const EdgeInsets.fromLTRB(20, 22, 20, 18),
          decoration: BoxDecoration(
            gradient: const LinearGradient(
              colors: [Color(0xFF053391), Color(0xFF021F5C)],
              begin: Alignment.topRight, end: Alignment.bottomLeft,
            ),
            borderRadius: BorderRadius.circular(26),
            boxShadow: [
              BoxShadow(
                color: AmyalColors.primary.withValues(alpha: 0.35),
                blurRadius: 22, offset: const Offset(0, 10),
              ),
            ],
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const Text('الرصيد الكلي',
                  style: TextStyle(color: Colors.white70, fontSize: 13)),
              const SizedBox(height: 6),
              Row(
                crossAxisAlignment: CrossAxisAlignment.baseline,
                textBaseline: TextBaseline.alphabetic,
                children: [
                  Text(
                    _loading ? '...' : _balanceText,
                    style: const TextStyle(
                        color: Colors.white,
                        fontSize: 34,
                        fontWeight: FontWeight.bold),
                  ),
                  const SizedBox(width: 6),
                  const Text('ر.ي',
                      style: TextStyle(
                          color: AmyalColors.yellow,
                          fontSize: 15,
                          fontWeight: FontWeight.w700)),
                ],
              ),
              const SizedBox(height: 16),
              Row(
                children: [
                  // زرّ الاستلام (QR) — الكبسة الأساسية
                  Expanded(
                    child: InkWell(
                      onTap: _openReceiveQr,
                      borderRadius: BorderRadius.circular(24),
                      child: Container(
                        padding: const EdgeInsets.symmetric(vertical: 11),
                        decoration: BoxDecoration(
                          color: AmyalColors.yellow,
                          borderRadius: BorderRadius.circular(24),
                        ),
                        child: const Row(
                          mainAxisAlignment: MainAxisAlignment.center,
                          children: [
                            Icon(Icons.qr_code_2_rounded,
                                size: 18, color: Color(0xFF053391)),
                            SizedBox(width: 6),
                            Text('استلام الأموال',
                                style: TextStyle(
                                    color: Color(0xFF053391),
                                    fontWeight: FontWeight.bold,
                                    fontSize: 13.5)),
                          ],
                        ),
                      ),
                    ),
                  ),
                  const SizedBox(width: 10),
                  _walletCircle(
                    icon: _hideBalance
                        ? Icons.visibility_off_outlined
                        : Icons.visibility_outlined,
                    onTap: () => setState(() => _hideBalance = !_hideBalance),
                  ),
                ],
              ),

              // AMIAL-HOME-003: صفّ الإجراءات السريعة داخل البطاقة (كما في
              // مراجع المحافظ): أيقونة دائرية + تسمية أسفلها، بدل زرّين فقط.
              const SizedBox(height: 18),
              Divider(color: Colors.white.withValues(alpha: 0.18), height: 1),
              const SizedBox(height: 14),
              Row(
                mainAxisAlignment: MainAxisAlignment.spaceAround,
                children: [
                  _walletAction(Icons.north_east_rounded, 'إرسال',
                      () => Get.to(() => const AmialSendMoneyScreen())),
                  _walletAction(Icons.request_page_outlined, 'طلب',
                      () => Get.to(() => const PaymentRequestCreateScreen())),
                  _walletAction(Icons.storefront_outlined, 'ادفع',
                      () => Get.to(() => const MerchantPayScreen())),
                  _walletAction(Icons.account_balance_outlined, 'سحب',
                      () => Get.to(() => const WithdrawRequestScreen())),
                  _walletAction(Icons.receipt_long_outlined, 'السجل',
                      () => Get.to(() => const ReceiptsListScreen())),
                ],
              ),
            ],
          ),
        ),
      ],
    );
  }

  /// إجراء سريع داخل بطاقة الرصيد: دائرة شفافة + تسمية.
  Widget _walletAction(IconData icon, String label, VoidCallback onTap) {
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(14),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 2, vertical: 2),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Container(
              width: 42,
              height: 42,
              decoration: BoxDecoration(
                color: Colors.white.withValues(alpha: 0.16),
                shape: BoxShape.circle,
                border: Border.all(color: Colors.white.withValues(alpha: 0.22)),
              ),
              child: Icon(icon, color: Colors.white, size: 19),
            ),
            const SizedBox(height: 6),
            Text(label,
                style: const TextStyle(
                    color: Colors.white70,
                    fontSize: 10.5,
                    fontWeight: FontWeight.w600)),
          ],
        ),
      ),
    );
  }

  Widget _walletCircle({required IconData icon, required VoidCallback onTap}) {
    return InkWell(
      onTap: onTap,
      customBorder: const CircleBorder(),
      child: Container(
        width: 42, height: 42,
        decoration: BoxDecoration(
          color: Colors.white.withValues(alpha: 0.14),
          shape: BoxShape.circle,
          border: Border.all(color: Colors.white.withValues(alpha: 0.25)),
        ),
        child: Icon(icon, color: Colors.white, size: 19),
      ),
    );
  }

  // ============ تحويل سريع (المحوَّل لهم مؤخراً) ============
  static const List<Color> _avatarPalette = [
    Color(0xFF1D4FB8), Color(0xFF12694E), Color(0xFFB8860B),
    Color(0xFF7B1FA2), Color(0xFFC0392B), Color(0xFF00695C),
  ];

  Widget _quickSendSection() {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        _sectionHeader('تحويل سريع',
            trailing: 'المزيد',
            onTrailing: () => Get.to(() => const AmialSendMoneyScreen())),
        const SizedBox(height: 12),
        SizedBox(
          height: 86,
          child: GetBuilder<ContactController>(builder: (c) {
            List recent = const [];
            try {
              recent = c.sendMoneySuggestList;
            } catch (_) {}
            return ListView(
              scrollDirection: Axis.horizontal,
              children: [
                // زرّ إضافة (دائرة متقطّعة) — يفتح الإرسال
                _quickSendAdd(),
                const SizedBox(width: 14),
                ...recent.take(8).toList().asMap().entries.map((e) {
                  final m = e.value;
                  final name = ((m.name ?? '') as String).trim();
                  final phone = (m.phoneNumber ?? '') as String;
                  final color =
                      _avatarPalette[e.key % _avatarPalette.length];
                  return Padding(
                    padding: const EdgeInsets.only(left: 14),
                    child: InkWell(
                      onTap: () => Get.to(() =>
                          AmialSendMoneyScreen(initialPhone: phone)),
                      borderRadius: BorderRadius.circular(14),
                      child: Column(
                        children: [
                          Container(
                            width: 54, height: 54,
                            decoration: BoxDecoration(
                              color: color.withValues(alpha: 0.12),
                              shape: BoxShape.circle,
                              border: Border.all(
                                  color: color.withValues(alpha: 0.35)),
                            ),
                            child: Center(
                              child: Text(
                                name.isNotEmpty ? name[0] : '؟',
                                style: TextStyle(
                                    color: color,
                                    fontSize: 20,
                                    fontWeight: FontWeight.bold),
                              ),
                            ),
                          ),
                          const SizedBox(height: 6),
                          SizedBox(
                            width: 58,
                            child: Text(
                              name.isEmpty ? 'مستلِم' : name,
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                              textAlign: TextAlign.center,
                              style: const TextStyle(fontSize: 11.5),
                            ),
                          ),
                        ],
                      ),
                    ),
                  );
                }),
              ],
            );
          }),
        ),
      ],
    );
  }

  Widget _quickSendAdd() {
    return InkWell(
      onTap: () => Get.to(() => const AmialSendMoneyScreen()),
      borderRadius: BorderRadius.circular(14),
      child: Column(
        children: [
          Container(
            width: 54, height: 54,
            decoration: BoxDecoration(
              shape: BoxShape.circle,
              color: Colors.white,
              border: Border.all(
                  color: AmyalColors.primary.withValues(alpha: 0.45)),
            ),
            child: const Icon(Icons.add_rounded,
                color: AmyalColors.primary, size: 26),
          ),
          const SizedBox(height: 6),
          const Text('إرسال',
              style: TextStyle(fontSize: 11.5, fontWeight: FontWeight.w600)),
        ],
      ),
    );
  }

  // ============ آخر العمليات — بطاقات أفقية ============
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

  static const Map<String, IconData> _receiptIcons = {
    'send_money': Icons.arrow_outward_rounded,
    'received_money': Icons.call_received_rounded,
    'cash_out': Icons.local_atm_rounded,
    'cash_in': Icons.savings_outlined,
    'add_money': Icons.add_card_rounded,
    'withdraw': Icons.account_balance_outlined,
    'pay_merchant': Icons.storefront_outlined,
    'pos_payment': Icons.point_of_sale_rounded,
    'qr_payment': Icons.qr_code_2_rounded,
    'refund': Icons.replay_rounded,
    'donation': Icons.volunteer_activism_outlined,
    'bill_payment': Icons.receipt_long_rounded,
  };

  Widget _recentSection() {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        _sectionHeader('آخر العمليات',
            trailing: 'عرض الكل',
            onTrailing: () => Get.to(() => const ReceiptsListScreen())),
        const SizedBox(height: 12),
        if (_loading)
          const Padding(
            padding: EdgeInsets.symmetric(vertical: 24),
            child: Center(
                child: CircularProgressIndicator(color: AmyalColors.primary)),
          )
        else if (_recent.isEmpty)
          Container(
            padding: const EdgeInsets.symmetric(vertical: 28),
            alignment: Alignment.center,
            decoration: BoxDecoration(
              color: Colors.white,
              borderRadius: BorderRadius.circular(18),
            ),
            child: const Column(children: [
              Icon(Icons.receipt_long_outlined,
                  size: 34, color: AmyalColors.textMuted),
              SizedBox(height: 8),
              Text('لا توجد عمليات بعد',
                  style: TextStyle(color: AmyalColors.textSecondary)),
            ]),
          )
        else
          // AMIAL-HOME-004: قائمة عمليات غنيّة داخل بطاقة بيضاء (كما في مراجع
          // المحافظ): أيقونة ملوّنة + العنوان + سطر وصف + المبلغ + التاريخ.
          // كانت بطاقات أفقية تُقصّ عند الحافة فلا يُقرأ نصفها.
          Container(
            decoration: BoxDecoration(
              color: Colors.white,
              borderRadius: BorderRadius.circular(20),
              boxShadow: [
                BoxShadow(
                    color: const Color(0xFF1A2433).withValues(alpha: 0.05),
                    blurRadius: 18,
                    offset: const Offset(0, 6)),
              ],
            ),
            child: Column(
              children: List.generate(
                _recent.length > 5 ? 5 : _recent.length,
                (i) => _recentRow(_recent[i], isLast: i == (_recent.length > 5 ? 4 : _recent.length - 1)),
              ),
            ),
          ),
      ],
    );
  }

  /// صفّ عملية غنيّ — أيقونة + عنوان + وصف + مبلغ + تاريخ.
  Widget _recentRow(Map<String, dynamic> t, {required bool isLast}) {
    final type = (t['receipt_type'] ?? '').toString();
    final title = _receiptLabels[type] ?? (type.isEmpty ? 'عملية' : type);
    final icon = _receiptIcons[type] ?? Icons.swap_horiz_rounded;
    final amountRaw = (t['amount'] ?? '').toString();
    final amount = amountRaw.contains('.')
        ? amountRaw.replaceAll(RegExp(r'\.?0+$'), '')
        : amountRaw;
    final isDebit = (t['direction'] ?? '').toString() == 'debit';
    final rawDate = (t['issued_at'] ?? t['created_at'] ?? '').toString();
    final dt = DateTime.tryParse(rawDate);
    String two(int n) => n.toString().padLeft(2, '0');
    final dateText = dt == null
        ? (rawDate.length >= 10 ? rawDate.substring(0, 10) : rawDate)
        : '${dt.year}/${two(dt.month)}/${two(dt.day)}  •  ${two(dt.hour)}:${two(dt.minute)}';
    final ref = (t['transaction_no'] ?? t['receipt_number'] ?? '').toString();
    final tone = isDebit ? const Color(0xFFDC2626) : const Color(0xFF16A34A);

    return InkWell(
      onTap: () => Get.to(() => const ReceiptsListScreen()),
      borderRadius: BorderRadius.circular(20),
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 13),
        decoration: BoxDecoration(
          border: isLast
              ? null
              : const Border(
                  bottom: BorderSide(color: Color(0xFFF1F3F6), width: 1)),
        ),
        child: Row(
          children: [
            Container(
              width: 44,
              height: 44,
              decoration: BoxDecoration(
                color: tone.withValues(alpha: 0.10),
                borderRadius: BorderRadius.circular(14),
              ),
              child: Icon(icon, color: tone, size: 21),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(title,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(
                          fontSize: 13.5,
                          fontWeight: FontWeight.w700,
                          color: Color(0xFF1A2433))),
                  const SizedBox(height: 3),
                  Text(
                    ref.isNotEmpty ? ref : dateText,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(
                        fontSize: 10.5, color: AmyalColors.textMuted),
                  ),
                ],
              ),
            ),
            const SizedBox(width: 8),
            Column(
              crossAxisAlignment: CrossAxisAlignment.end,
              children: [
                Text(
                  '${isDebit ? '-' : '+'}$amount ر.ي',
                  style: TextStyle(
                      fontSize: 13.5, fontWeight: FontWeight.bold, color: tone),
                ),
                const SizedBox(height: 3),
                Text(dateText.split('  •  ').first,
                    style: const TextStyle(
                        fontSize: 10, color: AmyalColors.textMuted)),
              ],
            ),
          ],
        ),
      ),
    );
  }

  // ============ شبكة الخدمات (كل الوظائف محفوظة) ============
  Widget _servicesGrid() {
    return Obx(() => _buildServicesGrid());
  }

  Widget _buildServicesGrid() {
    // AMIAL-FIX(PLANS-SCOPE): «الباقات» مفهوم اشتراك خاصّ بالتاجر (حصص
    // منتجات/موظفين/فروع). كانت تُعرض لكل مستخدم بلا شرط — فيظنّ المواطن
    // العادي أن المحفظة تتطلّب اشتراكاً مدفوعاً. تقتصر الآن على التاجر.
    bool isMerchant = false;
    try {
      isMerchant = Get.find<AccessController>().hasAny(const [
        'products', 'inventory', 'fuel_pos', 'pharmacy_pos',
        'wholesale_invoices', 'daily_reports', 'profit_reports',
      ]);
    } catch (_) {}

    final services = [
      _Svc('طلب المال', Icons.request_page_outlined, () => Get.to(() => const PaymentRequestCreateScreen())),
      _Svc('الطلبات الواردة', Icons.mark_email_unread_outlined, () => Get.to(() => const RequestedMoneyListScreen(requestType: RequestType.request))),
      _Svc('ادفع لتاجر', Icons.storefront_outlined, () => Get.to(() => const MerchantPayScreen())),
      _Svc('دفع الفواتير', Icons.flash_on_rounded, () => Get.to(() => const BillPayProvidersScreen())),
      _Svc('الدفع الآمن', Icons.shield_outlined, () => Get.to(() => const MySafePaymentsScreen())),
      _Svc('صندوق العائلة', Icons.groups_outlined, () => Get.to(() => const MyFundsScreen())),
      _Svc('التبرعات', Icons.volunteer_activism_outlined, () => Get.to(() => const DonationsHomeScreen())),
      _Svc('تقسيم الفواتير', Icons.call_split_rounded, () => Get.to(() => const SplitBillMySharesScreen())),
      _Svc('طلب سحب', Icons.account_balance_outlined, () => Get.to(() => const WithdrawRequestScreen())),
      _Svc('فواتيري الآجلة', Icons.receipt_long_outlined, () => Get.to(() => const MyCreditsScreen())),
      _Svc('الإيصالات', Icons.description_outlined, () => Get.to(() => const ReceiptsListScreen())),
      _Svc('التقارير', Icons.bar_chart_rounded, () => Get.to(() => const AmialReportsScreen()),
          color: AmyalColors.red),
      _Svc('توثيق الحساب', Icons.verified_user_outlined, () => Get.to(() => const KycVerifyScreen())),
      if (isMerchant)
        _Svc('الباقات', Icons.workspace_premium_outlined, () => Get.to(() => const PlansCatalogScreen())),
      _Svc('خدماتي', Icons.apps_rounded, () => Get.to(() => const MyServicesScreen())),
      _Svc('حسابي', Icons.person_outline_rounded, () => Get.to(() => const ProfileScreen())),
    ];
    // AMIAL-HOME-003: الخدمات داخل بطاقة بيضاء واحدة، وكل أيقونة في مربّع
    // باستيل بلونها الخاصّ — كما في مراجع المحافظ الاحترافية. كانت 16 مربّعاً
    // أبيض عائماً بلون أزرق واحد، فتبدو مبعثرة وبلا هرمية بصرية.
    const palette = <Color>[
      Color(0xFF053391), Color(0xFF1B9E4B), Color(0xFFE08A00), Color(0xFF7C3AED),
      Color(0xFF0EA5E9), Color(0xFFDB2777), Color(0xFF0E7C7B), Color(0xFFB45309),
    ];

    return Container(
      padding: const EdgeInsets.fromLTRB(12, 18, 12, 14),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(22),
        boxShadow: [
          BoxShadow(
              color: const Color(0xFF1A2433).withValues(alpha: 0.05),
              blurRadius: 18,
              offset: const Offset(0, 6)),
        ],
      ),
      child: GridView.count(
        crossAxisCount: 4,
        shrinkWrap: true,
        physics: const NeverScrollableScrollPhysics(),
        mainAxisSpacing: 16,
        crossAxisSpacing: 8,
        childAspectRatio: 0.80,
        children: List.generate(services.length, (i) {
          final s = services[i];
          final color = s.color ?? palette[i % palette.length];
          return InkWell(
            onTap: s.onTap,
            borderRadius: BorderRadius.circular(16),
            child: Column(
              children: [
                Container(
                  height: 50,
                  width: 50,
                  decoration: BoxDecoration(
                    // خلفية باستيل من لون الخدمة نفسها
                    color: color.withValues(alpha: 0.11),
                    borderRadius: BorderRadius.circular(16),
                  ),
                  child: Icon(s.icon, color: color, size: 23),
                ),
                const SizedBox(height: 7),
                Expanded(
                  child: Text(s.label,
                      textAlign: TextAlign.center,
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(
                          fontSize: 10.5,
                          height: 1.25,
                          fontWeight: FontWeight.w500,
                          color: Color(0xFF2A3B33))),
                ),
              ],
            ),
          );
        }),
      ),
    );
  }

  // ============ رأس قسم موحّد ============
  Widget _sectionHeader(String title,
      {required String trailing, VoidCallback? onTrailing}) {
    return Row(
      children: [
        Text(title,
            style: const TextStyle(
                fontSize: 16,
                fontWeight: FontWeight.bold,
                color: Color(0xFF1A2433))),
        const Spacer(),
        if (trailing.isNotEmpty)
          InkWell(
            onTap: onTrailing,
            child: Text(trailing,
                style: const TextStyle(
                    color: AmyalColors.primary,
                    fontWeight: FontWeight.w600,
                    fontSize: 12.5)),
          ),
      ],
    );
  }
}

class _Svc {
  final String label;
  final IconData icon;
  final VoidCallback onTap;
  final Color? color;
  _Svc(this.label, this.icon, this.onTap, {this.color});
}
