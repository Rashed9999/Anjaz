import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

void main() {
  String read(String relative) =>
      File('${Directory.current.path}/$relative').readAsStringSync();

  test('merchant payment keeps a correlation id before the network call', () {
    final api = read('lib/data/api/api_client.dart');
    final repo = read('lib/features/merchant/domain/repositories/merchant_pay_repo.dart');
    final controller = read('lib/features/merchant/controllers/merchant_pay_controller.dart');

    expect(api.contains("requestHeaders['X-Correlation-Id'] = traceId"), isTrue);
    expect(api.contains('DiagnosticTraceId.generate()'), isTrue);
    expect(api.contains("'x-correlation-id': traceId"), isTrue);

    expect(repo.contains('required String correlationId'), isTrue);
    expect(repo.contains('correlationId: correlationId'), isTrue);

    expect(controller.contains('_correlationId = DiagnosticTraceId.generate()'), isTrue);
    expect(controller.contains('correlationId: _correlationId'), isTrue);
    expect(controller.contains('lastDiagnosticId.value'), isTrue);
  });

  test('merchant payment does not mislabel an unknown result as weak internet', () {
    final controller = read(
      'lib/features/merchant/controllers/merchant_pay_controller.dart',
    );

    expect(controller.contains("lastError.value = 'خطأ في الشبكة'"), isFalse);
    expect(controller.contains('تعذر تأكيد نتيجة الدفع'), isTrue);
    expect(controller.contains('لا تبدأ عملية دفع جديدة قبل التحقق'), isTrue);
    expect(controller.contains('رقم التتبع:'), isTrue);
  });

  test('diagnostic trace id is PII-free and accepted by server format', () {
    final generator = read('lib/data/api/diagnostic_trace_id.dart');

    expect(generator.contains("static String generate() => 'diag-"), isTrue);
    expect(generator.contains('phone'), isFalse);
    expect(generator.contains('amount'), isFalse);
    expect(generator.contains(r'^[A-Za-z0-9][A-Za-z0-9._:-]+$'), isTrue);
  });
}
