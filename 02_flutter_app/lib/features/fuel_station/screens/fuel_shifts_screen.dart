import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:get/get.dart';
import 'package:intl/intl.dart';
import 'package:amial_pay/theme/amial_colors.dart';
import 'package:amial_pay/helper/date_converter_helper.dart';
import 'package:amial_pay/features/fuel_station/controllers/fuel_station_controller.dart';
import 'package:amial_pay/features/fuel_station/screens/fuel_shift_cash_screen.dart';

/// AMIAL-FUEL-002 — إدارة النوبات (فتح/إغلاق) + العجز والفائض.
class FuelShiftsScreen extends StatefulWidget {
  const FuelShiftsScreen({super.key});

  @override
  State<FuelShiftsScreen> createState() => _FuelShiftsScreenState();
}

class _FuelShiftsScreenState extends State<FuelShiftsScreen> {
  late final FuelStationController c;

  @override
  void initState() {
    super.initState();
    c = Get.find<FuelStationController>();
    WidgetsBinding.instance.addPostFrameCallback((_) async {
      await Future.wait([c.loadCurrentShift(), c.loadShifts(), c.loadPumps()]);
    });
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(
        title: const Text('النوبات والعجز/الفائض'),
      ),
      body: Obx(() {
        if (c.isLoading.value && c.currentShift.value == null && c.shifts.isEmpty) {
          return const Center(child: CircularProgressIndicator());
        }
        return SingleChildScrollView(
          padding: const EdgeInsets.all(16),
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            // ====== النوبة الحالية ======
            _currentShiftCard(),
            const SizedBox(height: 20),

            // ====== سجل النوبات ======
            const Text('سجل النوبات', textAlign: TextAlign.right,
                style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold)),
            const SizedBox(height: 8),
            if (c.shifts.isEmpty)
              Center(child: Padding(
                padding: const EdgeInsets.all(24),
                child: Text('لا توجد نوبات سابقة', style: TextStyle(color: Colors.grey.shade600)),
              ))
            else
              ...c.shifts.map((s) => _shiftHistoryCard(s)),
          ]),
        );
      }),
    );
  }

  // ==================== النوبة الحالية ====================
  Widget _currentShiftCard() {
    final shift = c.currentShift.value;
    if (shift == null) {
      // لا توجد نوبة مفتوحة → عرض زر فتح
      return Container(
        padding: const EdgeInsets.all(20),
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(14),
          border: Border.all(color: Colors.grey.shade300),
        ),
        child: Column(children: [
          Icon(Icons.event_available, size: 40, color: AmialColors.primary),
          const SizedBox(height: 8),
          const Text('لا توجد نوبة مفتوحة',
              style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold)),
          const SizedBox(height: 4),
          Text('افتح نوبة جديدة لبدء يومك',
              style: TextStyle(color: Colors.grey.shade600, fontSize: 13)),
          const SizedBox(height: 12),
          SizedBox(width: double.infinity, child: FilledButton.icon(
            onPressed: _openShiftDialog,
            icon: const Icon(Icons.play_circle),
            label: const Text('فتح نوبة جديدة'),
            style: FilledButton.styleFrom(
              backgroundColor: Colors.green.shade700,
              minimumSize: const Size.fromHeight(48),
            ),
          )),
        ]),
      );
    }

    // توجد نوبة مفتوحة
    final openedAt = shift['opened_at'] != null
        ? DateConverterHelper.tryFromApi(shift['opened_at'].toString())
        : null;
    final duration = openedAt != null
        ? DateConverterHelper.nowInMecca().difference(openedAt) : null;

    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        gradient: const LinearGradient(
          colors: [Color(0xFF166534), Color(0xFF14532D)],
          begin: Alignment.topLeft, end: Alignment.bottomRight,
        ),
        borderRadius: BorderRadius.circular(14),
      ),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Row(children: [
          const Icon(Icons.circle, color: Colors.greenAccent, size: 12),
          const SizedBox(width: 6),
          const Text('نوبة مفتوحة',
              style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold)),
          const Spacer(),
          if (duration != null)
            Text('${duration.inHours}س ${duration.inMinutes.remainder(60)}د',
                style: const TextStyle(color: Colors.white70, fontSize: 12)),
        ]),
        const SizedBox(height: 12),
        if (openedAt != null)
          Text('فُتحت: ${DateFormat('yyyy-MM-dd HH:mm').format(openedAt)}',
              style: const TextStyle(color: Colors.white70, fontSize: 12)),
        const SizedBox(height: 4),
        Text('نقد افتتاحي: ${shift['opening_cash']} ر.ي',
            style: const TextStyle(color: Colors.white, fontWeight: FontWeight.bold)),
        if (shift['opening_notes'] != null)
          Padding(
            padding: const EdgeInsets.only(top: 6),
            child: Text('"${shift['opening_notes']}"',
                style: const TextStyle(color: Colors.white70, fontSize: 12, fontStyle: FontStyle.italic)),
          ),
        const SizedBox(height: 14),

        // **حركةُ النقد قبل الإغلاق لا بعده** — AMIAL-FUEL-VERTICAL-001 · ٧.
        //
        // وبلا هذا الزرّ كان كلُّ ريالٍ يخرج للمصروفات يظهر عجزاً في وجه
        // الكاشير: المتوقَّع يُحسب من الافتتاح والمبيعات وحدَها.
        OutlinedButton.icon(
          key: const Key('fuel-shift-cash-btn'),
          onPressed: () => Get.to(
              () => FuelShiftCashScreen(shiftId: (shift['id'] as num).toInt())),
          icon: const Icon(Icons.account_balance_wallet_outlined),
          label: const Text('مصروفات وحركة النقد'),
          style: OutlinedButton.styleFrom(
            foregroundColor: Colors.white,
            side: const BorderSide(color: Colors.white54),
            minimumSize: const Size.fromHeight(44),
          ),
        ),
        const SizedBox(height: 8),

        FilledButton.icon(
          onPressed: () => _closeShiftDialog(shift),
          icon: const Icon(Icons.stop_circle),
          label: const Text('إغلاق النوبة'),
          style: FilledButton.styleFrom(
            backgroundColor: AmialColors.yellow,
            foregroundColor: Colors.black87,
            minimumSize: const Size.fromHeight(48),
          ),
        ),
      ]),
    );
  }

  void _openShiftDialog() {
    final cashCtrl = TextEditingController(text: '0');
    final notesCtrl = TextEditingController();

    showDialog(context: context, builder: (ctx) => AlertDialog(
      title: const Text('فتح نوبة جديدة'),
      content: Column(mainAxisSize: MainAxisSize.min, children: [
        TextField(
          controller: cashCtrl,
          keyboardType: TextInputType.number,
          inputFormatters: [FilteringTextInputFormatter.digitsOnly],
          decoration: const InputDecoration(labelText: 'النقد الافتتاحي في الصندوق', suffixText: 'ر.ي'),
        ),
        const SizedBox(height: 8),
        TextField(controller: notesCtrl, maxLines: 2,
            decoration: const InputDecoration(labelText: 'ملاحظة (اختياري)')),
      ]),
      actions: [
        TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('إلغاء')),
        Obx(() => FilledButton(
          style: FilledButton.styleFrom(backgroundColor: Colors.green.shade700),
          onPressed: c.isSubmitting.value ? null : () async {
            final ok = await c.openShift(
              openingCash: cashCtrl.text.isEmpty ? '0' : cashCtrl.text,
              notes: notesCtrl.text,
            );
            if (!mounted) return;
            if (ok) {
              Navigator.pop(ctx);
            } else {
              ScaffoldMessenger.of(context).showSnackBar(
              SnackBar(content: Text(c.lastError.value), backgroundColor: AmialColors.red),
            );
            }
          },
          child: const Text('فتح'),
        )),
      ],
    ));
  }

  void _closeShiftDialog(Map<String, dynamic> shift) {
    final actualCashCtrl = TextEditingController();
    final reasonCtrl = TextEditingController();
    final notesCtrl = TextEditingController();
    final Map<int, TextEditingController> meterCtrls = {};

    final pumpSummaries = (shift['pump_summaries'] ?? []) as List;
    for (final s in pumpSummaries) {
      final pumpId = (s as Map)['pump_id'] as int;
      meterCtrls[pumpId] = TextEditingController(text: '${s['opening_meter']}');
    }

    showDialog(context: context, builder: (ctx) => Dialog(
      child: Container(
        constraints: BoxConstraints(maxHeight: MediaQuery.of(context).size.height * 0.85),
        padding: const EdgeInsets.all(16),
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          const Text('إغلاق النوبة', style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold)),
          const SizedBox(height: 4),
          Text('سيتم حساب العجز/الفائض تلقائياً',
              style: TextStyle(fontSize: 11, color: Colors.grey.shade600)),
          const Divider(),
          Expanded(child: SingleChildScrollView(child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              const SizedBox(height: 8),
              Container(
                padding: const EdgeInsets.all(10),
                decoration: BoxDecoration(
                  color: AmialColors.yellow.withValues(alpha: 0.15),
                  borderRadius: BorderRadius.circular(8),
                  border: Border.all(color: AmialColors.yellow),
                ),
                child: Text(
                  'النقد الافتتاحي: ${shift['opening_cash']} ر.ي\n'
                  'سيُحتسب: (افتتاحي + مبيعات نقد) — الفعلي = العجز/الفائض',
                  style: const TextStyle(fontSize: 12),
                  textAlign: TextAlign.right,
                ),
              ),
              const SizedBox(height: 12),

              // قراءات العدّاد
              if (pumpSummaries.isNotEmpty) ...[
                const Text('قراءات العدّاد النهائية', textAlign: TextAlign.right,
                    style: TextStyle(fontWeight: FontWeight.bold)),
                const SizedBox(height: 6),
                ...pumpSummaries.map((s) {
                  final ps = s as Map;
                  final pumpId = ps['pump_id'] as int;
                  final pump = c.pumps.firstWhereOrNull((p) => p['id'] == pumpId);
                  if (pump == null) return const SizedBox.shrink();
                  return Padding(
                    padding: const EdgeInsets.symmetric(vertical: 4),
                    child: TextField(
                      controller: meterCtrls[pumpId],
                      keyboardType: const TextInputType.numberWithOptions(decimal: true),
                      decoration: InputDecoration(
                        labelText: 'مضخّة ${pump['pump_number']}',
                        helperText: 'افتتاحية: ${ps['opening_meter']}',
                        isDense: true,
                      ),
                    ),
                  );
                }),
                const SizedBox(height: 12),
              ],

              // النقد الفعلي
              TextField(
                controller: actualCashCtrl,
                keyboardType: TextInputType.number,
                inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                decoration: const InputDecoration(
                  labelText: 'النقد الفعلي في الصندوق *',
                  suffixText: 'ر.ي',
                  helperText: 'ما تعدّه فعلاً الآن',
                ),
              ),
              const SizedBox(height: 8),
              TextField(controller: reasonCtrl,
                  decoration: const InputDecoration(labelText: 'سبب الفرق (إن وُجد)')),
              const SizedBox(height: 8),
              TextField(controller: notesCtrl,
                  decoration: const InputDecoration(labelText: 'ملاحظات الإغلاق')),
            ],
          ))),
          const Divider(),
          Row(children: [
            Expanded(child: TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('إلغاء'))),
            Expanded(child: Obx(() => FilledButton(
              style: FilledButton.styleFrom(backgroundColor: Colors.green.shade700),
              onPressed: c.isSubmitting.value ? null : () async {
                if (actualCashCtrl.text.isEmpty) return;
                final closings = <int, String>{};
                meterCtrls.forEach((k, v) {
                  if (v.text.isNotEmpty) closings[k] = v.text;
                });

                final ok = await c.closeShift(
                  shiftId: shift['id'],
                  actualCash: actualCashCtrl.text,
                  pumpClosings: closings,
                  varianceReason: reasonCtrl.text,
                  closingNotes: notesCtrl.text,
                );
                if (!mounted) return;
                if (ok) {
                  Navigator.pop(ctx);
                  _showVarianceResult();
                } else {
                  ScaffoldMessenger.of(context).showSnackBar(
                    SnackBar(content: Text(c.lastError.value), backgroundColor: AmialColors.red),
                  );
                }
              },
              child: const Text('إغلاق النوبة'),
            ))),
          ]),
        ]),
      ),
    ));
  }

  /// عرض ملخّص النتيجة (variance) بعد الإغلاق
  void _showVarianceResult() {
    if (c.shifts.isEmpty) return;
    final lastClosed = c.shifts.first;
    final variance = double.tryParse('${lastClosed['variance'] ?? 0}') ?? 0;

    Color bgColor;
    String title;
    IconData icon;

    if (variance == 0) {
      bgColor = Colors.green;
      title = '✓ مطابق تماماً';
      icon = Icons.check_circle;
    } else if (variance > 0) {
      bgColor = Colors.blue;
      title = '⬆️ فائض ${variance.toStringAsFixed(0)} ر.ي';
      icon = Icons.trending_up;
    } else {
      bgColor = AmialColors.red;
      title = '⬇️ عجز ${(-variance).toStringAsFixed(0)} ر.ي';
      icon = Icons.trending_down;
    }

    showDialog(context: context, builder: (_) => AlertDialog(
      backgroundColor: bgColor,
      title: Row(children: [
        Icon(icon, color: Colors.white),
        const SizedBox(width: 8),
        Expanded(child: Text(title, style: const TextStyle(color: Colors.white, fontSize: 16))),
      ]),
      content: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.end, children: [
        _resultRow('المتوقّع', '${lastClosed['expected_cash']} ر.ي'),
        _resultRow('الفعلي', '${lastClosed['actual_cash']} ر.ي'),
        const Divider(color: Colors.white54),
        _resultRow('مبيعات نقد', '${lastClosed['total_cash_sales']} ر.ي'),
        _resultRow('مبيعات أميال', '${lastClosed['total_amial_pay_sales']} ر.ي'),
        _resultRow('مبيعات شركات', '${lastClosed['total_company_sales']} ر.ي'),
        const Divider(color: Colors.white54),
        _resultRow('إجمالي اللترات', '${lastClosed['total_liters']}'),
        if (lastClosed['requires_admin_review'] == true) ...[
          const SizedBox(height: 8),
          const Text('⚠️ يتطلّب مراجعة إدارية', style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold)),
        ],
      ]),
      actions: [
        FilledButton(
          style: FilledButton.styleFrom(backgroundColor: Colors.white, foregroundColor: bgColor),
          onPressed: () => Navigator.pop(context),
          child: const Text('حسناً'),
        ),
      ],
    ));
  }

  Widget _resultRow(String label, String value) => Padding(
    padding: const EdgeInsets.symmetric(vertical: 2),
    child: Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
      Text(value, style: const TextStyle(color: Colors.white, fontWeight: FontWeight.bold)),
      Text(label, style: const TextStyle(color: Colors.white70, fontSize: 12)),
    ]),
  );

  // ==================== سجل النوبات ====================
  Widget _shiftHistoryCard(Map<String, dynamic> s) {
    final variance = double.tryParse('${s['variance'] ?? 0}') ?? 0;
    final openedAt = s['opened_at'] != null
        ? DateConverterHelper.tryFromApi(s['opened_at'].toString()) : null;
    final closedAt = s['closed_at'] != null
        ? DateConverterHelper.tryFromApi(s['closed_at'].toString()) : null;
    final status = s['status']?.toString() ?? '';

    Color varColor;
    String varText;
    if (status == 'open') {
      varColor = Colors.green;
      varText = '🟢 مفتوحة';
    } else if (variance == 0) {
      varColor = Colors.green.shade700;
      varText = '✓ مطابق';
    } else if (variance > 0) {
      varColor = Colors.blue;
      varText = '+${variance.toStringAsFixed(0)} فائض';
    } else {
      varColor = AmialColors.red;
      varText = '${variance.toStringAsFixed(0)} عجز';
    }

    return Container(
      margin: const EdgeInsets.only(bottom: 8),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(10)),
      child: Row(children: [
        Container(
          width: 8, height: 50,
          color: varColor,
        ),
        const SizedBox(width: 10),
        Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(varText, style: TextStyle(color: varColor, fontWeight: FontWeight.bold)),
          const SizedBox(height: 2),
          if (openedAt != null)
            Text('فتح: ${DateFormat('MM-dd HH:mm').format(openedAt)}${closedAt != null ? ' → ${DateFormat('MM-dd HH:mm').format(closedAt)}' : ''}',
                style: TextStyle(fontSize: 11, color: Colors.grey.shade700)),
          if (status != 'open')
            Text('مبيعات: ${s['total_sales_count']} • لترات: ${s['total_liters']}',
                style: TextStyle(fontSize: 11, color: Colors.grey.shade600)),
        ])),
        if (s['requires_admin_review'] == true)
          const Padding(
            padding: EdgeInsets.only(left: 4),
            child: Icon(Icons.warning_amber, color: Colors.orange, size: 20),
          ),
      ]),
    );
  }
}
