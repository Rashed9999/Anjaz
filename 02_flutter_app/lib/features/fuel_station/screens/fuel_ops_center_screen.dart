import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amial_pay/theme/amial_colors.dart';
import 'package:amial_pay/features/fuel_station/controllers/fuel_vertical_controller.dart';
import 'package:amial_pay/features/fuel_station/widgets/fuel_state_view.dart';

/// AMIAL-FUEL-VERTICAL-001 · المرحلة ٨ — **مركزُ العمليّات**.
///
/// الحالةُ الآن في شاشةٍ واحدة: المضخّاتُ ومسدساتُها، والخزّاناتُ
/// وامتلاؤها، والوردية. **ومسدسٌ بلا خزّانٍ يُصرَّح به بالأحمر** — لتراتُه
/// خارج المصالحة، وسكوتُنا عنه يُنتج فائضاً يُقرأ ربحاً.
class FuelOpsCenterScreen extends StatefulWidget {
  const FuelOpsCenterScreen({super.key});

  @override
  State<FuelOpsCenterScreen> createState() => _FuelOpsCenterScreenState();
}

class _FuelOpsCenterScreenState extends State<FuelOpsCenterScreen> {
  late final FuelVerticalController c;

  @override
  void initState() {
    super.initState();
    c = Get.find<FuelVerticalController>();
    WidgetsBinding.instance.addPostFrameCallback((_) => c.loadOps());
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(title: const Text('مركز العمليات')),
      body: RefreshIndicator(
        onRefresh: c.loadOps,
        child: FuelStateView(
          c: c,
          isEmpty: c.ops.value == null,
          emptyTitle: 'لا بيانات تشغيل',
          emptyHint: 'أضف مضخّة وخزّاناً من الإعدادات لتظهر الحالة هنا.',
          emptyIcon: Icons.dashboard_outlined,
          onRetry: c.loadOps,
          child: Obx(() {
            final o = c.ops.value ?? {};
            final pumps = List<Map<String, dynamic>>.from(
                (o['pumps'] ?? const []).map((e) => Map<String, dynamic>.from(e)));
            final tanks = List<Map<String, dynamic>>.from(
                (o['tanks'] ?? const []).map((e) => Map<String, dynamic>.from(e)));

            return ListView(
              padding: const EdgeInsets.all(16),
              children: [
                _header('المضخات والمسدسات', Icons.local_gas_station_rounded),
                if (pumps.isEmpty)
                  _empty('لا مضخّات معرّفة')
                else
                  ...pumps.map(_pumpCard),

                const SizedBox(height: 20),
                _header('الخزانات', Icons.propane_tank_rounded),
                if (tanks.isEmpty)
                  _empty('لا خزّانات معرّفة — وبلا خزّان لا مصالحةَ مخزون')
                else
                  ...tanks.map(_tankCard),

                const SizedBox(height: 24),
              ],
            );
          }),
        ),
      ),
    );
  }

  Widget _header(String t, IconData i) => Padding(
        padding: const EdgeInsets.only(bottom: 8, top: 4),
        child: Row(children: [
          Icon(i, size: 18, color: AmialColors.textSecondary),
          const SizedBox(width: 8),
          Text(t, style: TextStyle(
              fontWeight: FontWeight.bold, color: AmialColors.textSecondary)),
        ]),
      );

  Widget _empty(String t) => Card(
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Text(t, style: TextStyle(color: AmialColors.textMuted)),
        ),
      );

  Widget _pumpCard(Map<String, dynamic> p) {
    final nozzles = List<Map<String, dynamic>>.from(
        (p['nozzles'] ?? const []).map((e) => Map<String, dynamic>.from(e)));
    final active = p['is_active'] == true;

    return Card(
      key: Key('fuel-pump-${p['id']}'),
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(children: [
            Icon(Icons.circle, size: 12,
                color: active ? Colors.green : AmialColors.textMuted),
            const SizedBox(width: 8),
            Text('مضخة ${p['pump_number']}',
                style: const TextStyle(fontWeight: FontWeight.bold)),
            const Spacer(),
            Text(active ? 'تعمل' : 'متوقفة',
                style: TextStyle(
                    fontSize: 12,
                    color: active ? Colors.green.shade700 : AmialColors.textMuted)),
          ]),
          const Divider(height: 16),

          if (nozzles.isEmpty)
            Text('لا مسدسات — لا يمكن البيع من هذه المضخّة',
                style: TextStyle(color: Colors.orange.shade800, fontSize: 13))
          else
            ...nozzles.map((n) {
              final unlinked = n['unlinked'] == true;

              return Padding(
                padding: const EdgeInsets.symmetric(vertical: 4),
                child: Row(children: [
                  Icon(unlinked ? Icons.link_off_rounded : Icons.link_rounded,
                      size: 16,
                      color: unlinked ? Colors.red : Colors.green.shade700),
                  const SizedBox(width: 8),
                  Text('مسدس ${n['nozzle_number']}'),
                  const Spacer(),
                  Text('عدّاد ${n['meter']}',
                      style: TextStyle(fontSize: 12, color: AmialColors.textMuted)),
                ]),
              );
            }),

          // **التحذيرُ في موضعه لا في شاشةٍ أخرى.**
          if (nozzles.any((n) => n['unlinked'] == true))
            Padding(
              padding: const EdgeInsets.only(top: 8),
              child: Text(
                  'مسدس بلا خزان: لتراته لا تُخصم من أي خزّان وتبقى خارج المصالحة',
                  style: TextStyle(fontSize: 12, color: Colors.red.shade700)),
            ),
        ]),
      ),
    );
  }

  Widget _tankCard(Map<String, dynamic> t) {
    final pct = ((t['fill_percent'] ?? 0) as num).toDouble();
    final low = t['is_low'] == true;
    final dipDiff = t['dip_vs_book'];

    return Card(
      key: Key('fuel-tank-${t['id']}'),
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(children: [
            Text('${t['name'] ?? 'خزان ${t['tank_number']}'} · ${t['product']}',
                style: const TextStyle(fontWeight: FontWeight.bold)),
            const Spacer(),
            if (low)
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
                decoration: BoxDecoration(
                    color: Colors.orange.shade100,
                    borderRadius: BorderRadius.circular(10)),
                child: Text('منخفض',
                    style: TextStyle(fontSize: 11, color: Colors.orange.shade900)),
              ),
          ]),
          const SizedBox(height: 8),

          ClipRRect(
            borderRadius: BorderRadius.circular(4),
            child: LinearProgressIndicator(
              value: (pct / 100).clamp(0.0, 1.0),
              minHeight: 8,
              backgroundColor: AmialColors.background,
              color: low ? Colors.orange : AmialColors.primary,
            ),
          ),
          const SizedBox(height: 6),

          Text('${t['book_liters']} / ${t['capacity_liters']} لتر  ·  ${pct.toStringAsFixed(1)}%',
              style: TextStyle(fontSize: 12, color: AmialColors.textMuted)),

          // **«لم يُقَس» ليس صفراً** — يُقال صراحةً.
          const SizedBox(height: 4),
          if (t['last_dip_at'] == null)
            Text('لم يُقَس بعد — بلا قياس لا مصالحة',
                style: TextStyle(fontSize: 12, color: Colors.orange.shade800))
          else
            Text(
              'آخر قياس ${t['last_dip_liters']} لتر '
              '(${_signed(dipDiff)} عن الدفتري)',
              style: TextStyle(
                fontSize: 12,
                color: _dipColor(dipDiff),
              ),
            ),
        ]),
      ),
    );
  }

  String _signed(dynamic v) {
    final s = '${v ?? '0'}';
    return s.startsWith('-') ? s : '+$s';
  }

  Color _dipColor(dynamic v) {
    final d = double.tryParse('${v ?? 0}') ?? 0;
    if (d.abs() < 0.001) return Colors.green.shade700;
    return d < 0 ? Colors.red.shade700 : Colors.blue.shade700;
  }
}
