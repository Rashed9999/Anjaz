import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/data/api/api_client.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';

/// AMIAL-RESTAURANT-001 — محرّر طلب المطعم.
///
/// يفتح طلباً جديداً على طاولة (أو سفري) أو يعرض طلباً قائماً: إضافة أصناف،
/// إرساله للمطبخ، تعليمه جاهزاً/مُقدَّماً، ثم إغلاقه (يُسجَّل بيعاً ويحرّر الطاولة).
class RestaurantOrderScreen extends StatefulWidget {
  const RestaurantOrderScreen({super.key, this.tableId, this.tableLabel, this.existingOrder});

  final int? tableId;
  final String? tableLabel;
  final Map<String, dynamic>? existingOrder;

  @override
  State<RestaurantOrderScreen> createState() => _RestaurantOrderScreenState();
}

class _RestaurantOrderScreenState extends State<RestaurantOrderScreen> {
  final _api = Get.find<ApiClient>();
  List<Map<String, dynamic>> _items = [];
  int? _orderId;
  String _status = 'open';
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    final o = widget.existingOrder;
    if (o != null) {
      _orderId = o['id'] as int?;
      _status = '${o['status'] ?? 'open'}';
      _items = ((o['items'] ?? []) as List).map((e) => Map<String, dynamic>.from(e as Map)).toList();
    }
  }

  double get _total => _items.fold(0.0, (s, it) =>
      s + ((double.tryParse('${it['qty'] ?? it['quantity']}') ?? 0) * (double.tryParse('${it['price']}') ?? 0)));

  void _snack(String m, {bool ok = false}) => ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(content: Text(m), backgroundColor: ok ? const Color(0xFF2E7D32) : AmyalColors.red));

  Future<void> _addItem() async {
    final name = TextEditingController();
    final qty = TextEditingController(text: '1');
    final price = TextEditingController();
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('إضافة صنف'),
        content: Column(mainAxisSize: MainAxisSize.min, children: [
          TextField(controller: name, decoration: const InputDecoration(labelText: 'الصنف *', border: OutlineInputBorder())),
          const SizedBox(height: 10),
          Row(children: [
            Expanded(child: TextField(controller: qty, keyboardType: TextInputType.number,
                decoration: const InputDecoration(labelText: 'الكمية', border: OutlineInputBorder()))),
            const SizedBox(width: 8),
            Expanded(child: TextField(controller: price, keyboardType: TextInputType.number,
                decoration: const InputDecoration(labelText: 'السعر', border: OutlineInputBorder()))),
          ]),
        ]),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('إلغاء')),
          FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('إضافة')),
        ],
      ),
    );
    if (ok != true) return;
    final n = name.text.trim();
    final q = double.tryParse(qty.text.trim()) ?? 0;
    final p = double.tryParse(price.text.trim()) ?? 0;
    if (n.isEmpty || q <= 0) { _snack('أكمل الصنف والكمية'); return; }
    setState(() => _items.add({'name': n, 'qty': q, 'price': p}));
    if (_orderId != null) _persist();
  }

  List<Map<String, dynamic>> get _payload =>
      _items.map((it) => {'name': it['name'], 'qty': it['qty'] ?? it['quantity'] ?? 1, 'price': it['price'] ?? 0}).toList();

  /// يحفظ الطلب (فتح جديد أو تعديل القائم).
  Future<void> _persist() async {
    if (_items.isEmpty) { _snack('أضِف صنفاً أولاً'); return; }
    setState(() => _busy = true);
    try {
      final r = _orderId == null
          ? await _api.postData('/api/v1/amial/restaurant/orders',
              {'table_id': widget.tableId, 'items': _payload})
          : await _api.postData('/api/v1/amial/restaurant/orders/$_orderId', {'items': _payload});
      if ((r.statusCode == 200 || r.statusCode == 201) && r.body is Map) {
        final o = (r.body['meta'] ?? {})['order'] ?? {};
        setState(() { _orderId = o['id'] as int? ?? _orderId; _status = '${o['status'] ?? _status}'; });
        _snack('حُفظ الطلب', ok: true);
      } else {
        _snack((r.body is Map ? r.body['message']?.toString() : null) ?? 'تعذّر الحفظ');
      }
    } catch (_) { _snack('خطأ في الشبكة'); }
    finally { if (mounted) setState(() => _busy = false); }
  }

  Future<void> _setStatus(String status) async {
    if (_orderId == null) { await _persist(); if (_orderId == null) return; }
    final r = await _api.postData('/api/v1/amial/restaurant/orders/$_orderId/status', {'status': status});
    if (r.statusCode == 200) { setState(() => _status = status); _snack('الحالة: ${_statusLabel(status)}', ok: true); }
    else { _snack('تعذّر تحديث الحالة'); }
  }

  Future<void> _close() async {
    if (_orderId == null) { await _persist(); if (_orderId == null) return; }
    String method = 'cash';
    final go = await showDialog<bool>(
      context: context,
      builder: (ctx) => StatefulBuilder(builder: (ctx, setLocal) => AlertDialog(
        title: const Text('إغلاق الطلب والدفع'),
        content: Column(mainAxisSize: MainAxisSize.min, children: [
          Text('الإجمالي: ${_total.toStringAsFixed(0)} ر.ي', style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16)),
          const SizedBox(height: 12),
          Wrap(spacing: 8, children: [
            for (final m in [('cash', 'نقد'), ('amial_pay', 'أميال باي'), ('credit', 'آجل')])
              ChoiceChip(label: Text(m.$2), selected: method == m.$1, onSelected: (_) => setLocal(() => method = m.$1)),
          ]),
        ]),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('إلغاء')),
          FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('تأكيد')),
        ],
      )),
    );
    if (go != true) return;
    setState(() => _busy = true);
    final r = await _api.postData('/api/v1/amial/restaurant/orders/$_orderId/close', {'payment_method': method});
    if (!mounted) return;
    setState(() => _busy = false);
    if (r.statusCode == 200) { _snack('أُغلق الطلب وسُجّلت الفاتورة', ok: true); Get.back(result: true); }
    else { _snack((r.body is Map ? r.body['message']?.toString() : null) ?? 'تعذّر الإغلاق'); }
  }

  String _statusLabel(String s) => {
        'open': 'مفتوح', 'preparing': 'قيد التحضير', 'ready': 'جاهز', 'served': 'مُقدَّم', 'closed': 'مُغلق',
      }[s] ?? s;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmyalColors.background,
      appBar: AppBar(
        title: Text(widget.tableLabel != null ? 'طلب — ${widget.tableLabel}' : 'طلب سفري'),
        backgroundColor: AmyalColors.primary, foregroundColor: Colors.white,
      ),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: _busy ? null : _addItem,
        backgroundColor: AmyalColors.primary, icon: const Icon(Icons.add), label: const Text('صنف'),
      ),
      body: Column(children: [
        Container(
          width: double.infinity,
          color: Colors.white,
          padding: const EdgeInsets.all(12),
          child: Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
              decoration: BoxDecoration(color: AmyalColors.primary.withValues(alpha: 0.1), borderRadius: BorderRadius.circular(20)),
              child: Text(_statusLabel(_status), style: const TextStyle(color: AmyalColors.primary, fontWeight: FontWeight.bold)),
            ),
            Text('${_total.toStringAsFixed(0)} ر.ي', style: const TextStyle(fontSize: 20, fontWeight: FontWeight.bold, color: AmyalColors.primary)),
          ]),
        ),
        Expanded(
          child: _items.isEmpty
              ? const Center(child: Text('أضِف أصناف الطلب', style: TextStyle(color: AmyalColors.textSecondary)))
              : ListView.separated(
                  padding: const EdgeInsets.all(12),
                  itemCount: _items.length,
                  separatorBuilder: (_, _) => const SizedBox(height: 6),
                  itemBuilder: (_, i) {
                    final it = _items[i];
                    final q = double.tryParse('${it['qty'] ?? it['quantity']}') ?? 0;
                    final p = double.tryParse('${it['price']}') ?? 0;
                    return Container(
                      decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(10)),
                      child: ListTile(
                        title: Text('${it['name']}', style: const TextStyle(fontWeight: FontWeight.bold)),
                        subtitle: Text('${q.toStringAsFixed(0)} × ${p.toStringAsFixed(0)} ر.ي'),
                        trailing: Row(mainAxisSize: MainAxisSize.min, children: [
                          Text('${(q * p).toStringAsFixed(0)} ر.ي',
                              style: const TextStyle(fontWeight: FontWeight.bold, color: AmyalColors.primary)),
                          IconButton(icon: const Icon(Icons.close, size: 18, color: AmyalColors.red),
                              onPressed: _status == 'closed' ? null : () { setState(() => _items.removeAt(i)); if (_orderId != null) _persist(); }),
                        ]),
                      ),
                    );
                  },
                ),
        ),
        if (_status != 'closed')
          SafeArea(
            child: Padding(
              padding: const EdgeInsets.all(12),
              child: Row(children: [
                Expanded(child: OutlinedButton.icon(
                  onPressed: _busy ? null : () => _setStatus(_status == 'open' ? 'preparing' : 'ready'),
                  icon: const Icon(Icons.soup_kitchen),
                  label: Text(_status == 'open' ? 'إرسال للمطبخ' : 'تعليم جاهز'),
                )),
                const SizedBox(width: 8),
                Expanded(child: FilledButton.icon(
                  onPressed: _busy ? null : _close,
                  icon: const Icon(Icons.point_of_sale),
                  label: const Text('إغلاق ودفع'),
                  style: FilledButton.styleFrom(backgroundColor: const Color(0xFF2E7D32)),
                )),
              ]),
            ),
          ),
      ]),
    );
  }
}
