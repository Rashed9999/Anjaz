import 'package:flutter/material.dart';

/// AMIAL-NUMPAD-001 — لوحة أرقام بنمط تصاميم أميال (26/29/30):
/// أزرار 1-9 و0 ومسح، تكتب في [controller] مباشرة (مع حدّ أقصى اختياري).
///
/// AMIAL-PIN-UI-004 — الترتيب واحد في كل مكان: 1 في أعلى اليسار.
///
/// **جُرّب بديلان وسقطا، ويُذكران كي لا يُعادا:**
///
///   البعثرة العشوائية (إجراء بنكيّ ضدّ من يقرأ حركة الإصبع من بعيد):
///   من حفظ رمزه بحركة يده — وهم الأكثر — يفقد ذلك تماماً، فيبطؤ الإدخال
///   ويكثر الخطأ. أمانٌ يدفع ثمنه كل عميل في كل عملية مقابل تهديد نادر.
///
///   الترتيب المعكوس (1 في أعلى اليمين، اتّباعاً لاتجاه القراءة العربية):
///   منسجم مع الواجهة لكنه يخالف لوحة الاتصال التي يستعملها العميل نفسه
///   كل يوم. والانسجام مع بقيّة التطبيق لا يساوي مخالفة ما في يده.
///
/// فالترتيب القياسي إذاً — لا لأنه أجمل، بل لأنه الوحيد الذي لا يُطلب من
/// المستخدم أن يتعلّمه. والحمايتان اللتان لا يدفع ثمنهما أحد باقيتان:
/// النقاط **** تحجب الأرقام، وثلاث محاولات تُقفل الحساب.
class AmialNumpad extends StatefulWidget {
  const AmialNumpad({
    super.key,
    required this.controller,
    this.maxLength,
    this.onChanged,
    this.compact = false,
  });

  final TextEditingController controller;
  final int? maxLength;
  final ValueChanged<String>? onChanged;

  /// يستبدل زرّ «000» بزرّ «مسح» — لشاشات الرمز السري.
  ///
  /// «000» لا معنى له في رمز من أربعة أرقام، ووجوده يمنح المتلصّص ضغطةً
  /// مميّزة الشكل يقرؤها من بعيد. يبقى في إدخال المبالغ حيث يوفّر ضغطات
  /// حقيقية.
  ///
  /// ══════════════════════════════════════════════════════════════════
  /// **AMIAL-NUMPAD-CLEAR-001 — خانةٌ فارغةٌ ليست تصميماً.**
  ///
  /// قال صاحبُ المشروع: «لوحةُ إدخال رمز Pin هناك **خانةٌ ناقصة** أحتاج
  /// إلى تكملتها من أجل لوحةٍ نظيفة».
  ///
  /// وكان `compact` يرسم `SizedBox` فارغاً مكانَ «000»: فجوةٌ في الصفّ
  /// الأخير تُقرأ **زرّاً معطَّلاً** لا فراغاً مقصوداً — والمستعمل يضغطها
  /// فلا يحدث شيء، وهو بعينه ما تحاربه القاعدة التاسعة.
  ///
  /// **والبديلُ ليس حشواً**: «مسح» يُفرِغ الرمزَ كلَّه بضغطةٍ واحدة، وهو
  /// ما يحتاجه من أخطأ في أوّل رقمٍ من أربعة — بدل أربع ضغطاتٍ على
  /// التراجع. فتكتمل اللوحةُ بفعلٍ حقيقيّ، لا بمربّعٍ يملأ الفراغ.
  ///
  /// **ولا يُوضَع «موافق»**: الشاشاتُ تُرسِل تلقائيّاً عند اكتمال الرمز،
  /// فزرُّ تأكيدٍ لا يُضغط أبداً هو الفراغُ نفسُه بثوبٍ جديد.
  final bool compact;

  @override
  State<AmialNumpad> createState() => _AmialNumpadState();
}

class _AmialNumpadState extends State<AmialNumpad> {
  TextEditingController get controller => widget.controller;

  void _tap(String d) {
    var next = controller.text + d;
    final max = widget.maxLength;
    if (max != null && next.length > max) {
      next = next.substring(0, max);
    }
    if (next == controller.text) return;
    controller.text = next;
    widget.onChanged?.call(controller.text);
  }

  void _backspace() {
    final t = controller.text;
    if (t.isEmpty) return;
    controller.text = t.substring(0, t.length - 1);
    widget.onChanged?.call(controller.text);
  }

  /// **مسحُ الرمز كلِّه** — ومن أخطأ في أوّل رقمٍ من أربعةٍ لا يضغط
  /// التراجعَ أربعاً. ولا يُنادى المستمعُ على لوحةٍ فارغةٍ أصلاً.
  void _clearAll() {
    if (controller.text.isEmpty) return;
    controller.text = '';
    widget.onChanged?.call('');
  }

  Widget _key(BuildContext context, String label, {VoidCallback? onTap, Widget? child}) {
    return Expanded(
      child: Padding(
        padding: const EdgeInsets.all(5),
        child: Material(
          color: Colors.white,
          borderRadius: BorderRadius.circular(12),
          child: InkWell(
            borderRadius: BorderRadius.circular(12),
            onTap: onTap ?? () => _tap(label),
            child: SizedBox(
              height: 58,
              child: Center(
                child: child ??
                    Text(label,
                        style: const TextStyle(
                            fontSize: 22,
                            fontWeight: FontWeight.w600,
                            color: Color(0xFF053391))),
              ),
            ),
          ),
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    // داخل واجهة عربية تنعكس الصفوف تلقائياً فتظهر «3 2 1». نفرض LTR
    // صراحةً بدل ترك المحيط يقرّره، فيبقى الترتيب واحداً أينما وُضعت اللوحة.
    return Directionality(
      textDirection: TextDirection.ltr,
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          for (var row = 0; row < 3; row++)
            Row(children: [
              for (var col = 1; col <= 3; col++) _key(context, '${row * 3 + col}'),
            ]),
          Row(children: [
            if (widget.compact)
              // **لا فجوةَ في الصفّ** — تُقرأ زرّاً معطَّلاً، ومن ضغطها
              // لم يحدث شيءٌ ولا رسالة. (AMIAL-NUMPAD-CLEAR-001)
              _key(context, '',
                  onTap: _clearAll,
                  child: const Text('مسح',
                      key: Key('numpad-clear'),
                      style: TextStyle(
                          fontSize: 16,
                          fontWeight: FontWeight.w600,
                          color: Color(0xFFDC0A0B))))
            else
              _key(context, '000'),
            _key(context, '0'),
            _key(context, '',
                onTap: _backspace,
                child: const Icon(Icons.backspace_outlined,
                    key: Key('numpad-backspace'),
                    color: Color(0xFFDC0A0B), size: 22)),
          ]),
        ],
      ),
    );
  }
}
