import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/features/merchant/controllers/split_bill_controller.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';
import 'package:amyal_pay/helper/amial_money.dart';

/// AMIAL-SPLIT-BILL-001 — حصص العميل المعلّقة في الفواتير المقسّمة + دفعها.
class SplitBillMySharesScreen extends StatefulWidget {
  const SplitBillMySharesScreen({super.key});

  @override
  State<SplitBillMySharesScreen> createState() => _SplitBillMySharesScreenState();
}

class _SplitBillMySharesScreenState extends State<SplitBillMySharesScreen> {
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      Get.find<SplitBillController>().loadMyShares();
    });
  }

  Future<void> _confirmPay(Map<String, dynamic> share) async {
    final id = (share['id'] ?? share['participant_id']) as int;
    final amount = share['share_amount'] ?? '';

    final confirm = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('تأكيد دفع الحصة'),
        content: Text('سيتم دفع ${AmialMoney.yer(amount)} للتاجر. متابعة؟'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('إلغاء')),
          ElevatedButton(
            onPressed: () => Navigator.pop(ctx, true),
            style: ElevatedButton.styleFrom(backgroundColor: AmyalColors.primary, foregroundColor: Colors.white),
            child: const Text('ادفع'),
          ),
        ],
      ),
    );
    if (confirm != true) return;

    final ctrl = Get.find<SplitBillController>();
    final ok = await ctrl.payShare(id);
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
      content: Text(ok ? 'تم دفع حصتك بنجاح' : (ctrl.lastError.value.isNotEmpty ? ctrl.lastError.value : 'فشل الدفع')),
      backgroundColor: ok ? Colors.green : AmyalColors.red,
    ));
  }

  @override
  Widget build(BuildContext context) {
    final ctrl = Get.find<SplitBillController>();

    return Scaffold(
      backgroundColor: AmyalColors.background,
      appBar: AppBar(
        backgroundColor: AmyalColors.primary,
        foregroundColor: Colors.white,
        title: const Text('حصصي في الفواتير'),
      ),
      body: RefreshIndicator(
        onRefresh: () => ctrl.loadMyShares(),
        child: Obx(() {
          if (ctrl.isLoadingShares.value && ctrl.myShares.isEmpty) {
            return const Center(child: CircularProgressIndicator());
          }
          if (ctrl.myShares.isEmpty) {
            return ListView(
              children: const [
                SizedBox(height: 120),
                Icon(Icons.check_circle_outline, size: 64, color: AmyalColors.textMuted),
                SizedBox(height: 12),
                Center(child: Text('لا توجد حصص معلّقة', style: TextStyle(color: AmyalColors.textSecondary))),
              ],
            );
          }
          return ListView.separated(
            padding: const EdgeInsets.all(16),
            itemCount: ctrl.myShares.length,
            separatorBuilder: (_, _) => const SizedBox(height: 10),
            itemBuilder: (context, i) {
              final share = ctrl.myShares[i];
              final bill = (share['split_bill'] ?? {}) as Map;
              final id = (share['id'] ?? share['participant_id']) as int;
              final isPaying = ctrl.payingParticipantId.value == id;

              return Card(
                color: AmyalColors.cardSurface,
                shape: RoundedRectangleBorder(
                  borderRadius: BorderRadius.circular(10),
                  side: const BorderSide(color: AmyalColors.border),
                ),
                child: Padding(
                  padding: const EdgeInsets.all(14),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        mainAxisAlignment: MainAxisAlignment.spaceBetween,
                        children: [
                          Expanded(
                            child: Text(
                              (bill['note'] ?? 'فاتورة مقسّمة').toString(),
                              style: const TextStyle(fontWeight: FontWeight.bold),
                              overflow: TextOverflow.ellipsis,
                            ),
                          ),
                          Text(AmialMoney.yer(share['share_amount'] ?? ''),
                              style: const TextStyle(
                                  fontWeight: FontWeight.bold, fontSize: 16, color: AmyalColors.primary)),
                        ],
                      ),
                      const SizedBox(height: 4),
                      Text('إجمالي الفاتورة: ${AmialMoney.yer(bill['total_amount'] ?? '')}',
                          style: const TextStyle(fontSize: 12, color: AmyalColors.textSecondary)),
                      const SizedBox(height: 10),
                      SizedBox(
                        width: double.infinity,
                        child: ElevatedButton(
                          onPressed: isPaying ? null : () => _confirmPay(Map<String, dynamic>.from(share)),
                          style: ElevatedButton.styleFrom(
                            backgroundColor: AmyalColors.primary,
                            foregroundColor: Colors.white,
                          ),
                          child: isPaying
                              ? const SizedBox(
                                  height: 18, width: 18,
                                  child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                              : const Text('ادفع حصتي'),
                        ),
                      ),
                    ],
                  ),
                ),
              );
            },
          );
        }),
      ),
    );
  }
}
