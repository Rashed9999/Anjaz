/// AMIAL-MONEY-002 — تنسيق مبالغ بلا تحويل إلى double.
///
/// المبلغ المالي يبقى نصاً عشرياً من الخادم حتى العرض. هذا يمنع فقدان
/// الدقة في الأرصدة الكبيرة ويجعل شاشة التقارير امتداداً للحقيقة المالية،
/// لا عملية حسابية جديدة داخل الهاتف.
class AmialMoney {
  AmialMoney._();

  /// "600.0000" → "600" ، "1234.5000" → "1,234.5".
  ///
  /// [maxFractionDigits] للعرض فقط؛ لا يغير القيمة الأصلية في الخادم.
  static String fmt(dynamic value, {int maxFractionDigits = 2}) {
    var raw = (value ?? '').toString().trim();
    if (raw.isEmpty) return '0';

    final negative = raw.startsWith('-');
    if (negative || raw.startsWith('+')) raw = raw.substring(1);

    // نقبل الشكل العشري فقط. أي قيمة أخرى تُعرض كما وصلت بدل اختراع رقم.
    if (!RegExp(r'^\d+(?:\.\d+)?$').hasMatch(raw)) {
      return (negative ? '-' : '') + raw;
    }

    final parts = raw.split('.');
    var whole = parts.first.replaceFirst(RegExp(r'^0+(?=\d)'), '');
    if (whole.isEmpty) whole = '0';

    var fraction = parts.length > 1 ? parts[1] : '';
    if (maxFractionDigits >= 0 && fraction.length > maxFractionDigits) {
      final roundUp = fraction.codeUnitAt(maxFractionDigits) >= 53;
      fraction = fraction.substring(0, maxFractionDigits);
      if (roundUp) {
        // نقرّب الخانات العشرية نفسها؛ BigInt يحفظ الدقة حتى على الويب.
        final rounded = (BigInt.parse('$whole$fraction') + BigInt.one)
            .toString()
            .padLeft(maxFractionDigits + 1, '0');
        final split = rounded.length - maxFractionDigits;
        whole = rounded.substring(0, split);
        fraction = rounded.substring(split);
      }
    }
    fraction = fraction.replaceFirst(RegExp(r'0+$'), '');

    final grouped = whole.replaceAllMapped(
      RegExp(r'\B(?=(\d{3})+(?!\d))'),
      (_) => ',',
    );

    final sign = negative && (grouped != '0' || fraction.isNotEmpty) ? '-' : '';
    return '$sign$grouped${fraction.isEmpty ? '' : '.$fraction'}';
  }

  static String yer(dynamic value) => '${fmt(value)} ر.ي';
}
