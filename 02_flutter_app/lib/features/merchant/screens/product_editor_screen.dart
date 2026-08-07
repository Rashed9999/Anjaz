import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:get/get.dart';
import 'package:amial_pay/features/merchant/controllers/cashier_controller.dart';
import 'package:amial_pay/theme/amial_colors.dart';

/// AMIAL-INVENTORY-002 — «إضافة/تعديل منتج» (التصميم 45):
/// المعلومات الأساسية (اسم + باركود + فئة) → المخزون والتواريخ (عدّاد كمية +
/// إنتاج/انتهاء) → التسعير (تكلفة/بيع/عرض) → حفظ المنتج في المتجر.
class ProductEditorScreen extends StatefulWidget {
  const ProductEditorScreen({super.key, this.product});

  /// null = إضافة جديد؛ وإلا تعديل.
  final Map<String, dynamic>? product;

  @override
  State<ProductEditorScreen> createState() => _ProductEditorScreenState();
}

class _ProductEditorScreenState extends State<ProductEditorScreen> {
  CashierController get c => Get.find<CashierController>();

  late final TextEditingController _name;
  late final TextEditingController _barcode;
  late final TextEditingController _cost;
  late final TextEditingController _price;
  late final TextEditingController _offer;
  late final TextEditingController _customCategory;

  static const _presetCategories = [
    'مشروبات', 'مواد غذائية', 'إلكترونيات', 'أدوية', 'مستلزمات', 'أخرى',
  ];

  String _category = _presetCategories.first;
  int _qty = 1;
  DateTime? _production;
  DateTime? _expiry;
  bool _saving = false;

  bool get _isEdit => widget.product != null;

  @override
  void initState() {
    super.initState();
    final p = widget.product;
    _name = TextEditingController(text: p?['name']?.toString() ?? '');
    _barcode = TextEditingController(text: p?['barcode']?.toString() ?? '');
    _cost = TextEditingController(
        text: _numText(p?['cost_price']));
    _price = TextEditingController(text: _numText(p?['price']));
    _offer = TextEditingController(text: _numText(p?['offer_price']));
    _customCategory = TextEditingController();
    if (p != null) {
      _qty = (double.tryParse('${p['quantity'] ?? 1}') ?? 1).round();
      final cat = '${p['category'] ?? ''}'.trim();
      if (cat.isNotEmpty) {
        if (_presetCategories.contains(cat)) {
          _category = cat;
        } else {
          _category = 'أخرى';
          _customCategory.text = cat;
        }
      }
      _production = _parseDate(p['production_date']);
      _expiry = _parseDate(p['expiry_date']);
    }
  }

  String _numText(dynamic v) {
    final n = double.tryParse('${v ?? ''}');
    if (n == null || n == 0) return '';
    return n == n.roundToDouble() ? n.toStringAsFixed(0) : '$n';
  }

  DateTime? _parseDate(dynamic v) {
    if (v == null) return null;
    try {
      return DateTime.parse('$v');
    } catch (_) {
      return null;
    }
  }

  @override
  void dispose() {
    _name.dispose();
    _barcode.dispose();
    _cost.dispose();
    _price.dispose();
    _offer.dispose();
    _customCategory.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    final name = _name.text.trim();
    final price = double.tryParse(_price.text.trim()) ?? 0;
    if (name.isEmpty) {
      _snack('أدخل اسم المنتج');
      return;
    }
    if (price <= 0) {
      _snack('أدخل سعر بيع صحيحاً');
      return;
    }
    final cost = double.tryParse(_cost.text.trim());
    final offer = double.tryParse(_offer.text.trim());
    if (offer != null && offer >= price) {
      _snack('سعر العرض يجب أن يكون أقل من سعر البيع');
      return;
    }

    final category = _category == 'أخرى' && _customCategory.text.trim().isNotEmpty
        ? _customCategory.text.trim()
        : _category;

    final data = <String, dynamic>{
      'name': name,
      'price': price,
      'quantity': _qty,
      'category': category,
      if (_barcode.text.trim().isNotEmpty) 'barcode': _barcode.text.trim(),
      if (cost != null) 'cost_price': cost,
      'offer_price': offer, // null يمسح العرض عند التعديل
      if (_production != null)
        'production_date': _fmtDate(_production!),
      if (_expiry != null) 'expiry_date': _fmtDate(_expiry!),
    };

    setState(() => _saving = true);
    bool ok;
    if (_isEdit) {
      ok = await c.updateProduct(widget.product!['id'] as int, data);
    } else {
      ok = await c.addProduct(data);
    }
    if (!mounted) return;
    setState(() => _saving = false);
    if (ok) {
      Get.back(result: true);
    } else {
      _snack(c.lastError.value.isEmpty ? 'فشل الحفظ' : c.lastError.value);
    }
  }

  String _fmtDate(DateTime d) =>
      '${d.year}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';

  void _snack(String m) => ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(m), backgroundColor: AmialColors.red),
      );

  Future<void> _pickDate(bool production) async {
    final d = await showDatePicker(
      context: context,
      initialDate: (production ? _production : _expiry) ?? DateTime.now(),
      firstDate: DateTime(2020),
      lastDate: DateTime(2040),
    );
    if (d != null) {
      setState(() => production ? _production = d : _expiry = d);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(
        title: Text(_isEdit ? 'تعديل منتج' : 'إضافة منتج جديد'),
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
            label: Text(_isEdit ? 'حفظ التعديلات' : 'حفظ المنتج في المتجر',
                style:
                    const TextStyle(fontSize: 16, fontWeight: FontWeight.w600)),
            style: FilledButton.styleFrom(
              backgroundColor: AmialColors.primary,
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
          // ====== المعلومات الأساسية ======
          _sectionTitle(Icons.info_outline, 'المعلومات الأساسية'),
          _card(children: [
            _label('اسم المنتج'),
            TextField(
              controller: _name,
              decoration: _input('مثلاً: علبة قهوة عربية'),
            ),
            const SizedBox(height: 12),
            TextField(
              controller: _barcode,
              keyboardType: TextInputType.number,
              inputFormatters: [FilteringTextInputFormatter.digitsOnly],
              decoration: _input('إضافة باركود المنتج').copyWith(
                prefixIcon: const Icon(Icons.qr_code_scanner, size: 20),
              ),
            ),
            const SizedBox(height: 12),
            _label('الفئة'),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: _presetCategories.map((cat) {
                final selected = _category == cat;
                return ChoiceChip(
                  label: Text(cat, style: const TextStyle(fontSize: 12)),
                  selected: selected,
                  selectedColor: AmialColors.primary,
                  backgroundColor: const Color(0xFFF0F1F3),
                  labelStyle: TextStyle(
                      color: selected ? Colors.white : Colors.black87),
                  onSelected: (_) => setState(() => _category = cat),
                );
              }).toList(),
            ),
            if (_category == 'أخرى') ...[
              const SizedBox(height: 10),
              TextField(
                controller: _customCategory,
                decoration: _input('اكتب اسم الفئة'),
              ),
            ],
          ]),
          const SizedBox(height: 16),

          // ====== المخزون والتواريخ ======
          _sectionTitle(Icons.inventory_2_outlined, 'المخزون والتواريخ'),
          _card(children: [
            Row(children: [
              // عدّاد الكمية
              Container(
                decoration: BoxDecoration(
                  color: const Color(0xFFF6F7F8),
                  borderRadius: BorderRadius.circular(12),
                ),
                child: Row(children: [
                  IconButton(
                    icon: const Icon(Icons.add, size: 20),
                    onPressed: () => setState(() => _qty++),
                  ),
                  SizedBox(
                    width: 48,
                    child: Text('$_qty',
                        textAlign: TextAlign.center,
                        style: const TextStyle(
                            fontSize: 16, fontWeight: FontWeight.bold)),
                  ),
                  IconButton(
                    icon: const Icon(Icons.remove, size: 20),
                    onPressed: () =>
                        setState(() => _qty = _qty > 0 ? _qty - 1 : 0),
                  ),
                ]),
              ),
              const Spacer(),
              const Text('الكمية المتوفرة',
                  style: TextStyle(
                      fontSize: 13, color: AmialColors.textSecondary)),
            ]),
            const SizedBox(height: 14),
            Row(children: [
              Expanded(child: _dateField('تاريخ الانتهاء', _expiry, false)),
              const SizedBox(width: 10),
              Expanded(child: _dateField('تاريخ الإنتاج', _production, true)),
            ]),
          ]),
          const SizedBox(height: 16),

          // ====== التسعير ======
          _sectionTitle(Icons.payments_outlined, 'التسعير (ريال يمني)'),
          _card(children: [
            Row(children: [
              Expanded(child: _priceField('سعر البيع', _price)),
              const SizedBox(width: 10),
              Expanded(child: _priceField('سعر التكلفة', _cost)),
            ]),
            const SizedBox(height: 12),
            _label('سعر العرض (اختياري)'),
            TextField(
              controller: _offer,
              keyboardType:
                  const TextInputType.numberWithOptions(decimal: true),
              decoration: _input('ادخل سعر التخفيض').copyWith(
                prefixText: 'YER ',
                enabledBorder: OutlineInputBorder(
                  borderRadius: BorderRadius.circular(12),
                  borderSide: const BorderSide(
                      color: AmialColors.yellowDark, width: 1),
                ),
              ),
            ),
          ]),
          const SizedBox(height: 90),
        ],
      ),
    );
  }

  Widget _sectionTitle(IconData icon, String text) => Padding(
        padding: const EdgeInsets.only(bottom: 8),
        child: Row(mainAxisAlignment: MainAxisAlignment.end, children: [
          Text(text,
              style:
                  const TextStyle(fontWeight: FontWeight.bold, fontSize: 14)),
          const SizedBox(width: 8),
          Icon(icon, size: 18, color: AmialColors.yellowDark),
        ]),
      );

  Widget _card({required List<Widget> children}) => Container(
        padding: const EdgeInsets.all(14),
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(16),
        ),
        child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: children),
      );

  Widget _label(String t) => Padding(
        padding: const EdgeInsets.only(bottom: 6),
        child: Text(t,
            textAlign: TextAlign.right,
            style: const TextStyle(
                fontSize: 12, color: AmialColors.textSecondary)),
      );

  InputDecoration _input(String hint) => InputDecoration(
        hintText: hint,
        hintStyle: const TextStyle(fontSize: 13, color: AmialColors.textMuted),
        filled: true,
        fillColor: const Color(0xFFF9FAFB),
        border: OutlineInputBorder(
          borderRadius: BorderRadius.circular(12),
          borderSide: const BorderSide(color: AmialColors.border),
        ),
        enabledBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(12),
          borderSide: const BorderSide(color: AmialColors.border),
        ),
      );

  Widget _priceField(String label, TextEditingController ctrl) {
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      _label(label),
      TextField(
        controller: ctrl,
        keyboardType: const TextInputType.numberWithOptions(decimal: true),
        textAlign: TextAlign.center,
        decoration: _input('0.00').copyWith(prefixText: 'YER '),
      ),
    ]);
  }

  Widget _dateField(String label, DateTime? value, bool production) {
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      _label(label),
      InkWell(
        onTap: () => _pickDate(production),
        borderRadius: BorderRadius.circular(12),
        child: Container(
          padding: const EdgeInsets.symmetric(vertical: 12, horizontal: 10),
          decoration: BoxDecoration(
            color: const Color(0xFFF9FAFB),
            borderRadius: BorderRadius.circular(12),
            border: Border.all(color: AmialColors.border),
          ),
          child: Row(mainAxisAlignment: MainAxisAlignment.center, children: [
            const Icon(Icons.calendar_month_outlined,
                size: 16, color: AmialColors.textMuted),
            const SizedBox(width: 6),
            Text(
              value == null
                  ? 'اختر التاريخ'
                  : '${value.year}/${value.month}/${value.day}',
              style: TextStyle(
                  fontSize: 12,
                  color: value == null
                      ? AmialColors.textMuted
                      : Colors.black87),
            ),
          ]),
        ),
      ),
    ]);
  }
}
