import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amial_pay/data/api/api_client.dart';
import 'package:amial_pay/theme/amial_colors.dart';

/// AMIAL-PROMOTIONS-001 — «العروض والخصومات» (باقة ستارتر فأعلى).
///
/// التاجر ينشئ خصومات تلقائية أو كوبونات بكود، تُطبَّق في شاشة الدفع على الفاتورة.
class MerchantPromotionsScreen extends StatefulWidget {
  const MerchantPromotionsScreen({super.key});

  @override
  State<MerchantPromotionsScreen> createState() => _MerchantPromotionsScreenState();
}

class _MerchantPromotionsScreenState extends State<MerchantPromotionsScreen> {
  final _api = Get.find<ApiClient>();
  bool _loading = true;
  String? _error;
  List<Map<String, dynamic>> _list = [];

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() { _loading = true; _error = null; });
    try {
      final r = await _api.getData('/api/v1/amial/merchant/promotions');
      if (r.statusCode == 402) {
        setState(() { _error = 'العروض والخصومات متاحة في باقة ستارتر فأعلى'; _loading = false; });
        return;
      }
      if (r.statusCode == 200 && r.body is Map) {
        setState(() => _list = (((r.body['meta'] ?? {})['promotions'] ?? []) as List)
            .map((e) => Map<String, dynamic>.from(e as Map)).toList());
      }
    } catch (_) {
      _error = 'خطأ في الشبكة';
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  void _snack(String m, {bool ok = false}) => ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(content: Text(m), backgroundColor: ok ? const Color(0xFF2E7D32) : AmialColors.red));

  Future<void> _editDialog({Map<String, dynamic>? existing}) async {
    final isEdit = existing != null;
    final name = TextEditingController(text: existing?['name'] ?? '');
    final value = TextEditingController(text: existing?['value']?.toString() ?? '');
    final code = TextEditingController(text: existing?['code'] ?? '');
    final minOrder = TextEditingController(text: existing?['min_order_amount']?.toString() ?? '');
    final maxDisc = TextEditingController(text: existing?['max_discount_amount']?.toString() ?? '');
    final usage = TextEditingController(text: existing?['usage_limit']?.toString() ?? '');
    String type = existing?['type'] ?? 'percent';

    final ok = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.white,
      shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(24))),
      builder: (ctx) => StatefulBuilder(builder: (ctx, setLocal) => Padding(
        padding: EdgeInsets.fromLTRB(20, 16, 20, 20 + MediaQuery.of(ctx).viewInsets.bottom),
        child: SingleChildScrollView(
          child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            Text(isEdit ? 'تعديل العرض' : 'عرض جديد',
                textAlign: TextAlign.center, style: const TextStyle(fontSize: 16, fontWeight: FontWeight.bold)),
            const SizedBox(height: 14),
            TextField(controller: name, decoration: const InputDecoration(labelText: 'اسم العرض *', border: OutlineInputBorder())),
            const SizedBox(height: 10),
            Row(children: [
              Expanded(child: SegmentedButton<String>(
                segments: const [
                  ButtonSegment(value: 'percent', label: Text('نسبة %')),
                  ButtonSegment(value: 'fixed', label: Text('مبلغ ثابت')),
                ],
                selected: {type},
                onSelectionChanged: (s) => setLocal(() => type = s.first),
              )),
            ]),
            const SizedBox(height: 10),
            TextField(controller: value, keyboardType: const TextInputType.numberWithOptions(decimal: true),
                decoration: InputDecoration(labelText: type == 'percent' ? 'النسبة % *' : 'المبلغ (ر.ي) *', border: const OutlineInputBorder())),
            const SizedBox(height: 10),
            TextField(controller: code, textDirection: TextDirection.ltr,
                decoration: const InputDecoration(labelText: 'رمز الكوبون (فارغ = خصم تلقائي)', border: OutlineInputBorder())),
            const SizedBox(height: 10),
            TextField(controller: minOrder, keyboardType: const TextInputType.numberWithOptions(decimal: true),
                decoration: const InputDecoration(labelText: 'أدنى مبلغ فاتورة — اختياري', border: OutlineInputBorder())),
            const SizedBox(height: 10),
            if (type == 'percent')
              TextField(controller: maxDisc, keyboardType: const TextInputType.numberWithOptions(decimal: true),
                  decoration: const InputDecoration(labelText: 'سقف الخصم (ر.ي) — اختياري', border: OutlineInputBorder())),
            if (type == 'percent') const SizedBox(height: 10),
            TextField(controller: usage, keyboardType: TextInputType.number,
                decoration: const InputDecoration(labelText: 'حدّ الاستخدام — اختياري', border: OutlineInputBorder())),
            const SizedBox(height: 16),
            FilledButton(
              onPressed: () => Navigator.pop(ctx, true),
              style: FilledButton.styleFrom(backgroundColor: AmialColors.primary, minimumSize: const Size.fromHeight(50)),
              child: Text(isEdit ? 'حفظ' : 'إنشاء'),
            ),
          ]),
        ),
      )),
    );
    if (ok != true || !mounted) return;
    if (name.text.trim().isEmpty || (double.tryParse(value.text.trim()) ?? 0) <= 0) {
      _snack('أكمل الاسم وقيمة الخصم'); return;
    }
    final body = {
      'name': name.text.trim(),
      'type': type,
      'value': value.text.trim(),
      if (code.text.trim().isNotEmpty) 'code': code.text.trim(),
      if (minOrder.text.trim().isNotEmpty) 'min_order_amount': minOrder.text.trim(),
      if (type == 'percent' && maxDisc.text.trim().isNotEmpty) 'max_discount_amount': maxDisc.text.trim(),
      if (usage.text.trim().isNotEmpty) 'usage_limit': int.tryParse(usage.text.trim()),
    };
    final r = isEdit
        ? await _api.postData('/api/v1/amial/merchant/promotions/${existing['id']}', body)
        : await _api.postData('/api/v1/amial/merchant/promotions', body);
    if (r.statusCode == 200 || r.statusCode == 201) { _snack('تم الحفظ', ok: true); _load(); }
    else { _snack((r.body is Map ? r.body['message']?.toString() : null) ?? 'تعذّر الحفظ'); }
  }

  Future<void> _toggle(int id) async {
    final r = await _api.postData('/api/v1/amial/merchant/promotions/$id/toggle', {});
    if (r.statusCode == 200) _load(); else _snack('تعذّر');
  }

  Future<void> _delete(int id) async {
    final r = await _api.deleteData('/api/v1/amial/merchant/promotions/$id');
    if (r.statusCode == 200) { _snack('تم الحذف', ok: true); _load(); } else { _snack('تعذّر الحذف'); }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(title: const Text('العروض والخصومات'),
          backgroundColor: AmialColors.primary, foregroundColor: Colors.white),
      floatingActionButton: _error == null
          ? FloatingActionButton.extended(onPressed: () => _editDialog(),
              backgroundColor: AmialColors.primary, icon: const Icon(Icons.add), label: const Text('عرض جديد'))
          : null,
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : _error != null
              ? Center(child: Padding(padding: const EdgeInsets.all(24),
                  child: Column(mainAxisSize: MainAxisSize.min, children: [
                    const Icon(Icons.workspace_premium, size: 56, color: AmialColors.yellowDark),
                    const SizedBox(height: 12),
                    Text(_error!, textAlign: TextAlign.center,
                        style: const TextStyle(fontSize: 15, fontWeight: FontWeight.w600)),
                  ])))
              : RefreshIndicator(
                  onRefresh: _load,
                  child: ListView(padding: const EdgeInsets.all(12), children: [
                    const Padding(padding: EdgeInsets.all(8),
                        child: Text('الخصومات التلقائية تُطبَّق عند بلوغ الحدّ الأدنى، والكوبونات تُطبَّق بإدخال رمزها في شاشة الدفع.',
                            style: TextStyle(fontSize: 12, color: AmialColors.textSecondary))),
                    if (_list.isEmpty) const Padding(padding: EdgeInsets.symmetric(vertical: 40),
                        child: Center(child: Text('لا عروض — أنشئ عرضاً لزيادة مبيعاتك'))),
                    ..._list.map(_card),
                  ]),
                ),
    );
  }

  Widget _card(Map<String, dynamic> p) {
    final active = p['is_active'] == true;
    final isPercent = p['type'] == 'percent';
    final valueLabel = isPercent ? '${p['value']}%' : '${p['value']} ر.ي';
    final code = (p['code'] ?? '').toString();
    final limit = p['usage_limit'];
    return Container(
      margin: const EdgeInsets.only(bottom: 8),
      decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(12)),
      child: ListTile(
        leading: CircleAvatar(
          backgroundColor: (active ? AmialColors.primary : AmialColors.textSecondary).withValues(alpha: 0.12),
          child: Icon(code.isEmpty ? Icons.local_offer : Icons.confirmation_number,
              color: active ? AmialColors.primary : AmialColors.textSecondary, size: 20),
        ),
        title: Text('${p['name']} — $valueLabel', style: const TextStyle(fontWeight: FontWeight.bold)),
        subtitle: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          if (code.isNotEmpty) Text('كوبون: $code', textDirection: TextDirection.ltr, style: const TextStyle(fontSize: 11)),
          Text([
            if ((double.tryParse('${p['min_order_amount']}') ?? 0) > 0) 'حدّ أدنى ${p['min_order_amount']} ر.ي',
            if (limit != null) 'استُخدم ${p['used_count']}/$limit',
          ].join(' • '), style: const TextStyle(fontSize: 10, color: AmialColors.textSecondary)),
        ]),
        trailing: Row(mainAxisSize: MainAxisSize.min, children: [
          IconButton(icon: const Icon(Icons.edit, size: 20, color: AmialColors.primary),
              onPressed: () => _editDialog(existing: p)),
          Switch(value: active, activeColor: AmialColors.primary, onChanged: (_) => _toggle(p['id'] as int)),
          IconButton(icon: const Icon(Icons.delete_outline, size: 20, color: AmialColors.red),
              onPressed: () => _delete(p['id'] as int)),
        ]),
      ),
    );
  }
}
