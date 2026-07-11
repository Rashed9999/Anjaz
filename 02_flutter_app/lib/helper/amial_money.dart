/// AMIAL-MONEY-001 — تنسيق مبالغ نظيف.
///
/// الخادم يُرجع المبالغ بأربع خانات عشرية ("600.0000") فتبدو غير منطقية.
/// هذا المساعد يعرضها بشكل بشريّ: أعداد صحيحة بلا كسور + فواصل آلاف،
/// والكسور بخانتين فقط عند وجودها. (بلا رمز العملة — يُضاف بعده.)
class AmialMoney {
  AmialMoney._();

  /// "600.0000" → "600" ، "1234.5000" → "1,234.5" ، "1234567" → "1,234,567"
  static String fmt(dynamic value) {
    final raw = (value ?? '').toString().trim();
    if (raw.isEmpty) return '0';
    final n = double.tryParse(raw);
    if (n == null) return raw;

    // كسر بخانتين كحدّ أقصى، مع إزالة الأصفار الزائدة
    var s = n.toStringAsFixed(2);
    if (s.contains('.')) {
      s = s.replaceAll(RegExp(r'0+$'), '');
      s = s.replaceAll(RegExp(r'\.$'), '');
    }

    // فواصل آلاف للجزء الصحيح
    final parts = s.split('.');
    parts[0] = parts[0].replaceAllMapped(
      RegExp(r'\B(?=(\d{3})+(?!\d))'),
      (m) => ',',
    );
    return parts.join('.');
  }

  /// مع رمز الريال اليمني: "600 ر.ي"
  static String yer(dynamic value) => '${fmt(value)} ر.ي';
}
