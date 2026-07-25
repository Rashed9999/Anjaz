/// AMIAL-RECEIPT-TAFQIT-001 — تفقيط المبالغ (كتابة الرقم بالحروف العربية).
///
/// معيار مصرفي لا زينة: الرقم وحده قابل للتحريف بإضافة خانة أو نقل فاصلة،
/// أما المبلغ مكتوباً بالحروف فيُثبّت القيمة. كل إيصال بنكي يمني يحمله،
/// ولم يكن في إيصالاتنا.
///
/// يغطّي 0 حتى 999,999,999 وهو أكثر من كافٍ لسقوف المحفظة.
class ArabicNumberWords {
  ArabicNumberWords._();

  static const _ones = [
    '', 'واحد', 'اثنان', 'ثلاثة', 'أربعة', 'خمسة',
    'ستة', 'سبعة', 'ثمانية', 'تسعة',
  ];

  static const _tens = [
    '', 'عشرة', 'عشرون', 'ثلاثون', 'أربعون', 'خمسون',
    'ستون', 'سبعون', 'ثمانون', 'تسعون',
  ];

  static const _teens = [
    'عشرة', 'أحد عشر', 'اثنا عشر', 'ثلاثة عشر', 'أربعة عشر',
    'خمسة عشر', 'ستة عشر', 'سبعة عشر', 'ثمانية عشر', 'تسعة عشر',
  ];

  static const _hundreds = [
    '', 'مائة', 'مائتان', 'ثلاثمائة', 'أربعمائة', 'خمسمائة',
    'ستمائة', 'سبعمائة', 'ثمانمائة', 'تسعمائة',
  ];

  /// يصوغ خانات المئات (1..999).
  static String _under1000(int n) {
    final parts = <String>[];
    final h = n ~/ 100;
    final rest = n % 100;

    if (h > 0) parts.add(_hundreds[h]);

    if (rest >= 10 && rest < 20) {
      parts.add(_teens[rest - 10]);
    } else {
      final t = rest ~/ 10;
      final o = rest % 10;
      // العربية تقدّم الآحاد على العشرات: «خمسة وعشرون»
      if (o > 0 && t > 0) {
        parts.add('${_ones[o]} و${_tens[t]}');
      } else if (o > 0) {
        parts.add(_ones[o]);
      } else if (t > 0) {
        parts.add(_tens[t]);
      }
    }
    return parts.join(' و');
  }

  /// صيغة المعدود حسب العدد: مفرد/مثنّى/جمع.
  static String _scale(int count, String one, String two, String plural) {
    if (count == 1) return one;
    if (count == 2) return two;
    if (count >= 3 && count <= 10) return '${_under1000(count)} $plural';
    return '${_under1000(count)} $one';
  }

  /// يحوّل عدداً صحيحاً إلى حروف عربية (بلا اسم العملة).
  static String integerToWords(int n) {
    if (n == 0) return 'صفر';
    if (n < 0) return 'سالب ${integerToWords(-n)}';

    final parts = <String>[];

    final millions = n ~/ 1000000;
    final thousands = (n % 1000000) ~/ 1000;
    final rest = n % 1000;

    if (millions > 0) {
      parts.add(_scale(millions, 'مليون', 'مليونان', 'ملايين'));
    }
    if (thousands > 0) {
      parts.add(_scale(thousands, 'ألف', 'ألفان', 'آلاف'));
    }
    if (rest > 0) {
      parts.add(_under1000(rest));
    }
    return parts.join(' و');
  }

  /// التفقيط الكامل بالريال اليمني — كما يظهر على الإيصالات المصرفية.
  ///
  /// مثال: `7700` → «سبعة آلاف وسبعمائة ريال يمني».
  /// الكسور (الفلوس) تُضاف عند وجودها فقط.
  static String yer(num amount) {
    final int whole = amount.abs().floor();
    final int frac = ((amount.abs() - whole) * 100).round();

    final sb = StringBuffer(integerToWords(whole));
    sb.write(' ريال يمني');

    if (frac > 0) {
      sb.write(' و${integerToWords(frac)} فلساً');
    }
    return sb.toString();
  }

  /// نسخة تقبل نصّاً (كما تصل المبالغ من الخادم) وتفشل بهدوء.
  static String? yerFromString(String? raw) {
    if (raw == null || raw.trim().isEmpty) return null;
    final v = double.tryParse(raw.replaceAll(',', ''));
    if (v == null) return null;
    return yer(v);
  }
}
