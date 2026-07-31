import 'package:flutter/material.dart';
import 'package:get/get.dart';

import 'package:amyal_pay/features/requested_money/controllers/payment_request_controller.dart';
import 'package:amyal_pay/common/widgets/amial_ltr_number.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';

/// AMIAL-REQUEST-DIRECT-001 — «الطلبات الواردة»: ما طُلب منك، فتوافق أو ترفض.
///
/// **الشاشة التي لم تكن موجودة.**
/// `PaymentRequestController.incoming` مبنيّة، و`listForUser(direction:
/// 'incoming')` مبنيّة في الخلفية — **ولم تقرأهما شاشةٌ واحدة**. فكان الطلب
/// يُنشَأ ويُربط بالمستلم ولا يراه المستلم أبداً، ولا يُشعَر به.
///
/// ولذلك بدا «طلب المال» رابطاً يُشارَك باليد: لم يكن الرابط بديلاً اختاره
/// أحد، بل المخرجَ الوحيد الباقي حين انقطع الطريق المباشر.
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
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmyalColors.background,
      appBar: AppBar(title: const Text('طلبات واردة')),
      body: Obx(() {
        if (c.isLoading.value && c.incoming.isEmpty) {
          return const Center(child: CircularProgressIndicator());
        }

        if (c.incoming.isEmpty) {
          return _empty();
        }

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
  }

  Widget _empty() => ListView(
        // قائمةٌ لا صفحةٌ ثابتة: بلا تمرير لا يعمل السحب للتحديث، فيبقى
        // المستخدم أمام «لا طلبات» ولا يملك طريقةً لإعادة السؤال.
        physics: const AlwaysScrollableScrollPhysics(),
        children: [
          const SizedBox(height: 120),
          Icon(Icons.inbox_outlined, size: 64, color: Colors.grey.shade400),
          const SizedBox(height: 12),
          const Text('لا طلبات واردة', textAlign: TextAlign.center,
              style: TextStyle(fontSize: 16, fontWeight: FontWeight.w600)),
          const SizedBox(height: 6),
          Text('حين يطلب منك أحدٌ مالاً يظهر هنا',
              textAlign: TextAlign.center,
              style: TextStyle(fontSize: 12.5, color: Colors.grey.shade600)),
        ],
      );

  Widget _card(Map<String, dynamic> r) {
    final id = r['id'] as int?;
    final amount = '${r['amount'] ?? '0'}';
    final from = (r['requester_name'] ?? r['requester_phone'] ?? 'مستخدم').toString();
    final note = (r['note'] ?? '').toString();

    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: AmyalColors.border),
      ),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Row(children: [
          const Icon(Icons.call_received, color: AmyalColors.primary, size: 20),
          const SizedBox(width: 8),
          Expanded(
            child: Text('$from يطلب منك',
                style: const TextStyle(fontSize: 14, fontWeight: FontWeight.w600)),
          ),
        ]),
        const SizedBox(height: 10),
        // AMIAL-RTL-SIGN-001: المبالغ تُلفّ باتجاه لاتينيّ داخل واجهة عربية.
        AmialLtrNumber('$amount ر.ي',
            style: const TextStyle(
                fontSize: 24, fontWeight: FontWeight.bold, color: AmyalColors.primary)),
        if (note.isNotEmpty) ...[
          const SizedBox(height: 6),
          Text(note, style: TextStyle(fontSize: 12.5, color: Colors.grey.shade700)),
        ],
        const SizedBox(height: 14),
        Row(children: [
          Expanded(
            child: OutlinedButton.icon(
              onPressed: id == null ? null : () => _decline(id),
              icon: const Icon(Icons.close, size: 18),
              label: const Text('رفض'),
              style: OutlinedButton.styleFrom(
                foregroundColor: AmyalColors.red,
                side: const BorderSide(color: AmyalColors.red),
                minimumSize: const Size.fromHeight(46),
              ),
            ),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: FilledButton.icon(
              onPressed: id == null ? null : () => _pay(id, amount, from),
              icon: const Icon(Icons.check, size: 18),
              label: const Text('ادفع'),
              style: FilledButton.styleFrom(
                backgroundColor: AmyalColors.primary,
                minimumSize: const Size.fromHeight(46),
              ),
            ),
          ),
        ]),
      ]),
    );
  }

  Future<void> _pay(int id, String amount, String from) async {
    // الدفع يُخرج مالاً — فيُسأل قبله. والرفض لا يُخرج شيئاً فيُسأل عن سببه
    // لا عن تأكيده.
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('تأكيد الدفع'),
        content: Text('ستدفع $amount ر.ي إلى $from'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('تراجع')),
          FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('ادفع')),
        ],
      ),
    );

    if (ok != true || !mounted) return;

    final done = await c.payById(id);
    if (!mounted) return;
    _snack(done ? 'تم الدفع' : (c.lastError.value.isEmpty ? 'فشل الدفع' : c.lastError.value),
        ok: done);
  }

  Future<void> _decline(int id) async {
    final reason = TextEditingController();

    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('رفض الطلب'),
        content: TextField(
          controller: reason,
          textAlign: TextAlign.right,
          decoration: const InputDecoration(hintText: 'السبب (اختياري)'),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('تراجع')),
          FilledButton(
            onPressed: () => Navigator.pop(ctx, true),
            style: FilledButton.styleFrom(backgroundColor: AmyalColors.red),
            child: const Text('رفض'),
          ),
        ],
      ),
    );

    if (ok != true || !mounted) return;

    final done = await c.decline(id, reason: reason.text.trim());
    if (!mounted) return;
    _snack(done ? 'رُفض الطلب وأُبلغ صاحبه' : 'فشل الرفض', ok: done);
  }

  void _snack(String m, {required bool ok}) =>
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
        content: Text(m),
        backgroundColor: ok ? const Color(0xFF2E7D32) : AmyalColors.red,
      ));
}
