import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:get/get.dart';
import 'package:qr_flutter/qr_flutter.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';
import 'package:amyal_pay/helper/amial_money.dart';
import 'package:amyal_pay/features/withdraw/controllers/customer_withdraw_controller.dart';

/// AMIAL-CUSTOMER-WITHDRAW-001 — شاشة الطلب المعلّق.
///
/// تعرض:
///   - رقم العملية (op_code) بخط كبير للوكيل.
///   - ملخّص: المبلغ + الرسوم + الإجمالي المخصوم.
///   - عدّ تنازلي (45 دقيقة).
///   - نسخ + مشاركة + إلغاء.
class WithdrawPendingScreen extends StatefulWidget {
  const WithdrawPendingScreen({super.key});

  @override
  State<WithdrawPendingScreen> createState() => _WithdrawPendingScreenState();
}

class _WithdrawPendingScreenState extends State<WithdrawPendingScreen> {
  late final CustomerWithdrawController c;
  Timer? _ticker;
  Duration _remaining = Duration.zero;

  @override
  void initState() {
    super.initState();
    c = Get.find<CustomerWithdrawController>();
    _startTicker();
  }

  void _startTicker() {
    _updateRemaining();
    _ticker?.cancel();
    _ticker = Timer.periodic(const Duration(seconds: 1), (_) {
      if (!mounted) return;
      setState(_updateRemaining);
      if (_remaining.inSeconds <= 0) {
        _ticker?.cancel();
      }
    });
  }

  void _updateRemaining() {
    final exp = c.currentRequest.value?['expires_at'];
    if (exp == null) {
      _remaining = Duration.zero;
      return;
    }
    try {
      final dt = DateTime.parse(exp.toString()).toLocal();
      final diff = dt.difference(DateTime.now());
      _remaining = diff.isNegative ? Duration.zero : diff;
    } catch (_) {
      _remaining = Duration.zero;
    }
  }

  @override
  void dispose() {
    _ticker?.cancel();
    super.dispose();
  }

  Future<void> _confirmCancel() async {
    // AMIAL-DESIGN: ورقة تأكيد الإلغاء (مثلث تحذير + تراجع/نعم إلغاء العملية)
    final ok = await showModalBottomSheet<bool>(
      context: context,
      backgroundColor: Colors.white,
      shape: const RoundedRectangleBorder(
          borderRadius: BorderRadius.vertical(top: Radius.circular(24))),
      builder: (ctx) => Padding(
        padding: const EdgeInsets.fromLTRB(24, 16, 24, 24),
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          Container(
            width: 44, height: 4,
            decoration: BoxDecoration(
                color: Colors.grey.shade300,
                borderRadius: BorderRadius.circular(2)),
          ),
          const SizedBox(height: 20),
          Container(
            height: 76, width: 76,
            alignment: Alignment.center,
            decoration: BoxDecoration(
              color: Colors.white,
              shape: BoxShape.circle,
              boxShadow: [
                BoxShadow(
                    color: Colors.black.withValues(alpha: 0.08),
                    blurRadius: 14),
              ],
            ),
            child: const Icon(Icons.warning_amber_rounded,
                color: AmyalColors.red, size: 40),
          ),
          const SizedBox(height: 16),
          const Text('هل أنت متأكد من إلغاء العملية؟',
              style: TextStyle(
                  fontWeight: FontWeight.bold,
                  fontSize: 15,
                  color: AmyalColors.primary)),
          const SizedBox(height: 10),
          const Text(
            'بمجرد الإلغاء، لن يتمكن الوكيل من إتمام عملية السحب '
            'باستخدام هذا الرمز. سيعود المبلغ فوراً إلى محفظتك.',
            textAlign: TextAlign.center,
            style: TextStyle(
                fontSize: 13, color: AmyalColors.textSecondary, height: 1.6),
          ),
          const SizedBox(height: 20),
          FilledButton(
            onPressed: () => Navigator.pop(ctx, false),
            style: FilledButton.styleFrom(
              backgroundColor: AmyalColors.primary,
              minimumSize: const Size.fromHeight(52),
              shape: RoundedRectangleBorder(
                  borderRadius: BorderRadius.circular(14)),
            ),
            child: const Text('تراجع'),
          ),
          const SizedBox(height: 10),
          OutlinedButton.icon(
            onPressed: () => Navigator.pop(ctx, true),
            icon: const Icon(Icons.close, size: 18),
            label: const Text('نعم، إلغاء العملية'),
            style: OutlinedButton.styleFrom(
              foregroundColor: AmyalColors.red,
              side: BorderSide(color: AmyalColors.red.withValues(alpha: 0.4)),
              minimumSize: const Size.fromHeight(52),
              shape: RoundedRectangleBorder(
                  borderRadius: BorderRadius.circular(14)),
            ),
          ),
          const SizedBox(height: 12),
          const Row(mainAxisAlignment: MainAxisAlignment.center, children: [
            Icon(Icons.verified_user_outlined,
                size: 14, color: AmyalColors.textMuted),
            SizedBox(width: 6),
            Text('عملية آمنة ومحمية من قبل أميال باي',
                style: TextStyle(fontSize: 11, color: AmyalColors.textMuted)),
          ]),
        ]),
      ),
    );
    if (ok != true) return;

    final req = c.currentRequest.value;
    final id = (req?['request_id'] ?? req?['id']) as int?;
    if (id == null) return;
    final success = await c.cancelRequest(id);
    if (!mounted) return;
    if (success) {
      Get.back(); // ارجع للشاشة السابقة
      Get.snackbar('تم', 'تم إلغاء العملية وفكّ الحجز',
          backgroundColor: Colors.green.shade100, colorText: Colors.green.shade800);
    } else {
      _snack(c.lastError.value.isEmpty ? 'فشل الإلغاء' : c.lastError.value);
    }
  }

  void _copyOpCode() {
    final code = c.currentRequest.value?['op_code']?.toString() ?? '';
    Clipboard.setData(ClipboardData(text: code));
    Get.snackbar('تم النسخ', 'رقم العملية في الحافظة',
        backgroundColor: Colors.green.shade100, colorText: Colors.green.shade800);
  }

  void _snack(String m) => ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(m), backgroundColor: AmyalColors.red),
      );

  String _fmtDur(Duration d) {
    final mm = d.inMinutes.toString().padLeft(2, '0');
    final ss = (d.inSeconds % 60).toString().padLeft(2, '0');
    return '$mm:$ss';
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmyalColors.background,
      appBar: AppBar(
        title: const Text('تفاصيل السحب'),
        leading: IconButton(
          icon: const Icon(Icons.arrow_back),
          onPressed: () => Get.back(),
        ),
      ),
      body: Obx(() {
        final req = c.currentRequest.value;
        if (req == null) {
          return const Center(
            child: Column(mainAxisAlignment: MainAxisAlignment.center, children: [
              Icon(Icons.check_circle, color: Colors.green, size: 64),
              SizedBox(height: 12),
              Text('لا يوجد طلب نشط'),
            ]),
          );
        }
        final opCode = req['op_code']?.toString() ?? '';
        final amount = req['amount']?.toString() ?? '0';
        final fee = req['fee']?.toString() ?? '0';
        final total = req['total_debit']?.toString() ?? '0';
        final expired = _remaining.inSeconds <= 0;

        return SingleChildScrollView(
          padding: const EdgeInsets.all(20),
          child: Column(children: [
            // ====== الـ op_code Card ======
            Container(
              width: double.infinity,
              padding: const EdgeInsets.all(24),
              decoration: BoxDecoration(
                color: AmyalColors.primary,
                borderRadius: BorderRadius.circular(20),
              ),
              child: Column(children: [
                const Text('رقم العملية', style: TextStyle(color: Colors.white70, fontSize: 13)),
                const SizedBox(height: 8),
                SelectableText(
                  opCode,
                  style: const TextStyle(
                    color: Colors.white,
                    fontSize: 38,
                    fontWeight: FontWeight.bold,
                    letterSpacing: 6,
                  ),
                  textAlign: TextAlign.center,
                ),
                const SizedBox(height: 8),
                const Text(
                  'أعطِ هذا الرقم للوكيل',
                  style: TextStyle(color: Colors.white70, fontSize: 12),
                ),
                // AMIAL-DESIGN-23: رمز QR للعملية — يمسحه الوكيل بدل كتابة الرقم
                if (opCode.isNotEmpty) ...[
                  const SizedBox(height: 16),
                  Container(
                    padding: const EdgeInsets.all(10),
                    decoration: BoxDecoration(
                      color: Colors.white,
                      borderRadius: BorderRadius.circular(14),
                    ),
                    child: QrImageView(
                      data: 'AMIAL-WD:$opCode',
                      size: 150,
                      backgroundColor: Colors.white,
                    ),
                  ),
                  const SizedBox(height: 6),
                  const Text(
                    'أو يمسح الوكيل هذا الرمز',
                    style: TextStyle(color: Colors.white70, fontSize: 11),
                  ),
                ],
              ]),
            ),
            const SizedBox(height: 16),

            // ====== العدّ التنازلي ======
            Container(
              width: double.infinity,
              padding: const EdgeInsets.all(14),
              decoration: BoxDecoration(
                color: expired ? AmyalColors.red.withValues(alpha: 0.1) : AmyalColors.yellow.withValues(alpha: 0.2),
                borderRadius: BorderRadius.circular(12),
                border: Border.all(color: expired ? AmyalColors.red : AmyalColors.yellowDark),
              ),
              child: Row(mainAxisAlignment: MainAxisAlignment.center, children: [
                Icon(
                  expired ? Icons.timer_off : Icons.timer,
                  color: expired ? AmyalColors.red : AmyalColors.yellowDark,
                ),
                const SizedBox(width: 10),
                Text(
                  expired ? 'انتهت الصلاحية' : 'صالح لـ ${_fmtDur(_remaining)}',
                  style: TextStyle(
                    fontSize: 18,
                    fontWeight: FontWeight.bold,
                    color: expired ? AmyalColors.red : AmyalColors.yellowDark,
                  ),
                ),
              ]),
            ),
            const SizedBox(height: 20),

            // ====== ملخّص العملية ======
            Container(
              padding: const EdgeInsets.all(16),
              decoration: BoxDecoration(
                color: Colors.white,
                borderRadius: BorderRadius.circular(12),
              ),
              child: Column(children: [
                _row('مبلغ السحب', AmialMoney.yer(amount)),
                const Divider(height: 24),
                _row('رسوم العملية', AmialMoney.yer(fee), color: AmyalColors.red),
                const Divider(height: 24),
                _row('الإجمالي المخصوم', AmialMoney.yer(total), bold: true, color: AmyalColors.primary, size: 18),
              ]),
            ),
            const SizedBox(height: 24),

            // ====== الأزرار ======
            Row(children: [
              Expanded(
                child: OutlinedButton.icon(
                  onPressed: expired ? null : _copyOpCode,
                  icon: const Icon(Icons.copy, size: 18),
                  label: const Text('نسخ الرقم'),
                  style: OutlinedButton.styleFrom(
                    minimumSize: const Size.fromHeight(50),
                    side: const BorderSide(color: AmyalColors.primary),
                    foregroundColor: AmyalColors.primary,
                  ),
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Obx(() => FilledButton.icon(
                  onPressed: c.isSubmitting.value ? null : _confirmCancel,
                  icon: const Icon(Icons.close, size: 18),
                  label: const Text('إلغاء'),
                  style: FilledButton.styleFrom(
                    backgroundColor: AmyalColors.red,
                    minimumSize: const Size.fromHeight(50),
                  ),
                )),
              ),
            ]),
            const SizedBox(height: 16),

            // ====== التعليمات ======
            Container(
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(
                color: Colors.grey.shade100,
                borderRadius: BorderRadius.circular(8),
              ),
              child: Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
                const Text('كيفية الاستلام:', style: TextStyle(fontWeight: FontWeight.bold)),
                const SizedBox(height: 8),
                Text(
                  '1. اذهب لأقرب وكيل أميال باي.\n'
                  '2. أعطه رقم العملية أعلاه.\n'
                  '3. سيتحقّق منه الوكيل في نظامه.\n'
                  '4. ستستلم المبلغ نقداً.',
                  style: TextStyle(fontSize: 13, color: Colors.grey.shade700, height: 1.6),
                ),
              ]),
            ),
          ]),
        );
      }),
    );
  }

  Widget _row(String label, String value, {bool bold = false, Color? color, double size = 14}) {
    return Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
      Text(
        value,
        style: TextStyle(
          fontSize: size,
          fontWeight: bold ? FontWeight.bold : FontWeight.w500,
          color: color ?? Colors.black87,
        ),
      ),
      Text(label, style: TextStyle(fontSize: size - 1, color: Colors.grey.shade700)),
    ]);
  }
}
