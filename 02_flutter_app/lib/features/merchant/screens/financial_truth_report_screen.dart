import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amial_pay/features/merchant/controllers/merchant_controller.dart';
import 'package:amial_pay/helper/amial_money.dart';
import 'package:amial_pay/theme/amial_colors.dart';

/// تقرير تشغيلي موحّد: لا يخلط البيع بالتحصيل أو حركة المحفظة.
class FinancialTruthReportScreen extends StatefulWidget {
  /// التقرير اليومي حقّ أساسي في المجاني. أمّا اختيار فترة أطول فهو واجهة
  /// التقرير التشغيلي التي تفتحها الباقات الأعمق.
  final bool dailyOnly;
  const FinancialTruthReportScreen({super.key, this.dailyOnly = false});

  @override
  State<FinancialTruthReportScreen> createState() => _FinancialTruthReportScreenState();
}

class _FinancialTruthReportScreenState extends State<FinancialTruthReportScreen> {
  MerchantController get c => Get.find<MerchantController>();
  int days = 1;

  @override
  void initState() {
    super.initState();
    if (widget.dailyOnly) days = 1;
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
    appBar: AppBar(title: Text(widget.dailyOnly ? 'تقرير اليوم' : 'التقرير المالي التشغيلي')),
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
        if (!widget.dailyOnly) ...[
          Wrap(alignment: WrapAlignment.center, spacing: 8, children: [1, 7, 30].map((d) => ChoiceChip(
            label: Text(d == 1 ? 'اليوم' : '$d يوماً'), selected: days == d,
            onSelected: (_) { setState(() => days = d); _load(); },
          )).toList()),
          const SizedBox(height: 16),
        ],
        // AMIAL-DAILY-MOVEMENT-001 — **المصفوفةُ أوّلاً**: هي جوابُ سؤال
        // «ماذا جرى اليوم؟» في نظرةٍ واحدة، وما بعدها تفصيلُها.
        ..._movement((report['movement'] as Map?) ?? const {}),
        _section('المبيعات التشغيلية', 'مصدرها ${sales['source'] ?? '—'}'),
        row('إجمالي البيع', sales['gross'], Icons.point_of_sale_outlined, AmialColors.primary),
        row('نقد', methods['cash'], Icons.payments_outlined, AmialColors.success),
        row('أميال باي', methods['amial_pay'], Icons.account_balance_wallet_outlined, AmialColors.primary),
        row('آجل / شركة', methods['credit'], Icons.credit_score_outlined, AmialColors.warning),
        _section('أين يوجد المال؟', 'كل خانة لها مصدر مختلف ولا تُجمع كرَصيد واحد'),
        row('نقد مبيعات الفترة — يُطابق بالدرج', methods['cash'], Icons.point_of_sale_outlined, AmialColors.success),
        row('رصيد محفظة أميال باي الإلكتروني', wallet['balance'], Icons.account_balance_wallet_outlined, AmialColors.primary),
        row('ذمم العملاء غير المحصّلة', receivables['amount'], Icons.receipt_long_outlined, AmialColors.red),
        const Padding(
          padding: EdgeInsets.only(top: 2, bottom: 4),
          child: Text('النقد ليس رصيد محفظة. طابقه بما في درج نقطة البيع عند إغلاق الوردية؛ والآجل يبقى ذمّة على العميل حتى التحصيل.',
              style: TextStyle(fontSize: 11, color: AmialColors.textMuted)),
        ),
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

  // ══════════════════════════════════════════════════════════════════
  //  AMIAL-DAILY-MOVEMENT-001 — الحركةُ اليوميّة الكاملة
  // ══════════════════════════════════════════════════════════════════

  /// **أربعُ حركاتٍ × ثلاثةُ أعمدة، وصافٍ نقديٌّ واحد.**
  ///
  /// من شاشة تطبيقٍ محاسبيٍّ منافس أرسلها صاحبُ المشروع واختار فكرتَها.
  ///
  /// **وثلاثةُ قراراتٍ في العرض، لا في الخادم وحده:**
  ///
  ///   · **الصفُّ الغائبُ يُكتب غائباً** ولا يُرسَم صفراً — الخادمُ يرسل
  ///     `available:false` ومعه سببُه، **والشاشةُ تقوله بنصّه**. وصفرٌ
  ///     هنا يُقرأ «لم يقع شيءٌ اليوم» وهو كذب. (القاعدة السابعة.)
  ///   · **والاتّجاهُ يُرى قبل الرقم**: سهمٌ داخلٌ أخضرُ وخارجٌ أحمر،
  ///     فالمرتجعُ لا يُقرأ بيعاً بلمحة عين.
  ///   · **والجدولُ يُمرَّر أفقيّاً** ولا يُضغط: أربعةُ أعمدةٍ بأرقامٍ
  ///     ماليّةٍ على شاشة هاتفٍ صغيرةٍ تنكسر، والانكسارُ يقلب الأعمدة.
  List<Widget> _movement(Map m) {
    final rows = (m['rows'] as List?) ?? const [];
    if (rows.isEmpty) return const [];

    final labels = (m['column_labels_ar'] as Map?) ?? const {};
    final net = (m['net_cash'] as Map?) ?? const {};

    Widget head(String t) => Padding(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 10),
      child: Text(t, style: const TextStyle(
          fontSize: 12, fontWeight: FontWeight.w900, color: AmialColors.textSecondary)),
    );

    Widget cell(dynamic v) => Padding(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 12),
      child: Text(money(v), textDirection: TextDirection.ltr,
          style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w700,
              fontFeatures: [FontFeature.tabularFigures()])),
    );

    return [
      _section('الحركة اليومية الكاملة',
          'المبيع والشراء ومرتجعاهما — نقداً وآجلاً، كلٌّ من مصدره'),
      Container(
        decoration: BoxDecoration(
          color: AmialColors.cardSurface,
          borderRadius: BorderRadius.circular(12),
          border: Border.all(color: AmialColors.border),
        ),
        child: SingleChildScrollView(
          scrollDirection: Axis.horizontal,
          child: DataTable(
            headingRowHeight: 40,
            dataRowMinHeight: 44,
            dataRowMaxHeight: 64,
            columnSpacing: 18,
            columns: [
              const DataColumn(label: Text('الحركة',
                  style: TextStyle(fontSize: 12, fontWeight: FontWeight.w900))),
              DataColumn(label: head('${labels['cash'] ?? 'نقدي'}')),
              DataColumn(label: head('${labels['amial_pay'] ?? 'أميال باي'}')),
              DataColumn(label: head('${labels['credit'] ?? 'آجل'}')),
              const DataColumn(label: Text('الإجمالي',
                  style: TextStyle(fontSize: 12, fontWeight: FontWeight.w900))),
            ],
            rows: rows.map<DataRow>((raw) {
              final r = raw as Map;
              final isIn = r['direction'] == 'in';
              final available = r['available'] == true;

              final title = Row(mainAxisSize: MainAxisSize.min, children: [
                Icon(isIn ? Icons.south_west_rounded : Icons.north_east_rounded,
                    size: 15, color: isIn ? AmialColors.success : AmialColors.red),
                const SizedBox(width: 6),
                Text('${r['label_ar'] ?? r['code']}',
                    style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w800)),
              ]);

              if (!available) {
                // **الغيابُ يُقال مرّةً واحدةً بعرض الصفّ** — ولا يُكرَّر
                // في كلّ خانةٍ شرطةً تُقرأ رقماً ناقصاً.
                return DataRow(cells: [
                  DataCell(title),
                  DataCell(Padding(
                    padding: const EdgeInsets.symmetric(vertical: 8),
                    child: Text('${r['unavailable_reason_ar'] ?? 'غير متاح في قطاعك'}',
                        style: const TextStyle(fontSize: 11, color: AmialColors.textMuted)),
                  )),
                  const DataCell(SizedBox.shrink()),
                  const DataCell(SizedBox.shrink()),
                  const DataCell(SizedBox.shrink()),
                ]);
              }

              return DataRow(cells: [
                DataCell(title),
                DataCell(cell(r['cash'])),
                DataCell(cell(r['amial_pay'])),
                DataCell(cell(r['credit'])),
                DataCell(Text(money(r['total']), textDirection: TextDirection.ltr,
                    style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w900))),
              ]);
            }).toList(),
          ),
        ),
      ),
      if (net.isNotEmpty)
        Padding(
          padding: const EdgeInsets.only(top: 10),
          child: Container(
            padding: const EdgeInsets.all(12),
            decoration: BoxDecoration(
              color: AmialColors.success.withValues(alpha: 0.08),
              borderRadius: BorderRadius.circular(12),
              border: Border.all(color: AmialColors.success.withValues(alpha: 0.3)),
            ),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Row(children: [
                const Icon(Icons.point_of_sale_outlined, size: 18, color: AmialColors.success),
                const SizedBox(width: 8),
                Expanded(child: Text('${net['label_ar'] ?? 'صافي النقد'}',
                    style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w800))),
                Text(money(net['amount']), textDirection: TextDirection.ltr,
                    style: const TextStyle(fontSize: 15, fontWeight: FontWeight.w900,
                        color: AmialColors.success)),
              ]),
              if ((net['note_ar'] ?? '').toString().isNotEmpty)
                Padding(
                  padding: const EdgeInsets.only(top: 6),
                  child: Text('${net['note_ar']}',
                      style: const TextStyle(fontSize: 11, color: AmialColors.textMuted)),
                ),
            ]),
          ),
        ),
    ];
  }

  Widget _section(String title, String note) => Padding(
    padding: const EdgeInsets.only(top: 16, bottom: 6),
    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      Text(title, style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w900)),
      Text(note, style: const TextStyle(fontSize: 11, color: AmialColors.textMuted)),
    ]),
  );
}
