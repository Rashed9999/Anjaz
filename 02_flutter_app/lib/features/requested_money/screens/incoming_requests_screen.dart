import 'package:amial_pay/common/widgets/amial_ltr_number.dart';
import 'package:amial_pay/features/payments/widgets/pin_prompt.dart';
import 'package:amial_pay/features/requested_money/controllers/payment_request_controller.dart';
import 'package:amial_pay/helper/amial_money.dart';
import 'package:amial_pay/theme/amial_colors.dart';
import 'package:flutter/material.dart';
import 'package:get/get.dart';

/// الطلبات التي وصلت إلى العميل: يراجع الطرف والمبلغ ثم يدفع أو يرفض.
class IncomingRequestsScreen extends StatefulWidget {
  const IncomingRequestsScreen({super.key});

  @override
  State<IncomingRequestsScreen> createState() => _IncomingRequestsScreenState();
}

class _IncomingRequestsScreenState extends State<IncomingRequestsScreen> {
  PaymentRequestController get c => Get.find<PaymentRequestController>();

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _reload());
  }

  Future<void> _reload() => c.loadList('incoming', status: 'pending');

  @override
  Widget build(BuildContext context) => Scaffold(
        backgroundColor: AmialColors.background,
        appBar: AppBar(title: const Text('طلبات المال الواردة')),
        body: Obx(() {
          if (c.isLoading.value && c.incoming.isEmpty) {
            return const Center(child: CircularProgressIndicator());
          }
          if (c.lastError.value.isNotEmpty && c.incoming.isEmpty) {
            return _error(c.lastError.value);
          }
          if (c.incoming.isEmpty) return _empty();

          return RefreshIndicator(
            onRefresh: _reload,
            child: ListView.separated(
              padding: const EdgeInsets.all(16),
              itemCount: c.incoming.length,
              separatorBuilder: (_, __) => const SizedBox(height: 10),
              itemBuilder: (_, i) => _card(c.incoming[i]),
            ),
          );
        }),
      );

  Widget _error(String message) => Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            const Icon(Icons.cloud_off_outlined, size: 52, color: AmialColors.red),
            const SizedBox(height: 12),
            Text(message, textAlign: TextAlign.center),
            const SizedBox(height: 12),
            FilledButton.icon(
              onPressed: _reload,
              icon: const Icon(Icons.refresh),
              label: const Text('إعادة المحاولة'),
            ),
          ]),
        ),
      );

  Widget _empty() => ListView(
        physics: const AlwaysScrollableScrollPhysics(),
        children: [
          const SizedBox(height: 120),
          Icon(Icons.inbox_outlined, size: 64, color: Colors.grey.shade400),
          const SizedBox(height: 12),
          const Text('لا طلبات واردة',
              textAlign: TextAlign.center,
              style: TextStyle(fontSize: 16, fontWeight: FontWeight.w600)),
          const SizedBox(height: 6),
          Text('حين يطلب منك عميل مالاً يظهر هنا',
              textAlign: TextAlign.center,
              style: TextStyle(fontSize: 12.5, color: Colors.grey.shade600)),
        ],
      );

  Widget _card(Map<String, dynamic> request) {
    final id = request['id'] as int?;
    final amount = '${request['amount'] ?? '0'}';
    final from = (request['requester_label'] ??
            request['requester_name'] ??
            request['requester_phone'] ??
            'مستخدم أميال')
        .toString();
    final note = (request['note'] ?? '').toString();
    final quoteAvailable =
        !request.containsKey('total_due') || request['total_due'] != null;
    final fee = '${request['fee'] ?? '0'}';
    final total = '${request['total_due'] ?? amount}';
    final recipientCredit = '${request['recipient_credit'] ?? amount}';
    final receiverPaysFee = request['fee_bearer'] == 'receiver';
    final hasFee = (double.tryParse(fee) ?? 0) > 0;

    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: AmialColors.border),
      ),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Row(children: [
          Container(
            width: 38,
            height: 38,
            decoration: BoxDecoration(
              color: AmialColors.primary.withValues(alpha: 0.09),
              shape: BoxShape.circle,
            ),
            child: const Icon(Icons.call_received_rounded,
                color: AmialColors.primary, size: 20),
          ),
          const SizedBox(width: 9),
          Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
              Text(from,
                  textAlign: TextAlign.right,
                  style: const TextStyle(fontSize: 14, fontWeight: FontWeight.bold)),
              const Text('يطلب منك مالاً',
                  textAlign: TextAlign.right,
                  style: TextStyle(fontSize: 11.5, color: AmialColors.textSecondary)),
            ]),
          ),
        ]),
        const SizedBox(height: 12),
        AmialLtrNumber('$amount ر.ي',
            style: const TextStyle(
                fontSize: 25,
                fontWeight: FontWeight.bold,
                color: AmialColors.primary)),
        if (note.isNotEmpty) ...[
          const SizedBox(height: 6),
          Text(note,
              textAlign: TextAlign.right,
              style: TextStyle(fontSize: 12.5, color: Colors.grey.shade700)),
        ],
        if (hasFee) ...[
          const SizedBox(height: 9),
          Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
            Text('الإجمالي ${AmialMoney.yer(total)}',
                style: const TextStyle(fontSize: 12.5, fontWeight: FontWeight.bold)),
            Text('رسوم ${AmialMoney.yer(fee)}',
                style: const TextStyle(fontSize: 12, color: AmialColors.textSecondary)),
          ]),
        ],
        if (hasFee && receiverPaysFee) ...[
          const SizedBox(height: 5),
          Text('سيصل إلى طالب المال ${AmialMoney.yer(recipientCredit)}',
              textAlign: TextAlign.right,
              style: const TextStyle(fontSize: 12, color: AmialColors.textSecondary)),
        ],
        if (!quoteAvailable) ...[
          const SizedBox(height: 9),
          const Text('تعذّر احتساب الإجمالي — حدّث القائمة قبل الدفع',
              textAlign: TextAlign.right,
              style: TextStyle(fontSize: 12, color: AmialColors.red)),
        ],
        const SizedBox(height: 14),
        Row(children: [
          Expanded(
            child: OutlinedButton.icon(
              onPressed: id == null || c.isSubmitting.value
                  ? null
                  : () => _decline(id),
              icon: const Icon(Icons.close, size: 18),
              label: const Text('رفض'),
              style: OutlinedButton.styleFrom(
                foregroundColor: AmialColors.red,
                side: const BorderSide(color: AmialColors.red),
                minimumSize: const Size.fromHeight(46),
              ),
            ),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: FilledButton.icon(
              onPressed: id == null || !quoteAvailable || c.isSubmitting.value
                  ? null
                  : () => _pay(
                        id,
                        amount,
                        fee,
                        total,
                        recipientCredit,
                        receiverPaysFee,
                        from,
                        note,
                      ),
              icon: const Icon(Icons.check, size: 18),
              label: const Text('موافقة ودفع'),
              style: FilledButton.styleFrom(
                backgroundColor: AmialColors.primary,
                minimumSize: const Size.fromHeight(46),
              ),
            ),
          ),
        ]),
      ]),
    );
  }

  Future<void> _pay(
    int id,
    String amount,
    String fee,
    String total,
    String recipientCredit,
    bool receiverPaysFee,
    String from,
    String note,
  ) async {
    final approved = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.white,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
      ),
      builder: (ctx) => SafeArea(
        child: Padding(
          padding: const EdgeInsets.fromLTRB(20, 10, 20, 24),
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            Row(children: [
              IconButton(
                tooltip: 'إغلاق',
                onPressed: () => Navigator.pop(ctx, false),
                icon: const Icon(Icons.close),
              ),
              const Spacer(),
              const Text('تأكيد دفع الطلب',
                  style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold)),
            ]),
            const SizedBox(height: 10),
            CircleAvatar(
              radius: 30,
              backgroundColor: AmialColors.primary.withValues(alpha: 0.1),
              child: const Icon(Icons.person_outline,
                  color: AmialColors.primary, size: 30),
            ),
            const SizedBox(height: 9),
            Text(from,
                style: const TextStyle(fontSize: 17, fontWeight: FontWeight.bold)),
            const Text('طالب المال',
                style: TextStyle(fontSize: 12, color: AmialColors.textSecondary)),
            const SizedBox(height: 16),
            Container(
              padding: const EdgeInsets.all(15),
              decoration: BoxDecoration(
                color: AmialColors.background,
                borderRadius: BorderRadius.circular(14),
              ),
              child: Column(children: [
                _confirmRow('المبلغ', AmialMoney.yer(amount)),
                const SizedBox(height: 7),
                _confirmRow('الرسوم', AmialMoney.yer(fee)),
                const Divider(height: 20),
                _confirmRow('الإجمالي', AmialMoney.yer(total), bold: true),
                if (receiverPaysFee && (double.tryParse(fee) ?? 0) > 0) ...[
                  const Divider(height: 20),
                  _confirmRow('سيصل لطالب المال', AmialMoney.yer(recipientCredit)),
                ],
                if (note.isNotEmpty) ...[
                  const Divider(height: 20),
                  _confirmRow('السبب', note),
                ],
              ]),
            ),
            const SizedBox(height: 14),
            const Text(
              'راجع الاسم والمبلغ. بعد إدخال رمز الحماية سيُخصم الإجمالي من رصيدك.',
              textAlign: TextAlign.center,
              style: TextStyle(fontSize: 12, color: AmialColors.textMuted),
            ),
            const SizedBox(height: 16),
            FilledButton.icon(
              onPressed: () => Navigator.pop(ctx, true),
              icon: const Icon(Icons.check_circle_outline),
              label: const Text('متابعة إلى رمز الحماية'),
              style: FilledButton.styleFrom(
                backgroundColor: AmialColors.primary,
                minimumSize: const Size.fromHeight(50),
              ),
            ),
          ]),
        ),
      ),
    );

    if (approved != true || !mounted) return;
    final pin = await askPin(context, subtitle: 'أدخل رمز الحماية لإتمام الدفع');
    if (pin == null || !mounted) return;

    final done = await c.payById(id, pin: pin);
    if (!mounted) return;
    _snack(
      done ? 'تم الدفع وتحويل المبلغ' :
          (c.lastError.value.isEmpty ? 'فشل الدفع' : c.lastError.value),
      ok: done,
    );
  }

  Widget _confirmRow(String label, String value, {bool bold = false}) =>
      Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
        Text(value,
            style: TextStyle(
                fontSize: bold ? 16 : 13,
                fontWeight: bold ? FontWeight.bold : FontWeight.w600,
                color: bold ? AmialColors.primary : Colors.black87)),
        Text(label,
            style: const TextStyle(fontSize: 12, color: AmialColors.textSecondary)),
      ]);

  Future<void> _decline(int id) async {
    final reason = TextEditingController();
    final rejected = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.white,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
      ),
      builder: (ctx) => Padding(
        padding: EdgeInsets.fromLTRB(
            20, 10, 20, 20 + MediaQuery.of(ctx).viewInsets.bottom),
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          Row(children: [
            IconButton(
              tooltip: 'إغلاق',
              onPressed: () => Navigator.pop(ctx, false),
              icon: const Icon(Icons.close),
            ),
            const Spacer(),
            const Text('رفض الطلب',
                style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold)),
          ]),
          const SizedBox(height: 10),
          TextField(
            controller: reason,
            textAlign: TextAlign.right,
            maxLength: 255,
            decoration: const InputDecoration(
              labelText: 'السبب (اختياري)',
              border: OutlineInputBorder(),
            ),
          ),
          const SizedBox(height: 12),
          FilledButton.icon(
            onPressed: () => Navigator.pop(ctx, true),
            icon: const Icon(Icons.block),
            label: const Text('تأكيد الرفض'),
            style: FilledButton.styleFrom(
              backgroundColor: AmialColors.red,
              minimumSize: const Size.fromHeight(50),
            ),
          ),
        ]),
      ),
    );

    final reasonText = reason.text.trim();
    reason.dispose();
    if (rejected != true || !mounted) return;
    final done = await c.decline(id, reason: reasonText);
    if (!mounted) return;
    _snack(
      done ? 'رُفض الطلب وأُبلغ صاحبه' :
          (c.lastError.value.isEmpty ? 'فشل الرفض' : c.lastError.value),
      ok: done,
    );
  }

  void _snack(String message, {required bool ok}) =>
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
        content: Text(message),
        backgroundColor: ok ? const Color(0xFF2E7D32) : AmialColors.red,
      ));
}
