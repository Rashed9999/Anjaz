import 'dart:math';

import 'package:flutter/material.dart';

/// AMIAL-NUMPAD-001 — لوحة أرقام بنمط تصاميم أميال (26/29/30):
/// أزرار 1-9 و0 ومسح، تكتب في [controller] مباشرة (مع حدّ أقصى اختياري).
///
/// AMIAL-PIN-UI-002 — [shuffle] يبعثر مواضع الأرقام.
///
/// يُستعمل في شاشات الرمز السري وحدها. السبب: الرمز أربعة أرقام، ومواضع
/// اللوحة ثابتة معروفة للجميع، فمن وقف خلفك في السوق لا يحتاج رؤية الشاشة
/// — تكفيه حركة إصبعك ليستنتج الرمز. بعثرة المواضع تقطع هذا الاستنتاج:
/// الحركة نفسها تعني رمزاً مختلفاً في كل مرّة.
///
/// الثمن أن الإدخال يصير أبطأ لأن العين تبحث عن كل رقم. هذا مقصود ومقبول
/// هنا وحده: أربع ضغطات تحمي رصيداً. ولا يُستعمل في إدخال المبالغ ولا
/// أرقام الهواتف — هناك السرعة أولى ولا سرّ يُحمى.
class AmialNumpad extends StatefulWidget {
  const AmialNumpad({
    super.key,
    required this.controller,
    this.maxLength,
    this.onChanged,
    this.shuffle = false,
  });

  final TextEditingController controller;
  final int? maxLength;
  final ValueChanged<String>? onChanged;

  /// بعثرة مواضع الأرقام — للرمز السري فقط.
  final bool shuffle;

  @override
  State<AmialNumpad> createState() => _AmialNumpadState();
}

class _AmialNumpadState extends State<AmialNumpad> {
  /// ترتيب الأرقام 0-9. يُحسب مرّة عند البناء لا عند كل ضغطة: لوحة تتبدّل
  /// تحت الإصبع بين الرقم والذي يليه تجعل الإدخال مستحيلاً لا آمناً.
  late List<String> _digits = _makeDigits();

  TextEditingController get controller => widget.controller;

  List<String> _makeDigits() {
    final d = List.generate(10, (i) => '$i');
    if (widget.shuffle) d.shuffle(Random.secure());
    return d;
  }

  @override
  void didUpdateWidget(AmialNumpad old) {
    super.didUpdateWidget(old);
    if (old.shuffle != widget.shuffle) _digits = _makeDigits();
  }

  /// يُعاد ترتيب اللوحة — يُستدعى بعد كل محاولة فاشلة.
  void reshuffle() => setState(() => _digits = _makeDigits());

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
    // AMIAL-FIX(RTL): داخل واجهة عربية تنعكس الصفوف فتظهر "3 2 1" —
    // نفرض اتجاه LTR حتى تبقى الأرقام بالترتيب الطبيعي للوحات الهواتف.
    return Directionality(
      textDirection: TextDirection.ltr,
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          for (var row = 0; row < 3; row++)
            Row(children: [
              for (var col = 0; col < 3; col++)
                // مواضع 1..9 تأخذ العناصر 1..9 من الترتيب، ويبقى العنصر 0
                // للصفّ الأخير — فالبعثرة تشمل الأرقام العشرة كلّها.
                _key(context, _digits[row * 3 + col + 1]),
            ]),
          Row(children: [
            // «000» لا معنى له في رمز سري من أربعة أرقام، ووجوده يمنح
            // المتلصّص ضغطةً مميّزة الشكل يقرؤها من بعيد.
            if (widget.shuffle)
              const Expanded(child: SizedBox(height: 58))
            else
              _key(context, '000'),
            _key(context, _digits[0]),
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
