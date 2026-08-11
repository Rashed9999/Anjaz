import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:get/get.dart';
import 'package:amial_pay/theme/amial_colors.dart';
import 'package:amial_pay/features/requested_money/controllers/payment_request_controller.dart';
import 'package:amial_pay/features/requested_money/screens/payment_request_show_screen.dart';
import 'package:amial_pay/features/setting/controllers/profile_screen_controller.dart';
import 'package:amial_pay/helper/amial_money.dart';
import 'package:amial_pay/common/widgets/amial_button.dart';
import 'package:amial_pay/common/widgets/amial_quick_amounts.dart';

/// AMIAL-PAYMENT-REQUESTS-001 — شاشة إنشاء طلب أموال.
class PaymentRequestCreateScreen extends StatefulWidget {
  /// رقمٌ يأتي جاهزاً — من مسح رمز شخصٍ مثلاً. ويُفحص فوراً فيظهر اسمُه
  /// قبل أن يكتب المستعملُ حرفاً.
  final String? initialPhone;

  const PaymentRequestCreateScreen({super.key, this.initialPhone});

  @override
  State<PaymentRequestCreateScreen> createState() => _PaymentRequestCreateScreenState();
}

class _PaymentRequestCreateScreenState extends State<PaymentRequestCreateScreen> {
  late final PaymentRequestController c;
  final _amount = TextEditingController();
  final _recipientPhone = TextEditingController();
  final _recipientName = TextEditingController();
  final _note = TextEditingController();
  /// **احتياطُ غير المشترك وحدَه.** لا يُقرأ إطلاقاً حين يكون صاحبُ الرقم
  /// على أميال — `_submit` يرسل `direct` حينها، وقائمةُ الاختيار لا تُعرض.
  String _shareMethod = 'link';
  bool _isRecurring = false;
  String _period = 'monthly';

  @override
  void initState() {
    super.initState();
    c = Get.find<PaymentRequestController>();

    // **ولا يُترك الفحصُ السابق يلوّث شاشةً جديدة**: المتحكّم دائمٌ
    // (`fenix`)، فبقاءُ `recipientCheck` من طلبٍ مضى يجعل الشاشةَ تعرض
    // اسمَ شخصٍ لم يُكتب رقمُه بعد.
    c.clearRecipientCheck();

    final seed = widget.initialPhone?.trim() ?? '';

    if (seed.isNotEmpty) {
      _recipientPhone.text = seed;
      WidgetsBinding.instance.addPostFrameCallback((_) => c.checkRecipient(seed));
    }
  }

  @override
  void dispose() {
    // مؤقّتٌ حيٌّ بعد إغلاق الشاشة ينادي متحكّماً قد يكون ذهب.
    _lookupTimer?.cancel();
    _amount.dispose();
    _recipientPhone.dispose();
    _recipientName.dispose();
    _note.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (_amount.text.trim().isEmpty) {
      _snack('أدخل المبلغ');
      return;
    }
    final amount = double.tryParse(_amount.text) ?? 0;
    if (amount <= 0) {
      _snack('المبلغ يجب أن يكون أكبر من صفر');
      return;
    }

    // AMIAL-REQUEST-DIRECT-001: المستلم إلزاميّ.
    //
    // كان اختيارياً وتحته سطرٌ يقول «اتركه فارغاً لجعل الطلب عاماً» — فكان
    // المسار الافتراضي رابطاً يُشارَك باليد، بينما المتوقَّع أن يصل الطلبُ
    // صاحبَه فيوافق أو يرفض. والرابط يبقى، لكن احتياطاً لمن ليس على أميال
    // لا مساراً أوّل.
    final phone = _recipientPhone.text.trim();
    if (phone.isEmpty) {
      _snack('اختر من تطلب منه — أو أدخل رقمه');
      return;
    }
    if (phone.replaceAll(RegExp(r'[^0-9]'), '').length < 9) {
      _snack('رقم الهاتف غير مكتمل');
      return;
    }
    // **ما قاله الخادمُ في الفحص يُحترم هنا** — ولا يُترك للمستعمل أن
    // يضغط «إرسال» فيقرأ الرفضَ بعده.
    if (c.recipientCheck.value?['is_self'] == true) {
      _snack('لا يمكنك طلب مبلغ من نفسك');
      return;
    }

    // الطريقةُ تتبع الحال: مشتركٌ ⇒ مباشر، وغيرُه ⇒ ما اختاره.
    final direct = c.recipientCheck.value?['found'] == true;

    final ok = await c.create(
      amount: _amount.text.trim(),
      recipientPhone: phone,
      recipientName: _recipientName.text.trim().isEmpty ? null : _recipientName.text.trim(),
      note: _note.text.trim().isEmpty ? null : _note.text.trim(),
      shareMethod: direct ? 'direct' : _shareMethod,
      isRecurring: _isRecurring,
      recurringPeriod: _isRecurring ? _period : null,
    );
    if (!mounted) return;
    if (ok) {
      Get.off(() => const PaymentRequestShowScreen());
    } else {
      _snack(c.lastError.value.isEmpty ? 'فشل إنشاء الطلب' : c.lastError.value);
    }
  }

  void _snack(String m) => ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(m), backgroundColor: AmialColors.red),
      );

  // ══════════════════════════════════════════════════════════════════
  //  AMIAL-REQUEST-DIRECT-002 — **الشاشةُ تقول ما سيحدث قبل أن يحدث.**
  //
  //  كان الطالبُ يكتب الرقم ويضغط «إرسال» ثمّ يكتشف — من شاشة النتيجة —
  //  أنّ ما أُنشئ رابطٌ عليه أن يُوصله بيده. فصار السؤالُ أثناء الكتابة،
  //  والجوابُ تحت الحقل مباشرةً.
  //
  //  **وبمهلة**: السؤالُ عند كلّ حرفٍ يُرسل تسعةَ طلباتٍ لرقمٍ واحد،
  //  ويصطدم بحدّ المعدّل، ويُظهر إجاباتٍ متضاربةً تتسابق. فيُنتظر
  //  نصفُ ثانيةٍ بعد آخر حرف.
  // ══════════════════════════════════════════════════════════════════

  Timer? _lookupTimer;

  void _onPhoneChanged(String v) {
    _lookupTimer?.cancel();
    c.clearRecipientCheck();
    setState(() {});   // معاينةُ الطلب تتبع الرقم

    final digits = v.replaceAll(RegExp(r'[^0-9]'), '');
    if (digits.length < 9) return;

    _lookupTimer = Timer(const Duration(milliseconds: 500),
        () => c.checkRecipient(v.trim()));
  }

  /// حالةُ الرقم تحت الحقل — **والغيابُ يُقال لا يُترك فراغاً**.
  Widget _recipientStatus() {
    return Obx(() {
      if (c.isChecking.value) {
        return const Padding(
          padding: EdgeInsets.symmetric(vertical: 4),
          child: Row(mainAxisAlignment: MainAxisAlignment.end, children: [
            Text('نتحقّق من الرقم…', style: TextStyle(fontSize: 11, color: Colors.grey)),
            SizedBox(width: 8),
            SizedBox(width: 12, height: 12, child: CircularProgressIndicator(strokeWidth: 2)),
          ]),
        );
      }

      final r = c.recipientCheck.value;

      if (r == null) {
        // لم يُسأل بعد — لا يُدَّعى شيء. (القاعدة ٧: «غير معروف» ليس صفراً.)
        return Text(
          'اكتب الرقم كاملاً لنعرف إن كان على أميال',
          textAlign: TextAlign.right,
          style: TextStyle(fontSize: 11, color: Colors.grey.shade600),
        );
      }

      final found = r['found'] == true;
      final isSelf = r['is_self'] == true;
      final name = (r['name'] ?? '').toString();
      final hint = (r['hint'] ?? '').toString();

      final color = isSelf
          ? AmialColors.red
          : (found ? Colors.green.shade700 : Colors.orange.shade800);
      final icon = isSelf
          ? Icons.block
          : (found ? Icons.verified_user : Icons.link);

      return Container(
        margin: const EdgeInsets.only(top: 4),
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
        decoration: BoxDecoration(
          color: color.withValues(alpha: 0.08),
          borderRadius: BorderRadius.circular(10),
          border: Border.all(color: color.withValues(alpha: 0.35)),
        ),
        child: Row(children: [
          Icon(icon, size: 16, color: color),
          const SizedBox(width: 8),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.end,
              mainAxisSize: MainAxisSize.min,
              children: [
                if (found && name.isNotEmpty)
                  Text(name, textAlign: TextAlign.right,
                      style: TextStyle(fontSize: 13, fontWeight: FontWeight.bold, color: color)),
                Text(hint, textAlign: TextAlign.right,
                    style: TextStyle(fontSize: 11, color: color)),
              ],
            ),
          ),
        ]),
      );
    });
  }


  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(
        title: const Text('طلب أموال'),
      ),
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(16),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          // المبلغ
          const Text('المبلغ المطلوب', textAlign: TextAlign.right,
              style: TextStyle(fontSize: 14, fontWeight: FontWeight.w600)),
          const SizedBox(height: 6),
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 4),
            decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(12)),
            child: Row(children: [
              const Text('ر.ي', style: TextStyle(fontSize: 16, color: Colors.grey, fontWeight: FontWeight.bold)),
              const SizedBox(width: 12),
              Expanded(child: TextField(
                controller: _amount,
                keyboardType: TextInputType.number,
                textAlign: TextAlign.right,
                inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                style: const TextStyle(fontSize: 28, fontWeight: FontWeight.bold, color: AmialColors.primary),
                decoration: const InputDecoration(border: InputBorder.none, hintText: '0'),
                onChanged: (_) => setState(() {}), // تحديث معاينة الطلب
              )),
            ]),
          ),
          const SizedBox(height: 10),
          // AMIAL-DS-001: مبالغ سريعة بضغطة (المكوّن الموحّد).
          AmialQuickAmounts(
            values: const [500, 1000, 2000, 5000, 10000, 20000],
            onPick: (v) {
              _amount.text = v.toString();
              setState(() {});
            },
          ),
          const SizedBox(height: 20),

          // من تطلب منه — إلزاميّ
          const Text('من تطلب منه', textAlign: TextAlign.right,
              style: TextStyle(fontSize: 14, fontWeight: FontWeight.w600)),
          const SizedBox(height: 6),
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 12),
            decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(12)),
            child: Column(children: [
              TextField(
                controller: _recipientPhone,
                keyboardType: TextInputType.phone,
                textAlign: TextAlign.right,
                decoration: const InputDecoration(
                  border: InputBorder.none,
                  hintText: 'رقم الهاتف +967...',
                  prefixIcon: Icon(Icons.phone, size: 20),
                ),
                onChanged: _onPhoneChanged,
              ),
              const Divider(height: 1),
              TextField(
                controller: _recipientName,
                textAlign: TextAlign.right,
                decoration: const InputDecoration(
                  border: InputBorder.none,
                  hintText: 'اسم المستلم',
                  prefixIcon: Icon(Icons.person, size: 20),
                ),
              ),
            ]),
          ),
          const SizedBox(height: 6),
          _recipientStatus(),
          const SizedBox(height: 20),

          // الملاحظة
          const Text('ملاحظة', textAlign: TextAlign.right,
              style: TextStyle(fontSize: 14, fontWeight: FontWeight.w600)),
          const SizedBox(height: 6),
          TextField(
            controller: _note,
            textAlign: TextAlign.right,
            maxLines: 2,
            decoration: InputDecoration(
              hintText: 'مثال: فاتورة الكهرباء',
              filled: true,
              fillColor: Colors.white,
              border: OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: BorderSide.none),
            ),
            onChanged: (_) => setState(() {}), // تحديث معاينة الطلب
          ),
          const SizedBox(height: 20),

          // ══════════════════════════════════════════════════════════
          //  طريقةُ الإيصال — **لا تُعرض إلّا حين تكون هناك خيارات.**
          //
          //  كانت الشاشةُ تسأل «رابط أم QR؟» دائماً — حتّى حين يكون
          //  المستلمُ مشتركاً معروفاً ولا حاجة لأيّهما. فصار السؤالُ
          //  يظهر لغير المشترك وحدَه، ومع المشترك تُقال النتيجة لا
          //  يُطلب اختيار.
          // ══════════════════════════════════════════════════════════
          Obx(() {
            final found = c.recipientCheck.value?['found'] == true;

            if (found) {
              return const SizedBox.shrink();
            }

            return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              const Text('طريقة المشاركة', textAlign: TextAlign.right,
                  style: TextStyle(fontSize: 14, fontWeight: FontWeight.w600)),
              const SizedBox(height: 6),
              Row(children: [
                Expanded(child: _methodTile('link', Icons.link, 'رابط')),
                const SizedBox(width: 8),
                Expanded(child: _methodTile('qr', Icons.qr_code_2, 'رمز QR')),
              ]),
              const SizedBox(height: 20),
            ]);
          }),

          // التكرار
          SwitchListTile(
            tileColor: Colors.white,
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
            value: _isRecurring,
            onChanged: (v) => setState(() => _isRecurring = v),
            title: const Text('تكرار هذا الطلب', textAlign: TextAlign.right),
            subtitle: const Text('فواتير دورية', textAlign: TextAlign.right, style: TextStyle(fontSize: 12)),
            activeThumbColor: AmialColors.primary,
          ),
          if (_isRecurring) ...[
            const SizedBox(height: 8),
            Row(children: [
              Expanded(child: _periodChip('daily', 'يومي')),
              const SizedBox(width: 8),
              Expanded(child: _periodChip('weekly', 'أسبوعي')),
              const SizedBox(width: 8),
              Expanded(child: _periodChip('monthly', 'شهري')),
            ]),
          ],
          const SizedBox(height: 24),

          // AMIAL-DESIGN: «معاينة الطلب» — بطاقة حيّة تتحدّث مع الكتابة
          const Text('معاينة الطلب', textAlign: TextAlign.right,
              style: TextStyle(fontSize: 14, fontWeight: FontWeight.w600)),
          const SizedBox(height: 8),
          _previewCard(),
          const SizedBox(height: 24),

          // زر الإنشاء — موحّد عبر AmialButton (DS)
          Obx(() => AmialButton(
                label: 'إنشاء الطلب',
                icon: Icons.send,
                loading: c.isSubmitting.value,
                onPressed: c.isSubmitting.value ? null : _submit,
              )),
        ]),
      ),
    );
  }

  /// بطاقة معاينة الطلب (تدرّج أخضر داكن + الاسم + المبلغ + الملاحظة + الرابط).
  Widget _previewCard() {
    String requester = 'أنا';
    try {
      final u = Get.find<ProfileController>().userInfo;
      final n = ('${u?.fName ?? ''} ${u?.lName ?? ''}').trim();
      if (n.isNotEmpty) requester = n;
    } catch (_) {}
    final amount = _amount.text.trim();
    final note = _note.text.trim();

    return Container(
      padding: const EdgeInsets.all(20),
      decoration: BoxDecoration(
        gradient: const LinearGradient(
          begin: Alignment.topRight,
          end: Alignment.bottomLeft,
          colors: [Color(0xFF053391), Color(0xFF1D4FB8)],
        ),
        borderRadius: BorderRadius.circular(20),
      ),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Row(children: [
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
            decoration: BoxDecoration(
              border: Border.all(color: const Color(0xFFFECA1E)),
              borderRadius: BorderRadius.circular(8),
            ),
            child: const Text('AMIAL PAY',
                style: TextStyle(
                    color: Color(0xFFFECA1E),
                    fontSize: 10,
                    fontWeight: FontWeight.bold,
                    letterSpacing: 1)),
          ),
          const Spacer(),
          Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
            const Text('طلب دفع من',
                style: TextStyle(color: Colors.white70, fontSize: 10)),
            Text(requester,
                style: const TextStyle(
                    color: Colors.white,
                    fontSize: 14,
                    fontWeight: FontWeight.bold)),
          ]),
          const SizedBox(width: 10),
          const CircleAvatar(
            radius: 18,
            backgroundColor: Colors.white24,
            child: Icon(Icons.payments_outlined, color: Colors.white, size: 18),
          ),
        ]),
        const SizedBox(height: 18),
        Row(
          mainAxisAlignment: MainAxisAlignment.center,
          crossAxisAlignment: CrossAxisAlignment.end,
          children: [
            const Padding(
              padding: EdgeInsets.only(bottom: 6),
              child: Text(' ر.ي',
                  style: TextStyle(color: Colors.white70, fontSize: 14)),
            ),
            Text(amount.isEmpty ? '0' : AmialMoney.fmt(amount),
                style: const TextStyle(
                    color: Colors.white,
                    fontSize: 40,
                    fontWeight: FontWeight.bold)),
          ],
        ),
        if (note.isNotEmpty) ...[
          const SizedBox(height: 4),
          Text(note,
              textAlign: TextAlign.center,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: const TextStyle(color: Colors.white70, fontSize: 13)),
        ],
        const SizedBox(height: 16),
        Container(
          padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
          decoration: BoxDecoration(
            color: Colors.white.withValues(alpha: 0.12),
            borderRadius: BorderRadius.circular(12),
          ),
          // **المعاينةُ تعرض ما سيقع فعلاً لا رابطاً دائماً.**
          //
          // كانت تعرض `amial.pay/req/xxxxx` حتّى حين يكون المستلم مشتركاً
          // ولا رابطَ في الأمر — فتُرسّخ في ذهن الطالب أنّ «طلب المال»
          // رابطٌ يُشارَك، وهي بالضبط الشكوى.
          child: Obx(() {
            final found = c.recipientCheck.value?['found'] == true;
            final name = (c.recipientCheck.value?['name'] ?? '').toString();

            final icon = found
                ? Icons.send_rounded
                : (_shareMethod == 'qr' ? Icons.qr_code_2 : Icons.link);

            final text = found
                ? 'يصل إلى ${name.isEmpty ? 'صاحب الرقم' : name} فوراً — يوافق أو يرفض'
                : (_shareMethod == 'qr'
                    ? 'سيظهر رمز QR بعد الإنشاء'
                    : '...amial.pay/req/xxxxx — يظهر الرابط بعد الإنشاء');

            return Row(children: [
              Icon(icon, color: const Color(0xFFFECA1E), size: 18),
              const SizedBox(width: 10),
              Expanded(
                child: Text(
                  text,
                  textDirection: found ? TextDirection.rtl : TextDirection.ltr,
                  textAlign: found ? TextAlign.right : TextAlign.left,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: TextStyle(
                      color: Colors.white,
                      fontSize: 12,
                      fontFamily: found ? null : 'monospace'),
                ),
              ),
            ]);
          }),
        ),
      ]),
    );
  }

  Widget _methodTile(String value, IconData icon, String label) {
    final selected = _shareMethod == value;
    return InkWell(
      borderRadius: BorderRadius.circular(12),
      onTap: () => setState(() => _shareMethod = value),
      child: Container(
        padding: const EdgeInsets.symmetric(vertical: 14),
        decoration: BoxDecoration(
          color: selected ? AmialColors.primary : Colors.white,
          borderRadius: BorderRadius.circular(12),
          border: Border.all(color: selected ? AmialColors.primary : Colors.grey.shade300, width: selected ? 2 : 1),
        ),
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          Icon(icon, color: selected ? Colors.white : AmialColors.primary),
          const SizedBox(height: 4),
          Text(label, style: TextStyle(color: selected ? Colors.white : Colors.black87, fontWeight: FontWeight.bold)),
        ]),
      ),
    );
  }

  Widget _periodChip(String value, String label) {
    final selected = _period == value;
    return InkWell(
      borderRadius: BorderRadius.circular(20),
      onTap: () => setState(() => _period = value),
      child: Container(
        padding: const EdgeInsets.symmetric(vertical: 10),
        decoration: BoxDecoration(
          color: selected ? AmialColors.yellow : Colors.white,
          borderRadius: BorderRadius.circular(20),
          border: Border.all(color: selected ? AmialColors.yellow : Colors.grey.shade300),
        ),
        child: Text(label, textAlign: TextAlign.center,
            style: TextStyle(color: Colors.black87, fontWeight: selected ? FontWeight.bold : FontWeight.normal)),
      ),
    );
  }
}
