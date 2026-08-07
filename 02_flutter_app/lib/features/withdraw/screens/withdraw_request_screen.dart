import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:get/get.dart';
import 'package:amial_pay/theme/amial_colors.dart';
import 'package:amial_pay/features/setting/controllers/profile_screen_controller.dart';
import 'package:amial_pay/features/withdraw/controllers/customer_withdraw_controller.dart';
import 'package:amial_pay/features/withdraw/screens/withdraw_history_screen.dart';
import 'package:amial_pay/features/withdraw/screens/withdraw_pending_screen.dart';
import 'package:amial_pay/features/shared/widgets/amial_pin_gate.dart';
import 'package:amial_pay/features/shared/widgets/amial_numpad.dart';
import 'package:amial_pay/helper/amial_errors.dart';
import 'package:amial_pay/helper/amial_money.dart';
import 'package:amial_pay/common/widgets/amial_button.dart';
import 'package:amial_pay/common/widgets/amial_quick_amounts.dart';

/// AMIAL-CUSTOMER-WITHDRAW-001 — شاشة «سحب الأموال» (تصميم أميال):
/// بطاقة الرصيد المتاح + المبلغ + مبالغ سريعة + طريقة السحب (وكيل/بنك)
/// + ملخّص العملية + لوحة أرقام.
class WithdrawRequestScreen extends StatefulWidget {
  const WithdrawRequestScreen({super.key});

  @override
  State<WithdrawRequestScreen> createState() => _WithdrawRequestScreenState();
}

class _WithdrawRequestScreenState extends State<WithdrawRequestScreen> {
  late final CustomerWithdrawController c;
  final _amountCtrl = TextEditingController();

  /// طريقة السحب: عبر الوكيل (فوري) هي المفعّلة حالياً.
  String _method = 'agent';

  /// مبالغ سريعة (اختيار شائع).
  static const _quickAmounts = [5000, 10000, 20000, 50000, 100000];

  @override
  void initState() {
    super.initState();
    c = Get.find<CustomerWithdrawController>();
    // فحص: قد يكون لدى المستخدم طلب معلّق بالفعل
    WidgetsBinding.instance.addPostFrameCallback((_) async {
      await c.loadPending();
      if (c.currentRequest.value != null && mounted) {
        Get.off(() => const WithdrawPendingScreen());
      }
    });
  }

  @override
  void dispose() {
    _amountCtrl.dispose();
    super.dispose();
  }

  void _setQuick(int amount) {
    _amountCtrl.text = amount.toString();
    setState(() {});
  }

  double get _availableBalance {
    try {
      return Get.find<ProfileController>().userInfo?.balance ?? 0.0;
    } catch (_) {
      return 0.0;
    }
  }

  Future<void> _submit() async {
    final amount = _amountCtrl.text.trim();
    if (amount.isEmpty) {
      _snack('أدخل المبلغ');
      return;
    }
    final n = double.tryParse(amount) ?? 0;
    if (n < 100) {
      _snack('الحد الأدنى للسحب 100 ر.ي');
      return;
    }
    // AMIAL-PIN-GATE-001: رمز PIN قبل تنفيذ السحب
    if (!await askAmialPin(title: 'تأكيد طلب السحب')) return;
    if (!mounted) return;
    final ok = await c.requestWithdraw(amount);
    if (!mounted) return;
    if (ok) {
      Get.off(() => const WithdrawPendingScreen());
    } else {
      // AMIAL-ERRORS-001: تعريب رسالة الخادم
      _snack(AmialErrors.arabize(c.lastError.value,
          code: c.lastErrorCode.value, fallback: 'فشل الطلب'));
    }
  }

  void _snack(String m) => ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(m), backgroundColor: AmialColors.red),
      );

  @override
  Widget build(BuildContext context) {
    final entered = double.tryParse(_amountCtrl.text.trim()) ?? 0;
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(
        title: const Text('سحب الأموال'),
        actions: [
          // AMIAL-WD-HISTORY-001: سجل طلبات السحب
          IconButton(
            icon: const Icon(Icons.history),
            tooltip: 'سجل طلبات السحب',
            onPressed: () => Get.to(() => const WithdrawHistoryScreen()),
          ),
        ],
      ),
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(20),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            // ====== بطاقة الرصيد المتاح ======
            Container(
              padding: const EdgeInsets.all(20),
              decoration: BoxDecoration(
                color: AmialColors.primary,
                borderRadius: BorderRadius.circular(16),
              ),
              child: Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
                const Text('الرصيد المتاح',
                    style: TextStyle(color: Colors.white70, fontSize: 13)),
                const SizedBox(height: 6),
                Text(AmialMoney.yer(_availableBalance),
                    style: const TextStyle(
                        color: Colors.white,
                        fontSize: 28,
                        fontWeight: FontWeight.bold)),
              ]),
            ),
            const SizedBox(height: 20),

            // ====== المبلغ ======
            const Text('مبلغ السحب',
                style: TextStyle(
                    fontSize: 14, color: Colors.grey, fontWeight: FontWeight.w500),
                textAlign: TextAlign.right),
            const SizedBox(height: 8),
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
              decoration: BoxDecoration(
                color: Colors.white,
                borderRadius: BorderRadius.circular(12),
                border: Border.all(color: Colors.grey.shade300),
              ),
              child: Row(children: [
                const Text('ر.ي',
                    style: TextStyle(
                        fontSize: 16,
                        color: Colors.grey,
                        fontWeight: FontWeight.bold)),
                const SizedBox(width: 12),
                Expanded(
                  child: TextField(
                    controller: _amountCtrl,
                    // AMIAL-DESIGN-26: الإدخال عبر لوحة الأرقام أدناه
                    readOnly: true,
                    showCursor: true,
                    textAlign: TextAlign.right,
                    inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                    style: const TextStyle(
                        fontSize: 28,
                        fontWeight: FontWeight.bold,
                        color: AmialColors.primary),
                    decoration: const InputDecoration(
                      border: InputBorder.none,
                      hintText: '0',
                      hintStyle: TextStyle(color: Colors.grey),
                    ),
                    onChanged: (_) => setState(() {}),
                  ),
                ),
              ]),
            ),
            const SizedBox(height: 12),

            // ====== مبالغ سريعة ======
            Wrap(
              spacing: 8,
              runSpacing: 8,
              alignment: WrapAlignment.center,
              children: _quickAmounts
                  .map((a) => ActionChip(
                        label: Text('${AmialMoney.fmt(a)} ر.ي',
                            style: const TextStyle(fontWeight: FontWeight.bold)),
                        backgroundColor: Colors.white,
                        side: BorderSide(
                            color: AmialColors.primary.withValues(alpha: 0.3)),
                        onPressed: () => _setQuick(a),
                      ))
                  .toList(),
            ),
            const SizedBox(height: 16),

            // ====== طريقة السحب ======
            const Text('طريقة السحب',
                style: TextStyle(
                    fontSize: 14, color: Colors.grey, fontWeight: FontWeight.w500),
                textAlign: TextAlign.right),
            const SizedBox(height: 8),
            Row(children: [
              Expanded(
                child: _methodCard(
                  label: 'عبر الوكيل',
                  icon: Icons.storefront_outlined,
                  value: 'agent',
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: _methodCard(
                  label: 'إلى بنك',
                  icon: Icons.account_balance_outlined,
                  value: 'bank',
                  comingSoon: true,
                ),
              ),
            ]),
            const SizedBox(height: 16),

            // ====== ملخص العملية ======
            Container(
              padding: const EdgeInsets.all(16),
              decoration: BoxDecoration(
                color: Colors.white,
                borderRadius: BorderRadius.circular(12),
              ),
              child: Column(children: [
                const Align(
                  alignment: Alignment.centerRight,
                  child: Text('ملخص العملية',
                      style:
                          TextStyle(fontWeight: FontWeight.bold, fontSize: 14)),
                ),
                const SizedBox(height: 10),
                _summaryRow('المبلغ المسحوب', AmialMoney.yer(entered)),
                const SizedBox(height: 6),
                // الرسوم تُحدَّد من الخادم عند التأكيد (حسب شرائح الرسوم)
                _summaryRow('رسوم التحويل', 'تُعرض عند التأكيد',
                    valueColor: AmialColors.textMuted, small: true),
              ]),
            ),
            const SizedBox(height: 16),

            // AMIAL-DS-001: مبالغ سريعة بضغطة (كالمراجع الاحترافية).
            AmialQuickAmounts(
              values: const [1000, 2000, 5000, 10000, 20000, 50000],
              onPick: (v) {
                _amountCtrl.text = v.toString();
                setState(() {});
              },
            ),
            const SizedBox(height: 12),

            // AMIAL-DESIGN-26: لوحة أرقام بنمط أميال
            AmialNumpad(
              controller: _amountCtrl,
              maxLength: 9,
              onChanged: (_) => setState(() {}),
            ),
            const SizedBox(height: 16),

            // ====== ملاحظة ======
            Container(
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(
                color: AmialColors.yellow.withValues(alpha: 0.18),
                borderRadius: BorderRadius.circular(10),
                border: Border.all(
                    color: AmialColors.yellow.withValues(alpha: 0.6)),
              ),
              child: Row(children: [
                const Icon(Icons.verified_user_outlined,
                    color: AmialColors.yellowDark, size: 20),
                const SizedBox(width: 10),
                Expanded(
                  child: Text(
                    'معاملاتك مؤمّنة بالكامل. السحب عبر الوكيل فوري — '
                    'يكون الطلب صالحاً 45 دقيقة ويحجز المبلغ من رصيدك خلالها.',
                    style: TextStyle(
                        fontSize: 12, color: Colors.grey.shade800, height: 1.5),
                  ),
                ),
              ]),
            ),
            const SizedBox(height: 20),

            // ====== زر التأكيد (موحّد عبر AmialButton) ======
            Obx(() => AmialButton(
                  label: 'تأكيد طلب السحب',
                  icon: Icons.check_circle_outline,
                  loading: c.isSubmitting.value,
                  onPressed: c.isSubmitting.value ? null : _submit,
                )),
          ],
        ),
      ),
    );
  }

  Widget _methodCard({
    required String label,
    required IconData icon,
    required String value,
    bool comingSoon = false,
  }) {
    final selected = _method == value;
    return InkWell(
      onTap: comingSoon
          ? () => _snack('السحب إلى البنك سيتوفّر قريباً')
          : () => setState(() => _method = value),
      borderRadius: BorderRadius.circular(14),
      child: Container(
        padding: const EdgeInsets.symmetric(vertical: 16),
        decoration: BoxDecoration(
          color: selected
              ? AmialColors.yellow.withValues(alpha: 0.25)
              : Colors.white,
          borderRadius: BorderRadius.circular(14),
          border: Border.all(
            color: selected ? AmialColors.yellowDark : AmialColors.border,
            width: selected ? 1.5 : 1,
          ),
        ),
        child: Column(children: [
          Icon(icon,
              size: 26,
              color: comingSoon ? AmialColors.textMuted : AmialColors.primary),
          const SizedBox(height: 6),
          Text(label,
              style: TextStyle(
                  fontSize: 13,
                  fontWeight: FontWeight.w600,
                  color: comingSoon
                      ? AmialColors.textMuted
                      : AmialColors.primary)),
          if (comingSoon)
            const Text('قريباً',
                style: TextStyle(fontSize: 10, color: AmialColors.textMuted)),
        ]),
      ),
    );
  }

  Widget _summaryRow(String label, String value,
      {Color? valueColor, bool small = false}) {
    return Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
      Text(value,
          style: TextStyle(
              fontSize: small ? 12 : 14,
              fontWeight: FontWeight.w600,
              color: valueColor ?? AmialColors.primary)),
      Text(label,
          style: const TextStyle(fontSize: 13, color: AmialColors.textSecondary)),
    ]);
  }
}
