/// AMIAL-ERRORS-001 — تعريب رسائل الخطأ القادمة من الخادم.
///
/// بعض مسارات 6cash القديمة تُرجع رسائل إنجليزية ("Insufficient balance"…)
/// فتظهر إشعارات حمراء بالإنجليزية. هذا المساعد يحوّل الأكواد/الرسائل
/// الشائعة إلى نص عربي واضح، ويُرجع الرسالة كما هي إن كانت عربية أصلاً.
class AmialErrors {
  AmialErrors._();

  static const Map<String, String> _byCode = {
    'TX_INSUFFICIENT_BALANCE': 'الرصيد غير كافٍ',
    'INSUFFICIENT_BALANCE': 'الرصيد غير كافٍ',
    'TX_ZONE_BLOCKED': 'التحويل بين المنطقتين غير متاح حالياً',
    'TX_LIMIT_EXCEEDED': 'تجاوزت الحدّ المسموح لهذه العملية',
    'KYC_REQUIRED': 'أكمل توثيق حسابك أولاً',
    'USER_NOT_FOUND': 'الرقم غير مسجّل في أميال باي',
    'RECEIPT_NOT_FOUND': 'الإيصال غير موجود',
    'REQUEST_ERROR': 'تعذّر تنفيذ الطلب',
    'VALIDATION_FAILED': 'بيانات غير صحيحة',
    'UNAUTHORIZED': 'انتهت الجلسة — سجّل الدخول مجدداً',
    'PIN_INCORRECT': 'رمز PIN غير صحيح',
    'RATE_LIMITED': 'محاولات كثيرة — انتظر قليلاً ثم أعد المحاولة',
  };

  static const Map<String, String> _byEnglish = {
    'insufficient balance': 'الرصيد غير كافٍ',
    'user not found': 'الرقم غير مسجّل في أميال باي',
    'customer not found': 'الرقم غير مسجّل في أميال باي',
    'unauthorized': 'انتهت الجلسة — سجّل الدخول مجدداً',
    'unauthenticated': 'انتهت الجلسة — سجّل الدخول مجدداً',
    'server error': 'خطأ في الخادم — حاول لاحقاً',
    'too many attempts': 'محاولات كثيرة — انتظر قليلاً ثم أعد المحاولة',
    'network error': 'خطأ في الشبكة',
    'not found': 'غير موجود',
    'forbidden': 'غير مسموح لك بهذه العملية',
  };

  /// يُرجع رسالة عربية: من الكود أولاً، ثم من الرسالة الإنجليزية،
  /// وإلا الرسالة الأصلية (إن كانت عربية تُعرض كما هي).
  static String arabize(String? message, {String? code, String fallback = 'حدث خطأ — حاول مجدداً'}) {
    final c = (code ?? '').trim().toUpperCase();
    if (c.isNotEmpty) {
      for (final e in _byCode.entries) {
        if (c == e.key || c.endsWith(e.key)) return e.value;
      }
    }
    final m = (message ?? '').trim();
    if (m.isEmpty) return fallback;
    final lower = m.toLowerCase();
    for (final e in _byEnglish.entries) {
      if (lower.contains(e.key)) return e.value;
    }
    // إن كانت الرسالة تحتوي حروفاً عربية نعرضها كما هي
    if (RegExp(r'[؀-ۿ]').hasMatch(m)) return m;
    return fallback;
  }
}
