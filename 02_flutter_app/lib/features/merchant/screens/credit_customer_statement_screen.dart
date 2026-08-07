import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:get/get.dart';
import 'package:intl/intl.dart';
import 'package:amial_pay/theme/amial_colors.dart';
import 'package:amial_pay/features/merchant/controllers/customer_credit_controller.dart';

/// AMIAL-CUSTOMER-CREDIT-001 — كشف حساب عميل + تسجيل سداد/مرتجع.
class CreditCustomerStatementScreen extends StatefulWidget {
  final Map<String, dynamic> customer;
  const CreditCustomerStatementScreen({super.key, required this.customer});

  @override
  State<CreditCustomerStatementScreen> createState() => _CreditCustomerStatementScreenState();
}

class _CreditCustomerStatementScreenState extends State<CreditCustomerStatementScreen> {
  late final CustomerCreditController c;
  DateTime? _from;
  DateTime? _to;

  @override
  void initState() {
    super.initState();
    c = Get.find<CustomerCreditController>();
    WidgetsBinding.instance.addPostFrameCallback((_) => _refresh());
  }

  void _refresh() {
    c.loadStatement(
      widget.customer['id'] as int,
      from: _from != null ? DateFormat('yyyy-MM-dd').format(_from!) : null,
      to: _to != null ? DateFormat('yyyy-MM-dd').format(_to!) : null,
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(
        title: Text(widget.customer['customer_name'] ?? 'كشف حساب'),
      ),
      body: Obx(() {
        if (c.isLoadingStatement.value) {
          return const Center(child: CircularProgressIndicator());
        }
        final s = c.statement.value;
        if (s == null) {
          return const Center(child: Text('لا توجد بيانات'));
        }
        final account = s['account'] as Map? ?? {};
        final movements = (s['movements'] ?? []) as List;
        final totals = (s['totals'] ?? {}) as Map;
        final closing = double.tryParse('${s['closing_balance'] ?? 0}') ?? 0;

        return RefreshIndicator(
          onRefresh: () async => _refresh(),
          child: ListView(
            padding: const EdgeInsets.all(16),
            children: [
              _balanceCard(account, closing),
              const SizedBox(height: 12),
              _dateFilter(),
              const SizedBox(height: 12),
              _totalsRow(totals),
              const SizedBox(height: 12),
              _actionsRow(account),
              const SizedBox(height: 16),
              const Text('الحركات', style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold)),
              const SizedBox(height: 8),
              if (movements.isEmpty)
                const Padding(padding: EdgeInsets.all(20), child: Center(child: Text('لا توجد حركات في هذه الفترة')))
              else
                ...movements.map((m) => _movementTile(m as Map)).toList().reversed,
            ],
          ),
        );
      }),
    );
  }

  Widget _balanceCard(Map account, double closing) {
    final cls = account['classification'] ?? 'bronze';
    final clsColor = cls == 'gold' ? AmialColors.yellowDark
        : cls == 'silver' ? Colors.grey.shade400 : Colors.brown.shade400;
    final lim = double.tryParse('${account['credit_limit'] ?? 0}') ?? 0;
    final util = lim > 0 ? ((closing / lim) * 100).clamp(0, 200).toDouble() : 0.0;

    return Container(
      padding: const EdgeInsets.all(20),
      decoration: BoxDecoration(color: AmialColors.primary, borderRadius: BorderRadius.circular(16)),
      child: Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
        Row(children: [
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
            decoration: BoxDecoration(color: clsColor, borderRadius: BorderRadius.circular(8)),
            child: Text(cls == 'gold' ? '⭐ ذهبي' : cls == 'silver' ? 'فضّي' : 'برونزي',
                style: const TextStyle(color: Colors.white, fontWeight: FontWeight.bold, fontSize: 11)),
          ),
          const Spacer(),
          const Text('الرصيد الحالي', style: TextStyle(color: Colors.white70)),
        ]),
        const SizedBox(height: 10),
        Text('${closing.toStringAsFixed(0)} ر.ي',
            style: const TextStyle(color: Colors.white, fontSize: 32, fontWeight: FontWeight.bold)),
        if (lim > 0) ...[
          const SizedBox(height: 10),
          ClipRRect(
            borderRadius: BorderRadius.circular(4),
            child: LinearProgressIndicator(
              value: (util / 100).clamp(0, 1).toDouble(),
              backgroundColor: Colors.white24,
              valueColor: AlwaysStoppedAnimation(
                util < 60 ? Colors.green : util < 90 ? AmialColors.yellow : AmialColors.red,
              ),
              minHeight: 8,
            ),
          ),
          const SizedBox(height: 4),
          Text('الحد: ${lim.toStringAsFixed(0)} • الاستهلاك ${util.toStringAsFixed(0)}%',
              style: const TextStyle(color: Colors.white70, fontSize: 12)),
        ],
      ]),
    );
  }

  Widget _dateFilter() {
    return Row(children: [
      Expanded(child: _dateBtn('من', _from, (d) { setState(() => _from = d); _refresh(); })),
      const SizedBox(width: 8),
      Expanded(child: _dateBtn('إلى', _to, (d) { setState(() => _to = d); _refresh(); })),
      if (_from != null || _to != null)
        IconButton(
          icon: const Icon(Icons.clear),
          onPressed: () { setState(() { _from = null; _to = null; }); _refresh(); },
        ),
    ]);
  }

  Widget _dateBtn(String label, DateTime? value, void Function(DateTime) onPicked) {
    return OutlinedButton.icon(
      onPressed: () async {
        final picked = await showDatePicker(
          context: context,
          initialDate: value ?? DateTime.now(),
          firstDate: DateTime(2020),
          lastDate: DateTime(2030),
        );
        if (picked != null) onPicked(picked);
      },
      icon: const Icon(Icons.calendar_today, size: 16),
      label: Text(value == null ? label : DateFormat('yyyy-MM-dd').format(value)),
    );
  }

  Widget _totalsRow(Map totals) {
    return Row(children: [
      Expanded(child: _statCard('مدين (-)', totals['credit'] ?? '0', Colors.green.shade700)),
      const SizedBox(width: 8),
      Expanded(child: _statCard('دائن (+)', totals['debit'] ?? '0', AmialColors.red)),
    ]);
  }

  Widget _statCard(String title, dynamic value, Color color) {
    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(10)),
      child: Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
        Text(title, style: TextStyle(color: Colors.grey.shade600, fontSize: 12)),
        const SizedBox(height: 4),
        Text('$value ر.ي', style: TextStyle(color: color, fontWeight: FontWeight.bold, fontSize: 16)),
      ]),
    );
  }

  Widget _actionsRow(Map account) {
    return Column(children: [
      Row(children: [
        Expanded(child: FilledButton.icon(
          onPressed: () => _movementDialog('سداد', 'payment'),
          icon: const Icon(Icons.payments),
          label: const Text('سداد'),
          style: FilledButton.styleFrom(backgroundColor: Colors.green.shade700),
        )),
        const SizedBox(width: 8),
        Expanded(child: OutlinedButton.icon(
          onPressed: () => _movementDialog('مرتجع', 'return'),
          icon: const Icon(Icons.undo),
          label: const Text('مرتجع'),
        )),
        const SizedBox(width: 8),
        IconButton(
          onPressed: () => _movementDialog('تعديل', 'adjustment'),
          icon: const Icon(Icons.tune),
          tooltip: 'تعديل يدوي',
        ),
      ]),
      const SizedBox(height: 8),
      // AMIAL-CREDIT-PDF-001 — زر تحميل/مشاركة كشف PDF
      Row(children: [
        Expanded(child: OutlinedButton.icon(
          onPressed: () => _downloadPdf(account),
          icon: const Icon(Icons.picture_as_pdf, color: AmialColors.red),
          label: const Text('تحميل كشف PDF'),
          style: OutlinedButton.styleFrom(
            side: const BorderSide(color: AmialColors.red),
            foregroundColor: AmialColors.red,
          ),
        )),
      ]),
    ]);
  }

  /// يفتح رابط الـ PDF عبر المتصفّح/الـ download intent.
  /// URL: /api/v1/amial/merchant/credit/customers/{id}/statement/pdf
  void _downloadPdf(Map account) {
    final id = account['id'];
    if (id == null) return;
    final url = '/api/v1/amial/merchant/credit/customers/$id/statement/pdf';
    Clipboard.setData(ClipboardData(text: url));
    Get.snackbar(
      'الرابط جاهز',
      'افتح الرابط في المتصفّح لتحميل PDF (تم نسخه)',
      backgroundColor: AmialColors.yellow.withValues(alpha: 0.2),
      duration: const Duration(seconds: 4),
      snackPosition: SnackPosition.BOTTOM,
    );
  }

  Widget _movementTile(Map m) {
    final amount = '${m['amount']}';
    final isNegative = amount.startsWith('-');
    final cleanAmount = amount.replaceAll('-', '');
    final type = m['type'];
    final label = type == 'sale' ? 'بيع آجل'
        : type == 'payment' ? 'سداد دفعة'
        : type == 'return' ? 'مرتجع مبيعات'
        : 'تعديل';
    final icon = type == 'sale' ? Icons.shopping_cart
        : type == 'payment' ? Icons.payments
        : type == 'return' ? Icons.undo : Icons.tune;
    final color = isNegative ? Colors.green.shade700 : AmialColors.red;

    return Container(
      margin: const EdgeInsets.only(bottom: 6),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(10)),
      child: Row(children: [
        // المبلغ + الرصيد
        Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text('${isNegative ? '-' : '+'}$cleanAmount ر.ي',
              style: TextStyle(color: color, fontSize: 16, fontWeight: FontWeight.bold)),
          Text('الرصيد: ${m['balance_after']}', style: TextStyle(color: Colors.grey.shade600, fontSize: 11)),
        ]),
        const Spacer(),
        // الوصف
        Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
          Text(label, style: const TextStyle(fontWeight: FontWeight.bold)),
          if (m['reference_number'] != null)
            Text('${m['reference_number']}', style: TextStyle(color: Colors.grey.shade600, fontSize: 11)),
          if (m['due_date'] != null)
            Text('استحقاق: ${m['due_date'].toString().substring(0, 10)}',
                style: TextStyle(color: AmialColors.yellowDark, fontSize: 11)),
          if (m['note'] != null && '${m['note']}'.isNotEmpty)
            Text('${m['note']}', style: TextStyle(color: Colors.grey.shade700, fontSize: 11)),
        ])),
        const SizedBox(width: 8),
        Icon(icon, color: color, size: 28),
      ]),
    );
  }

  Future<void> _movementDialog(String title, String type) async {
    final amountCtrl = TextEditingController();
    final noteCtrl = TextEditingController();
    final cid = widget.customer['id'] as int;

    final result = await Get.dialog<bool>(AlertDialog(
      title: Text(title),
      content: SingleChildScrollView(child: Column(mainAxisSize: MainAxisSize.min, children: [
        TextField(
          controller: amountCtrl,
          keyboardType: TextInputType.number,
          textAlign: TextAlign.right,
          decoration: const InputDecoration(labelText: 'المبلغ (موقّع للتعديل: +500 أو -200)'),
        ),
        const SizedBox(height: 12),
        TextField(
          controller: noteCtrl,
          textAlign: TextAlign.right,
          decoration: const InputDecoration(labelText: 'ملاحظة'),
        ),
      ])),
      actions: [
        TextButton(onPressed: () => Get.back(result: false), child: const Text('إلغاء')),
        Obx(() => FilledButton(
          onPressed: c.isSubmitting.value ? null : () async {
            bool ok = false;
            if (type == 'payment') {
              ok = await c.recordPayment(cid, amountCtrl.text, note: noteCtrl.text);
            } else if (type == 'return') {
              ok = await c.recordReturn(cid, amountCtrl.text, note: noteCtrl.text);
            } else {
              if (noteCtrl.text.isEmpty) {
                Get.snackbar('تنبيه', 'التعديل اليدوي يحتاج سبباً',
                    backgroundColor: AmialColors.red.withValues(alpha: 0.1));
                return;
              }
              ok = await c.recordAdjustment(cid, amountCtrl.text, noteCtrl.text);
            }
            if (ok) {
              Get.back(result: true);
            } else {
              Get.snackbar('فشل', c.lastError.value, backgroundColor: AmialColors.red.withValues(alpha: 0.1));
            }
          },
          child: c.isSubmitting.value
              ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
              : const Text('تأكيد'),
        )),
      ],
    ));

    if (result == true) _refresh();
  }
}
