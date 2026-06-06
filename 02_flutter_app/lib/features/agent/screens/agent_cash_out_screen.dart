import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/features/agent/controllers/agent_controller.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';

/// AMIAL-AGENT-APP-001 (v1.6)
///
/// Cash-Out: العميل يأخذ كاش من الوكيل ويُخصم من محفظته الإلكترونية.
/// الوكيل يطلب من العميل تأكيد ⇒ العميل يوافق من تطبيقه ⇒ المال ينتقل ⇒ الوكيل يعطي الكاش.
class AgentCashOutScreen extends StatefulWidget {
  const AgentCashOutScreen({super.key});

  @override
  State<AgentCashOutScreen> createState() => _AgentCashOutScreenState();
}

class _AgentCashOutScreenState extends State<AgentCashOutScreen> {
  final _formKey = GlobalKey<FormState>();
  final _phoneCtrl = TextEditingController();
  final _amountCtrl = TextEditingController();
  final _noteCtrl = TextEditingController();

  @override
  void dispose() {
    _phoneCtrl.dispose();
    _amountCtrl.dispose();
    _noteCtrl.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;

    final confirm = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('إرسال طلب سحب'),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text('للعميل: ${_phoneCtrl.text}'),
            const SizedBox(height: 4),
            Text('المبلغ: ${_amountCtrl.text} ر.س',
                style: const TextStyle(
                    fontWeight: FontWeight.bold,
                    color: AmyalColors.primary,
                    fontSize: 16)),
            const SizedBox(height: 12),
            const Text(
              'سيستقبل العميل طلب الدفع في تطبيقه. عليه الموافقة قبل أن تعطيه الكاش.',
              style: TextStyle(fontSize: 12),
            ),
          ],
        ),
        actions: [
          TextButton(
              onPressed: () => Navigator.pop(ctx, false),
              child: const Text('إلغاء')),
          ElevatedButton(
            onPressed: () => Navigator.pop(ctx, true),
            style: ElevatedButton.styleFrom(backgroundColor: AmyalColors.primary),
            child: const Text('إرسال', style: TextStyle(color: Colors.white)),
          ),
        ],
      ),
    );

    if (confirm != true) return;

    final ctrl = Get.find<AgentController>();
    final success = await ctrl.requestCashOut(
      customerPhone: _phoneCtrl.text.trim(),
      amount: _amountCtrl.text.trim(),
      note: _noteCtrl.text.trim().isEmpty ? null : _noteCtrl.text.trim(),
    );

    if (!mounted) return;
    if (success) {
      showDialog(
        context: context,
        builder: (ctx) => AlertDialog(
          icon: const Icon(Icons.send, color: AmyalColors.primary, size: 56),
          title: const Text('تم الإرسال'),
          content: const Text(
            'تم إرسال الطلب للعميل. ينتظر موافقته. لا تعطيه الكاش حتى تنجح العملية في صفحة "عملياتي".',
            textAlign: TextAlign.center,
          ),
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
        backgroundColor: AmyalColors.primary,
        foregroundColor: Colors.white,
        title: const Text('سحب من العميل'),
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
                  color: const Color(0xFFEF4444).withOpacity(0.1),
                  borderRadius: BorderRadius.circular(8),
                ),
                child: const Row(
                  children: [
                    Icon(Icons.warning_amber, color: Color(0xFFEF4444)),
                    SizedBox(width: 8),
                    Expanded(
                      child: Text(
                        'العميل سيستلم طلب موافقة في تطبيقه. لا تُسلِّمه الكاش حتى ينجح الطلب.',
                        style: TextStyle(fontSize: 12),
                      ),
                    ),
                  ],
                ),
              ),
              const SizedBox(height: 20),

              TextFormField(
                controller: _phoneCtrl,
                keyboardType: TextInputType.phone,
                decoration: const InputDecoration(
                  labelText: 'رقم جوال العميل *',
                  hintText: '+967700000000',
                  prefixIcon: Icon(Icons.phone),
                  border: OutlineInputBorder(),
                ),
                validator: (v) =>
                    (v == null || v.length < 6) ? 'رقم غير صحيح' : null,
              ),
              const SizedBox(height: 16),

              TextFormField(
                controller: _amountCtrl,
                keyboardType: const TextInputType.numberWithOptions(decimal: true),
                inputFormatters: [
                  FilteringTextInputFormatter.allow(RegExp(r'[\d.]')),
                ],
                decoration: const InputDecoration(
                  labelText: 'المبلغ المطلوب (ر.س) *',
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
                controller: _noteCtrl,
                maxLength: 200,
                decoration: const InputDecoration(
                  labelText: 'ملاحظة للعميل (اختياري)',
                  prefixIcon: Icon(Icons.notes),
                  border: OutlineInputBorder(),
                ),
              ),
              const SizedBox(height: 20),

              Obx(() {
                final ctrl = Get.find<AgentController>();
                return ElevatedButton.icon(
                  onPressed: ctrl.isSubmitting.value ? null : _submit,
                  icon: ctrl.isSubmitting.value
                      ? const SizedBox(
                          width: 18, height: 18,
                          child: CircularProgressIndicator(
                              color: Colors.white, strokeWidth: 2))
                      : const Icon(Icons.send),
                  label: const Text('إرسال طلب السحب',
                      style: TextStyle(fontSize: 16, fontWeight: FontWeight.w600)),
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
