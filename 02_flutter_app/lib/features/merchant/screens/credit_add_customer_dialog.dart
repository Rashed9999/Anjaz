import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';
import 'package:amyal_pay/features/merchant/controllers/customer_credit_controller.dart';

/// AMIAL-CUSTOMER-CREDIT-001 — حوار إضافة عميل ائتماني.
class CreditAddCustomerDialog extends StatefulWidget {
  const CreditAddCustomerDialog({super.key});

  @override
  State<CreditAddCustomerDialog> createState() => _CreditAddCustomerDialogState();
}

class _CreditAddCustomerDialogState extends State<CreditAddCustomerDialog> {
  late final CustomerCreditController c;
  final _phoneCtrl = TextEditingController();
  final _nameCtrl = TextEditingController();
  final _limitCtrl = TextEditingController();

  @override
  void initState() {
    super.initState();
    c = Get.find<CustomerCreditController>();
  }

  @override
  void dispose() {
    _phoneCtrl.dispose();
    _nameCtrl.dispose();
    _limitCtrl.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      title: const Text('إضافة عميل', textAlign: TextAlign.right),
      content: SingleChildScrollView(
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          TextField(
            controller: _nameCtrl,
            textAlign: TextAlign.right,
            decoration: const InputDecoration(
              labelText: 'الاسم الكامل',
              prefixIcon: Icon(Icons.person),
            ),
          ),
          const SizedBox(height: 12),
          TextField(
            controller: _phoneCtrl,
            textAlign: TextAlign.right,
            keyboardType: TextInputType.phone,
            decoration: const InputDecoration(
              labelText: 'رقم الهاتف',
              hintText: '+967 7XX XXX XXX',
              prefixIcon: Icon(Icons.phone),
            ),
          ),
          const SizedBox(height: 12),
          TextField(
            controller: _limitCtrl,
            textAlign: TextAlign.right,
            keyboardType: TextInputType.number,
            decoration: const InputDecoration(
              labelText: 'حد الائتمان (اختياري — 0 يعني بلا حد)',
              prefixIcon: Icon(Icons.credit_card),
              suffix: Text('ر.ي'),
            ),
          ),
          const SizedBox(height: 8),
          Obx(() => c.lastError.value.isEmpty
              ? const SizedBox.shrink()
              : Padding(
                  padding: const EdgeInsets.only(top: 8),
                  child: Text(c.lastError.value, style: TextStyle(color: AmyalColors.red, fontSize: 12)),
                )),
        ]),
      ),
      actions: [
        TextButton(onPressed: () => Get.back(result: false), child: const Text('إلغاء')),
        Obx(() => FilledButton(
          onPressed: c.isSubmitting.value ? null : _submit,
          style: FilledButton.styleFrom(backgroundColor: AmyalColors.primary),
          child: c.isSubmitting.value
              ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
              : const Text('حفظ'),
        )),
      ],
    );
  }

  Future<void> _submit() async {
    if (_nameCtrl.text.trim().isEmpty || _phoneCtrl.text.trim().isEmpty) {
      Get.snackbar('تنبيه', 'الاسم ورقم الهاتف مطلوبان',
          backgroundColor: AmyalColors.red.withOpacity(0.1));
      return;
    }
    final data = <String, dynamic>{
      'name': _nameCtrl.text.trim(),
      'phone': _phoneCtrl.text.trim(),
    };
    if (_limitCtrl.text.trim().isNotEmpty) {
      data['credit_limit'] = _limitCtrl.text.trim();
    }
    final ok = await c.upsertCustomer(data);
    if (ok) Get.back(result: true);
  }
}
