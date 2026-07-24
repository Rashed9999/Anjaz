import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/features/agent/controllers/agent_controller.dart';
import 'package:amyal_pay/features/agent/domain/models/agent_models.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';
import 'package:amyal_pay/helper/amial_money.dart';

/// AMIAL-AGENT-APP-001 (v1.6)
class AgentTransactionsScreen extends StatefulWidget {
  const AgentTransactionsScreen({super.key});

  @override
  State<AgentTransactionsScreen> createState() => _AgentTransactionsScreenState();
}

class _AgentTransactionsScreenState extends State<AgentTransactionsScreen> {
  String _filter = 'all';

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      Get.find<AgentController>().loadTransactions();
    });
  }

  void _setFilter(String f) {
    if (!mounted) return; // AMIAL-FIX-006
    setState(() => _filter = f);
    Get.find<AgentController>().loadTransactions(type: f == 'all' ? null : f);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmyalColors.background,
      appBar: AppBar(
        backgroundColor: AmyalColors.primary,
        foregroundColor: Colors.white,
        title: const Text('عملياتي'),
      ),
      body: Column(
        children: [
          // ====== Filter chips ======
          Container(
            color: Colors.white,
            padding: const EdgeInsets.symmetric(vertical: 8, horizontal: 12),
            child: SingleChildScrollView(
              scrollDirection: Axis.horizontal,
              child: Row(
                children: [
                  _FilterChip(label: 'الكل', value: 'all',
                      current: _filter, onSelect: _setFilter),
                  _FilterChip(label: 'إيداع', value: 'cash_in',
                      current: _filter, onSelect: _setFilter),
                  _FilterChip(label: 'سحب', value: 'cash_out',
                      current: _filter, onSelect: _setFilter),
                  _FilterChip(label: 'إضافة رصيد', value: 'add_money',
                      current: _filter, onSelect: _setFilter),
                  _FilterChip(label: 'سحب بنكي', value: 'withdraw',
                      current: _filter, onSelect: _setFilter),
                ],
              ),
            ),
          ),

          // ====== List ======
          Expanded(
            child: RefreshIndicator(
              onRefresh: () => Get.find<AgentController>()
                  .loadTransactions(type: _filter == 'all' ? null : _filter),
              color: AmyalColors.primary,
              child: Obx(() {
                final ctrl = Get.find<AgentController>();
                if (ctrl.isLoading.value && ctrl.transactions.isEmpty) {
                  return const Center(
                      child: CircularProgressIndicator(color: AmyalColors.primary));
                }
                if (ctrl.transactions.isEmpty) {
                  return ListView(
                    children: const [
                      SizedBox(height: 100),
                      Icon(Icons.inbox, size: 80, color: AmyalColors.textMuted),
                      SizedBox(height: 12),
                      Center(child: Text('لا توجد عمليات')),
                    ],
                  );
                }
                return ListView.builder(
                  padding: const EdgeInsets.all(12),
                  itemCount: ctrl.transactions.length,
                  itemBuilder: (context, i) =>
                      _TransactionTile(transaction: ctrl.transactions[i]),
                );
              }),
            ),
          ),
        ],
      ),
    );
  }
}

class _FilterChip extends StatelessWidget {
  final String label;
  final String value;
  final String current;
  final ValueChanged<String> onSelect;
  const _FilterChip({
    required this.label,
    required this.value,
    required this.current,
    required this.onSelect,
  });

  @override
  Widget build(BuildContext context) {
    final isActive = current == value;
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 4),
      child: ChoiceChip(
        label: Text(label, style: TextStyle(fontSize: 12,
            color: isActive ? Colors.white : AmyalColors.textPrimary)),
        selected: isActive,
        selectedColor: AmyalColors.primary,
        onSelected: (_) => onSelect(value),
      ),
    );
  }
}

class _TransactionTile extends StatelessWidget {
  final AmyalAgentTransaction transaction;
  const _TransactionTile({required this.transaction});

  @override
  Widget build(BuildContext context) {
    final isIn = transaction.type == 'cash_in' || transaction.type == 'add_money';
    final color = isIn ? const Color(0xFF10B981) : const Color(0xFFEF4444);
    final icon = isIn ? Icons.arrow_downward : Icons.arrow_upward;
    final statusColor = transaction.status == 'success'
        ? const Color(0xFF10B981)
        : transaction.status == 'pending'
            ? AmyalColors.yellow
            : AmyalColors.red;

    return Card(
      margin: const EdgeInsets.symmetric(vertical: 4),
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Row(
          children: [
            CircleAvatar(
              radius: 22,
              backgroundColor: color.withValues(alpha: 0.15),
              child: Icon(icon, color: color),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(transaction.typeLabel,
                      style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 13)),
                  if (transaction.counterpartyName != null ||
                      transaction.counterpartyPhoneMasked != null)
                    Text(
                      transaction.counterpartyName ??
                          transaction.counterpartyPhoneMasked!,
                      style: const TextStyle(
                          fontSize: 11, color: AmyalColors.textSecondary),
                    ),
                  Text(
                    _formatDate(transaction.createdAt),
                    style: const TextStyle(
                        fontSize: 10, color: AmyalColors.textMuted),
                  ),
                ],
              ),
            ),
            Column(
              crossAxisAlignment: CrossAxisAlignment.end,
              children: [
                Text(
                  '${isIn ? "+" : "-"}${AmialMoney.fmt(transaction.amount)} ر.ي',
                  style: TextStyle(fontWeight: FontWeight.bold, color: color),
                ),
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                  decoration: BoxDecoration(
                    color: statusColor.withValues(alpha: 0.15),
                    borderRadius: BorderRadius.circular(4),
                  ),
                  child: Text(transaction.statusLabel,
                      style: TextStyle(fontSize: 10, color: statusColor)),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }

  String _formatDate(DateTime d) {
    return '${d.year}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')} '
        '${d.hour.toString().padLeft(2, '0')}:${d.minute.toString().padLeft(2, '0')}';
  }
}
