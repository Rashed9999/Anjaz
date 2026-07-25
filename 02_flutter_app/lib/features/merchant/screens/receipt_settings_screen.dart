import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:image_picker/image_picker.dart';
import 'package:amyal_pay/features/merchant/controllers/receipt_settings_controller.dart';
import 'package:amyal_pay/features/payments/widgets/amial_invoice_card.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';

/// AMIAL-RECEIPT-SETTINGS-001 — «إعدادات الفاتورة والطباعة».
///
/// التاجر يخصّص فاتورته (شعار/اسم/ترويسة/تذييل/هاتف/عنوان) وإعداد الطباعة
/// (عرض الورق 58/80مم) مع معاينة حيّة. يُطبَّق على كل شاشات الفاتورة الموحّدة.
class ReceiptSettingsScreen extends StatefulWidget {
  const ReceiptSettingsScreen({super.key});

  @override
  State<ReceiptSettingsScreen> createState() => _ReceiptSettingsScreenState();
}

class _ReceiptSettingsScreenState extends State<ReceiptSettingsScreen> {
  late final ReceiptSettingsController c;
  final _store = TextEditingController();
  final _header = TextEditingController();
  final _footer = TextEditingController();
  final _phone = TextEditingController();
  final _address = TextEditingController();
  bool _showLogo = true, _showPhone = true, _showAddress = true;
  int _paper = 80;
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    c = Get.isRegistered<ReceiptSettingsController>()
        ? Get.find<ReceiptSettingsController>()
        : Get.put(ReceiptSettingsController(), permanent: true);
    _init();
  }

  Future<void> _init() async {
    await c.load(force: true);
    final s = c.effective;
    _store.text = '${s['store_name'] ?? ''}';
    _header.text = '${s['header_note'] ?? ''}';
    _footer.text = '${s['footer_note'] ?? ''}';
    _phone.text = '${s['phone'] ?? ''}';
    _address.text = '${s['address'] ?? ''}';
    _showLogo = s['show_logo'] == true;
    _showPhone = s['show_phone'] == true;
    _showAddress = s['show_address'] == true;
    _paper = (s['paper_width'] == 58) ? 58 : 80;
    if (mounted) setState(() => _loading = false);
  }

  @override
  void dispose() {
    for (final ctl in [_store, _header, _footer, _phone, _address]) {
      ctl.dispose();
    }
    super.dispose();
  }

  Map<String, dynamic> get _preview => {
        ...c.effective,
        'store_name': _store.text,
        'header_note': _header.text,
        'footer_note': _footer.text,
        'phone': _phone.text,
        'address': _address.text,
        'show_logo': _showLogo,
        'show_phone': _showPhone,
        'show_address': _showAddress,
        'paper_width': _paper,
      };

  Future<void> _pickLogo() async {
    try {
      final x = await ImagePicker().pickImage(source: ImageSource.gallery, maxWidth: 600, imageQuality: 80);
      if (x == null) return;
      final bytes = await x.readAsBytes();
      final b64 = base64Encode(bytes);
      final url = await c.uploadLogo(b64);
      if (!mounted) return;
      _snack(url != null ? 'تم رفع الشعار' : 'تعذّر رفع الشعار', ok: url != null);
      setState(() {});
    } catch (_) {
      _snack('تعذّر اختيار الصورة');
    }
  }

  Future<void> _save() async {
    final ok = await c.save({
      'store_name': _store.text.trim(),
      'header_note': _header.text.trim(),
      'footer_note': _footer.text.trim(),
      'phone': _phone.text.trim(),
      'address': _address.text.trim(),
      'show_logo': _showLogo,
      'show_phone': _showPhone,
      'show_address': _showAddress,
      'paper_width': _paper,
    });
    if (!mounted) return;
    _snack(ok ? 'تم حفظ الإعدادات ✓' : 'تعذّر الحفظ', ok: ok);
    if (ok) Get.back();
  }

  void _snack(String m, {bool ok = false}) => ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(content: Text(m), backgroundColor: ok ? const Color(0xFF2E7D32) : AmyalColors.red));

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmyalColors.background,
      appBar: AppBar(
        title: const Text('إعدادات الفاتورة والطباعة'),
      ),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : ListView(padding: const EdgeInsets.all(16), children: [
              // معاينة حيّة
              Center(
                child: Container(
                  padding: const EdgeInsets.all(8),
                  decoration: BoxDecoration(
                    color: Colors.grey.shade200, borderRadius: BorderRadius.circular(12)),
                  child: AmialInvoiceCard(
                    settings: _preview,
                    title: 'فاتورة بيع (معاينة)',
                    rows: const [('صنف تجريبي', '1,000 ر.ي'), ('صنف آخر', '500 ر.ي')],
                    total: '1,500',
                    method: 'نقداً',
                    reference: 'PREVIEW',
                    dateTime: 'الآن',
                  ),
                ),
              ),
              const SizedBox(height: 20),

              _field('اسم المتجر', _store),
              Row(children: [
                Expanded(child: OutlinedButton.icon(
                  onPressed: _pickLogo,
                  icon: const Icon(Icons.image_outlined, size: 18),
                  label: const Text('رفع شعار'),
                )),
                const SizedBox(width: 8),
                Expanded(child: SwitchListTile(
                  contentPadding: EdgeInsets.zero,
                  title: const Text('إظهار الشعار', style: TextStyle(fontSize: 13)),
                  value: _showLogo, activeColor: AmyalColors.primary,
                  onChanged: (v) => setState(() => _showLogo = v),
                )),
              ]),
              _field('سطر الترويسة (تحت الاسم)', _header),
              _field('سطر التذييل', _footer),
              _field('هاتف المتجر', _phone, ltr: true),
              SwitchListTile(
                contentPadding: EdgeInsets.zero,
                title: const Text('إظهار الهاتف'),
                value: _showPhone, activeColor: AmyalColors.primary,
                onChanged: (v) => setState(() => _showPhone = v),
              ),
              _field('العنوان', _address),
              SwitchListTile(
                contentPadding: EdgeInsets.zero,
                title: const Text('إظهار العنوان'),
                value: _showAddress, activeColor: AmyalColors.primary,
                onChanged: (v) => setState(() => _showAddress = v),
              ),
              const SizedBox(height: 8),
              const Text('عرض ورق الطباعة',
                  style: TextStyle(fontWeight: FontWeight.bold, fontSize: 13)),
              const SizedBox(height: 6),
              Row(children: [
                _paperChip(58, '58 مم'),
                const SizedBox(width: 10),
                _paperChip(80, '80 مم'),
              ]),
              const SizedBox(height: 24),
              Obx(() => FilledButton.icon(
                    onPressed: c.isSaving.value ? null : _save,
                    icon: c.isSaving.value
                        ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(color: Colors.white, strokeWidth: 2))
                        : const Icon(Icons.save),
                    label: const Text('حفظ الإعدادات'),
                    style: FilledButton.styleFrom(
                        backgroundColor: AmyalColors.primary, minimumSize: const Size.fromHeight(52)),
                  )),
            ]),
    );
  }

  Widget _field(String label, TextEditingController ctl, {bool ltr = false}) => Padding(
        padding: const EdgeInsets.only(bottom: 12),
        child: TextField(
          controller: ctl,
          textDirection: ltr ? TextDirection.ltr : null,
          onChanged: (_) => setState(() {}),
          decoration: InputDecoration(labelText: label, border: const OutlineInputBorder()),
        ),
      );

  Widget _paperChip(int val, String label) {
    final sel = _paper == val;
    return Expanded(
      child: InkWell(
        onTap: () => setState(() => _paper = val),
        borderRadius: BorderRadius.circular(10),
        child: Container(
          padding: const EdgeInsets.symmetric(vertical: 14),
          alignment: Alignment.center,
          decoration: BoxDecoration(
            color: sel ? AmyalColors.primary : Colors.white,
            borderRadius: BorderRadius.circular(10),
            border: Border.all(color: sel ? AmyalColors.primary : AmyalColors.border),
          ),
          child: Text(label, style: TextStyle(
              fontWeight: FontWeight.bold, color: sel ? Colors.white : AmyalColors.textPrimary)),
        ),
      ),
    );
  }
}
