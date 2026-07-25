import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/features/merchant/controllers/merchant_controller.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';

/// AMIAL-MERCHANT-APP-001 (v1.6)
///
/// استرجاع: التاجر يسترجع مبلغ لعميل من عملية أصلية.
/// (يطابق AMIAL-MERCHANT-REFUND-001 — استرجاع وليس تحويل)
class MerchantRefundScreen extends StatefulWidget {
  const MerchantRefundScreen({super.key});

  @override
  State<MerchantRefundScreen> createState() => _MerchantRefundScreenState();
}

class _MerchantRefundScreenState extends State<MerchantRefundScreen> {
  final _formKey = GlobalKey<FormState>();
  final _txIdCtrl = TextEditingController();
  final _amountCtrl = TextEditingController();
  final _reasonCtrl = TextEditingController();

  @override
  void dispose() {
    _txIdCtrl.dispose();
    _amountCtrl.dispose();
    _reasonCtrl.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;

    final confirm = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('تأكيد الاسترجاع'),
        content: Text(
          'سيتم استرجاع ${_amountCtrl.text} ر.ي للعميل من العملية ${_txIdCtrl.text}.\n\n'
          'هذا الإجراء لا يمكن التراجع عنه.',
        ),
        actions: [
          TextButton(
              onPressed: () => Navigator.pop(ctx, false),
              child: const Text('إلغاء')),
          ElevatedButton(
            onPressed: () => Navigator.pop(ctx, true),
            style: ElevatedButton.styleFrom(backgroundColor: AmyalColors.red),
            child: const Text('استرجاع', style: TextStyle(color: Colors.white)),
          ),
        ],
      ),
    );

    if (confirm != true) return;

    final ctrl = Get.find<MerchantController>();
    final success = await ctrl.processRefund(
      originalTransactionId: _txIdCtrl.text.trim(),
      amount: _amountCtrl.text.trim(),
      reason: _reasonCtrl.text.trim().isEmpty ? null : _reasonCtrl.text.trim(),
    );

    if (!mounted) return;
    if (success) {
      showDialog(
        context: context,
        builder: (ctx) => AlertDialog(
          icon: const Icon(Icons.check_circle,
              color: Color(0xFF10B981), size: 56),
          title: const Text('تم الاسترجاع'),
          content: Text('تم استرجاع ${_amountCtrl.text} ر.ي للعميل',
              textAlign: TextAlign.center),
          actions: [
            TextButton(
              onPressed: () {
                Navigator.pop(ctx);
                Get.back();
              },
              child: const Text('حسناً'),
            ),
          ],
        ),
      );
    } else {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
            content: Text(ctrl.lastError.value),
            backgroundColor: AmyalColors.red),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmyalColors.background,
      appBar: AppBar(
        title: const Text('استرجاع مبلغ'),
      ),
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(16),
        child: Form(
          key: _formKey,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Container(
                padding: const EdgeInsets.all(12),
                decoration: BoxDecoration(
                  color: AmyalColors.yellow.withValues(alpha: 0.2),
                  borderRadius: BorderRadius.circular(8),
                ),
                child: const Row(
                  children: [
                    Icon(Icons.info_outline, color: AmyalColors.primary),
                    SizedBox(width: 8),
                    Expanded(
                      child: Text(
                        'الاسترجاع فقط من عملية أصلية ناجحة ولنفس العميل. لا يمكن إرسال مال لرقم جديد.',
                        style: TextStyle(fontSize: 12),
                      ),
                    ),
                  ],
                ),
              ),
              const SizedBox(height: 20),

              TextFormField(
                controller: _txIdCtrl,
                decoration: const InputDecoration(
                  labelText: 'رقم العملية الأصلية *',
                  prefixIcon: Icon(Icons.tag),
                  border: OutlineInputBorder(),
                ),
                validator: (v) =>
                    (v == null || v.isEmpty) ? 'مطلوب' : null,
              ),
              const SizedBox(height: 16),

              TextFormField(
                controller: _amountCtrl,
                keyboardType:
                    const TextInputType.numberWithOptions(decimal: true),
                inputFormatters: [
                  FilteringTextInputFormatter.allow(RegExp(r'[\d.]')),
                ],
                decoration: const InputDecoration(
                  labelText: 'مبلغ الاسترجاع (ر.ي) *',
                  prefixIcon: Icon(Icons.attach_money),
                  border: OutlineInputBorder(),
                ),
                validator: (v) {
                  final n = double.tryParse(v ?? '');
                  if (n == null || n <= 0) return 'مبلغ غير صحيح';
                  return null;
                },
              ),
              const SizedBox(height: 16),

              TextFormField(
                controller: _reasonCtrl,
                maxLength: 300,
                maxLines: 2,
                decoration: const InputDecoration(
                  labelText: 'سبب الاسترجاع (اختياري)',
                  prefixIcon: Icon(Icons.notes),
                  border: OutlineInputBorder(),
                ),
              ),
              const SizedBox(height: 20),

              Obx(() {
                final ctrl = Get.find<MerchantController>();
                return ElevatedButton.icon(
                  onPressed: ctrl.isSubmitting.value ? null : _submit,
                  icon: ctrl.isSubmitting.value
                      ? const SizedBox(
                          width: 18, height: 18,
                          child: CircularProgressIndicator(
                              color: Colors.white, strokeWidth: 2))
                      : const Icon(Icons.undo),
                  label: const Text('تنفيذ الاسترجاع',
                      style:
                          TextStyle(fontSize: 16, fontWeight: FontWeight.w600)),
                  style: ElevatedButton.styleFrom(
                    backgroundColor: AmyalColors.red,
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
