import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';
import 'package:amyal_pay/features/agent/controllers/agent_portal_controller.dart';

/// AMIAL-AGENT-PORTAL-001 — لوحة ويب الوكيل (مدير شركة الصرافة من الكمبيوتر).
///
/// قسمان: (1) لوحة التحكم الرئيسية — KPIs + تنبيه نقص السيولة.
/// (2) إدارة السيولة — الرصيد، طلب زيادة، سجل الشحن/الخصومات، كشف حركة الرصيد.
/// تصميم متجاوب: شبكة بطاقات تتكيّف مع عرض الشاشة (مناسب لمتصفّح الكمبيوتر).
class AgentPortalScreen extends StatefulWidget {
  const AgentPortalScreen({super.key});

  @override
  State<AgentPortalScreen> createState() => _AgentPortalScreenState();
}

class _AgentPortalScreenState extends State<AgentPortalScreen> {
  late final AgentPortalController c;

  @override
  void initState() {
    super.initState();
    c = Get.find<AgentPortalController>();
    WidgetsBinding.instance.addPostFrameCallback((_) => c.loadAll());
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmyalColors.background,
      appBar: AppBar(
        title: const Text('لوحة الوكيل'),
        backgroundColor: AmyalColors.primary,
        foregroundColor: Colors.white,
        actions: [
          IconButton(icon: const Icon(Icons.refresh), tooltip: 'تحديث', onPressed: c.loadAll),
        ],
      ),
      body: Obx(() {
        if (c.isLoading.value && c.statementRows.isEmpty && c.today.value.isEmpty) {
          return const Center(child: CircularProgressIndicator());
        }
        return RefreshIndicator(
          onRefresh: c.loadAll,
          child: LayoutBuilder(builder: (context, box) {
            // أعمدة الـ KPI تتكيّف: 4 على الواسع، 2 على الضيّق.
            final cols = box.maxWidth >= 900 ? 4 : (box.maxWidth >= 560 ? 2 : 1);
            final maxW = box.maxWidth >= 1200 ? 1160.0 : box.maxWidth;
            return Center(
              child: ConstrainedBox(
                constraints: BoxConstraints(maxWidth: maxW),
                child: ListView(
                  padding: const EdgeInsets.all(16),
                  children: [
                    if (c.lowFloat.value) _lowFloatAlert(),
                    _sectionTitle('لوحة التحكم'),
                    _kpiGrid(cols),
                    const SizedBox(height: 20),
                    _sectionTitle('إدارة السيولة'),
                    _floatCard(),
                    const SizedBox(height: 16),
                    _settlementsCard(),
                    const SizedBox(height: 16),
                    _statementCard(box.maxWidth >= 720),
                    const SizedBox(height: 24),
                  ],
                ),
              ),
            );
          }),
        );
      }),
    );
  }

  Widget _sectionTitle(String t) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 8),
        child: Text(t, style: const TextStyle(fontSize: 18, fontWeight: FontWeight.bold)),
      );

  String _t(String key) => (c.today.value[key] ?? '0').toString();

  Widget _kpiGrid(int cols) {
    final items = [
      _kpi('الرصيد (السيولة)', '${c.currentFloat.value} ر.ي', Icons.account_balance_wallet, AmyalColors.primary),
      _kpi('إيداعات اليوم', '${_t('cash_in_total')} ر.ي', Icons.south_west, Colors.green.shade700),
      _kpi('سحوبات اليوم', '${_t('cash_out_total')} ر.ي', Icons.north_east, Colors.orange.shade800),
      _kpi('عدد العمليات', _t('transaction_count'), Icons.swap_horiz, Colors.blue.shade700),
      _kpi('العمولة/الأرباح', '${_t('commission_earned')} ر.ي', Icons.savings, AmyalColors.yellowDark),
      _kpi('شحن اليوم', '${_t('topup_total')} ر.ي', Icons.add_card, Colors.teal),
    ];
    return GridView.count(
      crossAxisCount: cols,
      shrinkWrap: true,
      physics: const NeverScrollableScrollPhysics(),
      mainAxisSpacing: 12,
      crossAxisSpacing: 12,
      childAspectRatio: 2.1,
      children: items,
    );
  }

  Widget _kpi(String label, String value, IconData icon, Color color) {
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: AmyalColors.cardSurface,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: AmyalColors.border),
      ),
      child: Row(children: [
        CircleAvatar(backgroundColor: color.withValues(alpha: 0.15), child: Icon(icon, color: color, size: 20)),
        const SizedBox(width: 10),
        Expanded(
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisAlignment: MainAxisAlignment.center, children: [
            Text(label, style: const TextStyle(fontSize: 12, color: AmyalColors.textSecondary)),
            const SizedBox(height: 2),
            Text(value, style: const TextStyle(fontSize: 16, fontWeight: FontWeight.bold), overflow: TextOverflow.ellipsis),
          ]),
        ),
      ]),
    );
  }

  Widget _lowFloatAlert() {
    return Container(
      margin: const EdgeInsets.only(bottom: 12),
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: AmyalColors.red.withValues(alpha: 0.08),
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: AmyalColors.red),
      ),
      child: Row(children: [
        const Icon(Icons.warning_amber, color: AmyalColors.red),
        const SizedBox(width: 10),
        const Expanded(
          child: Text('سيولتك منخفضة! اطلب زيادة الرصيد لمواصلة الإيداع للعملاء.',
              style: TextStyle(color: AmyalColors.red, fontWeight: FontWeight.bold)),
        ),
        FilledButton(
          style: FilledButton.styleFrom(backgroundColor: AmyalColors.red),
          onPressed: _topupDialog,
          child: const Text('طلب زيادة'),
        ),
      ]),
    );
  }

  Widget _floatCard() {
    final remaining = c.limits.value?['daily_cash_in_remaining'];
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(children: [
            const Icon(Icons.account_balance_wallet, color: AmyalColors.primary),
            const SizedBox(width: 8),
            const Text('الرصيد الحالي', style: TextStyle(fontWeight: FontWeight.bold)),
            const Spacer(),
            Text('${c.currentFloat.value} ر.ي',
                style: const TextStyle(fontSize: 20, fontWeight: FontWeight.bold, color: AmyalColors.primary)),
          ]),
          if (remaining != null) ...[
            const SizedBox(height: 6),
            Text('المتبقّي من حدّ الإيداع اليومي: $remaining ر.ي',
                style: const TextStyle(fontSize: 12, color: AmyalColors.textSecondary)),
          ],
          const SizedBox(height: 12),
          SizedBox(
            width: double.infinity,
            child: FilledButton.icon(
              style: FilledButton.styleFrom(backgroundColor: AmyalColors.primary),
              onPressed: _topupDialog,
              icon: const Icon(Icons.add_card),
              label: const Text('طلب زيادة الرصيد من الإدارة'),
            ),
          ),
        ]),
      ),
    );
  }

  Widget _settlementsCard() {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          const Text('سجل الشحن والتسويات', style: TextStyle(fontWeight: FontWeight.bold)),
          const SizedBox(height: 8),
          Obx(() => c.settlements.isEmpty
              ? const Padding(
                  padding: EdgeInsets.all(8),
                  child: Text('لا توجد طلبات بعد', style: TextStyle(color: AmyalColors.textMuted, fontSize: 13)))
              : Column(children: c.settlements.take(15).map(_settlementTile).toList())),
        ]),
      ),
    );
  }

  Widget _settlementTile(Map<String, dynamic> s) {
    final type = s['settlement_type'].toString();
    final isTopup = type == 'topup';
    final status = s['status'].toString();
    final statusColor = status == 'completed' || status == 'approved'
        ? Colors.green
        : status == 'pending' ? Colors.orange : AmyalColors.red;
    return ListTile(
      dense: true,
      contentPadding: EdgeInsets.zero,
      leading: Icon(isTopup ? Icons.add_card : Icons.remove_circle_outline,
          color: isTopup ? Colors.green : AmyalColors.red),
      title: Text(isTopup ? 'شحن رصيد' : 'خصم/تسوية', style: const TextStyle(fontSize: 14)),
      subtitle: Text((s['created_at'] ?? '').toString().split('T').first, style: const TextStyle(fontSize: 11)),
      trailing: Column(mainAxisAlignment: MainAxisAlignment.center, crossAxisAlignment: CrossAxisAlignment.end, children: [
        Text('${s['amount']} ر.ي', style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13)),
        Text(status, style: TextStyle(color: statusColor, fontSize: 11)),
      ]),
    );
  }

  Widget _statementCard(bool wide) {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(children: [
            const Expanded(child: Text('كشف حركة الرصيد', style: TextStyle(fontWeight: FontWeight.bold))),
            TextButton.icon(
              icon: const Icon(Icons.date_range, size: 18),
              label: Obx(() => Text('${c.from.value ?? ''} → ${c.to.value ?? ''}',
                  style: const TextStyle(fontSize: 11))),
              onPressed: _pickRange,
            ),
          ]),
          const SizedBox(height: 8),
          Obx(() {
            if (c.statementRows.isEmpty) {
              return const Padding(
                  padding: EdgeInsets.all(8),
                  child: Text('لا حركة في الفترة المختارة', style: TextStyle(color: AmyalColors.textMuted, fontSize: 13)));
            }
            return wide ? _statementTable() : _statementList();
          }),
          const Divider(),
          Obx(() => _totalsRow()),
        ]),
      ),
    );
  }

  Widget _statementTable() {
    return SingleChildScrollView(
      scrollDirection: Axis.horizontal,
      child: DataTable(
        columnSpacing: 22,
        headingRowColor: WidgetStatePropertyAll(AmyalColors.background),
        columns: const [
          DataColumn(label: Text('التاريخ')),
          DataColumn(label: Text('افتتاحي')),
          DataColumn(label: Text('إيداعات')),
          DataColumn(label: Text('سحوبات')),
          DataColumn(label: Text('شحن')),
          DataColumn(label: Text('عمولة')),
          DataColumn(label: Text('ختامي')),
          DataColumn(label: Text('عمليات')),
        ],
        rows: c.statementRows
            .map((r) => DataRow(cells: [
                  DataCell(Text(r['date'].toString())),
                  DataCell(Text(r['opening_float'].toString())),
                  DataCell(Text(r['cash_in_total'].toString())),
                  DataCell(Text(r['cash_out_total'].toString())),
                  DataCell(Text(r['topup_total'].toString())),
                  DataCell(Text(r['commission_earned'].toString())),
                  DataCell(Text(r['closing_float'].toString())),
                  DataCell(Text('${r['transaction_count']}')),
                ]))
            .toList(),
      ),
    );
  }

  Widget _statementList() {
    return Column(
      children: c.statementRows.map((r) {
        return ListTile(
          dense: true,
          contentPadding: EdgeInsets.zero,
          title: Text(r['date'].toString(), style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13)),
          subtitle: Text(
              'إيداع ${r['cash_in_total']} • سحب ${r['cash_out_total']} • شحن ${r['topup_total']} • عمولة ${r['commission_earned']}',
              style: const TextStyle(fontSize: 11)),
          trailing: Text('${r['closing_float']}', style: const TextStyle(fontWeight: FontWeight.bold)),
        );
      }).toList(),
    );
  }

  Widget _totalsRow() {
    final t = c.statementTotals.value;
    return Wrap(spacing: 14, runSpacing: 6, children: [
      _chip('إجمالي الإيداعات', '${t['cash_in_total'] ?? 0}'),
      _chip('إجمالي السحوبات', '${t['cash_out_total'] ?? 0}'),
      _chip('إجمالي الشحن', '${t['topup_total'] ?? 0}'),
      _chip('إجمالي العمولة', '${t['commission_earned'] ?? 0}'),
      _chip('عدد العمليات', '${t['transaction_count'] ?? 0}'),
    ]);
  }

  Widget _chip(String label, String value) => Chip(
        backgroundColor: AmyalColors.background,
        label: Text('$label: $value', style: const TextStyle(fontSize: 11)),
      );

  Future<void> _pickRange() async {
    final range = await showDateRangePicker(
      context: context,
      firstDate: DateTime(2023),
      lastDate: DateTime.now(),
    );
    if (range != null) {
      String fmt(DateTime d) => '${d.year}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';
      await c.setRange(fmt(range.start), fmt(range.end));
    }
  }

  void _topupDialog() {
    final amount = TextEditingController();
    final reference = TextEditingController();
    showDialog(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('طلب زيادة الرصيد'),
        content: Column(mainAxisSize: MainAxisSize.min, children: [
          TextField(controller: amount, keyboardType: const TextInputType.numberWithOptions(decimal: true),
              decoration: const InputDecoration(labelText: 'المبلغ *')),
          TextField(controller: reference,
              decoration: const InputDecoration(labelText: 'مرجع الدفع (اختياري)')),
        ]),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('إلغاء')),
          Obx(() => FilledButton(
                style: FilledButton.styleFrom(backgroundColor: AmyalColors.primary),
                onPressed: c.isSubmitting.value
                    ? null
                    : () async {
                        if (double.tryParse(amount.text.trim()) == null) return;
                        final ok = await c.requestTopup(amount.text.trim(),
                            reference: reference.text.trim().isEmpty ? null : reference.text.trim());
                        if (ctx.mounted) Navigator.pop(ctx);
                        _snack(ok ? c.lastMessage.value : c.lastError.value, ok);
                      },
                child: const Text('إرسال الطلب'),
              )),
        ],
      ),
    );
  }

  void _snack(String msg, bool ok) {
    if (!mounted || msg.isEmpty) return;
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
      content: Text(msg),
      backgroundColor: ok ? Colors.green : AmyalColors.red,
    ));
  }
}
