import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:get/get.dart';
import 'package:amial_pay/features/safe_payment/controllers/safe_payment_controller.dart';
import 'package:amial_pay/theme/amial_colors.dart';

/// AMIAL-SAFEPAY-CODE-001 — رمز تأكيد التسليم في الواجهة.
///
/// الفكرة كلها في سطر: المشتري يملك رمزاً، والبائع لا يستطيع تأكيد التسليم
/// بلا الرمز، والمشتري لا يعطيه إلا وقد استلم. فالتسليم يصير حدثاً بطرفين
/// لا ادّعاءً من طرف — وهو ما يُسقط أكثر النزاعات من أصلها.
///
/// **قراران في الواجهة:**
///   - الرمز يُعرض كبيراً مفصولاً: يُقرأ صوتاً في السوق ووسط الضجيج.
///   - تحذير صريح للمشتري: «لا تعطه قبل أن تفتح وتتأكّد». الرمز حمايته،
///     ومن يعطيه مبكّراً يتنازل عنها وهو لا يدري.
class BuyerDeliveryCodeCard extends StatelessWidget {
  const BuyerDeliveryCodeCard({super.key, required this.code});

  final String code;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: AmialColors.yellowDark.withValues(alpha: 0.5), width: 1.4),
      ),
      child: Column(children: [
        const Row(children: [
          Icon(Icons.pin_rounded, color: AmialColors.yellowDark, size: 20),
          SizedBox(width: 8),
          Text('رمز تأكيد الاستلام',
              style: TextStyle(fontWeight: FontWeight.bold, fontSize: 14)),
        ]),
        const SizedBox(height: 4),
        const Align(
          alignment: Alignment.centerRight,
          child: Text(
            'أعطِ هذا الرمز للبائع لحظة استلامك — لن يستطيع تأكيد التسليم بدونه.',
            style: TextStyle(fontSize: 11.5, height: 1.6, color: AmialColors.textSecondary),
          ),
        ),
        const SizedBox(height: 14),

        InkWell(
          onTap: () {
            Clipboard.setData(ClipboardData(text: code));
            ScaffoldMessenger.of(context).showSnackBar(
              const SnackBar(
                content: Text('نُسخ الرمز'),
                duration: Duration(seconds: 2),
              ),
            );
          },
          borderRadius: BorderRadius.circular(10),
          child: Container(
            width: double.infinity,
            padding: const EdgeInsets.symmetric(vertical: 14),
            decoration: BoxDecoration(
              color: const Color(0xFFFFF8E1),
              borderRadius: BorderRadius.circular(10),
            ),
            child: Column(children: [
              Text(
                _spaced(code),
                textAlign: TextAlign.center,
                textDirection: TextDirection.ltr,
                style: const TextStyle(
                  fontSize: 30,
                  fontWeight: FontWeight.bold,
                  letterSpacing: 5,
                  color: AmialColors.primary,
                ),
              ),
              const SizedBox(height: 4),
              const Text('اضغط للنسخ',
                  style: TextStyle(fontSize: 10, color: AmialColors.textMuted)),
            ]),
          ),
        ),

        const SizedBox(height: 12),
        Container(
          padding: const EdgeInsets.all(10),
          decoration: BoxDecoration(
            color: AmialColors.red.withValues(alpha: 0.06),
            borderRadius: BorderRadius.circular(8),
          ),
          child: const Row(children: [
            Icon(Icons.warning_amber_rounded, size: 17, color: AmialColors.red),
            SizedBox(width: 8),
            Expanded(
              child: Text(
                'لا تُعطِ الرمز قبل أن تفتح الطرد وتتأكّد من محتواه. '
                'إعطاؤه يعني إقرارك بالاستلام.',
                style: TextStyle(fontSize: 11, height: 1.6, color: AmialColors.red),
              ),
            ),
          ]),
        ),
      ]),
    );
  }

  /// فراغ كل ثلاثة: العين تلتقط 481 037 أسرع من 481037، والصوت كذلك.
  static String _spaced(String raw) {
    if (raw.length <= 4) return raw;
    final out = StringBuffer();
    for (var i = 0; i < raw.length; i++) {
      if (i > 0 && i % 3 == 0) out.write(' ');
      out.write(raw[i]);
    }
    return out.toString();
  }
}

/// إدخال الرمز عند البائع.
class SellerDeliveryCodeEntry extends StatefulWidget {
  const SellerDeliveryCodeEntry({super.key, required this.ulid});

  final String ulid;

  @override
  State<SellerDeliveryCodeEntry> createState() => _SellerDeliveryCodeEntryState();
}

class _SellerDeliveryCodeEntryState extends State<SellerDeliveryCodeEntry> {
  final _code = TextEditingController();
  bool _busy = false;

  @override
  void dispose() {
    _code.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    final code = _code.text.trim();
    if (code.length < 4) return;

    setState(() => _busy = true);
    final ctrl = Get.find<SafePaymentController>();
    final ok = await ctrl.verifyDelivery(widget.ulid, code);
    if (!mounted) return;
    setState(() => _busy = false);

    if (ok) _code.clear();
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
      content: Text(ok ? 'تم تأكيد التسليم برمز المشتري ✓' : ctrl.lastError.value),
      backgroundColor: ok ? Colors.green.shade700 : AmialColors.red,
    ));
  }

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: AmialColors.border),
      ),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        const Row(children: [
          Icon(Icons.password_rounded, color: AmialColors.primary, size: 20),
          SizedBox(width: 8),
          Text('تأكيد التسليم بالرمز',
              style: TextStyle(fontWeight: FontWeight.bold, fontSize: 14)),
        ]),
        const SizedBox(height: 4),
        const Text(
          'اطلب من المشتري رمز الاستلام الظاهر في تطبيقه وأدخله هنا. '
          'التسليم المؤكَّد برمز لا يُنازَع فيه.',
          style: TextStyle(fontSize: 11.5, height: 1.6, color: AmialColors.textSecondary),
        ),
        const SizedBox(height: 12),
        Row(children: [
          Expanded(
            child: TextField(
              controller: _code,
              enabled: !_busy,
              keyboardType: TextInputType.number,
              textDirection: TextDirection.ltr,
              textAlign: TextAlign.center,
              maxLength: 12,
              inputFormatters: [FilteringTextInputFormatter.digitsOnly],
              style: const TextStyle(
                  fontSize: 22, fontWeight: FontWeight.bold, letterSpacing: 4),
              decoration: const InputDecoration(
                counterText: '',
                hintText: '••••••',
                border: OutlineInputBorder(),
              ),
              onChanged: (_) => setState(() {}),
            ),
          ),
          const SizedBox(width: 10),
          SizedBox(
            height: 56,
            child: ElevatedButton(
              onPressed: _busy || _code.text.trim().length < 4 ? null : _submit,
              style: ElevatedButton.styleFrom(
                backgroundColor: AmialColors.primary,
                foregroundColor: Colors.white,
              ),
              child: _busy
                  ? const SizedBox(
                      width: 18,
                      height: 18,
                      child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                  : const Text('تأكيد'),
            ),
          ),
        ]),
        const Text(
          'ثلاث محاولات فقط — بعدها يلزم تدخّل الدعم.',
          style: TextStyle(fontSize: 10.5, color: AmialColors.textMuted),
        ),
      ]),
    );
  }
}
