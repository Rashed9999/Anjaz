import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:url_launcher/url_launcher.dart';
import 'package:amial_pay/theme/amial_colors.dart';
import 'package:amial_pay/util/contact_constants.dart';
import 'package:amial_pay/features/plans/controllers/plans_controller.dart';

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
  bool _compare = false; // AMIAL-PLANS-COMPARE-001: بطاقات ↔ جدول مقارنة

  // ألوان لكل خطّة
  static const _planColors = {
    'free': Color(0xFF6B7280),          // رمادي
    'business': AmialColors.primary,    // أزرق
    'enterprise': Color(0xFF7C3AED),    // بنفسجي
  };

  /// AMIAL-PLAN-COMPARE-001 — **أيقونةُ العائلة لا رمزٌ تعبيريّ.**
  ///
  /// الرمزُ التعبيريُّ يُرسَم بخطّ الجهاز، فيختلف شكلُه ولونُه بين هاتفٍ
  /// وآخر ولا يتبع هويّةَ المنتج — ويقرؤه صاحبُه «مسوّدة». وهو أوّلُ ما
  /// يقع عليه النظرُ في البطاقة.
  static const _planIcons = {
    'free': Icons.rocket_launch_outlined,
    'business': Icons.storefront_rounded,
    'enterprise': Icons.account_tree_rounded,
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
      backgroundColor: AmialColors.background,
      appBar: AppBar(
        title: const Text('الخطط والاشتراكات'),
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
              boxShadow: [BoxShadow(color: Colors.black.withValues(alpha: 0.05), blurRadius: 6)],
            ),
            child: Row(children: [
              _toggleBtn('شهري', !_annual, () => setState(() => _annual = false)),
              _toggleBtn('سنوي (وفّر 17%)', _annual, () => setState(() => _annual = true)),
            ]),
          ),

          // الخطّة الحالية (إن وُجدت)
          if (c.currentPlan.value != null) _currentPlanBadge(),

          // مبدّل العرض: بطاقات ↔ مقارنة
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 12),
            child: Row(children: [
              _viewBtn('بطاقات', Icons.view_carousel, !_compare, () => setState(() => _compare = false)),
              const SizedBox(width: 8),
              _viewBtn('مقارنة', Icons.table_chart, _compare, () => setState(() => _compare = true)),
            ]),
          ),
          const SizedBox(height: 8),

          // كتالوج الخطط
          Expanded(child: c.plans.isEmpty
              ? Center(child: Text('لا توجد خطط متاحة',
                  style: TextStyle(color: Colors.grey.shade600)))
              : (_compare
                  ? _comparisonMatrix()
                  : PageView.builder(
                      controller: _pageCtrl,
                      itemCount: c.plans.length,
                      padEnds: true,
                      itemBuilder: (_, i) => _planCard(c.plans[i]),
                    )),
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
          color: active ? AmialColors.primary : Colors.transparent,
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

  Widget _viewBtn(String label, IconData icon, bool active, VoidCallback onTap) => Expanded(
    child: InkWell(
      borderRadius: BorderRadius.circular(12),
      onTap: onTap,
      child: Container(
        padding: const EdgeInsets.symmetric(vertical: 9),
        decoration: BoxDecoration(
          color: active ? AmialColors.primary.withValues(alpha: 0.12) : Colors.white,
          borderRadius: BorderRadius.circular(12),
          border: Border.all(color: active ? AmialColors.primary : AmialColors.border),
        ),
        child: Row(mainAxisAlignment: MainAxisAlignment.center, children: [
          Icon(icon, size: 16, color: active ? AmialColors.primary : Colors.grey.shade600),
          const SizedBox(width: 6),
          Text(label, style: TextStyle(
              color: active ? AmialColors.primary : Colors.grey.shade700,
              fontWeight: FontWeight.bold, fontSize: 13)),
        ]),
      ),
    ),
  );

  /// AMIAL-PLAN-COMPARE-001 — **الفرقُ بين الباقات، لا سردُ كلِّ ميزة.**
  ///
  /// ══════════════════════════════════════════════════════════════════
  /// **الطلب:** «يجب التوضيح: الفرقُ بين كلّ باقةٍ بكلّ احترافيّة».
  ///
  /// وكان الجدولُ القديمُ يسرد **٣٩ صفّاً** — اتّحادَ ميزات الباقات —
  /// ويضع علامةً في كلّ عمود. **وعيبُه اثنان:**
  ///
  ///   ① يترجم بخريطةٍ مكتوبةٍ في هذا الملفّ فيها **١٩ من ٣٩**، فعشرون
  ///      صفّاً تُعرَض `advanced_reports` و`wholesale_credit` **خاماً
  ///      بالإنجليزيّة**. والأسماءُ العربيّةُ كلُّها في الخادم.
  ///   ② ويُعيد المشتركَ في كلّ عمود، **فتختفي الصفوفُ الفارقة** بين
  ///      عشرين متطابقة. والقارئُ يسأل «ماذا أكسب إن رقّيت؟» فلا يجد.
  ///
  /// **فصار العرضُ «ما تضيفه كلُّ باقةٍ على ما قبلها»**، مجموعةً مجموعة،
  /// بأسماءٍ وأوصافٍ من `CapabilityRegistry` — وهو المصدرُ الذي كتبه
  /// صاحبُ المشروع أصلاً.
  Widget _comparisonMatrix() {
    final ladder = c.comparison;

    if (ladder.isEmpty) {
      // **«لم تصل» ليست «لا فرق»** (القاعدة السابعة) — ولا يُعرَض فراغٌ
      // يُقرأ «الباقات متطابقة».
      return const Center(
        child: Padding(
          padding: EdgeInsets.all(32),
          child: Text('تعذّرت قراءة تفاصيل المقارنة — اسحب للتحديث.',
              textAlign: TextAlign.center,
              style: TextStyle(color: AmialColors.textMuted)),
        ),
      );
    }

    return ListView(
      padding: const EdgeInsets.fromLTRB(12, 4, 12, 16),
      children: [
        for (final plan in ladder) _ladderStep(plan),

        // ③ **وما لا تفتحه الترقيةُ يُقال** — وإلّا رقّى صاحبُ البقالة
        // ليحصل على قدرةِ صيدليّةٍ فلا يجدها.
        if (c.verticalNote.value.isNotEmpty)
          Container(
            key: const Key('plans-vertical-note'),
            margin: const EdgeInsets.only(top: 8),
            padding: const EdgeInsets.all(12),
            decoration: BoxDecoration(
              color: AmialColors.warningSurface,
              borderRadius: BorderRadius.circular(12),
            ),
            child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
              const Icon(Icons.info_outline, size: 18, color: AmialColors.warning),
              const SizedBox(width: 8),
              Expanded(
                child: Text(c.verticalNote.value,
                    style: const TextStyle(fontSize: 12, height: 1.5)),
              ),
            ]),
          ),
      ],
    );
  }

  /// درجةٌ من السلّم: الباقةُ، ووعدُها، وما تضيفه على ما قبلها.
  Widget _ladderStep(Map<String, dynamic> plan) {
    final code = plan['code']?.toString() ?? '';
    final color = _planColors[code] ?? AmialColors.primary;
    final pitch = (plan['pitch'] ?? const {}) as Map;
    final adds = (plan['adds'] ?? const []) as List;
    final isCurrent = c.isCurrentPlan(code);
    final isFree = plan['is_free'] == true;

    // تجميعٌ بالمعنى — لا قائمةً مسطّحةً من تسعةٍ وثلاثين سطراً.
    final grouped = <String, List<Map>>{};
    for (final a in adds) {
      final m = Map<String, dynamic>.from(a as Map);
      grouped.putIfAbsent('${m['group']}', () => []).add(m);
    }

    final price = _annual ? plan['price_annual'] : plan['price_monthly'];
    final currency = plan['currency']?.toString() ?? '';

    return Container(
      key: Key('plan-step-$code'),
      margin: const EdgeInsets.only(bottom: 14),
      decoration: BoxDecoration(
        color: AmialColors.cardSurface,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(
            color: isCurrent ? AmialColors.yellow : Colors.transparent,
            width: isCurrent ? 2 : 0),
      ),
      clipBehavior: Clip.antiAlias,
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Container(
          color: color,
          padding: const EdgeInsets.fromLTRB(14, 12, 14, 12),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Row(children: [
              Expanded(
                child: Text('${plan['label']}',
                    style: const TextStyle(
                        color: Colors.white, fontSize: 17, fontWeight: FontWeight.bold)),
              ),
              if (isCurrent)
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                  decoration: BoxDecoration(
                      color: AmialColors.yellow,
                      borderRadius: BorderRadius.circular(10)),
                  child: const Text('باقتك',
                      style: TextStyle(fontSize: 11, fontWeight: FontWeight.bold)),
                )
              else
                // **العملةُ تُقال ولا تُحوَّل** — السعرُ سعوديٌّ والرصيدُ
                // في المنتج يمنيّ. (القسمُ في `CLAUDE.md`.)
                Text(isFree ? 'مجّاناً' : '$price $currency',
                    style: const TextStyle(
                        color: Colors.white, fontSize: 15, fontWeight: FontWeight.bold)),
            ]),
            if ('${pitch['headline'] ?? ''}'.isNotEmpty) ...[
              const SizedBox(height: 4),
              Text('${pitch['headline']}',
                  style: const TextStyle(color: Colors.white, fontSize: 13.5)),
            ],
            if ('${pitch['for_whom'] ?? ''}'.isNotEmpty) ...[
              const SizedBox(height: 2),
              Text('${pitch['for_whom']}',
                  style: const TextStyle(color: Colors.white70, fontSize: 11.5, height: 1.4)),
            ],
          ]),
        ),

        Padding(
          padding: const EdgeInsets.fromLTRB(14, 12, 14, 14),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(
              isFree
                  ? 'تبدأ بهذه، وفيها:'
                  : 'تضيف على ما قبلها ${plan['adds_count']} قدرة:',
              style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13),
            ),
            const SizedBox(height: 8),

            for (final entry in grouped.entries) ...[
              Text(entry.key,
                  style: TextStyle(
                      fontSize: 11.5, fontWeight: FontWeight.bold, color: color)),
              const SizedBox(height: 4),
              for (final cap in entry.value)
                Padding(
                  padding: const EdgeInsets.only(bottom: 6),
                  child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Icon(Icons.check_rounded, size: 15, color: color),
                    const SizedBox(width: 6),
                    Expanded(
                      child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text('${cap['name']}',
                                style: const TextStyle(
                                    fontSize: 12.5, fontWeight: FontWeight.w600)),
                            if ('${cap['description'] ?? ''}'.isNotEmpty)
                              Text('${cap['description']}',
                                  style: const TextStyle(
                                      fontSize: 11,
                                      height: 1.45,
                                      color: AmialColors.textMuted)),
                          ]),
                    ),
                  ]),
                ),
              const SizedBox(height: 4),
            ],

            const Divider(height: 18),
            for (final l in ((plan['limits'] ?? const []) as List))
              _limitRow('${(l as Map)['label']}', '${l['text']}'),
          ]),
        ),
      ]),
    );
  }

  Widget _currentPlanBadge() {
    final cp = c.currentPlan.value!;
    final isExpired = c.isExpired;
    return Container(
      margin: const EdgeInsets.symmetric(horizontal: 12, vertical: 4),
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
      decoration: BoxDecoration(
        color: isExpired ? Colors.red.shade50 : AmialColors.yellow.withValues(alpha: 0.2),
        borderRadius: BorderRadius.circular(10),
        border: Border.all(color: isExpired ? Colors.red : AmialColors.yellowDark, width: 1),
      ),
      child: Row(children: [
        Icon(isExpired ? Icons.warning_amber : Icons.workspace_premium,
            color: isExpired ? Colors.red : AmialColors.yellowDark, size: 18),
        const SizedBox(width: 8),
        Expanded(child: Text(
          isExpired
            ? 'انتهت خطّتك — تعمل الآن بحدود FREE'
            : 'خطّتك الحالية: ${_planLabel(cp['code'])}',
          style: TextStyle(
            color: isExpired ? Colors.red.shade800 : AmialColors.yellowDark,
            fontWeight: FontWeight.bold, fontSize: 13,
          ),
        )),
      ]),
    );
  }

  /// AMIAL-PLAN-CURRENCY-001 — **العملةُ تُرسَل مع السعر، ولا تُكتب هنا.**
  ///
  /// قِيس: `PLAN_PRICES_SAR` بالريال **السعوديّ** كما يقول اسمُه، وكلُّ
  /// رصيدٍ في المنتج بالريال **اليمنيّ**. وأربعةُ مواضعَ في هذه الشاشة
  /// كانت تكتب «ر.ي» على الرقم نفسِه — **فالرقمُ صحيحٌ والعملةُ كاذبة**،
  /// وهي أخطرُ من رقمٍ خاطئ: ٣٥ ر.س ≈ ٢٤٠٠ ر.ي.
  ///
  /// و`amial-financial-truth` تقول: «Never silently convert currencies».
  /// **فلا تُحوَّل** — تُقال. والخادمُ يرسل `currency` مع كلّ سعر، ومصدرُها
  /// `AccessConstants::PLAN_PRICE_CURRENCY` — سطرٌ واحدٌ يُغيَّر إن غُيِّر
  /// التسعير، لا تسعُ شاشات.
  String _cur(Map<String, dynamic> plan) =>
      (plan['currency'] ?? '').toString();

  String _planLabel(String? code) {
    return c.plans.firstWhere(
      (p) => p['code'] == code,
      orElse: () => {'label': code ?? ''},
    )['label']?.toString() ?? '';
  }

  Widget _planCard(Map<String, dynamic> plan) {
    final code = plan['code']?.toString() ?? '';
    final color = _planColors[code] ?? AmialColors.primary;
    final icon = _planIcons[code] ?? Icons.workspace_premium_outlined;
    final pitch = _pitchFor(code);
    final isCurrent = c.isCurrentPlan(code);
    final isSuggested = widget.suggestedPlan == code;
    final price = _annual ? plan['price_annual_sar'] : plan['price_monthly_sar'];
    final priceLabel = _annual ? 'سنوياً' : 'شهرياً';
    final features = (plan['features'] ?? []) as List;
    final limits = (plan['limits'] ?? {}) as Map;

    return Container(
      // AMIAL-PLANS-UI-001: كانت الهوامش 8 مع ظلّ عريض 16 وviewportFraction
      // 0.85، فتتداخل ظلال البطاقات المجاورة وتبدو متراكبة. هامش أوسع وظلّ
      // أخفض اتجاهه للأسفل يجعل البطاقة المجاورة «إطلالة» مقصودة لا تشويشاً.
      margin: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
      clipBehavior: Clip.antiAlias,
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(18),
        border: Border.all(
          color: isCurrent ? AmialColors.yellow : (isSuggested ? color : Colors.transparent),
          width: isCurrent ? 3 : (isSuggested ? 2 : 0),
        ),
        boxShadow: [
          BoxShadow(
            color: Colors.black.withValues(alpha: 0.07),
            blurRadius: 10,
            offset: const Offset(0, 4),
          ),
        ],
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
            Icon(icon, size: 34, color: Colors.white),
            const SizedBox(height: 4),
            Text(plan['label']?.toString() ?? '',
                style: const TextStyle(color: Colors.white, fontSize: 22, fontWeight: FontWeight.bold)),

            // **«لمن هي» قبل «ماذا فيها»** — بطاقةٌ تبدأ بقائمة ميزاتٍ
            // تُقرأ فهرساً، وبطاقةٌ تبدأ بمن هي له تُقرأ عرضاً.
            _pitchBlock(pitch),

            const SizedBox(height: 12),
            if (plan['is_free'] == true) ...[
              Text(_freePriceLabel(plan),
                  style: const TextStyle(color: Colors.white, fontSize: 30, fontWeight: FontWeight.bold)),
              const Text('للأبد', style: TextStyle(color: Colors.white70, fontSize: 12)),
            ] else ...[
              Row(mainAxisAlignment: MainAxisAlignment.center,
                  crossAxisAlignment: CrossAxisAlignment.baseline,
                  textBaseline: TextBaseline.alphabetic, children: [
                Text('$price', style: const TextStyle(color: Colors.white, fontSize: 36, fontWeight: FontWeight.bold)),
                const SizedBox(width: 4),
                Text(_cur(plan), style: const TextStyle(color: Colors.white, fontSize: 14)),
              ]),
              Text(priceLabel, style: const TextStyle(color: Colors.white70, fontSize: 12)),
            ],
            if (isSuggested) ...[
              const SizedBox(height: 8),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 4),
                decoration: BoxDecoration(
                  color: AmialColors.yellow,
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
            // **الأسماءُ من الخادم** — و`_featureLabel` كانت خريطةً
            // مكتوبةً فيها ١٩ من ٣٩، فما نقص يُعرَض رمزاً إنجليزيّاً
            // خاماً (`map[f] ?? f`). والأسماءُ كلُّها في
            // `CapabilityRegistry` منذ البداية.
            ..._namedFeatures(code).take(8).map((f) => Padding(
              padding: const EdgeInsets.symmetric(vertical: 2),
              child: Row(children: [
                Icon(Icons.check_circle, color: color, size: 16),
                const SizedBox(width: 6),
                Expanded(child: Text(f, style: const TextStyle(fontSize: 12))),
              ]),
            )),
            if (features.length > 8)
              Padding(
                padding: const EdgeInsets.only(top: 4),
                child: Text('+ ${features.length - 8} قدرة أخرى — انظر «مقارنة»',
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
                  border: Border.all(color: AmialColors.yellow, width: 2),
                ),
                child: Row(mainAxisAlignment: MainAxisAlignment.center, children: const [
                  Icon(Icons.check_circle, color: AmialColors.yellowDark, size: 18),
                  SizedBox(width: 6),
                  Text('الخطّة الحالية', style: TextStyle(color: AmialColors.yellowDark, fontWeight: FontWeight.bold)),
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

  /// AMIAL-PLANS-UI-001: اسم الباقة المجانية يحمل كلمة «مجاني» أصلاً، فكان
  /// السعر يكرّرها تحته مباشرةً («مجاني / مجاني»). نعرض «بلا رسوم» عندئذٍ.
  String _freePriceLabel(Map<String, dynamic> plan) {
    final label = plan['label']?.toString() ?? '';
    return label.contains('مجان') ? 'بلا رسوم' : 'مجاني';
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

  /// وعدُ الباقة من الخادم — ولا يُخترَع هنا نصٌّ تسويقيّ.
  Map<String, dynamic> _pitchFor(String code) {
    final row = c.comparison.firstWhereOrNull((p) => p['code'] == code);

    return Map<String, dynamic>.from((row?['pitch'] ?? const {}) as Map);
  }

  Widget _pitchBlock(Map<String, dynamic> pitch) {
    final head = '${pitch['headline'] ?? ''}';
    final whom = '${pitch['for_whom'] ?? ''}';
    if (head.isEmpty && whom.isEmpty) return const SizedBox.shrink();

    return Padding(
      key: const Key('plan-card-pitch'),
      padding: const EdgeInsets.only(top: 6),
      child: Column(children: [
        if (head.isNotEmpty)
          Text(head,
              textAlign: TextAlign.center,
              style: const TextStyle(
                  color: Colors.white, fontSize: 13, fontWeight: FontWeight.w600)),
        if (whom.isNotEmpty)
          Padding(
            padding: const EdgeInsets.only(top: 2),
            child: Text(whom,
                textAlign: TextAlign.center,
                style: const TextStyle(color: Colors.white70, fontSize: 11, height: 1.4)),
          ),
      ]),
    );
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

  /// **أسماءُ قدرات الباقة من الخادم** — لا من خريطةٍ مكتوبةٍ هنا.
  ///
  /// وما لم يصل وصفُه **لا يُعرَض رمزاً خاماً**: الرمزُ الإنجليزيُّ في
  /// وجه تاجرٍ يمنيٍّ ليس اسماً — هو غيابُ اسم. (القاعدة السابعة.)
  List<String> _namedFeatures(String code) {
    final row = c.comparison.firstWhereOrNull((p) => p['code'] == code);
    if (row == null) return const [];

    return [
      for (final a in ((row['adds'] ?? const []) as List))
        if ((a as Map)['documented'] == true) '${a['name']}',
    ];
  }

  void _showContactDialog(Map<String, dynamic> plan) {
    final isFree = plan['is_free'] == true;
    final price = _annual ? plan['price_annual_sar'] : plan['price_monthly_sar'];

    showDialog(context: context, builder: (ctx) => AlertDialog(
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
      title: Row(children: [
        Icon(isFree ? Icons.info : Icons.workspace_premium,
            color: AmialColors.primary),
        const SizedBox(width: 8),
        Expanded(child: Text(isFree ? 'الخطّة المجانية' : 'الترقية إلى ${plan['label']}')),
      ]),
      content: Column(mainAxisSize: MainAxisSize.min, children: [
        if (!isFree) ...[
          Container(
            padding: const EdgeInsets.all(12),
            decoration: BoxDecoration(
              color: AmialColors.primary.withValues(alpha: 0.08),
              borderRadius: BorderRadius.circular(10),
            ),
            child: Column(children: [
              Text('$price ${_cur(plan)}', style: const TextStyle(
                  fontSize: 24, fontWeight: FontWeight.bold, color: AmialColors.primary)),
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
    final currency = _cur(plan);

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
                  planLabel: planLabel, priceSar: priceSar, currency: currency,
                ));
              },
            ),
            _ContactBtn(
              icon: Icons.phone,
              label: 'اتصال هاتفي',
              subtitle: ContactConstants.phoneNumber,
              color: AmialColors.primary,
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
                  'ترقية إلى $planLabel — $priceSar $currency',
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
          backgroundColor: AmialColors.red,
        ));
      }
    } catch (_) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
        content: const Text('فشل التواصل — حاول لاحقاً'),
        backgroundColor: AmialColors.red,
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
          color: color.withValues(alpha: 0.08),
          borderRadius: BorderRadius.circular(12),
          border: Border.all(color: color.withValues(alpha: 0.2)),
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
