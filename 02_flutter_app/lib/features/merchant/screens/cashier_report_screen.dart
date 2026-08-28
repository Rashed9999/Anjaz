import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amial_pay/features/merchant/controllers/cashier_controller.dart';
import 'package:amial_pay/helper/date_converter_helper.dart';
import 'package:amial_pay/features/merchant/screens/cashier_refund_screen.dart';
import 'package:amial_pay/features/merchant/screens/cashier_sale_detail_screen.dart';
import 'package:amial_pay/theme/amial_colors.dart';

/// AMIAL-CASHIER-001 — تقرير المبيعات اليومي.
class CashierReportScreen extends StatefulWidget {
  const CashierReportScreen({super.key});

  @override
  State<CashierReportScreen> createState() => _CashierReportScreenState();
}

class _CashierReportScreenState extends State<CashierReportScreen> {
  CashierController get c => Get.find<CashierController>();

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      c.loadReport();
      c.loadSales(); // AMIAL-CASHIER-REFUND-001 — مبيعات اليوم (مدخل الاسترجاع)
    });
  }

  // AMIAL-CASHIER-REFUND-001 — فتح شاشة الاسترجاع من صفّ بيع، وتحديث القوائم بعده
  Future<void> _openRefund(String saleUlid) async {
    final result = await Get.to(() => CashierRefundScreen(saleUlid: saleUlid));
    if (result == true) {
      c.loadReport();
      c.loadSales();
    }
  }

  /// AMIAL-RETAIL-VERTICAL-001 · المرحلة ١ — فتحُ تفصيل البيعة سطراً سطراً.
  void _openSaleDetail(String saleUlid) {
    if (saleUlid.isEmpty) return;
    Get.to(() => CashierSaleDetailScreen(saleUlid: saleUlid));
  }

  String _methodLabel(String? m) => switch (m) {
        'cash' => 'نقد',
        'credit' => 'أجل',
        'amial_pay' => 'أميال باي',
        _ => m ?? '',
      };

  String _timeOf(String? iso) {
    final d = DateConverterHelper.tryFromApi(iso);
    if (d == null) return '';
    return '${d.hour.toString().padLeft(2, '0')}:${d.minute.toString().padLeft(2, '0')}';
  }

  Widget _saleRow(Map<String, dynamic> s) {
    final fullyRefunded = s['fully_refunded'] == true;
    final refunded = double.tryParse((s['refunded_total'] ?? '0').toString()) ?? 0;
    return Card(
      color: AmialColors.cardSurface,
      child: ListTile(
        dense: true,
        // AMIAL-RETAIL-VERTICAL-001 · المرحلة ١ — **الصفُّ يُفتح**.
        // صار للبيعة أسطرٌ بتكلفةٍ وربح، ولولا هذه الضغطة لبقيت جدولاً
        // لا بابَ له (القاعدة ١٢).
        onTap: () => _openSaleDetail((s['sale_ulid'] ?? '').toString()),
        leading: Icon(
          fullyRefunded ? Icons.replay_circle_filled : Icons.receipt_long_outlined,
          color: fullyRefunded ? AmialColors.red : AmialColors.primary,
        ),
        title: Text('${_n(s['total_amount'])} ر.ي — ${_methodLabel(s['payment_method']?.toString())}',
            style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14)),
        subtitle: Text(
          [
            _timeOf(s['created_at']?.toString()),
            if ((s['customer_name'] ?? '').toString().isNotEmpty) s['customer_name'].toString(),
            if (refunded > 0) 'مسترجَع: ${_n(s['refunded_total'])} ر.ي',
          ].join(' · '),
          style: const TextStyle(fontSize: 12, color: AmialColors.textSecondary),
        ),
        trailing: fullyRefunded
            ? const Text('مسترجَع كاملاً',
                style: TextStyle(fontSize: 11, color: AmialColors.red))
            : TextButton.icon(
                onPressed: () => _openRefund((s['sale_ulid'] ?? '').toString()),
                icon: const Icon(Icons.replay_rounded, size: 16),
                label: const Text('استرجاع', style: TextStyle(fontSize: 12)),
                style: TextButton.styleFrom(foregroundColor: AmialColors.red),
              ),
      ),
    );
  }

  Widget _card(String label, String value, Color color) {
    return Expanded(
      child: Container(
        margin: const EdgeInsets.all(4),
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(
          color: AmialColors.cardSurface,
          borderRadius: BorderRadius.circular(8),
          border: Border(bottom: BorderSide(color: color, width: 3)),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(label, style: const TextStyle(fontSize: 12, color: AmialColors.textSecondary)),
            const SizedBox(height: 6),
            Text(value, style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold, color: color)),
          ],
        ),
      ),
    );
  }

  String _n(dynamic v) => (double.tryParse(v?.toString() ?? '0') ?? 0).toStringAsFixed(2);

  String _hourLabel(dynamic h) {
    final hr = h is int ? h : int.tryParse('$h') ?? 0;
    final h12 = hr % 12 == 0 ? 12 : hr % 12;
    return '$h12 ${hr < 12 ? 'ص' : 'م'}';
  }

  // AMIAL-REPORTS-HOURLY-001 — قسم المبيعات بالساعة (مخطط 24 ساعة + قائمة).
  Widget _hourlySection(Map<String, dynamic> r) {
    final byHour = ((r['by_hour'] ?? []) as List)
        .map((e) => Map<String, dynamic>.from(e as Map))
        .toList();
    if (byHour.isEmpty) return const SizedBox.shrink();
    final peak = r['peak_hour'];
    double amt(Map h) => double.tryParse('${h['total']}') ?? 0;
    final maxT = byHour.fold<double>(0, (a, h) => amt(h) > a ? amt(h) : a);
    final active = byHour.where((h) => (h['count'] ?? 0) != 0).toList();

    return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      const SizedBox(height: 18),
      Row(children: [
        const Text('المبيعات بالساعة', style: TextStyle(fontWeight: FontWeight.bold)),
        const Spacer(),
        if (peak != null && maxT > 0)
          Text('الذروة: ${_hourLabel(peak)} • ${_n(r['peak_hour_total'])} ر.ي',
              style: const TextStyle(fontSize: 12, color: AmialColors.yellowDark, fontWeight: FontWeight.bold)),
      ]),
      const SizedBox(height: 10),
      if (maxT <= 0)
        const Padding(
          padding: EdgeInsets.symmetric(vertical: 8),
          child: Text('لا مبيعات اليوم بعد', style: TextStyle(color: AmialColors.textSecondary, fontSize: 13)),
        )
      else ...[
        // مخطط أعمدة لكل 24 ساعة
        Container(
          padding: const EdgeInsets.all(10),
          decoration: BoxDecoration(color: AmialColors.cardSurface, borderRadius: BorderRadius.circular(10)),
          child: SizedBox(
            height: 92,
            child: Row(crossAxisAlignment: CrossAxisAlignment.end, children: byHour.map((h) {
              final t = amt(h);
              final hh = h['hour'] as int;
              final barH = maxT > 0 ? (t / maxT) * 72.0 : 0.0;
              final isPeak = peak != null && hh == peak;
              return Expanded(
                child: Padding(
                  padding: const EdgeInsets.symmetric(horizontal: 1),
                  child: Column(mainAxisAlignment: MainAxisAlignment.end, children: [
                    Container(
                      height: (t > 0 && barH < 3) ? 3 : barH,
                      decoration: BoxDecoration(
                        color: isPeak ? AmialColors.yellowDark : AmialColors.primary.withValues(alpha: t > 0 ? 0.85 : 0.0),
                        borderRadius: BorderRadius.circular(2),
                      ),
                    ),
                    const SizedBox(height: 3),
                    if (hh % 6 == 0)
                      Text('$hh', style: const TextStyle(fontSize: 8, color: AmialColors.textMuted)),
                  ]),
                ),
              );
            }).toList()),
          ),
        ),
        const SizedBox(height: 12),
        // قائمة الساعات النشطة (المبلغ + العدد)
        ...active.map((h) => Padding(
              padding: const EdgeInsets.symmetric(vertical: 3),
              child: Row(children: [
                SizedBox(width: 52, child: Text(_hourLabel(h['hour']),
                    style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w700))),
                Expanded(
                  child: ClipRRect(
                    borderRadius: BorderRadius.circular(4),
                    child: LinearProgressIndicator(
                      value: maxT > 0 ? amt(h) / maxT : 0,
                      minHeight: 8,
                      backgroundColor: AmialColors.border,
                      valueColor: AlwaysStoppedAnimation(
                          (peak == h['hour']) ? AmialColors.yellowDark : AmialColors.primary),
                    ),
                  ),
                ),
                const SizedBox(width: 8),
                Text('${_n(h['total'])} ر.ي', style: const TextStyle(fontSize: 12, fontWeight: FontWeight.bold)),
                const SizedBox(width: 6),
                Text('(${h['count']})', style: const TextStyle(fontSize: 11, color: AmialColors.textSecondary)),
              ]),
            )),
      ],
    ]);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(
        title: const Text('تقرير اليوم'),
      ),
      body: RefreshIndicator(
        onRefresh: () async {
          await c.loadReport();
          await c.loadSales();
        },
        child: Obx(() {
          if (c.isLoadingReport.value && c.report.value == null) {
            return const Center(child: CircularProgressIndicator());
          }
          final r = c.report.value;
          if (r == null) {
            return ListView(children: const [SizedBox(height: 120), Center(child: Text('لا بيانات'))]);
          }
          final byMethod = (r['by_method'] ?? {}) as Map;
          final top = (r['top_products'] ?? []) as List;

          return ListView(
            padding: const EdgeInsets.all(12),
            children: [
              Text('تاريخ: ${r['date'] ?? ''}', style: const TextStyle(color: AmialColors.textSecondary)),
              const SizedBox(height: 8),
              Row(children: [
                _card('الإيراد الفعلي', '${_n(r['realized_revenue'])} ر.ي', AmialColors.primary),
                _card('عدد المبيعات', '${r['sales_count'] ?? 0}', AmialColors.yellowDark),
              ]),
              Row(children: [
                _card('نقد', '${_n(byMethod['cash'])} ر.ي', Colors.green),
                _card('أميال باي', '${_n(byMethod['amial_pay'])} ر.ي', AmialColors.primary),
              ]),
              Row(children: [
                _card('أجل اليوم', '${_n(byMethod['credit'])} ر.ي', AmialColors.textSecondary),
                _card('إجمالي الأجل المستحق', '${_n(r['outstanding_credit_total'])} ر.ي', AmialColors.red),
              ]),
              const SizedBox(height: 16),
              if (top.isNotEmpty) ...[
                const Text('الأكثر مبيعاً', style: TextStyle(fontWeight: FontWeight.bold)),
                const SizedBox(height: 8),
                ...top.map((p) => Card(
                      color: AmialColors.cardSurface,
                      child: ListTile(
                        dense: true,
                        leading: const Icon(Icons.star_outline, color: AmialColors.yellowDark),
                        title: Text((p['name'] ?? '').toString()),
                        trailing: Text('×${p['qty'] ?? 0}', style: const TextStyle(fontWeight: FontWeight.bold)),
                      ),
                    )),
              ],

              // AMIAL-REPORTS-HOURLY-001 — تفصيل المبيعات بالساعة + ساعة الذروة
              _hourlySection(r),

              // AMIAL-CASHIER-REFUND-001 — قائمة مبيعات اليوم مع مدخل الاسترجاع
              const SizedBox(height: 16),
              Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  const Text('مبيعات اليوم', style: TextStyle(fontWeight: FontWeight.bold)),
                  if (c.isLoadingSales.value)
                    const SizedBox(
                        width: 14, height: 14, child: CircularProgressIndicator(strokeWidth: 2)),
                ],
              ),
              const SizedBox(height: 8),
              if (c.sales.isEmpty && !c.isLoadingSales.value)
                const Padding(
                  padding: EdgeInsets.symmetric(vertical: 12),
                  child: Text('لا مبيعات مسجّلة لهذا اليوم',
                      style: TextStyle(color: AmialColors.textSecondary, fontSize: 13)),
                )
              else
                ...c.sales.map(_saleRow),
            ],
          );
        }),
      ),
    );
  }
}
