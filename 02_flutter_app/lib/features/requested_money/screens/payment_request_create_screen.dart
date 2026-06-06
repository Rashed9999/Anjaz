import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';
import 'package:amyal_pay/features/requested_money/controllers/payment_request_controller.dart';
import 'package:amyal_pay/features/requested_money/screens/payment_request_show_screen.dart';

/// AMIAL-PAYMENT-REQUESTS-001 — شاشة إنشاء طلب أموال.
class PaymentRequestCreateScreen extends StatefulWidget {
  const PaymentRequestCreateScreen({super.key});

  @override
  State<PaymentRequestCreateScreen> createState() => _PaymentRequestCreateScreenState();
}

class _PaymentRequestCreateScreenState extends State<PaymentRequestCreateScreen> {
  late final PaymentRequestController c;
  final _amount = TextEditingController();
  final _recipientPhone = TextEditingController();
  final _recipientName = TextEditingController();
  final _note = TextEditingController();
  String _shareMethod = 'link';
  bool _isRecurring = false;
  String _period = 'monthly';

  @override
  void initState() {
    super.initState();
    c = Get.find<PaymentRequestController>();
  }

  @override
  void dispose() {
    _amount.dispose();
    _recipientPhone.dispose();
    _recipientName.dispose();
    _note.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (_amount.text.trim().isEmpty) {
      _snack('أدخل المبلغ');
      return;
    }
    final amount = double.tryParse(_amount.text) ?? 0;
    if (amount <= 0) {
      _snack('المبلغ يجب أن يكون أكبر من صفر');
      return;
    }
    final ok = await c.create(
      amount: _amount.text.trim(),
      recipientPhone: _recipientPhone.text.trim().isEmpty ? null : _recipientPhone.text.trim(),
      recipientName: _recipientName.text.trim().isEmpty ? null : _recipientName.text.trim(),
      note: _note.text.trim().isEmpty ? null : _note.text.trim(),
      shareMethod: _shareMethod,
      isRecurring: _isRecurring,
      recurringPeriod: _isRecurring ? _period : null,
    );
    if (!mounted) return;
    if (ok) {
      Get.off(() => const PaymentRequestShowScreen());
    } else {
      _snack(c.lastError.value.isEmpty ? 'فشل إنشاء الطلب' : c.lastError.value);
    }
  }

  void _snack(String m) => ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(m), backgroundColor: AmyalColors.red),
      );

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmyalColors.background,
      appBar: AppBar(
        title: const Text('طلب أموال'),
        backgroundColor: AmyalColors.primary,
        foregroundColor: Colors.white,
      ),
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(16),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          // المبلغ
          const Text('المبلغ المطلوب', textAlign: TextAlign.right,
              style: TextStyle(fontSize: 14, fontWeight: FontWeight.w600)),
          const SizedBox(height: 6),
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 4),
            decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(12)),
            child: Row(children: [
              const Text('ر.ي', style: TextStyle(fontSize: 16, color: Colors.grey, fontWeight: FontWeight.bold)),
              const SizedBox(width: 12),
              Expanded(child: TextField(
                controller: _amount,
                keyboardType: TextInputType.number,
                textAlign: TextAlign.right,
                inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                style: const TextStyle(fontSize: 28, fontWeight: FontWeight.bold, color: AmyalColors.primary),
                decoration: const InputDecoration(border: InputBorder.none, hintText: '0'),
              )),
            ]),
          ),
          const SizedBox(height: 20),

          // المستلم (اختياري)
          const Text('المستلم (اختياري)', textAlign: TextAlign.right,
              style: TextStyle(fontSize: 14, fontWeight: FontWeight.w600)),
          const SizedBox(height: 6),
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 12),
            decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(12)),
            child: Column(children: [
              TextField(
                controller: _recipientPhone,
                keyboardType: TextInputType.phone,
                textAlign: TextAlign.right,
                decoration: const InputDecoration(
                  border: InputBorder.none,
                  hintText: 'رقم الهاتف +967...',
                  prefixIcon: Icon(Icons.phone, size: 20),
                ),
              ),
              const Divider(height: 1),
              TextField(
                controller: _recipientName,
                textAlign: TextAlign.right,
                decoration: const InputDecoration(
                  border: InputBorder.none,
                  hintText: 'اسم المستلم',
                  prefixIcon: Icon(Icons.person, size: 20),
                ),
              ),
            ]),
          ),
          const SizedBox(height: 6),
          Text(
            'اتركه فارغاً لجعل الطلب عاماً (أيّ شخص يفتح الرابط)',
            textAlign: TextAlign.right,
            style: TextStyle(fontSize: 11, color: Colors.grey.shade600),
          ),
          const SizedBox(height: 20),

          // الملاحظة
          const Text('ملاحظة', textAlign: TextAlign.right,
              style: TextStyle(fontSize: 14, fontWeight: FontWeight.w600)),
          const SizedBox(height: 6),
          TextField(
            controller: _note,
            textAlign: TextAlign.right,
            maxLines: 2,
            decoration: InputDecoration(
              hintText: 'مثال: فاتورة الكهرباء',
              filled: true,
              fillColor: Colors.white,
              border: OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: BorderSide.none),
            ),
          ),
          const SizedBox(height: 20),

          // طريقة المشاركة
          const Text('طريقة المشاركة', textAlign: TextAlign.right,
              style: TextStyle(fontSize: 14, fontWeight: FontWeight.w600)),
          const SizedBox(height: 6),
          Row(children: [
            Expanded(child: _methodTile('link', Icons.link, 'رابط مباشر')),
            const SizedBox(width: 8),
            Expanded(child: _methodTile('qr', Icons.qr_code_2, 'رمز QR')),
          ]),
          const SizedBox(height: 20),

          // التكرار
          SwitchListTile(
            tileColor: Colors.white,
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
            value: _isRecurring,
            onChanged: (v) => setState(() => _isRecurring = v),
            title: const Text('تكرار هذا الطلب', textAlign: TextAlign.right),
            subtitle: const Text('فواتير دورية', textAlign: TextAlign.right, style: TextStyle(fontSize: 12)),
            activeColor: AmyalColors.primary,
          ),
          if (_isRecurring) ...[
            const SizedBox(height: 8),
            Row(children: [
              Expanded(child: _periodChip('daily', 'يومي')),
              const SizedBox(width: 8),
              Expanded(child: _periodChip('weekly', 'أسبوعي')),
              const SizedBox(width: 8),
              Expanded(child: _periodChip('monthly', 'شهري')),
            ]),
          ],
          const SizedBox(height: 24),

          // زر الإنشاء
          Obx(() => FilledButton.icon(
            onPressed: c.isSubmitting.value ? null : _submit,
            icon: c.isSubmitting.value
                ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                : const Icon(Icons.send),
            label: const Text('إنشاء الطلب', style: TextStyle(fontSize: 16)),
            style: FilledButton.styleFrom(
              backgroundColor: AmyalColors.primary,
              minimumSize: const Size.fromHeight(52),
            ),
          )),
        ]),
      ),
    );
  }

  Widget _methodTile(String value, IconData icon, String label) {
    final selected = _shareMethod == value;
    return InkWell(
      borderRadius: BorderRadius.circular(12),
      onTap: () => setState(() => _shareMethod = value),
      child: Container(
        padding: const EdgeInsets.symmetric(vertical: 14),
        decoration: BoxDecoration(
          color: selected ? AmyalColors.primary : Colors.white,
          borderRadius: BorderRadius.circular(12),
          border: Border.all(color: selected ? AmyalColors.primary : Colors.grey.shade300, width: selected ? 2 : 1),
        ),
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          Icon(icon, color: selected ? Colors.white : AmyalColors.primary),
          const SizedBox(height: 4),
          Text(label, style: TextStyle(color: selected ? Colors.white : Colors.black87, fontWeight: FontWeight.bold)),
        ]),
      ),
    );
  }

  Widget _periodChip(String value, String label) {
    final selected = _period == value;
    return InkWell(
      borderRadius: BorderRadius.circular(20),
      onTap: () => setState(() => _period = value),
      child: Container(
        padding: const EdgeInsets.symmetric(vertical: 10),
        decoration: BoxDecoration(
          color: selected ? AmyalColors.yellow : Colors.white,
          borderRadius: BorderRadius.circular(20),
          border: Border.all(color: selected ? AmyalColors.yellow : Colors.grey.shade300),
        ),
        child: Text(label, textAlign: TextAlign.center,
            style: TextStyle(color: Colors.black87, fontWeight: selected ? FontWeight.bold : FontWeight.normal)),
      ),
    );
  }
}
