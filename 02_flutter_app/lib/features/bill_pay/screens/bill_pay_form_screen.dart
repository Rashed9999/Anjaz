import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/features/bill_pay/controllers/bill_pay_controller.dart';
import 'package:amyal_pay/features/bill_pay/domain/models/bill_pay_models.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';
import 'package:amyal_pay/helper/amial_money.dart';

/// AMIAL-BILL-PAY-001 (v0.9-D)
///
/// نموذج الدفع — يعرض المنتجات (لو fixed) أو حقل مبلغ متغير.
class BillPayFormScreen extends StatefulWidget {
  final AmyalBillProvider provider;
  final AmyalBillService service;
  const BillPayFormScreen({super.key, required this.provider, required this.service});

  @override
  State<BillPayFormScreen> createState() => _BillPayFormScreenState();
}

class _BillPayFormScreenState extends State<BillPayFormScreen> {
  final _formKey = GlobalKey<FormState>();
  final _subscriberCtrl = TextEditingController();
  final _amountCtrl = TextEditingController();
  AmyalBillProduct? _selectedProduct;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      final ctrl = Get.find<BillPayController>();
      ctrl.loadServiceProducts(widget.service.id);
      ctrl.prepareNewPayment(); // مهم: مفتاح idempotency جديد لكل عملية
    });
  }

  @override
  void dispose() {
    _subscriberCtrl.dispose();
    _amountCtrl.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;
    final ctrl = Get.find<BillPayController>();

    // لو fixed: المبلغ من المنتج
    String amount = _selectedProduct?.isFixed == true
        ? _selectedProduct!.fixedAmount!
        : _amountCtrl.text.trim();

    final ok = await ctrl.pay(
      serviceId: widget.service.id,
      productId: _selectedProduct?.id,
      subscriberAccount: _subscriberCtrl.text.trim(),
      amount: amount,
    );

    if (!mounted) return;
    final order = ctrl.lastOrder.value;

    if (ok && order != null) {
      showDialog(
        context: context,
        barrierDismissible: false,
        builder: (ctx) => AlertDialog(
          icon: Icon(
            order.isSuccess ? Icons.check_circle :
            order.isPending ? Icons.hourglass_top :
            Icons.error_outline,
            color: order.isSuccess ? Colors.green :
                    order.isPending ? Colors.orange :
                    AmyalColors.red,
            size: 56,
          ),
          title: Text(
            order.isSuccess ? 'تم الدفع بنجاح' :
            order.isPending ? 'العملية قيد المعالجة' :
            'فشل الدفع',
            textAlign: TextAlign.center,
          ),
          content: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(
                order.isSuccess ? 'تم خصم ${AmialMoney.fmt(order.totalDebited)} ر.ي من حسابك. ستجد الإيصال في قائمة الإيصالات.' :
                order.isPending ? 'العملية أُرسلت للمزود. ستحصل على تأكيد قريباً.' :
                'لم تنجح العملية. المبلغ أعيد لحسابك.',
                textAlign: TextAlign.center,
                style: const TextStyle(fontSize: 13),
              ),
              if (order.providerReference != null) ...[
                const SizedBox(height: 8),
                Container(
                  padding: const EdgeInsets.all(6),
                  decoration: BoxDecoration(
                    color: Colors.grey.shade100,
                    borderRadius: BorderRadius.circular(4),
                  ),
                  child: Text(
                    'مرجع: ${order.providerReference}',
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
                Navigator.pop(context); // العودة لصفحة المزودين
              },
              child: const Text('حسناً'),
            ),
          ],
        ),
      );
    } else {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(ctrl.lastError.value.isNotEmpty
              ? ctrl.lastError.value
              : 'فشل الدفع، حاول مرة أخرى'),
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
        title: Text(widget.service.displayNameAr),
      ),
      body: Obx(() {
        final ctrl = Get.find<BillPayController>();

        return SingleChildScrollView(
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
                  child: Row(
                    children: [
                      const Icon(Icons.info_outline, color: AmyalColors.primary, size: 18),
                      const SizedBox(width: 8),
                      Expanded(
                        child: Text(
                          widget.provider.displayNameAr,
                          style: const TextStyle(fontSize: 13, color: AmyalColors.primary),
                        ),
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: 16),

                TextFormField(
                  controller: _subscriberCtrl,
                  keyboardType: TextInputType.phone,
                  decoration: InputDecoration(
                    labelText: widget.service.serviceType == 'recharge'
                        ? 'رقم الجوال *'
                        : 'رقم الحساب *',
                    border: const OutlineInputBorder(),
                  ),
                  validator: (v) {
                    if (v == null || v.trim().length < 3) {
                      return 'رقم غير صحيح';
                    }
                    return null;
                  },
                ),
                const SizedBox(height: 16),

                // المنتجات (لو متاحة)
                if (ctrl.selectedServiceProducts.isNotEmpty) ...[
                  const Padding(
                    padding: EdgeInsets.only(bottom: 8),
                    child: Text('اختر الفئة:', style: TextStyle(fontWeight: FontWeight.w600)),
                  ),
                  Wrap(
                    spacing: 8,
                    runSpacing: 8,
                    children: ctrl.selectedServiceProducts.map((p) {
                      final isSelected = _selectedProduct?.id == p.id;
                      return ChoiceChip(
                        label: Text(p.name),
                        selected: isSelected,
                        onSelected: (s) {
                          setState(() {
                            _selectedProduct = s ? p : null;
                            if (p.isFixed && p.fixedAmount != null) {
                              _amountCtrl.text = p.fixedAmount!;
                            } else {
                              _amountCtrl.clear();
                            }
                          });
                        },
                        selectedColor: AmyalColors.primary,
                        labelStyle: TextStyle(
                          color: isSelected ? Colors.white : Colors.black87,
                          fontSize: 12,
                        ),
                      );
                    }).toList(),
                  ),
                  const SizedBox(height: 16),
                ],

                // المبلغ
                TextFormField(
                  controller: _amountCtrl,
                  keyboardType: const TextInputType.numberWithOptions(decimal: true),
                  enabled: _selectedProduct == null || _selectedProduct!.isVariable,
                  decoration: InputDecoration(
                    labelText: 'المبلغ (ر.ي) *',
                    border: const OutlineInputBorder(),
                    helperText: _selectedProduct?.isVariable == true &&
                            (_selectedProduct?.minAmount != null || _selectedProduct?.maxAmount != null)
                        ? 'بين ${_selectedProduct!.minAmount ?? '-'} و ${_selectedProduct!.maxAmount ?? '-'}'
                        : null,
                  ),
                  validator: (v) {
                    final n = double.tryParse(v ?? '');
                    if (n == null || n <= 0) return 'مبلغ غير صحيح';
                    if (_selectedProduct?.isVariable == true) {
                      final min = double.tryParse(_selectedProduct!.minAmount ?? '');
                      final max = double.tryParse(_selectedProduct!.maxAmount ?? '');
                      if (min != null && n < min) return 'أقل من الحد الأدنى';
                      if (max != null && n > max) return 'أكثر من الحد الأقصى';
                    }
                    return null;
                  },
                ),
                if (_selectedProduct != null) ...[
                  const SizedBox(height: 8),
                  Text(
                    'الرسوم: ${AmialMoney.fmt(_selectedProduct!.feeAmount)} ر.ي',
                    style: const TextStyle(fontSize: 12, color: AmyalColors.textSecondary),
                  ),
                ],

                const SizedBox(height: 24),

                ElevatedButton(
                  onPressed: ctrl.isSubmitting.value ? null : _submit,
                  style: ElevatedButton.styleFrom(
                    backgroundColor: AmyalColors.primary,
                    foregroundColor: Colors.white,
                    padding: const EdgeInsets.symmetric(vertical: 14),
                  ),
                  child: ctrl.isSubmitting.value
                      ? const SizedBox(
                          height: 20, width: 20,
                          child: CircularProgressIndicator(
                              color: Colors.white, strokeWidth: 2))
                      : const Text('تأكيد الدفع',
                          style: TextStyle(fontSize: 16, fontWeight: FontWeight.w600)),
                ),
              ],
            ),
          ),
        );
      }),
    );
  }
}
