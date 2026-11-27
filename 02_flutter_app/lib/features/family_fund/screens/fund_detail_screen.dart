import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:get/get.dart';
import 'package:amial_pay/features/family_fund/controllers/funds_controller.dart';
import 'package:amial_pay/features/family_fund/domain/models/fund_models.dart';
import 'package:amial_pay/features/notification/controllers/notifications_center_controller.dart';
import 'package:amial_pay/features/shared/widgets/amial_pin_gate.dart';
import 'package:amial_pay/helper/amial_money.dart';
import 'package:amial_pay/helper/arabic_digits_input_formatter.dart';
import 'package:amial_pay/helper/payment_feedback.dart';
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

  String _roleLabel(String role) {
    switch (role) {
      case 'owner':
        return 'المالك';
      case 'admin':
        return 'مشرف';
      case 'member':
        return 'عضو';
      case 'viewer':
        return 'مشاهد';
      default:
        return role;
    }
  }

  Future<void> _openContributeSheet() async {
    final amountCtrl = TextEditingController();
    final noteCtrl = TextEditingController();
    final formKey = GlobalKey<FormState>();

    try {
      final ok = await showModalBottomSheet<bool>(
        context: context,
        isScrollControlled: true,
        backgroundColor: Colors.white,
        shape: const RoundedRectangleBorder(
            borderRadius: BorderRadius.vertical(top: Radius.circular(16))),
        builder: (ctx) => Padding(
          padding: EdgeInsets.only(
            bottom: MediaQuery.of(ctx).viewInsets.bottom,
            left: 16,
            right: 16,
            top: 16,
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
                  inputFormatters: [
                    const ArabicDigitsNormalizingFormatter(),
                    FilteringTextInputFormatter.allow(RegExp(r'^\d*\.?\d{0,2}')),
                  ],
                  decoration: const InputDecoration(
                    labelText: 'المبلغ (ر.ي) *',
                    border: OutlineInputBorder(),
                  ),
                  validator: (v) {
                    final n = double.tryParse((v ?? '').trim());
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
                const SizedBox(height: 12),
                Obx(() {
                  final ctrl = Get.find<FundsController>();
                  return Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      if (ctrl.lastError.value.isNotEmpty) ...[
                        Container(
                          padding: const EdgeInsets.all(10),
                          margin: const EdgeInsets.only(bottom: 10),
                          decoration: BoxDecoration(
                            color: AmialColors.red.withValues(alpha: 0.1),
                            borderRadius: BorderRadius.circular(8),
                          ),
                          child: Text(
                            ctrl.lastError.value,
                            style: const TextStyle(
                                color: AmialColors.red, fontSize: 12),
                          ),
                        ),
                      ],
                      ElevatedButton(
                        onPressed: ctrl.isSubmitting.value
                            ? null
                            : () async {
                                if (!formKey.currentState!.validate()) return;
                                // AMIAL-FUND-PIN-BOUND-001: رمز PIN يُرسَل مع المساهمة للخادم
                                final pin = await askAmialPinInput(title: 'تأكيد المساهمة');
                                if (pin == null || pin.length < 4) {
                                  return;
                                }
                                final success = await ctrl.contribute(
                                  fundUlid: widget.fundUlid,
                                  amount: amountCtrl.text.trim(),
                                  pin: pin,
                                  note: noteCtrl.text.trim().isNotEmpty
                                      ? noteCtrl.text.trim()
                                      : null,
                                );
                                if (!ctx.mounted) return;
                                if (success) {
                                  Navigator.pop(ctx, true);
                                }
                              },
                        style: ElevatedButton.styleFrom(
                          backgroundColor: AmialColors.primary,
                          foregroundColor: Colors.white,
                          padding: const EdgeInsets.symmetric(vertical: 14),
                        ),
                        child: ctrl.isSubmitting.value
                            ? const SizedBox(
                                height: 20,
                                width: 20,
                                child: CircularProgressIndicator(
                                    color: Colors.white, strokeWidth: 2))
                            : const Text('تأكيد المساهمة',
                                style: TextStyle(
                                    fontSize: 15, fontWeight: FontWeight.w600)),
                      ),
                    ],
                  );
                }),
                const SizedBox(height: 16),
              ],
            ),
          ),
        ),
      );

      if (ok == true && mounted) {
        unawaited(
          PaymentFeedback.success(speech: 'notif_spoken_fund_contribute_ok'.tr),
        );
        try {
          if (Get.isRegistered<NotificationsCenterController>()) {
            unawaited(
              Get.find<NotificationsCenterController>().refreshUnreadCount(),
            );
          }
        } catch (_) {}
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
              content: Text('تمت المساهمة بنجاح'),
              backgroundColor: AmialColors.success),
        );
      }
    } finally {
      amountCtrl.dispose();
      noteCtrl.dispose();
    }
  }

  /// AMIAL-FUND-003: نافذة دعوة عضو برقم هاتفه مع اختيار الدور
  Future<void> _openInviteDialog() async {
    final phoneCtrl = TextEditingController();
    String selectedRole = 'member';

    try {
      final ok = await showDialog<bool>(
        context: context,
        builder: (ctx) => StatefulBuilder(
          builder: (ctx, setDialogState) => AlertDialog(
            title: const Text('دعوة عضو للصندوق'),
            content: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                const Text('أدخل رقم جوال العضو المسجّل في أميال باي واختر دوره.',
                    style: TextStyle(
                        fontSize: 13, color: AmialColors.textSecondary)),
                const SizedBox(height: 12),
                TextField(
                  controller: phoneCtrl,
                  keyboardType: TextInputType.phone,
                  inputFormatters: const [ArabicDigitsNormalizingFormatter()],
                  decoration: const InputDecoration(
                    labelText: 'رقم الجوال',
                    hintText: '777xxxxxx',
                    border: OutlineInputBorder(),
                  ),
                ),
                const SizedBox(height: 12),
                DropdownButtonFormField<String>(
                  initialValue: selectedRole,
                  decoration: const InputDecoration(
                    labelText: 'دور العضو',
                    border: OutlineInputBorder(),
                  ),
                  items: const [
                    DropdownMenuItem(value: 'member', child: Text('عضو')),
                    DropdownMenuItem(value: 'admin', child: Text('مشرف')),
                    DropdownMenuItem(value: 'viewer', child: Text('مشاهد')),
                  ],
                  onChanged: (v) {
                    if (v != null) {
                      setDialogState(() => selectedRole = v);
                    }
                  },
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
        role: selectedRole,
      );
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
        content: Text(success
            ? 'أُرسلت الدعوة — سيظهر الصندوق لدى العضو ليقبلها'
            : (ctrl.lastError.value.isNotEmpty
                ? ctrl.lastError.value
                : 'تعذّر إرسال الدعوة')),
        backgroundColor: success ? AmialColors.success : AmialColors.red,
      ));
    } finally {
      phoneCtrl.dispose();
    }
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

    try {
      final isOwner = ctrl.selectedFundRole.value == 'owner';
      final requiresApproval =
          (ctrl.selectedFund.value?.requireOwnerApprovalForDisbursement ?? true) &&
              !isOwner;

      final ok = await showDialog<bool>(
        context: context,
        builder: (ctx) => AlertDialog(
          title: Text(requiresApproval ? 'اقتراح صرف من الصندوق' : 'صرف من الصندوق'),
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
                inputFormatters: [
                  const ArabicDigitsNormalizingFormatter(),
                  FilteringTextInputFormatter.allow(RegExp(r'^\d*\.?\d{0,2}')),
                ],
                decoration: const InputDecoration(
                    labelText: 'المبلغ (ر.ي) *', border: OutlineInputBorder()),
                validator: (v) {
                  final n = double.tryParse((v ?? '').trim());
                  if (n == null || n <= 0) return 'مبلغ غير صحيح';
                  return null;
                },
              ),
              const SizedBox(height: 12),
              TextFormField(
                controller: noteCtrl,
                maxLength: 500,
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
              child: Text(requiresApproval ? 'إرسال الطلب' : 'صرف'),
            ),
          ],
        ),
      );
      if (ok != true || beneficiaryId == null) return;
      // AMIAL-FUND-PIN-BOUND-001: رمز PIN يُرسَل مع الصرف للخادم
      final pin = await askAmialPinInput(title: 'تأكيد الصرف من الصندوق');
      if (pin == null || pin.length < 4) return;
      final status = await ctrl.proposeDisbursement(
        fundUlid: widget.fundUlid,
        beneficiaryUserId: beneficiaryId!,
        amount: amountCtrl.text.trim(),
        pin: pin,
        note: noteCtrl.text.trim().isNotEmpty ? noteCtrl.text.trim() : null,
      );
      if (!mounted) return;
      if (status == 'completed') {
        ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
          content: Text('تم الصرف من الصندوق بنجاح'),
          backgroundColor: AmialColors.success,
        ));
      } else if (status == 'pending_approval') {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(
          content: const Text('تم إرسال طلب الصرف وهو بانتظار موافقة المالك'),
          backgroundColor: Colors.orange.shade800,
        ));
      } else {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(
          content: Text(
              ctrl.lastError.value.isEmpty ? 'فشل الصرف' : ctrl.lastError.value),
          backgroundColor: AmialColors.red,
        ));
      }
    } finally {
      amountCtrl.dispose();
      noteCtrl.dispose();
    }
  }

  Future<void> _approvePending(AmialFundTransaction tx) async {
    final pin = await askAmialPinInput(title: 'تأكيد اعتماد الصرف');
    if (pin == null || pin.length < 4) return;
    final ctrl = Get.find<FundsController>();
    final ok = await ctrl.approveDisbursement(widget.fundUlid, tx.txUlid, pin);
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
      content: Text(ok
          ? 'تم اعتماد طلب الصرف وتحويل المبلغ للمستفيد'
          : (ctrl.lastError.value.isNotEmpty
              ? ctrl.lastError.value
              : 'تعذّر اعتماد الصرف')),
      backgroundColor: ok ? AmialColors.success : AmialColors.red,
    ));
  }

  Future<void> _openRejectDialog(AmialFundTransaction tx) async {
    final reasonCtrl = TextEditingController();
    final formKey = GlobalKey<FormState>();

    try {
      final confirmed = await showDialog<bool>(
        context: context,
        builder: (ctx) => AlertDialog(
          title: const Text('رفض طلب الصرف'),
          content: Form(
            key: formKey,
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  'المبلغ: ${AmialMoney.yer(tx.amount)}',
                  style: const TextStyle(
                      fontWeight: FontWeight.w600, fontSize: 13),
                ),
                const SizedBox(height: 12),
                TextFormField(
                  controller: reasonCtrl,
                  maxLines: 3,
                  maxLength: 500,
                  decoration: const InputDecoration(
                    labelText: 'سبب الرفض *',
                    hintText: 'اذكر سبب الرفض (5 أحرف على الأقل)',
                    border: OutlineInputBorder(),
                  ),
                  validator: (v) {
                    if (v == null || v.trim().length < 5) {
                      return 'يجب أن لا يقل سبب الرفض عن 5 أحرف';
                    }
                    return null;
                  },
                ),
              ],
            ),
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(ctx, false),
              child: const Text('إلغاء'),
            ),
            ElevatedButton(
              onPressed: () {
                if (!formKey.currentState!.validate()) return;
                Navigator.pop(ctx, true);
              },
              style: ElevatedButton.styleFrom(
                backgroundColor: AmialColors.red,
                foregroundColor: Colors.white,
              ),
              child: const Text('تأكيد الرفض'),
            ),
          ],
        ),
      );
      if (confirmed != true) return;
      final ctrl = Get.find<FundsController>();
      final ok = await ctrl.rejectDisbursement(
        widget.fundUlid,
        tx.txUlid,
        reasonCtrl.text.trim(),
      );
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
        content: Text(ok
            ? 'تم رفض طلب الصرف'
            : (ctrl.lastError.value.isNotEmpty
                ? ctrl.lastError.value
                : 'تعذّر رفض الطلب')),
        backgroundColor: ok ? Colors.orange.shade800 : AmialColors.red,
      ));
    } finally {
      reasonCtrl.dispose();
    }
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

        if (ctrl.isLoading.value && fund == null) {
          return const Center(
              child: CircularProgressIndicator(color: AmialColors.primary));
        }

        if (fund == null) {
          return Center(
            child: Padding(
              padding: const EdgeInsets.all(24),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  const Icon(Icons.error_outline,
                      size: 56, color: AmialColors.red),
                  const SizedBox(height: 12),
                  Text(
                    ctrl.lastError.value.isNotEmpty
                        ? ctrl.lastError.value
                        : 'تعذّر تحميل تفاصيل الصندوق',
                    textAlign: TextAlign.center,
                    style: const TextStyle(fontSize: 14),
                  ),
                  const SizedBox(height: 16),
                  ElevatedButton.icon(
                    onPressed: () => ctrl.loadFundDetails(widget.fundUlid),
                    icon: const Icon(Icons.refresh),
                    label: const Text('إعادة المحاولة'),
                    style: ElevatedButton.styleFrom(
                      backgroundColor: AmialColors.primary,
                      foregroundColor: Colors.white,
                    ),
                  ),
                ],
              ),
            ),
          );
        }

        final role = ctrl.selectedFundRole.value;
        final isOwner = role == 'owner';
        final canContribute = ['owner', 'admin', 'member'].contains(role);
        final canInvite = ['owner', 'admin'].contains(role);
        final canDisburse = ['owner', 'admin', 'member'].contains(role);
        final pendingDisbursements =
            ctrl.selectedFundTransactions.where((t) => t.isPending).toList();

        return RefreshIndicator(
          color: AmialColors.primary,
          onRefresh: () => ctrl.loadFundDetails(widget.fundUlid),
          child: CustomScrollView(
            physics: const AlwaysScrollableScrollPhysics(),
            slivers: [
              // 1) بطاقة الرصيد والهدف
              SliverToBoxAdapter(
                child: Container(
                  margin: const EdgeInsets.all(12),
                  padding: const EdgeInsets.all(20),
                  decoration: BoxDecoration(
                    color: AmialColors.yellow,
                    borderRadius: BorderRadius.circular(12),
                  ),
                  child: Column(
                    children: [
                      Row(
                        mainAxisAlignment: MainAxisAlignment.center,
                        children: [
                          Flexible(
                            child: Text(fund.name,
                                textAlign: TextAlign.center,
                                style: const TextStyle(
                                    color: AmialColors.primary,
                                    fontSize: 16,
                                    fontWeight: FontWeight.bold)),
                          ),
                          if (!fund.isActive) ...[
                            const SizedBox(width: 8),
                            Container(
                              padding: const EdgeInsets.symmetric(
                                  horizontal: 8, vertical: 2),
                              decoration: BoxDecoration(
                                color: AmialColors.red,
                                borderRadius: BorderRadius.circular(6),
                              ),
                              child: Text(
                                fund.status == 'frozen' ? 'مجمّد' : 'مؤرشف',
                                style: const TextStyle(
                                    color: Colors.white, fontSize: 11),
                              ),
                            ),
                          ],
                        ],
                      ),
                      const SizedBox(height: 8),
                      const Text('الرصيد',
                          style: TextStyle(
                              fontSize: 12, color: AmialColors.primary)),
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
                      if (fund.description != null &&
                          fund.description!.isNotEmpty)
                        Padding(
                          padding: const EdgeInsets.only(top: 6),
                          child: Text(
                            fund.description!,
                            textAlign: TextAlign.center,
                            style: TextStyle(
                              fontSize: 12,
                              color: AmialColors.primary.withValues(alpha: 0.7),
                            ),
                          ),
                        ),
                    ],
                  ),
                ),
              ),

              // 2) أزرار الإجراءات
              if (fund.isActive && canContribute)
                SliverToBoxAdapter(
                  child: Padding(
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
                      if (canInvite) ...[
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
                      ],
                      if (canDisburse) ...[
                        const SizedBox(width: 8),
                        Expanded(
                          child: OutlinedButton.icon(
                            onPressed: _openDisburseDialog,
                            icon: const Icon(Icons.outbox_outlined, size: 18),
                            label: Text(isOwner ? 'صرف' : 'طلب صرف'),
                            style: OutlinedButton.styleFrom(
                              foregroundColor: AmialColors.yellowDark,
                              side: const BorderSide(
                                  color: AmialColors.yellowDark),
                              minimumSize: const Size(0, 48),
                            ),
                          ),
                        ),
                      ],
                    ]),
                  ),
                ),

              // 3) قسم طلبات صرف بانتظار موافقة المالك
              if (isOwner && pendingDisbursements.isNotEmpty)
                SliverToBoxAdapter(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      const SizedBox(height: 16),
                      Padding(
                        padding: const EdgeInsets.symmetric(horizontal: 16),
                        child: Row(children: [
                          Icon(Icons.pending_actions,
                              size: 18, color: Colors.orange.shade800),
                          const SizedBox(width: 8),
                          Text(
                            'طلبات صرف بانتظار موافقتك (${pendingDisbursements.length})',
                            style: TextStyle(
                                fontWeight: FontWeight.bold,
                                fontSize: 13,
                                color: Colors.orange.shade800),
                          ),
                        ]),
                      ),
                      const SizedBox(height: 8),
                      ...pendingDisbursements.map((tx) => Card(
                            margin: const EdgeInsets.symmetric(
                                horizontal: 12, vertical: 4),
                            color: Colors.orange.shade50,
                            shape: RoundedRectangleBorder(
                              borderRadius: BorderRadius.circular(12),
                              side: BorderSide(color: Colors.orange.shade200),
                            ),
                            child: Padding(
                              padding: const EdgeInsets.all(12),
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  Row(
                                    mainAxisAlignment:
                                        MainAxisAlignment.spaceBetween,
                                    children: [
                                      Expanded(
                                        child: Text(
                                          'إلى: ${(tx.beneficiaryName ?? '').isNotEmpty ? tx.beneficiaryName : 'عضو'}',
                                          style: const TextStyle(
                                              fontWeight: FontWeight.bold,
                                              fontSize: 14),
                                        ),
                                      ),
                                      Text(
                                        AmialMoney.yer(tx.amount),
                                        style: const TextStyle(
                                            color: AmialColors.red,
                                            fontWeight: FontWeight.bold,
                                            fontSize: 15),
                                      ),
                                    ],
                                  ),
                                  if ((tx.actorName ?? '').isNotEmpty)
                                    Padding(
                                      padding: const EdgeInsets.only(top: 4),
                                      child: Text(
                                        'مقدّم الطلب: ${tx.actorName}',
                                        style: const TextStyle(
                                            fontSize: 12,
                                            color: AmialColors.textSecondary),
                                      ),
                                    ),
                                  if (tx.note != null && tx.note!.isNotEmpty)
                                    Padding(
                                      padding: const EdgeInsets.only(top: 4),
                                      child: Text(
                                        'البيان: ${tx.note!}',
                                        style: const TextStyle(fontSize: 12),
                                      ),
                                    ),
                                  const SizedBox(height: 10),
                                  Row(
                                    children: [
                                      Expanded(
                                        child: ElevatedButton.icon(
                                          onPressed: ctrl.isSubmitting.value
                                              ? null
                                              : () => _approvePending(tx),
                                          icon: const Icon(Icons.check, size: 18),
                                          label: const Text('اعتماد'),
                                          style: ElevatedButton.styleFrom(
                                            backgroundColor: AmialColors.success,
                                            foregroundColor: Colors.white,
                                          ),
                                        ),
                                      ),
                                      const SizedBox(width: 8),
                                      Expanded(
                                        child: OutlinedButton.icon(
                                          onPressed: ctrl.isSubmitting.value
                                              ? null
                                              : () => _openRejectDialog(tx),
                                          icon: const Icon(Icons.close, size: 18),
                                          label: const Text('رفض'),
                                          style: OutlinedButton.styleFrom(
                                            foregroundColor: AmialColors.red,
                                            side: const BorderSide(
                                                color: AmialColors.red),
                                          ),
                                        ),
                                      ),
                                    ],
                                  ),
                                ],
                              ),
                            ),
                          )),
                    ],
                  ),
                ),

              // 4) أفراد الصندوق + نسب المساهمة (كافة الأعضاء دون اقتطاع)
              if (ctrl.selectedFundMembers.isNotEmpty)
                SliverToBoxAdapter(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      const SizedBox(height: 16),
                      Padding(
                        padding: const EdgeInsets.symmetric(horizontal: 16),
                        child: Row(children: [
                          const Icon(Icons.groups_outlined,
                              size: 18, color: AmialColors.textSecondary),
                          const SizedBox(width: 8),
                          Text(
                            'أفراد الصندوق (${ctrl.selectedFundMembers.length})',
                            style: const TextStyle(
                                fontWeight: FontWeight.bold,
                                fontSize: 13,
                                color: AmialColors.textSecondary),
                          ),
                        ]),
                      ),
                      const SizedBox(height: 8),
                      Builder(builder: (_) {
                        final members = ctrl.selectedFundMembers;
                        double total = 0;
                        for (final m in members) {
                          total += double.tryParse(
                                  '${m['total_contributed'] ?? 0}') ??
                              0;
                        }
                        return Container(
                          margin: const EdgeInsets.symmetric(horizontal: 12),
                          padding: const EdgeInsets.all(12),
                          decoration: BoxDecoration(
                            color: Colors.white,
                            borderRadius: BorderRadius.circular(12),
                          ),
                          child: Column(
                            children: members.map((m) {
                              final u = m['user'] is Map ? m['user'] as Map : {};
                              final name =
                                  ('${u['f_name'] ?? ''} ${u['l_name'] ?? ''}')
                                      .trim();
                              final contributed = double.tryParse(
                                      '${m['total_contributed'] ?? 0}') ??
                                  0;
                              final ratio =
                                  total > 0 ? (contributed / total) : 0.0;
                              final memberRole = '${m['role'] ?? 'member'}';
                              final isMemberOwner = memberRole == 'owner';
                              return Padding(
                                padding:
                                    const EdgeInsets.symmetric(vertical: 6),
                                child: Row(children: [
                                  Column(
                                    crossAxisAlignment:
                                        CrossAxisAlignment.start,
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
                                      crossAxisAlignment:
                                          CrossAxisAlignment.stretch,
                                      children: [
                                        Row(
                                          mainAxisAlignment:
                                              MainAxisAlignment.end,
                                          children: [
                                            Container(
                                              margin: const EdgeInsets.only(
                                                  left: 6),
                                              padding:
                                                  const EdgeInsets.symmetric(
                                                      horizontal: 6,
                                                      vertical: 1),
                                              decoration: BoxDecoration(
                                                color: (isMemberOwner
                                                        ? AmialColors.primary
                                                        : AmialColors
                                                            .textSecondary)
                                                    .withValues(alpha: 0.1),
                                                borderRadius:
                                                    BorderRadius.circular(6),
                                              ),
                                              child: Text(
                                                  _roleLabel(memberRole),
                                                  style: TextStyle(
                                                      fontSize: 9,
                                                      color: isMemberOwner
                                                          ? AmialColors.primary
                                                          : AmialColors
                                                              .textSecondary)),
                                            ),
                                            Flexible(
                                              child: Text(
                                                  name.isEmpty ? 'عضو' : name,
                                                  overflow:
                                                      TextOverflow.ellipsis,
                                                  style: const TextStyle(
                                                      fontSize: 13,
                                                      fontWeight:
                                                          FontWeight.w600)),
                                            ),
                                          ],
                                        ),
                                        const SizedBox(height: 4),
                                        ClipRRect(
                                          borderRadius:
                                              BorderRadius.circular(6),
                                          child: LinearProgressIndicator(
                                            value: ratio.clamp(0.0, 1.0),
                                            minHeight: 6,
                                            backgroundColor:
                                                const Color(0xFFF0EFEA),
                                            color: isMemberOwner
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
                                    backgroundColor: AmialColors.primary
                                        .withValues(alpha: 0.12),
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
                  ),
                ),

              // 5) عنوان آخر الحركات
              const SliverToBoxAdapter(
                child: Padding(
                  padding: EdgeInsets.fromLTRB(16, 16, 16, 8),
                  child: Row(
                    children: [
                      Icon(Icons.history,
                          size: 18, color: AmialColors.textSecondary),
                      SizedBox(width: 8),
                      Text('آخر الحركات',
                          style: TextStyle(
                              fontWeight: FontWeight.bold,
                              fontSize: 13,
                              color: AmialColors.textSecondary)),
                    ],
                  ),
                ),
              ),

              // 6) قائمة الحركات
              if (ctrl.selectedFundTransactions.isEmpty)
                const SliverToBoxAdapter(
                  child: Padding(
                    padding: EdgeInsets.symmetric(vertical: 32),
                    child: Center(
                      child: Text('لا توجد حركات بعد',
                          style: TextStyle(color: AmialColors.textMuted)),
                    ),
                  ),
                )
              else
                SliverPadding(
                  padding: const EdgeInsets.symmetric(horizontal: 12),
                  sliver: SliverList(
                    delegate: SliverChildBuilderDelegate(
                      (context, index) {
                        final tx = ctrl.selectedFundTransactions[index];
                        return Column(
                          children: [
                            _TxTile(tx: tx),
                            if (index <
                                ctrl.selectedFundTransactions.length - 1)
                              const Divider(
                                  height: 1, color: AmialColors.border),
                          ],
                        );
                      },
                      childCount: ctrl.selectedFundTransactions.length,
                    ),
                  ),
                ),

              // 7) زر تحميل المزيد من الحركات
              if (ctrl.hasMoreTransactions.value)
                SliverToBoxAdapter(
                  child: Padding(
                    padding: const EdgeInsets.symmetric(
                        horizontal: 12, vertical: 12),
                    child: OutlinedButton(
                      onPressed: ctrl.isLoadingMoreTx.value
                          ? null
                          : () => ctrl.loadMoreTransactions(widget.fundUlid),
                      style: OutlinedButton.styleFrom(
                        foregroundColor: AmialColors.primary,
                        side: const BorderSide(color: AmialColors.primary),
                      ),
                      child: ctrl.isLoadingMoreTx.value
                          ? const SizedBox(
                              height: 18,
                              width: 18,
                              child: CircularProgressIndicator(
                                  strokeWidth: 2, color: AmialColors.primary),
                            )
                          : const Text('تحميل المزيد من الحركات'),
                    ),
                  ),
                ),

              const SliverToBoxAdapter(child: SizedBox(height: 24)),
            ],
          ),
        );
      }),
    );
  }
}

class _TxTile extends StatelessWidget {
  final AmialFundTransaction tx;
  const _TxTile({required this.tx});

  String _txTypeTitle(String type) {
    switch (type) {
      case 'contribute':
        return 'مساهمة';
      case 'disburse_to_member':
        return 'صرف لعضو';
      case 'disburse_to_external':
        return 'صرف خارجي';
      case 'disbursement':
        return 'صرف';
      case 'adjustment':
        return 'تسوية رصيد';
      default:
        return type;
    }
  }

  @override
  Widget build(BuildContext context) {
    final isContribute = tx.txType == 'contribute';
    final isAdjustment = tx.txType == 'adjustment';
    final color = isAdjustment
        ? AmialColors.textSecondary
        : (isContribute ? Colors.green.shade700 : AmialColors.red);
    final icon = isAdjustment
        ? Icons.tune
        : (isContribute ? Icons.arrow_downward : Icons.arrow_upward);
    final sign = isAdjustment ? '' : (isContribute ? '+' : '-');

    return ListTile(
      tileColor: Colors.white,
      leading: CircleAvatar(
        backgroundColor: color.withValues(alpha: 0.15),
        child: Icon(icon, color: color, size: 18),
      ),
      title: Text(
        _txTypeTitle(tx.txType),
        style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 13),
      ),
      subtitle: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          // AMIAL-FUND-UI: تفاصيل الصرف — بواسطة مَن وإلى مَن
          if (!isContribute &&
              !isAdjustment &&
              (tx.beneficiaryName ?? '').isNotEmpty)
            Text('إلى: ${tx.beneficiaryName}',
                style: const TextStyle(
                    fontSize: 12, fontWeight: FontWeight.w600)),
          if ((tx.actorName ?? '').isNotEmpty)
            Text(
                '${isContribute ? 'ساهم' : (isAdjustment ? 'بواسطة' : 'صرفها')}: ${tx.actorName}',
                style: const TextStyle(
                    fontSize: 11, color: AmialColors.textMuted)),
          if (tx.note != null && tx.note!.isNotEmpty)
            Text(tx.note!,
                style: const TextStyle(fontSize: 12),
                maxLines: 2,
                overflow: TextOverflow.ellipsis),
          if (tx.isPending)
            const Padding(
              padding: EdgeInsets.only(top: 2),
              child: Text('بانتظار موافقة المالك',
                  style: TextStyle(fontSize: 10, color: Colors.orange)),
            )
          else if (tx.isRejected)
            const Padding(
              padding: EdgeInsets.only(top: 2),
              child: Text('مرفوض',
                  style: TextStyle(fontSize: 10, color: AmialColors.red)),
            ),
        ],
      ),
      trailing: Text(
        // AMIAL-MONEY-001: "49000.0000" → "+49,000 ر.ي"
        '$sign${AmialMoney.yer(tx.amount)}',
        style:
            TextStyle(color: color, fontWeight: FontWeight.bold, fontSize: 14),
      ),
    );
  }
}
