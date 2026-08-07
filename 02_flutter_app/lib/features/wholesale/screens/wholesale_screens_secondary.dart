import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amial_pay/theme/amial_colors.dart';
import 'package:amial_pay/features/wholesale/controllers/wholesale_controller.dart';

// =========================================================================
// قائمة الفواتير
// =========================================================================
class WholesaleInvoicesListScreenImpl extends StatefulWidget {
  const WholesaleInvoicesListScreenImpl({super.key});
  @override
  State<WholesaleInvoicesListScreenImpl> createState() => _State();
}

class _State extends State<WholesaleInvoicesListScreenImpl> {
  late final WholesaleController c;
  bool _overdueOnly = false;

  @override
  void initState() {
    super.initState();
    c = Get.find<WholesaleController>();
    WidgetsBinding.instance.addPostFrameCallback((_) => c.loadInvoices());
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(
        title: const Text('الفواتير'),
        actions: [
          IconButton(
            icon: Icon(_overdueOnly ? Icons.filter_alt : Icons.filter_alt_outlined),
            tooltip: 'المتأخّرة فقط',
            onPressed: () { setState(() => _overdueOnly = !_overdueOnly);
              c.loadInvoices(overdueOnly: _overdueOnly); },
          ),
        ],
      ),
      body: Obx(() {
        if (c.isLoading.value && c.invoices.isEmpty) return const Center(child: CircularProgressIndicator());
        if (c.invoices.isEmpty) {
          return Center(child: Text('لا توجد فواتير', style: TextStyle(color: Colors.grey.shade600)));
        }
        return ListView.builder(
          padding: const EdgeInsets.all(12),
          itemCount: c.invoices.length,
          itemBuilder: (_, i) => _invoiceCard(c.invoices[i]),
        );
      }),
    );
  }

  Widget _invoiceCard(Map<String, dynamic> inv) {
    final status = inv['status']?.toString() ?? '';
    final balance = double.tryParse('${inv['balance_due']}') ?? 0;
    final cust = (inv['customer'] ?? {}) as Map;

    final (Color color, String label) = switch(status) {
      'paid' => (Colors.green, 'مدفوعة'),
      'partial_paid' => (Colors.orange, 'جزئية'),
      'issued' => (AmialColors.primary, 'قيد السداد'),
      'overdue' => (Colors.red, 'متأخّرة'),
      'voided' => (Colors.grey, 'مُبطَلة'),
      _ => (Colors.grey, status),
    };

    return InkWell(
      onTap: () => Get.to(() => WholesaleInvoiceDetailsScreen(invoiceId: inv['id'])),
      child: Container(
        margin: const EdgeInsets.only(bottom: 8),
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(
          color: Colors.white, borderRadius: BorderRadius.circular(10),
          border: Border(right: BorderSide(color: color, width: 4)),
        ),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Row(children: [
            Text(inv['invoice_number'] ?? '',
                style: const TextStyle(fontWeight: FontWeight.bold, fontFamily: 'monospace')),
            const Spacer(),
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
              decoration: BoxDecoration(color: color.withValues(alpha: 0.15), borderRadius: BorderRadius.circular(4)),
              child: Text(label, style: TextStyle(color: color, fontSize: 11, fontWeight: FontWeight.bold)),
            ),
          ]),
          const SizedBox(height: 4),
          Text(cust['full_name'] ?? '—',
              style: TextStyle(color: Colors.grey.shade700, fontSize: 12)),
          const SizedBox(height: 4),
          Row(children: [
            Text('${inv['total_amount']} ر.ي',
                style: const TextStyle(fontSize: 15, fontWeight: FontWeight.bold, color: AmialColors.primary)),
            const Spacer(),
            if (balance > 0)
              Text('متبقّي: ${balance.toStringAsFixed(0)}',
                  style: const TextStyle(color: Colors.red, fontSize: 12, fontWeight: FontWeight.bold)),
          ]),
        ]),
      ),
    );
  }
}

// =========================================================================
// تفاصيل فاتورة + تسجيل تحصيل
// =========================================================================
class WholesaleInvoiceDetailsScreen extends StatefulWidget {
  final int invoiceId;
  const WholesaleInvoiceDetailsScreen({super.key, required this.invoiceId});
  @override
  State<WholesaleInvoiceDetailsScreen> createState() => _WholesaleInvoiceDetailsState();
}

class _WholesaleInvoiceDetailsState extends State<WholesaleInvoiceDetailsScreen> {
  late final WholesaleController c;

  @override
  void initState() {
    super.initState();
    c = Get.find<WholesaleController>();
    WidgetsBinding.instance.addPostFrameCallback((_) => c.loadInvoiceDetails(widget.invoiceId));
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(
        title: const Text('تفاصيل الفاتورة'),
        actions: [
          // AMIAL-WHOLESALE-PDF — زر تحميل/مشاركة PDF
          Obx(() => IconButton(
            icon: c.isSubmitting.value
              ? const SizedBox(width: 18, height: 18,
                  child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
              : const Icon(Icons.picture_as_pdf),
            tooltip: 'تحميل PDF',
            onPressed: c.isSubmitting.value ? null : () async {
              final ok = await c.downloadInvoicePdf(widget.invoiceId);
              if (!mounted) return;
              if (!ok) {
                ScaffoldMessenger.of(context).showSnackBar(SnackBar(
                  content: Text(c.lastError.value),
                  backgroundColor: AmialColors.red,
                ));
              }
            },
          )),
        ],
      ),
      body: Obx(() {
        final inv = c.currentInvoice.value;
        if (inv == null) return const Center(child: CircularProgressIndicator());

        final items = (inv['items'] ?? []) as List;
        final collections = (inv['collections'] ?? []) as List;
        final cust = (inv['customer'] ?? {}) as Map;
        final balance = double.tryParse('${inv['balance_due']}') ?? 0;
        final canCollect = balance > 0 && inv['status'] != 'voided';

        return SingleChildScrollView(
          padding: const EdgeInsets.all(12),
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            // header
            Container(
              padding: const EdgeInsets.all(14),
              decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(10)),
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(inv['invoice_number'] ?? '',
                    style: const TextStyle(fontSize: 18, fontWeight: FontWeight.bold, fontFamily: 'monospace')),
                Text('العميل: ${cust['full_name'] ?? '—'}',
                    style: TextStyle(color: Colors.grey.shade700)),
                Text('التاريخ: ${inv['invoice_date']} • الاستحقاق: ${inv['due_date']}',
                    style: TextStyle(color: Colors.grey.shade600, fontSize: 12)),
              ]),
            ),
            const SizedBox(height: 10),

            // عناصر
            Container(
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(10)),
              child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                const Text('الأصناف', style: TextStyle(fontWeight: FontWeight.bold)),
                const Divider(),
                ...items.map((item) => Padding(
                  padding: const EdgeInsets.symmetric(vertical: 4),
                  child: Row(children: [
                    Text('${item['line_total']} ر.ي', style: const TextStyle(fontWeight: FontWeight.bold)),
                    const Spacer(),
                    Text('${item['quantity']} × ${item['unit_price']}', style: TextStyle(color: Colors.grey.shade600, fontSize: 12)),
                    const SizedBox(width: 8),
                    Expanded(child: Text(item['product_name'] ?? '', textAlign: TextAlign.right)),
                  ]),
                )),
              ]),
            ),
            const SizedBox(height: 10),

            // إجماليات
            Container(
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(10)),
              child: Column(children: [
                _totalRow('المجموع', '${inv['subtotal']}'),
                if (double.parse('${inv['discount_amount']}') > 0)
                  _totalRow('الخصم', '- ${inv['discount_amount']}'),
                if (double.parse('${inv['tax_amount']}') > 0)
                  _totalRow('الضريبة (${inv['tax_rate']}%)', '+ ${inv['tax_amount']}'),
                const Divider(),
                _totalRow('الإجمالي', '${inv['total_amount']}', bold: true, color: AmialColors.primary),
                if (double.parse('${inv['paid_amount']}') > 0)
                  _totalRow('المدفوع', '${inv['paid_amount']}', color: Colors.green),
                if (balance > 0)
                  _totalRow('المتبقّي', '${inv['balance_due']}', bold: true, color: Colors.red),
              ]),
            ),
            const SizedBox(height: 10),

            // التحصيلات
            if (collections.isNotEmpty) Container(
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(10)),
              child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                const Text('التحصيلات', style: TextStyle(fontWeight: FontWeight.bold)),
                const Divider(),
                ...collections.map((col) => Padding(
                  padding: const EdgeInsets.symmetric(vertical: 4),
                  child: Row(children: [
                    Text('${col['amount']} ر.ي',
                        style: const TextStyle(color: Colors.green, fontWeight: FontWeight.bold)),
                    const SizedBox(width: 8),
                    Container(
                      padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                      decoration: BoxDecoration(color: Colors.grey.shade200, borderRadius: BorderRadius.circular(4)),
                      child: Text(col['payment_method'] ?? '', style: const TextStyle(fontSize: 10)),
                    ),
                    const Spacer(),
                    Text(col['collection_date'] ?? '', style: TextStyle(color: Colors.grey.shade600, fontSize: 11)),
                  ]),
                )),
              ]),
            ),

            const SizedBox(height: 16),

            // زر تحصيل
            if (canCollect) FilledButton.icon(
              onPressed: () => _showCollectDialog(balance),
              icon: const Icon(Icons.payments),
              label: Text('تسجيل تحصيل (${balance.toStringAsFixed(0)} ر.ي)'),
              style: FilledButton.styleFrom(
                backgroundColor: AmialColors.primary,
                minimumSize: const Size.fromHeight(48),
              ),
            ),
          ]),
        );
      }),
    );
  }

  Widget _totalRow(String label, String value, {bool bold = false, Color? color}) =>
      Padding(padding: const EdgeInsets.symmetric(vertical: 3),
        child: Row(children: [
          Text(value, style: TextStyle(
              fontWeight: bold ? FontWeight.bold : FontWeight.normal,
              fontSize: bold ? 16 : 14, color: color)),
          const Spacer(),
          Text(label, style: TextStyle(color: Colors.grey.shade700)),
        ]),
      );

  void _showCollectDialog(double maxAmount) {
    final amountCtrl = TextEditingController(text: maxAmount.toStringAsFixed(0));
    final refCtrl = TextEditingController();
    String method = 'cash';

    showDialog(context: context, builder: (ctx) => StatefulBuilder(builder: (_, setSt) => AlertDialog(
      title: const Text('تسجيل تحصيل'),
      content: SingleChildScrollView(child: Column(mainAxisSize: MainAxisSize.min, children: [
        TextField(controller: amountCtrl, keyboardType: TextInputType.number,
            decoration: InputDecoration(labelText: 'المبلغ *', suffixText: 'ر.ي',
                helperText: 'الحدّ الأقصى: ${maxAmount.toStringAsFixed(0)}')),
        const SizedBox(height: 10),
        const Text('طريقة الدفع'),
        Wrap(spacing: 6, children: [
          for (final m in [('cash', 'نقد'), ('bank_transfer', 'تحويل'), ('amial_pay', 'أميال'), ('check', 'شيك')])
            ChoiceChip(
              label: Text(m.$2, style: const TextStyle(fontSize: 11)),
              selected: method == m.$1,
              onSelected: (_) => setSt(() => method = m.$1),
            ),
        ]),
        const SizedBox(height: 8),
        TextField(controller: refCtrl, decoration: const InputDecoration(labelText: 'رقم المرجع (اختياري)')),
      ])),
      actions: [
        TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('إلغاء')),
        Obx(() => FilledButton(
          onPressed: c.isSubmitting.value ? null : () async {
            final amount = double.tryParse(amountCtrl.text) ?? 0;
            if (amount <= 0) return;
            final ok = await c.recordCollection(widget.invoiceId, {
              'amount': amount, 'payment_method': method,
              if (refCtrl.text.isNotEmpty) 'reference_number': refCtrl.text.trim(),
            });
            if (!mounted) return;
            if (ok) Navigator.pop(ctx);
            ScaffoldMessenger.of(context).showSnackBar(SnackBar(
              content: Text(ok ? 'تم التحصيل' : c.lastError.value),
              backgroundColor: ok ? Colors.green : AmialColors.red,
            ));
          },
          child: const Text('تسجيل'),
        )),
      ],
    )));
  }
}

// =========================================================================
// تقرير Aging
// =========================================================================
class WholesaleAgingReportScreenImpl extends StatefulWidget {
  const WholesaleAgingReportScreenImpl({super.key});
  @override
  State<WholesaleAgingReportScreenImpl> createState() => _AgingState();
}

class _AgingState extends State<WholesaleAgingReportScreenImpl> {
  late final WholesaleController c;

  @override
  void initState() {
    super.initState();
    c = Get.find<WholesaleController>();
    WidgetsBinding.instance.addPostFrameCallback((_) => c.loadAgingReport());
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(
        title: const Text('تقرير تقادم الديون'),
      ),
      body: Obx(() {
        final r = c.agingReport.value;
        if (r == null) return const Center(child: CircularProgressIndicator());

        final buckets = (r['buckets'] ?? {}) as Map;
        final pcts = (r['percentages'] ?? {}) as Map;
        final byCustomer = (r['by_customer'] ?? []) as List;

        return RefreshIndicator(
          onRefresh: c.loadAgingReport,
          child: SingleChildScrollView(
            physics: const AlwaysScrollableScrollPhysics(),
            padding: const EdgeInsets.all(12),
            child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              Container(
                padding: const EdgeInsets.all(14),
                decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(10)),
                child: Column(children: [
                  Text('${r['total_receivable']?.toStringAsFixed?.call(0) ?? r['total_receivable']} ر.ي',
                      style: const TextStyle(fontSize: 26, fontWeight: FontWeight.bold, color: AmialColors.primary)),
                  const Text('إجمالي المستحقّات'),
                ]),
              ),
              const SizedBox(height: 10),
              _bucketRow('الحالي (0-30 يوم)', buckets['current'], pcts['current'], Colors.green),
              _bucketRow('30-60 يوم', buckets['30_60'], pcts['30_60'], Colors.orange),
              _bucketRow('60-90 يوم', buckets['60_90'], pcts['60_90'], Colors.deepOrange),
              _bucketRow('أكثر من 90 يوم', buckets['over_90'], pcts['over_90'], Colors.red),
              const SizedBox(height: 14),
              const Text('بحسب العميل', style: TextStyle(fontWeight: FontWeight.bold)),
              const SizedBox(height: 6),
              ...byCustomer.map((cust) {
                final m = cust as Map;
                return Container(
                  margin: const EdgeInsets.only(bottom: 6),
                  padding: const EdgeInsets.all(10),
                  decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(8)),
                  child: Row(children: [
                    Text('${m['total']?.toStringAsFixed?.call(0) ?? m['total']} ر.ي',
                        style: const TextStyle(fontWeight: FontWeight.bold, color: AmialColors.red)),
                    const Spacer(),
                    Text('${m['invoices_count']} فاتورة', style: TextStyle(color: Colors.grey.shade600, fontSize: 11)),
                    const SizedBox(width: 8),
                    Expanded(child: Text(m['customer_name'] ?? '', textAlign: TextAlign.right)),
                  ]),
                );
              }),
            ]),
          ),
        );
      }),
    );
  }

  Widget _bucketRow(String label, dynamic value, dynamic pct, Color color) {
    final v = value is num ? value.toDouble() : (double.tryParse('$value') ?? 0);
    final p = pct is num ? pct.toDouble() : (double.tryParse('$pct') ?? 0);
    return Container(
      margin: const EdgeInsets.only(bottom: 6),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: Colors.white, borderRadius: BorderRadius.circular(8),
        border: Border(right: BorderSide(color: color, width: 4)),
      ),
      child: Row(children: [
        Text('${v.toStringAsFixed(0)} ر.ي', style: TextStyle(color: color, fontWeight: FontWeight.bold, fontSize: 15)),
        const SizedBox(width: 8),
        Text('(${p.toStringAsFixed(1)}%)', style: TextStyle(color: Colors.grey.shade600, fontSize: 11)),
        const Spacer(),
        Text(label, style: const TextStyle(fontWeight: FontWeight.bold)),
      ]),
    );
  }
}

// =========================================================================
// كشف حساب عميل
// =========================================================================
class WholesaleCustomerStatementScreenImpl extends StatefulWidget {
  final Map<String, dynamic> customer;
  const WholesaleCustomerStatementScreenImpl({super.key, required this.customer});
  @override
  State<WholesaleCustomerStatementScreenImpl> createState() => _StatementState();
}

class _StatementState extends State<WholesaleCustomerStatementScreenImpl> {
  late final WholesaleController c;

  @override
  void initState() {
    super.initState();
    c = Get.find<WholesaleController>();
    WidgetsBinding.instance.addPostFrameCallback((_) => c.loadCustomerStatement(widget.customer['id']));
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(
        title: Text(widget.customer['full_name'] ?? 'كشف حساب'),
      ),
      body: Obx(() {
        final s = c.currentStatement.value;
        if (s == null) return const Center(child: CircularProgressIndicator());

        final summary = (s['summary'] ?? {}) as Map;
        final events = (s['events'] ?? []) as List;

        return Column(children: [
          Container(
            padding: const EdgeInsets.all(12),
            color: Colors.white,
            child: Row(children: [
              Expanded(child: _summaryBox('إجمالي الفواتير',
                  '${summary['total_invoiced']?.toStringAsFixed?.call(0) ?? summary['total_invoiced']}',
                  AmialColors.primary)),
              const SizedBox(width: 6),
              Expanded(child: _summaryBox('إجمالي المدفوع',
                  '${summary['total_paid']?.toStringAsFixed?.call(0) ?? summary['total_paid']}',
                  Colors.green)),
              const SizedBox(width: 6),
              Expanded(child: _summaryBox('الرصيد',
                  '${summary['closing_balance']?.toStringAsFixed?.call(0) ?? summary['closing_balance']}',
                  AmialColors.red)),
            ]),
          ),
          Expanded(child: ListView.builder(
            padding: const EdgeInsets.all(8),
            itemCount: events.length,
            itemBuilder: (_, i) => _eventTile(events[i] as Map),
          )),
        ]);
      }),
    );
  }

  Widget _summaryBox(String label, String value, Color color) => Container(
    padding: const EdgeInsets.all(8),
    decoration: BoxDecoration(color: color.withValues(alpha: 0.1), borderRadius: BorderRadius.circular(8)),
    child: Column(children: [
      Text(value, style: TextStyle(color: color, fontWeight: FontWeight.bold, fontSize: 12),
          textAlign: TextAlign.center, overflow: TextOverflow.ellipsis),
      Text(label, style: TextStyle(color: Colors.grey.shade700, fontSize: 9), textAlign: TextAlign.center),
    ]),
  );

  Widget _eventTile(Map e) {
    final isInvoice = e['type'] == 'invoice';
    return Container(
      margin: const EdgeInsets.only(bottom: 4),
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
      decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(8)),
      child: Row(children: [
        Icon(isInvoice ? Icons.receipt : Icons.payments,
            color: isInvoice ? AmialColors.red : Colors.green, size: 20),
        const SizedBox(width: 8),
        Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(e['description'] ?? '', style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13)),
          Text(e['date'] ?? '', style: TextStyle(color: Colors.grey.shade600, fontSize: 11)),
        ])),
        Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
          Text(isInvoice ? '+ ${e['debit']}' : '- ${e['credit']}',
              style: TextStyle(color: isInvoice ? AmialColors.red : Colors.green,
                  fontWeight: FontWeight.bold, fontSize: 13)),
          Text('رصيد: ${e['running_balance']}',
              style: TextStyle(color: Colors.grey.shade600, fontSize: 10)),
        ]),
      ]),
    );
  }
}
