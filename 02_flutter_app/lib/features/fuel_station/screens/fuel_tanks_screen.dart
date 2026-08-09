import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:get/get.dart';
import 'package:amial_pay/theme/amial_colors.dart';
import 'package:amial_pay/features/fuel_station/controllers/fuel_vertical_controller.dart';
import 'package:amial_pay/features/fuel_station/widgets/fuel_state_view.dart';

/// AMIAL-FUEL-VERTICAL-001 · المرحلة ٨ — الخزّانات والقياسات.
///
/// **والقياسُ هو الحَكَم لا الدفتر.** ولذلك يُعرض الفرقُ بينهما في كلّ
/// بطاقة، ويُعاد فورَ التسجيل: من يقيس يريد أن يعرف الآن.
class FuelTanksScreen extends StatefulWidget {
  const FuelTanksScreen({super.key});

  @override
  State<FuelTanksScreen> createState() => _FuelTanksScreenState();
}

class _FuelTanksScreenState extends State<FuelTanksScreen> {
  late final FuelVerticalController c;

  @override
  void initState() {
    super.initState();
    c = Get.find<FuelVerticalController>();
    WidgetsBinding.instance.addPostFrameCallback((_) => c.loadTanks());
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(title: const Text('الخزانات')),
      body: RefreshIndicator(
        onRefresh: c.loadTanks,
        child: FuelStateView(
          c: c,
          isEmpty: c.tanks.isEmpty,
          emptyTitle: 'لا خزانات معرّفة',
          emptyHint: 'بلا خزّانات لا تُحسب مصالحة المخزون، ولا يُكتشف الفقد.',
          emptyIcon: Icons.propane_tank_outlined,
          onRetry: c.loadTanks,
          child: Obx(() => ListView(
                padding: const EdgeInsets.all(16),
                children: [
                  for (final t in c.tanks) _tankCard(t),
                  const SizedBox(height: 80),
                ],
              )),
        ),
      ),
      floatingActionButton: Obx(() => c.can('fuel.tank.manage')
          ? FloatingActionButton.extended(
              key: const Key('fuel-add-tank'),
              onPressed: _addTankDialog,
              icon: const Icon(Icons.add),
              label: const Text('خزان جديد'),
            )
          : const SizedBox.shrink()),
    );
  }

  Widget _tankCard(Map<String, dynamic> t) {
    final pct = ((t['fill_percent'] ?? 0) as num).toDouble();
    final low = t['is_low'] == true;
    final neverDipped = t['last_dip_at'] == null;

    return Card(
      key: Key('fuel-tank-card-${t['id']}'),
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(children: [
            Expanded(
              child: Text('${t['name'] ?? 'خزان ${t['tank_number']}'}',
                  style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16)),
            ),
            Chip(
              label: Text('${t['product']}', style: const TextStyle(fontSize: 12)),
              visualDensity: VisualDensity.compact,
            ),
          ]),
          const SizedBox(height: 10),

          ClipRRect(
            borderRadius: BorderRadius.circular(4),
            child: LinearProgressIndicator(
              value: (pct / 100).clamp(0.0, 1.0),
              minHeight: 10,
              backgroundColor: AmialColors.background,
              color: low ? Colors.orange : AmialColors.primary,
            ),
          ),
          const SizedBox(height: 8),

          Row(children: [
            Text('الدفتري ${t['book_liters']} لتر',
                style: const TextStyle(fontSize: 13)),
            const Spacer(),
            Text('السعة ${t['capacity_liters']}',
                style: TextStyle(fontSize: 12, color: AmialColors.textMuted)),
          ]),

          const Divider(height: 20),

          // **«لم يُقَس» يُقال ولا يُعرض صفراً.** (القاعدة السابعة)
          if (neverDipped)
            Row(children: [
              Icon(Icons.help_outline_rounded, size: 16, color: Colors.orange.shade800),
              const SizedBox(width: 6),
              Expanded(
                child: Text('لم يُقَس بعد — والمصالحة تحتاج قياساً افتتاحياً',
                    style: TextStyle(fontSize: 12, color: Colors.orange.shade800)),
              ),
            ])
          else
            Row(children: [
              Icon(Icons.straighten_rounded, size: 16, color: AmialColors.textMuted),
              const SizedBox(width: 6),
              Expanded(
                child: Text(
                  'آخر قياس ${t['last_dip_liters']} لتر · الفرق عن الدفتري '
                  '${_signed(t['dip_vs_book'])}',
                  style: TextStyle(fontSize: 12, color: _diffColor(t['dip_vs_book'])),
                ),
              ),
            ]),

          const SizedBox(height: 12),

          Row(children: [
            if (c.can('fuel.dip.record'))
              Expanded(
                child: OutlinedButton.icon(
                  key: Key('fuel-dip-${t['id']}'),
                  onPressed: () => _dipDialog(t),
                  icon: const Icon(Icons.straighten_rounded, size: 18),
                  label: const Text('تسجيل قياس'),
                ),
              ),
            if (c.can('fuel.dip.record') && c.can('fuel.recon.view'))
              const SizedBox(width: 8),
            if (c.can('fuel.recon.view'))
              Expanded(
                child: ElevatedButton.icon(
                  key: Key('fuel-recon-${t['id']}'),
                  onPressed: () => _reconcileDialog(t),
                  style: ElevatedButton.styleFrom(
                      backgroundColor: AmialColors.primary,
                      foregroundColor: Colors.white),
                  icon: const Icon(Icons.rule_rounded, size: 18),
                  label: const Text('مصالحة'),
                ),
              ),
          ]),
        ]),
      ),
    );
  }

  // ── تسجيل قياس ────────────────────────────────────────────────────

  Future<void> _dipDialog(Map<String, dynamic> t) async {
    final ctrl = TextEditingController();
    final noteCtrl = TextEditingController();
    String type = 'spot';

    final ok = await Get.dialog<bool>(
      StatefulBuilder(builder: (ctx, setLocal) {
        return AlertDialog(
          title: Text('قياس ${t['name']}'),
          content: Column(mainAxisSize: MainAxisSize.min, children: [
            TextField(
              key: const Key('fuel-dip-liters'),
              controller: ctrl,
              autofocus: true,
              keyboardType: const TextInputType.numberWithOptions(decimal: true),
              inputFormatters: [FilteringTextInputFormatter.allow(RegExp(r'[0-9.]'))],
              decoration: InputDecoration(
                labelText: 'الكمية المقيسة (لتر)',
                helperText: 'الدفتري الآن ${t['book_liters']}',
              ),
            ),
            const SizedBox(height: 12),
            DropdownButtonFormField<String>(
              key: const Key('fuel-dip-type'),
              value: type,
              decoration: const InputDecoration(labelText: 'نوع القياس'),
              items: const [
                DropdownMenuItem(value: 'opening', child: Text('افتتاحي (بداية المدة)')),
                DropdownMenuItem(value: 'closing', child: Text('ختامي (نهاية المدة)')),
                DropdownMenuItem(value: 'spot', child: Text('عابر')),
              ],
              onChanged: (v) => setLocal(() => type = v ?? 'spot'),
            ),
            const SizedBox(height: 12),
            TextField(
              controller: noteCtrl,
              decoration: const InputDecoration(labelText: 'ملاحظة (اختياري)'),
            ),
          ]),
          actions: [
            TextButton(onPressed: () => Get.back(result: false), child: const Text('إلغاء')),
            ElevatedButton(
              key: const Key('fuel-dip-save'),
              onPressed: () => Get.back(result: true),
              child: const Text('حفظ'),
            ),
          ],
        );
      }),
    );

    if (ok != true || ctrl.text.trim().isEmpty) return;

    final diff = await c.recordDip(t['id'] as int, {
      'dip_liters': ctrl.text.trim(),
      'dip_type': type,
      if (noteCtrl.text.trim().isNotEmpty) 'note': noteCtrl.text.trim(),
    });

    if (!mounted) return;

    if (diff == null) {
      _snack(c.lastError.value, error: true);
      return;
    }

    // **يُعرض الفرقُ فوراً** — لا «تمّ الحفظ» وحدَها.
    final d = double.tryParse(diff) ?? 0;
    _snack(
      d.abs() < 0.001
          ? 'سُجّل القياس — مطابقٌ للدفتري'
          : 'سُجّل القياس — الفرق ${_signed(diff)} لتر عن الدفتري',
      error: d < 0,
    );
  }

  // ── مصالحة ────────────────────────────────────────────────────────

  Future<void> _reconcileDialog(Map<String, dynamic> t) async {
    final actualCtrl = TextEditingController();

    final run = await Get.dialog<bool>(AlertDialog(
      title: Text('مصالحة ${t['name']}'),
      content: Column(mainAxisSize: MainAxisSize.min, children: [
        const Text(
          'المعادلة: الافتتاحي + المورَّد − المباع = المتوقَّع، '
          'ثم المتوقَّع − المقيس = الفرق.',
          style: TextStyle(fontSize: 12),
        ),
        const SizedBox(height: 12),
        TextField(
          key: const Key('fuel-recon-actual'),
          controller: actualCtrl,
          autofocus: true,
          keyboardType: const TextInputType.numberWithOptions(decimal: true),
          inputFormatters: [FilteringTextInputFormatter.allow(RegExp(r'[0-9.]'))],
          decoration: const InputDecoration(
            labelText: 'القياس الختامي الفعلي (لتر)',
            helperText: 'اتركه فارغاً ليُقرأ آخر قياس مسجّل',
          ),
        ),
      ]),
      actions: [
        TextButton(onPressed: () => Get.back(result: false), child: const Text('إلغاء')),
        ElevatedButton(
          key: const Key('fuel-recon-preview'),
          onPressed: () => Get.back(result: true),
          child: const Text('احسب'),
        ),
      ],
    ));

    if (run != true) return;

    final actual = actualCtrl.text.trim().isEmpty ? null : actualCtrl.text.trim();
    final r = await c.previewReconciliation(t['id'] as int, actual);

    if (!mounted) return;

    if (r == null) {
      _snack(c.lastError.value, error: true);
      return;
    }

    // **غيرُ قابلٍ للحساب يُقال بسببه** — لا رقمَ مخترَع.
    if (r['computable'] != true) {
      _snack('${r['reason']}', error: true);
      return;
    }

    final variance = '${r['variance_liters']}';
    final within = r['within_tolerance'] == true;

    final save = await Get.dialog<bool>(AlertDialog(
      title: Text(within ? 'ضمن الحد المقبول' : 'فرق يتجاوز الحد'),
      content: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment:
          CrossAxisAlignment.start, children: [
        _row('الافتتاحي', '${r['opening_liters']}'),
        _row('المورَّد', '${r['delivered_liters']}'),
        _row('المباع', '− ${r['sold_liters']}'),
        const Divider(),
        _row('المتوقَّع', '${r['expected_closing_liters']}'),
        _row('المقيس', '${r['actual_closing_liters']}'),
        const Divider(),
        _row('الفرق', '$variance لتر', bold: true,
            color: within ? Colors.green.shade700 : Colors.red.shade700),
        _row('الحد المسموح', '± ${r['tolerance_liters']}'),
        if (!within) ...[
          const SizedBox(height: 8),
          Text('الحفظ يفتح تحقيقاً — والنظام لا يتّهم أحداً، يفتح ملفّاً.',
              style: TextStyle(fontSize: 12, color: Colors.orange.shade900)),
        ],
      ]),
      actions: [
        TextButton(onPressed: () => Get.back(result: false), child: const Text('إغلاق')),
        ElevatedButton(
          key: const Key('fuel-recon-save'),
          onPressed: () => Get.back(result: true),
          child: const Text('حفظ المصالحة'),
        ),
      ],
    ));

    if (save != true) return;

    final done = await c.reconcile(t['id'] as int, {
      if (actual != null) 'actual_closing_liters': actual,
    });

    if (!mounted) return;
    _snack(done ? 'حُفظت المصالحة' : c.lastError.value, error: !done);
  }

  // ── خزان جديد ─────────────────────────────────────────────────────

  Future<void> _addTankDialog() async {
    final numberCtrl = TextEditingController();
    final nameCtrl = TextEditingController();
    final capCtrl = TextEditingController();
    final minCtrl = TextEditingController();
    final productCtrl = TextEditingController();

    final ok = await Get.dialog<bool>(AlertDialog(
      title: const Text('خزان جديد'),
      content: SingleChildScrollView(
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          TextField(
            key: const Key('fuel-tank-number'),
            controller: numberCtrl,
            keyboardType: TextInputType.number,
            decoration: const InputDecoration(labelText: 'رقم الخزان'),
          ),
          TextField(controller: nameCtrl,
              decoration: const InputDecoration(labelText: 'الاسم (اختياري)')),
          TextField(
            key: const Key('fuel-tank-product'),
            controller: productCtrl,
            keyboardType: TextInputType.number,
            decoration: const InputDecoration(
              labelText: 'معرّف نوع الوقود',
              helperText: 'من شاشة الأسعار',
            ),
          ),
          TextField(
            key: const Key('fuel-tank-capacity'),
            controller: capCtrl,
            keyboardType: const TextInputType.numberWithOptions(decimal: true),
            decoration: const InputDecoration(labelText: 'السعة (لتر)'),
          ),
          TextField(
            controller: minCtrl,
            keyboardType: const TextInputType.numberWithOptions(decimal: true),
            decoration: const InputDecoration(labelText: 'حد التنبيه (لتر)'),
          ),
        ]),
      ),
      actions: [
        TextButton(onPressed: () => Get.back(result: false), child: const Text('إلغاء')),
        ElevatedButton(
          key: const Key('fuel-tank-save'),
          onPressed: () => Get.back(result: true),
          child: const Text('حفظ'),
        ),
      ],
    ));

    if (ok != true) return;

    final done = await c.addTank({
      'tank_number': numberCtrl.text.trim(),
      'name': nameCtrl.text.trim(),
      'fuel_product_id': productCtrl.text.trim(),
      'capacity_liters': capCtrl.text.trim(),
      'min_alert_liters': minCtrl.text.trim().isEmpty ? '0' : minCtrl.text.trim(),
    });

    if (!mounted) return;
    _snack(done ? 'أُضيف الخزان' : c.lastError.value, error: !done);
  }

  // ── أدوات ─────────────────────────────────────────────────────────

  Widget _row(String k, String v, {bool bold = false, Color? color}) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 2),
        child: Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
          Text(k, style: TextStyle(fontSize: 13, color: AmialColors.textSecondary)),
          Text(v, style: TextStyle(
              fontSize: 13,
              fontWeight: bold ? FontWeight.bold : FontWeight.normal,
              color: color)),
        ]),
      );

  String _signed(dynamic v) {
    final s = '${v ?? '0'}';
    return s.startsWith('-') ? s : '+$s';
  }

  Color _diffColor(dynamic v) {
    final d = double.tryParse('${v ?? 0}') ?? 0;
    if (d.abs() < 0.001) return Colors.green.shade700;
    return d < 0 ? Colors.red.shade700 : Colors.blue.shade700;
  }

  void _snack(String msg, {bool error = false}) {
    if (msg.trim().isEmpty) return;
    Get.snackbar(error ? 'تنبيه' : 'تم', msg,
        backgroundColor: error ? Colors.red.shade50 : Colors.green.shade50,
        colorText: error ? Colors.red.shade900 : Colors.green.shade900,
        snackPosition: SnackPosition.BOTTOM,
        duration: const Duration(seconds: 4));
  }
}
