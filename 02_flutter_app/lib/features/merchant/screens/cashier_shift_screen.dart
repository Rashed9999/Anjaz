import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amial_pay/data/api/api_client.dart';
import 'package:amial_pay/theme/amial_colors.dart';

/// AMIAL-SHIFT-CLOSE-001 — «إقفال الوردية» (باقة الأعمال فأعلى).
///
/// يبدأ الكاشير وردية برصيد افتتاحي، يرى تقرير X اللحظي (المتوقّع في الدرج)،
/// ثم يقفلها بجرد النقد (تقرير Z) فيُحسب الفرق.
class CashierShiftScreen extends StatefulWidget {
  const CashierShiftScreen({super.key});

  @override
  State<CashierShiftScreen> createState() => _CashierShiftScreenState();
}

class _CashierShiftScreenState extends State<CashierShiftScreen> {
  final _api = Get.find<ApiClient>();
  bool _loading = true;
  String? _error;
  Map<String, dynamic>? _shift;
  Map<String, dynamic>? _x;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() { _loading = true; _error = null; });
    try {
      final r = await _api.getData('/api/v1/amial/cashier/shift');
      if (r.statusCode == 402) {
        setState(() { _error = 'إقفال الوردية متاح في باقة الأعمال فأعلى'; _loading = false; });
        return;
      }
      if (r.statusCode == 200 && r.body is Map) {
        _shift = ((r.body['meta'] ?? {})['shift']) as Map<String, dynamic>?;
        if (_shift != null) await _loadX();
      }
    } catch (_) { _error = 'خطأ في الشبكة'; }
    finally { if (mounted) setState(() => _loading = false); }
  }

  Future<void> _loadX() async {
    final r = await _api.getData('/api/v1/amial/cashier/shift/x');
    if (r.statusCode == 200 && r.body is Map) {
      _x = ((r.body['meta'] ?? {})['report']) as Map<String, dynamic>?;
    }
  }

  void _snack(String m, {bool ok = false}) => ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(content: Text(m), backgroundColor: ok ? AmialColors.success : AmialColors.red));

  Future<void> _open() async {
    final floatCtrl = TextEditingController(text: '0');
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('بدء وردية'),
        content: TextField(controller: floatCtrl, keyboardType: TextInputType.number, autofocus: true,
            decoration: const InputDecoration(labelText: 'النقد الافتتاحي في الدرج', suffixText: 'ر.ي', border: OutlineInputBorder())),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('إلغاء')),
          FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('بدء')),
        ],
      ),
    );
    if (ok != true) return;
    final r = await _api.postData('/api/v1/amial/cashier/shift/open', {'opening_float': floatCtrl.text.trim()});
    if (r.statusCode == 201) { _snack('بدأت الوردية', ok: true); _load(); }
    else { _snack((r.body is Map ? r.body['message']?.toString() : null) ?? 'تعذّر'); }
  }

  Future<void> _close() async {
    final countCtrl = TextEditingController();
    final notes = TextEditingController();
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('إقفال الوردية (جرد الدرج)'),
        content: Column(mainAxisSize: MainAxisSize.min, children: [
          if (_x != null) Text('المتوقّع في الدرج: ${_x!['expected_cash']} ر.ي',
              style: const TextStyle(fontWeight: FontWeight.bold)),
          const SizedBox(height: 12),
          TextField(controller: countCtrl, keyboardType: TextInputType.number, autofocus: true,
              decoration: const InputDecoration(labelText: 'النقد المجرود فعلاً', suffixText: 'ر.ي', border: OutlineInputBorder())),
          const SizedBox(height: 10),
          TextField(controller: notes, decoration: const InputDecoration(labelText: 'ملاحظات (اختياري)', border: OutlineInputBorder())),
        ]),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('إلغاء')),
          FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('إقفال')),
        ],
      ),
    );
    if (ok != true) return;
    if (countCtrl.text.trim().isEmpty) { _snack('أدخل المبلغ المجرود'); return; }
    final r = await _api.postData('/api/v1/amial/cashier/shift/close',
        {'counted_cash': countCtrl.text.trim(), if (notes.text.trim().isNotEmpty) 'notes': notes.text.trim()});
    if (r.statusCode == 200 && r.body is Map) {
      final s = (r.body['meta'] ?? {})['shift'] ?? {};
      _showResult(Map<String, dynamic>.from(s as Map));
      _load();
    } else {
      _snack((r.body is Map ? r.body['message']?.toString() : null) ?? 'تعذّر الإقفال');
    }
  }

  void _showResult(Map<String, dynamic> s) {
    final variance = double.tryParse('${s['variance']}') ?? 0;
    final color = variance == 0 ? AmialColors.success : AmialColors.red;
    final label = variance == 0 ? 'مطابق تماماً' : variance > 0 ? 'زيادة' : 'عجز';
    showDialog(context: context, builder: (ctx) => AlertDialog(
      title: Row(children: [Icon(variance == 0 ? Icons.check_circle : Icons.warning, color: color), const SizedBox(width: 8), const Text('تقرير Z')]),
      content: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        _row('الرصيد الافتتاحي', '${s['opening_float']} ر.ي'),
        _row('مبيعات نقدية', '${s['cash_sales']} ر.ي'),
        _row('المتوقّع', '${s['expected_cash']} ر.ي'),
        _row('المجرود', '${s['counted_cash']} ر.ي'),
        const Divider(),
        _row('الفرق ($label)', '${s['variance']} ر.ي', color: color, bold: true),
      ]),
      actions: [FilledButton(onPressed: () => Navigator.pop(ctx), child: const Text('تم'))],
    ));
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(title: const Text('إقفال الوردية'), backgroundColor: AmialColors.primary, foregroundColor: Colors.white),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : _error != null
              ? Center(child: Padding(padding: const EdgeInsets.all(24),
                  child: Column(mainAxisSize: MainAxisSize.min, children: [
                    const Icon(Icons.workspace_premium, size: 56, color: AmialColors.yellowDark),
                    const SizedBox(height: 12),
                    Text(_error!, textAlign: TextAlign.center, style: const TextStyle(fontSize: 15, fontWeight: FontWeight.w600)),
                  ])))
              : RefreshIndicator(onRefresh: _load, child: ListView(padding: const EdgeInsets.all(16), children: [
                  if (_shift == null) _noShift() else _openShift(),
                ])),
    );
  }

  Widget _noShift() => Column(children: [
        const SizedBox(height: 40),
        const Icon(Icons.point_of_sale, size: 64, color: AmialColors.textSecondary),
        const SizedBox(height: 12),
        const Text('لا توجد وردية مفتوحة', style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold)),
        const SizedBox(height: 6),
        const Text('ابدأ وردية بتحديد النقد الافتتاحي في الدرج',
            textAlign: TextAlign.center, style: TextStyle(color: AmialColors.textSecondary)),
        const SizedBox(height: 20),
        FilledButton.icon(onPressed: _open, icon: const Icon(Icons.play_arrow),
            label: const Text('بدء وردية'),
            style: FilledButton.styleFrom(backgroundColor: AmialColors.primary, minimumSize: const Size(220, 52))),
      ]);

  Widget _openShift() => Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Container(
          padding: const EdgeInsets.all(18),
          decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(16)),
          child: Column(children: [
            const Row(children: [Icon(Icons.receipt_long, color: AmialColors.primary), SizedBox(width: 8),
              Text('تقرير X — لحظي', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 15))]),
            const Divider(height: 24),
            _row('الرصيد الافتتاحي', '${_shift!['opening_float']} ر.ي'),
            _row('مبيعات نقدية (${_x?['sales_count'] ?? 0})', '${_x?['cash_sales'] ?? '0'} ر.ي'),
            const SizedBox(height: 6),
            Container(
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(color: AmialColors.primary.withValues(alpha: 0.08), borderRadius: BorderRadius.circular(10)),
              child: _row('المتوقّع في الدرج الآن', '${_x?['expected_cash'] ?? '0'} ر.ي', bold: true, color: AmialColors.primary),
            ),
          ]),
        ),
        const SizedBox(height: 16),
        FilledButton.icon(onPressed: _close, icon: const Icon(Icons.lock),
            label: const Text('إقفال الوردية (Z)'),
            style: FilledButton.styleFrom(backgroundColor: AmialColors.success, minimumSize: const Size.fromHeight(52))),
      ]);

  Widget _row(String k, String v, {bool bold = false, Color? color}) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 5),
        child: Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
          Text(v, style: TextStyle(fontWeight: bold ? FontWeight.bold : FontWeight.normal, color: color, fontSize: bold ? 16 : 14)),
          Text(k, style: const TextStyle(fontSize: 13, color: AmialColors.textSecondary)),
        ]),
      );
}
