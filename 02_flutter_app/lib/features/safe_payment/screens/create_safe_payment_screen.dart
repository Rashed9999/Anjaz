import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/features/safe_payment/controllers/safe_payment_controller.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';

/// AMIAL-SAFE-PAYMENT-001 (v1.1)
class CreateSafePaymentScreen extends StatefulWidget {
  const CreateSafePaymentScreen({super.key});

  @override
  State<CreateSafePaymentScreen> createState() => _CreateSafePaymentScreenState();
}

class _CreateSafePaymentScreenState extends State<CreateSafePaymentScreen> {
  final _formKey = GlobalKey<FormState>();
  final _sellerPhoneCtrl = TextEditingController();
  final _titleCtrl = TextEditingController();
  final _descCtrl = TextEditingController();
  final _amountCtrl = TextEditingController();
  final _deliveryCtrl = TextEditingController();

  @override
  void dispose() {
    _sellerPhoneCtrl.dispose();
    _titleCtrl.dispose();
    _descCtrl.dispose();
    _amountCtrl.dispose();
    _deliveryCtrl.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;

    // تأكيد قبل الإنشاء (لأن المال سيُخصم مباشرة)
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        icon: const Icon(Icons.shield, color: AmyalColors.primary, size: 48),
        title: const Text('تأكيد العملية'),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(
              'سيتم خصم ${_amountCtrl.text} ر.ي من حسابك وحجزه حتى تأكيد الاستلام.',
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: 12),
            Container(
              padding: const EdgeInsets.all(8),
              decoration: BoxDecoration(
                color: AmyalColors.yellow.withValues(alpha: 0.2),
                borderRadius: BorderRadius.circular(6),
              ),
              child: const Text(
                'المال محجوز ولا يصل للبائع إلا بعد تأكيد استلامك للسلعة',
                style: TextStyle(fontSize: 11, color: AmyalColors.primary),
                textAlign: TextAlign.center,
              ),
            ),
          ],
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx, false),
            child: const Text('إلغاء'),
          ),
          ElevatedButton(
            onPressed: () => Navigator.pop(ctx, true),
            style: ElevatedButton.styleFrom(
              backgroundColor: AmyalColors.primary,
              foregroundColor: Colors.white,
            ),
            child: const Text('تأكيد ودفع'),
          ),
        ],
      ),
    );

    if (confirmed != true) return;

    final ok = await Get.find<SafePaymentController>().create(
      sellerPhone: _sellerPhoneCtrl.text.trim(),
      title: _titleCtrl.text.trim(),
      description: _descCtrl.text.trim(),
      amount: _amountCtrl.text.trim(),
      deliveryTerms: _deliveryCtrl.text.trim().isEmpty
          ? null
          : _deliveryCtrl.text.trim(),
    );

    if (!mounted) return;
    if (ok) {
      Navigator.pop(context, true);
    } else {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(Get.find<SafePaymentController>().lastError.value),
          backgroundColor: AmyalColors.red,
        ),
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
        title: const Text('طلب دفع آمن جديد'),
      ),
      body: Obx(() {
        final ctrl = Get.find<SafePaymentController>();
        return SingleChildScrollView(
          padding: const EdgeInsets.all(16),
          child: Form(
            key: _formKey,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                // ====== Info banner ======
                Container(
                  padding: const EdgeInsets.all(12),
                  decoration: BoxDecoration(
                    color: AmyalColors.yellow.withValues(alpha: 0.2),
                    borderRadius: BorderRadius.circular(8),
                    border: Border.all(color: AmyalColors.yellow),
                  ),
                  child: const Row(
                    children: [
                      Icon(Icons.shield, color: AmyalColors.primary, size: 24),
                      SizedBox(width: 12),
                      Expanded(
                        child: Text(
                          'حماية لأموالك: المبلغ محجوز حتى تستلم السلعة وتؤكد رضاك',
                          style: TextStyle(
                              fontSize: 12, color: AmyalColors.primary),
                        ),
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: 24),

                // ====== Seller phone ======
                TextFormField(
                  controller: _sellerPhoneCtrl,
                  keyboardType: TextInputType.phone,
                  decoration: const InputDecoration(
                    labelText: 'رقم جوال البائع *',
                    hintText: '+967700000000',
                    prefixIcon: Icon(Icons.person),
                    border: OutlineInputBorder(),
                  ),
                  validator: (v) {
                    if (v == null || v.trim().length < 6) return 'رقم غير صحيح';
                    return null;
                  },
                ),
                const SizedBox(height: 12),

                // ====== Title ======
                TextFormField(
                  controller: _titleCtrl,
                  maxLength: 200,
                  decoration: const InputDecoration(
                    labelText: 'عنوان السلعة/الخدمة *',
                    hintText: 'مثال: iPhone 14 Pro Max',
                    prefixIcon: Icon(Icons.shopping_bag),
                    border: OutlineInputBorder(),
                  ),
                  validator: (v) {
                    if (v == null || v.trim().length < 3) {
                      return 'العنوان 3 أحرف على الأقل';
                    }
                    return null;
                  },
                ),

                // ====== Description ======
                TextFormField(
                  controller: _descCtrl,
                  maxLines: 4,
                  maxLength: 5000,
                  decoration: const InputDecoration(
                    labelText: 'الوصف التفصيلي *',
                    hintText: 'اللون، الحالة، الكمية، أي تفاصيل مهمة...',
                    border: OutlineInputBorder(),
                    alignLabelWithHint: true,
                  ),
                  validator: (v) {
                    if (v == null || v.trim().length < 10) {
                      return 'الوصف 10 أحرف على الأقل';
                    }
                    return null;
                  },
                ),

                // ====== Amount ======
                TextFormField(
                  controller: _amountCtrl,
                  keyboardType:
                      const TextInputType.numberWithOptions(decimal: true),
                  decoration: const InputDecoration(
                    labelText: 'المبلغ (ر.ي) *',
                    prefixIcon: Icon(Icons.payments),
                    border: OutlineInputBorder(),
                  ),
                  validator: (v) {
                    final n = double.tryParse(v ?? '');
                    if (n == null || n < 1) return 'الحد الأدنى 1 ر.ي';
                    if (n > 100000) return 'الحد الأقصى 100,000 ر.ي';
                    return null;
                  },
                ),
                const SizedBox(height: 12),

                // ====== Delivery terms ======
                TextFormField(
                  controller: _deliveryCtrl,
                  maxLines: 3,
                  maxLength: 5000,
                  decoration: const InputDecoration(
                    labelText: 'شروط التسليم (اختياري)',
                    hintText: 'موعد التسليم، الموقع، طريقة الشحن...',
                    border: OutlineInputBorder(),
                    alignLabelWithHint: true,
                  ),
                ),

                const SizedBox(height: 24),

                // ====== Submit ======
                ElevatedButton.icon(
                  onPressed: ctrl.isSubmitting.value ? null : _submit,
                  icon: ctrl.isSubmitting.value
                      ? const SizedBox(
                          height: 16, width: 16,
                          child: CircularProgressIndicator(
                              color: Colors.white, strokeWidth: 2))
                      : const Icon(Icons.shield_outlined),
                  label: const Text('إنشاء + حجز المبلغ',
                      style: TextStyle(fontSize: 15, fontWeight: FontWeight.w600)),
                  style: ElevatedButton.styleFrom(
                    backgroundColor: AmyalColors.primary,
                    foregroundColor: Colors.white,
                    padding: const EdgeInsets.symmetric(vertical: 14),
                  ),
                ),

                const SizedBox(height: 12),
                const Center(
                  child: Text(
                    'يمكنك إلغاء الطلب وسحب أموالك قبل بدء التسليم',
                    style: TextStyle(fontSize: 11, color: AmyalColors.textMuted),
                  ),
                ),
              ],
            ),
          ),
        );
      }),
    );
  }
}
