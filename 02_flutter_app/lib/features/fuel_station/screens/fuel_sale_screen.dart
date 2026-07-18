import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';
import 'package:amyal_pay/features/fuel_station/controllers/fuel_station_controller.dart';

/// AMIAL-FUEL-001 — شاشة بيع وقود (الجوهر).
///
/// Flow:
///   1. اختيار المضخّة (من قائمة بصرية)
///   2. اختيار نوع الوقود (لو المضخّة تخدم عدّة)
///   3. اختيار طريقة الإدخال (لتر أو مبلغ)
///   4. إدخال الكمية مع حساب لحظي للأخرى
///   5. (للميكانيكية) قراءة العدّاد بعد البيع
///   6. اختيار طريقة الدفع (نقد / أميال باي / بطاقة شركة)
///   7. تأكيد
class FuelSaleScreen extends StatefulWidget {
  const FuelSaleScreen({super.key});

  @override
  State<FuelSaleScreen> createState() => _FuelSaleScreenState();
}

class _FuelSaleScreenState extends State<FuelSaleScreen> {
  late final FuelStationController c;

  // الاختيارات
  Map<String, dynamic>? _selectedPump;
  Map<String, dynamic>? _selectedProduct;
  String _saleType = 'by_amount'; // by_amount أو by_liters
  String _paymentMethod = 'cash';

  final _amountCtrl = TextEditingController();
  final _litersCtrl = TextEditingController();
  final _meterAfterCtrl = TextEditingController();
  final _plateCtrl = TextEditingController();
  final _driverCtrl = TextEditingController();

  // لـ company_card
  Map<String, dynamic>? _selectedCompany;
  final _cardIdCtrl = TextEditingController();

  // لـ amial_pay
  final _paidTxIdCtrl = TextEditingController();
  // AMIAL-FUEL-PAY-001: هاتف العميل للشحن المباشر عبر أميال باي
  final _customerPhoneCtrl = TextEditingController();

  @override
  void initState() {
    super.initState();
    c = Get.find<FuelStationController>();
    WidgetsBinding.instance.addPostFrameCallback((_) async {
      await Future.wait([c.loadPumps(), c.loadProducts(), c.loadCompanies()]);
    });
  }

  @override
  void dispose() {
    _amountCtrl.dispose();
    _litersCtrl.dispose();
    _meterAfterCtrl.dispose();
    _plateCtrl.dispose();
    _driverCtrl.dispose();
    _cardIdCtrl.dispose();
    _paidTxIdCtrl.dispose();
    _customerPhoneCtrl.dispose();
    super.dispose();
  }

  /// السعر للّتر للمنتج المختار (للحساب اللحظي)
  double get _pricePerLiter {
    if (_selectedProduct == null) return 0;
    return double.tryParse('${_selectedProduct!['price_per_liter']}') ?? 0;
  }

  /// إجمالي البيع المحسوب
  double get _computedTotal {
    if (_saleType == 'by_amount') {
      return double.tryParse(_amountCtrl.text) ?? 0;
    }
    final liters = double.tryParse(_litersCtrl.text) ?? 0;
    return liters * _pricePerLiter;
  }

  /// اللترات المحسوبة
  double get _computedLiters {
    if (_saleType == 'by_liters') {
      return double.tryParse(_litersCtrl.text) ?? 0;
    }
    if (_pricePerLiter <= 0) return 0;
    final amount = double.tryParse(_amountCtrl.text) ?? 0;
    return amount / _pricePerLiter;
  }

  bool get _isMechanical => _selectedPump?['pump_type'] == 'mechanical';

  double get _meterBefore =>
      double.tryParse('${_selectedPump?['current_meter_reading'] ?? 0}') ?? 0;

  Future<void> _submit() async {
    // التحقّقات
    if (_selectedPump == null) return _snack('اختر المضخّة');
    if (_selectedProduct == null) return _snack('اختر نوع الوقود');
    if (_computedTotal <= 0) return _snack('أدخل الكمية أو المبلغ');

    final data = <String, dynamic>{
      'pump_id': _selectedPump!['id'],
      'fuel_product_id': _selectedProduct!['id'],
      'sale_type': _saleType,
      'payment_method': _paymentMethod,
      if (_saleType == 'by_liters') 'liters': _litersCtrl.text,
      if (_saleType == 'by_amount') 'amount': _amountCtrl.text,
      if (_plateCtrl.text.isNotEmpty) 'vehicle_plate': _plateCtrl.text.trim(),
      if (_driverCtrl.text.isNotEmpty) 'driver_name': _driverCtrl.text.trim(),
    };

    if (_paymentMethod == 'amial_pay') {
      // AMIAL-FUEL-PAY-001: هاتف العميل يشحن مباشرةً؛ أو مرجع دفع مسبق
      final phone = _customerPhoneCtrl.text.trim();
      final ref = _paidTxIdCtrl.text.trim();
      if (phone.isEmpty && ref.isEmpty) {
        return _snack('أدخل رقم هاتف العميل (أو مرجع دفع مسبق)');
      }
      if (phone.isNotEmpty) data['customer_phone'] = phone;
      if (ref.isNotEmpty) data['paid_transaction_id'] = ref;
    }

    if (_paymentMethod == 'company_card') {
      if (_selectedCompany == null) return _snack('اختر الشركة');
      data['company_account_id'] = _selectedCompany!['id'];
      if (_cardIdCtrl.text.trim().isNotEmpty) {
        data['company_card_id'] = _cardIdCtrl.text.trim();
      }
    }

    if (_isMechanical && _meterAfterCtrl.text.trim().isNotEmpty) {
      data['meter_reading_after'] = _meterAfterCtrl.text.trim();
    }

    final ok = await c.recordSale(data);
    if (!mounted) return;
    if (ok) {
      _showSuccessDialog();
    } else {
      _snack(c.lastError.value.isEmpty ? 'فشل تسجيل البيع' : c.lastError.value);
    }
  }

  void _showSuccessDialog() {
    final sale = c.lastSale.value;
    if (sale == null) return;

    showDialog(
      context: context,
      barrierDismissible: false,
      builder: (_) => AlertDialog(
        title: const Row(children: [
          Icon(Icons.check_circle, color: Colors.green, size: 28),
          SizedBox(width: 8),
          Text('تم البيع'),
        ]),
        content: SingleChildScrollView(
          child: Column(crossAxisAlignment: CrossAxisAlignment.end, mainAxisSize: MainAxisSize.min, children: [
            _resultRow('اللترات', '${sale['liters']} لتر'),
            _resultRow('السعر للّتر', '${sale['price_per_liter']} ر.ي'),
            const Divider(),
            _resultRow('الإجمالي', '${sale['total_amount']} ر.ي', bold: true, color: AmyalColors.primary),
            const SizedBox(height: 8),
            if (sale['vehicle_plate'] != null)
              _resultRow('السيارة', '${sale['vehicle_plate']}'),
          ]),
        ),
        actions: [
          TextButton(
            onPressed: () {
              Navigator.pop(context);
              _resetForm();
            },
            child: const Text('بيع جديد'),
          ),
          FilledButton(
            onPressed: () {
              Navigator.pop(context);
              Get.back(); // ارجع للوحة المحطة
            },
            child: const Text('إغلاق'),
          ),
        ],
      ),
    );
  }

  Widget _resultRow(String label, String value, {bool bold = false, Color? color}) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 4),
      child: Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
        Text(value, style: TextStyle(
          fontWeight: bold ? FontWeight.bold : FontWeight.w500,
          fontSize: bold ? 18 : 14,
          color: color,
        )),
        Text(label, style: TextStyle(color: Colors.grey.shade600, fontSize: 13)),
      ]),
    );
  }

  void _resetForm() {
    _amountCtrl.clear();
    _litersCtrl.clear();
    _meterAfterCtrl.clear();
    _plateCtrl.clear();
    _driverCtrl.clear();
    _cardIdCtrl.clear();
    _paidTxIdCtrl.clear();
    _customerPhoneCtrl.clear();
    setState(() {
      _selectedPump = null;
      _selectedProduct = null;
      _selectedCompany = null;
    });
    c.loadPumps(); // إعادة تحميل لقراءة عدّاد محدّثة
  }

  void _snack(String m) => ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(m), backgroundColor: AmyalColors.red, duration: const Duration(seconds: 2)),
      );

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmyalColors.background,
      appBar: AppBar(
        title: const Text('بيع وقود'),
        backgroundColor: AmyalColors.primary,
        foregroundColor: Colors.white,
      ),
      body: Obx(() {
        if (c.isLoading.value && c.pumps.isEmpty) {
          return const Center(child: CircularProgressIndicator());
        }
        if (c.pumps.isEmpty || c.products.isEmpty) {
          return Center(
            child: Padding(
              padding: const EdgeInsets.all(24),
              child: Column(mainAxisAlignment: MainAxisAlignment.center, children: [
                Icon(Icons.local_gas_station, size: 64, color: Colors.grey.shade400),
                const SizedBox(height: 16),
                Text(c.pumps.isEmpty
                    ? 'لم تضِف أيّ مضخّة بعد. أضف مضخّة من الإعدادات أولاً.'
                    : 'لم تضِف أيّ نوع وقود بعد. أضف نوعاً من الإعدادات.',
                    style: const TextStyle(fontSize: 15), textAlign: TextAlign.center),
              ]),
            ),
          );
        }

        return SingleChildScrollView(
          padding: const EdgeInsets.all(16),
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            // 1) المضخّات
            _sectionTitle('1. اختر المضخّة'),
            const SizedBox(height: 8),
            SizedBox(height: 90, child: ListView.separated(
              scrollDirection: Axis.horizontal,
              itemCount: c.pumps.length,
              separatorBuilder: (_, _) => const SizedBox(width: 8),
              itemBuilder: (_, i) => _pumpTile(c.pumps[i]),
            )),

            const SizedBox(height: 20),

            // 2) نوع الوقود
            _sectionTitle('2. نوع الوقود'),
            const SizedBox(height: 8),
            Wrap(spacing: 8, runSpacing: 8, alignment: WrapAlignment.center,
                children: c.products.map((p) => _productChip(p)).toList()),

            const SizedBox(height: 20),

            // 3) طريقة الإدخال
            _sectionTitle('3. الكمية'),
            const SizedBox(height: 8),
            Row(children: [
              Expanded(child: _saleTypeBtn('by_amount', 'بالمبلغ', Icons.attach_money)),
              const SizedBox(width: 8),
              Expanded(child: _saleTypeBtn('by_liters', 'باللتر', Icons.local_gas_station)),
            ]),
            const SizedBox(height: 12),

            // 4) إدخال الكمية + الحساب اللحظي
            if (_saleType == 'by_amount') _amountField() else _litersField(),

            // 5) قراءة العدّاد (للميكانيكية)
            if (_isMechanical && _selectedPump != null) ...[
              const SizedBox(height: 16),
              _meterReadingSection(),
            ],

            const SizedBox(height: 20),

            // 6) السيارة (اختياري)
            _sectionTitle('بيانات السيارة (اختياري)'),
            const SizedBox(height: 8),
            Row(children: [
              Expanded(child: _textField(_plateCtrl, 'لوحة السيارة')),
              const SizedBox(width: 8),
              Expanded(child: _textField(_driverCtrl, 'اسم السائق')),
            ]),

            const SizedBox(height: 20),

            // 7) طريقة الدفع
            _sectionTitle('4. طريقة الدفع'),
            const SizedBox(height: 8),
            Row(children: [
              Expanded(child: _payMethodTile('cash', Icons.payments, 'نقدي')),
              const SizedBox(width: 6),
              Expanded(child: _payMethodTile('amial_pay', Icons.qr_code, 'أميال باي')),
              const SizedBox(width: 6),
              Expanded(child: _payMethodTile('company_card', Icons.business, 'شركة')),
            ]),

            if (_paymentMethod == 'amial_pay') ...[
              const SizedBox(height: 12),
              _textField(_customerPhoneCtrl, 'رقم هاتف العميل',
                  type: TextInputType.phone),
              const SizedBox(height: 6),
              const Text(
                'يُخصم المبلغ من محفظة العميل فوراً ويُضاف لك (بعد الرسوم).',
                style: TextStyle(fontSize: 11, color: AmyalColors.textMuted),
              ),
            ],

            if (_paymentMethod == 'company_card') ...[
              const SizedBox(height: 12),
              _companySelector(),
            ],

            const SizedBox(height: 24),

            // 8) ملخّص + زر التأكيد
            _summaryCard(),
            const SizedBox(height: 12),
            Obx(() => FilledButton.icon(
              onPressed: c.isSubmitting.value ? null : _submit,
              icon: c.isSubmitting.value
                  ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                  : const Icon(Icons.check_circle_outline),
              label: const Text('تأكيد البيع', style: TextStyle(fontSize: 17, fontWeight: FontWeight.bold)),
              style: FilledButton.styleFrom(
                backgroundColor: AmyalColors.primary,
                minimumSize: const Size.fromHeight(56),
              ),
            )),
          ]),
        );
      }),
    );
  }

  Widget _sectionTitle(String s) => Text(s,
      textAlign: TextAlign.right,
      style: const TextStyle(fontSize: 14, fontWeight: FontWeight.bold, color: Colors.black87));

  Widget _pumpTile(Map<String, dynamic> pump) {
    final selected = _selectedPump?['id'] == pump['id'];
    return InkWell(
      borderRadius: BorderRadius.circular(12),
      onTap: () => setState(() => _selectedPump = pump),
      child: Container(
        width: 110,
        padding: const EdgeInsets.all(8),
        decoration: BoxDecoration(
          color: selected ? AmyalColors.primary : Colors.white,
          borderRadius: BorderRadius.circular(12),
          border: Border.all(color: selected ? AmyalColors.primary : Colors.grey.shade300, width: selected ? 2 : 1),
        ),
        child: Column(mainAxisAlignment: MainAxisAlignment.center, children: [
          Icon(Icons.local_gas_station,
              color: selected ? AmyalColors.yellow : AmyalColors.primary, size: 32),
          const SizedBox(height: 4),
          Text('مضخّة ${pump['pump_number']}',
              style: TextStyle(color: selected ? Colors.white : Colors.black87,
                  fontWeight: FontWeight.bold, fontSize: 13)),
          Text(pump['pump_type'] == 'mechanical' ? 'يدوية' : 'إلكترونية',
              style: TextStyle(color: selected ? Colors.white70 : Colors.grey.shade600, fontSize: 10)),
        ]),
      ),
    );
  }

  Widget _productChip(Map<String, dynamic> product) {
    final selected = _selectedProduct?['id'] == product['id'];
    final price = product['price_per_liter']?.toString() ?? '0';
    return InkWell(
      borderRadius: BorderRadius.circular(12),
      onTap: () => setState(() => _selectedProduct = product),
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
        decoration: BoxDecoration(
          color: selected ? AmyalColors.yellow : Colors.white,
          borderRadius: BorderRadius.circular(12),
          border: Border.all(color: selected ? AmyalColors.yellowDark : Colors.grey.shade300, width: selected ? 2 : 1),
        ),
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          Text(product['name'] ?? '',
              style: TextStyle(fontWeight: FontWeight.bold,
                  color: selected ? Colors.black87 : Colors.black87)),
          Text('$price ر.ي/لتر',
              style: TextStyle(color: selected ? Colors.black87 : Colors.grey.shade600, fontSize: 11)),
        ]),
      ),
    );
  }

  Widget _saleTypeBtn(String value, String label, IconData icon) {
    final selected = _saleType == value;
    return InkWell(
      borderRadius: BorderRadius.circular(10),
      onTap: () => setState(() => _saleType = value),
      child: Container(
        padding: const EdgeInsets.symmetric(vertical: 12),
        decoration: BoxDecoration(
          color: selected ? AmyalColors.primary : Colors.white,
          borderRadius: BorderRadius.circular(10),
          border: Border.all(color: selected ? AmyalColors.primary : Colors.grey.shade300, width: selected ? 2 : 1),
        ),
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          Icon(icon, color: selected ? Colors.white : AmyalColors.primary),
          const SizedBox(height: 2),
          Text(label, style: TextStyle(color: selected ? Colors.white : Colors.black87, fontWeight: FontWeight.bold)),
        ]),
      ),
    );
  }

  Widget _amountField() {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 4),
      decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(12)),
      child: Row(children: [
        const Text('ر.ي', style: TextStyle(color: Colors.grey, fontWeight: FontWeight.bold)),
        const SizedBox(width: 12),
        Expanded(child: TextField(
          controller: _amountCtrl,
          keyboardType: TextInputType.number,
          textAlign: TextAlign.right,
          inputFormatters: [FilteringTextInputFormatter.digitsOnly],
          style: const TextStyle(fontSize: 28, fontWeight: FontWeight.bold, color: AmyalColors.primary),
          decoration: InputDecoration(
            border: InputBorder.none,
            hintText: 'المبلغ',
            suffix: _pricePerLiter > 0
                ? Text('= ${_computedLiters.toStringAsFixed(2)} لتر',
                    style: TextStyle(fontSize: 13, color: Colors.grey.shade600))
                : null,
          ),
          onChanged: (_) => setState(() {}),
        )),
      ]),
    );
  }

  Widget _litersField() {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 4),
      decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(12)),
      child: Row(children: [
        const Text('لتر', style: TextStyle(color: Colors.grey, fontWeight: FontWeight.bold)),
        const SizedBox(width: 12),
        Expanded(child: TextField(
          controller: _litersCtrl,
          keyboardType: const TextInputType.numberWithOptions(decimal: true),
          textAlign: TextAlign.right,
          style: const TextStyle(fontSize: 28, fontWeight: FontWeight.bold, color: AmyalColors.primary),
          decoration: InputDecoration(
            border: InputBorder.none,
            hintText: 'اللترات',
            suffix: _pricePerLiter > 0
                ? Text('= ${_computedTotal.toStringAsFixed(0)} ر.ي',
                    style: TextStyle(fontSize: 13, color: Colors.grey.shade600))
                : null,
          ),
          onChanged: (_) => setState(() {}),
        )),
      ]),
    );
  }

  Widget _meterReadingSection() {
    final expected = _meterBefore + _computedLiters;
    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: AmyalColors.yellow.withValues(alpha: 0.15),
        borderRadius: BorderRadius.circular(10),
        border: Border.all(color: AmyalColors.yellow),
      ),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Row(children: [
          const Icon(Icons.speed, color: AmyalColors.yellowDark, size: 20),
          const SizedBox(width: 8),
          const Expanded(child: Text('قراءة العدّاد (للمضخّة اليدوية)',
              style: TextStyle(fontWeight: FontWeight.bold))),
        ]),
        const SizedBox(height: 8),
        Text('القراءة قبل: ${_meterBefore.toStringAsFixed(2)}',
            style: TextStyle(color: Colors.grey.shade700, fontSize: 13)),
        Text('متوقّعة بعد: ${expected.toStringAsFixed(2)}',
            style: TextStyle(color: AmyalColors.yellowDark, fontSize: 13)),
        const SizedBox(height: 8),
        TextField(
          controller: _meterAfterCtrl,
          keyboardType: const TextInputType.numberWithOptions(decimal: true),
          textAlign: TextAlign.right,
          decoration: InputDecoration(
            labelText: 'القراءة الفعلية بعد البيع',
            filled: true, fillColor: Colors.white,
            border: OutlineInputBorder(borderRadius: BorderRadius.circular(8), borderSide: BorderSide.none),
            hintText: expected.toStringAsFixed(2),
          ),
        ),
      ]),
    );
  }

  Widget _textField(TextEditingController ctrl, String label, {TextInputType? type}) {
    return TextField(
      controller: ctrl,
      keyboardType: type,
      textAlign: TextAlign.right,
      decoration: InputDecoration(
        labelText: label,
        filled: true, fillColor: Colors.white,
        border: OutlineInputBorder(borderRadius: BorderRadius.circular(10), borderSide: BorderSide.none),
      ),
    );
  }

  Widget _payMethodTile(String value, IconData icon, String label) {
    final selected = _paymentMethod == value;
    return InkWell(
      borderRadius: BorderRadius.circular(10),
      onTap: () => setState(() => _paymentMethod = value),
      child: Container(
        padding: const EdgeInsets.symmetric(vertical: 12, horizontal: 4),
        decoration: BoxDecoration(
          color: selected ? AmyalColors.primary : Colors.white,
          borderRadius: BorderRadius.circular(10),
          border: Border.all(color: selected ? AmyalColors.primary : Colors.grey.shade300, width: selected ? 2 : 1),
        ),
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          Icon(icon, color: selected ? Colors.white : AmyalColors.primary, size: 22),
          const SizedBox(height: 4),
          Text(label, style: TextStyle(color: selected ? Colors.white : Colors.black87,
              fontWeight: FontWeight.bold, fontSize: 12)),
        ]),
      ),
    );
  }

  Widget _companySelector() {
    return Column(children: [
      DropdownButtonFormField<Map<String, dynamic>>(
        initialValue: _selectedCompany,
        decoration: InputDecoration(
          labelText: 'اختر الشركة',
          filled: true, fillColor: Colors.white,
          border: OutlineInputBorder(borderRadius: BorderRadius.circular(10), borderSide: BorderSide.none),
        ),
        items: c.companies.map((comp) => DropdownMenuItem(
          value: comp,
          child: Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
            Text('${comp['current_balance']} ر.ي',
                style: TextStyle(color: Colors.grey.shade600, fontSize: 12)),
            Text(comp['company_name'] ?? '', style: const TextStyle(fontWeight: FontWeight.bold)),
          ]),
        )).toList(),
        onChanged: (v) => setState(() => _selectedCompany = v),
      ),
      const SizedBox(height: 8),
      _textField(_cardIdCtrl, 'رقم بطاقة الشركة (اختياري)'),
    ]);
  }

  Widget _summaryCard() {
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: AmyalColors.primary,
        borderRadius: BorderRadius.circular(14),
      ),
      child: Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
        if (_selectedProduct != null)
          Text(_selectedProduct!['name'] ?? '',
              style: const TextStyle(color: Colors.white70, fontSize: 13)),
        const SizedBox(height: 4),
        Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
          Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            const Text('الإجمالي', style: TextStyle(color: Colors.white70, fontSize: 12)),
            Text('${_computedTotal.toStringAsFixed(0)} ر.ي',
                style: const TextStyle(color: Colors.white, fontSize: 28, fontWeight: FontWeight.bold)),
          ]),
          Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
            const Text('الكمية', style: TextStyle(color: Colors.white70, fontSize: 12)),
            Text('${_computedLiters.toStringAsFixed(2)} لتر',
                style: const TextStyle(color: AmyalColors.yellow, fontSize: 18, fontWeight: FontWeight.bold)),
          ]),
        ]),
      ]),
    );
  }
}
