import 'package:flutter/material.dart';
import 'package:get/get.dart';

import 'package:amial_pay/common/widgets/amial_ltr_number.dart';
import 'package:amial_pay/features/requested_money/controllers/payment_request_controller.dart';
import 'package:amial_pay/theme/amial_colors.dart';

/// AMIAL-REQUEST-DIRECT-003 — «طلباتي المرسلة»: أين ذهب ما طلبتُه.
///
/// ══════════════════════════════════════════════════════════════════════
/// **الشاشةُ الثانية التي لم تكن موجودة.** `PaymentRequestController.outgoing`
/// مبنيّة، و`listForUser(direction: 'outgoing')` مبنيّة في الخلفية —
/// **ولم تقرأهما شاشةٌ واحدة** (قِيس بالبحث: لا نداءَ لـ`loadList('outgoing')`
/// في التطبيق كلّه).
///
/// فمن أرسل طلباً خرج من شاشة النتيجة ولم يجد له أثراً: لا يعرف أوُوفق عليه
/// أم رُفض أم ما زال معلّقاً، ولا يملك سحبَه. **فيسأل بواتساب** — وهو نفسُ
/// الطريق الذي أُريد الخلاصُ منه.
///
/// (نمطُ العطل الأكثر تكراراً هنا: مبنيٌّ ولا يُوصَل إليه.)
class OutgoingRequestsScreen extends StatefulWidget {
  const OutgoingRequestsScreen({super.key});

  @override
  State<OutgoingRequestsScreen> createState() => _OutgoingRequestsScreenState();
}

class _OutgoingRequestsScreenState extends State<OutgoingRequestsScreen> {
  PaymentRequestController get c => Get.find<PaymentRequestController>();

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _reload());
  }

  /// بلا `status`: **المعلَّقُ والمدفوعُ والمرفوضُ في مكانٍ واحد.**
  /// من فتح الشاشة يسأل «ماذا حدث لطلبي»، وقائمةُ المعلَّق وحدَها تُخفي
  /// الجواب حين يكون الجوابُ «رُفض».
  Future<void> _reload() => c.loadList('outgoing');

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(title: const Text('طلباتي المرسلة')),
      body: Obx(() {
        if (c.isLoading.value && c.outgoing.isEmpty) {
          return const Center(child: CircularProgressIndicator());
        }

        if (c.outgoing.isEmpty) {
          return _empty();
        }

        return RefreshIndicator(
          onRefresh: _reload,
          child: ListView.separated(
            padding: const EdgeInsets.all(16),
            itemCount: c.outgoing.length,
            separatorBuilder: (_, __) => const SizedBox(height: 10),
            itemBuilder: (_, i) => _card(c.outgoing[i]),
          ),
        );
      }),
    );
  }

  Widget _empty() => ListView(
        physics: const AlwaysScrollableScrollPhysics(),
        children: [
          const SizedBox(height: 120),
          Icon(Icons.outbox_outlined, size: 64, color: Colors.grey.shade400),
          const SizedBox(height: 12),
          const Text('لم تطلب مالاً بعد',
              textAlign: TextAlign.center,
              style: TextStyle(fontSize: 16, fontWeight: FontWeight.w600)),
          const SizedBox(height: 6),
          Text('ما تطلبه من غيرك يظهر هنا حتى يوافق أو يرفض',
              textAlign: TextAlign.center,
              style: TextStyle(fontSize: 12.5, color: Colors.grey.shade600)),
        ],
      );

  /// الحالةُ بلونها ونصّها العربيّ — **ولا حالةَ بلا اسم**: `status`
  /// مجهولةٌ تُعرض كما جاءت لا تُبتلع صامتةً.
  (String, Color, IconData) _statusOf(String s) => switch (s) {
        'pending' => ('بانتظار موافقته', Colors.orange.shade800, Icons.hourglass_top_rounded),
        'paid' => ('دُفع', AmialColors.success, Icons.check_circle_rounded),
        'declined' => ('رفضه', AmialColors.red, Icons.cancel_rounded),
        'cancelled' => ('سحبتَه', Colors.grey.shade700, Icons.undo_rounded),
        'expired' => ('انتهت مدّته', Colors.grey.shade700, Icons.timer_off_rounded),
        _ => (s.isEmpty ? 'غير معروفة' : s, Colors.grey.shade700, Icons.help_outline),
      };

  Widget _card(Map<String, dynamic> r) {
    final id = r['id'] as int?;
    final amount = '${r['amount'] ?? '0'}';
    final to = (r['recipient_label'] ?? r['recipient_name'] ?? r['recipient_phone'] ?? '—')
        .toString();
    final note = (r['note'] ?? '').toString();
    final status = (r['status'] ?? '').toString();
    final (label, color, icon) = _statusOf(status);

    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: AmialColors.border),
      ),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Row(children: [
          const Icon(Icons.call_made, color: AmialColors.primary, size: 20),
          const SizedBox(width: 8),
          Expanded(
            child: Text('طلبتَ من $to',
                style: const TextStyle(fontSize: 14, fontWeight: FontWeight.w600)),
          ),
        ]),
        const SizedBox(height: 10),
        // AMIAL-RTL-SIGN-001: المبالغ تُلفّ باتجاه لاتينيّ داخل واجهة عربية.
        AmialLtrNumber('$amount ر.ي',
            style: const TextStyle(
                fontSize: 24, fontWeight: FontWeight.bold, color: AmialColors.primary)),
        if (note.isNotEmpty) ...[
          const SizedBox(height: 6),
          Text(note, style: TextStyle(fontSize: 12.5, color: Colors.grey.shade700)),
        ],
        const SizedBox(height: 12),
        Row(children: [
          Icon(icon, size: 16, color: color),
          const SizedBox(width: 6),
          Text(label,
              style: TextStyle(fontSize: 12.5, fontWeight: FontWeight.bold, color: color)),
          const Spacer(),
          // **السحبُ فعلٌ حقيقيّ لا زينة** — ولا يُعرض إلّا حين يمكن.
          if (status == 'pending' && id != null)
            Obx(() => TextButton(
                  onPressed: c.isSubmitting.value ? null : () => _cancel(id),
                  child: const Text('سحب الطلب',
                      style: TextStyle(color: AmialColors.red, fontSize: 12.5)),
                )),
        ]),
      ]),
    );
  }

  Future<void> _cancel(int id) async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('سحب الطلب؟'),
        content: const Text('لن يستطيع صاحبه دفعه بعد السحب.'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('تراجع')),
          FilledButton(
            onPressed: () => Navigator.pop(ctx, true),
            style: FilledButton.styleFrom(backgroundColor: AmialColors.red),
            child: const Text('اسحب'),
          ),
        ],
      ),
    );

    if (ok != true || !mounted) return;

    final done = await c.cancel(id);
    if (!mounted) return;

    // **والقائمةُ تُعاد قراءتها** — `cancel` تحذف الصفّ محلّيّاً، والحالةُ
    // الحقيقيّة «cancelled» لا «مختفٍ»: من سحب طلباً يريد أن يرى أنّه سُحب.
    if (done) await _reload();

    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
      content: Text(done ? 'سُحب الطلب' : (c.lastError.value.isEmpty ? 'تعذّر السحب' : c.lastError.value)),
      backgroundColor: done ? AmialColors.success : AmialColors.red,
    ));
  }
}
