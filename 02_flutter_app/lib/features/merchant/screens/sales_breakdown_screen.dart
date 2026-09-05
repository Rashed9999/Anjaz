import 'package:flutter/material.dart';
import 'package:get/get.dart';

import 'package:amial_pay/features/merchant/controllers/cashier_controller.dart';
import 'package:amial_pay/helper/amial_money.dart';
import 'package:amial_pay/theme/amial_colors.dart';

/// AMIAL-SALES-BREAKDOWN-001 — **ماذا بِعتُ، وبكم، وبأيّ ربح.**
///
/// ══════════════════════════════════════════════════════════════════════
/// شاشةُ الربحيّة تقول الإجماليَّ وأعلى عشرةِ منتجاتٍ **ربحاً**. وهذه
/// تقول التفصيل: كلَّ صنفٍ بكمّيّته وإيراده وتكلفته وهامشه، **وكلَّ
/// تصنيفٍ مجموعاً** — وهو السؤالُ الذي يُغيّر ما يطلبه التاجرُ غداً.
///
/// **وثلاثةُ حدودٍ في العرض نفسِه:**
///
///   ① **المرتجعُ يُقال لا يُطوى.** الرقمُ مطروحٌ منه المرتجعُ في الخادم،
///      فلو لم يُعرَض «رُدّ منه ١٨» قرأ التاجرُ رقماً صغيراً ولا يعرف
///      لماذا صغُر — فيظنّ الصنفَ ضعيفاً وهو مردود.
///
///   ② **والهامشُ المجهولُ يُقال «—» لا «٠٪».** صفرٌ يُقرأ «لا ربح»،
///      والحقيقةُ «لا نعرف». (القاعدة السابعة.)
///
///   ③ **ولافتةُ التغطية فوق الجدول لا تحته.** من قرأ الأرقامَ ثمّ علم
///      أنّ نصفَها بلا تكلفةٍ يكون قد بنى قرارَه سلفاً.
class SalesBreakdownScreen extends StatefulWidget {
  const SalesBreakdownScreen({super.key});

  @override
  State<SalesBreakdownScreen> createState() => _SalesBreakdownScreenState();
}

class _SalesBreakdownScreenState extends State<SalesBreakdownScreen>
    with SingleTickerProviderStateMixin {
  CashierController get c => Get.find<CashierController>();

  late final TabController _tabs = TabController(length: 2, vsync: this);
  int _days = 30;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _load());
  }

  @override
  void dispose() {
    _tabs.dispose();
    super.dispose();
  }

  Future<void> _load() {
    final from = DateTime.now().subtract(Duration(days: _days - 1));

    return c.loadSalesBreakdown(
        from: '${from.year}-${_pad(from.month)}-${_pad(from.day)}');
  }

  static String _pad(int n) => n.toString().padLeft(2, '0');

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(
        title: const Text('المبيعات بالصنف'),
        bottom: TabBar(
          controller: _tabs,
          labelColor: AmialColors.primary,
          tabs: const [Tab(text: 'الأصناف'), Tab(text: 'التصنيفات')],
        ),
      ),
      body: Obx(() {
        final report = c.salesBreakdown.value;

        if (c.isLoadingBreakdown.value && report == null) {
          return const Center(
              child: CircularProgressIndicator(color: AmialColors.primary));
        }

        if (report == null) {
          return _empty(c.lastError.value.isEmpty
              ? 'لا بيانات بعد'
              : c.lastError.value);
        }

        return Column(
          children: [
            _rangeChips(),
            _totals(report),
            _coverageNote(report),
            Expanded(
              child: TabBarView(
                controller: _tabs,
                children: [
                  _itemsList(),
                  _categoriesList(),
                ],
              ),
            ),
          ],
        );
      }),
    );
  }

  Widget _empty(String msg) => Center(
        child: Padding(
          padding: const EdgeInsets.all(32),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              const Icon(Icons.inventory_2_outlined,
                  size: 48, color: AmialColors.textMuted),
              const SizedBox(height: 12),
              Text(msg,
                  textAlign: TextAlign.center,
                  style: const TextStyle(
                      fontSize: 13, color: AmialColors.textMuted)),
              const SizedBox(height: 12),
              TextButton(onPressed: _load, child: const Text('أعِد المحاولة')),
            ],
          ),
        ),
      );

  Widget _rangeChips() => Padding(
        padding: const EdgeInsets.symmetric(vertical: 8),
        child: Row(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [7, 30, 90].map((d) {
            final selected = _days == d;
            return Padding(
              padding: const EdgeInsets.symmetric(horizontal: 4),
              child: ChoiceChip(
                label: Text('آخر $d يوم', style: const TextStyle(fontSize: 12)),
                selected: selected,
                selectedColor: AmialColors.primary,
                backgroundColor: Colors.white,
                labelStyle: TextStyle(
                    color: selected ? Colors.white : AmialColors.primary),
                onSelected: (_) {
                  setState(() => _days = d);
                  _load();
                },
              ),
            );
          }).toList(),
        ),
      );

  Widget _totals(Map<String, dynamic> report) {
    final t = Map<String, dynamic>.from((report['totals'] ?? {}) as Map);
    final range = Map<String, dynamic>.from((report['range'] ?? {}) as Map);

    return Card(
      margin: const EdgeInsets.symmetric(horizontal: 16),
      color: AmialColors.cardSurface,
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            // ⑤ المدى كما فهمه الخادمُ لا كما ظنّته الشاشة.
            Text('${range['from']} ← ${range['to']} · ${range['days']} يوماً',
                style: const TextStyle(
                    fontSize: 11, color: AmialColors.textMuted)),
            const SizedBox(height: 8),
            Row(
              children: [
                _stat('الإيراد', AmialMoney.yer(t['revenue']), AmialColors.primary),
                _stat('الربح', AmialMoney.yer(t['profit']), AmialColors.success),
                _stat('الهامش', _percent(t['margin_percent']),
                    AmialColors.textMuted),
              ],
            ),
            const SizedBox(height: 6),
            Text('${t['items_count'] ?? 0} صنفاً · '
                '${t['categories_count'] ?? 0} تصنيفاً · '
                'الكمّية ${t['qty'] ?? 0}',
                style: const TextStyle(
                    fontSize: 11, color: AmialColors.textMuted)),
          ],
        ),
      ),
    );
  }

  Widget _stat(String label, String value, Color color) => Expanded(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(label,
                style: const TextStyle(
                    fontSize: 11, color: AmialColors.textMuted)),
            const SizedBox(height: 2),
            Text(value,
                style: TextStyle(
                    fontSize: 14, fontWeight: FontWeight.bold, color: color)),
          ],
        ),
      );

  /// ② «—» لا «٠٪» — وصفرٌ يُقرأ «لا ربح» والحقيقةُ «لا نعرف».
  static String _percent(dynamic v) =>
      v == null ? '—' : '$v٪';

  /// ③ اللافتةُ فوق الجدول لا تحته.
  Widget _coverageNote(Map<String, dynamic> report) {
    final cov = report['cost_coverage'];
    final note = cov is Map ? cov['note'] : null;

    if (note == null || '$note'.trim().isEmpty) return const SizedBox.shrink();

    return Container(
      margin: const EdgeInsets.fromLTRB(16, 8, 16, 0),
      padding: const EdgeInsets.all(10),
      decoration: BoxDecoration(
        color: AmialColors.yellowDark.withValues(alpha: 0.12),
        borderRadius: BorderRadius.circular(8),
      ),
      child: Row(
        children: [
          const Icon(Icons.info_outline_rounded,
              size: 16, color: AmialColors.yellowDark),
          const SizedBox(width: 8),
          Expanded(
            child: Text('$note',
                style: const TextStyle(
                    fontSize: 11, color: AmialColors.yellowDark)),
          ),
        ],
      ),
    );
  }

  Widget _itemsList() {
    final rows = c.breakdownItems;

    if (rows.isEmpty) return _empty('لا مبيعات في هذا المدى');

    return ListView.separated(
      padding: const EdgeInsets.all(16),
      itemCount: rows.length,
      separatorBuilder: (_, _) => const Divider(height: 1),
      itemBuilder: (_, i) {
        final r = rows[i];
        final returned = '${r['returned_qty'] ?? '0'}';
        final hasReturns = returned != '0' && returned.isNotEmpty;

        return ListTile(
          contentPadding: EdgeInsets.zero,
          title: Text('${r['name']}',
              style: const TextStyle(fontSize: 13, fontWeight: FontWeight.bold)),
          subtitle: Text(
            '${r['category']} · بيع ${r['qty']}'
            // ① المرتجعُ يُقال — وإلّا قُرئ الرقمُ الصغيرُ ضعفاً.
            '${hasReturns ? ' · رُدّ $returned' : ''}',
            style: const TextStyle(fontSize: 11, color: AmialColors.textMuted),
          ),
          trailing: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              Text(AmialMoney.yer(r['revenue']),
                  style: const TextStyle(
                      fontSize: 13, fontWeight: FontWeight.bold)),
              Text('ربح ${AmialMoney.yer(r['profit'])} · '
                  '${_percent(r['margin_percent'])}',
                  style: const TextStyle(
                      fontSize: 10, color: AmialColors.textMuted)),
            ],
          ),
        );
      },
    );
  }

  Widget _categoriesList() {
    final rows = c.breakdownCategories;

    if (rows.isEmpty) return _empty('لا مبيعات في هذا المدى');

    return ListView.separated(
      padding: const EdgeInsets.all(16),
      itemCount: rows.length,
      separatorBuilder: (_, _) => const Divider(height: 1),
      itemBuilder: (_, i) {
        final r = rows[i];

        return ListTile(
          contentPadding: EdgeInsets.zero,
          leading: const Icon(Icons.category_outlined,
              color: AmialColors.primary),
          title: Text('${r['category']}',
              style: const TextStyle(fontSize: 13, fontWeight: FontWeight.bold)),
          subtitle: Text('${r['items']} صنفاً · الكمّية ${r['qty']}',
              style: const TextStyle(
                  fontSize: 11, color: AmialColors.textMuted)),
          trailing: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              Text(AmialMoney.yer(r['revenue']),
                  style: const TextStyle(
                      fontSize: 13, fontWeight: FontWeight.bold)),
              Text('ربح ${AmialMoney.yer(r['profit'])} · '
                  '${_percent(r['margin_percent'])}',
                  style: const TextStyle(
                      fontSize: 10, color: AmialColors.textMuted)),
            ],
          ),
        );
      },
    );
  }
}
