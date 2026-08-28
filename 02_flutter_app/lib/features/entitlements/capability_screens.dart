import 'package:flutter/material.dart';

import 'package:amial_pay/features/merchant/screens/merchant_pos_devices_screen.dart';
import 'package:amial_pay/features/branches/screens/branches_management_screen.dart';
import 'package:amial_pay/features/corporate/screens/corporate_accounts_screen.dart';
import 'package:amial_pay/features/fuel_station/screens/fuel_ops_center_screen.dart';
import 'package:amial_pay/features/fuel_station/screens/fuel_companies_screen.dart';
import 'package:amial_pay/features/fuel_station/screens/fuel_shifts_screen.dart';
import 'package:amial_pay/features/fuel_station/screens/fuel_owner_console_screen.dart';
import 'package:amial_pay/features/fuel_station/screens/fuel_tanks_screen.dart';
import 'package:amial_pay/features/fuel_station/screens/fuel_variances_screen.dart';
import 'package:amial_pay/features/merchant/screens/cashier_pos_screen.dart';
import 'package:amial_pay/features/merchant/screens/cashier_products_screen.dart';
import 'package:amial_pay/features/merchant/screens/cashier_report_screen.dart';
import 'package:amial_pay/features/merchant/screens/cashier_shift_screen.dart';
import 'package:amial_pay/features/merchant/screens/credit_customers_screen.dart';
import 'package:amial_pay/features/merchant/screens/credit_dashboard_screen.dart';
import 'package:amial_pay/features/merchant/screens/merchant_api_keys_screen.dart';
import 'package:amial_pay/features/merchant/screens/merchant_audit_log_screen.dart';
import 'package:amial_pay/features/merchant/screens/merchant_backup_screen.dart';
import 'package:amial_pay/features/merchant/screens/merchant_currencies_screen.dart';
import 'package:amial_pay/features/merchant/screens/merchant_excel_export_screen.dart';
import 'package:amial_pay/features/merchant/screens/merchant_expenses_screen.dart';
import 'package:amial_pay/features/merchant/screens/merchant_gift_cards_screen.dart';
import 'package:amial_pay/features/merchant/screens/merchant_installments_screen.dart';
import 'package:amial_pay/features/merchant/screens/merchant_loyalty_screen.dart';
import 'package:amial_pay/features/merchant/screens/merchant_promotions_screen.dart';
import 'package:amial_pay/features/access/screens/role_based_home_screens.dart';
import 'package:amial_pay/features/merchant/screens/merchant_refund_screen.dart';
import 'package:amial_pay/features/merchant/screens/merchant_staff_screen.dart';
import 'package:amial_pay/features/merchant/screens/offline_sales_screen.dart';
import 'package:amial_pay/features/merchant/screens/financial_truth_report_screen.dart';
import 'package:amial_pay/features/merchant/screens/split_bill_create_screen.dart';
import 'package:amial_pay/features/pharmacy/screens/pharmacy_dashboard_screen.dart';
import 'package:amial_pay/features/suppliers/screens/purchase_order_create_screen.dart';
import 'package:amial_pay/features/restaurant/screens/restaurant_screen.dart';
import 'package:amial_pay/features/merchant/screens/inventory_audit_screen.dart';
import 'package:amial_pay/features/merchant/screens/inventory_screen.dart';
import 'package:amial_pay/features/retail/screens/retail_ops_center_screen.dart';
import 'package:amial_pay/features/retail/screens/retail_locations_screen.dart';
import 'package:amial_pay/features/retail/screens/retail_transfers_screen.dart';
import 'package:amial_pay/features/retail/screens/retail_wastes_screen.dart';
import 'package:amial_pay/features/merchant/screens/stock_alerts_screen.dart';
import 'package:amial_pay/features/suppliers/screens/suppliers_screen.dart';
import 'package:amial_pay/features/wholesale/screens/wholesale_screens.dart';

/// AMIAL-CAP-SCREENS-001 — **مصدرُ حقيقةٍ واحدٌ للتنقّل بالقدرة.**
///
/// ══════════════════════════════════════════════════════════════════════
/// **العطلُ الذي وُلد منه — مقيسٌ لا مفترَض:**
///
/// كان في المنصّة **كتالوجان**: سجلُّ الخادم فيه ٤٧ قدرة، وقائمةٌ مكتوبةٌ
/// بيدٍ داخل `merchant_services_hub_screen.dart` فيها ٢٧. فكان التاجرُ
/// يقرأ «مفتوح لديك ١٢ من ٢٧ خدمة» — **ومقامٌ لا يُحسب من مصدره**
/// (القاعدة السادسة). و٢٢ قدرةً في الخادم لا مدخلَ لها في الشاشة
/// إطلاقاً، منها الكاشيرُ والبيعُ السريع والباركود ومحطّةُ الوقود
/// والصيدليّةُ والجملةُ كلُّها.
///
/// **والأسوأ:** `my_capabilities_screen` كان ينقل **بالاسم** إلى ما
/// يُعلنه الخادمُ (`/cashier`, `/products` …)، و`route_helper.dart` لا
/// يسجّل واحداً من الأربعين — المسجَّلُ فيه مساراتُ القالب القديم
/// (`/splash`, `/send_money`). فكلُّ خدمةٍ «متاحة» تُضغَط فتُخرج
/// «لم تُفعَّل شاشتها بعد». **قِيس: ٤٠ من ٤٠.**
///
/// ══════════════════════════════════════════════════════════════════════
/// **فصار المفتاحُ رمزَ القدرة لا نصَّ المسار.** الرمزُ هو ما يعرفه
/// الطرفان أصلاً — `access.has(code)` تقرؤه، والخادمُ يُصدره. ونصُّ
/// المسار كان طرفاً ثالثاً لا يقرؤه أحد.
///
/// **وما لا شاشةَ له يُقال ولا يُسكت عنه:** `screenFor` تُرجع `null`،
/// والشاشةُ تعرض سببَه. (القاعدة السابعة: الغيابُ يُقال صراحةً.)
///
/// ويحرس تمامَها `CapabilityScreenMapGuardTest`: كلُّ قدرةٍ يُعلن الخادمُ
/// لها `screen` لها مدخلٌ هنا، وكلُّ رمزٍ هنا موجودٌ في سجلّ الخادم.
class CapabilityScreens {
  CapabilityScreens._();

  /// رمزُ القدرة ⇒ باني الشاشة. و`null` = لا شاشةَ في التطبيق بعد.
  static final Map<String, Widget Function()> _map = {
    // ── البيع ──────────────────────────────────────────────────────
    'quick_sale': () => const MerchantQuickSaleHomeScreen(),
    'cashier': () => const CashierPosScreen(),
    'refunds': () => const MerchantRefundScreen(),
    'debts': () => const CreditDashboardScreen(),
    'offline_pos': () => const OfflineSalesScreen(),
    'split_bill': () => const SplitBillCreateScreen(),
    'gift_cards': () => const MerchantGiftCardsScreen(),
    'installments': () => const MerchantInstallmentsScreen(),

    // ── الأصناف والمخزون ───────────────────────────────────────────
    'products': () => const CashierProductsScreen(),
    'promotions': () => const MerchantPromotionsScreen(),
    'loyalty': () => const MerchantLoyaltyScreen(),
    'inventory': () => const InventoryScreen(),
    'low_stock_alerts': () => const StockAlertsScreen(),
    'inventory_audit': () => const InventoryAuditScreen(),
    'suppliers': () => const SuppliersScreen(),
    'purchases': () => const PurchaseOrderCreateScreen(),

    // ── العملاء والفريق ────────────────────────────────────────────
    'customers': () => const CreditCustomersScreen(),
    'employees': () => const MerchantStaffScreen(),
    // AMIAL-POS-DEVICES-008 — الجهازُ مقعدٌ، والموظّفُ حساب: شاشتان لا واحدة.
    'multi_pos': () => const MerchantPosDevicesScreen(),
    'branches': () => const BranchesManagementScreen(),
    'corporate_accounts': () => const CorporateAccountsScreen(),

    // ── المال والتقارير ────────────────────────────────────────────
    'shift_close': () => const CashierShiftScreen(),
    'daily_reports': () => const CashierReportScreen(),
    'profit_reports': () => const FinancialTruthReportScreen(),
    'advanced_reports': () => const FinancialTruthReportScreen(),
    'excel_export': () => const MerchantExcelExportScreen(),
    'expenses': () => const MerchantExpensesScreen(),
    'audit_log': () => const MerchantAuditLogScreen(),
    'advanced_backup': () => const MerchantBackupScreen(),
    'multi_currency': () => const MerchantCurrenciesScreen(),
    'api_access': () => const MerchantApiKeysScreen(),

    // ── أصنافُ التجّار ─────────────────────────────────────────────
    'fuel_pos': () => const FuelOwnerConsoleScreen(),
    'fuel_pumps': () => const FuelTanksScreen(),
    'fuel_variance': () => const FuelVariancesScreen(),
    'fuel_cards': () => const FuelOpsCenterScreen(),
    'fuel_products': () => const FuelOpsCenterScreen(),
    'fuel_companies': () => const FuelCompaniesScreen(),
    'fuel_shifts': () => const FuelShiftsScreen(),
    'pharmacy_pos': () => const PharmacyDashboardScreen(),
    'pharmacy_products': () => const PharmacyDashboardScreen(),
    'pharmacy_batches': () => const PharmacyDashboardScreen(),
    'pharmacy_alerts': () => const PharmacyDashboardScreen(),
    'pharmacy_customers': () => const PharmacyDashboardScreen(),
    // **الوصفاتُ تُدار من لوحة الصيدليّة نفسِها** — وسمُ الصنف وحقولُ
    // الوصفة في البيعة. ولا شاشةَ ثالثةٌ لها، فتُوجَّه إلى موضع عملها.
    // (‏وبلا هذا السطر يظهر سهمُ الدخول ويُضغط فلا يفتح — أمسكه
    // `CapabilityScreenMapGuardTest`.)
    'pharmacy_prescriptions': () => const PharmacyDashboardScreen(),
    'wholesale_invoices': () => const WholesaleDashboardScreen(),
    'wholesale_collections': () => const WholesaleDashboardScreen(),
    'restaurant_tables': () => const RestaurantScreen(),

    // ── تجزئةٌ: أرمزٌ منقّطةٌ فاتت أوّلَ مسحٍ لي ─────────────────────
    //
    // **أمسكها الحارسُ لا أنا.** كان تعبيري `[a-z_0-9]+` فأسقط كلَّ رمزٍ
    // فيه نقطة — سبعُ قدراتٍ يُعلن الخادمُ لها شاشةً. وهو بعينه ما تحذّر
    // منه القاعدةُ الخامسة: تعبيرٌ نمطيٌّ يقطع ما لم يُتوقَّع.
    'retail.catalog': () => const RetailOpsCenterScreen(),
    // `ProductVariantsScreen` تحتاج `productId` و`productName` — فهي
    // شاشةُ صنفٍ بعينه لا مدخلَ قائمة. والطريقُ إليها من شاشة الأصناف.
    'retail.variants': () => const CashierProductsScreen(),
    'retail.price_versions': () => const RetailOpsCenterScreen(),
    'retail.locations': () => const RetailLocationsScreen(),
    'retail.transfers': () => const RetailTransfersScreen(),
    'retail.waste': () => const RetailWastesScreen(),
    'retail.returns.by_line': () => const MerchantRefundScreen(),

    // ── وما يُفتح من مركزٍ لا من شاشةٍ مستقلّة ──────────────────────
    'rbac': () => const RetailOpsCenterScreen(),
    'barcode': () => const CashierPosScreen(),
  };

  /// الشاشةُ التي تفتحها هذه القدرة، أو `null` إن لم تُبنَ بعد.
  static Widget Function()? screenFor(String code) => _map[code];

  /// أرمزُ كلِّ ما له شاشة — يقرؤها الحارس.
  static Set<String> get codes => _map.keys.toSet();
}
