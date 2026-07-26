import 'package:flutter/material.dart';

/// AMIAL-NUMPAD-001 — لوحة أرقام بنمط تصاميم أميال (26/29/30):
/// أزرار 1-9 و0 ومسح، تكتب في [controller] مباشرة (مع حدّ أقصى اختياري).
///
/// AMIAL-PIN-UI-003 — [rtl] يجعل الترتيب من اليمين إلى اليسار.
///
/// **لماذا لا بعثرة:** جُرّبت بعثرة المواضع في شاشات الرمز (إجراء بنكيّ
/// معروف ضدّ من يقرأ حركة الإصبع من بعيد) فوجدها المستخدمون معطِّلة: من
/// حفظ رمزه بحركة يده — وهم الأكثر — يفقد ذلك تماماً، فيبطؤ الإدخال ويكثر
/// الخطأ. أمانٌ يدفع ثمنه كل عميل في كل عملية، مقابل تهديد نادر، صفقة
/// خاسرة. النقاط **** تحجب الأرقام أصلاً، وثلاث محاولات تُقفل الحساب —
/// وهما الحمايتان اللتان تعملان بلا كلفة على أحد.
///
/// الترتيب ثابت إذاً، لكن باتجاه القراءة العربية: 1 في أعلى اليمين. تُبنى
/// الذاكرة العضلية ولا تُكسر.
class AmialNumpad extends StatefulWidget {
  const AmialNumpad({
    super.key,
    required this.controller,
    this.maxLength,
    this.onChanged,
    this.rtl = false,
  });

  final TextEditingController controller;
  final int? maxLength;
  final ValueChanged<String>? onChanged;

  /// ترتيب من اليمين إلى اليسار (1 في أعلى اليمين) — لشاشات الرمز السري.
  /// يبقى الافتراضي 1 2 3 لبقيّة الاستعمالات كي لا تتبدّل لوحة مألوفة.
  final bool rtl;

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
    // AMIAL-FIX(RTL): داخل واجهة عربية تنعكس الصفوف تلقائياً. نفرض الاتجاه
    // صراحةً بدل ترك المحيط يقرّره، فيبقى الترتيب واحداً أينما وُضعت اللوحة.
    return Directionality(
      textDirection: widget.rtl ? TextDirection.rtl : TextDirection.ltr,
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          for (var row = 0; row < 3; row++)
            Row(children: [
              for (var col = 1; col <= 3; col++) _key(context, '${row * 3 + col}'),
            ]),
          Row(children: [
            // «000» لا معنى له في رمز من أربعة أرقام، ووجوده يمنح المتلصّص
            // ضغطةً مميّزة الشكل يقرؤها من بعيد. يبقى لإدخال المبالغ.
            if (widget.rtl)
              const Expanded(child: SizedBox(height: 58))
            else
              _key(context, '000'),
            _key(context, '0'),
            _key(context, '',
                onTap: _backspace,
                child: const Icon(Icons.backspace_outlined,
                    color: Color(0xFFDC0A0B), size: 22)),
          ]),
        ],
      ),
    );
  }
}
