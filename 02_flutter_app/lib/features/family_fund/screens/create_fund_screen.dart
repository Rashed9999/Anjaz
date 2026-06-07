import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/features/family_fund/controllers/funds_controller.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';

/// AMIAL-FUND-FAMILY-001 (v0.9-D)
class CreateFundScreen extends StatefulWidget {
  const CreateFundScreen({super.key});

  @override
  State<CreateFundScreen> createState() => _CreateFundScreenState();
}

class _CreateFundScreenState extends State<CreateFundScreen> {
  final _formKey = GlobalKey<FormState>();
  final _nameCtrl = TextEditingController();
  final _descCtrl = TextEditingController();
  bool _requireApproval = true;

  @override
  void dispose() {
    _nameCtrl.dispose();
    _descCtrl.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;
    final ok = await Get.find<FundsController>().createFund(
      name: _nameCtrl.text.trim(),
      description: _descCtrl.text.trim().isNotEmpty ? _descCtrl.text.trim() : null,
      requireOwnerApproval: _requireApproval,
    );
    if (!mounted) return;
    if (ok) {
      Navigator.pop(context, true);
    } else {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(Get.find<FundsController>().lastError.value),
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
        title: const Text('صندوق عائلي جديد'),
      ),
      body: Obx(() {
        final ctrl = Get.find<FundsController>();
        return SingleChildScrollView(
          padding: const EdgeInsets.all(16),
          child: Form(
            key: _formKey,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                const Text(
                  'ابدأ صندوقاً مشتركاً للعائلة أو الأصدقاء',
                  style: TextStyle(fontSize: 16, fontWeight: FontWeight.w600),
                ),
                const SizedBox(height: 8),
                const Text(
                  'سيتم تعيينك كـ owner، ويمكنك دعوة الأعضاء بأرقامهم.',
                  style: TextStyle(color: AmyalColors.textSecondary, fontSize: 12),
                ),
                const SizedBox(height: 24),

                TextFormField(
                  controller: _nameCtrl,
                  maxLength: 100,
                  decoration: const InputDecoration(
                    labelText: 'اسم الصندوق *',
                    hintText: 'مثال: عائلة المحمدي',
                    border: OutlineInputBorder(),
                  ),
                  validator: (v) {
                    if (v == null || v.trim().length < 3) {
                      return 'الاسم 3 أحرف على الأقل';
                    }
                    return null;
                  },
                ),
                const SizedBox(height: 12),

                TextFormField(
                  controller: _descCtrl,
                  maxLines: 3,
                  maxLength: 500,
                  decoration: const InputDecoration(
                    labelText: 'الوصف (اختياري)',
                    hintText: 'لماذا هذا الصندوق؟',
                    border: OutlineInputBorder(),
                    alignLabelWithHint: true,
                  ),
                ),
                const SizedBox(height: 16),

                Card(
                  color: AmyalColors.yellow.withValues(alpha: 0.15),
                  child: SwitchListTile(
                    title: const Text('موافقة المالك للصرف'),
                    subtitle: const Text(
                      'يجب أن يوافق المالك على أي عملية صرف من الصندوق',
                      style: TextStyle(fontSize: 11),
                    ),
                    value: _requireApproval,
                    activeThumbColor: AmyalColors.primary,
                    onChanged: (v) => setState(() => _requireApproval = v),
                  ),
                ),

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
                      : const Text('إنشاء الصندوق',
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
