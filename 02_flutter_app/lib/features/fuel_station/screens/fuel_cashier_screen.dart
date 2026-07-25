import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';
import 'package:amyal_pay/features/fuel_station/controllers/fuel_station_controller.dart';
import 'package:amyal_pay/features/fuel_station/screens/fuel_sales_history_screen.dart';
import 'package:amyal_pay/features/fuel_station/screens/fuel_receipt_screen.dart';
import 'package:amyal_pay/features/fuel_station/screens/fuel_qr_collect_screen.dart';

/// AMIAL-FUEL-CASHIER-001 — كاشير محطة الوقود (تصميم #103).
///
/// كاشير سريع للعامل: يختار المضخّة ونوع الوقود، يُدخل المبلغ (بالريال) أو
/// الكمية (باللتر) عبر لوحة أرقام أو أزرار سريعة، ثم يدفع نقداً أو «QR الفوري»
/// (أميال باي بهاتف العميل — دفع حقيقي يحرّك المال). موصول بـ recordSale.
class FuelCashierScreen extends StatefulWidget {
  const FuelCashierScreen({super.key});

  @override
  State<FuelCashierScreen> createState() => _FuelCashierScreenState();
}

class _FuelCashierScreenState extends State<FuelCashierScreen> {
  late final FuelStationController c;

  Map<String, dynamic>? _pump;
  Map<String, dynamic>? _product;
  String _mode = 'by_amount'; // by_amount (بالريال) | by_liters (باللتر)
  String _entry = ''; // النص المُدخل عبر اللوحة

  @override
  void initState() {
    super.initState();
    c = Get.find<FuelStationController>();
    WidgetsBinding.instance.addPostFrameCallback((_) async {
      await Future.wait([c.loadStation(), c.loadPumps(), c.loadProducts(), c.loadSales()]);
      if (!mounted) return;
      setState(() {
        if (c.pumps.isNotEmpty) _pump = c.pumps.first;
        if (c.products.isNotEmpty) _product = c.products.first;
      });
    });
  }

  double get _pricePerLiter =>
      double.tryParse('${_product?['price_per_liter'] ?? 0}') ?? 0;

  double get _enteredValue => double.tryParse(_entry) ?? 0;

  /// المبلغ المحسوب (ر.ي) واللترات — حسب وضع الإدخال.
  double get _amount => _mode == 'by_amount'
      ? _enteredValue
      : _enteredValue * _pricePerLiter;
  double get _liters => _mode == 'by_liters'
      ? _enteredValue
      : (_pricePerLiter > 0 ? _enteredValue / _pricePerLiter : 0);

  String _fmt(num n) =>
      n.toStringAsFixed(n == n.roundToDouble() ? 0 : 2).replaceAllMapped(
          RegExp(r'\B(?=(\d{3})+(?!\d))'), (m) => ',');

  void _tap(String key) {
    setState(() {
      if (key == 'C') {
        _entry = '';
      } else if (key == '<') {
        if (_entry.isNotEmpty) _entry = _entry.substring(0, _entry.length - 1);
      } else if (key == '.') {
        if (!_entry.contains('.')) _entry = _entry.isEmpty ? '0.' : '$_entry.';
      } else {
        if (_entry == '0') _entry = key; else _entry += key;
      }
    });
  }

  bool _validate() {
    if (_pump == null) { _snack('اختر المضخّة'); return false; }
    if (_product == null) { _snack('اختر نوع الوقود'); return false; }
    if (_amount <= 0) { _snack('أدخل المبلغ أو الكمية'); return false; }
    return true;
  }

  Map<String, dynamic> _baseData() => {
        'pump_id': _pump!['id'],
        'fuel_product_id': _product!['id'],
        'sale_type': _mode,
        if (_mode == 'by_amount') 'amount': _enteredValue.toString(),
        if (_mode == 'by_liters') 'liters': _enteredValue.toString(),
      };

  Future<void> _payCash() async {
    if (!_validate()) return;
    final data = _baseData()..['payment_method'] = 'cash';
    await _submit(data);
  }

  /// «QR الفوري» — يعرض رمزاً بمبلغ ثابت يمسحه العميل ويدفع من محفظته،
  /// ثم يُسجَّل البيع تلقائياً عند تأكيد الدفع. المسار المهني الافتراضي.
  Future<void> _payByQr() async {
    if (!_validate()) return;
    Get.to(() => FuelQrCollectScreen(
          saleData: _baseData(),
          amount: _amount,
          stationName: c.station.value?['station_name'] ?? 'محطة الوقود',
          pumpLabel: _pump == null ? null : 'المضخّة #${_pump!['pump_number']}',
          productName: _product?['name']?.toString(),
        ));
  }

  /// دفع أميال باي بهاتف العميل (شحن مباشر) — خيار بديل سريع.
  Future<void> _payAmial() async {
    if (!_validate()) return;
    final phoneCtrl = TextEditingController();
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('دفع أميال باي'),
        content: Column(mainAxisSize: MainAxisSize.min, children: [
          Text('المبلغ: ${_fmt(_amount)} ر.ي',
              style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16)),
          const SizedBox(height: 12),
          TextField(
            controller: phoneCtrl,
            keyboardType: TextInputType.phone,
            autofocus: true,
            decoration: const InputDecoration(
              labelText: 'رقم هاتف العميل',
              hintText: '9677xxxxxxxx',
              border: OutlineInputBorder(),
            ),
          ),
          const SizedBox(height: 6),
          const Text('يُخصم من محفظة العميل فوراً ويُضاف لك بعد الرسوم.',
              style: TextStyle(fontSize: 11, color: AmyalColors.textMuted)),
        ]),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('إلغاء')),
          FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('تأكيد الدفع')),
        ],
      ),
    );
    if (ok != true) return;
    if (phoneCtrl.text.trim().isEmpty) { _snack('أدخل رقم هاتف العميل'); return; }
    _lastCustomerPhone = phoneCtrl.text.trim();
    final data = _baseData()
      ..['payment_method'] = 'amial_pay'
      ..['customer_phone'] = phoneCtrl.text.trim();
    await _submit(data);
  }

  /// هاتف العميل لآخر عملية أميال باي — يُمرَّر للفاتورة (إرسال واتساب).
  String? _lastCustomerPhone;

  Future<void> _submit(Map<String, dynamic> data) async {
    final ok = await c.recordSale(data);
    if (!mounted) return;
    if (ok) {
      setState(() => _entry = '');
      c.loadSales();
      _showReceipt();
    } else {
      _snack(c.lastError.value.isEmpty ? 'فشل تسجيل البيع' : c.lastError.value);
    }
  }

  void _showReceipt() {
    final s = Map<String, dynamic>.from(c.lastSale.value ?? {});
    // اضمن ظهور اسم الوقود واللترات/السعر حتى لو لم يُرجعها الخادم صراحةً.
    s.putIfAbsent('liters', () => _liters);
    s.putIfAbsent('price_per_liter', () => _pricePerLiter);
    s.putIfAbsent('total_amount', () => _amount);
    s.putIfAbsent('product_name', () => _product?['name']);
    Get.to(() => FuelReceiptScreen(
          sale: s,
          stationName: c.station.value?['station_name'] ?? 'محطة الوقود',
          pumpLabel: _pump == null ? null : 'المضخّة #${_pump!['pump_number']}',
          customerPhone: _lastCustomerPhone,
        ));
    _lastCustomerPhone = null;
  }

  void _snack(String m) => ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(content: Text(m), backgroundColor: AmyalColors.red));

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmyalColors.background,
      appBar: AppBar(
        title: Obx(() => Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(c.station.value?['station_name'] ?? 'كاشير المحطة',
              style: const TextStyle(fontSize: 16, fontWeight: FontWeight.bold)),
          Text(_pump == null ? 'اختر المضخّة' : 'المضخّة #${_pump!['pump_number']} • متصل',
              style: const TextStyle(fontSize: 11, color: Colors.white70)),
        ])),
      ),
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(16),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          // ===== المضخّة + نوع الوقود =====
          Obx(() => Row(children: [
            Expanded(child: _selector(
              label: 'المضخّة',
              value: _pump == null ? '—' : '#${_pump!['pump_number']}',
              onTap: c.pumps.isEmpty ? null : _pickPump,
            )),
            const SizedBox(width: 8),
            Expanded(child: _selector(
              label: 'نوع الوقود',
              value: _product?['name']?.toString() ?? '—',
              onTap: c.products.isEmpty ? null : _pickProduct,
            )),
          ])),
          const SizedBox(height: 12),

          // ===== بالريال / باللتر =====
          Container(
            decoration: BoxDecoration(
              color: Colors.white, borderRadius: BorderRadius.circular(12),
              border: Border.all(color: AmyalColors.border),
            ),
            child: Row(children: [
              _modeTab('by_amount', 'بالريال'),
              _modeTab('by_liters', 'باللتر'),
            ]),
          ),
          const SizedBox(height: 12),

          // ===== شاشة العرض =====
          Container(
            padding: const EdgeInsets.symmetric(vertical: 22),
            decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(16)),
            child: Column(children: [
              Text(_mode == 'by_amount' ? 'المبلغ المطلوب تعبئته' : 'الكمية باللتر',
                  style: const TextStyle(color: AmyalColors.textMuted, fontSize: 12)),
              const SizedBox(height: 6),
              Text(
                _mode == 'by_amount'
                    ? 'YER ${_fmt(_enteredValue)}'
                    : '${_fmt(_enteredValue)} L',
                style: const TextStyle(fontSize: 34, fontWeight: FontWeight.bold, color: AmyalColors.primary),
              ),
              const SizedBox(height: 4),
              // القيمة المقابلة (لتر ↔ ريال)
              Text(
                _mode == 'by_amount'
                    ? '≈ ${_fmt(_liters)} لتر'
                    : '≈ ${_fmt(_amount)} ر.ي',
                style: const TextStyle(color: AmyalColors.textSecondary, fontSize: 13),
              ),
            ]),
          ),
          const SizedBox(height: 12),

          // ===== أزرار سريعة =====
          Row(children: [
            _quick('10000'), const SizedBox(width: 8), _quick('5000'),
          ]),
          const SizedBox(height: 8),
          Row(children: [
            _quick('50000'), const SizedBox(width: 8), _quick('20000'),
          ]),
          const SizedBox(height: 12),

          // ===== لوحة الأرقام =====
          _keypadRow(['1', '2', '3']),
          _keypadRow(['4', '5', '6']),
          _keypadRow(['7', '8', '9']),
          _keypadRow(['C', '0', '<']),
          const SizedBox(height: 14),

          // ===== الدفع =====
          Obx(() => FilledButton.icon(
            onPressed: c.isSubmitting.value ? null : _payByQr,
            icon: c.isSubmitting.value
                ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                : const Icon(Icons.qr_code_2),
            label: const Text('استلام بـ QR (العميل يمسح ويدفع)', style: TextStyle(fontSize: 15, fontWeight: FontWeight.bold)),
            style: FilledButton.styleFrom(
              backgroundColor: AmyalColors.primary, minimumSize: const Size.fromHeight(54)),
          )),
          const SizedBox(height: 8),
          Row(children: [
            Expanded(child: OutlinedButton.icon(
              onPressed: c.isSubmitting.value ? null : _payCash,
              icon: const Icon(Icons.payments_outlined),
              label: const Text('نقد'),
            )),
            const SizedBox(width: 8),
            Expanded(child: OutlinedButton.icon(
              onPressed: c.isSubmitting.value ? null : _payAmial,
              icon: const Icon(Icons.phone_iphone),
              label: const Text('بالهاتف'),
            )),
            const SizedBox(width: 8),
            Expanded(child: OutlinedButton.icon(
              onPressed: () => Get.to(() => const FuelSalesHistoryScreen()),
              icon: const Icon(Icons.history),
              label: const Text('السجل'),
            )),
          ]),
          const SizedBox(height: 20),

          // ===== آخر العمليات =====
          Obx(() {
            if (c.sales.isEmpty) return const SizedBox.shrink();
            return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              const Text('آخر العمليات', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 14)),
              const SizedBox(height: 8),
              ...c.sales.take(5).map(_saleRow),
            ]);
          }),
        ]),
      ),
    );
  }

  Widget _selector({required String label, required String value, VoidCallback? onTap}) {
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(12),
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 12),
        decoration: BoxDecoration(
          color: Colors.white, borderRadius: BorderRadius.circular(12),
          border: Border.all(color: AmyalColors.border),
        ),
        child: Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
          const Icon(Icons.expand_more, size: 18, color: AmyalColors.textMuted),
          Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
            Text(label, style: const TextStyle(fontSize: 10, color: AmyalColors.textMuted)),
            Text(value, style: const TextStyle(fontWeight: FontWeight.bold)),
          ]),
        ]),
      ),
    );
  }

  Widget _modeTab(String mode, String label) {
    final sel = _mode == mode;
    return Expanded(child: InkWell(
      onTap: () => setState(() { _mode = mode; _entry = ''; }),
      child: Container(
        padding: const EdgeInsets.symmetric(vertical: 12),
        decoration: BoxDecoration(
          color: sel ? AmyalColors.primary : Colors.transparent,
          borderRadius: BorderRadius.circular(12),
        ),
        alignment: Alignment.center,
        child: Text(label, style: TextStyle(
          color: sel ? Colors.white : AmyalColors.textSecondary,
          fontWeight: FontWeight.bold)),
      ),
    ));
  }

  Widget _quick(String amount) {
    return Expanded(child: InkWell(
      onTap: () => setState(() { _mode = 'by_amount'; _entry = amount; }),
      borderRadius: BorderRadius.circular(10),
      child: Container(
        padding: const EdgeInsets.symmetric(vertical: 16),
        alignment: Alignment.center,
        decoration: BoxDecoration(
          color: const Color(0xFFEEF1F6), borderRadius: BorderRadius.circular(10)),
        child: Text(_fmt(int.parse(amount)),
            style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16)),
      ),
    ));
  }

  Widget _keypadRow(List<String> keys) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 8),
      child: Row(children: keys.map((k) => Expanded(child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 4),
        child: InkWell(
          onTap: () => _tap(k),
          borderRadius: BorderRadius.circular(10),
          child: Container(
            padding: const EdgeInsets.symmetric(vertical: 18),
            alignment: Alignment.center,
            decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(10)),
            child: k == '<'
                ? const Icon(Icons.backspace_outlined, size: 20)
                : Text(k, style: TextStyle(fontSize: 20, fontWeight: FontWeight.bold,
                    color: k == 'C' ? AmyalColors.red : AmyalColors.textPrimary)),
          ),
        ),
      ))).toList()),
    );
  }

  Widget _saleRow(Map<String, dynamic> s) {
    final method = s['payment_method']?.toString() ?? '';
    return Container(
      margin: const EdgeInsets.only(bottom: 8),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(10)),
      child: Row(children: [
        Container(
          height: 36, width: 36, alignment: Alignment.center,
          decoration: BoxDecoration(
            color: AmyalColors.yellow.withValues(alpha: 0.3), shape: BoxShape.circle),
          child: const Icon(Icons.local_gas_station, size: 18, color: AmyalColors.primary),
        ),
        const SizedBox(width: 10),
        Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text('تعبئة — ${_fmt(double.tryParse('${s['liters'] ?? 0}') ?? 0)} لتر',
              style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13)),
          Text(method == 'cash' ? 'نقد' : (method == 'amial_pay' ? 'أميال باي' : 'حساب شركة'),
              style: const TextStyle(fontSize: 11, color: AmyalColors.textMuted)),
        ])),
        Text('${_fmt(double.tryParse('${s['total_amount'] ?? 0}') ?? 0)} ر.ي',
            style: const TextStyle(fontWeight: FontWeight.bold)),
      ]),
    );
  }

  void _pickPump() {
    showModalBottomSheet(context: context, builder: (_) => SafeArea(
      child: Column(mainAxisSize: MainAxisSize.min, children: [
        const Padding(padding: EdgeInsets.all(12),
            child: Text('اختر المضخّة', style: TextStyle(fontWeight: FontWeight.bold))),
        ...c.pumps.map((p) => ListTile(
          leading: const Icon(Icons.local_gas_station, color: AmyalColors.primary),
          title: Text('مضخّة #${p['pump_number']}'),
          subtitle: Text(p['pump_type'] == 'mechanical' ? 'يدوية' : 'إلكترونية'),
          selected: _pump?['id'] == p['id'],
          onTap: () { setState(() => _pump = p); Navigator.pop(context); },
        )),
      ]),
    ));
  }

  void _pickProduct() {
    showModalBottomSheet(context: context, builder: (_) => SafeArea(
      child: Column(mainAxisSize: MainAxisSize.min, children: [
        const Padding(padding: EdgeInsets.all(12),
            child: Text('اختر نوع الوقود', style: TextStyle(fontWeight: FontWeight.bold))),
        ...c.products.map((p) => ListTile(
          leading: const Icon(Icons.oil_barrel, color: AmyalColors.yellowDark),
          title: Text('${p['name']}'),
          trailing: Text('${p['price_per_liter']} ر.ي/لتر'),
          selected: _product?['id'] == p['id'],
          onTap: () { setState(() => _product = p); Navigator.pop(context); },
        )),
      ]),
    ));
  }
}
