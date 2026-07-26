import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/features/merchant/controllers/merchant_pay_controller.dart';
import 'package:amyal_pay/features/payments/domain/amial_qr_payload.dart';
import 'package:amyal_pay/features/requested_money/controllers/payment_request_controller.dart';
import 'package:amyal_pay/features/shared/widgets/qr_widgets.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';
import 'package:amyal_pay/helper/amial_money.dart';
import 'package:amyal_pay/common/widgets/amial_result_sheet.dart';

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

  /// AMIAL-QR-UNIFIED-001 — رمز «طلب دفع بمبلغ ثابت» ممرَّراً من الماسح
  /// المركزي. حين يُمرَّر تُفتح معاينة الدفع فوراً: البائع والمبلغ ثم تأكيد،
  /// ولا يُطلب من العميل رقمٌ ولا مبلغ — البائع حدّدهما.
  final String? requestCode;

  const MerchantPayScreen({
    super.key,
    this.prefillMerchantPhone,
    this.merchantUserId,
    this.channel = 'qr',
    this.posUserId,
    this.requestCode,
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
      final code = widget.requestCode;
      if (code != null && code.isNotEmpty) _payFixedRequest(code);
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
    final raw = await Get.to<String>(() => const QrScannerScreen(title: 'مسح رمز الدفع'));
    if (raw == null || raw.isEmpty || !mounted) return;

    // AMIAL-QR-UNIFIED-001: التحليل من القارئ المشترك لا نسخةً محلّية.
    // كانت هنا نسخة ثانية من المنطق تقبل أي 6–15 رقماً هاتفاً، فتبتلع
    // باركود المنتجات (EAN‑13 ثلاثة عشر رقماً) وتعرض تحويلاً لرقم لا وجود له.
    final payload = AmialQrPayload.parse(raw);
    final String? prCode = payload.isPaymentRequest ? payload.requestCode : null;
    final String? phone = payload.phone;

    if (prCode != null) {
      await _payFixedRequest(prCode);
      return;
    }

    if (phone == null) {
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
        content: Text('هذا الرمز ليس رمز دفع في أميال باي'),
        backgroundColor: AmyalColors.red,
      ));
      return;
    }

    setState(() => _phoneCtrl.text = phone);
    _refreshQuote();
  }

  /// دفع «طلب بمبلغ ثابت» بعد مسحه: معاينة (المستلِم + المبلغ) ثم تأكيد ودفع.
  Future<void> _payFixedRequest(String code) async {
    final pr = Get.find<PaymentRequestController>();
    final data = await pr.showByCode(code);
    if (!mounted) return;
    if (data == null) {
      _err(pr.lastError.value.isEmpty ? 'الطلب غير موجود' : pr.lastError.value);
      return;
    }
    final req = (data['request'] ?? {}) as Map;
    final requester = (data['requester'] ?? {}) as Map;
    final isActive = data['is_active'] == true;
    final amount = '${req['amount'] ?? ''}';
    if (!isActive) {
      _err('الطلب غير صالح (مدفوع/ملغى/منتهٍ)');
      return;
    }

    final confirm = await Get.dialog<bool>(AlertDialog(
      title: const Text('تأكيد الدفع'),
      content: Column(mainAxisSize: MainAxisSize.min, children: [
        Text('$amount ر.ي',
            style: const TextStyle(fontSize: 26, fontWeight: FontWeight.bold, color: AmyalColors.primary)),
        const SizedBox(height: 8),
        Text('إلى: ${requester['name'] ?? 'تاجر'}', style: const TextStyle(fontSize: 14)),
        if (req['note'] != null) ...[
          const SizedBox(height: 4),
          Text('${req['note']}', style: const TextStyle(fontSize: 12, color: AmyalColors.textMuted)),
        ],
        const SizedBox(height: 8),
        const Text('سيُخصم المبلغ من محفظتك فوراً.',
            style: TextStyle(fontSize: 11, color: AmyalColors.textMuted)),
      ]),
      actions: [
        TextButton(onPressed: () => Get.back(result: false), child: const Text('إلغاء')),
        FilledButton(onPressed: () => Get.back(result: true), child: const Text('ادفع الآن')),
      ],
    ));
    if (confirm != true || !mounted) return;

    final ok = await pr.pay(code);
    if (!mounted) return;
    if (ok) {
      await Get.dialog(AlertDialog(
        icon: const Icon(Icons.check_circle, color: Colors.green, size: 48),
        title: const Text('تم الدفع', textAlign: TextAlign.center),
        content: Text('تم دفع $amount ر.ي بنجاح. ستجد الإيصال في قائمة الإيصالات.',
            textAlign: TextAlign.center),
        actions: [
          FilledButton(onPressed: () { Get.back(); Get.back(); }, child: const Text('تم')),
        ],
      ));
    } else {
      _err(pr.lastError.value.isEmpty ? 'فشل الدفع' : pr.lastError.value);
    }
  }

  void _err(String m) => ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(content: Text(m), backgroundColor: AmyalColors.red));

  void _refreshQuote() {
    final amount = _amountCtrl.text.trim();
    if (amount.isNotEmpty) {
      Get.find<MerchantPayController>().getQuote(amount: amount, channel: widget.channel);
    }
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;
    final ctrl = Get.find<MerchantPayController>();

    // AMYAL-DS-001: ورقة النتيجة الموحّدة (جارٍ التنفيذ → نجاح/فشل) بدل
    // AlertDialog + SnackBar المتفرّقين — نفس النمط عبر كل العمليات المالية.
    final done = await AmialResultSheet.run<bool>(
      context,
      processingTitle: 'جارٍ تنفيذ الدفع',
      successTitle: 'تم الدفع بنجاح',
      successSubtitle: 'ستجد الإيصال في قائمة الإيصالات',
      successButton: 'تم',
      errorMessage: (_) =>
          ctrl.lastError.value.isNotEmpty ? ctrl.lastError.value : 'فشل الدفع',
      action: () async {
        final ok = await ctrl.pay(
          merchantPhone:
              widget.merchantUserId == null ? _phoneCtrl.text.trim() : null,
          merchantUserId: widget.merchantUserId,
          amount: _amountCtrl.text.trim(),
          channel: widget.channel,
          posUserId: widget.posUserId,
          note: _noteCtrl.text.trim(),
        );
        if (!ok) {
          throw Exception(ctrl.lastError.value.isNotEmpty
              ? ctrl.lastError.value
              : 'فشل الدفع');
        }
        return true;
      },
    );

    if (!mounted) return;
    if (done == true) {
      Navigator.of(context).pop(); // أغلق شاشة الدفع بعد النجاح
    }
  }

  @override
  Widget build(BuildContext context) {
    final ctrl = Get.find<MerchantPayController>();

    return Scaffold(
      backgroundColor: AmyalColors.background,
      appBar: AppBar(
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
                        AmialMoney.yer(ctrl.quoteMerchantReceives.value),
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
