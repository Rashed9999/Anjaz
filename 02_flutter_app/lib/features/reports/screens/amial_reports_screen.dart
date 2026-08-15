import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amial_pay/data/api/api_client.dart';
import 'package:amial_pay/features/history/controllers/transaction_history_controller.dart';
import 'package:amial_pay/features/history/domain/models/transaction_model.dart';
import 'package:amial_pay/helper/amial_money.dart';
import 'package:amial_pay/helper/date_converter_helper.dart';
import 'package:amial_pay/helper/pdf_downloader_helper.dart';
import 'package:amial_pay/theme/amial_colors.dart';
import 'package:amial_pay/util/app_constants.dart';
import 'package:amial_pay/common/widgets/amial_donut_chart.dart';
import 'package:amial_pay/common/widgets/amial_form.dart';
import 'package:amial_pay/features/reports/screens/amial_account_statement_screen.dart';

/// AMIAL-REPORTS-001 — شاشة «التقارير»:
/// أنواع التقارير (المصروفات / الإيرادات / كشف الحساب) مع اختيار الفترة،
/// ملخّص إجمالي، وتفصيل المصروفات حسب نوع العملية، وتنزيل كشف حساب PDF.
class AmialReportsScreen extends StatefulWidget {
  const AmialReportsScreen({super.key});

  @override
  State<AmialReportsScreen> createState() => _AmialReportsScreenState();
}

enum _Period { month, days30, days90, all }

enum _ReportType { expenses, income, statement }

class _AmialReportsScreenState extends State<AmialReportsScreen> {
  _Period _period = _Period.month;
  _ReportType _type = _ReportType.expenses;

  bool _loading = true;
  bool _downloading = false;
  String _error = '';
  List<Transactions> _txs = [];

  /// تسميات عربية لأنواع العمليات (مفاتيح 6cash)
  static const Map<String, String> _typeLabels = {
    'send_money': 'تحويلات صادرة',
    'received_money': 'تحويلات واردة',
    'cash_in': 'إيداع نقدي',
    'cash_out': 'سحب عبر وكيل',
    'add_money': 'شحن رصيد',
    'withdraw': 'سحب نقدي',
    'payment': 'مدفوعات',
    'add_money_bonus': 'مكافآت',
    'admin_charge': 'رسوم',
    'charge': 'رسوم',
  };

  @override
  void initState() {
    super.initState();
    _load();
  }

  DateTime? get _startDate {
    final now = DateTime.now();
    switch (_period) {
      case _Period.month:
        return DateTime(now.year, now.month, 1);
      case _Period.days30:
        return now.subtract(const Duration(days: 30));
      case _Period.days90:
        return now.subtract(const Duration(days: 90));
      case _Period.all:
        return null;
    }
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = '';
    });
    try {
      final api = Get.find<ApiClient>();
      final params = <String, String>{
        'offset': '1',
        'limit': '500',
        'transaction_type': 'all',
        if (_startDate != null)
          'start_date': DateConverterHelper.formatDate(_startDate!),
        if (_startDate != null)
          'end_date': DateConverterHelper.formatDate(DateTime.now()),
      };
      final qs = Uri(queryParameters: params).query;
      final r = await api.getData('${AppConstants.customerTransactionHistory}?$qs');
      if (r.statusCode == 200 && r.body is Map) {
        final model = TransactionModel.fromJson(r.body);
        _txs = model.transactions ?? [];
      } else {
        _error = 'تعذّر تحميل البيانات';
      }
    } catch (_) {
      _error = 'خطأ في الشبكة';
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  /// تنزيل كشف الحساب PDF للفترة المحدّدة.
  ///
  /// **والرسالةُ تتبع السبب.** كانت تقول «لا توجد عمليات في هذه الفترة»
  /// على شاشةٍ تعرض عشرين عمليّة — لأنّ الردّ `null` كان يُقرأ فراغاً
  /// وهو كان رفضاً من شرطٍ يسأل متحكّماً آخر عن حالةٍ لم تُحمَّل.
  Future<void> _downloadStatement() async {
    // **الفراغُ يُفحص هنا حيث تُعرف الحقيقة** — الشاشةُ تملك القائمة.
    if (_txs.isEmpty) {
      _snack('لا توجد عمليات في هذه الفترة — جرّب فترة أوسع');
      return;
    }

    setState(() => _downloading = true);
    try {
      final ctrl = Get.find<TransactionHistoryController>();
      final pdf = await ctrl.downloadTransactionHistory(
        transactionType: 'all',
        startDate: _startDate,
        endDate: _startDate != null ? DateTime.now() : null,
      );

      if (pdf != null) {
        await PdfDownloaderHelper.downloadAndOpenPdf(
            pdfData: pdf, baseFileName: 'Amial_Statement');
        return;
      }

      if (mounted) {
        // سببُ الفشل من المتحكّم لا جملةٌ عامّة.
        _snack(ctrl.downloadError.isNotEmpty
            ? ctrl.downloadError
            : 'تعذّر تنزيل الكشف — أعد المحاولة');
      }
    } catch (_) {
      if (mounted) _snack('تعذّر تنزيل الكشف — حاول مجدداً');
    } finally {
      if (mounted) setState(() => _downloading = false);
    }
  }

  void _snack(String msg) {
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
        content: Text(msg), backgroundColor: AmialColors.red));
  }

  // ============ تجميع البيانات ============

  double get _totalDebit =>
      _txs.fold(0.0, (s, t) => s + (t.debit ?? 0));
  double get _totalCredit =>
      _txs.fold(0.0, (s, t) => s + (t.credit ?? 0));

  /// تفصيل حسب نوع العملية للاتجاه المطلوب (مصروف=debit / دخل=credit).
  List<MapEntry<String, double>> _breakdown({required bool debit}) {
    final map = <String, double>{};
    for (final t in _txs) {
      final v = debit ? (t.debit ?? 0) : (t.credit ?? 0);
      if (v <= 0) continue;
      final key = _typeLabels[t.transactionType ?? ''] ??
          (t.transactionType ?? 'أخرى');
      map[key] = (map[key] ?? 0) + v;
    }
    final entries = map.entries.toList()
      ..sort((a, b) => b.value.compareTo(a.value));
    return entries;
  }

  @override
  Widget build(BuildContext context) {
    // AMIAL-DS-002: ترويسة خفيفة موحّدة بدل AppBar أزرق صلب.
    return Scaffold(
      backgroundColor: AmialColors.background,
      body: SafeArea(
        child: Column(children: [
          AmialScreenHeader(
            title: 'التقارير',
            actions: [
              // AMIAL-STATEMENT-001: كشف الحساب مدخله الطبيعي من التقارير.
              AmialHeaderAction(
                icon: Icons.table_rows_rounded,
                onTap: () => Get.to(() => const AmialAccountStatementScreen()),
              ),
            ],
          ),
          Expanded(
            child: RefreshIndicator(
        onRefresh: _load,
        child: ListView(
          padding: const EdgeInsets.fromLTRB(16, 0, 16, 16),
          children: [
            // ====== نوع التقرير ======
            _sectionTitle(Icons.assessment_outlined, 'نوع التقرير'),
            const SizedBox(height: 8),
            Row(children: [
              _typeChip('المصروفات', _ReportType.expenses, Icons.arrow_upward),
              const SizedBox(width: 8),
              _typeChip('الإيرادات', _ReportType.income, Icons.arrow_downward),
              const SizedBox(width: 8),
              _typeChip('كشف الحساب', _ReportType.statement, Icons.receipt_long),
            ]),
            const SizedBox(height: 16),

            // ====== الفترة ======
            _sectionTitle(Icons.date_range_outlined, 'الفترة'),
            const SizedBox(height: 8),
            Wrap(spacing: 8, runSpacing: 8, children: [
              _periodChip('هذا الشهر', _Period.month),
              _periodChip('آخر 30 يوماً', _Period.days30),
              _periodChip('آخر 90 يوماً', _Period.days90),
              _periodChip('الكل', _Period.all),
            ]),
            const SizedBox(height: 20),

            if (_loading)
              const Padding(
                padding: EdgeInsets.all(40),
                child: Center(
                    child: CircularProgressIndicator(color: AmialColors.primary)),
              )
            else if (_error.isNotEmpty)
              Padding(
                padding: const EdgeInsets.all(24),
                child: Column(children: [
                  Text(_error, style: const TextStyle(color: AmialColors.red)),
                  const SizedBox(height: 8),
                  TextButton(onPressed: _load, child: const Text('إعادة المحاولة')),
                ]),
              )
            else ...[
              // ====== الملخّص ======
              Row(children: [
                Expanded(
                    child: _summaryCard('المصروفات', _totalDebit,
                        const Color(0xFFDC0A0B), Icons.arrow_upward)),
                const SizedBox(width: 10),
                Expanded(
                    child: _summaryCard('الإيرادات', _totalCredit,
                        AmialColors.success, Icons.arrow_downward)),
              ]),
              const SizedBox(height: 10),
              _summaryCard(
                  'الصافي',
                  _totalCredit - _totalDebit,
                  AmialColors.primary,
                  Icons.account_balance_wallet_outlined),
              const SizedBox(height: 20),

              // ====== محتوى التقرير ======
              if (_type == _ReportType.statement)
                _statementSection()
              else
                _breakdownSection(debit: _type == _ReportType.expenses),
            ],
          ],
        ),
      ),
          ),
        ]),
      ),
    );
  }

  // ============ Widgets ============

  Widget _sectionTitle(IconData icon, String text) => Row(children: [
        Icon(icon, size: 18, color: AmialColors.textSecondary),
        const SizedBox(width: 8),
        Text(text,
            style: const TextStyle(
                fontWeight: FontWeight.bold,
                fontSize: 13,
                color: AmialColors.textSecondary)),
      ]);

  Widget _typeChip(String label, _ReportType t, IconData icon) {
    final selected = _type == t;
    return Expanded(
      child: InkWell(
        onTap: () => setState(() => _type = t),
        borderRadius: BorderRadius.circular(12),
        child: Container(
          padding: const EdgeInsets.symmetric(vertical: 10),
          decoration: BoxDecoration(
            color: selected ? AmialColors.primary : Colors.white,
            borderRadius: BorderRadius.circular(12),
            border: Border.all(
                color: selected ? AmialColors.primary : AmialColors.border),
          ),
          child: Column(children: [
            Icon(icon,
                size: 18,
                color: selected ? Colors.white : AmialColors.primary),
            const SizedBox(height: 4),
            Text(label,
                style: TextStyle(
                    fontSize: 11,
                    fontWeight: FontWeight.w600,
                    color: selected ? Colors.white : AmialColors.primary)),
          ]),
        ),
      ),
    );
  }

  Widget _periodChip(String label, _Period p) {
    final selected = _period == p;
    return ChoiceChip(
      label: Text(label, style: const TextStyle(fontSize: 12)),
      selected: selected,
      selectedColor: AmialColors.primary,
      labelStyle: TextStyle(color: selected ? Colors.white : AmialColors.primary),
      backgroundColor: Colors.white,
      onSelected: (_) {
        setState(() => _period = p);
        _load();
      },
    );
  }

  Widget _summaryCard(String label, double value, Color color, IconData icon) {
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(12),
      ),
      child: Row(children: [
        CircleAvatar(
          radius: 18,
          backgroundColor: color.withValues(alpha: 0.12),
          child: Icon(icon, color: color, size: 18),
        ),
        const SizedBox(width: 10),
        Expanded(
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(label,
                style: const TextStyle(
                    fontSize: 11, color: AmialColors.textSecondary)),
            Text(AmialMoney.yer(value),
                style: TextStyle(
                    fontSize: 16, fontWeight: FontWeight.bold, color: color)),
          ]),
        ),
      ]),
    );
  }

  /// تفصيل المصروفات/الإيرادات حسب النوع مع أشرطة نسبية.
  Widget _breakdownSection({required bool debit}) {
    final entries = _breakdown(debit: debit);
    final total = debit ? _totalDebit : _totalCredit;
    if (entries.isEmpty) {
      return const Padding(
        padding: EdgeInsets.all(24),
        child: Center(
            child: Text('لا توجد عمليات في هذه الفترة',
                style: TextStyle(color: AmialColors.textMuted))),
      );
    }
    final barColor = debit ? const Color(0xFFDC0A0B) : AmialColors.success;
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(12),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(debit ? 'تفصيل المصروفات حسب النوع' : 'تفصيل الإيرادات حسب النوع',
              style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13)),
          const SizedBox(height: 4),
          // AMIAL-CHART-001: مخطّط حلقي بالمجموع في المركز — كان القسم
          // أشرطة نسب فقط بلا صورة كلّية تُقرأ بلمحة.
          Center(
            child: AmialDonutChart(
              slices: entries.take(6).toList(),
              centerLabel: debit ? 'إجمالي المصروفات' : 'إجمالي الإيرادات',
              centerValue: AmialMoney.yer(total),
            ),
          ),
          const SizedBox(height: 10),
          ...entries.map((e) {
            final ratio = total > 0 ? (e.value / total).clamp(0.0, 1.0) : 0.0;
            return Padding(
              padding: const EdgeInsets.symmetric(vertical: 6),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Row(
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    children: [
                      Text(e.key,
                          style: const TextStyle(
                              fontSize: 13, fontWeight: FontWeight.w600)),
                      Text(
                          '${AmialMoney.yer(e.value)} — ${(ratio * 100).toStringAsFixed(0)}%',
                          style: TextStyle(
                              fontSize: 12,
                              fontWeight: FontWeight.bold,
                              color: barColor)),
                    ],
                  ),
                  const SizedBox(height: 4),
                  ClipRRect(
                    borderRadius: BorderRadius.circular(6),
                    child: LinearProgressIndicator(
                      value: ratio,
                      minHeight: 7,
                      backgroundColor: const Color(0xFFF0EFEA),
                      color: barColor,
                    ),
                  ),
                ],
              ),
            );
          }),
        ],
      ),
    );
  }

  /// قسم كشف الحساب: عدد العمليات + زر تنزيل PDF.
  Widget _statementSection() {
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(12),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(children: [
            const Icon(Icons.receipt_long, color: AmialColors.primary),
            const SizedBox(width: 10),
            Expanded(
              child: Text('كشف حساب رسمي يشمل ${_txs.length} عملية في الفترة المحدّدة',
                  style: const TextStyle(fontSize: 13, height: 1.4)),
            ),
          ]),
          const SizedBox(height: 14),
          FilledButton.icon(
            onPressed: _downloading ? null : _downloadStatement,
            icon: _downloading
                ? const SizedBox(
                    width: 16,
                    height: 16,
                    child: CircularProgressIndicator(
                        strokeWidth: 2, color: Colors.white))
                : const Icon(Icons.picture_as_pdf_outlined),
            label: const Text('تنزيل كشف الحساب PDF'),
            style: FilledButton.styleFrom(
              backgroundColor: AmialColors.primary,
              minimumSize: const Size.fromHeight(50),
            ),
          ),
        ],
      ),
    );
  }
}
