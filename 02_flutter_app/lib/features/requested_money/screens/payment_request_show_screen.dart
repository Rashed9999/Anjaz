import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:get/get.dart';
import 'package:amial_pay/theme/amial_colors.dart';
import 'package:amial_pay/theme/amial_spacing.dart';
import 'package:amial_pay/helper/amial_money.dart';
import 'package:amial_pay/common/widgets/amial_button.dart';
import 'package:amial_pay/features/requested_money/controllers/payment_request_controller.dart';
import 'package:amial_pay/features/shared/widgets/qr_widgets.dart';

/// AMIAL-PAYMENT-REQUESTS-001 — شاشة عرض طلب بعد إنشائه.
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
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(
        title: const Text('تم إنشاء الطلب'),
      ),
      body: Obx(() {
        final data = c.currentRequest.value;
        if (data == null) {
          return const Center(child: Text('لا يوجد طلب'));
        }
        final req = data['request'] as Map? ?? {};
        final shortCode = (data['short_code'] ?? req['short_code'] ?? '').toString();
        final publicUrl = (data['public_url'] ?? '').toString();
        final amount = req['amount']?.toString() ?? '0';
        final note = req['note']?.toString();
        final isRecurring = req['is_recurring'] == true;
        final period = req['recurring_period']?.toString();
        final id = req['id'] as int?;

        return SingleChildScrollView(
          padding: const EdgeInsets.all(20),
          child: Column(children: [
            // أيقونة النجاح
            Container(
              width: 80, height: 80,
              decoration: const BoxDecoration(color: Colors.green, shape: BoxShape.circle),
              child: const Icon(Icons.check, color: Colors.white, size: 48),
            ),
            const SizedBox(height: 16),
            const Text('الطلب جاهز للمشاركة',
                style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold)),
            const SizedBox(height: 24),

            // بطاقة المبلغ
            Container(
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
                    style: const TextStyle(color: Colors.white, fontSize: 32, fontWeight: FontWeight.bold)),
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
                      style: const TextStyle(color: Colors.black87, fontSize: 11, fontWeight: FontWeight.bold),
                    ),
                  ),
                ],
              ]),
            ),
            const SizedBox(height: 20),

            // AMIAL-REQ-QR-001 — **حُذف هنا رمزُ QR مزيَّف.**
            //
            // ══════════════════════════════════════════════════════════
            // كان في هذا الموضع `Icon(Icons.qr_code_2)` بحجم ١٤٠ داخل
            // إطارٍ أبيض، وتحته «سيُولَّد رمز QR كامل قريباً» — **أيقونةٌ
            // تتظاهر برمز**.
            //
            // وهي أسوأُ من غيابها: يعرضها التاجرُ على العميل، فيفتح
            // العميلُ الماسحَ ويصوّبه فلا يُقرأ شيء. فيظنّ أنّ هاتفه
            // معطوب أو أنّ التطبيق لا يعمل — **ولا خطأ في أيّ سجلّ**.
            //
            // والرمزُ الحقيقيّ أسفلُ، مع الرمز القصير، ويظهر دائماً لا
            // بشرط `share_method == 'qr'`: من اختار «رابطاً» عند الإنشاء
            // قد يقف أمامه زبونٌ يريد المسح.
            // (المهارة: «No fake UI. Everything visible must function.»)
            const SizedBox(height: 16),

            // الرمز القصير
            Container(
              padding: const EdgeInsets.all(16),
              decoration: BoxDecoration(
                color: Colors.white,
                borderRadius: BorderRadius.circular(16),
              ),
              child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                // AMIAL-REQ-QR-001 — **الرمز المرئيّ: ما يُمسح فعلاً.**
                //
                // ══════════════════════════════════════════════════════
                // كانت الشاشة تعرض الرمزَ القصير والرابطَ نصّاً، **ولا
                // رمزَ QR إطلاقاً**. فالطالبُ يقول للدافع «امسح» ولا شيء
                // يُمسح: يُملي عليه ستّةَ محارفَ أو يُرسل رابطاً.
                //
                // وشاشةُ محطّة الوقود كانت تعرضه منذ جولاتٍ بالحمولة
                // نفسِها — فأُصلح مدخلٌ وتُرك الآخر. (القاعدة الرابعة:
                // ميزةٌ لها مدخلان تُختبر من مدخليها.)
                //
                // والحمولةُ هي التي يفهمها ماسحُ التطبيق حرفاً بحرف:
                // `{"t":"amial_pr","code":…}` — وأيُّ صيغةٍ غيرِها تُنتج
                // رمزاً يُمسح ولا يقع شيء.
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

                const Text('الرمز القصير', textAlign: TextAlign.right,
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
                  const Text('الرابط الكامل', textAlign: TextAlign.right,
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
              Expanded(child: AmialButton(
                label: 'نسخ الرمز',
                icon: Icons.copy,
                kind: AmialButtonKind.secondary,
                onPressed: () => _copy(shortCode, 'الرمز نُسخ'),
              )),
              const SizedBox(width: AmialSpacing.sm),
              Expanded(child: AmialButton(
                label: 'مشاركة الرابط',
                icon: Icons.share,
                kind: AmialButtonKind.outline,
                onPressed: publicUrl.isEmpty ? null : () => _copy(publicUrl, 'الرابط نُسخ'),
              )),
            ]),
            const SizedBox(height: AmialSpacing.sm),

            // زر الإلغاء
            if (id != null && req['status'] == 'pending')
              Obx(() => TextButton.icon(
                onPressed: c.isSubmitting.value ? null : () => _cancel(id),
                icon: const Icon(Icons.cancel, size: 18, color: AmialColors.red),
                label: const Text('إلغاء الطلب', style: TextStyle(color: AmialColors.red)),
              )),
          ]),
        );
      }),
    );
  }
}


