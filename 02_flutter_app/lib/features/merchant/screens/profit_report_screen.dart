import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:share_plus/share_plus.dart';
import 'package:amyal_pay/features/merchant/controllers/cashier_controller.dart';
import 'package:amyal_pay/helper/amial_money.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';

/// AMIAL-PROFIT-001 — «تقارير الربحية» (التصميم 48):
/// بطاقة إجمالي الأرباح + هامش الربح وإجمالي المبيعات + اتجاه أشرطة يومي
/// + تحليل المنتجات (صافي الربح لكل منتج) + مشاركة.
class ProfitReportScreen extends StatefulWidget {
  const ProfitReportScreen({super.key});

  @override
  State<ProfitReportScreen> createState() => _ProfitReportScreenState();
}

class _ProfitReportScreenState extends State<ProfitReportScreen> {
  CashierController get c => Get.find<CashierController>();
  int _days = 7;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback(
        (_) => c.loadProfitReport(days: _days));
  }

  double _d(dynamic v) => double.tryParse('$v') ?? 0;

  Future<void> _share() async {
    final t = c.profitReport.value?['totals'] ?? {};
    await Share.share('''
تقرير ربحية — أميال باي (آخر $_days أيام)
إجمالي المبيعات: ${AmialMoney.yer(t['revenue'])}
إجمالي التكلفة: ${AmialMoney.yer(t['cost'])}
صافي الربح: ${AmialMoney.yer(t['profit'])}
هامش الربح: ${t['margin_percent']}%
عدد العمليات: ${t['sales_count']}
''');
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmyalColors.background,
      appBar: AppBar(
        backgroundColor: AmyalColors.primary,
        foregroundColor: Colors.white,
        title: const Text('تقارير الربحية'),
        actions: [
          IconButton(icon: const Icon(Icons.share), onPressed: _share),
        ],
      ),
      body: Obx(() {
        final report = c.profitReport.value;
        if (c.isLoadingProfit.value && report == null) {
          return const Center(
              child: CircularProgressIndicator(color: AmyalColors.primary));
        }
        final totals = report?['totals'] ?? {};
        final daily = (report?['daily'] as List?) ?? [];
        final products = (report?['products'] as List?) ?? [];
        final maxRevenue = daily.fold<double>(
            1, (m, e) => _d(e['revenue']) > m ? _d(e['revenue']) : m);

        return RefreshIndicator(
          onRefresh: () => c.loadProfitReport(days: _days),
          color: AmyalColors.primary,
          child: ListView(
            padding: const EdgeInsets.all(16),
            children: [
              // ====== الفترة ======
              Row(
                mainAxisAlignment: MainAxisAlignment.center,
                children: [7, 30, 90].map((d) {
                  final selected = _days == d;
                  return Padding(
                    padding: const EdgeInsets.symmetric(horizontal: 4),
                    child: ChoiceChip(
                      label: Text('آخر $d يوم',
                          style: const TextStyle(fontSize: 12)),
                      selected: selected,
                      selectedColor: AmyalColors.primary,
                      backgroundColor: Colors.white,
                      labelStyle: TextStyle(
                          color:
                              selected ? Colors.white : AmyalColors.primary),
                      onSelected: (_) {
                        setState(() => _days = d);
                        c.loadProfitReport(days: d);
                      },
                    ),
                  );
                }).toList(),
              ),
              const SizedBox(height: 14),

              // ====== بطاقة الإجماليات ======
              Container(
                padding: const EdgeInsets.all(20),
                decoration: BoxDecoration(
                  color: Colors.white,
                  borderRadius: BorderRadius.circular(18),
                ),
                child: Column(children: [
                  Row(children: [
                    Container(
                      padding: const EdgeInsets.symmetric(
                          horizontal: 8, vertical: 3),
                      decoration: BoxDecoration(
                        color: const Color(0xFFE3F3E5),
                        borderRadius: BorderRadius.circular(10),
                      ),
                      child: Text(
                          '${totals['sales_count'] ?? 0} عملية',
                          style: const TextStyle(
                              fontSize: 11,
                              color: Color(0xFF2E7D32),
                              fontWeight: FontWeight.w600)),
                    ),
                    const Spacer(),
                    const Text('إجمالي الأرباح',
                        style: TextStyle(
                            fontSize: 13, color: AmyalColors.textSecondary)),
                  ]),
                  const SizedBox(height: 10),
                  Text(AmialMoney.yer(totals['profit']),
                      style: const TextStyle(
                          fontSize: 32,
                          fontWeight: FontWeight.bold,
                          color: AmyalColors.primary)),
                  const Divider(height: 28),
                  Row(children: [
                    Expanded(
                      child: Column(children: [
                        Text('${totals['margin_percent'] ?? 0}%',
                            style: const TextStyle(
                                fontSize: 18,
                                fontWeight: FontWeight.bold,
                                color: AmyalColors.yellowDark)),
                        const Text('هامش الربح',
                            style: TextStyle(
                                fontSize: 11,
                                color: AmyalColors.textMuted)),
                      ]),
                    ),
                    Container(
                        height: 34, width: 1, color: AmyalColors.border),
                    Expanded(
                      child: Column(children: [
                        FittedBox(
                          child: Text(AmialMoney.yer(totals['revenue']),
                              style: const TextStyle(
                                  fontSize: 16,
                                  fontWeight: FontWeight.bold)),
                        ),
                        const Text('إجمالي المبيعات',
                            style: TextStyle(
                                fontSize: 11,
                                color: AmyalColors.textMuted)),
                      ]),
                    ),
                    Container(
                        height: 34, width: 1, color: AmyalColors.border),
                    Expanded(
                      child: Column(children: [
                        FittedBox(
                          child: Text(AmialMoney.yer(totals['cost']),
                              style: const TextStyle(
                                  fontSize: 16,
                                  fontWeight: FontWeight.bold,
                                  color: Color(0xFFDC0A0B))),
                        ),
                        const Text('التكلفة',
                            style: TextStyle(
                                fontSize: 11,
                                color: AmyalColors.textMuted)),
                      ]),
                    ),
                  ]),
                ]),
              ),
              const SizedBox(height: 18),

              // ====== الاتجاه اليومي ======
              Align(
                alignment: Alignment.centerRight,
                child: Text('اتجاه المبيعات ($_days أيام)',
                    style: const TextStyle(
                        fontWeight: FontWeight.bold, fontSize: 15)),
              ),
              const SizedBox(height: 10),
              Container(
                height: 150,
                padding:
                    const EdgeInsets.symmetric(horizontal: 10, vertical: 14),
                decoration: BoxDecoration(
                  color: Colors.white,
                  borderRadius: BorderRadius.circular(16),
                ),
                child: daily.isEmpty
                    ? const Center(
                        child: Text('لا بيانات',
                            style: TextStyle(color: AmyalColors.textMuted)))
                    : Row(
                        crossAxisAlignment: CrossAxisAlignment.end,
                        children: daily.map<Widget>((e) {
                          final rev = _d(e['revenue']);
                          final ratio = (rev / maxRevenue).clamp(0.04, 1.0);
                          final isMax = rev >= maxRevenue && rev > 0;
                          final label = '${e['date']}'.length >= 10
                              ? '${e['date']}'.substring(8)
                              : '';
                          return Expanded(
                            child: Padding(
                              padding:
                                  const EdgeInsets.symmetric(horizontal: 3),
                              child: Column(
                                mainAxisAlignment: MainAxisAlignment.end,
                                children: [
                                  Expanded(
                                    child: FractionallySizedBox(
                                      heightFactor: ratio,
                                      alignment: Alignment.bottomCenter,
                                      child: Container(
                                        decoration: BoxDecoration(
                                          color: isMax
                                              ? AmyalColors.primary
                                              : const Color(0xFFDCE5F2),
                                          borderRadius:
                                              BorderRadius.circular(6),
                                        ),
                                      ),
                                    ),
                                  ),
                                  const SizedBox(height: 4),
                                  Text(label,
                                      style: const TextStyle(
                                          fontSize: 9,
                                          color: AmyalColors.textMuted)),
                                ],
                              ),
                            ),
                          );
                        }).toList(),
                      ),
              ),
              const SizedBox(height: 18),

              // ====== تحليل المنتجات ======
              const Align(
                alignment: Alignment.centerRight,
                child: Text('أعلى المنتجات ربحاً',
                    style:
                        TextStyle(fontWeight: FontWeight.bold, fontSize: 15)),
              ),
              const SizedBox(height: 10),
              if (products.isEmpty)
                const Padding(
                  padding: EdgeInsets.all(24),
                  child: Center(
                      child: Text('لا مبيعات بمنتجات في هذه الفترة',
                          style: TextStyle(color: AmyalColors.textMuted))),
                )
              else
                ...products.map((p) {
                  final profit = _d(p['profit']);
                  final revenue = _d(p['revenue']);
                  final positive = profit >= 0;
                  return Padding(
                    padding: const EdgeInsets.only(bottom: 10),
                    child: Container(
                      padding: const EdgeInsets.all(14),
                      decoration: BoxDecoration(
                        color: Colors.white,
                        borderRadius: BorderRadius.circular(14),
                      ),
                      child: Column(children: [
                        Row(children: [
                          Text(
                              '${positive ? '+' : ''}${AmialMoney.yer(profit)}',
                              style: TextStyle(
                                  fontWeight: FontWeight.bold,
                                  fontSize: 15,
                                  color: positive
                                      ? const Color(0xFF2E7D32)
                                      : AmyalColors.red)),
                          const Spacer(),
                          Expanded(
                            flex: 2,
                            child: Text('${p['name']}',
                                textAlign: TextAlign.right,
                                maxLines: 1,
                                overflow: TextOverflow.ellipsis,
                                style: const TextStyle(
                                    fontWeight: FontWeight.bold,
                                    fontSize: 14)),
                          ),
                        ]),
                        const SizedBox(height: 6),
                        Row(
                          mainAxisAlignment: MainAxisAlignment.spaceBetween,
                          children: [
                            Text('الإيراد: ${AmialMoney.yer(revenue)}',
                                style: const TextStyle(
                                    fontSize: 11,
                                    color: AmyalColors.textSecondary)),
                            Text(
                                'الكمية المباعة: ${AmialMoney.fmt(p['qty'])}',
                                style: const TextStyle(
                                    fontSize: 11,
                                    color: AmyalColors.textSecondary)),
                          ],
                        ),
                      ]),
                    ),
                  );
                }),

              const SizedBox(height: 8),
              const Text(
                'التكلفة محسوبة من «سعر التكلفة» الحالي لكل منتج — حدّثه من إدارة المخزون لدقة أعلى.',
                textAlign: TextAlign.center,
                style: TextStyle(fontSize: 11, color: AmyalColors.textMuted),
              ),
            ],
          ),
        );
      }),
    );
  }
}
