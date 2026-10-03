import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amial_pay/data/api/api_client.dart';
import 'package:amial_pay/theme/amial_colors.dart';

/// AMIAL-EXPENSES-001 — «المصروفات» (باقة الأعمال فأعلى).
///
/// التاجر يسجّل مصاريفه (إيجار/رواتب/كهرباء…) لتُخصم من الأرباح الحقيقية،
/// مع ملخّص بالإجمالي وحسب الفئة.
class MerchantExpensesScreen extends StatefulWidget {
  const MerchantExpensesScreen({super.key});

  @override
  State<MerchantExpensesScreen> createState() => _MerchantExpensesScreenState();
}

const _cats = {
  'rent': 'إيجار', 'salary': 'رواتب', 'utilities': 'كهرباء/ماء',
  'supplies': 'مستلزمات', 'transport': 'نقل', 'other': 'أخرى',
};

class _MerchantExpensesScreenState extends State<MerchantExpensesScreen> {
  final _api = Get.find<ApiClient>();
  bool _loading = true;
  String? _error;
  List<Map<String, dynamic>> _items = [];
  String _total = '0';
  Map<String, dynamic> _byCat = {};

  @override
  void initState() {
    super.initState();
    _load();
  }

  // ══════════════════════════════════════════════════════════════════
  // AMIAL-EXPENSES-DIAG-001 — **رسالةٌ تدلّ على سببها.**
  //
  // كان كلُّ فشلٍ يُبتلع في `catch (_)` ويخرج «خطأ في الشبكة» — فرفضُ
  // صلاحيّةٍ (٤٠٣) وانتهاءُ جلسةٍ (٤٠١) وعطلُ خادمٍ (٥٠٠) وانقطاعُ
  // إنترنتٍ كلُّها تُقرأ شيئاً واحداً. فيُطارَد العطلُ في الشبكة وهو في
  // الحساب، **ويصل إليّ بلاغٌ لا يمكن تشخيصُه.**
  //
  // (وهو نمطُ العطل الذي دفع المشروعُ ثمنَه مراراً: «الرسالةُ لا تدلّ
  // على سببها».) فتُميَّز الحالاتُ الآن، ويُذكَر الرمزُ لما لم يُتوقَّع.
  // ══════════════════════════════════════════════════════════════════
  Future<void> _load() async {
    setState(() { _loading = true; _error = null; });
    try {
      final r = await _api.getData('/api/v1/amial/merchant/expenses');

      if (r.statusCode == 200 && r.body is Map) {
        final meta = (r.body['meta'] ?? {}) as Map;
        _items = ((meta['expenses'] ?? []) as List).map((e) => Map<String, dynamic>.from(e as Map)).toList();
        _total = '${meta['total'] ?? '0'}';
        _byCat = Map<String, dynamic>.from((meta['by_category'] ?? {}) as Map);
        return;
      }

      // رسالةُ الخادم أدقُّ ما يمكن قولُه — تُقدَّم على أيّ نصٍّ عندنا.
      String? serverSays;
      try {
        if (r.body is Map && r.body['message'] != null) {
          final m = '${r.body['message']}';
          if (m.isNotEmpty) serverSays = m;
        }
      } catch (_) {}

      _error = switch (r.statusCode) {
        402 => serverSays ?? 'المصروفات متاحة في باقة الأعمال فأعلى',
        403 => serverSays ?? 'هذه الصفحة لحساب التاجر — الحساب الحالي لا يملك صلاحيتها',
        401 => 'انتهت الجلسة — سجّل الدخول من جديد',
        -1 => 'أوقف VPN ثم حاول مجدداً',
        0 || 1 => 'لا اتصال بالإنترنت',
        _ => serverSays ?? 'تعذّر فتح المصروفات (رمز ${r.statusCode})',
      };
    } catch (e) {
      // **ويُقال إنّه غيرُ متوقَّع** — لا يُلبَس ثوبَ عطلِ شبكة.
      _error = 'تعذّر فتح المصروفات — خطأ غير متوقَّع';
      if (kDebugMode) debugPrint('expenses load: $e');
    }
    finally { if (mounted) setState(() => _loading = false); }
  }

  void _snack(String m, {bool ok = false}) => ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(content: Text(m), backgroundColor: ok ? AmialColors.success : AmialColors.red));

  Future<void> _edit({Map<String, dynamic>? existing}) async {
    final isEdit = existing != null;
    final title = TextEditingController(text: existing?['title'] ?? '');
    final amount = TextEditingController(text: existing?['amount']?.toString() ?? '');
    String cat = existing?['category'] ?? 'other';

    final ok = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.white,
      shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(24))),
      builder: (ctx) => StatefulBuilder(builder: (ctx, setLocal) => Padding(
        padding: EdgeInsets.fromLTRB(20, 16, 20, 20 + MediaQuery.of(ctx).viewInsets.bottom),
        child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Text(isEdit ? 'تعديل مصروف' : 'مصروف جديد', textAlign: TextAlign.center,
              style: const TextStyle(fontSize: 16, fontWeight: FontWeight.bold)),
          const SizedBox(height: 12),
          TextField(controller: title, decoration: const InputDecoration(labelText: 'البيان *', border: OutlineInputBorder())),
          const SizedBox(height: 10),
          TextField(controller: amount, keyboardType: TextInputType.number,
              decoration: const InputDecoration(labelText: 'المبلغ ر.ي *', border: OutlineInputBorder())),
          const SizedBox(height: 10),
          DropdownButtonFormField<String>(
            initialValue: cat,
            decoration: const InputDecoration(labelText: 'الفئة', border: OutlineInputBorder()),
            items: _cats.entries.map((e) => DropdownMenuItem(value: e.key, child: Text(e.value))).toList(),
            onChanged: (v) => setLocal(() => cat = v ?? cat),
          ),
          const SizedBox(height: 14),
          FilledButton(onPressed: () => Navigator.pop(ctx, true),
              style: FilledButton.styleFrom(backgroundColor: AmialColors.primary, minimumSize: const Size.fromHeight(50)),
              child: Text(isEdit ? 'حفظ' : 'تسجيل')),
        ]),
      )),
    );
    if (ok != true) return;
    if (title.text.trim().isEmpty || (double.tryParse(amount.text.trim()) ?? 0) <= 0) { _snack('أكمل البيان والمبلغ'); return; }
    final body = {'title': title.text.trim(), 'amount': amount.text.trim(), 'category': cat};
    final r = isEdit
        ? await _api.postData('/api/v1/amial/merchant/expenses/${existing['id']}', body)
        : await _api.postData('/api/v1/amial/merchant/expenses', body);
    if (r.statusCode == 200 || r.statusCode == 201) { _snack('تم الحفظ', ok: true); _load(); }
    else { _snack((r.body is Map ? r.body['message']?.toString() : null) ?? 'تعذّر'); }
  }

  Future<void> _delete(int id) async {
    final r = await _api.deleteData('/api/v1/amial/merchant/expenses/$id');
    if (r.statusCode == 200) { _snack('تم الحذف', ok: true); _load(); } else { _snack('تعذّر'); }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(title: const Text('المصروفات'), backgroundColor: AmialColors.primary, foregroundColor: Colors.white),
      floatingActionButton: _error == null
          ? FloatingActionButton.extended(onPressed: () => _edit(), backgroundColor: AmialColors.primary,
              icon: const Icon(Icons.add), label: const Text('مصروف'))
          : null,
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : _error != null
              ? Center(child: Padding(padding: const EdgeInsets.all(24),
                  child: Column(mainAxisSize: MainAxisSize.min, children: [
                    const Icon(Icons.workspace_premium, size: 56, color: AmialColors.yellowDark),
                    const SizedBox(height: 12),
                    Text(_error!, textAlign: TextAlign.center, style: const TextStyle(fontSize: 15, fontWeight: FontWeight.w600)),
                  ])))
              : RefreshIndicator(
                  onRefresh: _load,
                  child: ListView(padding: const EdgeInsets.all(12), children: [
                    _summary(),
                    const SizedBox(height: 12),
                    if (_items.isEmpty) const Padding(padding: EdgeInsets.symmetric(vertical: 30),
                        child: Center(child: Text('لا مصاريف مسجّلة'))),
                    ..._items.map(_tile),
                  ]),
                ),
    );
  }

  Widget _summary() => Container(
        padding: const EdgeInsets.all(16),
        decoration: BoxDecoration(
          gradient: const LinearGradient(colors: [Color(0xFFC0392B), Color(0xFFE74C3C)],
              begin: Alignment.topLeft, end: Alignment.bottomRight),
          borderRadius: BorderRadius.circular(16),
        ),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          const Text('إجمالي المصروفات', style: TextStyle(color: Colors.white70, fontSize: 13)),
          const SizedBox(height: 4),
          Text('$_total ر.ي', style: const TextStyle(color: Colors.white, fontSize: 26, fontWeight: FontWeight.bold)),
          if (_byCat.isNotEmpty) ...[
            const SizedBox(height: 10),
            Wrap(spacing: 6, runSpacing: 6, children: _byCat.entries.map((e) => Container(
              padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
              decoration: BoxDecoration(color: Colors.white.withValues(alpha: 0.2), borderRadius: BorderRadius.circular(12)),
              child: Text('${_cats[e.key] ?? e.key}: ${e.value}', style: const TextStyle(color: Colors.white, fontSize: 11)),
            )).toList()),
          ],
        ]),
      );

  Widget _tile(Map<String, dynamic> e) => Container(
        margin: const EdgeInsets.only(bottom: 8),
        decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(12)),
        child: ListTile(
          leading: CircleAvatar(backgroundColor: AmialColors.red.withValues(alpha: 0.1),
              child: const Icon(Icons.receipt, color: AmialColors.red, size: 20)),
          title: Text('${e['title']}', style: const TextStyle(fontWeight: FontWeight.bold)),
          subtitle: Text('${_cats[e['category']] ?? e['category']} • ${e['spent_on']}', style: const TextStyle(fontSize: 11)),
          trailing: Row(mainAxisSize: MainAxisSize.min, children: [
            Text('${e['amount']} ر.ي', style: const TextStyle(fontWeight: FontWeight.bold, color: AmialColors.red)),
            IconButton(icon: const Icon(Icons.edit, size: 18, color: AmialColors.primary), onPressed: () => _edit(existing: e)),
            IconButton(icon: const Icon(Icons.delete_outline, size: 18, color: AmialColors.red), onPressed: () => _delete(e['id'] as int)),
          ]),
        ),
      );
}
