import 'package:uuid/uuid.dart';

/// AMYAL-SECURITY-002 (v0.7-C)
///
/// IdempotencyKeyGenerator — يولّد مفتاح idempotency لكل عملية مالية.
///
/// **المنطق:**
///   - كل request مالي ينتج مفتاح UUID v4 (entropy عالي، 122 bit randomness).
///   - يُرسل في header `Idempotency-Key`.
///   - الـ backend (EnforceIdempotency middleware) يحفظه ويعيد نفس الـ response لو
///     نفس الـ key أُعيد إرساله خلال 24h.
///
/// **القاعدة:**
///   - مفتاح واحد لكل عملية بَشَر-مرئية.
///   - retry تلقائي بسبب network → نفس المفتاح (لـ idempotency).
///   - عملية جديدة من المستخدم → مفتاح جديد.
///
/// **الاستخدام في ApiClient:**
///   ```dart
///   final key = IdempotencyKeyGenerator.forFinancialAction('send_money');
///   final response = await apiClient.postData(url, body, idempotencyKey: key);
///   ```
///
/// **الاستخدام في UI (للـ retry):**
///   ```dart
///   final controller = SendMoneyController();
///   // كل ضغطة على "إرسال" تولّد key جديد:
///   controller.idempotencyKey = IdempotencyKeyGenerator.forFinancialAction('send_money');
///
///   // لو فشل الـ network، إعادة المحاولة تستخدم نفس الـ key:
///   await controller.retry();  // يستعمل controller.idempotencyKey نفسه
///   ```
class IdempotencyKeyGenerator {
  IdempotencyKeyGenerator._();

  static const _uuid = Uuid();

  /// يولّد مفتاح UUID v4 (random).
  /// طول: 36 حرف (32 hex + 4 hyphens).
  static String generate() => _uuid.v4();

  /// يولّد مفتاح مع prefix يدل على الـ action (للتشخيص في الـ logs).
  /// مثال: `send_money_a1b2c3d4-...`
  ///
  /// **مهم:** الـ backend يقبل الـ prefix لكن لا يستخدمه دلالياً.
  /// المهم هو الـ uniqueness الكلية للـ string.
  static String forFinancialAction(String action) {
    final safeAction = action.replaceAll(RegExp(r'[^a-z_]'), '');
    return '${safeAction}_${_uuid.v4()}';
  }

  /// يفحص أن المفتاح صالح (للـ tests/debugging).
  static bool isValid(String key) {
    if (key.length < 16 || key.length > 128) return false;
    // يحوي على الأقل hyphen و alphanumeric
    return RegExp(r'^[a-zA-Z0-9_\-]+$').hasMatch(key);
  }
}
