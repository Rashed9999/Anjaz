import 'package:flutter/services.dart';

/// يحوّل الأرقام العربية الشرقية (٠-٩) والفارسية (۰-۹) تلقائياً إلى أرقام ASCII (0-9)
/// لحظة الكتابة أو اللصق، حتى لا تبدو حقول الهاتف ورموز التحقق وPIN «معطّلة»
/// عندما تكون لوحة مفاتيح هاتف العميل مضبوطة على العربية.
class ArabicDigitsNormalizingFormatter extends TextInputFormatter {
  const ArabicDigitsNormalizingFormatter();

  static String normalize(String input) {
    if (input.isEmpty) return input;
    final buffer = StringBuffer();
    for (final rune in input.runes) {
      if (rune >= 0x0660 && rune <= 0x0669) {
        buffer.writeCharCode(0x30 + (rune - 0x0660));
      } else if (rune >= 0x06F0 && rune <= 0x06F9) {
        buffer.writeCharCode(0x30 + (rune - 0x06F0));
      } else {
        buffer.writeCharCode(rune);
      }
    }
    return buffer.toString();
  }

  @override
  TextEditingValue formatEditUpdate(
    TextEditingValue oldValue,
    TextEditingValue newValue,
  ) {
    final normalized = normalize(newValue.text);
    if (normalized == newValue.text) return newValue;
    return newValue.copyWith(
      text: normalized,
      selection: TextSelection.collapsed(
        offset: newValue.selection.extentOffset.clamp(0, normalized.length),
      ),
      composing: TextRange.empty,
    );
  }
}
