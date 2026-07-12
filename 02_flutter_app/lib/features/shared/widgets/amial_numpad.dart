import 'package:flutter/material.dart';

/// AMIAL-NUMPAD-001 — لوحة أرقام بنمط تصاميم أميال (26/29/30):
/// أزرار 1-9 و0 ومسح، تكتب في [controller] مباشرة (مع حدّ أقصى اختياري).
class AmialNumpad extends StatelessWidget {
  const AmialNumpad({
    super.key,
    required this.controller,
    this.maxLength,
    this.onChanged,
  });

  final TextEditingController controller;
  final int? maxLength;
  final ValueChanged<String>? onChanged;

  void _tap(String d) {
    var next = controller.text + d;
    if (maxLength != null && next.length > maxLength!) {
      next = next.substring(0, maxLength!);
    }
    if (next == controller.text) return;
    controller.text = next;
    onChanged?.call(controller.text);
  }

  void _backspace() {
    final t = controller.text;
    if (t.isEmpty) return;
    controller.text = t.substring(0, t.length - 1);
    onChanged?.call(controller.text);
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
                            color: Color(0xFF14342A))),
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
          Row(children: [
            _key(context, '1'),
            _key(context, '2'),
            _key(context, '3'),
          ]),
          Row(children: [
            _key(context, '4'),
            _key(context, '5'),
            _key(context, '6'),
          ]),
          Row(children: [
            _key(context, '7'),
            _key(context, '8'),
            _key(context, '9'),
          ]),
          Row(children: [
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
