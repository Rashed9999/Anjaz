import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/features/merchant/controllers/split_bill_controller.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';

/// AMIAL-SPLIT-BILL-001 — التاجر/POS ينشئ فاتورة مقسّمة.
class SplitBillCreateScreen extends StatefulWidget {
  const SplitBillCreateScreen({super.key});

  @override
  State<SplitBillCreateScreen> createState() => _SplitBillCreateScreenState();
}

class _SplitBillCreateScreenState extends State<SplitBillCreateScreen> {
  final _formKey = GlobalKey<FormState>();
  final _amountCtrl = TextEditingController();
  final _noteCtrl = TextEditingController();
  final List<TextEditingController> _phoneCtrls = [
    TextEditingController(),
    TextEditingController(),
  ];

  @override
  void dispose() {
    _amountCtrl.dispose();
    _noteCtrl.dispose();
    for (final c in _phoneCtrls) {
      c.dispose();
    }
    super.dispose();
  }

  void _addParticipant() => setState(() => _phoneCtrls.add(TextEditingController()));

  void _removeParticipant(int i) {
    if (_phoneCtrls.length <= 2) return; // مشاركان على الأقل
    setState(() {
      _phoneCtrls[i].dispose();
      _phoneCtrls.removeAt(i);
    });
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;

    final phones = _phoneCtrls.map((c) => c.text.trim()).where((p) => p.isNotEmpty).toList();
    if (phones.toSet().length != phones.length) {
      _snack('لا يمكن تكرار رقم داخل نفس الفاتورة');
      return;
    }
    if (phones.length < 2) {
      _snack('أضف مشاركَين على الأقل');
      return;
    }

    final ctrl = Get.find<SplitBillController>();
    final ok = await ctrl.createSplit(
      totalAmount: _amountCtrl.text.trim(),
      participants: phones,
      note: _noteCtrl.text.trim(),
    );

    if (!mounted) return;
    if (ok) {
      _showResult(ctrl.createdBill.value ?? {});
    } else {
      _snack(ctrl.lastError.value.isNotEmpty ? ctrl.lastError.value : 'فشل إنشاء الفاتورة');
    }
  }

  void _snack(String msg) {
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(content: Text(msg), backgroundColor: AmyalColors.red),
    );
  }

  void _showResult(Map<String, dynamic> bill) {
    final participants = (bill['participants'] ?? []) as List;
    showDialog(
      context: context,
      builder: (ctx) => AlertDialog(
        icon: const Icon(Icons.receipt_long, color: AmyalColors.primary, size: 48),
        title: const Text('تم إنشاء الفاتورة المقسّمة', textAlign: TextAlign.center),
        content: SizedBox(
          width: double.maxFinite,
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Text('الإجمالي: ${bill['total_amount'] ?? ''} ر.ي',
                  style: const TextStyle(fontWeight: FontWeight.bold)),
              const SizedBox(height: 8),
              const Text('أُرسل لكل مشارك طلب دفع بحصته:',
                  style: TextStyle(fontSize: 12, color: AmyalColors.textSecondary)),
              const SizedBox(height: 8),
              ...participants.map((p) => ListTile(
                    dense: true,
                    leading: const Icon(Icons.person_outline, size: 20),
                    title: Text((p['customer_phone'] ?? '').toString(), style: const TextStyle(fontSize: 13)),
                    trailing: Text('${p['share_amount'] ?? ''} ر.ي',
                        style: const TextStyle(fontWeight: FontWeight.bold, color: AmyalColors.primary)),
                  )),
            ],
          ),
        ),
        actions: [
          TextButton(
            onPressed: () {
              Navigator.pop(ctx);
              Navigator.pop(context);
            },
            child: const Text('تم'),
          ),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final ctrl = Get.find<SplitBillController>();

    return Scaffold(
      backgroundColor: AmyalColors.background,
      appBar: AppBar(
        backgroundColor: AmyalColors.primary,
        foregroundColor: Colors.white,
        title: const Text('تقسيم فاتورة'),
      ),
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(16),
        child: Form(
          key: _formKey,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              TextFormField(
                controller: _amountCtrl,
                keyboardType: const TextInputType.numberWithOptions(decimal: true),
                decoration: const InputDecoration(
                  labelText: 'إجمالي المبلغ',
                  prefixIcon: Icon(Icons.payments),
                  border: OutlineInputBorder(),
                ),
                validator: (v) {
                  final d = double.tryParse((v ?? '').trim());
                  if (d == null || d <= 0) return 'أدخل مبلغاً صحيحاً';
                  return null;
                },
              ),
              const SizedBox(height: 16),
              Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  const Text('المشاركون', style: TextStyle(fontWeight: FontWeight.bold)),
                  TextButton.icon(
                    onPressed: _addParticipant,
                    icon: const Icon(Icons.add, size: 18),
                    label: const Text('إضافة'),
                  ),
                ],
              ),
              const SizedBox(height: 4),
              ...List.generate(_phoneCtrls.length, (i) => Padding(
                    padding: const EdgeInsets.only(bottom: 8),
                    child: Row(
                      children: [
                        Expanded(
                          child: TextFormField(
                            controller: _phoneCtrls[i],
                            keyboardType: TextInputType.phone,
                            decoration: InputDecoration(
                              labelText: 'رقم المشارك ${i + 1}',
                              prefixIcon: const Icon(Icons.person_outline),
                              border: const OutlineInputBorder(),
                            ),
                            validator: (v) =>
                                (v == null || v.trim().length < 6) ? 'رقم غير صحيح' : null,
                          ),
                        ),
                        if (_phoneCtrls.length > 2)
                          IconButton(
                            onPressed: () => _removeParticipant(i),
                            icon: const Icon(Icons.remove_circle_outline, color: AmyalColors.red),
                          ),
                      ],
                    ),
                  )),
              const SizedBox(height: 8),
              TextFormField(
                controller: _noteCtrl,
                decoration: const InputDecoration(
                  labelText: 'ملاحظة (اختياري)',
                  prefixIcon: Icon(Icons.note_alt_outlined),
                  border: OutlineInputBorder(),
                ),
              ),
              const SizedBox(height: 20),
              Obx(() => ElevatedButton(
                    onPressed: ctrl.isCreating.value ? null : _submit,
                    style: ElevatedButton.styleFrom(
                      backgroundColor: AmyalColors.primary,
                      foregroundColor: Colors.white,
                      padding: const EdgeInsets.symmetric(vertical: 14),
                    ),
                    child: ctrl.isCreating.value
                        ? const SizedBox(
                            height: 20, width: 20,
                            child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                        : const Text('إنشاء الفاتورة وتقسيمها', style: TextStyle(fontSize: 16)),
                  )),
            ],
          ),
        ),
      ),
    );
  }
}
