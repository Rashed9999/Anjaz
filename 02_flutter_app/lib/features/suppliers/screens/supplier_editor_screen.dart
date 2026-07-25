import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/features/suppliers/controllers/suppliers_controller.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';

/// AMIAL-SUPPLIERS-003 — «إضافة مورد جديد» (التصميم 53):
/// المعلومات الأساسية (اسم/شخص التواصل/الفئة) + بيانات الاتصال
/// (هاتف/بريد/عنوان) + الرصيد الافتتاحي (دين سابق للمورد).
class SupplierEditorScreen extends StatefulWidget {
  const SupplierEditorScreen({super.key});

  @override
  State<SupplierEditorScreen> createState() => _SupplierEditorScreenState();
}

class _SupplierEditorScreenState extends State<SupplierEditorScreen> {
  SuppliersController get c => Get.find<SuppliersController>();

  final _name = TextEditingController();
  final _contact = TextEditingController();
  final _phone = TextEditingController();
  final _email = TextEditingController();
  final _address = TextEditingController();
  final _opening = TextEditingController();

  static const _categories = [
    'مواد غذائية', 'مشروبات', 'إلكترونيات', 'أدوية', 'وقود وزيوت', 'أخرى',
  ];
  String _category = _categories.first;
  bool _saving = false;

  @override
  void dispose() {
    for (final t in [_name, _contact, _phone, _email, _address, _opening]) {
      t.dispose();
    }
    super.dispose();
  }

  Future<void> _save() async {
    if (_name.text.trim().isEmpty) {
      _snack('أدخل اسم المورد');
      return;
    }
    setState(() => _saving = true);
    final ok = await c.createSupplier({
      'name': _name.text.trim(),
      if (_contact.text.trim().isNotEmpty)
        'contact_person': _contact.text.trim(),
      if (_phone.text.trim().isNotEmpty) 'phone': _phone.text.trim(),
      if (_email.text.trim().isNotEmpty) 'email': _email.text.trim(),
      if (_address.text.trim().isNotEmpty) 'address': _address.text.trim(),
      'category': _category,
      if ((double.tryParse(_opening.text.trim()) ?? 0) > 0)
        'opening_balance': _opening.text.trim(),
    });
    if (!mounted) return;
    setState(() => _saving = false);
    if (ok) {
      Get.back(result: true);
    } else {
      _snack(c.lastError.value.isEmpty ? 'فشل الحفظ' : c.lastError.value);
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
        title: const Text('إضافة مورد جديد'),
      ),
      bottomNavigationBar: SafeArea(
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: FilledButton.icon(
            onPressed: _saving ? null : _save,
            icon: _saving
                ? const SizedBox(
                    height: 18,
                    width: 18,
                    child: CircularProgressIndicator(
                        strokeWidth: 2, color: Colors.white))
                : const Icon(Icons.save_outlined),
            label: const Text('حفظ المورد',
                style: TextStyle(fontSize: 16, fontWeight: FontWeight.w600)),
            style: FilledButton.styleFrom(
              backgroundColor: AmyalColors.primary,
              minimumSize: const Size.fromHeight(54),
              shape: RoundedRectangleBorder(
                  borderRadius: BorderRadius.circular(16)),
            ),
          ),
        ),
      ),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          const Text(
            'أدخل تفاصيل المورد لبدء التعاملات المالية وإدارة المخزون.',
            textAlign: TextAlign.center,
            style: TextStyle(fontSize: 12, color: AmyalColors.textSecondary),
          ),
          const SizedBox(height: 14),

          _section(Icons.business_outlined, 'المعلومات الأساسية', [
            _field(_name, 'اسم المورد / الشركة', 'مثال: شركة الخليج للتجارة'),
            _field(_contact, 'شخص التواصل', 'الاسم الكامل للمندوب'),
            const SizedBox(height: 6),
            const Align(
              alignment: Alignment.centerRight,
              child: Text('الفئة',
                  style: TextStyle(
                      fontSize: 12, color: AmyalColors.textSecondary)),
            ),
            const SizedBox(height: 6),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: _categories.map((cat) {
                final selected = _category == cat;
                return ChoiceChip(
                  label: Text(cat, style: const TextStyle(fontSize: 12)),
                  selected: selected,
                  selectedColor: AmyalColors.primary,
                  backgroundColor: const Color(0xFFF0F1F3),
                  labelStyle: TextStyle(
                      color: selected ? Colors.white : Colors.black87),
                  onSelected: (_) => setState(() => _category = cat),
                );
              }).toList(),
            ),
          ]),
          const SizedBox(height: 14),

          _section(Icons.contact_phone_outlined, 'بيانات الاتصال', [
            _field(_phone, 'رقم الهاتف', '77XXXXXXX',
                keyboard: TextInputType.phone),
            _field(_email, 'البريد الإلكتروني', 'sample@mail.com',
                keyboard: TextInputType.emailAddress),
            _field(_address, 'العنوان بالتفصيل',
                'المدينة، الشارع، المعلم القريب',
                lines: 2),
          ]),
          const SizedBox(height: 14),

          _section(Icons.account_balance_wallet_outlined, 'الرصيد الافتتاحي', [
            _field(_opening, 'الرصيد السابق (دين للمورد)', '0',
                keyboard: TextInputType.number,
                formatters: [FilteringTextInputFormatter.digitsOnly]),
            const Text(
              'يُسجَّل كدين حالي عليك للمورد ويظهر في كشف حركاته.',
              textAlign: TextAlign.right,
              style: TextStyle(fontSize: 11, color: AmyalColors.textMuted),
            ),
          ]),
          const SizedBox(height: 80),
        ],
      ),
    );
  }

  Widget _section(IconData icon, String title, List<Widget> children) {
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(16),
      ),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Row(mainAxisAlignment: MainAxisAlignment.end, children: [
          Text(title,
              style:
                  const TextStyle(fontWeight: FontWeight.bold, fontSize: 14)),
          const SizedBox(width: 8),
          Icon(icon, size: 18, color: AmyalColors.yellowDark),
        ]),
        const SizedBox(height: 10),
        ...children,
      ]),
    );
  }

  Widget _field(TextEditingController ctrl, String label, String hint,
      {TextInputType? keyboard,
      int lines = 1,
      List<TextInputFormatter>? formatters}) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Text(label,
            textAlign: TextAlign.right,
            style: const TextStyle(
                fontSize: 12, color: AmyalColors.textSecondary)),
        const SizedBox(height: 6),
        TextField(
          controller: ctrl,
          keyboardType: keyboard,
          maxLines: lines,
          inputFormatters: formatters,
          textAlign: TextAlign.right,
          decoration: InputDecoration(
            hintText: hint,
            hintStyle:
                const TextStyle(fontSize: 13, color: AmyalColors.textMuted),
            filled: true,
            fillColor: const Color(0xFFF9FAFB),
            border: OutlineInputBorder(
              borderRadius: BorderRadius.circular(12),
              borderSide: const BorderSide(color: AmyalColors.border),
            ),
            enabledBorder: OutlineInputBorder(
              borderRadius: BorderRadius.circular(12),
              borderSide: const BorderSide(color: AmyalColors.border),
            ),
          ),
        ),
      ]),
    );
  }
}
