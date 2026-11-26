import 'package:amial_pay/data/api/idempotent_intent.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:get/get.dart';

class _Intent with IdempotentIntent {}

void main() {
  test('wallet actions reuse their key while the outcome is uncertain', () {
    final intent = _Intent();
    final key = intent.keyFor('donate', scope: 'campaign');
    for (final response in [
      const Response(statusCode: 503),
      const Response(statusCode: 409, body: {'code': 'IDEMPOTENCY_IN_PROGRESS'}),
    ]) {
      intent.settleKey('donate', response, scope: 'campaign');
      expect(intent.keyFor('donate', scope: 'campaign'), key);
    }
    intent.settleKey('donate', const Response(statusCode: 200), scope: 'campaign');
    expect(intent.keyFor('donate', scope: 'campaign'), isNot(key));
  });
}
