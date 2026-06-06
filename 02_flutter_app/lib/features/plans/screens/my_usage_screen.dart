import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';
import 'package:amyal_pay/features/plans/controllers/plans_controller.dart';
import 'package:amyal_pay/features/plans/screens/plans_catalog_screen.dart';

/// CRITICAL-001-USAGE — شاشة "استخدامي".
///
/// تعرض snapshot للحدود الحالية:
/// - عدّاد العمليات الشهرية (progress bar)
/// - عدد المنتجات / الحدّ
/// - عدد الموظفين / الحدّ
/// - مدّة الأرشيف
class MyUsageScreen extends StatefulWidget {
  const MyUsageScreen({super.key});

  @override
  State<MyUsageScreen> createState() => _MyUsageScreenState();
}

class _MyUsageScreenState extends State<MyUsageScreen> {
  late final PlansController c;

  @override
  void initState() {
    super.initState();
    c = Get.find<PlansController>();
    WidgetsBinding.instance.addPostFrameCallback((_) => c.loadUsage());
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmyalColors.background,
      appBar: AppBar(
        title: const Text('استخدامي'),
        backgroundColor: AmyalColors.primary,
        foregroundColor: Colors.white,
        actions: [
          IconButton(
            icon: const Icon(Icons.workspace_premium),
            tooltip: 'الخطط',
            onPressed: () => Get.to(() => const PlansCatalogScreen()),
          ),
        ],
      ),
      body: RefreshIndicator(
        onRefresh: c.loadUsage,
        child: Obx(() {
          final u = c.usage.value;
          if (u == null) {
            return c.isLoading.value
              ? const Center(child: CircularProgressIndicator())
              : ListView(children: [
                  const SizedBox(height: 100),
                  Center(child: Text('لا توجد بيانات',
                      style: TextStyle(color: Colors.grey.shade600))),
                ]);
          }

          final monthOps = (u['monthly_operations'] ?? {}) as Map;
          final products = (u['products'] ?? {}) as Map;
          final employees = (u['employees'] ?? {}) as Map;

          return ListView(
            padding: const EdgeInsets.all(12),
            children: [
              // بطاقة الخطّة الحالية
              _planCard(u['plan_label']?.toString() ?? '', u['period']?.toString() ?? ''),
              const SizedBox(height: 12),

              // العمليات الشهرية
              _usageCard(
                icon: Icons.point_of_sale,
                label: 'عمليات البيع هذا الشهر',
                current: _toInt(monthOps['current']),
                max: _toInt(monthOps['max']),
                isUnlimited: monthOps['is_unlimited'] == true,
                percentage: monthOps['percentage'],
                color: AmyalColors.primary,
              ),

              // المنتجات
              _usageCard(
                icon: Icons.inventory_2,
                label: 'المنتجات',
                current: _toInt(products['current']),
                max: _toInt(products['max']),
                isUnlimited: products['is_unlimited'] == true,
                color: Colors.blue.shade700,
              ),

              // الموظفون
              _usageCard(
                icon: Icons.people,
                label: 'الموظفون',
                current: _toInt(employees['current']),
                max: _toInt(employees['max']),
                isUnlimited: employees['is_unlimited'] == true,
                color: Colors.deepPurple,
              ),

              // مدّة الأرشيف
              _archiveCard(_toInt(u['archive_days'])),

              const SizedBox(height: 16),

              // زر الترقية
              FilledButton.icon(
                onPressed: () => Get.to(() => const PlansCatalogScreen()),
                icon: const Icon(Icons.workspace_premium),
                label: const Text('عرض كل الخطط'),
                style: FilledButton.styleFrom(
                  backgroundColor: AmyalColors.yellowDark,
                  minimumSize: const Size.fromHeight(50),
                ),
              ),
            ],
          );
        }),
      ),
    );
  }

  int _toInt(dynamic v) {
    if (v == null) return 0;
    if (v is num) return v.toInt();
    return int.tryParse('$v') ?? 0;
  }

  Widget _planCard(String label, String period) {
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        gradient: const LinearGradient(
          colors: [AmyalColors.primary, Color(0xFF1E40AF)],
          begin: Alignment.topRight, end: Alignment.bottomLeft,
        ),
        borderRadius: BorderRadius.circular(14),
      ),
      child: Row(children: [
        Container(width: 50, height: 50,
            decoration: BoxDecoration(
              color: AmyalColors.yellow,
              borderRadius: BorderRadius.circular(12),
            ),
            child: const Icon(Icons.workspace_premium, color: Colors.black87, size: 28)),
        const SizedBox(width: 12),
        Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          const Text('خطّتك الحالية',
              style: TextStyle(color: Colors.white70, fontSize: 12)),
          Text(label, style: const TextStyle(
              color: Colors.white, fontSize: 22, fontWeight: FontWeight.bold)),
          Text('فترة: $period',
              style: const TextStyle(color: Colors.white60, fontSize: 11)),
        ])),
      ]),
    );
  }

  Widget _usageCard({
    required IconData icon,
    required String label,
    required int current,
    required int max,
    required bool isUnlimited,
    dynamic percentage,
    required Color color,
  }) {
    double pct = 0;
    String valueText;
    Color barColor = color;

    if (isUnlimited) {
      valueText = '$current من ∞';
      pct = 0;
    } else if (max == 0) {
      valueText = 'غير متاحة في خطّتك';
      pct = 0;
      barColor = Colors.grey;
    } else {
      valueText = '$current / $max';
      pct = (percentage is num)
        ? percentage.toDouble() / 100
        : (current / max).clamp(0.0, 1.0);

      // تلوين حسب النسبة
      if (pct >= 0.9) barColor = Colors.red;
      else if (pct >= 0.7) barColor = Colors.orange;
      else barColor = color;
    }

    return Container(
      margin: const EdgeInsets.only(bottom: 10),
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(12)),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Row(children: [
          Icon(icon, color: color),
          const SizedBox(width: 8),
          Expanded(child: Text(label, style: const TextStyle(fontWeight: FontWeight.bold))),
          Text(valueText, style: TextStyle(
              color: barColor, fontWeight: FontWeight.bold, fontSize: 14)),
        ]),
        if (!isUnlimited && max > 0) ...[
          const SizedBox(height: 8),
          ClipRRect(
            borderRadius: BorderRadius.circular(8),
            child: LinearProgressIndicator(
              value: pct, minHeight: 8,
              backgroundColor: Colors.grey.shade200,
              valueColor: AlwaysStoppedAnimation(barColor),
            ),
          ),
          if (pct >= 0.7) Padding(
            padding: const EdgeInsets.only(top: 6),
            child: Row(children: [
              Icon(pct >= 0.9 ? Icons.error : Icons.warning_amber,
                  color: barColor, size: 14),
              const SizedBox(width: 4),
              Text(
                pct >= 0.9 ? 'وصلت إلى الحدّ تقريباً!' : 'اقتربت من الحدّ',
                style: TextStyle(color: barColor, fontSize: 11, fontWeight: FontWeight.bold),
              ),
            ]),
          ),
        ],
        if (max == 0) Padding(
          padding: const EdgeInsets.only(top: 6),
          child: TextButton.icon(
            onPressed: () => Get.to(() => const PlansCatalogScreen()),
            icon: const Icon(Icons.upgrade, size: 16),
            label: const Text('رقّ خطّتك لإلغاء القفل'),
            style: TextButton.styleFrom(
              foregroundColor: AmyalColors.yellowDark,
              padding: EdgeInsets.zero, minimumSize: const Size(0, 30),
            ),
          ),
        ),
      ]),
    );
  }

  Widget _archiveCard(int days) {
    final isUnlimited = days < 0;
    return Container(
      margin: const EdgeInsets.only(bottom: 10),
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(12)),
      child: Row(children: [
        const Icon(Icons.archive, color: Colors.brown),
        const SizedBox(width: 8),
        const Expanded(child: Text('مدّة حفظ الأرشيف',
            style: TextStyle(fontWeight: FontWeight.bold))),
        Text(
          isUnlimited ? 'دائم' :
            days >= 365 ? '${(days / 365).toStringAsFixed(0)} سنة' :
            days >= 30 ? '${(days / 30).toStringAsFixed(0)} شهر' :
            '$days يوم',
          style: const TextStyle(fontWeight: FontWeight.bold, color: Colors.brown),
        ),
      ]),
    );
  }
}

// =========================================================================
// UsageLimitDialog — يُستدعى يدوياً من الـ Controllers عند 402.
// =========================================================================

/// عند فشل عملية بسبب وصول الحدّ، استدعي هذا:
///
/// ```dart
/// if (response.statusCode == 402 && response.body['code'] == 'USAGE_LIMIT_EXCEEDED') {
///   await UsageLimitDialog.show(context, response.body['meta']);
/// }
/// ```
class UsageLimitDialog {
  /// يفحص الـ Response. إن كان 402 USAGE_LIMIT_EXCEEDED:
  ///   - يُظهر الحوار تلقائياً.
  ///   - يُرجع true (الـ caller يعرف أنّ الخطأ عُولج، لا داعي لـ snackbar إضافي).
  ///
  /// وإلا يُرجع false (الـ caller يعالج الخطأ بنفسه).
  ///
  /// الاستخدام في أيّ Controller:
  /// ```dart
  /// final r = await repo.someAction(...);
  /// if (await UsageLimitDialog.handleIfLimitExceeded(r)) return false;
  /// // ...باقي معالجة النجاح/الفشل العادية
  /// ```
  static Future<bool> handleIfLimitExceeded(Response r) async {
    if (r.statusCode != 402) return false;
    if (r.body is! Map) return false;
    if (r.body['code'] != 'USAGE_LIMIT_EXCEEDED') return false;

    final ctx = Get.context;
    if (ctx == null) return true; // الـ 402 موجود لكن لا context — نمتصّ الخطأ بصمت
    final meta = Map<String, dynamic>.from((r.body['meta'] ?? {}) as Map);
    await show(ctx, meta);
    return true;
  }

  static Future<void> show(BuildContext context, Map<String, dynamic> meta) {
    final limitType = meta['limit_type']?.toString() ?? '';
    final current = meta['current_value']?.toString() ?? '0';
    final max = meta['max_value']?.toString() ?? '0';
    final currentPlanLabel = meta['current_plan_label']?.toString() ?? '';
    final suggestedPlan = meta['suggested_plan']?.toString();
    final suggestedLabel = meta['suggested_plan_label']?.toString() ?? '';
    final suggestedPrice = meta['suggested_plan_price_sar'];

    final limitLabel = _limitLabel(limitType);
    final isBlocked = max == '0';

    return showDialog<void>(
      context: context,
      builder: (ctx) => AlertDialog(
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
        title: Row(children: [
          Icon(isBlocked ? Icons.lock : Icons.block,
              color: AmyalColors.red, size: 28),
          const SizedBox(width: 8),
          const Expanded(child: Text('وصلت إلى الحدّ')),
        ]),
        content: Column(mainAxisSize: MainAxisSize.min, children: [
          // الوصف
          Container(
            padding: const EdgeInsets.all(12),
            decoration: BoxDecoration(
              color: Colors.red.shade50,
              borderRadius: BorderRadius.circular(10),
            ),
            child: Column(children: [
              Text(limitLabel,
                  style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14)),
              const SizedBox(height: 4),
              if (!isBlocked) Text('$current / $max',
                  style: const TextStyle(color: AmyalColors.red, fontSize: 18, fontWeight: FontWeight.bold))
              else const Text('غير متاحة في خطّتك الحالية',
                  style: TextStyle(color: AmyalColors.red, fontSize: 14)),
              Text('الخطّة: $currentPlanLabel',
                  style: TextStyle(color: Colors.grey.shade700, fontSize: 12)),
            ]),
          ),

          if (suggestedPlan != null && suggestedPrice != null) ...[
            const SizedBox(height: 14),
            const Text('الحلّ المقترح:',
                style: TextStyle(fontWeight: FontWeight.bold, fontSize: 13)),
            const SizedBox(height: 6),
            Container(
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(
                color: AmyalColors.yellow.withOpacity(0.2),
                borderRadius: BorderRadius.circular(10),
                border: Border.all(color: AmyalColors.yellowDark, width: 1.5),
              ),
              child: Row(children: [
                const Text('⬆️', style: TextStyle(fontSize: 24)),
                const SizedBox(width: 8),
                Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text('ترقية إلى $suggestedLabel',
                      style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14)),
                  Text('$suggestedPrice ر.س / شهرياً',
                      style: TextStyle(color: Colors.grey.shade700, fontSize: 12)),
                ])),
              ]),
            ),
          ],
        ]),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx),
            child: const Text('لاحقاً'),
          ),
          FilledButton.icon(
            icon: const Icon(Icons.workspace_premium),
            label: const Text('عرض الخطط'),
            onPressed: () {
              Navigator.pop(ctx);
              Get.to(() => PlansCatalogScreen(suggestedPlan: suggestedPlan));
            },
          ),
        ],
      ),
    );
  }

  static String _limitLabel(String t) => switch(t) {
    'monthly_operations' => 'الحدّ الشهري لعمليات البيع',
    'products' => 'الحدّ الأقصى للمنتجات',
    'employees' => 'الحدّ الأقصى للموظفين',
    'branches' => 'الحدّ الأقصى للفروع',
    'pos_devices' => 'الحدّ الأقصى لنقاط البيع',
    _ => 'حدّ الخطّة',
  };
}
