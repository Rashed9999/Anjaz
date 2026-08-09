import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:get/get.dart';
import 'package:amial_pay/theme/amial_colors.dart';
import 'package:amial_pay/features/fuel_station/controllers/fuel_vertical_controller.dart';
import 'package:amial_pay/features/fuel_station/widgets/fuel_state_view.dart';

/// AMIAL-FUEL-VERTICAL-001 · المرحلة ٨ — التوريدات.
///
/// **ثلاثةُ أحوالٍ ظاهرةٌ في الشاشة كما هي في المحرّك:**
/// مستلم ← مُتحقَّق ← مُرحَّل. **والمخزونُ لا يرتفع إلّا بالثالث.**
///
/// وزرُّ كلّ حالٍ يظهر لمن يملك صلاحيّتَه وحدَه: من يستلم ليس بالضرورة
/// من يرحّل، وهذا فصلُ الأيدي الذي يمنع رفعَ مخزونٍ بورقةٍ خاطئة.
class FuelDeliveriesScreen extends StatefulWidget {
  const FuelDeliveriesScreen({super.key});

  @override
  State<FuelDeliveriesScreen> createState() => _FuelDeliveriesScreenState();
}

class _FuelDeliveriesScreenState extends State<FuelDeliveriesScreen> {
  late final FuelVerticalController c;
  String _filter = 'all';

  @override
  void initState() {
    super.initState();
    c = Get.find<FuelVerticalController>();
    WidgetsBinding.instance.addPostFrameCallback((_) async {
      await c.loadDeliveries();
      await c.loadTanks();
    });
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(
        title: const Text('التوريدات'),
        bottom: PreferredSize(
          preferredSize: const Size.fromHeight(48),
          child: Obx(() {
            final counts = <String, int>{
              'all': c.deliveries.length,
              'received': c.deliveries.where((d) => d['status'] == 'received').length,
              'verified': c.deliveries.where((d) => d['status'] == 'verified').length,
              'posted': c.deliveries.where((d) => d['status'] == 'posted').length,
            };

            return SingleChildScrollView(
              scrollDirection: Axis.horizontal,
              padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
              child: Row(children: [
                _chip('الكل', 'all', counts['all']!),
                _chip('مستلم', 'received', counts['received']!),
                _chip('مُتحقَّق', 'verified', counts['verified']!),
                _chip('مُرحَّل', 'posted', counts['posted']!),
              ]),
            );
          }),
        ),
      ),
      body: RefreshIndicator(
        onRefresh: c.loadDeliveries,
        child: Obx(() {
          final rows = _filter == 'all'
              ? c.deliveries.toList()
              : c.deliveries.where((d) => d['status'] == _filter).toList();

          return FuelStateView(
            c: c,
            isEmpty: rows.isEmpty,
            emptyTitle: _filter == 'all' ? 'لا توريدات' : 'لا توريدات بهذه الحالة',
            emptyHint: 'المخزون لا يدخل من العدم — سجّل التوريد بمستنده.',
            emptyIcon: Icons.local_shipping_outlined,
            onRetry: c.loadDeliveries,
            child: ListView(
              padding: const EdgeInsets.all(16),
              children: [
                for (final d in rows) _card(d),
                const SizedBox(height: 80),
              ],
            ),
          );
        }),
      ),
      floatingActionButton: Obx(() => c.can('fuel.delivery.receive')
          ? FloatingActionButton.extended(
              key: const Key('fuel-add-delivery'),
              onPressed: _receiveDialog,
              icon: const Icon(Icons.add),
              label: const Text('توريد جديد'),
            )
          : const SizedBox.shrink()),
    );
  }

  Widget _chip(String label, String value, int count) => Padding(
        padding: const EdgeInsets.only(left: 8),
        child: FilterChip(
          key: Key('fuel-del-filter-$value'),
          selected: _filter == value,
          label: Text('$label ($count)'),
          onSelected: (_) => setState(() => _filter = value),
        ),
      );

  Widget _card(Map<String, dynamic> d) {
    final status = '${d['status']}';
    final gap = d['measured_variance'];

    return Card(
      key: Key('fuel-delivery-${d['id']}'),
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(children: [
            _statusBadge(status, '${d['status_ar']}'),
            const Spacer(),
            Text('${d['quantity_liters']} لتر',
                style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16)),
          ]),
          const SizedBox(height: 8),

          Text('${d['tank']} · المورد: ${d['supplier']}',
              style: TextStyle(fontSize: 13, color: AmialColors.textSecondary)),

          if (gap != null) ...[
            const SizedBox(height: 6),
            Text('فرق القياس عن الفاتورة: $gap لتر',
                style: TextStyle(
                    fontSize: 12,
                    color: (double.tryParse('$gap') ?? 0).abs() < 0.001
                        ? Colors.green.shade700
                        : Colors.orange.shade800)),
          ],

          const SizedBox(height: 12),

          // ── الأزرارُ بحسب الحال والصلاحيّة ──
          if (status == 'received' && c.can('fuel.delivery.verify'))
            SizedBox(
              width: double.infinity,
              child: OutlinedButton.icon(
                key: Key('fuel-verify-${d['id']}'),
                onPressed: () => _verifyDialog(d),
                icon: const Icon(Icons.fact_check_outlined, size: 18),
                label: const Text('تحقّق بقياس الخزان'),
              ),
            )
          else if (status == 'verified' && c.can('fuel.delivery.post'))
            SizedBox(
              width: double.infinity,
              child: ElevatedButton.icon(
                key: Key('fuel-post-${d['id']}'),
                onPressed: () => _confirmPost(d),
                style: ElevatedButton.styleFrom(
                    backgroundColor: Colors.green.shade700,
                    foregroundColor: Colors.white),
                icon: const Icon(Icons.upload_rounded, size: 18),
                label: const Text('ترحيل — يرفع المخزون'),
              ),
            )
          else if (status == 'posted')
            Text('رُحِّل ${_short(d['posted_at'])} — المخزون ارتفع',
                style: TextStyle(fontSize: 12, color: Colors.green.shade700))
          else if (status == 'received')
            // **الزرُّ غائبٌ ويُقال لماذا** — لا زرَّ معطَّلٌ بلا سبب.
            Text('بانتظار من يملك صلاحية التحقق',
                style: TextStyle(fontSize: 12, color: AmialColors.textMuted))
          else if (status == 'verified')
            Text('بانتظار من يملك صلاحية الترحيل',
                style: TextStyle(fontSize: 12, color: AmialColors.textMuted)),
        ]),
      ),
    );
  }

  Widget _statusBadge(String s, String label) {
    final color = switch (s) {
      'posted' => Colors.green,
      'verified' => Colors.blue,
      'rejected' => Colors.red,
      _ => Colors.orange,
    };

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 3),
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.12),
        borderRadius: BorderRadius.circular(12),
      ),
      child: Text(label,
          style: TextStyle(fontSize: 12, color: color.shade800, fontWeight: FontWeight.bold)),
    );
  }

  // ── استلام ────────────────────────────────────────────────────────

  Future<void> _receiveDialog() async {
    if (c.tanks.isEmpty) {
      _snack('عرّف خزّاناً أولاً — التوريد يدخل خزّاناً بعينه', error: true);
      return;
    }

    int? tankId = c.tanks.first['id'] as int?;
    final qtyCtrl = TextEditingController();
    final dipCtrl = TextEditingController();
    final invoiceCtrl = TextEditingController();
    final costCtrl = TextEditingController();

    final ok = await Get.dialog<bool>(
      StatefulBuilder(builder: (ctx, setLocal) => AlertDialog(
        title: const Text('توريد جديد'),
        content: SingleChildScrollView(
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            DropdownButtonFormField<int>(
              key: const Key('fuel-del-tank'),
              value: tankId,
              decoration: const InputDecoration(labelText: 'الخزان'),
              items: [
                for (final t in c.tanks)
                  DropdownMenuItem(
                    value: t['id'] as int,
                    child: Text('${t['name']} · ${t['product']}'),
                  ),
              ],
              onChanged: (v) => setLocal(() => tankId = v),
            ),
            TextField(
              key: const Key('fuel-del-qty'),
              controller: qtyCtrl,
              keyboardType: const TextInputType.numberWithOptions(decimal: true),
              inputFormatters: [FilteringTextInputFormatter.allow(RegExp(r'[0-9.]'))],
              decoration: const InputDecoration(labelText: 'الكمية حسب الفاتورة (لتر)'),
            ),
            TextField(
              key: const Key('fuel-del-dip-before'),
              controller: dipCtrl,
              keyboardType: const TextInputType.numberWithOptions(decimal: true),
              inputFormatters: [FilteringTextInputFormatter.allow(RegExp(r'[0-9.]'))],
              decoration: const InputDecoration(
                labelText: 'قياس الخزان قبل التفريغ (لتر)',
                helperText: 'يُقارن بقياس ما بعد التفريغ عند التحقق',
              ),
            ),
            TextField(controller: invoiceCtrl,
                decoration: const InputDecoration(labelText: 'رقم الفاتورة')),
            TextField(
              controller: costCtrl,
              keyboardType: const TextInputType.numberWithOptions(decimal: true),
              decoration: const InputDecoration(labelText: 'سعر اللتر (اختياري)'),
            ),
          ]),
        ),
        actions: [
          TextButton(onPressed: () => Get.back(result: false), child: const Text('إلغاء')),
          ElevatedButton(
            key: const Key('fuel-del-save'),
            onPressed: () => Get.back(result: true),
            child: const Text('تسجيل'),
          ),
        ],
      )),
    );

    if (ok != true || tankId == null) return;

    final done = await c.receiveDelivery({
      'tank_id': tankId,
      'quantity_liters': qtyCtrl.text.trim(),
      if (dipCtrl.text.trim().isNotEmpty) 'dip_before_liters': dipCtrl.text.trim(),
      if (invoiceCtrl.text.trim().isNotEmpty) 'invoice_number': invoiceCtrl.text.trim(),
      if (costCtrl.text.trim().isNotEmpty) 'unit_cost': costCtrl.text.trim(),
    });

    if (!mounted) return;
    _snack(done
        ? 'سُجّل التوريد — المخزون لن يرتفع قبل التحقق والترحيل'
        : c.lastError.value, error: !done);
  }

  // ── تحقّق ─────────────────────────────────────────────────────────

  Future<void> _verifyDialog(Map<String, dynamic> d) async {
    final beforeCtrl = TextEditingController();
    final afterCtrl = TextEditingController();

    final ok = await Get.dialog<bool>(AlertDialog(
      title: const Text('تحقق من التوريد'),
      content: Column(mainAxisSize: MainAxisSize.min, children: [
        Text('الفاتورة تقول ${d['quantity_liters']} لتر. '
            'الفرق بين القياسين يجب أن يطابقها.',
            style: const TextStyle(fontSize: 12)),
        const SizedBox(height: 12),
        TextField(
          key: const Key('fuel-verify-before'),
          controller: beforeCtrl,
          keyboardType: const TextInputType.numberWithOptions(decimal: true),
          decoration: const InputDecoration(labelText: 'القياس قبل التفريغ'),
        ),
        TextField(
          key: const Key('fuel-verify-after'),
          controller: afterCtrl,
          keyboardType: const TextInputType.numberWithOptions(decimal: true),
          decoration: const InputDecoration(labelText: 'القياس بعد التفريغ'),
        ),
      ]),
      actions: [
        TextButton(onPressed: () => Get.back(result: false), child: const Text('إلغاء')),
        ElevatedButton(
          key: const Key('fuel-verify-save'),
          onPressed: () => Get.back(result: true),
          child: const Text('تحقّق'),
        ),
      ],
    ));

    if (ok != true) return;

    final done = await c.verifyDelivery(
        d['id'] as int, beforeCtrl.text.trim(), afterCtrl.text.trim());

    if (!mounted) return;
    _snack(done ? 'طابق القياس الفاتورة — جاهز للترحيل' : c.lastError.value,
        error: !done);
  }

  // ── ترحيل ─────────────────────────────────────────────────────────

  Future<void> _confirmPost(Map<String, dynamic> d) async {
    // **فعلٌ يغيّر المخزون يُؤكَّد.**
    final ok = await Get.dialog<bool>(AlertDialog(
      title: const Text('تأكيد الترحيل'),
      content: Text('سيرتفع مخزون ${d['tank']} بمقدار ${d['quantity_liters']} لتر. '
          'والمرحَّل لا يُرفض بعدها — يُصحَّح بتوريد عكسيّ موثّق.'),
      actions: [
        TextButton(onPressed: () => Get.back(result: false), child: const Text('تراجع')),
        ElevatedButton(
          key: const Key('fuel-post-confirm'),
          onPressed: () => Get.back(result: true),
          style: ElevatedButton.styleFrom(
              backgroundColor: Colors.green.shade700, foregroundColor: Colors.white),
          child: const Text('رحّل'),
        ),
      ],
    ));

    if (ok != true) return;

    final done = await c.postDelivery(d['id'] as int);

    if (!mounted) return;
    _snack(done ? 'رُحّل التوريد وارتفع المخزون' : c.lastError.value, error: !done);
  }

  String _short(dynamic iso) {
    final t = DateTime.tryParse('${iso ?? ''}');
    return t == null ? '' : '${t.year}/${t.month}/${t.day}';
  }

  void _snack(String msg, {bool error = false}) {
    if (msg.trim().isEmpty) return;
    Get.snackbar(error ? 'تنبيه' : 'تم', msg,
        backgroundColor: error ? Colors.red.shade50 : Colors.green.shade50,
        colorText: error ? Colors.red.shade900 : Colors.green.shade900,
        snackPosition: SnackPosition.BOTTOM,
        duration: const Duration(seconds: 5));
  }
}
