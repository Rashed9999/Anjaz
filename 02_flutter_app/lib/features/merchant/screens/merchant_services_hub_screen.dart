import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amial_pay/features/merchant/screens/merchant_pos_devices_screen.dart';
import 'package:amial_pay/features/merchant/screens/inventory_screen.dart';
import 'package:amial_pay/theme/amial_colors.dart';
import 'package:amial_pay/features/access/controllers/access_controller.dart';
import 'package:amial_pay/features/plans/screens/plans_catalog_screen.dart';
// شاشات الخدمات
import 'package:amial_pay/features/merchant/screens/financial_truth_report_screen.dart';
import 'package:amial_pay/features/merchant/screens/merchant_advanced_reports_screen.dart';
import 'package:amial_pay/features/merchant/screens/profit_report_screen.dart';
import 'package:amial_pay/features/merchant/screens/merchant_staff_screen.dart';
import 'package:amial_pay/features/merchant/screens/merchant_audit_log_screen.dart';
import 'package:amial_pay/features/merchant/screens/merchant_excel_export_screen.dart';
import 'package:amial_pay/features/merchant/screens/merchant_currencies_screen.dart';
import 'package:amial_pay/features/merchant/screens/merchant_api_keys_screen.dart';
import 'package:amial_pay/features/merchant/screens/merchant_backup_screen.dart';
import 'package:amial_pay/features/merchant/screens/merchant_loyalty_screen.dart';
import 'package:amial_pay/features/merchant/screens/merchant_promotions_screen.dart';
import 'package:amial_pay/features/merchant/screens/merchant_installments_screen.dart';
import 'package:amial_pay/features/merchant/screens/merchant_gift_cards_screen.dart';
import 'package:amial_pay/features/merchant/screens/cashier_shift_screen.dart';
import 'package:amial_pay/features/merchant/screens/merchant_expenses_screen.dart';
import 'package:amial_pay/features/branches/screens/branches_management_screen.dart';
import 'package:amial_pay/features/corporate/screens/corporate_accounts_screen.dart';
// AMIAL-SERVICES-CATALOG-002 — شاشات كانت مبنيّة بلا مدخل من «خدماتي»
import 'package:amial_pay/features/merchant/screens/offline_sales_screen.dart';
import 'package:amial_pay/features/merchant/screens/merchant_refund_screen.dart';
import 'package:amial_pay/features/merchant/screens/cashier_products_screen.dart';
import 'package:amial_pay/features/merchant/screens/receipt_settings_screen.dart';
import 'package:amial_pay/features/merchant/screens/split_bill_create_screen.dart';
import 'package:amial_pay/features/merchant/screens/inventory_audit_screen.dart';
import 'package:amial_pay/features/merchant/screens/stock_alerts_screen.dart';
import 'package:amial_pay/features/merchant/screens/credit_dashboard_screen.dart';
import 'package:amial_pay/features/merchant/screens/credit_customers_screen.dart';
import 'package:amial_pay/features/merchant/screens/merchant_transactions_screen.dart';
import 'package:amial_pay/features/merchant/screens/merchant_wallet_screen.dart';

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
      backgroundColor: AmialColors.background,
      appBar: AppBar(
        title: Text('خدمات التاجر'.tr),
      ),
      body: Obx(() {
        final planLabel = access.subscriptionPlanLabel.value ?? _planName(access.subscriptionPlan.value);

        // AMIAL-SERVICES-ORDER-001: الترتيب بالملكية لا بالتصنيف.
        //
        // كان الكتالوج مرتّباً بالمجموعات (مبيعات، مالية، …) فيتخلّل المقفلُ
        // المفتوحَ في كل مجموعة. والتاجر يفتح هذه الشاشة ليعمل لا ليتسوّق،
        // فيمرّ على ما لا يملكه في طريقه إلى ما يملكه.
        //
        // فصار ما يملكه أوّلاً وكاملاً، وما لا يملكه أسفلَه بعنوانٍ صريح.
        // والتصنيف لم يُفقَد — نزل إلى سطر تحت كل خدمة.
        // AMIAL-SERVICES-SCOPE-001: المقفل نوعان لا نوع واحد.
        //
        // الباقة تفتح خدمةً، ونوع النشاط يقرّر أنها تخصّه أصلاً. وكان القسم
        // المقفل يقول «متاح بترقية الباقة» عن الاثنين — فصاحب المحطة على
        // الباقة المؤسسية (أعلى ما يُشترى) يرى خدمةً مقفلة ودعوةً لترقيةٍ
        // لا وجود لها، ثم يرقّي فلا تُفتح. وعدٌ لا يستطيع النظام الوفاء به.
        final biz = access.businessType.value;
        bool fitsBusiness(_Svc s) =>
            s.onlyFor.isEmpty || (biz != null && s.onlyFor.contains(biz));

        // لا نعرض خدمة قطاع آخر كمفتوحة حتى لو وصلت قائمة قديمة من الخادم؛
        // الحارس في الخادم هو الحماية، وهذه طبقة منع التشويش في الواجهة.
        final open = _catalog.where((s) => access.has(s.code) && fitsBusiness(s)).toList();
        final closed = _catalog.where((s) => !access.has(s.code) || !fitsBusiness(s));
        final upgradable = closed.where(fitsBusiness).toList();
        final foreign = closed.where((s) => !fitsBusiness(s)).toList();

        return ListView(
          padding: const EdgeInsets.all(16),
          children: [
            _planHeader(planLabel, open.length, _catalog.length - foreign.length,
                isTopPlan: access.isEnterprisePlan),
            const SizedBox(height: 18),

            if (open.isNotEmpty) ...[
              _sectionTitle('خدماتك (${open.length})', Icons.check_circle_outline),
              const SizedBox(height: 10),
              _grid(context, access, open),
              const SizedBox(height: 22),
            ],

            if (upgradable.isNotEmpty) ...[
              _sectionTitle('متاح بترقية الباقة (${upgradable.length})', Icons.lock_outline),
              const SizedBox(height: 4),
              Padding(
                padding: EdgeInsets.only(bottom: 10),
                child: Text('اضغط أي خدمة لترى ما تفعله وباقتها'.tr,
                    style: TextStyle(fontSize: 11.5, color: AmialColors.textMuted)),
              ),
              _grid(context, access, upgradable),
              const SizedBox(height: 22),
            ],

            if (foreign.isNotEmpty) ...[
              _sectionTitle('لأنواع نشاط أخرى (${foreign.length})', Icons.block),
              const SizedBox(height: 4),
              Padding(
                padding: const EdgeInsets.only(bottom: 10),
                child: Text(
                    'هذه الخدمات مصمَّمة لنشاطات غير ${access.businessTypeLabel.value ?? 'نشاطك'} — لا تفتحها ترقية الباقة.',
                    style: const TextStyle(fontSize: 11.5, color: AmialColors.textMuted)),
              ),
              _grid(context, access, foreign),
            ],

            // باقةٌ تفتح كل ما يخصّ نشاطه: لا يُعرض قسم ترقية فارغ، ولا
            // يُقال «متاح بالترقية» لمن لا ترقية فوقه.
            if (upgradable.isEmpty)
              Container(
                padding: const EdgeInsets.all(16),
                decoration: BoxDecoration(
                  color: const Color(0xFFE8F5E9),
                  borderRadius: BorderRadius.circular(14),
                ),
                child: Row(children: [
                  const Icon(Icons.verified, color: AmialColors.success),
                  const SizedBox(width: 10),
                  Expanded(
                    child: Text('باقتك تفتح كل الخدمات — لا شيء محجوب عنك.'.tr,
                        style: TextStyle(
                            fontSize: 13, fontWeight: FontWeight.w600,
                            color: Colors.green.shade900)),
                  ),
                ]),
              ),

            const SizedBox(height: 24),
          ],
        );
      }),
    );
  }

  Widget _grid(BuildContext context, AccessController access, List<_Svc> items) {
    return GridView.count(
      crossAxisCount: 2,
      shrinkWrap: true,
      physics: const NeverScrollableScrollPhysics(),
      mainAxisSpacing: 10,
      crossAxisSpacing: 10,
      childAspectRatio: 1.05,
      children: items.map((s) => _tile(context, access, s)).toList(),
    );
  }

  // ── بطاقة الباقة الحالية ──────────────────────────────────────────
  Widget _planHeader(String planLabel, int unlocked, int total,
      {required bool isTopPlan}) {
    return Container(
      padding: const EdgeInsets.all(18),
      decoration: BoxDecoration(
        gradient: const LinearGradient(
          colors: [AmialColors.primary, Color(0xFF1D4FB8)],
          begin: Alignment.topRight, end: Alignment.bottomLeft,
        ),
        borderRadius: BorderRadius.circular(18),
      ),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Row(children: [
          const Icon(Icons.workspace_premium, color: AmialColors.yellow, size: 26),
          const SizedBox(width: 10),
          Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text('باقتك الحالية'.tr, style: TextStyle(color: Colors.white70, fontSize: 12)),
              Text('باقة $planLabel',
                  style: const TextStyle(color: Colors.white, fontSize: 18, fontWeight: FontWeight.bold)),
            ]),
          ),
          // AMIAL-PLAN-TRUTH-001 — **ولا يُدعى إلى ترقيةٍ من هو في أعلاها.**
          //
          // هذا الملفُّ نفسُه يقول في قسم المقفلات: «صاحبُ المحطّة على
          // الباقة المؤسسيّة يرى دعوةً لترقيةٍ لا وجودَ لها، ثمّ يرقّي
          // فلا تُفتح — **وعدٌ لا يستطيع النظامُ الوفاءَ به**». والقاعدةُ
          // طُبّقت هناك **ونُسيت هنا**، فبقي الزرُّ في الترويسة يدعو
          // صاحبَ «مؤسسة» إلى ما فوقها ولا شيءَ فوقها.
          if (!isTopPlan)
            TextButton(
              onPressed: () => Get.to(() => const PlansCatalogScreen()),
              style: TextButton.styleFrom(
                backgroundColor: AmialColors.yellow,
                foregroundColor: Colors.black,
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
              ),
              child: Text('ترقية'.tr, style: TextStyle(fontWeight: FontWeight.bold)),
            ),
        ]),
        const SizedBox(height: 14),
        ClipRRect(
          borderRadius: BorderRadius.circular(6),
          child: LinearProgressIndicator(
            value: total == 0 ? 0 : unlocked / total,
            minHeight: 8,
            backgroundColor: Colors.white24,
            valueColor: const AlwaysStoppedAnimation(AmialColors.yellow),
          ),
        ),
        const SizedBox(height: 6),
        // ══════════════════════════════════════════════════════════
        // AMIAL-PLAN-TRUTH-001 — **رقمان لشيءٍ واحد، وكلاهما صحيح.**
        //
        // أرسل صاحبُ المشروع صورتين: «مزايا باقتي» تقول «٤٩ متاحة»،
        // وهذه تقول «٢٩ من ٢٩». **وليس أحدُهما خطأً في الحساب**:
        //
        //   · «مزايا باقتي» تقرأ `/me/entitlements` ← `CapabilityRegistry`
        //     في الخادم — **٧١ قدرة**.
        //   · وهذه تقرأ `_catalog` **مكتوباً يدويّاً في هذا الملفّ** —
        //     ٣٠ عنصراً، وهي ما لها شاشةٌ في التطبيق.
        //
        // فالعطلُ أنّ الاثنين يسمّيان ما يعدّانه «خدمة». **فيُسمّى كلٌّ
        // باسمه**، ويُقال أين تُقرأ القائمةُ الكاملة — ولا يُترك القارئُ
        // يوفّق بين رقمين لا يلتقيان.
        //
        // (والقائمةُ المكتوبةُ تشيخ يومَ تُضاف قدرةٌ في الخادم ولا تظهر
        // هنا أبداً — وهو ما تحرسه `MerchantServicesCatalogGuardTest`.)
        // ══════════════════════════════════════════════════════════
        Text('مفتوح لديك $unlocked من $total شاشة في التطبيق',
            style: const TextStyle(color: Colors.white70, fontSize: 12)),
        const SizedBox(height: 2),
        Text('وقائمةُ مزايا باقتك كاملةً في «مزايا باقتي».'.tr,
            style: TextStyle(color: Colors.white60, fontSize: 11)),
      ]),
    );
  }

  // العنوان يحمل عدداً متغيّراً («متاح بترقية الباقة (24)»)، فيطول ويفيض على
  // هاتفٍ ضيّق — التقطه اختبار الترتيب عند عرض 400. وExpanded يجعل النصّ
  // يقصّ نفسه بدل أن يدفع الصفّ خارج الشاشة.
  Widget _sectionTitle(String title, IconData icon) => Row(children: [
        Icon(icon, size: 18, color: AmialColors.primary),
        const SizedBox(width: 8),
        Expanded(
          child: Text(title,
              maxLines: 1, overflow: TextOverflow.ellipsis,
              style: const TextStyle(fontSize: 15, fontWeight: FontWeight.bold)),
        ),
      ]);

  // ── بطاقة خدمة (مفتوحة/بالترقية/لنشاط آخر) ────────────────────────
  Widget _tile(BuildContext context, AccessController access, _Svc s) {
    final biz = access.businessType.value;
    final foreign = s.onlyFor.isNotEmpty && (biz == null || !s.onlyFor.contains(biz));
    final locked = !access.has(s.code);
    return InkWell(
      borderRadius: BorderRadius.circular(14),
      onTap: () => _showSheet(context, s, locked, foreign),
      child: Container(
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(
          color: locked ? const Color(0xFFEDEFF3) : Colors.white,
          borderRadius: BorderRadius.circular(14),
          border: Border.all(
              color: locked ? AmialColors.border : AmialColors.primary.withValues(alpha: 0.30)),
        ),
        child: Column(mainAxisAlignment: MainAxisAlignment.center, children: [
          Stack(alignment: Alignment.topRight, clipBehavior: Clip.none, children: [
            Container(
              width: 46, height: 46,
              decoration: BoxDecoration(
                color: locked ? Colors.grey.shade300 : AmialColors.primary.withValues(alpha: 0.10),
                borderRadius: BorderRadius.circular(12),
              ),
              child: Icon(s.icon, size: 24, color: locked ? Colors.grey.shade600 : AmialColors.primary),
            ),
            if (locked)
              Positioned(top: -4, right: -4,
                  child: Container(
                    padding: const EdgeInsets.all(3),
                    decoration: BoxDecoration(
                        color: foreign ? Colors.grey.shade500 : AmialColors.yellowDark,
                        shape: BoxShape.circle),
                    child: Icon(foreign ? Icons.block : Icons.lock,
                        size: 11, color: Colors.white),
                  )),
          ]),
          const SizedBox(height: 8),
          Text(s.title,
              textAlign: TextAlign.center, maxLines: 1, overflow: TextOverflow.ellipsis,
              style: TextStyle(
                  fontSize: 13, fontWeight: FontWeight.w600,
                  color: locked ? Colors.grey.shade700 : AmialColors.textPrimary)),
          const SizedBox(height: 1),
          Text(_categoryOf(s.code),
              maxLines: 1, overflow: TextOverflow.ellipsis,
              style: const TextStyle(fontSize: 9.5, color: AmialColors.textMuted)),
          const SizedBox(height: 2),
          Text(
              foreign
                  ? 'لنشاط آخر'.tr
                  : locked
                      ? 'باقة ${s.planLabel}'
                      : 'مفتوحة'.tr,
              style: TextStyle(
                  fontSize: 10.5, fontWeight: FontWeight.bold,
                  color: foreign
                      ? Colors.grey.shade600
                      : locked
                          ? AmialColors.yellowDark
                          : AmialColors.success)),
        ]),
      ),
    );
  }

  // ── ورقة شرح الخدمة ───────────────────────────────────────────────
  void _showSheet(BuildContext context, _Svc s, bool locked, bool foreign) {
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
                color: (locked ? AmialColors.yellowDark : AmialColors.primary).withValues(alpha: 0.12),
                borderRadius: BorderRadius.circular(14),
              ),
              child: Icon(s.icon, color: locked ? AmialColors.yellowDark : AmialColors.primary),
            ),
            const SizedBox(width: 14),
            Expanded(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(s.title, style: const TextStyle(fontSize: 18, fontWeight: FontWeight.bold)),
                Row(children: [
                  Icon(
                      foreign
                          ? Icons.block
                          : locked
                              ? Icons.lock
                              : Icons.check_circle,
                      size: 14,
                      color: foreign
                          ? Colors.grey.shade600
                          : locked
                              ? AmialColors.yellowDark
                              : AmialColors.success),
                  const SizedBox(width: 4),
                  Text(
                      foreign
                          ? 'ليست لنشاطك'.tr
                          : locked
                              ? 'متوفّرة في باقة ${s.planLabel}'
                              : 'مفتوحة في باقتك'.tr,
                      style: TextStyle(
                          fontSize: 12, fontWeight: FontWeight.w600,
                          color: foreign
                              ? Colors.grey.shade600
                              : locked
                                  ? AmialColors.yellowDark
                                  : AmialColors.success)),
                ]),
              ]),
            ),
          ]),
          const SizedBox(height: 16),
          Text(s.desc, style: const TextStyle(fontSize: 14, height: 1.6, color: AmialColors.textSecondary)),
          const SizedBox(height: 22),
          // خدمةٌ لنشاطٍ آخر: لا زرّ ترقية. زرٌّ لا يُوصل إلى شيء أسوأ من
          // غيابه — يدفع صاحب المحطة إلى شراء باقةٍ أعلى لن تفتحها له.
          if (foreign)
            Container(
              padding: const EdgeInsets.all(14),
              decoration: BoxDecoration(
                color: const Color(0xFFF3F4F6),
                borderRadius: BorderRadius.circular(12),
              ),
              child: Row(children: [
                Icon(Icons.info_outline, color: Colors.grey.shade700, size: 20),
                const SizedBox(width: 10),
                Expanded(
                  child: Text(
                      'هذه الخدمة مخصَّصة لـ${_bizNames(s.onlyFor)}. '
                      'ترقية الباقة لا تفتحها — تُفتح بتغيير نوع النشاط.'.tr,
                      style: const TextStyle(fontSize: 13, height: 1.5)),
                ),
              ]),
            )
          else if (locked)
            FilledButton.icon(
              onPressed: () { Navigator.pop(ctx); Get.to(() => const PlansCatalogScreen()); },
              icon: const Icon(Icons.workspace_premium),
              label: Text('الترقية إلى باقة ${s.planLabel}'),
              style: FilledButton.styleFrom(
                backgroundColor: AmialColors.yellowDark,
                minimumSize: const Size.fromHeight(52),
              ),
            )
          else if (s.builder != null)
            FilledButton.icon(
              onPressed: () { Navigator.pop(ctx); Get.to(s.builder!); },
              icon: const Icon(Icons.arrow_back),
              label: Text('افتح الخدمة'.tr),
              style: FilledButton.styleFrom(
                backgroundColor: AmialColors.primary,
                minimumSize: const Size.fromHeight(52),
              ),
            )
          else
            Container(
              padding: const EdgeInsets.all(14),
              decoration: BoxDecoration(
                color: AmialColors.background,
                borderRadius: BorderRadius.circular(12),
              ),
              child: Row(children: [
                Icon(Icons.info_outline, color: AmialColors.primary, size: 20),
                SizedBox(width: 10),
                Expanded(child: Text('تعمل هذه الخدمة تلقائياً داخل الكاشير — لا تحتاج فتح شاشة.'.tr,
                    style: TextStyle(fontSize: 13))),
              ]),
            ),
        ]),
      ),
    );
  }

  /// أسماء أنواع النشاط بالعربية — للجملة «مخصَّصة لـ…».
  static String _bizNames(Set<String> codes) {
    final labels = {
      'quick_sale': 'البيع السريع'.tr,
      'retail': 'التجزئة'.tr,
      'fuel': 'محطات الوقود'.tr,
      'pharmacy': 'الصيدليات'.tr,
      'wholesale': 'تجارة الجملة'.tr,
      'restaurant': 'المطاعم'.tr,
    };
    return codes.map((c) => labels[c] ?? c).join(' و'.tr);
  }

  String _planName(String code) {
    switch (code) {
      case 'business': return 'الأعمال'.tr;
      case 'enterprise': return 'مؤسسة'.tr;
      default: return 'المجانية'.tr;
    }
  }

  // ── فئات العرض ────────────────────────────────────────────────────
  /// تصنيف الخدمة — لم يُفقَد بإعادة الترتيب، نزل تحت اسمها.
  static String _categoryOf(String code) {
    for (final g in _groups) {
      if (g.codes.contains(code)) return g.title;
    }
    return 'أخرى'.tr;
  }

  static final List<_Group> _groups = [
    _Group('المبيعات والكاشير'.tr, Icons.point_of_sale, [
      'products', 'promotions', 'installments', 'gift_cards',
      'shift_close', 'offline_pos', 'refunds', 'split_bill', 'receipts',
    ]),
    _Group('المخزون والجرد'.tr, Icons.inventory_2,
        ['inventory', 'inventory_audit', 'low_stock_alerts']),
    _Group('المالية والتقارير'.tr, Icons.bar_chart, [
      'profit_reports', 'advanced_reports', 'expenses', 'excel_export', 'multi_currency',
      'advanced_backup', 'daily_reports', 'wallet',
    ]),
    _Group('العملاء والتسويق'.tr, Icons.groups,
        ['loyalty', 'corporate_accounts', 'debts', 'customers']),
    _Group('الإدارة والفريق'.tr, Icons.admin_panel_settings,
        ['branches', 'employees', 'audit_log', 'api_access']),
  ];

  // ── كتالوج الخدمات (الكود، العنوان، الشرح، الأيقونة، الباقة، الشاشة) ─
  //
  // AMIAL-SERVICES-CATALOG-002 — «تظهر جميع الخدمات».
  //
  // كان الكتالوج سبع عشرة خدمة بينما في التطبيق شاشاتٌ مبنيّة وتعمل لا
  // مدخل لها من هنا: الآجل، المرتجعات، الجرد، تنبيهات النفاد، تقرير اليوم،
  // العملاء… وكانت تُفتح من `MerchantDashboardScreen` وحدها — وصاحب محطة
  // الوقود لا يمرّ بها أبداً، لأن الموزِّع يرسله إلى لوحة المحطة مباشرة.
  // فالشاشة مبنيّة ومدفوعٌ ثمنها في الباقة ولا يُمكن الوصول إليها.
  //
  // **الأكواد هنا ليست أسماء عرض**: كلٌّ منها مفتاح ميزة يقرؤه الخادم في
  // `AccessPresets`. كودٌ مخترَع يجعل `access.has` تُرجع false دائماً،
  // فتظهر خدمةٌ يملكها التاجر مقفلةً إلى الأبد بلا خطأ ولا رسالة.
  static final List<_Svc> _catalog = [
    // AMIAL-INVENTORY-LINK-001: كانت تفتح CashierPosScreen — أي نقطة البيع
    // لا المخزون. فيضغط التاجر «المخزون» فيُفتح له الكاشير، بلا خطأ ولا
    // رسالة: شاشةٌ خاطئة تعمل بثقة. وInventoryScreen مبنيّة وكانت مهملة.
    _Svc('inventory', 'المخزون'.tr,
        'أضِف منتجاتك وتابع كمياتها، مع تنبيه عند اقتراب النفاد وجردٍ دوريّ.'.tr,
        Icons.inventory_2, 'الأعمال'.tr, () => InventoryScreen(),
        onlyFor: {'retail', 'wholesale'}),
    _Svc('promotions', 'العروض والخصومات'.tr,
        'أنشئ كوبونات وخصومات (نسبة مئوية أو مبلغ ثابت) تُطبَّق تلقائياً عند الدفع في الكاشير.'.tr,
        Icons.local_offer, 'الأعمال'.tr, () => MerchantPromotionsScreen()),
    _Svc('installments', 'البيع بالتقسيط'.tr,
        'بِع بالتقسيط بشروط وضمانات عالمية: دفعة أولى، كفيل، حدّ ائتماني، هامش مرابحة، ورسوم تأخير.'.tr,
        Icons.handshake, 'مؤسسة'.tr, () => MerchantInstallmentsScreen()),
    _Svc('gift_cards', 'بطاقات الهدايا'.tr,
        'أصدر بطاقات هدايا برصيد مخزَّن يستخدمها العملاء لدى متجرك بكود فريد — تزيد ولاءهم وإنفاقهم.'.tr,
        Icons.card_giftcard, 'الأعمال'.tr, () => MerchantGiftCardsScreen()),
    _Svc('shift_close', 'إقفال الوردية'.tr,
        'افتح وردية الكاشير وأقفلها بتقرير X/Z، مع حساب فرق الصندوق النقدي (العجز/الفائض).'.tr,
        Icons.lock_clock, 'الأعمال'.tr, () => CashierShiftScreen()),
    // كانت بلا شاشة (null) فتفتح ورقةً تقول «تعمل تلقائياً داخل الكاشير»،
    // بينما OfflineSalesScreen مبنيّة وتعرض ما لم يُزامَن بعد. ومن باع دون
    // اتصال يحتاج بالضبط أن يرى ما لم يصل الخادم — لا أن يُطمأن عنه.
    _Svc('offline_pos', 'البيع دون اتصال'.tr,
        'استمر بالبيع عند انقطاع الإنترنت، وتابع هنا ما لم يُزامَن بعد — تُرفع الفواتير تلقائياً وبلا تكرار عند عودة الاتصال.'.tr,
        Icons.cloud_off, 'الأعمال'.tr, () => OfflineSalesScreen()),
    _Svc('refunds', 'المرتجعات'.tr,
        'أرجع مبلغ عملية بيع كلياً أو جزئياً، مع تسجيل السبب وربط المرتجع بفاتورته الأصلية.'.tr,
        Icons.assignment_return, 'المجانية'.tr, () => MerchantRefundScreen()),
    _Svc('products', 'المنتجات والأسعار'.tr,
        'كتالوج منتجاتك: السعر والتكلفة والباركود وتاريخ الانتهاء — يُستخدم في الكاشير مباشرة.'.tr,
        Icons.sell, 'الأعمال'.tr, () => CashierProductsScreen(),
        onlyFor: {'retail', 'wholesale'}),
    _Svc('receipts', 'إعدادات الفاتورة'.tr,
        'اسم المتجر وشعاره وبيانات التواصل التي تُطبع أعلى كل إيصال، مع رسالة ختامية للعميل.'.tr,
        Icons.receipt, 'المجانية'.tr, () => ReceiptSettingsScreen()),
    _Svc('split_bill', 'تقسيم الفاتورة'.tr,
        'قسّم فاتورة واحدة على عدّة أشخاص، ويدفع كلٌّ حصّته من محفظته.'.tr,
        Icons.call_split, 'المجانية'.tr, () => SplitBillCreateScreen(),
        onlyFor: {'retail'}),
    _Svc('inventory_audit', 'الجرد'.tr,
        'جردٌ دوريّ يقارن الكمية الدفترية بالكمية الفعلية على الرفّ ويُظهر الفروق صنفاً صنفاً.'.tr,
        Icons.checklist, 'الأعمال'.tr, () => InventoryAuditScreen(),
        onlyFor: {'retail', 'wholesale'}),
    _Svc('low_stock_alerts', 'تنبيهات النفاد'.tr,
        'حدّد لكل صنف حدّاً أدنى، ونبّهك قبل نفاده بوقتٍ يكفي لإعادة الطلب.'.tr,
        Icons.notification_important, 'الأعمال'.tr, () => StockAlertsScreen(),
        onlyFor: {'retail', 'wholesale'}),
    _Svc('debts', 'البيع بالآجل'.tr,
        'لوحة الآجل: كم لك على العملاء، ومن تأخّر، وسدادٌ جزئيّ أو كامل بكشف حساب لكل عميل.'.tr,
        Icons.account_balance_wallet, 'المجانية'.tr, () => CreditDashboardScreen(),
        onlyFor: {'quick_sale', 'retail', 'wholesale', 'pharmacy', 'restaurant'}),
    _Svc('customers', 'العملاء وحساباتهم'.tr,
        'سجلّ عملائك وأرصدتهم الآجلة وحدودهم الائتمانية، مع كشف حساب قابل للتصدير.'.tr,
        Icons.person_search, 'الأعمال'.tr, () => CreditCustomersScreen()),
    _Svc('daily_reports', 'تقرير اليوم'.tr,
        'مبيعات اليوم بالتفصيل: عدد الفواتير، النقد مقابل المحفظة، وأعلى الأصناف مبيعاً.'.tr,
        Icons.today, 'المجانية'.tr, () => FinancialTruthReportScreen(dailyOnly: true)),
    // **اسمٌ واحدٌ للمال.** كان هنا «حركات المتجر» وفي اللوحة «المبيعات»
    // وفي الشاشة «المبيعات والعمليات» — ثلاثةُ أسماءٍ لشيءٍ واحدٍ يفتحها
    // التاجرُ من ثلاثة أبوابٍ في الجلسة نفسِها فيظنّها ثلاثةَ أشياء.
    _Svc('wallet', 'محفظة المتجر'.tr,
        'رصيدُ متجرك وكلُّ ما دخله وخرج منه: مقبوضات، تحويلات، رسوم — بترتيب زمنيّ وبحث.'.tr,
        Icons.account_balance_wallet_rounded, 'المجانية'.tr, () => MerchantWalletScreen()),
    _Svc('profit_reports', 'تقارير الأرباح'.tr,
        'تقارير مبيعات وأرباح مفصّلة لمتجرك، بمقارنات يومية وشهرية لتعرف أداءك الحقيقي.'.tr,
        Icons.trending_up, 'الأعمال'.tr, () => ProfitReportScreen()),
    _Svc('expenses', 'المصروفات والصندوق'.tr,
        'سجّل مصروفات المتجر والصندوق النثري وصنّفها، لتحسب صافي ربحك بدقّة بعد المصاريف.'.tr,
        Icons.receipt_long, 'الأعمال'.tr, () => MerchantExpensesScreen()),
    _Svc('excel_export', 'تصدير Excel'.tr,
        'صدّر مبيعاتك وبياناتك إلى ملفات Excel جاهزة للمحاسبة والمراجعة الخارجية.'.tr,
        Icons.grid_on, 'الأعمال'.tr, () => MerchantExcelExportScreen()),
    _Svc('advanced_reports', 'مركز التقارير'.tr,
        'التقرير المالي والربحية والتصدير في مكان واحد، مع الانتقال إلى التقرير المناسب مباشرة.'.tr,
        Icons.analytics, 'الأعمال'.tr, () => MerchantAdvancedReportsScreen()),
    _Svc('multi_currency', 'تعدّد العملات'.tr,
        'اعرض الأسعار وبِع بأكثر من عملة، مع أسعار صرف قابلة للتحديث يدوياً.'.tr,
        Icons.currency_exchange, 'مؤسسة'.tr, () => MerchantCurrenciesScreen()),
    _Svc('advanced_backup', 'النسخ الاحتياطي'.tr,
        'أنشئ نسخاً احتياطية لبيانات متجرك واستعِدها عند الحاجة — أمان لبياناتك.'.tr,
        Icons.backup, 'مؤسسة'.tr, () => MerchantBackupScreen()),
    _Svc('loyalty', 'برنامج الولاء'.tr,
        'كافئ عملاءك بنقاط على كل عملية شراء يستبدلونها بخصومات — وسيلة مثبتة لزيادة تكرار الشراء.'.tr,
        Icons.stars, 'الأعمال'.tr, () => MerchantLoyaltyScreen()),
    _Svc('corporate_accounts', 'حسابات الشركات B2B'.tr,
        'افتح حسابات آجلة للشركات بحدّ ائتماني، وبِع على حساب الشركة وحصّل المستحقّات لاحقاً.'.tr,
        Icons.business_center, 'المؤسسات'.tr, () => CorporateAccountsScreen()),
    _Svc('branches', 'الفروع'.tr,
        'أدر عدّة فروع لمتجرك تحت حساب واحد، مع تقارير منفصلة لكل فرع.'.tr,
        Icons.account_tree, 'مؤسسة'.tr, () => BranchesManagementScreen()),
    _Svc('employees', 'الموظفون'.tr,
        'أضِف موظفي الكاشير بصلاحيات محدّدة، وتابع مبيعات وأداء كل موظف.'.tr,
        Icons.badge, 'الأعمال'.tr, () => MerchantStaffScreen()),
    _Svc('multi_pos', 'أجهزة نقاط البيع'.tr,
        'أجهزةُ الكاشير المسجَّلة في متجرك: سمِّها، وتابع آخر نشاطها، وألغِ ما فُقد منها فيتوقّف فوراً. والموظّفون يتناوبون عليها.'.tr,
        Icons.point_of_sale, 'حسب الباقة'.tr, () => MerchantPosDevicesScreen()),
    _Svc('audit_log', 'سجلّ التدقيق'.tr,
        'سجلّ كامل لكل عملية حسّاسة في متجرك: من فعل ماذا ومتى — للرقابة والأمان.'.tr,
        Icons.fact_check, 'مؤسسة'.tr, () => MerchantAuditLogScreen()),
    _Svc('api_access', 'مفاتيح API'.tr,
        'اربط متجرك بأنظمتك الخارجية (محاسبة، مواقع) عبر مفاتيح API آمنة.'.tr,
        Icons.api, 'المؤسسات'.tr, () => MerchantApiKeysScreen()),
  ];
}

class _Group {
  final String title;
  final IconData icon;
  final List<String> codes;
  const _Group(this.title, this.icon, this.codes);
}

class _Svc {
  /// مفتاح الميزة كما يُصدره الخادم في `AccessPresets` — لا اسم عرض.
  final String code;
  final String title;
  final String desc;
  final IconData icon;
  final String planLabel;
  final Widget Function()? builder;

  /// أنواع النشاط التي تُمنح لها هذه الخدمة أصلاً. الفارغة = للجميع.
  ///
  /// بعض الميزات يمنحها الخادم حسب `business_type` لا حسب الباقة، فلا
  /// تفتحها أغلى باقة لمن ليس من نوعها. وبلا هذا الحقل تظهر «متاح بترقية
  /// الباقة» لمن هو أصلاً على الباقة الأعلى — وعدٌ كاذب.
  final Set<String> onlyFor;

  _Svc(this.code, this.title, this.desc, this.icon, this.planLabel, this.builder,
      {this.onlyFor = const {}});
}
