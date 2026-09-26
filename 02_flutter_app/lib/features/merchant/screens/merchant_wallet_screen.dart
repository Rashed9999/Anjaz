import 'package:flutter/material.dart';
import 'package:get/get.dart';

import 'package:amial_pay/features/access/controllers/access_controller.dart';
import 'package:amial_pay/features/merchant/controllers/merchant_controller.dart';
import 'package:amial_pay/features/merchant/screens/merchant_transactions_screen.dart';
import 'package:amial_pay/features/transaction_money/screens/amial_send_money_screen.dart';
import 'package:amial_pay/features/withdraw/screens/withdraw_request_screen.dart';
import 'package:amial_pay/helper/amial_money.dart';
import 'package:amial_pay/theme/amial_colors.dart';
import 'package:amial_pay/util/app_direction.dart';

/// ══════════════════════════════════════════════════════════════════════
/// AMIAL-MERCHANT-WALLET-001 — **محفظةُ المتجر. ولم تكن هناك محفظة.**
///
/// **الطلب بنصّه:** «المفترض لديه المحفظة يرى المال الموجود فيه من
/// عمليّات البيع، يستطيع سحبَه تحويلَه فقط».
///
/// وقِيست الشيفرةُ فما كان فيها شاشةُ محفظةٍ لتاجر — بل أربعُ قطعٍ
/// متفرّقة، **ولكلٍّ اسمٌ آخر**:
///
///   الرصيد   بطاقةٌ في ترويسة لوحة التاجر، لا تُضغط
///   الحركات  `MerchantTransactionsScreen` عنوانُها «المبيعات والعمليات»
///            واسمُها في «خدماتي» **«حركات المتجر»** — ثلاثةُ أسماءٍ لشيء
///   السحب    رابطٌ في أسفل اللوحة، مبنيٌّ ويعمل
///   التحويل  **غيرُ موصولٍ لحساب تاجرٍ إطلاقاً** — `AmialSendMoneyScreen`
///            تُفتح من رئيسيّة العميل في ستّة مواضع، ولا موضعَ من شاشة تاجر
///
/// فالمالُ كان يُرى مبعثراً، ويُسحَب مدفوناً، **ولا يُحوَّل**.
///
/// ══════════════════════════════════════════════════════════════════════
/// **ولا تُحسب هنا حقيقةٌ ماليّة.** الرصيدُ والإحصاءاتُ من
/// `merchant/daily-stats`، والحركاتُ من سجلّ المعاملات — والمحرّكُ
/// الماليُّ هو المصدر. هذه الشاشةُ **تقرأ وتوجّه**، ولا تجمع رقماً من
/// أرقامٍ ولا تطرح. (`amial-financial-truth`: مصدرُ الحقيقة واحد.)
///
/// **والرصيدُ الغائبُ يُقال غيابُه.** الخادمُ يحذف `current_balance` عن
/// موظّف نقطة البيع (`AMIAL-POS-SCOPE-001`)، والنموذجُ يجعله `null` لا
/// صفراً — **وصفرٌ هنا يُقرأ «متجرٌ خاوٍ»**، وهو كذب. (القاعدة السابعة.)
///
/// يظهر في : التطبيق ← لوحة التاجر ← «محفظة المتجر» · والدرجُ ←
///           «محفظة المتجر» · ورئيسيّةُ التجزئة ← بطاقةُ الرصيد.
/// ويُوصل إليه من : ثلاثةُ مداخلَ أعلاه، ولمالك المتجر وحدَه.
/// ══════════════════════════════════════════════════════════════════════
class MerchantWalletScreen extends StatefulWidget {
  const MerchantWalletScreen({super.key});

  @override
  State<MerchantWalletScreen> createState() => _MerchantWalletScreenState();
}

class _MerchantWalletScreenState extends State<MerchantWalletScreen> {
  MerchantController get _ctrl => Get.find<MerchantController>();
  AccessController get _access => Get.find<AccessController>();

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _refresh());
  }

  Future<void> _refresh() async {
    await _ctrl.loadProfile();
    await _ctrl.loadTransactions();
  }

  @override
  Widget build(BuildContext context) {
    return Directionality(
      textDirection: appTextDirection(),
      child: Scaffold(
        backgroundColor: AmialColors.background,
        appBar: AppBar(title: const Text('محفظة المتجر')),
        body: RefreshIndicator(
          onRefresh: _refresh,
          color: AmialColors.primary,
          child: Obx(() {
            final stats = _ctrl.stats.value;
            final txns = _ctrl.transactions;

            return ListView(
              padding: const EdgeInsets.all(16),
              children: [
                _BalanceCard(
                  balance: stats.balance,
                  ownerOnly: !_access.isMerchantOwner,
                ),
                const SizedBox(height: 14),

                // ══════════════════════════════════════════════════════
                // **بابان اثنان لا أكثر — كما طُلب حرفاً: «سحبه تحويله
                // فقط».** ولا إيداعَ هنا: مالُ المتجر يدخل بالبيع، لا
                // بشحنٍ يدويّ. وبابٌ ثالثٌ يُضاف يوماً يُبرَّر يومَها.
                // ══════════════════════════════════════════════════════
                Row(children: [
                  Expanded(
                    child: _ActionCard(
                      icon: Icons.account_balance_outlined,
                      label: 'سحب',
                      subtitle: 'نقداً عبر وكيل',
                      onTap: () => Get.to(() => const WithdrawRequestScreen()),
                    ),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: _ActionCard(
                      icon: Icons.send_rounded,
                      label: 'تحويل',
                      subtitle: 'إلى حساب أميال باي',
                      // **ورصيدُ المتجر يُمرَّر معها** — شاشةُ التحويل
                      // تقرأ رصيدَها من ملفّ العميل، وهو مسارٌ يردّ ٤٠٣
                      // لكلّ تاجر. (AMIAL-MERCHANT-SESSION-001)
                      onTap: () => Get.to(() => AmialSendMoneyScreen(
                          balanceOverride: stats.balance)),
                    ),
                  ),
                ]),

                const SizedBox(height: 20),
                const _SectionTitle('إحصاءات اليوم'),
                _StatsCard(
                  sales: stats.todaySales,
                  refunds: stats.todayRefunds,
                  net: stats.todayNet,
                  count: stats.todayTransactionsCount,
                  transfersIn: stats.todayTransfersIn,
                ),

                const SizedBox(height: 20),
                Row(children: [
                  const Expanded(child: _SectionTitle('آخر الحركات')),
                  TextButton(
                    onPressed: () =>
                        Get.to(() => const MerchantTransactionsScreen()),
                    child: const Text('عرض الكل'),
                  ),
                ]),

                if (_ctrl.isLoading.value && txns.isEmpty)
                  const Padding(
                    padding: EdgeInsets.symmetric(vertical: 30),
                    child: Center(
                        child: CircularProgressIndicator(
                            color: AmialColors.primary)),
                  )
                else if (txns.isEmpty)
                  const _EmptyMovements()
                else
                  // **والسطرُ يقول من ومتى ومن باع** — فحركةٌ بلا نسبةٍ
                  // لا تُراجَع، والمراجعةُ هي غرضُ السجلّ.
                  ...txns.take(8).map((t) => _MovementRow(
                        title: t.typeLabel,
                        subtitle: [
                          if (t.customerName != null &&
                              t.customerName!.trim().isNotEmpty)
                            t.customerName!.trim(),
                          if (t.posUserName != null &&
                              t.posUserName!.trim().isNotEmpty)
                            'كاشير: ${t.posUserName!.trim()}',
                          _shortTime(t.createdAt),
                        ].join(' · '),
                        amount: t.amount,
                        incoming: t.isIncoming,
                      )),

                const SizedBox(height: 24),
                _ScopeNote(isOwner: _access.isMerchantOwner),
                const SizedBox(height: 24),
              ],
            );
          }),
        ),
      ),
    );
  }
}

// ═══════════════════════════════════════════════════════════════════════

/// وقتٌ مختصرٌ يفهمه صاحبُ المتجر: «اليوم ١١:٤٠» لا طابعٌ زمنيٌّ كامل.
String _shortTime(DateTime t) {
  final now = DateTime.now();
  final today = DateTime(now.year, now.month, now.day);
  final day = DateTime(t.year, t.month, t.day);

  final clock = '${t.hour.toString().padLeft(2, '0')}:'
      '${t.minute.toString().padLeft(2, '0')}';

  if (day == today) return 'اليوم $clock';
  if (day == today.subtract(const Duration(days: 1))) return 'أمس $clock';

  return '${t.year}-${t.month.toString().padLeft(2, '0')}-'
      '${t.day.toString().padLeft(2, '0')} $clock';
}

class _BalanceCard extends StatelessWidget {
  const _BalanceCard({required this.balance, required this.ownerOnly});

  final String? balance;
  final bool ownerOnly;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(18),
      decoration: BoxDecoration(
        gradient: const LinearGradient(
          begin: Alignment.topRight,
          end: Alignment.bottomLeft,
          colors: [AmialColors.primaryDark, AmialColors.primary],
        ),
        borderRadius: BorderRadius.circular(14),
      ),
      child: Row(children: [
        Container(
          width: 46,
          height: 46,
          decoration: BoxDecoration(
            color: Colors.white.withValues(alpha: 0.16),
            borderRadius: BorderRadius.circular(12),
          ),
          child: const Icon(Icons.account_balance_wallet_rounded,
              color: Colors.white, size: 23),
        ),
        const SizedBox(width: 13),
        Expanded(
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            const Text('الرصيد المتاح',
                style: TextStyle(color: Colors.white70, fontSize: 12)),
            const SizedBox(height: 3),

            // **الغيابُ يُقال، ولا يُكتب صفراً.**
            if (balance != null)
              Text(AmialMoney.yer(balance!),
                  style: const TextStyle(
                      color: Colors.white,
                      fontSize: 25,
                      fontWeight: FontWeight.bold))
            else
              Text(
                ownerOnly ? 'لمالك المتجر' : 'غير متاح الآن',
                style: const TextStyle(
                    color: Colors.white70,
                    fontSize: 17,
                    fontWeight: FontWeight.bold),
              ),
          ]),
        ),
      ]),
    );
  }
}

class _ActionCard extends StatelessWidget {
  const _ActionCard({
    required this.icon,
    required this.label,
    required this.subtitle,
    required this.onTap,
  });

  final IconData icon;
  final String label;
  final String subtitle;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: AmialColors.cardSurface,
      borderRadius: BorderRadius.circular(13),
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(13),
        child: Container(
          padding: const EdgeInsets.symmetric(vertical: 16, horizontal: 12),
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(13),
            border: Border.all(color: AmialColors.border),
          ),
          child: Column(children: [
            Icon(icon, color: AmialColors.primary, size: 24),
            const SizedBox(height: 8),
            Text(label,
                style: const TextStyle(
                    fontWeight: FontWeight.bold, fontSize: 14)),
            const SizedBox(height: 2),
            Text(subtitle,
                textAlign: TextAlign.center,
                style: const TextStyle(
                    fontSize: 10.5, color: AmialColors.textMuted)),
          ]),
        ),
      ),
    );
  }
}

class _StatsCard extends StatelessWidget {
  const _StatsCard({
    required this.sales,
    required this.refunds,
    required this.net,
    required this.count,
    this.transfersIn,
  });

  final String sales;
  final String refunds;
  final String net;
  final int count;

  /// AMIAL-MERCHANT-RECEIVE-LIMIT-002 — **مالٌ وصل بلا بيع.**
  ///
  /// كان يُحتسب في «المبيعات»، فيقرأ صاحبُ المتجر يومَه على رقمٍ يحمل
  /// ما لم يُبَع. و`null` تعني «لم يُرسَل» (موظّفُ نقطة البيع) لا صفراً.
  final String? transfersIn;

  @override
  Widget build(BuildContext context) {
    return Container(
      decoration: BoxDecoration(
        color: AmialColors.cardSurface,
        borderRadius: BorderRadius.circular(13),
        border: Border.all(color: AmialColors.border),
      ),
      child: Column(children: [
        _row('المبيعات', AmialMoney.yer(sales), AmialColors.success),
        const Divider(height: 1),
        // **ولا يُعرَض صفرٌ بلا سبب**: السطرُ يظهر حين يصل مالٌ بلا بيع،
        // فيعرف صاحبُ المتجر أنّ في محفظته ما ليس من الكاشير.
        if (transfersIn != null && (double.tryParse(transfersIn!) ?? 0) > 0) ...[
          _row('تحويلات واردة (ليست مبيعات)',
              AmialMoney.yer(transfersIn!), AmialColors.primary),
          const Divider(height: 1),
        ],
        _row('الاسترجاعات', AmialMoney.yer(refunds), AmialColors.red),
        const Divider(height: 1),
        _row('الصافي', AmialMoney.yer(net), AmialColors.primary),
        const Divider(height: 1),
        _row('عدد العمليات', '$count', AmialColors.textSecondary),
      ]),
    );
  }

  Widget _row(String label, String value, Color color) {
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 13),
      child: Row(children: [
        Expanded(child: Text(label, style: const TextStyle(fontSize: 13.5))),
        Text(value,
            textDirection: TextDirection.ltr,
            style: TextStyle(
                fontSize: 13.5,
                fontWeight: FontWeight.bold,
                fontFeatures: const [FontFeature.tabularFigures()],
                color: color)),
      ]),
    );
  }
}

class _MovementRow extends StatelessWidget {
  const _MovementRow({
    required this.title,
    required this.subtitle,
    required this.amount,
    required this.incoming,
  });

  final String title;
  final String subtitle;
  final String amount;
  final bool incoming;

  @override
  Widget build(BuildContext context) {
    final color = incoming ? AmialColors.success : AmialColors.red;

    return Container(
      margin: const EdgeInsets.only(bottom: 8),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: AmialColors.cardSurface,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: AmialColors.border),
      ),
      child: Row(children: [
        Container(
          width: 34,
          height: 34,
          decoration: BoxDecoration(
            color: color.withValues(alpha: 0.1),
            borderRadius: BorderRadius.circular(10),
          ),
          child: Icon(
              incoming ? Icons.south_rounded : Icons.north_rounded,
              color: color,
              size: 17),
        ),
        const SizedBox(width: 11),
        Expanded(
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(title,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: const TextStyle(
                    fontSize: 13, fontWeight: FontWeight.w600)),
            if (subtitle.isNotEmpty)
              Text(subtitle,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(
                      fontSize: 11, color: AmialColors.textMuted)),
          ]),
        ),
        Text('${incoming ? '+' : '−'} ${AmialMoney.yer(amount)}',
            textDirection: TextDirection.ltr,
            style: TextStyle(
                fontSize: 13,
                fontWeight: FontWeight.bold,
                fontFeatures: const [FontFeature.tabularFigures()],
                color: color)),
      ]),
    );
  }
}

class _EmptyMovements extends StatelessWidget {
  const _EmptyMovements();

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(vertical: 32),
      alignment: Alignment.center,
      child: const Column(children: [
        Icon(Icons.swap_vert_rounded, size: 46, color: AmialColors.textMuted),
        SizedBox(height: 10),
        Text('لا حركةَ في المحفظة بعد',
            style: TextStyle(fontSize: 13, color: AmialColors.textSecondary)),
        SizedBox(height: 4),
        Text('أوّلُ عمليّة بيعٍ تظهر هنا',
            style: TextStyle(fontSize: 11.5, color: AmialColors.textMuted)),
      ]),
    );
  }
}

class _SectionTitle extends StatelessWidget {
  const _SectionTitle(this.text);

  final String text;

  @override
  Widget build(BuildContext context) {
    return Text(text,
        style: const TextStyle(
            fontSize: 13.5,
            fontWeight: FontWeight.bold,
            color: AmialColors.textSecondary));
  }
}

class _ScopeNote extends StatelessWidget {
  const _ScopeNote({required this.isOwner});

  final bool isOwner;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(13),
      decoration: BoxDecoration(
        color: AmialColors.cardSurface,
        borderRadius: BorderRadius.circular(12),
        border: const Border(
            right: BorderSide(color: AmialColors.yellowDark, width: 3)),
      ),
      child: Text(
        isOwner
            ? 'هذه محفظةُ متجرك: يدخلها مالُ البيع، ويخرج منها سحباً عبر '
                'وكيلٍ أو تحويلاً إلى حساب أميال باي. ولا شحنَ يدويّاً لها — '
                'رصيدُها من بيعك.'
            : 'رصيدُ المتجر لصاحبه. وما تراه هنا من حركاتٍ هو ما يخصّ عملك.',
        style: const TextStyle(
            fontSize: 11.5, height: 1.7, color: AmialColors.textMuted),
      ),
    );
  }
}