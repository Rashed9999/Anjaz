import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amial_pay/features/family_fund/controllers/funds_controller.dart';
import 'package:amial_pay/features/family_fund/domain/models/fund_models.dart';
import 'package:amial_pay/features/shared/widgets/amial_pin_gate.dart';
import 'package:amial_pay/helper/amial_money.dart';
import 'package:amial_pay/theme/amial_colors.dart';

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
                  labelText: 'المبلغ (ر.ي) *',
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
                          // AMIAL-PIN-GATE-001: رمز PIN قبل خصم المساهمة
                          if (!await askAmialPin(title: 'تأكيد المساهمة')) return;
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
                    backgroundColor: AmialColors.primary,
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

  /// AMIAL-FUND-003: نافذة دعوة عضو برقم هاتفه (مثل 777100002)
  Future<void> _openInviteDialog() async {
    final phoneCtrl = TextEditingController();
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('دعوة عضو للصندوق'),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Text('أدخل رقم جوال العضو المسجّل في أميال باي.',
                style: TextStyle(fontSize: 13, color: AmialColors.textSecondary)),
            const SizedBox(height: 12),
            TextField(
              controller: phoneCtrl,
              keyboardType: TextInputType.phone,
              decoration: const InputDecoration(
                labelText: 'رقم الجوال',
                hintText: '777xxxxxx',
                border: OutlineInputBorder(),
              ),
            ),
          ],
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx, false),
            child: const Text('إلغاء'),
          ),
          ElevatedButton(
            onPressed: () => Navigator.pop(ctx, true),
            style: ElevatedButton.styleFrom(
              backgroundColor: AmialColors.primary,
              foregroundColor: Colors.white,
            ),
            child: const Text('إرسال الدعوة'),
          ),
        ],
      ),
    );
    if (ok != true) return;
    final phone = phoneCtrl.text.trim();
    if (phone.length < 6) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
            content: Text('أدخل رقم جوال صحيحاً'),
            backgroundColor: AmialColors.red));
      }
      return;
    }
    final ctrl = Get.find<FundsController>();
    final success = await ctrl.inviteMember(
      fundUlid: widget.fundUlid,
      phone: phone,
    );
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
      content: Text(success
          ? 'أُرسلت الدعوة — سيظهر الصندوق لدى العضو ليقبلها'
          : ctrl.lastError.value),
      backgroundColor: success ? const Color(0xFF2E7D32) : AmialColors.red,
    ));
  }

  /// AMIAL-FUND-005: صرف مبلغ من الصندوق لعضو (اختيار العضو + المبلغ + بيان).
  Future<void> _openDisburseDialog() async {
    final ctrl = Get.find<FundsController>();
    final members = ctrl.selectedFundMembers
        .where((m) => m['user'] is Map && (m['user'] as Map)['id'] != null)
        .toList();
    if (members.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
          content: Text('لا يوجد أعضاء للصرف لهم'),
          backgroundColor: AmialColors.red));
      return;
    }
    int? beneficiaryId = (members.first['user'] as Map)['id'] as int?;
    final amountCtrl = TextEditingController();
    final noteCtrl = TextEditingController();
    final formKey = GlobalKey<FormState>();

    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('صرف من الصندوق'),
        content: Form(
          key: formKey,
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            DropdownButtonFormField<int>(
              initialValue: beneficiaryId,
              decoration: const InputDecoration(
                  labelText: 'المستفيد', border: OutlineInputBorder()),
              items: members.map((m) {
                final u = m['user'] as Map;
                final name =
                    ('${u['f_name'] ?? ''} ${u['l_name'] ?? ''}').trim();
                return DropdownMenuItem<int>(
                  value: u['id'] as int,
                  child: Text(name.isEmpty ? 'عضو' : name,
                      style: const TextStyle(fontSize: 14)),
                );
              }).toList(),
              onChanged: (v) => beneficiaryId = v,
            ),
            const SizedBox(height: 12),
            TextFormField(
              controller: amountCtrl,
              keyboardType:
                  const TextInputType.numberWithOptions(decimal: true),
              decoration: const InputDecoration(
                  labelText: 'المبلغ (ر.ي) *', border: OutlineInputBorder()),
              validator: (v) {
                final n = double.tryParse(v ?? '');
                if (n == null || n <= 0) return 'مبلغ غير صحيح';
                return null;
              },
            ),
            const SizedBox(height: 12),
            TextFormField(
              controller: noteCtrl,
              decoration: const InputDecoration(
                  labelText: 'بيان (اختياري)', border: OutlineInputBorder()),
            ),
          ]),
        ),
        actions: [
          TextButton(
              onPressed: () => Navigator.pop(ctx, false),
              child: const Text('إلغاء')),
          ElevatedButton(
            onPressed: () {
              if (!formKey.currentState!.validate()) return;
              Navigator.pop(ctx, true);
            },
            style: ElevatedButton.styleFrom(
                backgroundColor: AmialColors.primary,
                foregroundColor: Colors.white),
            child: const Text('صرف'),
          ),
        ],
      ),
    );
    if (ok != true || beneficiaryId == null) return;
    // AMIAL-PIN-GATE-001: رمز PIN قبل الصرف
    if (!await askAmialPin(title: 'تأكيد الصرف من الصندوق')) return;
    final success = await ctrl.proposeDisbursement(
      fundUlid: widget.fundUlid,
      beneficiaryUserId: beneficiaryId!,
      amount: amountCtrl.text.trim(),
      note: noteCtrl.text.trim().isNotEmpty ? noteCtrl.text.trim() : null,
    );
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
      content: Text(success
          ? 'تم الصرف من الصندوق بنجاح'
          : (ctrl.lastError.value.isEmpty ? 'فشل الصرف' : ctrl.lastError.value)),
      backgroundColor: success ? const Color(0xFF2E7D32) : AmialColors.red,
    ));
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(
        title: const Text('تفاصيل الصندوق'),
      ),
      body: Obx(() {
        final ctrl = Get.find<FundsController>();
        final fund = ctrl.selectedFund.value;
        if (ctrl.isLoading.value || fund == null) {
          return const Center(
              child: CircularProgressIndicator(color: AmialColors.primary));
        }

        final canContribute = ['owner', 'admin', 'member'].contains(ctrl.selectedFundRole.value);

        return Column(
          children: [
            // Balance card
            Container(
              margin: const EdgeInsets.all(12),
              padding: const EdgeInsets.all(20),
              decoration: BoxDecoration(
                color: AmialColors.yellow,
                borderRadius: BorderRadius.circular(12),
              ),
              child: Column(
                children: [
                  Text(fund.name,
                      style: const TextStyle(
                          color: AmialColors.primary,
                          fontSize: 16,
                          fontWeight: FontWeight.bold)),
                  const SizedBox(height: 8),
                  const Text('الرصيد',
                      style: TextStyle(fontSize: 12, color: AmialColors.primary)),
                  Text(
                    AmialMoney.yer(fund.balance),
                    style: const TextStyle(
                      color: AmialColors.primary,
                      fontSize: 28,
                      fontWeight: FontWeight.bold,
                    ),
                  ),

                  // AMIAL-FUND-002: شريط تقدّم نحو المبلغ المستهدف
                  if (fund.targetAmount != null &&
                      (double.tryParse(fund.targetAmount!) ?? 0) > 0) ...[
                    const SizedBox(height: 12),
                    Builder(builder: (_) {
                      final target = double.tryParse(fund.targetAmount!) ?? 1;
                      final current = double.tryParse(fund.balance) ?? 0;
                      final ratio = (current / target).clamp(0.0, 1.0);
                      return Column(children: [
                        ClipRRect(
                          borderRadius: BorderRadius.circular(8),
                          child: LinearProgressIndicator(
                            value: ratio,
                            minHeight: 10,
                            backgroundColor: Colors.white,
                            color: AmialColors.primary,
                          ),
                        ),
                        const SizedBox(height: 6),
                        Text(
                          'الهدف: ${AmialMoney.yer(fund.targetAmount!)} — ${(ratio * 100).toStringAsFixed(0)}%',
                          style: const TextStyle(
                              fontSize: 12,
                              color: AmialColors.primary,
                              fontWeight: FontWeight.w600),
                        ),
                      ]);
                    }),
                  ],
                  if (fund.description != null && fund.description!.isNotEmpty)
                    Padding(
                      padding: const EdgeInsets.only(top: 6),
                      child: Text(
                        fund.description!,
                        style: TextStyle(
                          fontSize: 12,
                          color: AmialColors.primary.withValues(alpha: 0.7),
                        ),
                      ),
                    ),
                ],
              ),
            ),

            if (canContribute)
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: 12),
                child: Row(children: [
                  Expanded(
                    flex: 2,
                    child: ElevatedButton.icon(
                      onPressed: _openContributeSheet,
                      icon: const Icon(Icons.add_circle_outline),
                      label: const Text('مساهمة'),
                      style: ElevatedButton.styleFrom(
                        backgroundColor: AmialColors.primary,
                        foregroundColor: Colors.white,
                        minimumSize: const Size(0, 48),
                      ),
                    ),
                  ),
                  // AMIAL-FUND-003: دعوة عضو (المالك/الأدمن)
                  if (['owner', 'admin'].contains(ctrl.selectedFundRole.value)) ...[
                    const SizedBox(width: 8),
                    Expanded(
                      child: OutlinedButton.icon(
                        onPressed: _openInviteDialog,
                        icon: const Icon(Icons.person_add_alt, size: 18),
                        label: const Text('دعوة'),
                        style: OutlinedButton.styleFrom(
                          foregroundColor: AmialColors.primary,
                          side: const BorderSide(color: AmialColors.primary),
                          minimumSize: const Size(0, 48),
                        ),
                      ),
                    ),
                    // AMIAL-FUND-005: صرف من الصندوق لعضو (كان في الخادم بلا زر)
                    const SizedBox(width: 8),
                    Expanded(
                      child: OutlinedButton.icon(
                        onPressed: _openDisburseDialog,
                        icon: const Icon(Icons.outbox_outlined, size: 18),
                        label: const Text('صرف'),
                        style: OutlinedButton.styleFrom(
                          foregroundColor: AmialColors.yellowDark,
                          side: const BorderSide(color: AmialColors.yellowDark),
                          minimumSize: const Size(0, 48),
                        ),
                      ),
                    ),
                  ],
                ]),
              ),

            // AMIAL-DESIGN-16: أفراد الصندوق + نسب المساهمة
            if (ctrl.selectedFundMembers.isNotEmpty) ...[
              const SizedBox(height: 16),
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: 16),
                child: Row(children: const [
                  Icon(Icons.groups_outlined, size: 18, color: AmialColors.textSecondary),
                  SizedBox(width: 8),
                  Text('أفراد الصندوق',
                      style: TextStyle(
                          fontWeight: FontWeight.bold,
                          fontSize: 13,
                          color: AmialColors.textSecondary)),
                ]),
              ),
              const SizedBox(height: 8),
              Builder(builder: (_) {
                final members = ctrl.selectedFundMembers;
                double total = 0;
                for (final m in members) {
                  total += double.tryParse('${m['total_contributed'] ?? 0}') ?? 0;
                }
                return Container(
                  margin: const EdgeInsets.symmetric(horizontal: 12),
                  padding: const EdgeInsets.all(12),
                  decoration: BoxDecoration(
                    color: Colors.white,
                    borderRadius: BorderRadius.circular(12),
                  ),
                  child: Column(
                    children: members.take(6).map((m) {
                      final u = m['user'] is Map ? m['user'] as Map : {};
                      final name = ('${u['f_name'] ?? ''} ${u['l_name'] ?? ''}').trim();
                      final contributed =
                          double.tryParse('${m['total_contributed'] ?? 0}') ?? 0;
                      final ratio = total > 0 ? (contributed / total) : 0.0;
                      final isOwner = '${m['role']}' == 'owner';
                      return Padding(
                        padding: const EdgeInsets.symmetric(vertical: 6),
                        child: Row(children: [
                          // AMIAL-FUND-004: إجمالي مساهمة العضو + نسبته
                          Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(AmialMoney.yer(contributed),
                                  style: const TextStyle(
                                      fontWeight: FontWeight.bold,
                                      fontSize: 12,
                                      color: AmialColors.primary)),
                              Text('${(ratio * 100).toStringAsFixed(0)}%',
                                  style: const TextStyle(
                                      fontWeight: FontWeight.bold,
                                      fontSize: 11,
                                      color: Color(0xFFB8860B))),
                            ],
                          ),
                          const SizedBox(width: 10),
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.stretch,
                              children: [
                                Row(
                                  mainAxisAlignment: MainAxisAlignment.end,
                                  children: [
                                    if (isOwner)
                                      Container(
                                        margin: const EdgeInsets.only(left: 6),
                                        padding: const EdgeInsets.symmetric(
                                            horizontal: 6, vertical: 1),
                                        decoration: BoxDecoration(
                                          color: AmialColors.primary
                                              .withValues(alpha: 0.1),
                                          borderRadius: BorderRadius.circular(6),
                                        ),
                                        child: const Text('مسؤول',
                                            style: TextStyle(
                                                fontSize: 9,
                                                color: AmialColors.primary)),
                                      ),
                                    Text(name.isEmpty ? 'عضو' : name,
                                        style: const TextStyle(
                                            fontSize: 13,
                                            fontWeight: FontWeight.w600)),
                                  ],
                                ),
                                const SizedBox(height: 4),
                                ClipRRect(
                                  borderRadius: BorderRadius.circular(6),
                                  child: LinearProgressIndicator(
                                    value: ratio.clamp(0.0, 1.0),
                                    minHeight: 6,
                                    backgroundColor: const Color(0xFFF0EFEA),
                                    color: isOwner
                                        ? AmialColors.primary
                                        : const Color(0xFFE6B84C),
                                  ),
                                ),
                              ],
                            ),
                          ),
                          const SizedBox(width: 10),
                          CircleAvatar(
                            radius: 16,
                            backgroundColor:
                                AmialColors.primary.withValues(alpha: 0.12),
                            child: Text(name.isNotEmpty ? name[0] : '؟',
                                style: const TextStyle(
                                    color: AmialColors.primary,
                                    fontWeight: FontWeight.bold,
                                    fontSize: 13)),
                          ),
                        ]),
                      );
                    }).toList(),
                  ),
                );
              }),
            ],

            const SizedBox(height: 16),

            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 16),
              child: Row(
                children: const [
                  Icon(Icons.history, size: 18, color: AmialColors.textSecondary),
                  SizedBox(width: 8),
                  Text('آخر الحركات',
                      style: TextStyle(
                          fontWeight: FontWeight.bold,
                          fontSize: 13,
                          color: AmialColors.textSecondary)),
                ],
              ),
            ),

            Expanded(
              child: ctrl.selectedFundTransactions.isEmpty
                  ? const Center(
                      child: Text('لا توجد حركات بعد',
                          style: TextStyle(color: AmialColors.textMuted)))
                  : ListView.separated(
                      padding: const EdgeInsets.all(12),
                      itemCount: ctrl.selectedFundTransactions.length,
                      separatorBuilder: (_, _) =>
                          const Divider(height: 1, color: AmialColors.border),
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
  final AmialFundTransaction tx;
  const _TxTile({required this.tx});

  @override
  Widget build(BuildContext context) {
    final isContribute = tx.txType == 'contribute';
    final color = isContribute ? Colors.green.shade700 : AmialColors.red;

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
          // AMIAL-FUND-UI: تفاصيل الصرف — بواسطة مَن وإلى مَن
          if (!isContribute && (tx.beneficiaryName ?? '').isNotEmpty)
            Text('إلى: ${tx.beneficiaryName}',
                style: const TextStyle(
                    fontSize: 12, fontWeight: FontWeight.w600)),
          if ((tx.actorName ?? '').isNotEmpty)
            Text('${isContribute ? 'ساهم' : 'صرفها'}: ${tx.actorName}',
                style: const TextStyle(
                    fontSize: 11, color: AmialColors.textMuted)),
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
              child: Text('مرفوض', style: TextStyle(fontSize: 10, color: AmialColors.red)),
            ),
        ],
      ),
      trailing: Text(
        // AMIAL-MONEY-001: "49000.0000" → "+49,000 ر.ي"
        '${isContribute ? '+' : '-'}${AmialMoney.yer(tx.amount)}',
        style: TextStyle(color: color, fontWeight: FontWeight.bold, fontSize: 14),
      ),
    );
  }
}
