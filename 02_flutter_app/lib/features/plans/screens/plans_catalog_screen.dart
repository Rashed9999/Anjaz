import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:url_launcher/url_launcher.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';
import 'package:amyal_pay/util/contact_constants.dart';
import 'package:amyal_pay/features/plans/controllers/plans_controller.dart';

/// CRITICAL-001-PLANS — شاشة كتالوج الخطط.
///
/// التصميم:
///   - بطاقات أفقية قابلة للسحب (PageView).
///   - الخطّة الحالية مميّزة بإطار أصفر.
///   - زر "اشترك" يفتح dialog "تواصل مع خدمة العملاء".
///   - تبديل شهري/سنوي للأسعار.
class PlansCatalogScreen extends StatefulWidget {
  /// الخطّة المُقترَحة (تأتي من UsageLimitDialog عند 402).
  final String? suggestedPlan;

  const PlansCatalogScreen({super.key, this.suggestedPlan});

  @override
  State<PlansCatalogScreen> createState() => _PlansCatalogScreenState();
}

class _PlansCatalogScreenState extends State<PlansCatalogScreen> {
  late final PlansController c;
  late final PageController _pageCtrl;
  bool _annual = false;

  // ألوان لكل خطّة
  static const _planColors = {
    'free': Color(0xFF6B7280),          // رمادي
    'starter': Color(0xFF059669),       // أخضر
    'business': AmyalColors.primary,    // أزرق
    'merchant_pro': Color(0xFFF59E0B),  // برتقالي
    'enterprise': Color(0xFF7C3AED),    // بنفسجي
  };

  static const _planEmojis = {
    'free': '🆓',
    'starter': '🌱',
    'business': '💼',
    'merchant_pro': '⭐',
    'enterprise': '👑',
  };

  @override
  void initState() {
    super.initState();
    c = Get.find<PlansController>();
    _pageCtrl = PageController(viewportFraction: 0.85, initialPage: 0);
    WidgetsBinding.instance.addPostFrameCallback((_) async {
      await c.loadCatalog();
      // إن وُجدت suggestedPlan، انتقل إليها
      if (widget.suggestedPlan != null) {
        final idx = c.plans.indexWhere((p) => p['code'] == widget.suggestedPlan);
        if (idx >= 0 && _pageCtrl.hasClients) {
          _pageCtrl.animateToPage(idx,
              duration: const Duration(milliseconds: 500), curve: Curves.easeOut);
        }
      }
    });
  }

  @override
  void dispose() { _pageCtrl.dispose(); super.dispose(); }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmyalColors.background,
      appBar: AppBar(
        title: const Text('الخطط والاشتراكات'),
        backgroundColor: AmyalColors.primary,
        foregroundColor: Colors.white,
      ),
      body: Obx(() {
        if (c.isLoading.value && c.plans.isEmpty) {
          return const Center(child: CircularProgressIndicator());
        }

        return Column(children: [
          // Toggle شهري/سنوي
          Container(
            margin: const EdgeInsets.all(12),
            padding: const EdgeInsets.all(4),
            decoration: BoxDecoration(
              color: Colors.white,
              borderRadius: BorderRadius.circular(30),
              boxShadow: [BoxShadow(color: Colors.black.withOpacity(0.05), blurRadius: 6)],
            ),
            child: Row(children: [
              _toggleBtn('شهري', !_annual, () => setState(() => _annual = false)),
              _toggleBtn('سنوي (وفّر 17%)', _annual, () => setState(() => _annual = true)),
            ]),
          ),

          // الخطّة الحالية (إن وُجدت)
          if (c.currentPlan.value != null) _currentPlanBadge(),

          // كتالوج الخطط
          Expanded(child: c.plans.isEmpty
              ? Center(child: Text('لا توجد خطط متاحة',
                  style: TextStyle(color: Colors.grey.shade600)))
              : PageView.builder(
                  controller: _pageCtrl,
                  itemCount: c.plans.length,
                  padEnds: true,
                  itemBuilder: (_, i) => _planCard(c.plans[i]),
                ),
          ),

          // ملاحظة قانونية
          Padding(
            padding: const EdgeInsets.all(12),
            child: Text(
              'الأسعار مرجعية بالريال السعودي. التفعيل يتم يدوياً عبر خدمة العملاء.',
              textAlign: TextAlign.center,
              style: TextStyle(color: Colors.grey.shade600, fontSize: 11),
            ),
          ),
        ]);
      }),
    );
  }

  Widget _toggleBtn(String label, bool active, VoidCallback onTap) => Expanded(
    child: InkWell(
      borderRadius: BorderRadius.circular(26),
      onTap: onTap,
      child: AnimatedContainer(
        duration: const Duration(milliseconds: 250),
        padding: const EdgeInsets.symmetric(vertical: 10),
        decoration: BoxDecoration(
          color: active ? AmyalColors.primary : Colors.transparent,
          borderRadius: BorderRadius.circular(26),
        ),
        child: Text(label, textAlign: TextAlign.center,
            style: TextStyle(
              color: active ? Colors.white : Colors.grey.shade700,
              fontWeight: FontWeight.bold, fontSize: 13,
            )),
      ),
    ),
  );

  Widget _currentPlanBadge() {
    final cp = c.currentPlan.value!;
    final isExpired = c.isExpired;
    return Container(
      margin: const EdgeInsets.symmetric(horizontal: 12, vertical: 4),
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
      decoration: BoxDecoration(
        color: isExpired ? Colors.red.shade50 : AmyalColors.yellow.withOpacity(0.2),
        borderRadius: BorderRadius.circular(10),
        border: Border.all(color: isExpired ? Colors.red : AmyalColors.yellowDark, width: 1),
      ),
      child: Row(children: [
        Icon(isExpired ? Icons.warning_amber : Icons.workspace_premium,
            color: isExpired ? Colors.red : AmyalColors.yellowDark, size: 18),
        const SizedBox(width: 8),
        Expanded(child: Text(
          isExpired
            ? 'انتهت خطّتك — تعمل الآن بحدود FREE'
            : 'خطّتك الحالية: ${_planLabel(cp['code'])}',
          style: TextStyle(
            color: isExpired ? Colors.red.shade800 : AmyalColors.yellowDark,
            fontWeight: FontWeight.bold, fontSize: 13,
          ),
        )),
      ]),
    );
  }

  String _planLabel(String? code) {
    return c.plans.firstWhere(
      (p) => p['code'] == code,
      orElse: () => {'label': code ?? ''},
    )['label']?.toString() ?? '';
  }

  Widget _planCard(Map<String, dynamic> plan) {
    final code = plan['code']?.toString() ?? '';
    final color = _planColors[code] ?? AmyalColors.primary;
    final emoji = _planEmojis[code] ?? '📋';
    final isCurrent = c.isCurrentPlan(code);
    final isSuggested = widget.suggestedPlan == code;
    final price = _annual ? plan['price_annual_sar'] : plan['price_monthly_sar'];
    final priceLabel = _annual ? 'سنوياً' : 'شهرياً';
    final features = (plan['features'] ?? []) as List;
    final limits = (plan['limits'] ?? {}) as Map;

    return Container(
      margin: const EdgeInsets.symmetric(horizontal: 8, vertical: 6),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(18),
        border: Border.all(
          color: isCurrent ? AmyalColors.yellow : (isSuggested ? color : Colors.transparent),
          width: isCurrent ? 3 : (isSuggested ? 2 : 0),
        ),
        boxShadow: [BoxShadow(color: color.withOpacity(0.15), blurRadius: 16)],
      ),
      child: Column(children: [
        // Header ملوّن
        Container(
          padding: const EdgeInsets.all(20),
          decoration: BoxDecoration(
            color: color,
            borderRadius: const BorderRadius.vertical(top: Radius.circular(15)),
          ),
          child: Column(children: [
            Text(emoji, style: const TextStyle(fontSize: 32)),
            const SizedBox(height: 4),
            Text(plan['label']?.toString() ?? '',
                style: const TextStyle(color: Colors.white, fontSize: 22, fontWeight: FontWeight.bold)),
            const SizedBox(height: 12),
            if (plan['is_free'] == true) ...[
              const Text('مجاني', style: TextStyle(color: Colors.white, fontSize: 32, fontWeight: FontWeight.bold)),
              const Text('للأبد', style: TextStyle(color: Colors.white70, fontSize: 12)),
            ] else ...[
              Row(mainAxisAlignment: MainAxisAlignment.center,
                  crossAxisAlignment: CrossAxisAlignment.baseline,
                  textBaseline: TextBaseline.alphabetic, children: [
                Text('$price', style: const TextStyle(color: Colors.white, fontSize: 36, fontWeight: FontWeight.bold)),
                const SizedBox(width: 4),
                const Text('ر.س', style: TextStyle(color: Colors.white, fontSize: 14)),
              ]),
              Text(priceLabel, style: const TextStyle(color: Colors.white70, fontSize: 12)),
            ],
            if (isSuggested) ...[
              const SizedBox(height: 8),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 4),
                decoration: BoxDecoration(
                  color: AmyalColors.yellow,
                  borderRadius: BorderRadius.circular(20),
                ),
                child: const Text('مُقترحة لك',
                    style: TextStyle(color: Colors.black87, fontWeight: FontWeight.bold, fontSize: 11)),
              ),
            ],
          ]),
        ),

        // الميزات + الحدود
        Expanded(child: SingleChildScrollView(
          padding: const EdgeInsets.all(16),
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            // الحدود الرقمية
            _limitRow('عدد المنتجات', _limitText(limits['max_products'])),
            _limitRow('عمليات شهرية', _limitText(limits['monthly_operations'])),
            _limitRow('الموظفون', _limitText(limits['max_employees'])),
            _limitRow('الفروع', _limitText(limits['max_branches'])),
            _limitRow('نقاط البيع', _limitText(limits['max_pos_devices'])),
            _limitRow('مدّة الأرشيف', _archiveText(limits['archive_days'])),
            const SizedBox(height: 14),
            // الميزات
            const Text('الميزات المُتاحة',
                style: TextStyle(fontWeight: FontWeight.bold, fontSize: 13)),
            const SizedBox(height: 8),
            ...features.take(8).map((f) => Padding(
              padding: const EdgeInsets.symmetric(vertical: 2),
              child: Row(children: [
                Icon(Icons.check_circle, color: color, size: 16),
                const SizedBox(width: 6),
                Expanded(child: Text(_featureLabel(f.toString()),
                    style: const TextStyle(fontSize: 12))),
              ]),
            )),
            if (features.length > 8)
              Padding(
                padding: const EdgeInsets.only(top: 4),
                child: Text('+ ${features.length - 8} ميزة أخرى',
                    style: TextStyle(color: Colors.grey.shade600, fontSize: 11)),
              ),
          ]),
        )),

        // زر الاشتراك
        Padding(
          padding: const EdgeInsets.all(12),
          child: isCurrent
            ? Container(
                width: double.infinity,
                padding: const EdgeInsets.symmetric(vertical: 12),
                decoration: BoxDecoration(
                  color: Colors.grey.shade100, borderRadius: BorderRadius.circular(10),
                  border: Border.all(color: AmyalColors.yellow, width: 2),
                ),
                child: Row(mainAxisAlignment: MainAxisAlignment.center, children: const [
                  Icon(Icons.check_circle, color: AmyalColors.yellowDark, size: 18),
                  SizedBox(width: 6),
                  Text('الخطّة الحالية', style: TextStyle(color: AmyalColors.yellowDark, fontWeight: FontWeight.bold)),
                ]),
              )
            : FilledButton.icon(
                onPressed: () => _showContactDialog(plan),
                icon: const Icon(Icons.workspace_premium),
                label: Text(plan['is_free'] == true ? 'استخدم المجانية' : 'ترقية الآن'),
                style: FilledButton.styleFrom(
                  backgroundColor: color,
                  minimumSize: const Size.fromHeight(48),
                ),
              ),
        ),
      ]),
    );
  }

  String _limitText(dynamic v) {
    if (v == null) return '—';
    final n = v is num ? v.toInt() : (int.tryParse('$v') ?? 0);
    if (n < 0) return 'غير محدود';
    if (n == 0) return '—';
    return '$n';
  }

  String _archiveText(dynamic v) {
    if (v == null) return '—';
    final n = v is num ? v.toInt() : (int.tryParse('$v') ?? 0);
    if (n < 0) return 'دائم';
    if (n >= 365) return '${(n / 365).toStringAsFixed(0)} سنة';
    if (n >= 30) return '${(n / 30).toStringAsFixed(0)} شهر';
    return '$n يوم';
  }

  Widget _limitRow(String label, String value) => Padding(
    padding: const EdgeInsets.symmetric(vertical: 3),
    child: Row(children: [
      Expanded(child: Text(label, style: const TextStyle(fontSize: 12, color: Colors.black87))),
      Text(value, style: TextStyle(
        fontSize: 12, fontWeight: FontWeight.bold,
        color: value == '—' ? Colors.grey.shade400 : Colors.black87,
      )),
    ]),
  );

  String _featureLabel(String f) {
    const map = {
      'quick_sale': 'بيع سريع', 'cashier': 'كاشير', 'debts': 'الديون',
      'refunds': 'استرجاع', 'products': 'إدارة المنتجات', 'inventory': 'المخزون',
      'barcode': 'الباركود', 'inventory_audit': 'جرد المخزون',
      'low_stock_alerts': 'تنبيه نفاد المخزون', 'customers': 'العملاء',
      'suppliers': 'الموردون', 'purchases': 'المشتريات',
      'profit_reports': 'تقارير الأرباح', 'excel_export': 'تصدير Excel',
      'advanced_reports': 'تقارير متقدّمة', 'employees': 'الموظفون',
      'employee_permissions': 'صلاحيات الموظفين', 'multi_pos': 'نقاط بيع متعدّدة',
      'branches': 'الفروع', 'branch_reports': 'تقارير الفروع',
      'multi_currency': 'عملات متعدّدة', 'audit_log': 'سجل التدقيق',
      'advanced_backup': 'نسخ احتياطي متقدّم', 'api_access': 'وصول API',
      'corporate_accounts': 'حسابات الشركات', 'corporate_credit_limits': 'حدود ائتمانية',
      'operations_manager': 'مدير عمليات', 'financial_manager': 'مدير مالي',
      'rbac': 'صلاحيات متقدّمة',
      'fuel_cards': 'بطاقات الوقود', 'fuel_variance': 'العجز/الفائض',
      'pharmacy_prescriptions': 'الوصفات الطبيّة',
      'wholesale_multi_pricing': 'تسعير متعدّد للجملة',
    };
    return map[f] ?? f;
  }

  void _showContactDialog(Map<String, dynamic> plan) {
    final isFree = plan['is_free'] == true;
    final price = _annual ? plan['price_annual_sar'] : plan['price_monthly_sar'];

    showDialog(context: context, builder: (ctx) => AlertDialog(
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
      title: Row(children: [
        Icon(isFree ? Icons.info : Icons.workspace_premium,
            color: AmyalColors.primary),
        const SizedBox(width: 8),
        Expanded(child: Text(isFree ? 'الخطّة المجانية' : 'الترقية إلى ${plan['label']}')),
      ]),
      content: Column(mainAxisSize: MainAxisSize.min, children: [
        if (!isFree) ...[
          Container(
            padding: const EdgeInsets.all(12),
            decoration: BoxDecoration(
              color: AmyalColors.primary.withOpacity(0.08),
              borderRadius: BorderRadius.circular(10),
            ),
            child: Column(children: [
              Text('$price ر.س', style: const TextStyle(
                  fontSize: 24, fontWeight: FontWeight.bold, color: AmyalColors.primary)),
              Text(_annual ? 'سنوياً' : 'شهرياً',
                  style: TextStyle(color: Colors.grey.shade700, fontSize: 12)),
            ]),
          ),
          const SizedBox(height: 12),
          const Text(
            'للترقية، تواصل مع خدمة العملاء وسيتمّ تفعيل خطّتك خلال 24 ساعة من تأكيد الدفع.',
            textAlign: TextAlign.center,
            style: TextStyle(fontSize: 13),
          ),
          const SizedBox(height: 12),
          const Text('طرق الدفع المتاحة:',
              style: TextStyle(fontWeight: FontWeight.bold, fontSize: 12)),
          const SizedBox(height: 4),
          const Wrap(spacing: 6, runSpacing: 4, children: [
            _PayMethod('💵', 'نقد'),
            _PayMethod('🏦', 'تحويل بنكي'),
            _PayMethod('📱', 'محفظة إلكترونية'),
          ]),
        ] else ...[
          const Text(
            'الخطّة المجانية مُتاحة دائماً، لكنها محدودة بـ 100 عملية بيع شهرياً.\n\n'
            'للحصول على ميزات إضافية، رقّ خطّتك في أيّ وقت.',
            textAlign: TextAlign.right,
          ),
        ],
      ]),
      actions: [
        TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('إغلاق')),
        if (!isFree) FilledButton.icon(
          icon: const Icon(Icons.phone),
          label: const Text('تواصل الآن'),
          onPressed: () async {
            Navigator.pop(ctx);
            await _openContactSheet(plan, price);
          },
        ),
      ],
    ));
  }

  /// Bottom sheet يعرض 3 خيارات تواصل (WhatsApp / Phone / Email).
  Future<void> _openContactSheet(Map<String, dynamic> plan, dynamic price) async {
    final planLabel = plan['label']?.toString() ?? '';
    final priceSar = (price is num) ? price.toInt() : (int.tryParse('$price') ?? 0);

    await showModalBottomSheet(
      context: context,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
      ),
      builder: (sheetCtx) => SafeArea(
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            Container(width: 40, height: 4,
                margin: const EdgeInsets.only(bottom: 12),
                decoration: BoxDecoration(color: Colors.grey.shade300,
                    borderRadius: BorderRadius.circular(2))),
            const Text('تواصل لتفعيل الخطّة',
                style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold)),
            const SizedBox(height: 12),
            _ContactBtn(
              icon: Icons.chat_bubble,
              label: 'WhatsApp',
              subtitle: 'الأسرع — رسالة مُعدّة مسبقاً',
              color: const Color(0xFF25D366),
              onTap: () async {
                Navigator.pop(sheetCtx);
                await _tryLaunch(ContactConstants.upgradeWhatsAppUrl(
                  planLabel: planLabel, priceSar: priceSar,
                ));
              },
            ),
            _ContactBtn(
              icon: Icons.phone,
              label: 'اتصال هاتفي',
              subtitle: ContactConstants.phoneNumber,
              color: AmyalColors.primary,
              onTap: () async {
                Navigator.pop(sheetCtx);
                await _tryLaunch(ContactConstants.phoneUrl());
              },
            ),
            _ContactBtn(
              icon: Icons.email,
              label: 'البريد الإلكتروني',
              subtitle: ContactConstants.supportEmail,
              color: Colors.deepOrange,
              onTap: () async {
                Navigator.pop(sheetCtx);
                await _tryLaunch(ContactConstants.mailUrl(
                  'ترقية إلى $planLabel — $priceSar ر.س',
                ));
              },
            ),
            const SizedBox(height: 8),
          ]),
        ),
      ),
    );
  }

  /// محاولة فتح URL — يعرض snackbar عند الفشل.
  Future<void> _tryLaunch(String url) async {
    try {
      final uri = Uri.parse(url);
      final ok = await launchUrl(uri, mode: LaunchMode.externalApplication);
      if (!ok && mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(
          content: const Text('تعذّر فتح التطبيق — تأكّد من تثبيته'),
          backgroundColor: AmyalColors.red,
        ));
      }
    } catch (_) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
        content: const Text('فشل التواصل — حاول لاحقاً'),
        backgroundColor: AmyalColors.red,
      ));
    }
  }
}

/// زر تواصل واحد داخل Bottom Sheet.
class _ContactBtn extends StatelessWidget {
  final IconData icon;
  final String label;
  final String subtitle;
  final Color color;
  final VoidCallback onTap;
  const _ContactBtn({
    required this.icon, required this.label, required this.subtitle,
    required this.color, required this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    return InkWell(
      borderRadius: BorderRadius.circular(12),
      onTap: onTap,
      child: Container(
        margin: const EdgeInsets.only(bottom: 8),
        padding: const EdgeInsets.all(14),
        decoration: BoxDecoration(
          color: color.withOpacity(0.08),
          borderRadius: BorderRadius.circular(12),
          border: Border.all(color: color.withOpacity(0.2)),
        ),
        child: Row(children: [
          Container(width: 44, height: 44,
              decoration: BoxDecoration(color: color, borderRadius: BorderRadius.circular(10)),
              child: Icon(icon, color: Colors.white, size: 22)),
          const SizedBox(width: 12),
          Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(label, style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 15)),
            Text(subtitle, style: TextStyle(color: Colors.grey.shade700, fontSize: 11)),
          ])),
          Icon(Icons.arrow_forward_ios, size: 14, color: color),
        ]),
      ),
    );
  }
}

class _PayMethod extends StatelessWidget {
  final String emoji;
  final String label;
  const _PayMethod(this.emoji, this.label);

  @override
  Widget build(BuildContext context) => Container(
    padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
    decoration: BoxDecoration(
      color: Colors.grey.shade100,
      borderRadius: BorderRadius.circular(16),
    ),
    child: Text('$emoji $label', style: const TextStyle(fontSize: 12)),
  );
}
