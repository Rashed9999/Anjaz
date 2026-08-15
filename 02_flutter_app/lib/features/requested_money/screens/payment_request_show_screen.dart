import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:get/get.dart';
import 'package:amial_pay/theme/amial_colors.dart';
import 'package:amial_pay/theme/amial_spacing.dart';
import 'package:amial_pay/helper/amial_money.dart';
import 'package:amial_pay/common/widgets/amial_button.dart';
import 'package:amial_pay/features/requested_money/controllers/payment_request_controller.dart';
import 'package:amial_pay/features/requested_money/screens/outgoing_requests_screen.dart';
import 'package:amial_pay/features/shared/widgets/qr_widgets.dart';

/// AMIAL-REQUEST-DIRECT-003 — **نتيجةُ الطلب: وجهان لا وجهٌ واحد.**
///
/// ══════════════════════════════════════════════════════════════════════
/// **الثمن الذي دُفع:** أُصلح الخادمُ فصار الطلبُ يصل إلى صاحبه مباشرةً،
/// وأُصلحت شاشةُ الإنشاء فصارت تعرض اسمَ المستلم قبل الإرسال — **وبقيت
/// هذه الشاشة تقول للجميع «الطلب جاهز للمشاركة»** وتحتها رمزُ QR والرابطُ
/// وزرُّ «مشاركة الرابط».
///
/// فآخرُ ما يراه الطالبُ بعد ضغط «إرسال» هو رمزٌ ورابط. **وآخرُ ما يُرى
/// هو ما يُصدَّق** — فبقي «طلبُ المال» في ذهنه رابطاً يُشارَك مهما قيل في
/// الشاشة السابقة. (وقالها صاحبُ المشروع ثلاث مرّات: «لماذا لا زلتَ
/// مصمِّماً على الرابط والباركود».)
///
/// فصار للشاشة وجهان:
///
/// | الحال | ما يُعرض |
/// |---|---|
/// | `delivered == true` — المستلم على أميال | «وصل إلى فلان — بانتظار موافقته». **لا رمز، ولا رابط، ولا رمزٌ قصير.** |
/// | `delivered == false` — ليس على أميال | الرمزُ والرابط، **ومعهما سببُ ظهورهما**. |
///
/// والوجهُ الثاني لم يُحذف لأنّ له حالةً حقيقيّة: من يطلب من شخصٍ ليس على
/// أميال لا يملك طريقاً غيرَه. لكنّه صار **الاستثناءَ المعلَّل** لا
/// المخرجَ الافتراضيّ.
class PaymentRequestShowScreen extends StatefulWidget {
  const PaymentRequestShowScreen({super.key});

  @override
  State<PaymentRequestShowScreen> createState() => _PaymentRequestShowScreenState();
}

class _PaymentRequestShowScreenState extends State<PaymentRequestShowScreen> {
  late final PaymentRequestController c;

  @override
  void initState() {
    super.initState();
    c = Get.find<PaymentRequestController>();
  }

  void _copy(String value, String label) {
    Clipboard.setData(ClipboardData(text: value));
    Get.snackbar('نُسخ', label,
        backgroundColor: Colors.green.shade100,
        colorText: Colors.green.shade800,
        snackPosition: SnackPosition.BOTTOM,
        duration: const Duration(seconds: 2));
  }

  Future<void> _cancel(int id) async {
    final confirm = await Get.dialog<bool>(AlertDialog(
      title: const Text('إلغاء الطلب؟'),
      content: const Text('لن يستطيع المستلم دفع هذا الطلب بعد الإلغاء.'),
      actions: [
        TextButton(onPressed: () => Get.back(result: false), child: const Text('تراجع')),
        FilledButton(
          style: FilledButton.styleFrom(backgroundColor: AmialColors.red),
          onPressed: () => Get.back(result: true),
          child: const Text('نعم، إلغاء'),
        ),
      ],
    ));
    if (confirm != true) return;
    final ok = await c.cancel(id);
    if (!mounted) return;
    if (ok) {
      Get.back();
      Get.snackbar('تم', 'تم إلغاء الطلب',
          backgroundColor: Colors.orange.shade100, colorText: Colors.orange.shade800);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Obx(() {
      final data = c.currentRequest.value;

      if (data == null) {
        return Scaffold(
          backgroundColor: AmialColors.background,
          appBar: AppBar(title: const Text('الطلب')),
          body: const Center(child: Text('لا يوجد طلب')),
        );
      }

      final req = data['request'] as Map? ?? {};
      final delivered = data['delivered'] == true;
      final recipient = (data['recipient_label'] ?? req['recipient_name'] ??
              req['recipient_phone'] ?? '')
          .toString();

      return Scaffold(
        backgroundColor: AmialColors.background,
        // **والعنوانُ يقول أيَّ الوجهين هذا** — «تم إنشاء الطلب» كانت
        // تصلح للحالتين فلا تُخبر عن أيٍّ منهما.
        appBar: AppBar(title: Text(delivered ? 'وصل الطلب' : 'شارِك الطلب')),
        body: SingleChildScrollView(
          padding: const EdgeInsets.all(20),
          child: Column(children: [
            _headline(delivered, recipient),
            const SizedBox(height: 24),
            _amountCard(req),
            const SizedBox(height: 20),
            if (delivered) ..._deliveredBody(req) else ..._shareBody(data, req),
          ]),
        ),
      );
    });
  }

  // ══════════════════════════════════════════════════════════════════
  //  الترويسة
  // ══════════════════════════════════════════════════════════════════

  Widget _headline(bool delivered, String recipient) {
    final color = delivered ? AmialColors.success : AmialColors.yellowDark;

    return Column(children: [
      Container(
        width: 80,
        height: 80,
        decoration: BoxDecoration(color: color, shape: BoxShape.circle),
        child: Icon(delivered ? Icons.mark_email_read_rounded : Icons.share_rounded,
            color: Colors.white, size: 42),
      ),
      const SizedBox(height: 16),
      Text(
        delivered
            ? (recipient.isEmpty ? 'وصل الطلب' : 'وصل الطلب إلى $recipient')
            : 'الطلب جاهز للمشاركة',
        textAlign: TextAlign.center,
        style: const TextStyle(fontSize: 18, fontWeight: FontWeight.bold),
      ),
      const SizedBox(height: 6),
      Text(
        delivered
            // **ما يقع بعدها يُقال** — القاعدة ١٢: لا يُترك الطالب يسأل
            // «وماذا الآن؟».
            ? 'يصله إشعار الآن. حين يوافق يُخصم المبلغ من رصيده ويُضاف إلى رصيدك، '
                'ولا يُخصم شيء قبل موافقته.'
            // **والسببُ يُقال** — وإلّا ظنّ الطالبُ أنّ الرابط هو الطريقة
            // الوحيدة، وهو استثناءٌ لا أصل.
            : 'صاحب هذا الرقم ليس على أميال، فلا يصله إشعار. '
                'شاركه الرمز أو الرابط ليدفع منه.',
        textAlign: TextAlign.center,
        style: TextStyle(fontSize: 12.5, color: Colors.grey.shade700, height: 1.5),
      ),
    ]);
  }

  Widget _amountCard(Map req) {
    final amount = req['amount']?.toString() ?? '0';
    final note = req['note']?.toString();
    final isRecurring = req['is_recurring'] == true;
    final period = req['recurring_period']?.toString();

    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(20),
      decoration: BoxDecoration(
        color: AmialColors.primary,
        borderRadius: BorderRadius.circular(16),
      ),
      child: Column(children: [
        const Text('المبلغ المطلوب', style: TextStyle(color: Colors.white70)),
        const SizedBox(height: 8),
        // AMIAL-DS-001: تنسيق نقدي نظيف (كان يظهر «5000.0000» خام).
        Text(AmialMoney.yer(amount),
            style: const TextStyle(
                color: Colors.white, fontSize: 32, fontWeight: FontWeight.bold)),
        if (note != null && note.isNotEmpty) ...[
          const SizedBox(height: 8),
          Text(note, style: const TextStyle(color: Colors.white70, fontSize: 13)),
        ],
        if (isRecurring) ...[
          const SizedBox(height: 8),
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
            decoration: BoxDecoration(
              color: AmialColors.yellow,
              borderRadius: BorderRadius.circular(8),
            ),
            child: Text(
              '🔁 يتكرّر ${period == 'daily' ? 'يومياً' : period == 'weekly' ? 'أسبوعياً' : 'شهرياً'}',
              style: const TextStyle(
                  color: Colors.black87, fontSize: 11, fontWeight: FontWeight.bold),
            ),
          ),
        ],
      ]),
    );
  }

  // ══════════════════════════════════════════════════════════════════
  //  ① الوجه المباشر — بلا رمزٍ ولا رابط
  // ══════════════════════════════════════════════════════════════════

  List<Widget> _deliveredBody(Map req) {
    final id = req['id'] as int?;

    return [
      Container(
        padding: const EdgeInsets.all(14),
        decoration: BoxDecoration(
          color: Colors.orange.shade50,
          borderRadius: BorderRadius.circular(14),
          border: Border.all(color: Colors.orange.shade200),
        ),
        child: Row(children: [
          Icon(Icons.hourglass_top_rounded, color: Colors.orange.shade800, size: 20),
          const SizedBox(width: 10),
          Expanded(
            child: Text('بانتظار موافقته',
                style: TextStyle(
                    fontSize: 13.5,
                    fontWeight: FontWeight.bold,
                    color: Colors.orange.shade900)),
          ),
        ]),
      ),
      const SizedBox(height: AmialSpacing.md),

      // **ومتابعةُ الطلب لها بابٌ من هنا** — وإلّا خرج الطالبُ من الشاشة
      // ولا يعرف أين يجد طلبه بعدها. (القاعدة ١٢.)
      AmialButton(
        label: 'طلباتي المرسلة',
        icon: Icons.outbox_rounded,
        onPressed: () => Get.off(() => const OutgoingRequestsScreen()),
      ),
      const SizedBox(height: AmialSpacing.sm),
      AmialButton(
        label: 'تم',
        kind: AmialButtonKind.outline,
        onPressed: () => Get.back(),
      ),

      if (id != null && req['status'] == 'pending') ...[
        const SizedBox(height: AmialSpacing.sm),
        Obx(() => TextButton.icon(
              onPressed: c.isSubmitting.value ? null : () => _cancel(id),
              icon: const Icon(Icons.cancel, size: 18, color: AmialColors.red),
              label: const Text('سحب الطلب', style: TextStyle(color: AmialColors.red)),
            )),
      ],
    ];
  }

  // ══════════════════════════════════════════════════════════════════
  //  ② وجه المشاركة — لمن ليس على أميال وحدَه
  // ══════════════════════════════════════════════════════════════════

  List<Widget> _shareBody(Map data, Map req) {
    final shortCode = (data['short_code'] ?? req['short_code'] ?? '').toString();
    final publicUrl = (data['public_url'] ?? '').toString();
    final id = req['id'] as int?;

    return [
      Container(
        padding: const EdgeInsets.all(16),
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(16),
        ),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          // AMIAL-REQ-QR-001 — **الرمز المرئيّ: ما يُمسح فعلاً.**
          //
          // كان في هذا الموضع `Icon(Icons.qr_code_2)` بحجم ١٤٠ داخل إطارٍ
          // أبيض وتحته «سيُولَّد رمز QR كامل قريباً» — **أيقونةٌ تتظاهر
          // برمز**: يعرضها الطالبُ فيصوّب الدافعُ ماسحَه فلا يُقرأ شيء،
          // ولا خطأ في أيّ سجلّ.
          //
          // والحمولةُ هي التي يفهمها ماسحُ التطبيق حرفاً بحرف:
          // `{"t":"amial_pr","code":…}` — وأيُّ صيغةٍ غيرِها تُنتج رمزاً
          // يُمسح ولا يقع شيء.
          if (shortCode.isNotEmpty) ...[
            Center(
              child: QrDisplayWidget(
                data: jsonEncode({'t': 'amial_pr', 'code': shortCode}),
                size: 190,
                caption: 'يمسحه الدافع من «دفع لتاجر»',
              ),
            ),
            const SizedBox(height: 16),
            const Divider(),
            const SizedBox(height: 8),
          ],

          const Text('الرمز القصير',
              textAlign: TextAlign.right,
              style: TextStyle(color: Colors.grey, fontSize: 12)),
          const SizedBox(height: 8),
          Center(
            child: SelectableText(
              shortCode,
              style: const TextStyle(
                fontSize: 36,
                fontWeight: FontWeight.bold,
                letterSpacing: 6,
                color: AmialColors.primary,
              ),
            ),
          ),
          const SizedBox(height: 12),
          if (publicUrl.isNotEmpty) ...[
            const Divider(),
            const SizedBox(height: 8),
            const Text('الرابط الكامل',
                textAlign: TextAlign.right,
                style: TextStyle(color: Colors.grey, fontSize: 12)),
            const SizedBox(height: 4),
            SelectableText(
              publicUrl,
              textAlign: TextAlign.center,
              style: TextStyle(fontSize: 13, color: Colors.grey.shade800, height: 1.4),
            ),
          ],
        ]),
      ),
      const SizedBox(height: 20),

      // أزرار النسخ — موحّدة عبر AmialButton (DS)
      Row(children: [
        Expanded(
            child: AmialButton(
          label: 'نسخ الرمز',
          icon: Icons.copy,
          kind: AmialButtonKind.secondary,
          onPressed: () => _copy(shortCode, 'الرمز نُسخ'),
        )),
        const SizedBox(width: AmialSpacing.sm),
        Expanded(
            child: AmialButton(
          label: 'مشاركة الرابط',
          icon: Icons.share,
          kind: AmialButtonKind.outline,
          onPressed: publicUrl.isEmpty ? null : () => _copy(publicUrl, 'الرابط نُسخ'),
        )),
      ]),
      const SizedBox(height: AmialSpacing.sm),

      if (id != null && req['status'] == 'pending')
        Obx(() => TextButton.icon(
              onPressed: c.isSubmitting.value ? null : () => _cancel(id),
              icon: const Icon(Icons.cancel, size: 18, color: AmialColors.red),
              label: const Text('إلغاء الطلب', style: TextStyle(color: AmialColors.red)),
            )),
    ];
  }
}
