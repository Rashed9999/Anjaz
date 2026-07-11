import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/features/merchant/controllers/merchant_controller.dart';
import 'package:amyal_pay/features/shared/widgets/qr_widgets.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';

/// AMIAL-MERCHANT-APP-001 (v1.6)
///
/// استلام دفعة: التاجر يدخل المبلغ ويطلب دفعة من العميل.
/// يمكن إدخال رقم العميل مباشرة، أو توليد طلب دفع يشاركه (QR لاحقاً).
class MerchantAcceptPaymentScreen extends StatefulWidget {
  const MerchantAcceptPaymentScreen({super.key});

  @override
  State<MerchantAcceptPaymentScreen> createState() =>
      _MerchantAcceptPaymentScreenState();
}

class _MerchantAcceptPaymentScreenState
    extends State<MerchantAcceptPaymentScreen> {
  final _formKey = GlobalKey<FormState>();
  final _amountCtrl = TextEditingController();
  final _phoneCtrl = TextEditingController();
  final _noteCtrl = TextEditingController();
  bool _directPhone = false;

  @override
  void dispose() {
    _amountCtrl.dispose();
    _phoneCtrl.dispose();
    _noteCtrl.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;

    final ctrl = Get.find<MerchantController>();
    final success = await ctrl.requestPayment(
      amount: _amountCtrl.text.trim(),
      note: _noteCtrl.text.trim().isEmpty ? null : _noteCtrl.text.trim(),
      customerPhone:
          _directPhone && _phoneCtrl.text.trim().isNotEmpty ? _phoneCtrl.text.trim() : null,
    );

    if (!mounted) return;
    if (success) {
      _showSuccessSheet(ctrl);
    } else {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
            content: Text(ctrl.lastError.value),
            backgroundColor: AmyalColors.red),
      );
    }
  }

  void _showSuccessSheet(MerchantController ctrl) {
    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(16)),
      ),
      builder: (ctx) => Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Icon(Icons.qr_code_2, size: 80, color: AmyalColors.primary),
            const SizedBox(height: 16),
            const Text('تم إنشاء طلب الدفع',
                style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold)),
            const SizedBox(height: 8),
            Text('المبلغ: ${ctrl.lastPaymentAmount.value} ر.ي',
                style: const TextStyle(
                    fontSize: 16, color: AmyalColors.primary)),
            const SizedBox(height: 16),
            if (_directPhone)
              const Text(
                'تم إرسال الطلب لرقم العميل. ينتظر موافقته من تطبيقه.',
                textAlign: TextAlign.center,
                style: TextStyle(fontSize: 13),
              )
            else
              Container(
                padding: const EdgeInsets.all(16),
                decoration: BoxDecoration(
                  color: AmyalColors.background,
                  borderRadius: BorderRadius.circular(12),
                ),
                child: Column(
                  children: [
                    // QR حقيقي يحمل بيانات طلب الدفع (AMIAL-QR-001 v1.8)
                    QrDisplayWidget(
                      data: 'amyalpay://pay?request_id=${ctrl.lastPaymentRequestId.value}'
                          '&amount=${ctrl.lastPaymentAmount.value}',
                      size: 180,
                    ),
                    const SizedBox(height: 8),
                    Text(
                      'رقم الطلب: ${ctrl.lastPaymentRequestId.value}',
                      style: const TextStyle(
                          fontSize: 11, color: AmyalColors.textMuted),
                    ),
                    const SizedBox(height: 4),
                    const Text(
                      'اطلب من العميل مسح الرمز للدفع',
                      style: TextStyle(fontSize: 12),
                    ),
                  ],
                ),
              ),
            const SizedBox(height: 20),
            SizedBox(
              width: double.infinity,
              child: ElevatedButton(
                onPressed: () {
                  Navigator.pop(ctx);
                  Get.back();
                },
                style: ElevatedButton.styleFrom(
                  backgroundColor: AmyalColors.primary,
                  foregroundColor: Colors.white,
                  padding: const EdgeInsets.symmetric(vertical: 14),
                ),
                child: const Text('تم'),
              ),
            ),
          ],
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmyalColors.background,
      appBar: AppBar(
        backgroundColor: AmyalColors.primary,
        foregroundColor: Colors.white,
        title: const Text('استلام دفعة'),
      ),
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(16),
        child: Form(
          key: _formKey,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              // ====== Amount ======
              TextFormField(
                controller: _amountCtrl,
                keyboardType:
                    const TextInputType.numberWithOptions(decimal: true),
                inputFormatters: [
                  FilteringTextInputFormatter.allow(RegExp(r'[\d.]')),
                ],
                style: const TextStyle(fontSize: 22, fontWeight: FontWeight.bold),
                textAlign: TextAlign.center,
                decoration: const InputDecoration(
                  labelText: 'المبلغ المطلوب (ر.ي) *',
                  border: OutlineInputBorder(),
                ),
                validator: (v) {
                  final n = double.tryParse(v ?? '');
                  if (n == null || n <= 0) return 'مبلغ غير صحيح';
                  return null;
                },
              ),
              const SizedBox(height: 16),

              // ====== Note ======
              TextFormField(
                controller: _noteCtrl,
                maxLength: 200,
                decoration: const InputDecoration(
                  labelText: 'وصف الدفعة (اختياري)',
                  prefixIcon: Icon(Icons.notes),
                  border: OutlineInputBorder(),
                ),
              ),

              // ====== Direct phone option ======
              CheckboxListTile(
                title: const Text('إرسال الطلب لرقم عميل محدد'),
                subtitle: const Text(
                    'بدلاً من توليد QR للمسح',
                    style: TextStyle(fontSize: 11)),
                value: _directPhone,
                activeColor: AmyalColors.primary,
                onChanged: (v) => setState(() => _directPhone = v ?? false),
                controlAffinity: ListTileControlAffinity.leading,
                dense: true,
              ),

              if (_directPhone)
                Padding(
                  padding: const EdgeInsets.only(top: 8),
                  child: TextFormField(
                    controller: _phoneCtrl,
                    keyboardType: TextInputType.phone,
                    decoration: const InputDecoration(
                      labelText: 'رقم جوال العميل *',
                      prefixIcon: Icon(Icons.phone),
                      border: OutlineInputBorder(),
                    ),
                    validator: (v) {
                      if (!_directPhone) return null;
                      return (v == null || v.length < 6) ? 'رقم غير صحيح' : null;
                    },
                  ),
                ),

              const SizedBox(height: 24),

              Obx(() {
                final ctrl = Get.find<MerchantController>();
                return ElevatedButton.icon(
                  onPressed: ctrl.isSubmitting.value ? null : _submit,
                  icon: ctrl.isSubmitting.value
                      ? const SizedBox(
                          width: 18, height: 18,
                          child: CircularProgressIndicator(
                              color: Colors.white, strokeWidth: 2))
                      : Icon(_directPhone ? Icons.send : Icons.qr_code),
                  label: Text(
                    _directPhone ? 'إرسال الطلب' : 'توليد رمز QR',
                    style: const TextStyle(
                        fontSize: 16, fontWeight: FontWeight.w600),
                  ),
                  style: ElevatedButton.styleFrom(
                    backgroundColor: AmyalColors.primary,
                    foregroundColor: Colors.white,
                    padding: const EdgeInsets.symmetric(vertical: 16),
                  ),
                );
              }),
            ],
          ),
        ),
      ),
    );
  }
}
