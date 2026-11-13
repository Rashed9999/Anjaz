import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amial_pay/data/api/api_client.dart';
import 'package:amial_pay/features/kyc_verification/controllers/verification_center_controller.dart';
import 'package:amial_pay/features/kyc_verification/domain/reposotories/verification_center_repo.dart';
import 'package:amial_pay/helper/amial_money.dart';
import 'package:amial_pay/theme/amial_colors.dart';

/// AMIAL-USAGE-LIMITS-BAR-001 — شريط حدود الاستخدام في «حسابي».
///
/// **لماذا:** حدود المستوى (١٠٠ ألف للأول، ٢٥٠ ألفاً للثاني…) سياسةٌ ماليّةٌ لا يراها العميل إلّا حين يُرفض
/// تحويله. فيظنّ الرفض عطلاً، أو يتجاوز بلا أن يعلم أين يقف. الشريط يقول له قبل أن يطلب: كم استهلكتَ اليوم،
/// وكم هذا الشهر، وأين رصيدك من سقفه، وكم بقي.
///
/// الأرقام من الخادم وحده (`/me/verification-status` → `tier.limits` و`tier.usage`)؛ لا يحسب التطبيق حدّاً بنفسه.
/// والاستهلاك أصلُ المبلغ دون الرسوم (كما يحسبه الحارس في الخادم).
class UsageLimitRow {
  final String label;
  final double used;
  final double limit;
  final String kind; // movement | balance

  const UsageLimitRow(this.label, this.used, this.limit, {this.kind = 'movement'});

  double get fraction => limit <= 0 ? 0 : (used / limit).clamp(0.0, 1.0);
  double get remaining => (limit - used) < 0 ? 0 : (limit - used);
  bool get exhausted => limit > 0 && used >= limit;
  bool get nearLimit => limit > 0 && fraction >= 0.8;

  Color get color => exhausted
      ? AmialColors.red
      : nearLimit
          ? AmialColors.warning
          : AmialColors.primary;
}

class UsageLimitsBar extends StatelessWidget {
  /// `data['tier']` من استجابة حالة التوثيق.
  final Map<String, dynamic> tier;
  final VoidCallback? onUpgrade;

  const UsageLimitsBar({super.key, required this.tier, this.onUpgrade});

  static Map<String, dynamic> _map(dynamic v) => v is Map ? Map<String, dynamic>.from(v) : <String, dynamic>{};
  static double _num(dynamic v) => double.tryParse('${v ?? 0}') ?? 0;

  /// الصفوف من استجابة الخادم. الصفّ يُحذف إن لم يكن له حدّ (مستوى بلا محفظة) أو لم يرسل الخادم قيمته.
  static List<UsageLimitRow> rowsFrom(Map<String, dynamic> tier) {
    final limits = _map(tier['limits']);
    final usage = _map(tier['usage']);
    final rows = <UsageLimitRow>[];

    void add(String label, String? usedKey, String limitKey, {String kind = 'movement'}) {
      if (usedKey == null || !usage.containsKey(usedKey)) return;
      final limit = _num(limits[limitKey]);
      if (limit <= 0) return;
      rows.add(UsageLimitRow(label, _num(usage[usedKey]), limit, kind: kind));
    }

    add('الحركة اليوم', 'today', 'max_daily_total');
    add('الحركة هذا الشهر', 'month', 'max_monthly_total');
    add('رصيد المحفظة', 'balance', 'max_balance', kind: 'balance');
    return rows;
  }

  @override
  Widget build(BuildContext context) {
    final rows = rowsFrom(tier);
    final current = (tier['current'] is num) ? (tier['current'] as num).toInt() : int.tryParse('${tier['current']}') ?? 0;
    final name = '${tier['name'] ?? ''}';
    final single = _num(_map(tier['limits'])['max_single_transaction']);

    return Container(
      key: const Key('usage-limits-bar'),
      margin: const EdgeInsets.fromLTRB(16, 12, 16, 4),
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: Theme.of(context).cardColor,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: AmialColors.border),
      ),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(children: [
          const Icon(Icons.speed_rounded, size: 20, color: AmialColors.primary),
          const SizedBox(width: 8),
          const Expanded(child: Text('حدود استخدامك', style: TextStyle(fontWeight: FontWeight.w700, fontSize: 15))),
          if (name.isNotEmpty)
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 3),
              decoration: BoxDecoration(color: AmialColors.warningSurface, borderRadius: BorderRadius.circular(20)),
              child: Text(name, style: const TextStyle(fontSize: 11, fontWeight: FontWeight.w600, color: AmialColors.warning)),
            ),
        ]),
        const SizedBox(height: 10),
        if (current <= 0 || rows.isEmpty)
          const Text('أكمل توثيق هاتفك وسكنك لتفعيل محفظتك وتُحدَّد حدودك.', style: TextStyle(color: AmialColors.textMuted, fontSize: 13))
        else ...[
          for (final r in rows) _row(r),
          if (single > 0)
            Padding(
              padding: const EdgeInsets.only(top: 2),
              child: Text('حدّ العملية الواحدة: ${AmialMoney.yer(single)}', style: const TextStyle(color: AmialColors.textMuted, fontSize: 12)),
            ),
          const Padding(
            padding: EdgeInsets.only(top: 2),
            child: Text('تُحسب على أصل المبلغ دون الرسوم.', style: TextStyle(color: AmialColors.textMuted, fontSize: 11)),
          ),
        ],
        if (onUpgrade != null && current < 3)
          Align(
            alignment: AlignmentDirectional.centerEnd,
            child: TextButton(onPressed: onUpgrade, child: const Text('ارفع حدودك بتوثيق أعلى')),
          ),
      ]),
    );
  }

  Widget _row(UsageLimitRow r) {
    final status = r.exhausted
        ? (r.kind == 'balance' ? 'بلغ السقف' : 'استنفدتَ الحدّ')
        : 'المتبقي ${AmialMoney.yer(r.remaining)}';
    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(children: [
          Expanded(child: Text(r.label, style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w600))),
          Text('${AmialMoney.fmt(r.used)} / ${AmialMoney.yer(r.limit)}', style: const TextStyle(fontSize: 12), textDirection: TextDirection.ltr),
        ]),
        const SizedBox(height: 5),
        ClipRRect(
          borderRadius: BorderRadius.circular(6),
          child: LinearProgressIndicator(
            key: Key('usage-bar-${r.label}'),
            value: r.fraction,
            minHeight: 8,
            backgroundColor: AmialColors.border,
            valueColor: AlwaysStoppedAnimation<Color>(r.color),
          ),
        ),
        const SizedBox(height: 3),
        Text(status, style: TextStyle(fontSize: 11, color: r.exhausted || r.nearLimit ? r.color : AmialColors.textMuted)),
      ]),
    );
  }
}

/// يجلب الحالة من الخادم ويعرض الشريط. فشل الجلب يُخفيه ولا يعرض أرقاماً مخمَّنة («غير معروف» ليس صفراً).
class UsageLimitsBarLoader extends StatefulWidget {
  final VoidCallback? onUpgrade;
  const UsageLimitsBarLoader({super.key, this.onUpgrade});

  @override
  State<UsageLimitsBarLoader> createState() => _UsageLimitsBarLoaderState();
}

class _UsageLimitsBarLoaderState extends State<UsageLimitsBarLoader> {
  @override
  void initState() {
    super.initState();
    if (!Get.isRegistered<VerificationCenterController>()) {
      Get.put(VerificationCenterController(repo: VerificationCenterRepo(apiClient: Get.find<ApiClient>())));
    }
    Future.microtask(() => Get.find<VerificationCenterController>().load(silent: true));
  }

  @override
  Widget build(BuildContext context) {
    return GetBuilder<VerificationCenterController>(builder: (c) {
      final tier = c.data?['tier'];
      if (tier is! Map) return const SizedBox.shrink();
      return UsageLimitsBar(tier: Map<String, dynamic>.from(tier), onUpgrade: widget.onUpgrade);
    });
  }
}
