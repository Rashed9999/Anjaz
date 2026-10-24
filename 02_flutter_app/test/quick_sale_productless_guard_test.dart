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


  test('quick sale clears retail stock state and uses a quick-sale invoice title', () {
    final pos =
        File('lib/features/merchant/screens/cashier_pos_screen.dart')
            .readAsStringSync();
    final payment =
        File('lib/features/merchant/screens/cashier_payment_screen.dart')
            .readAsStringSync();

    expect(pos, contains('c.lastNegativeStock.clear();'));
    expect(
      payment,
      contains("invoiceTitle: widget.freeAmount ? 'فاتورة بيع سريع' : null"),
    );
    expect(
      payment,
      contains("note: widget.freeAmount ? 'بيع سريع' : 'دفع مشتريات'"),
    );
  });

  test('quick sale report never advertises a debt row', () {
    final report =
        File('lib/features/merchant/screens/cashier_report_screen.dart')
            .readAsStringSync();

    expect(report, contains('bool get _isQuickSale'));
    expect(report, contains('if (!_isQuickSale)'));
    expect(report, contains("'تقرير البيع السريع'"));
  });
}
