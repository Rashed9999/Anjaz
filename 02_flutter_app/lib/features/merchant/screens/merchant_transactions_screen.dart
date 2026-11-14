import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amial_pay/features/merchant/controllers/merchant_controller.dart';
import 'package:amial_pay/features/merchant/domain/models/merchant_models.dart';
import 'package:amial_pay/features/merchant/screens/financial_truth_report_screen.dart';
import 'package:amial_pay/theme/amial_colors.dart';

/// AMIAL-MERCHANT-APP-001 (v1.6)
class MerchantTransactionsScreen extends StatefulWidget {
  const MerchantTransactionsScreen({super.key});

  @override
  State<MerchantTransactionsScreen> createState() =>
      _MerchantTransactionsScreenState();
}

class _MerchantTransactionsScreenState
    extends State<MerchantTransactionsScreen> {
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      Get.find<MerchantController>().loadTransactions();
    });
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(
        title: const Text('حركات محفظة أميال'),
      ),
      body: RefreshIndicator(
        onRefresh: () => Get.find<MerchantController>().loadTransactions(),
        color: AmialColors.primary,
        child: Obx(() {
          final ctrl = Get.find<MerchantController>();
          if (ctrl.isLoading.value && ctrl.transactions.isEmpty) {
            return const Center(
                child: CircularProgressIndicator(color: AmialColors.primary));
          }
          return ListView(
            padding: const EdgeInsets.all(12),
            children: [
              _scopeNotice(),
              const SizedBox(height: 12),
              if (ctrl.transactions.isEmpty)
                const Padding(
                  padding: EdgeInsets.only(top: 76),
                  child: Column(children: [
                    Icon(Icons.account_balance_wallet_outlined, size: 72, color: AmialColors.textMuted),
                    SizedBox(height: 12),
                    Text('لا توجد حركة إلكترونية في المحفظة بعد'),
                  ]),
                )
              else
                ...ctrl.transactions.map((tx) => _TransactionTile(transaction: tx)),
            ],
          );
        }),
      ),
    );
  }

  Widget _scopeNotice() => Container(
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(
          color: AmialColors.primary.withValues(alpha: .08),
          borderRadius: BorderRadius.circular(12),
          border: Border.all(color: AmialColors.primary.withValues(alpha: .2)),
        ),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          const Text('هذه حركات محفظة أميال الإلكترونية فقط',
              style: TextStyle(fontWeight: FontWeight.bold)),
          const SizedBox(height: 4),
          const Text('المبيعات النقدية تُراجع في الدرج، والبيع الآجل يبقى ذمّة على العميل؛ لا يظهر أي منهما كرَصيد محفظة.',
              style: TextStyle(fontSize: 12, color: AmialColors.textSecondary)),
          TextButton.icon(
            onPressed: () => Get.to(() => const FinancialTruthReportScreen(dailyOnly: true)),
            icon: const Icon(Icons.insights_outlined, size: 18),
            label: const Text('عرض تقرير المال التشغيلي'),
          ),
        ]),
      );
}

class _TransactionTile extends StatelessWidget {
  final AmialMerchantTransaction transaction;
  const _TransactionTile({required this.transaction});

  @override
  Widget build(BuildContext context) {
    final isIn = transaction.isIncoming;
    final color = isIn ? const Color(0xFF10B981) : const Color(0xFFEF4444);
    final icon = isIn ? Icons.add_circle_outline : Icons.remove_circle_outline;
    final statusColor = transaction.status == 'success'
        ? const Color(0xFF10B981)
        : transaction.status == 'pending'
            ? AmialColors.yellow
            : AmialColors.red;

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
                      style: const TextStyle(
                          fontWeight: FontWeight.w600, fontSize: 13)),
                  if (transaction.customerName != null ||
                      transaction.customerPhoneMasked != null)
                    Text(
                      transaction.customerName ??
                          transaction.customerPhoneMasked!,
                      style: const TextStyle(
                          fontSize: 11, color: AmialColors.textSecondary),
                    ),
                  if (transaction.posNumber != null)
                    Text('POS: ${transaction.posNumber}',
                        style: const TextStyle(
                            fontSize: 10, color: AmialColors.primary)),
                  Text(
                    _formatDate(transaction.createdAt),
                    style: const TextStyle(
                        fontSize: 10, color: AmialColors.textMuted),
                  ),
                ],
              ),
            ),
            Column(
              crossAxisAlignment: CrossAxisAlignment.end,
              children: [
                Text(
                  '${isIn ? "+" : "-"}${transaction.amount} ر.ي',
                  style: TextStyle(fontWeight: FontWeight.bold, color: color),
                ),
                Container(
                  padding:
                      const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                  decoration: BoxDecoration(
                    color: statusColor.withValues(alpha: 0.15),
                    borderRadius: BorderRadius.circular(4),
                  ),
                  child: Text(
                    transaction.status == 'success' ? 'ناجحة'
                        : transaction.status == 'pending' ? 'معلقة' : 'فاشلة',
                    style: TextStyle(fontSize: 10, color: statusColor),
                  ),
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
