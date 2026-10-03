import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

void main() {
  test('quick sale is amount-only and never opens the product catalogue', () {
    final source =
        File('lib/features/merchant/screens/cashier_pos_screen.dart')
            .readAsStringSync();

    expect(
      source,
      contains(
          'if (access.isQuickSale) return ShiftGate(child: _quickSaleTill(context));'),
    );
    expect(source, contains("key: const Key('quick-sale-amount')"));
    expect(source, contains('لا منتجات ولا باركود — مبلغ، تحصيل، ثم فاتورة.'));

    final quickInit = source.indexOf('if (access.isQuickSale) {');
    final productLoad = source.indexOf('c.loadProducts();');
    expect(quickInit, greaterThanOrEqualTo(0));
    expect(productLoad, greaterThan(quickInit));
    expect(
      source.substring(quickInit, productLoad),
      contains('return;'),
      reason: 'quick_sale must return before the catalogue request',
    );
  });

  test('quick sale payment only exposes cash and Amial Pay', () {
    final source =
        File('lib/features/merchant/screens/cashier_payment_screen.dart')
            .readAsStringSync();

    expect(
      source,
      contains('if (!widget.freeAmount) MerchantPaymentOption.credit'),
    );
    expect(
      source,
      contains(
          "if (!widget.freeAmount && _methodAllowedInCurrency('mixed'))"),
    );
    expect(
      source,
      contains(
          "if (!widget.freeAmount && _methodAllowedInCurrency('corporate'))"),
    );
    expect(
      source,
      contains('بيع سريع: التحصيل نقداً أو عبر أميال باي فقط.'),
    );
  });
}
