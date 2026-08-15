import 'package:flutter/material.dart';
import 'package:get/get.dart';

import 'package:amial_pay/features/entitlements/capability_screens.dart';
import 'package:amial_pay/common/widgets/vertical_state_view.dart';
import 'package:amial_pay/features/entitlements/controllers/entitlements_controller.dart';
import 'package:amial_pay/theme/amial_colors.dart';

/// AMIAL-ENTITLEMENTS-001 — **ملفُّ خدماتي**.
///
/// ══════════════════════════════════════════════════════════════════════
/// كلُّ ما في المنصّة معروضٌ هنا، **والمقفلُ يُعرض ولا يُخفى** — وما يُخفى
/// لا يُشترى. وعلى البطاقة المقفلة سببُ قفلها وطريقُ فتحها:
///
/// | القفل | ما يُعرض |
/// |---|---|
/// | بالباقة | اسمُ الباقة وسعرُها الشهريّ · زرُّ ترقية |
/// | بالدور | «اطلبها من مالك المنشأة» — **ولا يُعرض سعر** |
/// | ببلوغ الحدّ | الرقمان: المستعمَل والحدّ · وأيُّ باقةٍ ترفعه |
///
/// **وعرضُ سعرٍ لموظّفٍ نقصُه صلاحيّةٌ يُرسل صاحبَ المتجر يدفع بلا سبب.**
///
/// **ولا قائمةَ مكتوبةً في هذا الملفّ**: البطاقاتُ من `manifest`، فقدرةٌ
/// جديدةٌ تظهر للتجّار بلا نشرةِ تطبيق.
class MyCapabilitiesScreen extends StatefulWidget {
  const MyCapabilitiesScreen({super.key});

  @override
  State<MyCapabilitiesScreen> createState() => _MyCapabilitiesScreenState();
}

class _MyCapabilitiesScreenState extends State<MyCapabilitiesScreen> {
  EntitlementsController get c => Get.find<EntitlementsController>();

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => c.load());
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(
        title: const Text('خدماتي'),
        backgroundColor: AmialColors.background,
        elevation: 0,
      ),
      body: RefreshIndicator(
        onRefresh: c.load,
        child: Obx(() => VerticalStateView(
              c: c,
              isEmpty: c.items.isEmpty,
              emptyTitle: 'لا خدمات بعد',
              emptyHint: 'اختر نوع نشاطك من الإعدادات لتظهر خدماتك.',
              emptyIcon: Icons.apps_outlined,
              onRetry: c.load,
              grantedBy: 'مالك المنشأة',
              child: ListView(
                padding: const EdgeInsets.all(16),
                children: [
                  _planCard(),
                  const SizedBox(height: 12),
                  _groupChips(),
                  const SizedBox(height: 12),
                  ...c.visible.map(_card),
                  const SizedBox(height: 24),
                ],
              ),
            )),
      ),
    );
  }

  // ── بطاقةُ الباقة ─────────────────────────────────────────────────

  Widget _planCard() {
    final locked = c.summary(EntitlementsController.stLockedByPlan);

    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        gradient: const LinearGradient(
          colors: [AmialColors.primary, AmialColors.primaryLight],
        ),
        borderRadius: BorderRadius.circular(14),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Text('باقتك الحالية',
              style: TextStyle(color: Colors.white70, fontSize: 12)),
          const SizedBox(height: 4),
          Text(c.planName,
              style: const TextStyle(
                  color: Colors.white, fontSize: 24, fontWeight: FontWeight.bold)),
          if (c.planExpiry.isNotEmpty) ...[
            const SizedBox(height: 4),
            Text('تنتهي: ${c.planExpiry.split('T').first}',
                style: const TextStyle(color: Colors.white70, fontSize: 11)),
          ],
          const SizedBox(height: 12),
          Row(children: [
            _pill('${c.summary(EntitlementsController.stAvailable)} متاحة',
                Colors.white.withValues(alpha: 0.22)),
            const SizedBox(width: 6),
            if (locked > 0)
              _pill('$locked بترقية', AmialColors.yellow.withValues(alpha: 0.9),
                  dark: true),
          ]),
        ],
      ),
    );
  }

  Widget _pill(String text, Color bg, {bool dark = false}) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
      decoration: BoxDecoration(color: bg, borderRadius: BorderRadius.circular(20)),
      child: Text(text,
          style: TextStyle(
              color: dark ? AmialColors.textPrimary : Colors.white,
              fontSize: 11, fontWeight: FontWeight.bold)),
    );
  }

  Widget _groupChips() {
    return SizedBox(
      height: 34,
      child: ListView(
        scrollDirection: Axis.horizontal,
        children: [
          _chip('الكل', ''),
          ...c.groups.map((g) => _chip(g, g)),
        ],
      ),
    );
  }

  Widget _chip(String label, String value) {
    final on = c.groupFilter.value == value;
    return Padding(
      padding: const EdgeInsets.only(left: 6),
      child: ChoiceChip(
        label: Text(label, style: const TextStyle(fontSize: 12)),
        selected: on,
        onSelected: (_) => c.groupFilter.value = value,
        selectedColor: AmialColors.primary,
        labelStyle: TextStyle(color: on ? Colors.white : AmialColors.textPrimary),
      ),
    );
  }

  // ── بطاقةُ القدرة ─────────────────────────────────────────────────

  Widget _card(Map<String, dynamic> row) {
    final cap = Map<String, dynamic>.from(row['capability'] as Map);
    final state = '${row['state']}';
    final unlock = row['unlock'] is Map
        ? Map<String, dynamic>.from(row['unlock'] as Map) : null;
    final usage = row['usage'] is Map
        ? Map<String, dynamic>.from(row['usage'] as Map) : null;

    final available = state == EntitlementsController.stAvailable;

    return Card(
      color: AmialColors.cardSurface,
      margin: const EdgeInsets.only(bottom: 8),
      child: Opacity(
        // **المقفلُ يبهت ولا يختفي.**
        opacity: available ? 1 : 0.72,
        child: ListTile(
          leading: CircleAvatar(
            backgroundColor: available
                ? AmialColors.primary.withValues(alpha: 0.12)
                : AmialColors.border,
            child: Icon(
              available ? Icons.check_rounded : Icons.lock_outline_rounded,
              size: 18,
              color: available ? AmialColors.primary : AmialColors.textMuted,
            ),
          ),
          title: Text('${cap['name']}',
              style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14)),
          subtitle: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              if ('${cap['description'] ?? ''}'.isNotEmpty)
                Text('${cap['description']}',
                    style: const TextStyle(
                        fontSize: 11, color: AmialColors.textMuted)),
              const SizedBox(height: 4),
              _stateLine(state, unlock, usage),
            ],
          ),
          // **الشرطُ رمزُ القدرة لا نصُّ المسار.** كان `cap['screen'] != null`
          // — وهو يصدق لأربعين قدرةً ولا واحدةَ منها مسجَّلةٌ مساراً، فيظهر
          // السهمُ ويُضغط فتخرج «لم تُفعَّل شاشتها بعد». (قِيس: ٤٠ من ٤٠.)
          trailing: available && CapabilityScreens.screenFor('${cap['code']}') != null
              ? const Icon(Icons.chevron_left_rounded)
              : null,
          onTap: available && CapabilityScreens.screenFor('${cap['code']}') != null
              ? () => _open('${cap['code']}')
              : () => _explain(cap, state, unlock, usage),
        ),
      ),
    );
  }

  Widget _stateLine(String state, Map<String, dynamic>? unlock,
      Map<String, dynamic>? usage) {
    switch (state) {
      case EntitlementsController.stLockedByPlan:
        return Text(
          // **العملةُ من الخادم لا مكتوبةً هنا.** كان «ر.س» محفوراً في هذا
          // السطر وحدَه — موضعٌ واحدٌ في تطبيقٍ فيه ٢٧٥ موضعاً بـ«ر.ي».
          // وليست غلطةَ حرف: الثابتُ في الخادم اسمُه `PLAN_PRICES_SAR`
          // والأسعارُ بالريال السعوديّ فعلاً، والأرصدةُ كلُّها يمنيّة.
          // فصار المصدرُ واحداً: يُغيَّر في الخادم فيتبعه كلُّ سطح.
          'تُفتح في باقة ${unlock?['plan_name'] ?? '—'}'
          ' · ${unlock?['price_monthly'] ?? '—'} ${unlock?['currency'] ?? ''} شهرياً',
          style: const TextStyle(
              fontSize: 11, color: AmialColors.yellowDark, fontWeight: FontWeight.bold),
        );

      case EntitlementsController.stLockedByRole:
        // **ولا يُعرض سعر** — نقصُه صلاحيّةٌ لا اشتراك.
        return Text(
          'تحتاج صلاحية — اطلبها من ${unlock?['ask'] ?? 'مالك المنشأة'}',
          style: const TextStyle(fontSize: 11, color: AmialColors.textSecondary),
        );

      case EntitlementsController.stLimitReached:
        return Text(
          'بلغتَ الحدّ: ${usage?['used'] ?? '—'} من ${usage?['max'] ?? '—'}'
          '${unlock != null ? ' · ترفعه باقة ${unlock['plan_name']}' : ''}',
          style: const TextStyle(
              fontSize: 11, color: AmialColors.red, fontWeight: FontWeight.bold),
        );

      default:
        return const Text('متاحة',
            style: TextStyle(fontSize: 11, color: Colors.green));
    }
  }

  /// **التنقّلُ برمز القدرة لا بنصّ المسار.**
  ///
  /// كان يُطابق `cap['screen']` بشجرة مسارات GetX. وقِيس أنّ ما يسجّله
  /// التطبيق هو مساراتُ القالب القديم (`/splash` · `/send_money`) ولا
  /// واحدَ من الأربعين التي يُعلنها الخادم — **فكانت كلُّ خدمةٍ «متاحة»
  /// ميّتةً عند الضغط**، والرسالةُ تصدق دائماً.
  ///
  /// والرمزُ هو المفتاحُ الذي يعرفه الطرفان أصلاً: `access.has(code)`.
  void _open(String code) {
    final builder = CapabilityScreens.screenFor(code);

    if (builder == null) {
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
        content: Text('هذه الخدمة متاحة في حسابك ولم تُبنَ شاشتها بعد'),
      ));
      return;
    }

    Get.to(builder);
  }

  void _explain(Map<String, dynamic> cap, String state,
      Map<String, dynamic>? unlock, Map<String, dynamic>? usage) {
    showModalBottomSheet<void>(
      context: context,
      backgroundColor: AmialColors.cardSurface,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(16))),
      builder: (_) => Padding(
        padding: const EdgeInsets.all(20),
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          Icon(
            state == EntitlementsController.stLockedByRole
                ? Icons.badge_outlined : Icons.lock_outline_rounded,
            size: 44, color: AmialColors.yellowDark),
          const SizedBox(height: 12),
          Text('${cap['name']}',
              style: const TextStyle(fontSize: 17, fontWeight: FontWeight.bold)),
          if ('${cap['description'] ?? ''}'.isNotEmpty) ...[
            const SizedBox(height: 8),
            Text('${cap['description']}',
                textAlign: TextAlign.center,
                style: const TextStyle(fontSize: 13, color: AmialColors.textMuted)),
          ],
          const SizedBox(height: 16),
          _stateLine(state, unlock, usage),
          const SizedBox(height: 16),
          if (state == EntitlementsController.stLockedByRole)
            // **لا زرَّ دفعٍ هنا** — من نقصُه صلاحيّةٌ لا يُحلّ أمرَه بالمال.
            const Text('صاحب المنشأة يمنحك هذه الصلاحية من شاشة الأدوار',
                textAlign: TextAlign.center,
                style: TextStyle(fontSize: 12, color: AmialColors.textSecondary))
          else if (state != EntitlementsController.stAvailable)
            SizedBox(
              width: double.infinity,
              child: ElevatedButton.icon(
                onPressed: () {
                  Navigator.pop(context);
                  _open('/plans');
                },
                style: ElevatedButton.styleFrom(
                    backgroundColor: AmialColors.yellow,
                    foregroundColor: AmialColors.textPrimary),
                icon: const Icon(Icons.upgrade_rounded),
                label: Text('ترقية إلى ${unlock?['plan_name'] ?? 'باقة أعلى'}'),
              ),
            ),
        ]),
      ),
    );
  }
}
