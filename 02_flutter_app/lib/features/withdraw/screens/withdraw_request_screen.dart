import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';
import 'package:amyal_pay/features/withdraw/controllers/customer_withdraw_controller.dart';
import 'package:amyal_pay/features/withdraw/screens/withdraw_pending_screen.dart';
import 'package:amyal_pay/features/shared/widgets/amial_pin_gate.dart';

/// AMIAL-CUSTOMER-WITHDRAW-001 — شاشة طلب سحب نقدي عبر الوكيل.
class WithdrawRequestScreen extends StatefulWidget {
  const WithdrawRequestScreen({super.key});

  @override
  State<WithdrawRequestScreen> createState() => _WithdrawRequestScreenState();
}

class _WithdrawRequestScreenState extends State<WithdrawRequestScreen> {
  late final CustomerWithdrawController c;
  final _amountCtrl = TextEditingController();

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
      // عرض الخطأ بناءً على code
      final code = c.lastErrorCode.value;
      if (code == 'INSUFFICIENT_BALANCE') {
        _snack('الرصيد غير كافٍ');
      } else {
        _snack(c.lastError.value.isEmpty ? 'فشل الطلب' : c.lastError.value);
      }
    }
  }

  void _snack(String m) => ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(m), backgroundColor: AmyalColors.red),
      );

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmyalColors.background,
      appBar: AppBar(
        title: const Text('سحب نقدي'),
        backgroundColor: AmyalColors.primary,
        foregroundColor: Colors.white,
      ),
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(20),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            // ====== الشرح ======
            Container(
              padding: const EdgeInsets.all(16),
              decoration: BoxDecoration(
                color: AmyalColors.yellow.withValues(alpha: 0.18),
                borderRadius: BorderRadius.circular(12),
                border: Border.all(color: AmyalColors.yellow, width: 1),
              ),
              child: Row(children: [
                const Icon(Icons.info_outline, color: AmyalColors.yellowDark),
                const SizedBox(width: 12),
                const Expanded(
                  child: Text(
                    'ستحصل على رقم عملية صالح لمدة قصيرة. اذهب لأقرب وكيل وأعطه الرقم لاستلام النقد.',
                    style: TextStyle(fontSize: 13, height: 1.4),
                  ),
                ),
              ]),
            ),
            const SizedBox(height: 24),

            // ====== المبلغ ======
            const Text('مبلغ السحب', style: TextStyle(fontSize: 14, color: Colors.grey, fontWeight: FontWeight.w500), textAlign: TextAlign.right),
            const SizedBox(height: 8),
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
              decoration: BoxDecoration(
                color: Colors.white,
                borderRadius: BorderRadius.circular(12),
                border: Border.all(color: Colors.grey.shade300),
              ),
              child: Row(children: [
                const Text('ر.ي', style: TextStyle(fontSize: 16, color: Colors.grey, fontWeight: FontWeight.bold)),
                const SizedBox(width: 12),
                Expanded(
                  child: TextField(
                    controller: _amountCtrl,
                    keyboardType: TextInputType.number,
                    textAlign: TextAlign.right,
                    inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                    style: const TextStyle(fontSize: 28, fontWeight: FontWeight.bold, color: AmyalColors.primary),
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
            const SizedBox(height: 16),

            // ====== مبالغ سريعة ======
            Wrap(
              spacing: 8,
              runSpacing: 8,
              alignment: WrapAlignment.center,
              children: _quickAmounts.map((a) => ActionChip(
                label: Text('${_formatNum(a)} ر.ي', style: const TextStyle(fontWeight: FontWeight.bold)),
                backgroundColor: Colors.white,
                side: BorderSide(color: AmyalColors.primary.withValues(alpha: 0.3)),
                onPressed: () => _setQuick(a),
              )).toList(),
            ),
            const SizedBox(height: 32),

            // ====== ملاحظة الصلاحية ======
            Container(
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(
                color: Colors.grey.shade100,
                borderRadius: BorderRadius.circular(8),
              ),
              child: Row(children: [
                Icon(Icons.timer_outlined, color: Colors.grey.shade600, size: 18),
                const SizedBox(width: 8),
                Expanded(
                  child: Text(
                    'سيكون الطلب صالحاً لمدة 45 دقيقة. خلالها يحجز المبلغ من رصيدك.',
                    style: TextStyle(fontSize: 12, color: Colors.grey.shade700),
                  ),
                ),
              ]),
            ),
            const SizedBox(height: 24),

            // ====== زر التأكيد ======
            Obx(() => FilledButton.icon(
              onPressed: c.isSubmitting.value ? null : _submit,
              icon: c.isSubmitting.value
                  ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                  : const Icon(Icons.check_circle_outline),
              label: const Text('تأكيد طلب السحب', style: TextStyle(fontSize: 16)),
              style: FilledButton.styleFrom(
                backgroundColor: AmyalColors.primary,
                minimumSize: const Size.fromHeight(54),
              ),
            )),
          ],
        ),
      ),
    );
  }

  String _formatNum(int n) {
    final s = n.toString();
    final buf = StringBuffer();
    for (var i = 0; i < s.length; i++) {
      if (i > 0 && (s.length - i) % 3 == 0) buf.write(',');
      buf.write(s[i]);
    }
    return buf.toString();
  }
}
