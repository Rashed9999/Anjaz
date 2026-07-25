import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/features/receipts/controllers/receipts_controller.dart';
import 'package:amyal_pay/features/receipts/domain/models/receipt_models.dart';
import 'package:amyal_pay/features/receipts/screens/receipt_detail_screen.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';

/// AMIAL-RECEIPTS-001 (v0.9-D)
///
/// قائمة الإيصالات — chronological list مع icon لكل نوع.
class ReceiptsListScreen extends StatefulWidget {
  const ReceiptsListScreen({super.key});

  @override
  State<ReceiptsListScreen> createState() => _ReceiptsListScreenState();
}

class _ReceiptsListScreenState extends State<ReceiptsListScreen> {
  final ScrollController _scrollController = ScrollController();

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      Get.find<ReceiptsController>().loadReceipts(refresh: true);
    });
    _scrollController.addListener(_onScroll);
  }

  void _onScroll() {
    final ctrl = Get.find<ReceiptsController>();
    if (_scrollController.position.pixels >=
            _scrollController.position.maxScrollExtent - 200 &&
        !ctrl.isLoadingMore.value &&
        ctrl.hasMore.value) {
      ctrl.loadReceipts();
    }
  }

  @override
  void dispose() {
    _scrollController.removeListener(_onScroll);
    _scrollController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmyalColors.background,
      appBar: AppBar(
        title: const Text('الإيصالات'),
      ),
      body: RefreshIndicator(
        onRefresh: () => Get.find<ReceiptsController>().loadReceipts(refresh: true),
        color: AmyalColors.primary,
        child: Obx(() {
          final ctrl = Get.find<ReceiptsController>();

          if (ctrl.isLoading.value && ctrl.receipts.isEmpty) {
            return const Center(
              child: CircularProgressIndicator(color: AmyalColors.primary),
            );
          }

          if (ctrl.receipts.isEmpty) {
            return ListView(
              children: [
                SizedBox(height: MediaQuery.of(context).size.height * 0.2),
                Icon(Icons.receipt_long_outlined,
                    size: 80, color: AmyalColors.textMuted.withValues(alpha: 0.5)),
                const SizedBox(height: 16),
                Text(
                  ctrl.lastError.value.isNotEmpty
                      ? ctrl.lastError.value
                      : 'لا توجد إيصالات بعد',
                  textAlign: TextAlign.center,
                  style: TextStyle(color: AmyalColors.textSecondary, fontSize: 14),
                ),
                const SizedBox(height: 12),
                Center(
                  child: TextButton(
                    onPressed: () => ctrl.loadReceipts(refresh: true),
                    child: const Text('إعادة المحاولة'),
                  ),
                ),
              ],
            );
          }

          return ListView.separated(
            controller: _scrollController,
            padding: const EdgeInsets.symmetric(vertical: 8),
            itemCount: ctrl.receipts.length + (ctrl.hasMore.value ? 1 : 0),
            separatorBuilder: (_, _) => const Divider(height: 1, color: AmyalColors.border),
            itemBuilder: (context, index) {
              if (index >= ctrl.receipts.length) {
                return const Padding(
                  padding: EdgeInsets.symmetric(vertical: 16),
                  child: Center(
                      child: CircularProgressIndicator(color: AmyalColors.primary)),
                );
              }
              return _ReceiptListTile(receipt: ctrl.receipts[index]);
            },
          );
        }),
      ),
    );
  }
}

class _ReceiptListTile extends StatelessWidget {
  final AmyalReceipt receipt;
  const _ReceiptListTile({required this.receipt});

  @override
  Widget build(BuildContext context) {
    final isCredit = receipt.direction == 'credit';
    final amountColor = isCredit ? Colors.green.shade700 : AmyalColors.red;
    final iconData = _iconForType(receipt.receiptType);

    return ListTile(
      tileColor: Colors.white,
      contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 4),
      leading: CircleAvatar(
        backgroundColor: AmyalColors.yellow.withValues(alpha: 0.25),
        child: Icon(iconData, color: AmyalColors.primary, size: 20),
      ),
      title: Text(
        receipt.arabicTypeLabel,
        style: const TextStyle(fontWeight: FontWeight.w600),
      ),
      subtitle: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            receipt.receiptNumber,
            style: TextStyle(
              fontSize: 11,
              color: AmyalColors.textMuted,
              fontFamily: 'monospace',
            ),
          ),
          if (receipt.issuedAt != null)
            Text(
              _formatDate(receipt.issuedAt!),
              style: TextStyle(fontSize: 11, color: AmyalColors.textSecondary),
            ),
        ],
      ),
      trailing: Column(
        mainAxisAlignment: MainAxisAlignment.center,
        crossAxisAlignment: CrossAxisAlignment.end,
        children: [
          Text(
            '${isCredit ? '+' : '-'}${_fmtAmount(receipt.amount)}',
            style: TextStyle(
              fontWeight: FontWeight.bold,
              color: amountColor,
              fontSize: 14,
            ),
          ),
          // AMIAL-FIX(RECEIPT-STATUS): كانت الشارة تعرض حالة توليد PDF الداخلية
          // («جارٍ التحضير» لكل العمليات، و«فشل» لعمليات ناجحة). الآن تعرض
          // حالة العملية نفسها بصياغة تناسب نوعها (تم التحويل/تم الدفع/…).
          Container(
            margin: const EdgeInsets.only(top: 2),
            padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
            decoration: BoxDecoration(
              color: receipt.isVoided
                  ? AmyalColors.red.withValues(alpha: 0.15)
                  : Colors.green.withValues(alpha: 0.15),
              borderRadius: BorderRadius.circular(4),
            ),
            child: Text(
              receipt.operationStatusLabel,
              style: TextStyle(
                fontSize: 9,
                color: receipt.isVoided ? AmyalColors.red : Colors.green.shade700,
              ),
            ),
          ),
        ],
      ),
      onTap: () {
        Get.to(() => ReceiptDetailScreen(receiptId: receipt.id));
      },
    );
  }

  IconData _iconForType(String type) {
    switch (type) {
      case 'send_money': return Icons.send;
      case 'cash_in': return Icons.arrow_downward;
      case 'cash_out': return Icons.arrow_upward;
      case 'add_money': return Icons.add_circle_outline;
      case 'pay_merchant':
      case 'pos_payment':
      case 'qr_payment': return Icons.store;
      case 'refund': return Icons.replay;
      case 'family_fund_contribute':
      case 'family_fund_disburse': return Icons.diversity_3;
      case 'safe_payment_funded':
      case 'safe_payment_released':
      case 'safe_payment_refunded': return Icons.lock;
      case 'fee_charge': return Icons.receipt;
      default: return Icons.receipt_long;
    }
  }

  String _fmtAmount(String s) {
    final v = double.tryParse(s) ?? 0;
    return v.toStringAsFixed(2);
  }

  String _formatDate(DateTime d) {
    final months = ['يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو',
                    'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'];
    return '${d.day} ${months[d.month - 1]} ${d.year} • ${d.hour.toString().padLeft(2, '0')}:${d.minute.toString().padLeft(2, '0')}';
  }
}
