import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/features/merchant/controllers/merchant_pay_controller.dart';
import 'package:amyal_pay/features/shared/widgets/qr_widgets.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';

/// AMIAL-MERCHANT-PAY-001 — شاشة دفع العميل للتاجر (QR / POS).
///
/// يدخل العميل رقم التاجر (أو يُملأ تلقائياً من مسح QR) والمبلغ،
/// تظهر معاينة (كم يستلم التاجر بعد الرسم)، ثم يؤكّد الدفع.
class MerchantPayScreen extends StatefulWidget {
  /// قد تُمرّر من شاشة مسح QR
  final String? prefillMerchantPhone;
  final int? merchantUserId;
  final String channel; // 'qr' | 'pos'
  final int? posUserId;

  const MerchantPayScreen({
    super.key,
    this.prefillMerchantPhone,
    this.merchantUserId,
    this.channel = 'qr',
    this.posUserId,
  });

  @override
  State<MerchantPayScreen> createState() => _MerchantPayScreenState();
}

class _MerchantPayScreenState extends State<MerchantPayScreen> {
  final _formKey = GlobalKey<FormState>();
  final _phoneCtrl = TextEditingController();
  final _amountCtrl = TextEditingController();
  final _noteCtrl = TextEditingController();

  @override
  void initState() {
    super.initState();
    if (widget.prefillMerchantPhone != null) {
      _phoneCtrl.text = widget.prefillMerchantPhone!;
    }
    WidgetsBinding.instance.addPostFrameCallback((_) {
      Get.find<MerchantPayController>().prepareNewPayment();
    });
  }

  @override
  void dispose() {
    _phoneCtrl.dispose();
    _amountCtrl.dispose();
    _noteCtrl.dispose();
    super.dispose();
  }

  /// AMIAL-MERCHANT-PAY-001 — تعبئة رقم التاجر بمسح رمز QR الخاص به.
  /// رموز أميال باي JSON فيها حقل phone؛ ويُقبل أيضاً رمز نصّه رقم هاتف مباشرةً.
  Future<void> _scanMerchantQr() async {
    final raw = await Get.to<String>(() => const QrScannerScreen(title: 'مسح رمز التاجر'));
    if (raw == null || raw.isEmpty || !mounted) return;

    String? phone;
    try {
      final decoded = jsonDecode(raw);
      if (decoded is Map && decoded['phone'] != null) {
        phone = decoded['phone'].toString();
      }
    } catch (_) {
      final cleaned = raw.replaceAll(RegExp(r'[\s\-]'), '');
      if (RegExp(r'^\+?[0-9]{6,15}$').hasMatch(cleaned)) phone = cleaned;
    }

    if (phone == null) {
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
        content: Text('هذا الرمز ليس رمز تاجر في أميال باي'),
        backgroundColor: AmyalColors.red,
      ));
      return;
    }

    setState(() => _phoneCtrl.text = phone!);
    _refreshQuote();
  }

  void _refreshQuote() {
    final amount = _amountCtrl.text.trim();
    if (amount.isNotEmpty) {
      Get.find<MerchantPayController>().getQuote(amount: amount, channel: widget.channel);
    }
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;
    final ctrl = Get.find<MerchantPayController>();

    final ok = await ctrl.pay(
      merchantPhone: widget.merchantUserId == null ? _phoneCtrl.text.trim() : null,
      merchantUserId: widget.merchantUserId,
      amount: _amountCtrl.text.trim(),
      channel: widget.channel,
      posUserId: widget.posUserId,
      note: _noteCtrl.text.trim(),
    );

    if (!mounted) return;

    if (ok) {
      final meta = ctrl.lastResult.value ?? {};
      showDialog(
        context: context,
        barrierDismissible: false,
        builder: (ctx) => AlertDialog(
          icon: const Icon(Icons.check_circle, color: Colors.green, size: 56),
          title: const Text('تم الدفع بنجاح', textAlign: TextAlign.center),
          content: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(
                'تم دفع ${meta['amount'] ?? ''} ر.ي للتاجر. ستجد الإيصال في قائمة الإيصالات.',
                textAlign: TextAlign.center,
                style: const TextStyle(fontSize: 13),
              ),
              if (meta['transaction_id'] != null) ...[
                const SizedBox(height: 8),
                Container(
                  padding: const EdgeInsets.all(6),
                  decoration: BoxDecoration(
                    color: Colors.grey.shade100,
                    borderRadius: BorderRadius.circular(4),
                  ),
                  child: Text(
                    'مرجع: ${meta['transaction_id']}',
                    style: const TextStyle(fontFamily: 'monospace', fontSize: 10),
                  ),
                ),
              ],
            ],
          ),
          actions: [
            TextButton(
              onPressed: () {
                Navigator.pop(ctx);
                Navigator.pop(context);
              },
              child: const Text('حسناً'),
            ),
          ],
        ),
      );
    } else {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(ctrl.lastError.value.isNotEmpty ? ctrl.lastError.value : 'فشل الدفع'),
          backgroundColor: AmyalColors.red,
        ),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    final ctrl = Get.find<MerchantPayController>();

    return Scaffold(
      backgroundColor: AmyalColors.background,
      appBar: AppBar(
        backgroundColor: AmyalColors.primary,
        foregroundColor: Colors.white,
        title: const Text('دفع تاجر'),
      ),
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(16),
        child: Form(
          key: _formKey,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              if (widget.merchantUserId == null) ...[
                TextFormField(
                  controller: _phoneCtrl,
                  keyboardType: TextInputType.phone,
                  decoration: InputDecoration(
                    labelText: 'رقم هاتف التاجر',
                    prefixIcon: const Icon(Icons.store),
                    border: const OutlineInputBorder(),
                    // مسح QR التاجر بدل الإدخال اليدوي
                    suffixIcon: IconButton(
                      tooltip: 'مسح رمز التاجر',
                      icon: const Icon(Icons.qr_code_scanner_rounded,
                          color: AmyalColors.primary),
                      onPressed: _scanMerchantQr,
                    ),
                  ),
                  validator: (v) =>
                      (v == null || v.trim().length < 6) ? 'أدخل رقم تاجر صحيح' : null,
                ),
                const SizedBox(height: 12),
              ],
              TextFormField(
                controller: _amountCtrl,
                keyboardType: const TextInputType.numberWithOptions(decimal: true),
                onChanged: (_) => _refreshQuote(),
                decoration: const InputDecoration(
                  labelText: 'المبلغ',
                  prefixIcon: Icon(Icons.payments),
                  border: OutlineInputBorder(),
                ),
                validator: (v) {
                  final d = double.tryParse((v ?? '').trim());
                  if (d == null || d <= 0) return 'أدخل مبلغاً صحيحاً';
                  return null;
                },
              ),
              const SizedBox(height: 12),
              TextFormField(
                controller: _noteCtrl,
                decoration: const InputDecoration(
                  labelText: 'ملاحظة (اختياري)',
                  prefixIcon: Icon(Icons.note_alt_outlined),
                  border: OutlineInputBorder(),
                ),
              ),
              const SizedBox(height: 16),

              // معاينة الرسم
              Obx(() {
                if (ctrl.quoteMerchantReceives.value.isEmpty) {
                  return const SizedBox.shrink();
                }
                return Container(
                  padding: const EdgeInsets.all(12),
                  decoration: BoxDecoration(
                    color: AmyalColors.cardSurface,
                    borderRadius: BorderRadius.circular(8),
                    border: Border.all(color: AmyalColors.border),
                  ),
                  child: Row(
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    children: [
                      const Text('التاجر يستلم', style: TextStyle(color: AmyalColors.textSecondary)),
                      Text(
                        '${ctrl.quoteMerchantReceives.value} ر.ي',
                        style: const TextStyle(fontWeight: FontWeight.bold, color: AmyalColors.primary),
                      ),
                    ],
                  ),
                );
              }),

              const SizedBox(height: 20),
              Obx(() => ElevatedButton(
                    onPressed: ctrl.isSubmitting.value ? null : _submit,
                    style: ElevatedButton.styleFrom(
                      backgroundColor: AmyalColors.primary,
                      foregroundColor: Colors.white,
                      padding: const EdgeInsets.symmetric(vertical: 14),
                    ),
                    child: ctrl.isSubmitting.value
                        ? const SizedBox(
                            height: 20, width: 20,
                            child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white),
                          )
                        : const Text('ادفع الآن', style: TextStyle(fontSize: 16)),
                  )),
            ],
          ),
        ),
      ),
    );
  }
}
