import 'package:flutter/services.dart';

class CurrencyTextInputFormatterHelper extends TextInputFormatter {
  final int? decimalDigits;
  final int? maxIntegerDigits;
  final String currencySymbol;
  final bool symbolOnLeft;

  CurrencyTextInputFormatterHelper({
    this.decimalDigits,
    this.maxIntegerDigits,
    required this.currencySymbol,
    this.symbolOnLeft = true,
  });

  @override
  TextEditingValue formatEditUpdate(TextEditingValue oldValue, TextEditingValue newValue) {
    String newText = newValue.text;

    // Remove symbol if user tries to edit it
    String cleanText = newText.replaceAll(currencySymbol, '').trim();

    // Allow empty input
    if (cleanText.isEmpty) {
      return const TextEditingValue(
        text: '',
        selection: TextSelection.collapsed(offset: 0),
      );
    }

    // Allow only digits and dot
    cleanText = cleanText.replaceAll(RegExp(r'[^0-9.]'), '');

    // Split integer and decimal
    List<String> parts = cleanText.split('.');
    String intPart = parts[0];
    String decimalPart = parts.length > 1 ? parts[1] : '';

    // Limit integer digits
    if (maxIntegerDigits != null && intPart.length > maxIntegerDigits!) {
      intPart = intPart.substring(0, maxIntegerDigits);
    }

    // Limit decimal digits
    if (decimalDigits == null || decimalDigits == 0) {
      decimalPart = '';
    } else if (decimalPart.length > decimalDigits!) {
      decimalPart = decimalPart.substring(0, decimalDigits);
    }

    // Combine integer and decimal
    String numberText = decimalPart.isNotEmpty
        ? '$intPart.$decimalPart'
        : (cleanText.endsWith('.') &&
        decimalDigits != null &&
        decimalDigits! > 0
        ? '$intPart.'
        : intPart);

    // ✅ Add currency symbol correctly
    String finalText = symbolOnLeft
        ? '$currencySymbol$numberText'
        : '$numberText$currencySymbol';

    // Maintain correct cursor position
    int newCursorPosition = newValue.selection.baseOffset;
    int logicalPosition = 0;

    for (int i = 0; i < newCursorPosition && i < newText.length; i++) {
      if (RegExp(r'[0-9.]').hasMatch(newText[i])) logicalPosition++;
    }

    int cursorPos = symbolOnLeft ? currencySymbol.length : 0;
    int counted = 0;

    for (int i = 0; i < numberText.length; i++) {
      if (RegExp(r'[0-9.]').hasMatch(numberText[i])) counted++;
      cursorPos++;
      if (counted >= logicalPosition) break;
    }

    if (cursorPos > finalText.length) cursorPos = finalText.length;

    return TextEditingValue(
      text: finalText,
      selection: TextSelection.collapsed(offset: cursorPos),
    );
  }
}