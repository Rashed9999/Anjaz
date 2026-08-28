import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amial_pay/features/merchant/controllers/merchant_controller.dart';
import 'package:amial_pay/helper/amial_money.dart';
import 'package:amial_pay/theme/amial_colors.dart';

/// تقرير تشغيلي موحّد: لا يخلط البيع بالتحصيل أو حركة المحفظة.
class FinancialTruthReportScreen extends StatefulWidget {
  const FinancialTruthReportScreen({super.key});

  @override
  State<FinancialTruthReportScreen> createState() => _FinancialTruthReportScreenState();
}

class _FinancialTruthReportScreenState extends State<FinancialTruthReportScreen> {
  MerchantController get c => Get.find<MerchantController>();
  int days = 1;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _load());
  }

  Future<void> _load() {
    final now = DateTime.now();
    final from = now.subtract(Duration(days: days - 1));
    String date(DateTime d) => '${d.year}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';
    return c.loadFinancialReport(from: date(from), to: date(now));
  }

  String money(dynamic value) => AmialMoney.yer(value);
  Widget row(String label, dynamic value, IconData icon, Color color) => ListTile(
    dense: true, leading: Icon(icon, color: color), title: Text(label),
    trailing: Text(money(value), style: const TextStyle(fontWeight: FontWeight.w900)),
  );

  @override
  Widget build(BuildContext context) => Scaffold(
    backgroundColor: AmialColors.background,
    appBar: AppBar(title: const Text('التقرير المالي التشغيلي')),
    body: Obx(() {
      final report = c.financialReport.value;
      if (c.isLoadingFinancialReport.value && report == null) {
        return const Center(child: CircularProgressIndicator());
      }
      if (report == null) return Center(child: Padding(
        padding: const EdgeInsets.all(24),
        child: Text(c.lastError.value.isEmpty ? 'لا توجد بيانات للتقرير' : c.lastError.value,
            textAlign: TextAlign.center),
      ));
      final sales = (report['sales'] as Map?) ?? const {};
      final methods = (sales['by_payment_method'] as Map?) ?? const {};
      final wallet = (report['wallet'] as Map?) ?? const {};
      final collections = (report['collections'] as Map?) ?? const {};
      final receivables = (report['receivables'] as Map?) ?? const {};
      return RefreshIndicator(onRefresh: _load, child: ListView(padding: const EdgeInsets.all(16), children: [
        Wrap(alignment: WrapAlignment.center, spacing: 8, children: [1, 7, 30].map((d) => ChoiceChip(
          label: Text(d == 1 ? 'اليوم' : '$d يوماً'), selected: days == d,
          onSelected: (_) { setState(() => days = d); _load(); },
        )).toList()),
        const SizedBox(height: 16),
        _section('المبيعات التشغيلية', 'مصدرها ${sales['source'] ?? '—'}'),
        row('إجمالي البيع', sales['gross'], Icons.point_of_sale_outlined, AmialColors.primary),
        row('نقد', methods['cash'], Icons.payments_outlined, AmialColors.success),
        row('أميال باي', methods['amial_pay'], Icons.account_balance_wallet_outlined, AmialColors.primary),
        row('آجل / شركة', methods['credit'], Icons.credit_score_outlined, AmialColors.warning),
        _section('حركة المحفظة', 'تُعرض مستقلة عن البيع النقدي والآجل'),
        row('المستلم في المحفظة', wallet['received'], Icons.south_west_rounded, AmialColors.success),
        row('المدفوع من المحفظة', wallet['paid_out'], Icons.north_east_rounded, AmialColors.red),
        row('رصيد المحفظة الحالي', wallet['balance'], Icons.account_balance_wallet, AmialColors.primary),
        if ((collections['count'] as num? ?? 0) > 0) ...[
          _section('تحصيلات ديون سابقة', 'لا تدخل ضمن بيع الفترة'),
          row('نقد محصّل', collections['cash'], Icons.payments_outlined, AmialColors.success),
          row('أميال باي محصّل', collections['amial_pay'], Icons.qr_code_rounded, AmialColors.primary),
        ],
        if (receivables['known'] == true) ...[
          _section('الذمم الحالية', '${receivables['source']}'),
          row('إجمالي المستحق', receivables['amount'], Icons.receipt_long_outlined, AmialColors.red),
        ],
      ]));
    }),
  );

  Widget _section(String title, String note) => Padding(
    padding: const EdgeInsets.only(top: 16, bottom: 6),
    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      Text(title, style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w900)),
      Text(note, style: const TextStyle(fontSize: 11, color: AmialColors.textMuted)),
    ]),
  );
}
