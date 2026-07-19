import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/data/api/api_client.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';

/// AMIAL-MULTI-CURRENCY-001 — «العملات» (باقة التاجر برو فأعلى).
///
/// التاجر يضيف عملات بأسعار صرف مقابل الريال؛ تظهر مكافئاتها على الفواتير.
class MerchantCurrenciesScreen extends StatefulWidget {
  const MerchantCurrenciesScreen({super.key});

  @override
  State<MerchantCurrenciesScreen> createState() => _MerchantCurrenciesScreenState();
}

class _MerchantCurrenciesScreenState extends State<MerchantCurrenciesScreen> {
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
      final r = await _api.getData('/api/v1/amial/merchant/currencies');
      if (r.statusCode == 402) {
        setState(() { _error = 'تعدّد العملات متاح في باقة التاجر برو فأعلى'; _loading = false; });
        return;
      }
      if (r.statusCode == 200 && r.body is Map && r.body['success'] == true) {
        setState(() => _list = (((r.body['meta'] ?? {})['currencies'] ?? []) as List)
            .map((e) => Map<String, dynamic>.from(e as Map)).toList());
      }
    } catch (_) {
      _error = 'خطأ في الشبكة';
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  void _snack(String m, {bool ok = false}) => ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(content: Text(m), backgroundColor: ok ? const Color(0xFF2E7D32) : AmyalColors.red));

  Future<void> _editDialog({Map<String, dynamic>? existing}) async {
    final code = TextEditingController(text: existing?['code'] ?? '');
    final name = TextEditingController(text: existing?['name'] ?? '');
    final symbol = TextEditingController(text: existing?['symbol'] ?? '');
    final rate = TextEditingController(text: existing?['rate_to_base']?.toString() ?? '');
    final isEdit = existing != null;

    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(isEdit ? 'تعديل العملة' : 'عملة جديدة'),
        content: SingleChildScrollView(child: Column(mainAxisSize: MainAxisSize.min, children: [
          TextField(controller: code, textCapitalization: TextCapitalization.characters,
              enabled: !isEdit, textDirection: TextDirection.ltr,
              decoration: const InputDecoration(labelText: 'الرمز (USD/SAR)', border: OutlineInputBorder())),
          const SizedBox(height: 10),
          TextField(controller: name, decoration: const InputDecoration(labelText: 'الاسم', border: OutlineInputBorder())),
          const SizedBox(height: 10),
          TextField(controller: symbol, decoration: const InputDecoration(labelText: 'العلامة (\$ / ﷼) — اختياري', border: OutlineInputBorder())),
          const SizedBox(height: 10),
          TextField(controller: rate, keyboardType: const TextInputType.numberWithOptions(decimal: true),
              decoration: const InputDecoration(labelText: 'كم ريال = 1 وحدة', hintText: 'مثال: 530', border: OutlineInputBorder())),
        ])),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('إلغاء')),
          FilledButton(onPressed: () => Navigator.pop(ctx, true), child: Text(isEdit ? 'حفظ' : 'إضافة')),
        ],
      ),
    );
    if (ok != true || !mounted) return;
    if ((double.tryParse(rate.text) ?? 0) <= 0 || (isEdit ? false : (code.text.trim().isEmpty || name.text.trim().isEmpty))) {
      _snack('أكمل البيانات وسعر صرف صحيح'); return;
    }
    final body = {
      if (!isEdit) 'code': code.text.trim(),
      'name': name.text.trim(),
      'symbol': symbol.text.trim(),
      'rate_to_base': rate.text.trim(),
    };
    final r = isEdit
        ? await _api.postData('/api/v1/amial/merchant/currencies/${existing['id']}', body)
        : await _api.postData('/api/v1/amial/merchant/currencies', body);
    if (r.statusCode == 200 || r.statusCode == 201) { _snack('تم الحفظ', ok: true); _load(); }
    else { _snack((r.body is Map ? r.body['message']?.toString() : null) ?? 'تعذّر الحفظ'); }
  }

  Future<void> _toggle(int id) async {
    final r = await _api.postData('/api/v1/amial/merchant/currencies/$id/toggle', {});
    if (r.statusCode == 200) _load(); else _snack('تعذّر');
  }

  Future<void> _delete(int id) async {
    final r = await _api.deleteData('/api/v1/amial/merchant/currencies/$id');
    if (r.statusCode == 200) { _snack('تم الحذف', ok: true); _load(); } else { _snack('تعذّر الحذف'); }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmyalColors.background,
      appBar: AppBar(title: const Text('العملات'),
          backgroundColor: AmyalColors.primary, foregroundColor: Colors.white),
      floatingActionButton: _error == null
          ? FloatingActionButton.extended(onPressed: () => _editDialog(),
              backgroundColor: AmyalColors.primary, icon: const Icon(Icons.add), label: const Text('عملة جديدة'))
          : null,
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : _error != null
              ? Center(child: Padding(padding: const EdgeInsets.all(24),
                  child: Column(mainAxisSize: MainAxisSize.min, children: [
                    const Icon(Icons.workspace_premium, size: 56, color: AmyalColors.yellowDark),
                    const SizedBox(height: 12),
                    Text(_error!, textAlign: TextAlign.center,
                        style: const TextStyle(fontSize: 15, fontWeight: FontWeight.w600)),
                  ])))
              : RefreshIndicator(
                  onRefresh: _load,
                  child: ListView(padding: const EdgeInsets.all(12), children: [
                    const Padding(padding: EdgeInsets.all(8),
                        child: Text('العملة الأساس: الريال اليمني (ر.ي). أضِف عملات بأسعار صرفها لتظهر مكافئاتها على الفواتير.',
                            style: TextStyle(fontSize: 12, color: AmyalColors.textSecondary))),
                    if (_list.isEmpty) const Padding(padding: EdgeInsets.symmetric(vertical: 40),
                        child: Center(child: Text('لا عملات مضافة'))),
                    ..._list.map(_card),
                  ]),
                ),
    );
  }

  Widget _card(Map<String, dynamic> c) {
    final active = c['is_active'] == true;
    return Container(
      margin: const EdgeInsets.only(bottom: 8),
      decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(12)),
      child: ListTile(
        leading: CircleAvatar(
          backgroundColor: AmyalColors.primary.withValues(alpha: 0.1),
          child: Text('${c['symbol'] ?? c['code']}', style: const TextStyle(fontWeight: FontWeight.bold, color: AmyalColors.primary, fontSize: 12)),
        ),
        title: Text('${c['code']} — ${c['name']}', style: const TextStyle(fontWeight: FontWeight.bold)),
        subtitle: Text('1 ${c['code']} = ${c['rate_to_base']} ر.ي', style: const TextStyle(fontSize: 11)),
        trailing: Row(mainAxisSize: MainAxisSize.min, children: [
          IconButton(icon: const Icon(Icons.edit, size: 20, color: AmyalColors.primary),
              onPressed: () => _editDialog(existing: c)),
          Switch(value: active, activeColor: AmyalColors.primary, onChanged: (_) => _toggle(c['id'] as int)),
          IconButton(icon: const Icon(Icons.delete_outline, size: 20, color: AmyalColors.red),
              onPressed: () => _delete(c['id'] as int)),
        ]),
      ),
    );
  }
}
