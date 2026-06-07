import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/features/merchant/controllers/merchant_controller.dart';
import 'package:amyal_pay/features/merchant/domain/models/merchant_models.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';

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
      backgroundColor: AmyalColors.background,
      appBar: AppBar(
        backgroundColor: AmyalColors.primary,
        foregroundColor: Colors.white,
        title: const Text('المبيعات والعمليات'),
      ),
      body: RefreshIndicator(
        onRefresh: () => Get.find<MerchantController>().loadTransactions(),
        color: AmyalColors.primary,
        child: Obx(() {
          final ctrl = Get.find<MerchantController>();
          if (ctrl.isLoading.value && ctrl.transactions.isEmpty) {
            return const Center(
                child: CircularProgressIndicator(color: AmyalColors.primary));
          }
          if (ctrl.transactions.isEmpty) {
            return ListView(
              children: const [
                SizedBox(height: 100),
                Icon(Icons.point_of_sale, size: 80, color: AmyalColors.textMuted),
                SizedBox(height: 12),
                Center(child: Text('لا توجد عمليات بعد')),
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
    );
  }
}

class _TransactionTile extends StatelessWidget {
  final AmyalMerchantTransaction transaction;
  const _TransactionTile({required this.transaction});

  @override
  Widget build(BuildContext context) {
    final isIn = transaction.isIncoming;
    final color = isIn ? const Color(0xFF10B981) : const Color(0xFFEF4444);
    final icon = isIn ? Icons.add_circle_outline : Icons.remove_circle_outline;
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
                      style: const TextStyle(
                          fontWeight: FontWeight.w600, fontSize: 13)),
                  if (transaction.customerName != null ||
                      transaction.customerPhoneMasked != null)
                    Text(
                      transaction.customerName ??
                          transaction.customerPhoneMasked!,
                      style: const TextStyle(
                          fontSize: 11, color: AmyalColors.textSecondary),
                    ),
                  if (transaction.posNumber != null)
                    Text('POS: ${transaction.posNumber}',
                        style: const TextStyle(
                            fontSize: 10, color: AmyalColors.primary)),
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
                  '${isIn ? "+" : "-"}${transaction.amount} ر.س',
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
