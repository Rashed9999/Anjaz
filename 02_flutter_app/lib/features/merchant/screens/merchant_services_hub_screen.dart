import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';
import 'package:amyal_pay/features/access/controllers/access_controller.dart';
import 'package:amyal_pay/features/plans/screens/plans_catalog_screen.dart';
// شاشات الخدمات
import 'package:amyal_pay/features/merchant/screens/cashier_pos_screen.dart';
import 'package:amyal_pay/features/merchant/screens/profit_report_screen.dart';
import 'package:amyal_pay/features/merchant/screens/merchant_staff_screen.dart';
import 'package:amyal_pay/features/merchant/screens/merchant_audit_log_screen.dart';
import 'package:amyal_pay/features/merchant/screens/merchant_excel_export_screen.dart';
import 'package:amyal_pay/features/merchant/screens/merchant_currencies_screen.dart';
import 'package:amyal_pay/features/merchant/screens/merchant_api_keys_screen.dart';
import 'package:amyal_pay/features/merchant/screens/merchant_backup_screen.dart';
import 'package:amyal_pay/features/merchant/screens/merchant_loyalty_screen.dart';
import 'package:amyal_pay/features/merchant/screens/merchant_promotions_screen.dart';
import 'package:amyal_pay/features/merchant/screens/merchant_installments_screen.dart';
import 'package:amyal_pay/features/merchant/screens/merchant_gift_cards_screen.dart';
import 'package:amyal_pay/features/merchant/screens/cashier_shift_screen.dart';
import 'package:amyal_pay/features/merchant/screens/merchant_expenses_screen.dart';
import 'package:amyal_pay/features/branches/screens/branches_management_screen.dart';
import 'package:amyal_pay/features/corporate/screens/corporate_accounts_screen.dart';

/// AMIAL-MERCHANT-SERVICES-HUB-001 — «مركز خدمات التاجر».
///
/// شاشة واحدة تعرض **كل** خدمات التاجر مجمّعة حسب الفئة. كل خدمة:
///   • مفتوحة حسب الباقة الحالية (access.has) → تفتح شاشتها.
///   • مقفلة → رمادية + قفل + اسم الباقة التي تفتحها.
/// الضغط على أيّ خدمة يفتح ورقة شرح: ماذا تفعل الخدمة + زر «افتح» أو «ترقية».
class MerchantServicesHubScreen extends StatelessWidget {
  const MerchantServicesHubScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final access = Get.find<AccessController>();
    return Scaffold(
      backgroundColor: AmyalColors.background,
      appBar: AppBar(
        title: const Text('خدمات التاجر'),
      ),
      body: Obx(() {
        final planLabel = access.subscriptionPlanLabel.value ?? _planName(access.subscriptionPlan.value);
        final unlocked = _catalog.where((s) => access.has(s.code)).length;
        return ListView(
          padding: const EdgeInsets.all(16),
          children: [
            _planHeader(planLabel, unlocked, _catalog.length),
            const SizedBox(height: 18),
            for (final group in _groups) ...[
              _sectionTitle(group.title, group.icon),
              const SizedBox(height: 10),
              GridView.count(
                crossAxisCount: 2,
                shrinkWrap: true,
                physics: const NeverScrollableScrollPhysics(),
                mainAxisSpacing: 10,
                crossAxisSpacing: 10,
                childAspectRatio: 1.05,
                children: group.codes
                    .map((c) => _catalog.firstWhere((s) => s.code == c))
                    .map((s) => _tile(context, access, s))
                    .toList(),
              ),
              const SizedBox(height: 20),
            ],
          ],
        );
      }),
    );
  }

  // ── بطاقة الباقة الحالية ──────────────────────────────────────────
  Widget _planHeader(String planLabel, int unlocked, int total) {
    return Container(
      padding: const EdgeInsets.all(18),
      decoration: BoxDecoration(
        gradient: const LinearGradient(
          colors: [AmyalColors.primary, Color(0xFF1D4FB8)],
          begin: Alignment.topRight, end: Alignment.bottomLeft,
        ),
        borderRadius: BorderRadius.circular(18),
      ),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Row(children: [
          const Icon(Icons.workspace_premium, color: AmyalColors.yellow, size: 26),
          const SizedBox(width: 10),
          Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              const Text('باقتك الحالية', style: TextStyle(color: Colors.white70, fontSize: 12)),
              Text('باقة $planLabel',
                  style: const TextStyle(color: Colors.white, fontSize: 18, fontWeight: FontWeight.bold)),
            ]),
          ),
          TextButton(
            onPressed: () => Get.to(() => const PlansCatalogScreen()),
            style: TextButton.styleFrom(
              backgroundColor: AmyalColors.yellow,
              foregroundColor: Colors.black,
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
            ),
            child: const Text('ترقية', style: TextStyle(fontWeight: FontWeight.bold)),
          ),
        ]),
        const SizedBox(height: 14),
        ClipRRect(
          borderRadius: BorderRadius.circular(6),
          child: LinearProgressIndicator(
            value: total == 0 ? 0 : unlocked / total,
            minHeight: 8,
            backgroundColor: Colors.white24,
            valueColor: const AlwaysStoppedAnimation(AmyalColors.yellow),
          ),
        ),
        const SizedBox(height: 6),
        Text('مفتوح لديك $unlocked من $total خدمة',
            style: const TextStyle(color: Colors.white70, fontSize: 12)),
      ]),
    );
  }

  Widget _sectionTitle(String title, IconData icon) => Row(children: [
        Icon(icon, size: 18, color: AmyalColors.primary),
        const SizedBox(width: 8),
        Text(title, style: const TextStyle(fontSize: 15, fontWeight: FontWeight.bold)),
      ]);

  // ── بطاقة خدمة (مفتوحة/مقفلة) ─────────────────────────────────────
  Widget _tile(BuildContext context, AccessController access, _Svc s) {
    final locked = !access.has(s.code);
    return InkWell(
      borderRadius: BorderRadius.circular(14),
      onTap: () => _showSheet(context, s, locked),
      child: Container(
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(
          color: locked ? const Color(0xFFEDEFF3) : Colors.white,
          borderRadius: BorderRadius.circular(14),
          border: Border.all(
              color: locked ? AmyalColors.border : AmyalColors.primary.withValues(alpha: 0.30)),
        ),
        child: Column(mainAxisAlignment: MainAxisAlignment.center, children: [
          Stack(alignment: Alignment.topRight, clipBehavior: Clip.none, children: [
            Container(
              width: 46, height: 46,
              decoration: BoxDecoration(
                color: locked ? Colors.grey.shade300 : AmyalColors.primary.withValues(alpha: 0.10),
                borderRadius: BorderRadius.circular(12),
              ),
              child: Icon(s.icon, size: 24, color: locked ? Colors.grey.shade600 : AmyalColors.primary),
            ),
            if (locked)
              Positioned(top: -4, right: -4,
                  child: Container(
                    padding: const EdgeInsets.all(3),
                    decoration: const BoxDecoration(color: AmyalColors.yellowDark, shape: BoxShape.circle),
                    child: const Icon(Icons.lock, size: 11, color: Colors.white),
                  )),
          ]),
          const SizedBox(height: 8),
          Text(s.title,
              textAlign: TextAlign.center, maxLines: 1, overflow: TextOverflow.ellipsis,
              style: TextStyle(
                  fontSize: 13, fontWeight: FontWeight.w600,
                  color: locked ? Colors.grey.shade700 : AmyalColors.textPrimary)),
          const SizedBox(height: 2),
          Text(locked ? 'باقة ${s.planLabel}' : 'مفتوحة',
              style: TextStyle(
                  fontSize: 10.5, fontWeight: FontWeight.bold,
                  color: locked ? AmyalColors.yellowDark : const Color(0xFF2E7D32))),
        ]),
      ),
    );
  }

  // ── ورقة شرح الخدمة ───────────────────────────────────────────────
  void _showSheet(BuildContext context, _Svc s, bool locked) {
    showModalBottomSheet(
      context: context,
      backgroundColor: Colors.white,
      isScrollControlled: true,
      shape: const RoundedRectangleBorder(
          borderRadius: BorderRadius.vertical(top: Radius.circular(24))),
      builder: (ctx) => Padding(
        padding: EdgeInsets.fromLTRB(22, 18, 22, 22 + MediaQuery.of(ctx).viewInsets.bottom),
        child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Center(
            child: Container(width: 44, height: 4,
                decoration: BoxDecoration(color: Colors.grey.shade300, borderRadius: BorderRadius.circular(2))),
          ),
          const SizedBox(height: 18),
          Row(children: [
            Container(
              width: 52, height: 52,
              decoration: BoxDecoration(
                color: (locked ? AmyalColors.yellowDark : AmyalColors.primary).withValues(alpha: 0.12),
                borderRadius: BorderRadius.circular(14),
              ),
              child: Icon(s.icon, color: locked ? AmyalColors.yellowDark : AmyalColors.primary),
            ),
            const SizedBox(width: 14),
            Expanded(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(s.title, style: const TextStyle(fontSize: 18, fontWeight: FontWeight.bold)),
                Row(children: [
                  Icon(locked ? Icons.lock : Icons.check_circle,
                      size: 14, color: locked ? AmyalColors.yellowDark : const Color(0xFF2E7D32)),
                  const SizedBox(width: 4),
                  Text(locked ? 'متوفّرة في باقة ${s.planLabel}' : 'مفتوحة في باقتك',
                      style: TextStyle(
                          fontSize: 12, fontWeight: FontWeight.w600,
                          color: locked ? AmyalColors.yellowDark : const Color(0xFF2E7D32))),
                ]),
              ]),
            ),
          ]),
          const SizedBox(height: 16),
          Text(s.desc, style: const TextStyle(fontSize: 14, height: 1.6, color: AmyalColors.textSecondary)),
          const SizedBox(height: 22),
          if (locked)
            FilledButton.icon(
              onPressed: () { Navigator.pop(ctx); Get.to(() => const PlansCatalogScreen()); },
              icon: const Icon(Icons.workspace_premium),
              label: Text('الترقية إلى باقة ${s.planLabel}'),
              style: FilledButton.styleFrom(
                backgroundColor: AmyalColors.yellowDark,
                minimumSize: const Size.fromHeight(52),
              ),
            )
          else if (s.builder != null)
            FilledButton.icon(
              onPressed: () { Navigator.pop(ctx); Get.to(s.builder!); },
              icon: const Icon(Icons.arrow_back),
              label: const Text('افتح الخدمة'),
              style: FilledButton.styleFrom(
                backgroundColor: AmyalColors.primary,
                minimumSize: const Size.fromHeight(52),
              ),
            )
          else
            Container(
              padding: const EdgeInsets.all(14),
              decoration: BoxDecoration(
                color: AmyalColors.background,
                borderRadius: BorderRadius.circular(12),
              ),
              child: const Row(children: [
                Icon(Icons.info_outline, color: AmyalColors.primary, size: 20),
                SizedBox(width: 10),
                Expanded(child: Text('تعمل هذه الخدمة تلقائياً داخل الكاشير — لا تحتاج فتح شاشة.',
                    style: TextStyle(fontSize: 13))),
              ]),
            ),
        ]),
      ),
    );
  }

  String _planName(String code) {
    switch (code) {
      case 'starter': return 'البداية';
      case 'business': return 'الأعمال';
      case 'merchant_pro': return 'التاجر برو';
      case 'enterprise': return 'المؤسسات';
      default: return 'المجانية';
    }
  }

  // ── فئات العرض ────────────────────────────────────────────────────
  static const List<_Group> _groups = [
    _Group('المبيعات والكاشير', Icons.point_of_sale,
        ['inventory', 'promotions', 'installments', 'gift_cards', 'shift_close', 'offline_pos']),
    _Group('المالية والتقارير', Icons.bar_chart,
        ['profit_reports', 'expenses', 'excel_export', 'multi_currency', 'advanced_backup']),
    _Group('العملاء والتسويق', Icons.groups,
        ['loyalty', 'corporate_accounts']),
    _Group('الإدارة والفريق', Icons.admin_panel_settings,
        ['branches', 'employees', 'audit_log', 'api_access']),
  ];

  // ── كتالوج الخدمات (الكود، العنوان، الشرح، الأيقونة، الباقة، الشاشة) ─
  static final List<_Svc> _catalog = [
    _Svc('inventory', 'المخزون والكاشير',
        'نقطة بيع كاملة: أضِف منتجاتك، امسح الباركود، وتابع الكميات مع تنبيه عند نفاد المخزون.',
        Icons.inventory_2, 'البداية', () => const CashierPosScreen()),
    _Svc('promotions', 'العروض والخصومات',
        'أنشئ كوبونات وخصومات (نسبة مئوية أو مبلغ ثابت) تُطبَّق تلقائياً عند الدفع في الكاشير.',
        Icons.local_offer, 'ستارتر', () => const MerchantPromotionsScreen()),
    _Svc('installments', 'البيع بالتقسيط',
        'بِع بالتقسيط بشروط وضمانات عالمية: دفعة أولى، كفيل، حدّ ائتماني، هامش مرابحة، ورسوم تأخير.',
        Icons.handshake, 'التاجر برو', () => const MerchantInstallmentsScreen()),
    _Svc('gift_cards', 'بطاقات الهدايا',
        'أصدر بطاقات هدايا برصيد مخزَّن يستخدمها العملاء لدى متجرك بكود فريد — تزيد ولاءهم وإنفاقهم.',
        Icons.card_giftcard, 'الأعمال', () => const MerchantGiftCardsScreen()),
    _Svc('shift_close', 'إقفال الوردية',
        'افتح وردية الكاشير وأقفلها بتقرير X/Z، مع حساب فرق الصندوق النقدي (العجز/الفائض).',
        Icons.lock_clock, 'الأعمال', () => const CashierShiftScreen()),
    _Svc('offline_pos', 'البيع دون اتصال',
        'استمر بالبيع عند انقطاع الإنترنت، وتُزامَن الفواتير تلقائياً وبأمان (بلا تكرار) عند عودة الاتصال.',
        Icons.cloud_off, 'الأعمال', null),
    _Svc('profit_reports', 'تقارير الأرباح',
        'تقارير مبيعات وأرباح مفصّلة لمتجرك، بمقارنات يومية وشهرية لتعرف أداءك الحقيقي.',
        Icons.trending_up, 'الأعمال', () => const ProfitReportScreen()),
    _Svc('expenses', 'المصروفات والصندوق',
        'سجّل مصروفات المتجر والصندوق النثري وصنّفها، لتحسب صافي ربحك بدقّة بعد المصاريف.',
        Icons.receipt_long, 'الأعمال', () => const MerchantExpensesScreen()),
    _Svc('excel_export', 'تصدير Excel',
        'صدّر مبيعاتك وبياناتك إلى ملفات Excel جاهزة للمحاسبة والمراجعة الخارجية.',
        Icons.grid_on, 'الأعمال', () => const MerchantExcelExportScreen()),
    _Svc('multi_currency', 'تعدّد العملات',
        'اعرض الأسعار وبِع بأكثر من عملة، مع أسعار صرف قابلة للتحديث يدوياً.',
        Icons.currency_exchange, 'التاجر برو', () => const MerchantCurrenciesScreen()),
    _Svc('advanced_backup', 'النسخ الاحتياطي',
        'أنشئ نسخاً احتياطية لبيانات متجرك واستعِدها عند الحاجة — أمان لبياناتك.',
        Icons.backup, 'التاجر برو', () => const MerchantBackupScreen()),
    _Svc('loyalty', 'برنامج الولاء',
        'كافئ عملاءك بنقاط على كل عملية شراء يستبدلونها بخصومات — وسيلة مثبتة لزيادة تكرار الشراء.',
        Icons.stars, 'الأعمال', () => const MerchantLoyaltyScreen()),
    _Svc('corporate_accounts', 'حسابات الشركات B2B',
        'افتح حسابات آجلة للشركات بحدّ ائتماني، وبِع على حساب الشركة وحصّل المستحقّات لاحقاً.',
        Icons.business_center, 'المؤسسات', () => const CorporateAccountsScreen()),
    _Svc('branches', 'الفروع',
        'أدر عدّة فروع لمتجرك تحت حساب واحد، مع تقارير منفصلة لكل فرع.',
        Icons.account_tree, 'التاجر برو', () => const BranchesManagementScreen()),
    _Svc('employees', 'الموظفون',
        'أضِف موظفي الكاشير بصلاحيات محدّدة، وتابع مبيعات وأداء كل موظف.',
        Icons.badge, 'الأعمال', () => const MerchantStaffScreen()),
    _Svc('audit_log', 'سجلّ التدقيق',
        'سجلّ كامل لكل عملية حسّاسة في متجرك: من فعل ماذا ومتى — للرقابة والأمان.',
        Icons.fact_check, 'التاجر برو', () => const MerchantAuditLogScreen()),
    _Svc('api_access', 'مفاتيح API',
        'اربط متجرك بأنظمتك الخارجية (محاسبة، مواقع) عبر مفاتيح API آمنة.',
        Icons.api, 'المؤسسات', () => const MerchantApiKeysScreen()),
  ];
}

class _Group {
  final String title;
  final IconData icon;
  final List<String> codes;
  const _Group(this.title, this.icon, this.codes);
}

class _Svc {
  final String code;
  final String title;
  final String desc;
  final IconData icon;
  final String planLabel;
  final Widget Function()? builder;
  _Svc(this.code, this.title, this.desc, this.icon, this.planLabel, this.builder);
}
