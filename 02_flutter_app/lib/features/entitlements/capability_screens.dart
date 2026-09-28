import 'package:flutter/material.dart';
import 'package:amial_pay/features/access/screens/web_portal_notice_screen.dart';
import 'package:amial_pay/features/fuel_station/screens/fuel_sale_screen.dart';
import 'package:amial_pay/features/merchant/screens/cashier_pos_screen.dart';
import 'package:amial_pay/features/merchant/screens/cashier_products_screen.dart';
import 'package:amial_pay/features/merchant/screens/cashier_report_screen.dart';
import 'package:amial_pay/features/merchant/screens/cashier_shift_screen.dart';
import 'package:amial_pay/features/merchant/screens/merchant_refund_screen.dart';
import 'package:amial_pay/features/merchant/screens/offline_sales_screen.dart';
import 'package:amial_pay/features/merchant/screens/pos_credit_lookup_screen.dart';
import 'package:amial_pay/features/merchant/screens/split_bill_create_screen.dart';
import 'package:amial_pay/features/pharmacy/screens/pharmacy_dashboard_screen.dart';
import 'package:amial_pay/features/pharmacy/screens/pharmacy_sale_screen.dart';
import 'package:amial_pay/features/restaurant/screens/restaurant_screen.dart';
import 'package:amial_pay/features/wholesale/screens/wholesale_workflow_screens.dart';

/// خريطة القدرة في التطبيق بعد فصل المالك إلى الويب.
///
/// لا نكسر عقد القدرات مع الخادم ولا نترك رابطاً ميتاً: كل رمزٍ يظل معروفاً.
/// لكن ما كان لوحة مالك (موظفون، أجهزة، مخزون، أسعار، إعدادات وتقارير
/// مؤسسية) يحيل إلى البوابة بدلاً من أن يُضمَّن سطحٌ موازٍ في تطبيق العميل
/// وPOS. العمليات التي يحتاجها الكاشير تبقى شاشات محمولة محدودة.
class CapabilityScreens {
  CapabilityScreens._();

  static final Map<String, Widget Function()> _pos = {
    'quick_sale': () => const CashierPosScreen(),
    'cashier': () => const CashierPosScreen(),
    'refunds': () => const MerchantRefundScreen(),
    'debts': () => const PosCreditLookupScreen(),
    'offline_pos': () => const OfflineSalesScreen(),
    'split_bill': () => const SplitBillCreateScreen(),
    'products': () => const CashierProductsScreen(),
    'customers': () => const PosCreditLookupScreen(),
    'shift_close': () => const CashierShiftScreen(),
    'daily_reports': () => const CashierReportScreen(),
    'fuel_pos': () => const FuelSaleScreen(),
    'pharmacy_pos': () => const PharmacySaleScreen(),
    'pharmacy_products': () => const PharmacyProductsScreen(),
    'pharmacy_prescriptions': () => const PharmacySaleScreen(),
    'wholesale_invoices': () => const WholesaleProInvoicesScreen(),
    'wholesale_collections': () => const WholesaleProInvoicesScreen(),
    'restaurant_tables': () => const RestaurantScreen(),
    'restaurant_orders': () => const RestaurantScreen(),
    'restaurant_kitchen': () => const RestaurantScreen(initialTab: 1),
    'retail.variants': () => const CashierProductsScreen(),
    'retail.returns.by_line': () => const MerchantRefundScreen(),
    'barcode': () => const CashierPosScreen(),
  };

  /// هذه القدرات لا تعمل من التطبيق بعد الآن؛ أسماءها تبقى حتى لا يختل
  /// عقد الباقة أو تظهر خدمة بلا تفسير، والوجهة تعلن بوضوح أين توجد.
  static const Set<String> _ownerWebOnly = {
    'gift_cards', 'installments', 'promotions', 'loyalty', 'inventory',
    'low_stock_alerts', 'inventory_audit', 'suppliers', 'purchases',
    'employees', 'multi_pos', 'branches', 'corporate_accounts',
    'profit_reports', 'advanced_reports', 'excel_export', 'expenses',
    'audit_log', 'advanced_backup', 'multi_currency', 'api_access',
    'fuel_pumps', 'fuel_variance', 'fuel_cards', 'fuel_products',
    'fuel_companies', 'fuel_shifts', 'pharmacy_alerts',
    'pharmacy_customers', 'wholesale_multi_pricing', 'retail.catalog',
    'retail.price_versions', 'retail.locations', 'retail.transfers',
    'retail.waste', 'rbac',
  };

  static Widget Function()? screenFor(String code) {
    final posScreen = _pos[code];
    if (posScreen != null) return posScreen;
    if (_ownerWebOnly.contains(code)) {
      return () => const WebPortalNoticeScreen(role: 'merchant');
    }
    return null;
  }

  static Set<String> get codes => {..._pos.keys, ..._ownerWebOnly};
}
