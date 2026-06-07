import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/features/family_fund/controllers/funds_controller.dart';
import 'package:amyal_pay/features/family_fund/domain/models/fund_models.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';

/// AMIAL-FUND-FAMILY-001 (v0.9-D)
class FundDetailScreen extends StatefulWidget {
  final String fundUlid;
  const FundDetailScreen({super.key, required this.fundUlid});

  @override
  State<FundDetailScreen> createState() => _FundDetailScreenState();
}

class _FundDetailScreenState extends State<FundDetailScreen> {
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      Get.find<FundsController>().loadFundDetails(widget.fundUlid);
    });
  }

  Future<void> _openContributeSheet() async {
    final amountCtrl = TextEditingController();
    final noteCtrl = TextEditingController();
    final formKey = GlobalKey<FormState>();

    final ok = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.white,
      shape: const RoundedRectangleBorder(
          borderRadius: BorderRadius.vertical(top: Radius.circular(16))),
      builder: (ctx) => Padding(
        padding: EdgeInsets.only(
          bottom: MediaQuery.of(ctx).viewInsets.bottom,
          left: 16, right: 16, top: 16,
        ),
        child: Form(
          key: formKey,
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              const Text('مساهمة في الصندوق',
                  style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold)),
              const SizedBox(height: 16),
              TextFormField(
                controller: amountCtrl,
                keyboardType: const TextInputType.numberWithOptions(decimal: true),
                decoration: const InputDecoration(
                  labelText: 'المبلغ (ر.س) *',
                  border: OutlineInputBorder(),
                ),
                validator: (v) {
                  final n = double.tryParse(v ?? '');
                  if (n == null || n <= 0) return 'مبلغ غير صحيح';
                  return null;
                },
              ),
              const SizedBox(height: 12),
              TextFormField(
                controller: noteCtrl,
                maxLength: 500,
                decoration: const InputDecoration(
                  labelText: 'بيان (اختياري)',
                  border: OutlineInputBorder(),
                ),
              ),
              const SizedBox(height: 16),
              Obx(() {
                final ctrl = Get.find<FundsController>();
                return ElevatedButton(
                  onPressed: ctrl.isSubmitting.value
                      ? null
                      : () async {
                          if (!formKey.currentState!.validate()) return;
                          final success = await ctrl.contribute(
                            fundUlid: widget.fundUlid,
                            amount: amountCtrl.text.trim(),
                            note: noteCtrl.text.trim().isNotEmpty
                                ? noteCtrl.text.trim()
                                : null,
                          );
                          if (success) Navigator.pop(ctx, true);
                        },
                  style: ElevatedButton.styleFrom(
                    backgroundColor: AmyalColors.primary,
                    foregroundColor: Colors.white,
                    padding: const EdgeInsets.symmetric(vertical: 14),
                  ),
                  child: ctrl.isSubmitting.value
                      ? const SizedBox(
                          height: 20, width: 20,
                          child: CircularProgressIndicator(
                              color: Colors.white, strokeWidth: 2))
                      : const Text('تأكيد المساهمة',
                          style: TextStyle(fontSize: 15, fontWeight: FontWeight.w600)),
                );
              }),
              const SizedBox(height: 16),
            ],
          ),
        ),
      ),
    );

    if (ok == true && mounted) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
            content: Text('تمت المساهمة بنجاح'),
            backgroundColor: Color(0xFF2E7D32)),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmyalColors.background,
      appBar: AppBar(
        backgroundColor: AmyalColors.primary,
        foregroundColor: Colors.white,
        title: const Text('تفاصيل الصندوق'),
      ),
      body: Obx(() {
        final ctrl = Get.find<FundsController>();
        final fund = ctrl.selectedFund.value;
        if (ctrl.isLoading.value || fund == null) {
          return const Center(
              child: CircularProgressIndicator(color: AmyalColors.primary));
        }

        final canContribute = ['owner', 'admin', 'member'].contains(ctrl.selectedFundRole.value);

        return Column(
          children: [
            // Balance card
            Container(
              margin: const EdgeInsets.all(12),
              padding: const EdgeInsets.all(20),
              decoration: BoxDecoration(
                color: AmyalColors.yellow,
                borderRadius: BorderRadius.circular(12),
              ),
              child: Column(
                children: [
                  Text(fund.name,
                      style: const TextStyle(
                          color: AmyalColors.primary,
                          fontSize: 16,
                          fontWeight: FontWeight.bold)),
                  const SizedBox(height: 8),
                  const Text('الرصيد',
                      style: TextStyle(fontSize: 12, color: AmyalColors.primary)),
                  Text(
                    '${fund.balance} ر.س',
                    style: const TextStyle(
                      color: AmyalColors.primary,
                      fontSize: 28,
                      fontWeight: FontWeight.bold,
                    ),
                  ),
                  if (fund.description != null && fund.description!.isNotEmpty)
                    Padding(
                      padding: const EdgeInsets.only(top: 6),
                      child: Text(
                        fund.description!,
                        style: TextStyle(
                          fontSize: 12,
                          color: AmyalColors.primary.withValues(alpha: 0.7),
                        ),
                      ),
                    ),
                ],
              ),
            ),

            if (canContribute)
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: 12),
                child: ElevatedButton.icon(
                  onPressed: _openContributeSheet,
                  icon: const Icon(Icons.add_circle_outline),
                  label: const Text('مساهمة في الصندوق'),
                  style: ElevatedButton.styleFrom(
                    backgroundColor: AmyalColors.primary,
                    foregroundColor: Colors.white,
                    minimumSize: const Size(double.infinity, 48),
                  ),
                ),
              ),

            const SizedBox(height: 16),

            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 16),
              child: Row(
                children: const [
                  Icon(Icons.history, size: 18, color: AmyalColors.textSecondary),
                  SizedBox(width: 8),
                  Text('آخر الحركات',
                      style: TextStyle(
                          fontWeight: FontWeight.bold,
                          fontSize: 13,
                          color: AmyalColors.textSecondary)),
                ],
              ),
            ),

            Expanded(
              child: ctrl.selectedFundTransactions.isEmpty
                  ? const Center(
                      child: Text('لا توجد حركات بعد',
                          style: TextStyle(color: AmyalColors.textMuted)))
                  : ListView.separated(
                      padding: const EdgeInsets.all(12),
                      itemCount: ctrl.selectedFundTransactions.length,
                      separatorBuilder: (_, _) =>
                          const Divider(height: 1, color: AmyalColors.border),
                      itemBuilder: (context, i) =>
                          _TxTile(tx: ctrl.selectedFundTransactions[i]),
                    ),
            ),
          ],
        );
      }),
    );
  }
}

class _TxTile extends StatelessWidget {
  final AmyalFundTransaction tx;
  const _TxTile({required this.tx});

  @override
  Widget build(BuildContext context) {
    final isContribute = tx.txType == 'contribute';
    final color = isContribute ? Colors.green.shade700 : AmyalColors.red;

    return ListTile(
      tileColor: Colors.white,
      leading: CircleAvatar(
        backgroundColor: color.withValues(alpha: 0.15),
        child: Icon(isContribute ? Icons.arrow_downward : Icons.arrow_upward, color: color, size: 18),
      ),
      title: Text(
        isContribute ? 'مساهمة' :
          (tx.txType == 'disburse_to_member' ? 'صرف لعضو' : tx.txType),
        style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 13),
      ),
      subtitle: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          if (tx.note != null && tx.note!.isNotEmpty)
            Text(tx.note!, style: const TextStyle(fontSize: 12), maxLines: 1, overflow: TextOverflow.ellipsis),
          if (tx.isPending)
            const Padding(
              padding: EdgeInsets.only(top: 2),
              child: Text('بانتظار موافقة المالك', style: TextStyle(fontSize: 10, color: Colors.orange)),
            )
          else if (tx.isRejected)
            const Padding(
              padding: EdgeInsets.only(top: 2),
              child: Text('مرفوض', style: TextStyle(fontSize: 10, color: AmyalColors.red)),
            ),
        ],
      ),
      trailing: Text(
        '${isContribute ? '+' : '-'}${tx.amount}',
        style: TextStyle(color: color, fontWeight: FontWeight.bold, fontSize: 14),
      ),
    );
  }
}
