import 'dart:convert';

/// AMIAL-QR-UNIFIED-001 — قارئ رموز أميال باي الموحّد.
///
/// **المشكلة التي يحلّها:** في المشروع صيغتا رمز لا تعرف إحداهما الأخرى:
///
///   الهوية (من 6cash):  {"name":…, "phone":…, "type":…, "image":…}
///   طلب الدفع (أميال):  {"t":"amial_pr", "code":"…"}
///
/// وكانت صيغة طلب الدفع يفهمها مسار واحد («ادفع لتاجر»)، بينما زرّ المسح
/// الأوسط — أبرز زرّ في التطبيق — يتحقّق من حقول الهوية الأربعة فقط. فإذا
/// مسح العميل رمز الكاشير كانت الحقول الأربعة فارغةً ولا يقع شيء: لا رسالة
/// ولا خطأ. يقف العميل عند الصندوق فيضغط الزرّ الأصفر ولا يحدث شيء.
///
/// وهذا الصنف يفصل *فهم الرمز* عن *ما يُفعل به*: دالّة نقيّة تُختبر وحدها
/// بلا كاميرا ولا واجهة، ويستعملها كل من يمسح رمزاً — فلا تعود صيغة معروفة
/// في مكان ومجهولة في آخر.
enum AmialQrKind {
  /// طلب دفع بمبلغ حدّده البائع مسبقاً — العميل يؤكّد ولا يكتب مبلغاً.
  paymentRequest,

  /// هوية عميل — تحويل أو طلب، والمبلغ يكتبه المستخدم.
  customer,

  /// هوية وكيل — سحب نقدي.
  agent,

  /// هوية تاجر (رمز متجر ثابت بلا مبلغ).
  merchant,

  /// رقم هاتف مجرّد: رموز يطبعها بعض التجّار بلا تغليف JSON.
  phone,

  /// أي شيء آخر — باركود منتج، رمز موقع، رمز تطبيق آخر.
  unknown,
}

class AmialQrPayload {
  const AmialQrPayload({
    required this.kind,
    this.requestCode,
    this.phone,
    this.name,
    this.image,
  });

  final AmialQrKind kind;

  /// رمز طلب الدفع القصير — يُقرأ به المبلغ والبائع من الخادم.
  final String? requestCode;

  final String? phone;
  final String? name;
  final String? image;

  bool get isPaymentRequest => kind == AmialQrKind.paymentRequest;
  bool get isIdentity =>
      kind == AmialQrKind.customer ||
      kind == AmialQrKind.agent ||
      kind == AmialQrKind.merchant;

  /// يفهم أي نصّ رمز. لا يرمي أبداً — الرمز المجهول نتيجةٌ لا استثناء.
  ///
  /// كان `jsonDecode(raw)['name']` يُستدعى بلا حماية، فمسحُ باركود منتج
  /// يرمي FormatException ويُبقي قفل الماسح مرفوعاً — فيموت الماسح لبقيّة
  /// الجلسة. الرمز المجهول شيء يقع كل يوم، لا حالة استثنائية.
  static AmialQrPayload parse(String? raw) {
    final text = raw?.trim() ?? '';
    if (text.isEmpty) return const AmialQrPayload(kind: AmialQrKind.unknown);

    Map<String, dynamic>? map;
    try {
      final decoded = jsonDecode(text);
      if (decoded is Map) map = Map<String, dynamic>.from(decoded);
    } catch (_) {
      // ليس JSON — يُجرَّب كرقم هاتف أدناه.
    }

    if (map != null) {
      final code = map['code']?.toString();
      if (map['t'] == 'amial_pr' && code != null && code.isNotEmpty) {
        return AmialQrPayload(
          kind: AmialQrKind.paymentRequest,
          requestCode: code,
        );
      }

      final phone = _digits(map['phone']?.toString());
      if (phone != null) {
        return AmialQrPayload(
          // الهوية بلا نوع تُعامَل معاملة العميل — وهو أضيق الاحتمالات
          // صلاحيةً: التحويل يتطلّب تأكيداً بينما السحب يتطلّب وكيلاً.
          kind: switch (map['type']?.toString()) {
            'agent' => AmialQrKind.agent,
            'merchant' => AmialQrKind.merchant,
            _ => AmialQrKind.customer,
          },
          phone: phone,
          name: map['name']?.toString(),
          image: map['image']?.toString(),
        );
      }

      return const AmialQrPayload(kind: AmialQrKind.unknown);
    }

    // رمز نصّه رقم هاتف مباشرةً — يطبعه بعض التجّار.
    final bare = _yemeniPhone(text);
    if (bare != null) {
      return AmialQrPayload(kind: AmialQrKind.phone, phone: bare);
    }

    return const AmialQrPayload(kind: AmialQrKind.unknown);
  }

  /// رقم داخل رمز أميال (JSON): متساهل، لأن الرمز عرّف نفسه أصلاً.
  static String? _digits(String? value) {
    if (value == null) return null;
    final cleaned = value.replaceAll(RegExp(r'[\s\-()]'), '');
    if (!RegExp(r'^\+?[0-9]{6,15}$').hasMatch(cleaned)) return null;
    return cleaned.replaceFirst('+', '');
  }

  /// رقم في رمز عارٍ بلا تغليف: يجب أن يكون على شكل يمنيّ صريح.
  ///
  /// القاعدة المتساهلة (6–15 رقماً) تبتلع باركود المنتجات: EAN-13 ثلاثة
  /// عشر رقماً، فيقرأه التطبيق هاتفاً ويعرض على المستخدم تحويلاً إلى رقم
  /// لا وجود له. رمزٌ عارٍ لا يقول ما هو، فلا يُقبل إلا إذا طابق شكلاً
  /// نعرفه: 7xxxxxxxx أو 967 7xxxxxxxx.
  static String? _yemeniPhone(String value) {
    final cleaned = value.replaceAll(RegExp(r'[\s\-()]'), '').replaceFirst('+', '');

    if (RegExp(r'^7[0-8][0-9]{7}$').hasMatch(cleaned)) return cleaned;
    if (RegExp(r'^9677[0-8][0-9]{7}$').hasMatch(cleaned)) return cleaned;

    return null;
  }
}
