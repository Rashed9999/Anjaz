import 'package:uuid/uuid.dart';

/// AMIAL-SUPPORT-CORRELATION-002
///
/// رقم تشخيص يسبق الطلب نفسه، لذلك يبقى معروفاً حتى لو انقطع الرد.
/// لا يحتوي رقم هاتف أو مبلغ أو أي PII.
class DiagnosticTraceId {
  DiagnosticTraceId._();

  static const _uuid = Uuid();

  static String generate() => 'diag-${_uuid.v4()}';

  static bool isValid(String value) =>
      value.length <= 64 &&
      RegExp(r'^[A-Za-z0-9][A-Za-z0-9._:-]+$').hasMatch(value);
}
