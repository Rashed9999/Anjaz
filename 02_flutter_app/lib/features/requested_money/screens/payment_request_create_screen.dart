import 'dart:async';

import 'package:amial_pay/common/widgets/amial_button.dart';
import 'package:amial_pay/common/widgets/amial_quick_amounts.dart';
import 'package:amial_pay/features/requested_money/controllers/payment_request_controller.dart';
import 'package:amial_pay/features/requested_money/screens/payment_request_show_screen.dart';
import 'package:amial_pay/helper/amial_money.dart';
import 'package:amial_pay/theme/amial_colors.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:get/get.dart';

/// طلب مال مباشر من عميل أميال: تحقق من العميل، تأكيد، ثم إرسال إلى وارده.
class PaymentRequestCreateScreen extends StatefulWidget {
  final String? initialPhone;

  const PaymentRequestCreateScreen({super.key, this.initialPhone});

  @override
  State<PaymentRequestCreateScreen> createState() =>
      _PaymentRequestCreateScreenState();
}

class _PaymentRequestCreateScreenState
    extends State<PaymentRequestCreateScreen> {
  late final PaymentRequestController c;
  final _phone = TextEditingController();
  final _amount = TextEditingController();
  final _note = TextEditingController();
  Timer? _lookupTimer;

  @override
  void initState() {
    super.initState();
    c = Get.find<PaymentRequestController>();
    c.clearRecipientCheck();

    final seed = widget.initialPhone?.trim() ?? '';
    if (seed.isNotEmpty) {
      _phone.text = seed;
      WidgetsBinding.instance.addPostFrameCallback((_) => c.checkRecipient(seed));
    }
  }

  @override
  void dispose() {
    _lookupTimer?.cancel();
    _phone.dispose();
    _amount.dispose();
    _note.dispose();
    super.dispose();
  }

  void _onPhoneChanged(String value) {
    _lookupTimer?.cancel();
    c.clearRecipientCheck();
    final digits = value.replaceAll(RegExp(r'[^0-9]'), '');
    if (digits.length != 8 && digits.length < 9) return;
    _lookupTimer = Timer(
      const Duration(milliseconds: 500),
      () => c.checkRecipient(value.trim()),
    );
  }

  Future<void> _submit() async {
    final phone = _phone.text.trim();
    final digits = phone.replaceAll(RegExp(r'[^0-9]'), '');
    if (digits.length != 8 && digits.length < 9) {
      _snack('أدخل رقم العميل كاملاً');
      return;
    }

    final amount = double.tryParse(_amount.text.trim()) ?? 0;
    if (amount <= 0) {
      _snack('أدخل مبلغاً صحيحاً أكبر من صفر');
      return;
    }

    var verified = c.recipientCheck.value;
    if (verified?['found'] != true) {
      final found = await c.checkRecipient(phone);
      if (!mounted) return;
      verified = c.recipientCheck.value;
      if (!found || verified?['found'] != true) {
        _snack(c.lastError.value.isEmpty
            ? 'تعذّر العثور على عميل مؤهّل بهذا الرقم'
            : c.lastError.value);
        return;
      }
    }

    final recipientId = int.tryParse('${verified?['recipient_id'] ?? ''}');
    final token = '${verified?['verification_token'] ?? ''}';
    if (recipientId == null || token.length != 26) {
      c.clearRecipientCheck();
      _snack('انتهى تأكيد العميل — تحقّق من الرقم مرة أخرى');
      return;
    }

    final confirmed = await _confirmSheet(
      name: '${verified?['masked_name'] ?? verified?['name'] ?? 'عميل أميال'}',
      phone: '${verified?['masked_phone'] ?? phone}',
      amount: amount,
      note: _note.text.trim(),
    );
    if (confirmed != true || !mounted) return;

    final ok = await c.createDirect(
      amount: _amount.text.trim(),
      recipientId: recipientId,
      verificationToken: token,
      note: _note.text.trim().isEmpty ? null : _note.text.trim(),
    );
    if (!mounted) return;

    if (ok) {
      Get.off(() => const PaymentRequestShowScreen());
    } else {
      final error = c.lastError.value;
      // عند انقطاع الجواب نبقي token والجسد كما هما: ApiClient يحتفظ
      // بمفتاح Idempotency نفسه، فتكون المحاولة التالية إعادةً آمنة.
      if (!error.startsWith('تعذّر الاتصال')) {
        c.clearRecipientCheck();
      }
      _snack(error.isEmpty ? 'تعذّر إرسال الطلب' : error);
    }
  }

  Future<bool?> _confirmSheet({
    required String name,
    required String phone,
    required double amount,
    required String note,
  }) {
    return showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.white,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
      ),
      builder: (ctx) => SafeArea(
        child: Padding(
          padding: const EdgeInsets.fromLTRB(20, 12, 20, 24),
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            Row(children: [
              IconButton(
                tooltip: 'إغلاق',
                onPressed: () => Navigator.pop(ctx, false),
                icon: const Icon(Icons.close),
              ),
              const Spacer(),
              const Text('تأكيد طلب المال',
                  style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold)),
            ]),
            const SizedBox(height: 12),
            CircleAvatar(
              radius: 32,
              backgroundColor: AmialColors.primary.withValues(alpha: 0.1),
              child: Text(
                name.isEmpty ? '؟' : name.substring(0, 1),
                style: const TextStyle(
                    color: AmialColors.primary,
                    fontSize: 24,
                    fontWeight: FontWeight.bold),
              ),
            ),
            const SizedBox(height: 10),
            Text(name,
                style: const TextStyle(fontSize: 18, fontWeight: FontWeight.bold)),
            const SizedBox(height: 3),
            Text(phone,
                textDirection: TextDirection.ltr,
                style: const TextStyle(color: AmialColors.textSecondary)),
            const SizedBox(height: 18),
            Container(
              width: double.infinity,
              padding: const EdgeInsets.all(16),
              decoration: BoxDecoration(
                color: AmialColors.background,
                borderRadius: BorderRadius.circular(14),
              ),
              child: Column(children: [
                _confirmRow('المبلغ المطلوب', AmialMoney.yer(amount), bold: true),
                if (note.isNotEmpty) ...[
                  const Divider(height: 20),
                  _confirmRow('السبب', note),
                ],
              ]),
            ),
            const SizedBox(height: 12),
            const Row(children: [
              Icon(Icons.info_outline, size: 17, color: AmialColors.textMuted),
              SizedBox(width: 7),
              Expanded(
                child: Text(
                  'سيصل الطلب إلى هذا العميل، ولن يتحرك أي مال حتى يوافق هو ويدخل رمز الحماية.',
                  textAlign: TextAlign.right,
                  style: TextStyle(fontSize: 12, color: AmialColors.textMuted),
                ),
              ),
            ]),
            const SizedBox(height: 18),
            FilledButton.icon(
              onPressed: () => Navigator.pop(ctx, true),
              icon: const Icon(Icons.send_rounded),
              label: const Text('تأكيد وإرسال الطلب'),
              style: FilledButton.styleFrom(
                backgroundColor: AmialColors.primary,
                minimumSize: const Size.fromHeight(52),
              ),
            ),
          ]),
        ),
      ),
    );
  }

  Widget _confirmRow(String label, String value, {bool bold = false}) =>
      Row(children: [
        Expanded(
          child: Text(value,
              textAlign: TextAlign.left,
              style: TextStyle(
                  fontSize: bold ? 18 : 13,
                  fontWeight: bold ? FontWeight.bold : FontWeight.w500,
                  color: bold ? AmialColors.primary : Colors.black87)),
        ),
        const SizedBox(width: 12),
        Text(label,
            style: const TextStyle(fontSize: 12, color: AmialColors.textSecondary)),
      ]);

  void _snack(String message) => ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(message), backgroundColor: AmialColors.red),
      );

  Widget _recipientStatus() => Obx(() {
        if (c.isChecking.value) {
          return const _StatusCard(
            color: AmialColors.primary,
            icon: Icons.manage_search,
            title: 'نتحقق من بيانات العميل…',
            loading: true,
          );
        }

        final result = c.recipientCheck.value;
        if (result?['found'] == true) {
          return _StatusCard(
            color: AmialColors.success,
            icon: Icons.verified_user_rounded,
            title: '${result?['masked_name'] ?? result?['name'] ?? 'عميل أميال'}',
            subtitle: '${result?['masked_phone'] ?? ''}\nتحقّق من الاسم قبل الإرسال',
          );
        }

        if (c.lastError.value.isNotEmpty) {
          return _StatusCard(
            color: AmialColors.red,
            icon: Icons.error_outline,
            title: c.lastError.value,
            subtitle: 'راجع الرقم أو حاول مرة أخرى',
          );
        }

        return const _StatusCard(
          color: AmialColors.textMuted,
          icon: Icons.badge_outlined,
          title: 'أدخل رقم الهاتف أو رقم حساب العميل',
          subtitle: 'لن يُرسل الطلب حتى تظهر بيانات التأكيد',
        );
      });

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(title: const Text('طلب المال')),
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(16),
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            Container(
              padding: const EdgeInsets.all(16),
              decoration: BoxDecoration(
                color: AmialColors.primary,
                borderRadius: BorderRadius.circular(18),
              ),
              child: const Row(children: [
                Icon(Icons.request_quote_rounded, color: Colors.white, size: 32),
                SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.end,
                    children: [
                      Text('اطلب من عميل أميال',
                          style: TextStyle(
                              color: Colors.white,
                              fontSize: 17,
                              fontWeight: FontWeight.bold)),
                      SizedBox(height: 4),
                      Text('يتحقق من هويته، ثم يصله الطلب ليوافق أو يرفض.',
                          textAlign: TextAlign.right,
                          style: TextStyle(color: Colors.white70, fontSize: 12)),
                    ],
                  ),
                ),
              ]),
            ),
            const SizedBox(height: 20),
            const Text('رقم العميل',
                textAlign: TextAlign.right,
                style: TextStyle(fontSize: 14, fontWeight: FontWeight.w600)),
            const SizedBox(height: 7),
            TextField(
              controller: _phone,
              keyboardType: TextInputType.phone,
              textDirection: TextDirection.ltr,
              textAlign: TextAlign.left,
              inputFormatters: [FilteringTextInputFormatter.allow(RegExp(r'[0-9+ ]'))],
              decoration: InputDecoration(
                hintText: '777 000 000 أو رقم الحساب',
                prefixIcon: const Icon(Icons.person_search_outlined),
                filled: true,
                fillColor: Colors.white,
                border: OutlineInputBorder(
                    borderRadius: BorderRadius.circular(14),
                    borderSide: BorderSide.none),
              ),
              onChanged: _onPhoneChanged,
            ),
            const SizedBox(height: 8),
            _recipientStatus(),
            const SizedBox(height: 20),
            const Text('المبلغ المطلوب',
                textAlign: TextAlign.right,
                style: TextStyle(fontSize: 14, fontWeight: FontWeight.w600)),
            const SizedBox(height: 7),
            TextField(
              controller: _amount,
              keyboardType: TextInputType.number,
              textDirection: TextDirection.ltr,
              textAlign: TextAlign.left,
              inputFormatters: [FilteringTextInputFormatter.digitsOnly],
              style: const TextStyle(
                  fontSize: 25,
                  fontWeight: FontWeight.bold,
                  color: AmialColors.primary),
              decoration: InputDecoration(
                hintText: '0',
                suffixText: 'ر.ي',
                filled: true,
                fillColor: Colors.white,
                border: OutlineInputBorder(
                    borderRadius: BorderRadius.circular(14),
                    borderSide: BorderSide.none),
              ),
            ),
            const SizedBox(height: 10),
            AmialQuickAmounts(
              values: const [500, 1000, 2000, 5000, 10000, 20000],
              onPick: (value) => _amount.text = value.toString(),
            ),
            const SizedBox(height: 20),
            const Text('سبب الطلب (اختياري)',
                textAlign: TextAlign.right,
                style: TextStyle(fontSize: 14, fontWeight: FontWeight.w600)),
            const SizedBox(height: 7),
            TextField(
              controller: _note,
              textAlign: TextAlign.right,
              maxLines: 2,
              maxLength: 255,
              decoration: InputDecoration(
                hintText: 'مثال: حصتي من فاتورة الكهرباء',
                filled: true,
                fillColor: Colors.white,
                border: OutlineInputBorder(
                    borderRadius: BorderRadius.circular(14),
                    borderSide: BorderSide.none),
              ),
            ),
            const SizedBox(height: 12),
            Obx(() => AmialButton(
                  label: 'متابعة وتأكيد',
                  icon: Icons.arrow_back_rounded,
                  loading: c.isSubmitting.value || c.isChecking.value,
                  onPressed: c.isSubmitting.value || c.isChecking.value
                      ? null
                      : _submit,
                )),
          ]),
        ),
      ),
    );
  }
}

class _StatusCard extends StatelessWidget {
  final Color color;
  final IconData icon;
  final String title;
  final String? subtitle;
  final bool loading;

  const _StatusCard({
    required this.color,
    required this.icon,
    required this.title,
    this.subtitle,
    this.loading = false,
  });

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.all(11),
        decoration: BoxDecoration(
          color: color.withValues(alpha: 0.08),
          borderRadius: BorderRadius.circular(12),
          border: Border.all(color: color.withValues(alpha: 0.28)),
        ),
        child: Row(children: [
          if (loading)
            SizedBox(
              width: 20,
              height: 20,
              child: CircularProgressIndicator(strokeWidth: 2, color: color),
            )
          else
            Icon(icon, color: color, size: 21),
          const SizedBox(width: 10),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.end,
              children: [
                Text(title,
                    textAlign: TextAlign.right,
                    style: TextStyle(
                        color: color, fontSize: 13, fontWeight: FontWeight.bold)),
                if (subtitle != null && subtitle!.isNotEmpty) ...[
                  const SizedBox(height: 2),
                  Text(subtitle!,
                      textAlign: TextAlign.right,
                      style: TextStyle(color: color, fontSize: 11.5)),
                ],
              ],
            ),
          ),
        ]),
      );
}
