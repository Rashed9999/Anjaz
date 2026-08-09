import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:get/get.dart';
import 'package:amial_pay/theme/amial_colors.dart';
import 'package:amial_pay/features/fuel_station/controllers/fuel_vertical_controller.dart';
import 'package:amial_pay/features/fuel_station/widgets/fuel_state_view.dart';

/// AMIAL-FUEL-VERTICAL-001 · المرحلة ٨ — حركةُ نقد الوردية.
///
/// **وبلا هذه الشاشة كان كلُّ ريالٍ يخرج للمصروفات يظهر عجزاً** في وجه
/// الكاشير: اشترى ماءً للمحطّة بألفين، فيُطالَب بألفين آخرَ الوردية.
class FuelShiftCashScreen extends StatefulWidget {
  final int shiftId;
  const FuelShiftCashScreen({super.key, required this.shiftId});

  @override
  State<FuelShiftCashScreen> createState() => _FuelShiftCashScreenState();
}

class _FuelShiftCashScreenState extends State<FuelShiftCashScreen> {
  late final FuelVerticalController c;

  @override
  void initState() {
    super.initState();
    c = Get.find<FuelVerticalController>();
    WidgetsBinding.instance
        .addPostFrameCallback((_) => c.loadShiftCash(widget.shiftId));
  }

  Future<void> _refresh() => c.loadShiftCash(widget.shiftId);

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(title: const Text('نقد الوردية')),
      body: RefreshIndicator(
        onRefresh: _refresh,
        child: FuelStateView(
          c: c,
          isEmpty: c.cashMovements.isEmpty,
          emptyTitle: 'لا حركات نقد',
          emptyHint: 'سجّل المصروفات والإيداعات هنا حتى لا تظهر عجزاً في الإغلاق.',
          emptyIcon: Icons.account_balance_wallet_outlined,
          onRetry: _refresh,
          child: Obx(() => ListView(
                padding: const EdgeInsets.all(16),
                children: [
                  _summary(),
                  const SizedBox(height: 12),
                  for (final m in c.cashMovements) _row(m),
                  const SizedBox(height: 80),
                ],
              )),
        ),
      ),
      floatingActionButton: Obx(() => c.can('cash.move')
          ? FloatingActionButton.extended(
              key: const Key('fuel-add-cash-move'),
              onPressed: _addDialog,
              icon: const Icon(Icons.add),
              label: const Text('حركة نقد'),
            )
          : const SizedBox.shrink()),
    );
  }

  Widget _summary() {
    final s = c.cashSummary;
    if (s.isEmpty) return const SizedBox.shrink();

    return Card(
      key: const Key('fuel-cash-summary'),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(children: [
          _line('داخل', '${s['in'] ?? '0'}', Colors.green.shade700),
          _line('خارج', '${s['out'] ?? '0'}', Colors.red.shade700),
          const Divider(),
          _line('الصافي (يُضاف للمتوقَّع)', '${s['net'] ?? '0'}',
              AmialColors.textPrimary, bold: true),
          const SizedBox(height: 6),
          Text('منها مصروفات: ${s['expenses'] ?? '0'}',
              style: TextStyle(fontSize: 12, color: AmialColors.textMuted)),
        ]),
      ),
    );
  }

  Widget _line(String k, String v, Color color, {bool bold = false}) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 3),
        child: Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
          Text(k, style: const TextStyle(fontSize: 14)),
          Text(v, style: TextStyle(
              fontSize: 15,
              color: color,
              fontWeight: bold ? FontWeight.bold : FontWeight.normal)),
        ]),
      );

  Widget _row(Map<String, dynamic> m) {
    final isIn = m['direction'] == 'in';

    return Card(
      key: Key('fuel-cash-${m['id']}'),
      child: ListTile(
        leading: Icon(
          isIn ? Icons.arrow_downward_rounded : Icons.arrow_upward_rounded,
          color: isIn ? Colors.green.shade700 : Colors.red.shade700,
        ),
        title: Text('${m['reason_ar']}'),
        subtitle: Text([
          if ((m['note'] ?? '').toString().isNotEmpty) '${m['note']}',
          if ((m['actor'] ?? '').toString().isNotEmpty) '${m['actor']}',
        ].join(' · ')),
        trailing: Text('${isIn ? '+' : '−'} ${m['amount']}',
            style: TextStyle(
                fontWeight: FontWeight.bold,
                color: isIn ? Colors.green.shade700 : Colors.red.shade700)),
      ),
    );
  }

  Future<void> _addDialog() async {
    final amountCtrl = TextEditingController();
    final noteCtrl = TextEditingController();
    String reason = 'expense';

    // **الاتّجاهُ يتبع السبب ولا يُختار** — والخلطُ يقلب إشارة الفرق،
    // فيصير المصروفُ زيادةً في المتوقَّع بدل نقصان.
    String directionOf(String r) => switch (r) {
          'cash_in' || 'change_fund' => 'in',
          _ => 'out',
        };

    final ok = await Get.dialog<bool>(
      StatefulBuilder(builder: (ctx, setLocal) => AlertDialog(
        title: const Text('حركة نقد'),
        content: Column(mainAxisSize: MainAxisSize.min, children: [
          DropdownButtonFormField<String>(
            key: const Key('fuel-cash-reason'),
            value: reason,
            decoration: const InputDecoration(labelText: 'السبب'),
            items: const [
              DropdownMenuItem(value: 'expense', child: Text('مصروف (خروج)')),
              DropdownMenuItem(value: 'cash_drop', child: Text('تسليم للخزنة (خروج)')),
              DropdownMenuItem(value: 'refund', child: Text('استرجاع لعميل (خروج)')),
              DropdownMenuItem(value: 'cash_in', child: Text('إيداع نقد (دخول)')),
              DropdownMenuItem(value: 'change_fund', child: Text('فكّة (دخول)')),
            ],
            onChanged: (v) => setLocal(() => reason = v ?? 'expense'),
          ),
          const SizedBox(height: 12),
          TextField(
            key: const Key('fuel-cash-amount'),
            controller: amountCtrl,
            keyboardType: const TextInputType.numberWithOptions(decimal: true),
            inputFormatters: [FilteringTextInputFormatter.allow(RegExp(r'[0-9.]'))],
            decoration: InputDecoration(
              labelText: 'المبلغ',
              helperText: directionOf(reason) == 'out'
                  ? 'سيُنقص من النقد المتوقَّع'
                  : 'سيُضاف إلى النقد المتوقَّع',
            ),
          ),
          const SizedBox(height: 12),
          TextField(
            controller: noteCtrl,
            decoration: const InputDecoration(labelText: 'ملاحظة'),
          ),
        ]),
        actions: [
          TextButton(onPressed: () => Get.back(result: false), child: const Text('إلغاء')),
          ElevatedButton(
            key: const Key('fuel-cash-save'),
            onPressed: () => Get.back(result: true),
            child: const Text('تسجيل'),
          ),
        ],
      )),
    );

    if (ok != true) return;

    final done = await c.addCashMovement(widget.shiftId, {
      'direction': directionOf(reason),
      'reason': reason,
      'amount': amountCtrl.text.trim(),
      if (noteCtrl.text.trim().isNotEmpty) 'note': noteCtrl.text.trim(),
    });

    if (!mounted) return;
    Get.snackbar(done ? 'تم' : 'تنبيه',
        done ? 'سُجّلت الحركة' : c.lastError.value,
        backgroundColor: done ? Colors.green.shade50 : Colors.red.shade50,
        colorText: done ? Colors.green.shade900 : Colors.red.shade900,
        snackPosition: SnackPosition.BOTTOM);
  }
}
