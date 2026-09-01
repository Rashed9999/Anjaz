import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amial_pay/theme/amial_colors.dart';
import 'package:amial_pay/features/access/controllers/access_controller.dart';
import 'package:amial_pay/features/access/widgets/access_gate.dart';
import 'package:amial_pay/features/me/screens/my_services_screen.dart';
// الشاشات الفعلية للربط
import 'package:amial_pay/features/merchant/screens/cashier_pos_screen.dart';
import 'package:amial_pay/features/merchant/screens/cashier_products_screen.dart';
import 'package:amial_pay/features/entitlements/screens/my_capabilities_screen.dart';
import 'package:amial_pay/features/retail/screens/retail_ops_center_screen.dart';
import 'package:amial_pay/features/merchant/screens/financial_truth_report_screen.dart';
import 'package:amial_pay/features/merchant/screens/merchant_staff_screen.dart';
import 'package:amial_pay/features/merchant/screens/merchant_audit_log_screen.dart';
import 'package:amial_pay/features/merchant/screens/merchant_excel_export_screen.dart';
import 'package:amial_pay/features/merchant/screens/receipt_settings_screen.dart';
import 'package:amial_pay/features/corporate/screens/corporate_accounts_screen.dart';
import 'package:amial_pay/features/merchant/screens/merchant_currencies_screen.dart';
import 'package:amial_pay/features/merchant/screens/merchant_api_keys_screen.dart';
import 'package:amial_pay/features/merchant/screens/merchant_backup_screen.dart';
import 'package:amial_pay/features/merchant/screens/merchant_loyalty_screen.dart';
import 'package:amial_pay/features/merchant/screens/merchant_promotions_screen.dart';
import 'package:amial_pay/features/merchant/screens/merchant_installments_screen.dart';
import 'package:amial_pay/features/restaurant/screens/restaurant_screen.dart';
import 'package:amial_pay/features/merchant/screens/merchant_gift_cards_screen.dart';
import 'package:amial_pay/features/merchant/screens/cashier_shift_screen.dart';
import 'package:amial_pay/features/merchant/screens/merchant_expenses_screen.dart';
import 'package:amial_pay/features/merchant/screens/credit_dashboard_screen.dart';
import 'package:amial_pay/features/merchant/screens/credit_customers_screen.dart';
import 'package:amial_pay/features/barcode/screens/continuous_scanner_screen.dart';

/// CRITICAL-001 (Phase 2) — شاشات Home متمايزة.
///
/// كل business_type يحصل على Home بسيط مناسب لاحتياجاته.
/// المبدأ: "بائع السمك يتعلّم في دقيقتين."

// =========================================================================
// 1) Home لـ Quick Sale (أسماك، خضار، بسطات) — أقصى بساطة
// =========================================================================
class MerchantQuickSaleHomeScreen extends StatelessWidget {
  const MerchantQuickSaleHomeScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final access = Get.find<AccessController>();

    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(
        title: Obx(() => Text(access.businessName)),
        actions: [
          IconButton(
            icon: const Icon(Icons.menu),
            onPressed: () => Get.to(() => const MyServicesScreen()),
            tooltip: 'الخدمات',
          ),
        ],
      ),
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(20),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          // بطاقة "أهلاً"
          _GreetingCard(subtitle: 'تطبيق بيع سريع — أبسط ما يمكن'),
          const SizedBox(height: 24),

          // 3 أزرار كبيرة فقط — هذا كل ما يحتاجه بائع السمك
          _BigActionButton(
            icon: Icons.point_of_sale,
            label: 'بيع جديد',
            subtitle: 'سجّل عملية بيع',
            color: AmialColors.primary,
            onTap: () => Get.to(() => const CashierPosScreen()),
          ),
          const SizedBox(height: 14),
          AccessGate(feature: 'debts', child: _BigActionButton(
            icon: Icons.receipt_long,
            label: 'الديون',
            subtitle: 'سجّل من عليه دين',
            color: AmialColors.primary,
            onTap: () => Get.to(() => const CreditDashboardScreen()),
          )),
          const SizedBox(height: 14),
          AccessGate(feature: 'daily_reports', child: _BigActionButton(
            icon: Icons.bar_chart,
            label: 'تقرير اليوم',
            subtitle: 'كم بِعت اليوم؟',
            color: AmialColors.primary,
            onTap: () => Get.to(() => const FinancialTruthReportScreen(dailyOnly: true)),
          )),
        ]),
      ),
    );
  }
}

// =========================================================================
// 2) Home لـ Retail (بقالة، سوبرماركت)
// =========================================================================
class MerchantRetailHomeScreen extends StatelessWidget {
  const MerchantRetailHomeScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final access = Get.find<AccessController>();

    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(
        title: Obx(() => Text(access.businessName)),
        actions: [
          // AMIAL-ENTITLEMENTS-001 — **ملفّ خدماتي**: كلُّ ما في المنصّة
          // بحالته لهذا الحساب، والمقفلُ يُعرض بسعر فتحه. وما يُخفى لا
          // يُشترى — ولا يعرف التاجرُ أنّه موجودٌ أصلاً.
          IconButton(
            icon: const Icon(Icons.apps_rounded),
            tooltip: 'خدماتي',
            onPressed: () => Get.to(() => const MyCapabilitiesScreen()),
          ),
          IconButton(icon: const Icon(Icons.menu),
              onPressed: () => Get.to(() => const MyServicesScreen())),
        ],
      ),
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(16),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          _GreetingCard(subtitle: 'متجر تجزئة — POS كامل'),
          const SizedBox(height: 20),

          // الإجراء الأساسي: الكاشير
          _BigActionButton(
            icon: Icons.point_of_sale,
            label: 'الكاشير',
            subtitle: 'بيع جديد + سلّة',
            color: AmialColors.primary,
            onTap: () => Get.to(() => const CashierPosScreen()),
          ),
          const SizedBox(height: 14),

          // ══════════════════════════════════════════════════════════
          //  **الفجوات: خليّةٌ فارغةٌ لكلّ ميزةٍ مقفلة.**
          //
          //  `AccessGate` يُرجع `SizedBox.shrink()` عند القفل — ودجتٌ
          //  بصفر حجم **تبقى عنصراً في القائمة**، و`GridView.count`
          //  يوزّع أبناءه بالموضع فتحتلّ خليّةً كاملة.
          //
          //  قِيس على باقة البداية: الموظفون وتصدير Excel وسجلّ التدقيق
          //  مقفلةٌ ⇒ **ثلاثُ خلايا فارغةٍ متتالية**، ثمّ «إعدادات
          //  الفاتورة» وحدَها، ثمّ فجوةٌ، ثمّ «العروض» وحدَها.
          //
          //  **والفجوةُ ليست فراغاً — هي بصمةُ ما لا يملكه التاجر**،
          //  فالشاشةُ تُفشي باقتَه بالثقوب.
          //
          //  فتُبنى القائمةُ وتُصفّى **ثمّ** تُسلَّم للشبكة.
          // ══════════════════════════════════════════════════════════
          _ToolGrid(children: [
              AccessGate(feature: 'products', child: _MiniAction(
                icon: Icons.inventory_2, label: 'المنتجات',
                color: AmialColors.primary,
                onTap: () => Get.to(() => const CashierProductsScreen()),
              )),
              // AMIAL-RETAIL-VERTICAL-001 · المرحلة ١٠ — **زرُّ «المخزون»
              // كان يفتح قائمةَ المنتجات**: عنوانٌ يَعِد بالمخزون ويُعطي
              // الكتالوج، ولا مكانَ للمواقع ولا التحويلات ولا الجرد.
              AccessGate(feature: 'inventory', child: _MiniAction(
                icon: Icons.warehouse, label: 'المخزون',
                color: AmialColors.primary,
                onTap: () => Get.to(() => const RetailOpsCenterScreen()),
              )),
              AccessGate(feature: 'customers', child: _MiniAction(
                icon: Icons.people, label: 'العملاء',
                color: AmialColors.primary,
                onTap: () => Get.to(() => const CreditCustomersScreen()),
              )),
              AccessGate(feature: 'debts', child: _MiniAction(
                icon: Icons.account_balance_wallet, label: 'الديون',
                color: AmialColors.primary,
                onTap: () => Get.to(() => const CreditDashboardScreen()),
              )),
              AccessGate(feature: 'barcode', child: _MiniAction(
                icon: Icons.qr_code_scanner, label: 'مسح باركود',
                color: AmialColors.primary,
                onTap: () async {
                  final scanned = await Get.to<List<ScannedItem>>(
                    () => const ContinuousScannerScreen(context: 'auto'),
                  );
                  if (scanned != null && scanned.isNotEmpty) {
                    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
                      content: Text('✓ تم مسح ${scanned.length} صنف بإجمالي '
                          '${scanned.fold(0.0, (s, c) => s + c.unitPrice * c.quantity).toStringAsFixed(0)} ر.ي'),
                      backgroundColor: Colors.green,
                      duration: const Duration(seconds: 3),
                    ));
                  }
                },
              )),
              AccessGate(feature: 'daily_reports', child: _MiniAction(
                icon: Icons.bar_chart, label: 'التقارير',
                color: AmialColors.primary,
                onTap: () => Get.to(() => const FinancialTruthReportScreen(dailyOnly: true)),
              )),
              AccessGate(feature: 'employees', child: _MiniAction(
                icon: Icons.badge, label: 'الموظفون',
                color: AmialColors.primary,
                onTap: () => Get.to(() => const MerchantStaffScreen()),
              )),
              AccessGate(feature: 'excel_export', child: _MiniAction(
                icon: Icons.grid_on, label: 'تصدير Excel',
                color: AmialColors.primary,
                onTap: () => Get.to(() => const MerchantExcelExportScreen()),
              )),
              AccessGate(feature: 'audit_log', child: _MiniAction(
                icon: Icons.fact_check, label: 'سجلّ التدقيق',
                color: AmialColors.primary,
                onTap: () => Get.to(() => const MerchantAuditLogScreen()),
              )),
              _MiniAction(
                icon: Icons.receipt_long, label: 'إعدادات الفاتورة',
                color: AmialColors.primary,
                onTap: () => Get.to(() => const ReceiptSettingsScreen()),
              ),
              AccessGate(feature: 'promotions', child: _MiniAction(
                icon: Icons.local_offer, label: 'العروض والخصومات',
                color: AmialColors.primary,
                onTap: () => Get.to(() => const MerchantPromotionsScreen()),
              )),
              AccessGate(feature: 'loyalty', child: _MiniAction(
                icon: Icons.card_giftcard, label: 'برنامج الولاء',
                color: const Color(0xFFB8860B),
                onTap: () => Get.to(() => const MerchantLoyaltyScreen()),
              )),
              AccessGate(feature: 'gift_cards', child: _MiniAction(
                icon: Icons.redeem, label: 'بطاقات الهدايا',
                color: const Color(0xFF7B1FA2),
                onTap: () => Get.to(() => const MerchantGiftCardsScreen()),
              )),
              AccessGate(feature: 'shift_close', child: _MiniAction(
                icon: Icons.point_of_sale, label: 'إقفال الوردية',
                color: const Color(0xFF455A64),
                onTap: () => Get.to(() => const CashierShiftScreen()),
              )),
              AccessGate(feature: 'expenses', child: _MiniAction(
                icon: Icons.receipt_long, label: 'المصروفات',
                color: AmialColors.primary,
                onTap: () => Get.to(() => const MerchantExpensesScreen()),
              )),
              AccessGate(feature: 'installments', child: _MiniAction(
                icon: Icons.handshake, label: 'البيع بالتقسيط',
                color: const Color(0xFF00695C),
                onTap: () => Get.to(() => const MerchantInstallmentsScreen()),
              )),
              AccessGate(feature: 'restaurant_orders', child: _MiniAction(
                icon: Icons.restaurant, label: 'المطعم',
                color: const Color(0xFFC2410C),
                onTap: () => Get.to(() => const RestaurantScreen()),
              )),
              AccessGate(feature: 'corporate_accounts', child: _MiniAction(
                icon: Icons.business_center, label: 'حسابات الشركات',
                color: AmialColors.primary,
                onTap: () => Get.to(() => const CorporateAccountsScreen()),
              )),
              AccessGate(feature: 'multi_currency', child: _MiniAction(
                icon: Icons.currency_exchange, label: 'العملات',
                color: AmialColors.primary,
                onTap: () => Get.to(() => const MerchantCurrenciesScreen()),
              )),
              AccessGate(feature: 'api_access', child: _MiniAction(
                icon: Icons.api, label: 'مفاتيح API',
                color: AmialColors.primary,
                onTap: () => Get.to(() => const MerchantApiKeysScreen()),
              )),
              AccessGate(feature: 'advanced_backup', child: _MiniAction(
                icon: Icons.backup, label: 'نسخة احتياطية',
                color: AmialColors.primary,
                onTap: () => Get.to(() => const MerchantBackupScreen()),
              )),
          ]),

          // CTA ترقية إن FREE
          Obx(() => access.isFreePlan ? Padding(
            padding: const EdgeInsets.only(top: 16),
            child: _UpgradeBanner(),
          ) : const SizedBox.shrink()),
        ]),
      ),
    );
  }
}

// =========================================================================
// Helpers مشتركة
// =========================================================================

class _GreetingCard extends StatelessWidget {
  final String subtitle;
  const _GreetingCard({required this.subtitle});

  @override
  Widget build(BuildContext context) {
    final access = Get.find<AccessController>();
    return Container(
      padding: const EdgeInsets.all(18),
      decoration: BoxDecoration(
        gradient: const LinearGradient(
          colors: [AmialColors.primary, Color(0xFF021A55)],
          begin: Alignment.topLeft, end: Alignment.bottomRight,
        ),
        borderRadius: BorderRadius.circular(16),
      ),
      child: Obx(() => Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Text(access.businessName,
            style: const TextStyle(color: Colors.white, fontSize: 19, fontWeight: FontWeight.bold)),
        const SizedBox(height: 4),
        Text(subtitle, style: const TextStyle(color: Colors.white70, fontSize: 13)),
        const SizedBox(height: 8),
        Row(children: [
          if (access.businessTypeLabel.value != null) Container(
            padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
            decoration: BoxDecoration(
              color: AmialColors.yellow,
              borderRadius: BorderRadius.circular(6),
            ),
            child: Text(access.businessTypeLabel.value ?? '',
                style: const TextStyle(color: Colors.black87, fontWeight: FontWeight.bold, fontSize: 11)),
          ),
          const SizedBox(width: 6),
          if (access.subscriptionPlanLabel.value != null && !access.isFreePlan) Container(
            padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
            decoration: BoxDecoration(
              color: Colors.white.withValues(alpha: 0.2),
              borderRadius: BorderRadius.circular(6),
            ),
            child: Text(access.subscriptionPlanLabel.value ?? '',
                style: const TextStyle(color: Colors.white, fontWeight: FontWeight.bold, fontSize: 11)),
          ),
        ]),
      ])),
    );
  }
}

class _BigActionButton extends StatelessWidget {
  final IconData icon;
  final String label;
  final String subtitle;
  final Color color;
  final VoidCallback onTap;

  const _BigActionButton({
    required this.icon, required this.label, required this.subtitle,
    required this.color, required this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    return InkWell(
      borderRadius: BorderRadius.circular(16),
      onTap: onTap,
      child: Container(
        padding: const EdgeInsets.all(18),
        decoration: BoxDecoration(color: color, borderRadius: BorderRadius.circular(16)),
        child: Row(children: [
          Container(
            width: 54, height: 54,
            decoration: BoxDecoration(
              color: Colors.white.withValues(alpha: 0.2),
              borderRadius: BorderRadius.circular(14),
            ),
            child: Icon(icon, color: Colors.white, size: 30),
          ),
          const SizedBox(width: 14),
          Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(label, style: const TextStyle(color: Colors.white, fontSize: 18, fontWeight: FontWeight.bold)),
            Text(subtitle, style: const TextStyle(color: Colors.white70, fontSize: 12)),
          ])),
          const Icon(Icons.chevron_left, color: Colors.white),
        ]),
      ),
    );
  }
}

class _MiniAction extends StatelessWidget {
  final IconData icon;
  final String label;
  final Color color;
  final VoidCallback onTap;

  const _MiniAction({required this.icon, required this.label, required this.color, required this.onTap});

  @override
  Widget build(BuildContext context) {
    return InkWell(
      borderRadius: BorderRadius.circular(14),
      onTap: onTap,
      child: Container(
        decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(14)),
        child: Column(mainAxisAlignment: MainAxisAlignment.center, children: [
          Icon(icon, color: color, size: 28),
          const SizedBox(height: 6),
          Text(label, style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13)),
        ]),
      ),
    );
  }
}

class _UpgradeBanner extends StatelessWidget {
  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        gradient: LinearGradient(colors: [AmialColors.yellow, AmialColors.yellow.withValues(alpha: 0.7)]),
        borderRadius: BorderRadius.circular(12),
      ),
      child: Row(children: [
        const Icon(Icons.workspace_premium, color: Colors.black87),
        const SizedBox(width: 10),
        const Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text('ترقية لخطّة مدفوعة', style: TextStyle(fontWeight: FontWeight.bold)),
          Text('للحصول على ميزات أكثر', style: TextStyle(fontSize: 11)),
        ])),
        Icon(Icons.chevron_left, color: Colors.black87),
      ]),
    );
  }
}

/// AMIAL-GRID-HOLES-001 — **شبكةٌ بلا ثقوب.**
///
/// ══════════════════════════════════════════════════════════════════════
/// `GridView.count` يوزّع أبناءه **بالموضع**، و`AccessGate` يُرجع
/// `SizedBox.shrink()` عند القفل — ودجتٌ بصفر حجمٍ **تحتلّ خليّةً كاملة**.
/// فكانت الشاشةُ الأولى التي يراها التاجرُ مثقوبةً بعدد ما لا يملكه.
///
/// **والحلُّ أن تُصفّى القائمةُ قبل التوزيع لا بعده.** ويُقاس التصفيةُ
/// بأن يُسأل `AccessGate` عن حالته مسبقاً — فيُبنى منه `_GatedTool` يحمل
/// الشرطَ بدل أن يبتلعه.
class _ToolGrid extends StatelessWidget {
  final List<Widget> children;

  const _ToolGrid({required this.children});

  @override
  Widget build(BuildContext context) {
    final access = Get.find<AccessController>();

    return Obx(() {
      // **يُسأل الشرطُ هنا** — فما لا يُعرض لا يحجز خليّة.
      final visible = children.where((w) {
        if (w is! AccessGate) return true;

        final g = w;
        var ok = true;
        if (g.feature != null) ok = ok && access.has(g.feature!);
        if (g.anyOf != null) ok = ok && access.hasAny(g.anyOf!);
        if (g.allOf != null) ok = ok && access.hasAll(g.allOf!);

        return ok || g.fallback != null;
      }).toList();

      if (visible.isEmpty) return const SizedBox.shrink();

      return GridView.count(
        crossAxisCount: 2,
        shrinkWrap: true,
        physics: const NeverScrollableScrollPhysics(),
        mainAxisSpacing: 10,
        crossAxisSpacing: 10,
        childAspectRatio: 1.3,
        children: visible,
      );
    });
  }
}
