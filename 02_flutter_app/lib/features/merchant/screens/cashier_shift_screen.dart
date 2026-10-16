import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amial_pay/data/api/api_client.dart';
import 'package:amial_pay/helper/date_converter_helper.dart';
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
  final List<Map<String, dynamic>> _movements = [];

  /// AMIAL-SHIFT-GATE-001 — ساعاتُ العمل: اليومَ وهذا الشهر.
  /// `null` تعني «لم تُقرأ» لا «صفرُ ساعات» — والفرقُ يُعرَض.
  List<Map<String, dynamic>>? _people;

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
        await _loadWorkTime();
      } else {
        _error = _messageOf(r) ?? 'تعذّر تحميل حالة الوردية';
      }
    } catch (_) { _error = 'خطأ في الشبكة'; }
    finally { if (mounted) setState(() => _loading = false); }
  }

  Future<void> _loadX() async {
    final r = await _api.getData('/api/v1/amial/cashier/shift/x');
    if (r.statusCode == 200 && r.body is Map) {
      final meta = (r.body['meta'] ?? {}) as Map;
      final report = meta['report'];
      _x = report is Map ? Map<String, dynamic>.from(report) : null;
      _movements
        ..clear()
        ..addAll(((meta['movements'] ?? []) as List)
            .whereType<Map>()
            .map((e) => Map<String, dynamic>.from(e)));
    }
  }

  /// AMIAL-SHIFT-GATE-001 — **ساعاتُ العمل للمالك وحدَه** (٤٠٣ للموظّف).
  /// ولا تُعرَض رسالةُ عطلٍ له: ليست عطلاً، هي ليست شغلَه.
  Future<void> _loadWorkTime() async {
    try {
      final r = await _api.getData('/api/v1/amial/cashier/shift/work-time');
      if (r.statusCode == 200 && r.body is Map) {
        _people = (((r.body['meta'] ?? {})['people'] ?? []) as List)
            .map((e) => Map<String, dynamic>.from(e as Map))
            .toList();
      }
    } catch (_) {/* الشبكة — والشاشةُ تعمل بما تعرفه */}
  }

  /// **الاسمُ `r` كبقيّة الملفّ** — وهو ما يقرؤه الحارسُ أيضاً. واختلافُ
  /// التسمية في دالّةٍ واحدةٍ جعل فحصاً سليماً يبدو غائباً.
  String? _messageOf(dynamic r) {
    final body = r.body;
    if (r.statusCode == 401) return 'انتهت الجلسة — سجّل الدخول من جديد';
    if (r.statusCode == 403) {
      return body is Map && body['message'] != null
          ? body['message'].toString()
          : 'لا تملك الصلاحية اللازمة للورديات';
    }
    return body is Map ? body['message']?.toString() : null;
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
    else { _snack(_messageOf(r) ?? 'تعذّر بدء الوردية. أعد المحاولة أو تواصل مع الدعم.'); }
  }

  /// AMIAL-CASH-DROP-001 — يسجّل خروج النقد من الدرج، لا من محفظة أميال.
  Future<void> _cashDrop() async {
    final amount = TextEditingController();
    final reference = TextEditingController();
    final note = TextEditingController();

    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('تسليم نقد للخزنة'),
        content: SingleChildScrollView(
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            if (_x != null)
              Text(
                'المتوقّع في الدرج الآن: ${_x!['expected_cash']} ر.ي',
                style: const TextStyle(fontWeight: FontWeight.bold),
              ),
            const SizedBox(height: 8),
            const Text(
              'يسجّل هذا أن النقد خرج فعلياً من درج نقطة البيع. لا يغيّر رصيد محفظة التاجر الإلكترونية.',
              style: TextStyle(fontSize: 12, color: AmialColors.textSecondary, height: 1.4),
            ),
            const SizedBox(height: 12),
            TextField(
              controller: amount,
              keyboardType: const TextInputType.numberWithOptions(decimal: true),
              autofocus: true,
              decoration: const InputDecoration(
                labelText: 'المبلغ المسلّم',
                suffixText: 'ر.ي',
                border: OutlineInputBorder(),
              ),
            ),
            const SizedBox(height: 10),
            TextField(
              controller: reference,
              decoration: const InputDecoration(
                labelText: 'مرجع التسليم (اختياري)',
                hintText: 'رقم سند / اسم مستلم مختصر',
                border: OutlineInputBorder(),
              ),
            ),
            const SizedBox(height: 10),
            TextField(
              controller: note,
              decoration: const InputDecoration(
                labelText: 'ملاحظة (اختياري)',
                border: OutlineInputBorder(),
              ),
            ),
          ]),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('إلغاء')),
          FilledButton(
            onPressed: () => Navigator.pop(ctx, true),
            child: const Text('تسجيل التسليم'),
          ),
        ],
      ),
    );

    if (ok != true) return;
    final value = double.tryParse(amount.text.trim());
    if (value == null || value <= 0) {
      _snack('أدخل مبلغ تسليم صحيحاً أكبر من صفر');
      return;
    }

    final r = await _api.postData('/api/v1/amial/cashier/shift/cash-drop', {
      'amount': amount.text.trim(),
      if (reference.text.trim().isNotEmpty) 'reference': reference.text.trim(),
      if (note.text.trim().isNotEmpty) 'note': note.text.trim(),
    });

    if (r.statusCode == 200 && r.body is Map) {
      await _loadX();
      if (mounted) setState(() {});
      _snack('سُجّل تسليم النقد وخصم من المتوقّع في الدرج', ok: true);
    } else {
      _snack(_messageOf(r) ?? 'تعذّر تسجيل تسليم النقد');
    }
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
      _snack(_messageOf(r) ?? 'تعذّر إقفال الوردية. أعد المحاولة أو تواصل مع الدعم.');
    }
  }

  void _showResult(Map<String, dynamic> s) {
    final variance = double.tryParse('${s['variance']}') ?? 0;
    // **والاسمُ من الخادم أوّلاً** — مصدرٌ واحدٌ يسمّي الفرق، فلا تقول
    // الشاشةُ «زيادة» ويقول التقريرُ غيرَها.
    final kind = '${s['variance_kind'] ?? ''}';
    final color = variance == 0 ? AmialColors.success
        : (kind == 'surplus' || variance > 0 ? AmialColors.yellowDark : AmialColors.red);
    final label = switch (kind) {
      'balanced' => 'مطابق تماماً',
      'surplus' => 'فائض في الدرج',
      'shortage' => 'عجز في الدرج',
      _ => variance == 0 ? 'مطابق تماماً' : (variance > 0 ? 'فائض' : 'عجز'),
    };
    showDialog(context: context, builder: (ctx) => AlertDialog(
      title: Row(children: [Icon(variance == 0 ? Icons.check_circle : Icons.warning, color: color), const SizedBox(width: 8), const Text('تقرير Z')]),
      content: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        _row('الرصيد الافتتاحي', '${s['opening_float']} ر.ي'),
        _row('مبيعات نقدية', '${s['cash_sales']} ر.ي'),
        if (_x != null)
          _row('تحصيلات نقدية', '${_x!['cash_collections'] ?? '0'} ر.ي'),
        if (_x != null)
          _row('حركة نقد داخلة', '${_x!['cash_movements_in'] ?? '0'} ر.ي'),
        if (_x != null)
          _row('حركة نقد خارجة / تسليمات', '${_x!['cash_movements_out'] ?? '0'} ر.ي'),
        _row('المتوقّع', '${s['expected_cash']} ر.ي'),
        _row('المجرود', '${s['counted_cash']} ر.ي'),
        const Divider(),
        if ('${s['opened_by_name'] ?? ''}'.isNotEmpty)
          _row('فتحها', '${s['opened_by_name']}'),
        if ('${s['closed_by_name'] ?? ''}'.isNotEmpty)
          _row('أقفلها', '${s['closed_by_name']}'),
        _row('الفرق ($label)', '${s['variance']} ر.ي', color: color, bold: true),
      ]),
      actions: [FilledButton(onPressed: () => Navigator.pop(ctx), child: const Text('تم'))],
    ));
  }

  String _movementTime(dynamic iso) {
    final d = DateConverterHelper.tryFromApi(iso?.toString());
    if (d == null) return '';
    return '${d.hour.toString().padLeft(2, '0')}:${d.minute.toString().padLeft(2, '0')}';
  }

  List<Widget> _cashMovementTrail() {
    if (_movements.isEmpty) return const [];

    final recent = _movements.reversed.take(8);
    return [
      const SizedBox(height: 14),
      const Row(children: [
        Icon(Icons.receipt_long_outlined, size: 18, color: AmialColors.primary),
        SizedBox(width: 7),
        Text('سجل حركة عهدة الدرج',
            style: TextStyle(fontSize: 14, fontWeight: FontWeight.bold)),
      ]),
      const SizedBox(height: 6),
      ...recent.map((m) {
        final outgoing = m['direction'] == 'out';
        final ref = (m['reference'] ?? '').toString().trim();
        final note = (m['note'] ?? '').toString().trim();
        final actor = (m['actor'] ?? '').toString().trim();
        final time = _movementTime(m['created_at']);

        final subtitle = <String>[
          if (ref.isNotEmpty) 'المرجع: $ref',
          if (actor.isNotEmpty) 'بواسطة: $actor',
          if (note.isNotEmpty) note,
          if (time.isNotEmpty) time,
        ].join(' · ');

        return Card(
          color: AmialColors.cardSurface,
          child: ListTile(
            dense: true,
            leading: Icon(
              outgoing ? Icons.north_east_rounded : Icons.south_west_rounded,
              color: outgoing ? AmialColors.red : AmialColors.success,
            ),
            title: Text(
              '${m['reason_ar'] ?? m['reason'] ?? 'حركة نقد'} — ${m['amount'] ?? '0'} ر.ي',
              style: const TextStyle(fontWeight: FontWeight.w600),
            ),
            subtitle: subtitle.isEmpty ? null : Text(subtitle),
          ),
        );
      }),
    ];
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
                    const SizedBox(height: 16),
                    OutlinedButton.icon(onPressed: _load, icon: const Icon(Icons.refresh), label: const Text('إعادة المحاولة')),
                  ])))
              : RefreshIndicator(onRefresh: _load, child: ListView(padding: const EdgeInsets.all(16), children: [
                  if (_shift == null) _noShift() else _openShift(),
                  ..._workTime(),
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
            // AMIAL-SHIFT-GATE-001 — **باسم من ستُطبَع الفاتورة.**
            if ('${_shift!['opened_by_name'] ?? ''}'.isNotEmpty)
              _row('فتح الوردية', '${_shift!['opened_by_name']}'),
            _row('الرصيد الافتتاحي', '${_shift!['opening_float']} ر.ي'),
            _row('مبيعات نقدية (${_x?['sales_count'] ?? 0})', '${_x?['cash_sales'] ?? '0'} ر.ي'),
            _row('تحصيلات نقدية', '${_x?['cash_collections'] ?? '0'} ر.ي'),
            _row('حركة نقد داخلة', '${_x?['cash_movements_in'] ?? '0'} ر.ي'),
            _row('حركة نقد خارجة / تسليمات', '${_x?['cash_movements_out'] ?? '0'} ر.ي'),
            const SizedBox(height: 6),
            Container(
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(color: AmialColors.primary.withValues(alpha: 0.08), borderRadius: BorderRadius.circular(10)),
              child: _row('المتوقّع في الدرج الآن', '${_x?['expected_cash'] ?? '0'} ر.ي', bold: true, color: AmialColors.primary),
            ),
          ]),
        ),
        ..._cashMovementTrail(),
        const SizedBox(height: 16),
        OutlinedButton.icon(
          onPressed: _cashDrop,
          icon: const Icon(Icons.account_balance_outlined),
          label: const Text('تسليم نقد للخزنة'),
          style: OutlinedButton.styleFrom(minimumSize: const Size.fromHeight(50)),
        ),
        const SizedBox(height: 6),
        const Text(
          'إن خرج نقد من الدرج قبل الإقفال فسجّله هنا أولاً؛ عندها يحسب تقرير Z المتبقي الفعلي ولا يسجّل التسليم كعجز.',
          textAlign: TextAlign.center,
          style: TextStyle(fontSize: 11, color: AmialColors.textMuted, height: 1.4),
        ),
        const SizedBox(height: 12),
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

  // ══════════════════════════════════════════════════════════════════
  //  AMIAL-SHIFT-GATE-001 — ساعاتُ العمل: اليومَ وهذا الشهر
  // ══════════════════════════════════════════════════════════════════

  /// **بنصّ الطلب:** «وردية تعمل اسمه ووقت عمله اليومي والشهري».
  ///
  /// **ولا تُعرَض لموظّف** — الخادمُ يردّ ٤٠٣ فتبقى `_people` فارغةً،
  /// والقسمُ لا يُرسَم. وذلك مقصود: ساعاتُ الزملاء وفروقُ درجهم ليست شغلَ
  /// كاشيرٍ على الشبّاك.
  ///
  /// **والورديّةُ الجاريةُ تُوسَم ولا تُحسَب صفراً** (القاعدة السابعة) —
  /// صفرٌ هناك يقتطع من أجرِ من هو واقفٌ الآن.
  List<Widget> _workTime() {
    final people = _people;
    if (people == null || people.isEmpty) return const [];

    return [
      const SizedBox(height: 20),
      const Row(children: [
        Icon(Icons.schedule, color: AmialColors.primary, size: 18),
        SizedBox(width: 8),
        Text('ساعات العمل هذا الشهر',
            style: TextStyle(fontWeight: FontWeight.bold, fontSize: 15)),
      ]),
      const SizedBox(height: 4),
      const Text('تُحسب من الورديات نفسها — لا من عمود مخزَّن.',
          style: TextStyle(fontSize: 11, color: AmialColors.textMuted)),
      const SizedBox(height: 10),
      ...people.map(_personCard),
    ];
  }

  Widget _personCard(Map<String, dynamic> p) {
    final running = p['is_running'] == true;

    return Container(
      margin: const EdgeInsets.only(bottom: 10),
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(
            color: running
                ? AmialColors.success.withValues(alpha: 0.45)
                : AmialColors.border),
      ),
      child: Column(children: [
        Row(children: [
          Expanded(
            child: Text('${p['name'] ?? 'غير معروف'}',
                style: const TextStyle(fontWeight: FontWeight.w900, fontSize: 14)),
          ),
          if (running)
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
              decoration: BoxDecoration(
                  color: AmialColors.successSurface,
                  borderRadius: BorderRadius.circular(20)),
              child: const Text('وردية جارية',
                  style: TextStyle(
                      fontSize: 10,
                      fontWeight: FontWeight.w800,
                      color: AmialColors.success)),
            )
          else
            Text(p['role'] == 'owner' ? 'صاحب المتجر' : 'موظف نقطة بيع',
                style: const TextStyle(
                    fontSize: 11, color: AmialColors.textSecondary)),
        ]),
        const Divider(height: 20),
        _row('اليوم', '${p['hours_today'] ?? 0} ساعة · ${p['shifts_today'] ?? 0} وردية'),
        _row('هذا الشهر', '${p['hours_month'] ?? 0} ساعة · ${p['shifts_month'] ?? 0} وردية'),
        _row('مبيعات نقدية للشهر', '${p['cash_sales_month'] ?? '0'} ر.ي'),
        _row('مجموع الفروق', '${p['variance_month'] ?? '0'} ر.ي',
            color: (double.tryParse('${p['variance_month'] ?? 0}') ?? 0) == 0
                ? null
                : AmialColors.yellowDark),
      ]),
    );
  }
}
