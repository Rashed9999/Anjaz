import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amial_pay/theme/amial_colors.dart';
import 'package:amial_pay/features/fuel_station/controllers/fuel_vertical_controller.dart';
import 'package:amial_pay/features/fuel_station/widgets/fuel_state_view.dart';

/// AMIAL-FUEL-VERTICAL-001 · المرحلة ٨ — فروقاتُ المخزون.
///
/// **والنظامُ يفتح ملفّاً ولا يتّهم أحداً.** فقدُ تسعين لتراً قد يكون قراءةً
/// خاطئة، أو توريداً لم يُرحَّل، أو عدّاداً معطوباً، أو مسدساً غيرَ مربوطٍ
/// بخزّانه — ولذلك تُعرض الاحتمالاتُ مع كلّ تحقيق.
class FuelVariancesScreen extends StatefulWidget {
  const FuelVariancesScreen({super.key});

  @override
  State<FuelVariancesScreen> createState() => _FuelVariancesScreenState();
}

class _FuelVariancesScreenState extends State<FuelVariancesScreen> {
  late final FuelVerticalController c;

  @override
  void initState() {
    super.initState();
    c = Get.find<FuelVerticalController>();
    WidgetsBinding.instance.addPostFrameCallback((_) => c.loadVariances());
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(title: const Text('فروقات المخزون')),
      body: RefreshIndicator(
        onRefresh: c.loadVariances,
        child: FuelStateView(
          c: c,
          isEmpty: c.variances.isEmpty &&
              (double.tryParse(c.unattributedLiters.value) ?? 0) == 0,
          // **«لا فروقات» تُقال بمعناها**: فُحص فلم يوجد، لا لم يُفحص.
          emptyTitle: 'لا فروقات مسجّلة',
          emptyHint: 'سجّل مصالحة من شاشة الخزانات ليظهر الفرق هنا.',
          emptyIcon: Icons.rule_rounded,
          onRetry: c.loadVariances,
          child: Obx(() => ListView(
                padding: const EdgeInsets.all(16),
                children: [
                  _unattributedBanner(),
                  for (final v in c.variances) _card(v),
                  const SizedBox(height: 24),
                ],
              )),
        ),
      ),
    );
  }

  /// **لتراتٌ خارج المعادلة كلِّها** — أخطرُ من فرقٍ معلوم.
  Widget _unattributedBanner() {
    final u = double.tryParse(c.unattributedLiters.value) ?? 0;
    if (u <= 0) return const SizedBox.shrink();

    return Card(
      key: const Key('fuel-unattributed-banner'),
      color: AmialColors.warningSurface,
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Row(children: [
          Icon(Icons.link_off_rounded, color: Colors.orange.shade800),
          const SizedBox(width: 12),
          Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text('${c.unattributedLiters.value} لتر خارج المصالحة',
                  style: TextStyle(
                      fontWeight: FontWeight.bold, color: Colors.orange.shade900)),
              const SizedBox(height: 4),
              Text(
                'بيعت من مسدسات غير مربوطة بخزانات، فلا تُخصم من أي خزّان — '
                'ويظهر فائضٌ يُقرأ ربحاً. اربط المسدسات من «مركز العمليات».',
                style: TextStyle(fontSize: 12, color: Colors.orange.shade900),
              ),
            ]),
          ),
        ]),
      ),
    );
  }

  Widget _card(Map<String, dynamic> v) {
    final isLoss = v['is_loss'] == true;
    final status = '${v['status']}';
    final open = status == 'investigating';

    return Card(
      key: Key('fuel-variance-${v['id']}'),
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(children: [
            Icon(isLoss ? Icons.trending_down_rounded : Icons.trending_up_rounded,
                color: isLoss ? Colors.red : Colors.blue),
            const SizedBox(width: 8),
            Text('${v['tank']}', style: const TextStyle(fontWeight: FontWeight.bold)),
            const Spacer(),
            Text('${v['variance_liters']} لتر',
                style: TextStyle(
                    fontWeight: FontWeight.bold,
                    fontSize: 16,
                    color: isLoss ? Colors.red.shade700 : Colors.blue.shade700)),
          ]),
          const SizedBox(height: 8),

          Text('المتوقَّع ${v['expected']} · المقيس ${v['actual']} · '
              '${v['variance_percent']}%',
              style: TextStyle(fontSize: 12, color: AmialColors.textSecondary)),

          const SizedBox(height: 4),
          Text(_statusLabel(status),
              style: TextStyle(
                  fontSize: 12,
                  color: open ? Colors.orange.shade800 : Colors.green.shade700)),

          if (open) ...[
            const Divider(height: 20),
            Text('احتمالات تُفحص قبل الحكم:',
                style: TextStyle(fontSize: 12, fontWeight: FontWeight.bold,
                    color: AmialColors.textSecondary)),
            const SizedBox(height: 4),
            ...const [
              'قراءة قياس خاطئة',
              'توريد وصل ولم يُرحَّل',
              'مسدس غير مربوط بخزانه',
              'عدّاد معطوب',
              'تسرّب في الخزان أو الأنابيب',
            ].map((s) => Padding(
                  padding: const EdgeInsets.only(top: 2),
                  child: Text('• $s', style: const TextStyle(fontSize: 12)),
                )),

            if (c.can('fuel.recon.resolve')) ...[
              const SizedBox(height: 12),
              SizedBox(
                width: double.infinity,
                child: OutlinedButton.icon(
                  key: Key('fuel-resolve-${v['id']}'),
                  onPressed: () => _resolveDialog(v),
                  icon: const Icon(Icons.task_alt_rounded, size: 18),
                  label: const Text('إغلاق التحقيق'),
                ),
              ),
            ] else
              Padding(
                padding: const EdgeInsets.only(top: 8),
                child: Text('إغلاق التحقيق يحتاج صلاحية لا تملكها',
                    style: TextStyle(fontSize: 12, color: AmialColors.textMuted)),
              ),
          ],
        ]),
      ),
    );
  }

  String _statusLabel(String s) => switch (s) {
        'within_tolerance' => 'ضمن الحد المقبول',
        'investigating' => 'تحقيق مفتوح',
        'resolved' => 'أُغلق بتفسير',
        'written_off' => 'شُطب',
        _ => s,
      };

  Future<void> _resolveDialog(Map<String, dynamic> v) async {
    final noteCtrl = TextEditingController();
    String status = 'resolved';

    final ok = await Get.dialog<bool>(
      StatefulBuilder(builder: (ctx, setLocal) => AlertDialog(
        title: const Text('إغلاق التحقيق'),
        content: Column(mainAxisSize: MainAxisSize.min, children: [
          const Text(
            'اكتب ما وُجد. تحقيقٌ يُغلق بلا سبب يعود بعد شهر ولا أحد يعرف '
            'ماذا وُجد في الأول.',
            style: TextStyle(fontSize: 12),
          ),
          const SizedBox(height: 12),
          TextField(
            key: const Key('fuel-resolve-note'),
            controller: noteCtrl,
            autofocus: true,
            maxLines: 3,
            decoration: const InputDecoration(
              labelText: 'نتيجة التحقيق',
              helperText: '١٠ أحرف على الأقل',
            ),
          ),
          const SizedBox(height: 12),
          DropdownButtonFormField<String>(
            key: const Key('fuel-resolve-status'),
            value: status,
            decoration: const InputDecoration(labelText: 'الحالة'),
            items: const [
              DropdownMenuItem(value: 'resolved', child: Text('أُغلق بتفسير')),
              DropdownMenuItem(value: 'written_off', child: Text('شُطب كخسارة')),
            ],
            onChanged: (x) => setLocal(() => status = x ?? 'resolved'),
          ),
        ]),
        actions: [
          TextButton(onPressed: () => Get.back(result: false), child: const Text('إلغاء')),
          ElevatedButton(
            key: const Key('fuel-resolve-save'),
            onPressed: () => Get.back(result: true),
            child: const Text('إغلاق'),
          ),
        ],
      )),
    );

    if (ok != true) return;

    final done = await c.resolveVariance(v['id'] as int, noteCtrl.text.trim(), status);

    if (!mounted) return;
    Get.snackbar(done ? 'تم' : 'تنبيه',
        done ? 'أُغلق التحقيق' : c.lastError.value,
        backgroundColor: done ? Colors.green.shade50 : Colors.red.shade50,
        colorText: done ? Colors.green.shade900 : Colors.red.shade900,
        snackPosition: SnackPosition.BOTTOM);
  }
}
