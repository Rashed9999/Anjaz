import 'package:flutter_test/flutter_test.dart';
import 'package:amial_pay/features/wholesale/widgets/wholesale_entitlement_gate.dart';

void main() {
  group('AMIAL-WHOLESALE-SCOPE-001 capability map', () {
    test('dashboard is vertical-scoped but not sold as a plan feature', () {
      expect(WholesaleSurface.dashboard.capability, isNull);
    });

    test('base wholesale surfaces use wholesale/base capabilities', () {
      expect(WholesaleSurface.invoices.capability, 'wholesale_invoices');
      expect(WholesaleSurface.refunds.capability, 'refunds');
    });

    test('catalog and inventory depth use the server entitlement codes', () {
      expect(WholesaleSurface.products.capability, 'products');
      expect(WholesaleSurface.lowStockAlerts.capability, 'low_stock_alerts');
      expect(WholesaleSurface.expiryAlerts.capability, 'inventory');
    });

    test('business depth is never inferred from a hard-coded plan name', () {
      expect(WholesaleSurface.customers.capability, 'customers');
      expect(WholesaleSurface.aging.capability, 'advanced_reports');
      expect(WholesaleSurface.excelExport.capability, 'excel_export');
      expect(WholesaleSurface.suppliers.capability, 'suppliers');
      expect(WholesaleSurface.purchases.capability, 'purchases');
    });

    test('wholesale advanced pricing has its dedicated capability', () {
      expect(
        WholesaleSurface.multiPricing.capability,
        'wholesale_multi_pricing',
      );
    });
  });
}
