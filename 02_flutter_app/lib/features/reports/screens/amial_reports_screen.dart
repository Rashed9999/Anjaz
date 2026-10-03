import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amial_pay/common/widgets/amial_form.dart';
import 'package:amial_pay/data/api/api_client.dart';
import 'package:amial_pay/features/history/controllers/transaction_history_controller.dart';
import 'package:amial_pay/features/reports/screens/amial_account_statement_screen.dart';
import 'package:amial_pay/helper/amial_money.dart';
import 'package:amial_pay/helper/date_converter_helper.dart';
import 'package:amial_pay/helper/pdf_downloader_helper.dart';
import 'package:amial_pay/theme/amial_colors.dart';
import 'package:amial_pay/theme/amial_spacing.dart';

/// AMIAL-CUSTOMER-REPORTS-003 — تقرير عميل مصدره الدفتر على الخادم.
///
/// لا يُجمع أول 500 صف داخل الهاتف. الخادم يعيد الإجماليات لكل الفترة
/// بدقة عشرية، ولكل عملة على حدة، مع فصل التصحيحات عن النشاط الاعتيادي.
class AmialReportsScreen extends StatefulWidget {
  const AmialReportsScreen({super.key});

  @override
  State<AmialReportsScreen> createState() => _AmialReportsScreenState();
}

enum _Period { month, days30, days90, all }
enum _ReportType { expenses, income, statement }
enum _ReportViewState {
  loading,
  ready,
  empty,
  error,
  permissionDenied,
  offline,
  maintenance,
}

class _AmialReportsScreenState extends State<AmialReportsScreen> {
  static const String _summaryEndpoint = '/api/v1/customer/reports/summary';

  _Period _period = _Period.days30;
  _ReportType _type = _ReportType.expenses;
  _ReportViewState _viewState = _ReportViewState.loading;

  bool _downloading = false;
  String _stateMessage = '';
  List<Map<String, dynamic>> _currencies = const [];
  int _currencyIndex = 0;
  DateTime? _lastUpdatedAt;
  int _loadEpoch = 0;

  static const Map<String, String> _sourceLabels = {
    'send_money': 'تحويلات',
    'cash_in': 'إيداع نقدي',
    'cash_out': 'سحب نقدي',
    'payment': 'مدفوعات',
    'merchant_payment': 'مدفوعات تاجر',
    'admin_charge': 'رسوم',
    'fee': 'رسوم',
    'refund': 'استرجاع',
    'debt': 'ديون',
    'invoice': 'فواتير',
    'pending_transfer_release': 'تحويلات مستلمة',
    'opening_balance': 'رصيد افتتاحي',
    'external_adjustment': 'تسوية محاسبية',
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

  String get _periodLabel {
    switch (_period) {
      case _Period.month:
        return 'هذا الشهر';
      case _Period.days30:
        return 'آخر 30 يوماً';
      case _Period.days90:
        return 'آخر 90 يوماً';
      case _Period.all:
        return 'كل الفترات';
    }
  }

  Map<String, dynamic>? get _selectedCurrency {
    if (_currencies.isEmpty) return null;
    final safe = _currencyIndex.clamp(0, _currencies.length - 1);
    return _currencies[safe];
  }

  Future<void> _load() async {
    final epoch = ++_loadEpoch;
    if (mounted) {
      setState(() {
        _viewState = _ReportViewState.loading;
        _stateMessage = '';
      });
    }

    try {
      final api = Get.find<ApiClient>();
      final params = <String, String>{
        if (_startDate != null)
          'from': DateConverterHelper.formatDate(_startDate!),
        'to': DateConverterHelper.formatDate(DateTime.now()),
      };
      final query = Uri(queryParameters: params).query;
      final response = await api.getData(
        '$_summaryEndpoint${query.isEmpty ? '' : '?$query'}',
      );

      if (!mounted || epoch != _loadEpoch) return;

      if (response.statusCode == 200 && response.body is Map) {
        final body = Map<String, dynamic>.from(response.body as Map);
        final metaRaw = body['meta'];
        final meta = metaRaw is Map
            ? Map<String, dynamic>.from(metaRaw)
            : <String, dynamic>{};
        final rowsRaw = meta['by_currency'];
        final rows = rowsRaw is List
            ? rowsRaw
                .whereType<Map>()
                .map((e) => Map<String, dynamic>.from(e))
                .toList(growable: false)
            : <Map<String, dynamic>>[];

        setState(() {
          _currencies = rows;
          _currencyIndex = 0;
          _lastUpdatedAt = DateTime.now();
          _viewState = rows.isEmpty
              ? _ReportViewState.empty
              : _ReportViewState.ready;
        });
        return;
      }

      if (response.statusCode == 403) {
        setState(() {
          _viewState = _ReportViewState.permissionDenied;
          _stateMessage = 'ليس لديك صلاحية لعرض هذا التقرير.';
        });
        return;
      }
      if (response.statusCode == 503) {
        setState(() {
          _viewState = _ReportViewState.maintenance;
          _stateMessage = 'خدمة التقارير تحت الصيانة حالياً.';
        });
        return;
      }
      if (response.statusCode == 1) {
        setState(() {
          _viewState = _ReportViewState.offline;
          _stateMessage = 'تعذّر الاتصال بالخادم. تحقق من الشبكة.';
        });
        return;
      }

      setState(() {
        _viewState = _ReportViewState.error;
        _stateMessage = 'تعذّر تحميل التقرير من مصدره المالي.';
      });
    } catch (_) {
      if (!mounted || epoch != _loadEpoch) return;
      setState(() {
        _viewState = _ReportViewState.offline;
        _stateMessage = 'تعذّر الاتصال بالخادم. حاول مجدداً.';
      });
    }
  }

  Future<void> _downloadStatement() async {
    setState(() => _downloading = true);
    try {
      final controller = Get.find<TransactionHistoryController>();
      final pdf = await controller.downloadTransactionHistory(
        transactionType: 'all',
        startDate: _startDate,
        endDate: _startDate != null ? DateTime.now() : null,
      );
      if (pdf != null) {
        await PdfDownloaderHelper.downloadAndOpenPdf(
          pdfData: pdf,
          baseFileName: 'Amial_Statement',
        );
        return;
      }
      if (mounted) {
        _snack(
          controller.downloadError.isNotEmpty
              ? controller.downloadError
              : 'تعذّر تنزيل كشف الحساب.',
        );
      }
    } catch (_) {
      if (mounted) _snack('تعذّر تنزيل كشف الحساب.');
    } finally {
      if (mounted) setState(() => _downloading = false);
    }
  }

  void _snack(String message) {
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(content: Text(message), backgroundColor: AmialColors.danger),
    );
  }

  String _money(dynamic value) => AmialMoney.fmt(value, maxFractionDigits: 4);

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmialColors.background,
      body: SafeArea(
        child: Column(
          children: [
            AmialScreenHeader(
              title: 'التقارير'.tr,
              actions: [
                AmialHeaderAction(
                  icon: Icons.description_outlined,
                  onTap: () => Get.to(
                    () => const AmialAccountStatementScreen(),
                  ),
                ),
              ],
            ),
            Expanded(
              child: RefreshIndicator(
                color: AmialColors.primary,
                onRefresh: _load,
                child: ListView(
                  physics: const AlwaysScrollableScrollPhysics(),
                  padding: const EdgeInsets.fromLTRB(
                    AmialSpacing.screen,
                    AmialSpacing.xs,
                    AmialSpacing.screen,
                    AmialSpacing.xxl,
                  ),
                  children: [
                    _truthBanner(context),
                    const SizedBox(height: AmialSpacing.md),
                    _reportTabs(context),
                    const SizedBox(height: AmialSpacing.lg),
                    _periodSelector(context),
                    const SizedBox(height: AmialSpacing.lg),
                    _currencySelector(context),
                    const SizedBox(height: AmialSpacing.lg),
                    _bodyForState(context),
                  ],
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _truthBanner(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(AmialSpacing.md),
      decoration: BoxDecoration(
        color: AmialColors.primary.withValues(alpha: 0.08),
        borderRadius: BorderRadius.circular(AmialSpacing.radiusLg),
        border: Border.all(color: AmialColors.primary.withValues(alpha: 0.18)),
      ),
      child: const Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(Icons.verified_outlined, color: AmialColors.primary),
          SizedBox(width: AmialSpacing.sm),
          Expanded(
            child: Text(
              'الأرقام من الدفتر الكامل على الخادم، وليست من صفحة محدودة من العمليات.',
              style: TextStyle(fontWeight: FontWeight.w700),
            ),
          ),
        ],
      ),
    );
  }

  Widget _reportTabs(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(AmialSpacing.xxs),
      decoration: BoxDecoration(
        color: AmialColors.cardSurface,
        borderRadius: BorderRadius.circular(AmialSpacing.radiusLg),
        border: Border.all(color: AmialColors.border),
        boxShadow: AmialSpacing.cardShadow,
      ),
      child: Row(
        children: [
          _reportTab(context, 'المصروفات'.tr, Icons.south_east_rounded,
              _ReportType.expenses),
          _reportTab(context, 'الإيرادات', Icons.north_east_rounded,
              _ReportType.income),
          _reportTab(context, 'كشف الحساب', Icons.account_balance_outlined,
              _ReportType.statement),
        ],
      ),
    );
  }

  Widget _reportTab(
    BuildContext context,
    String label,
    IconData icon,
    _ReportType type,
  ) {
    final selected = _type == type;
    return Expanded(
      child: InkWell(
        onTap: () => setState(() => _type = type),
        borderRadius: BorderRadius.circular(AmialSpacing.radiusMd),
        child: AnimatedContainer(
          duration: const Duration(milliseconds: 180),
          padding: const EdgeInsets.symmetric(
            horizontal: AmialSpacing.xs,
            vertical: AmialSpacing.sm,
          ),
          decoration: BoxDecoration(
            color: selected ? AmialColors.primary : AmialColors.cardSurface,
            borderRadius: BorderRadius.circular(AmialSpacing.radiusMd),
          ),
          child: Row(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              Icon(
                icon,
                size: AmialSpacing.lg,
                color: selected ? AmialColors.cardSurface : AmialColors.primary,
              ),
              const SizedBox(width: AmialSpacing.xs),
              Flexible(
                child: Text(
                  label,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                        fontWeight: FontWeight.w800,
                        color: selected
                            ? AmialColors.cardSurface
                            : AmialColors.primary,
                      ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _periodSelector(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Row(
          children: [
            const Icon(Icons.calendar_month_outlined,
                color: AmialColors.primary),
            const SizedBox(width: AmialSpacing.xs),
            Text(
              'الفترة',
              style: Theme.of(context).textTheme.titleMedium?.copyWith(
                    fontWeight: FontWeight.w800,
                  ),
            ),
            const Spacer(),
            Text(
              _periodLabel,
              style: Theme.of(context).textTheme.bodySmall?.copyWith(
                    color: AmialColors.textSecondary,
                  ),
            ),
          ],
        ),
        const SizedBox(height: AmialSpacing.sm),
        Wrap(
          spacing: AmialSpacing.xs,
          runSpacing: AmialSpacing.xs,
          children: [
            _periodChip('هذا الشهر', _Period.month),
            _periodChip('30 يوماً', _Period.days30),
            _periodChip('90 يوماً', _Period.days90),
            _periodChip('الكل'.tr, _Period.all),
          ],
        ),
      ],
    );
  }

  Widget _periodChip(String label, _Period period) {
    final selected = _period == period;
    return ChoiceChip(
      label: Text(label),
      selected: selected,
      onSelected: (_) {
        if (selected) return;
        setState(() => _period = period);
        _load();
      },
      selectedColor: AmialColors.primary.withValues(alpha: 0.12),
      side: BorderSide(
        color: selected ? AmialColors.primary : AmialColors.border,
      ),
      labelStyle: TextStyle(
        color: selected ? AmialColors.primary : AmialColors.textPrimary,
        fontWeight: FontWeight.w700,
      ),
    );
  }

  Widget _currencySelector(BuildContext context) {
    if (_currencies.length <= 1) return const SizedBox.shrink();
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Text(
          'العملة',
          style: Theme.of(context).textTheme.titleMedium?.copyWith(
                fontWeight: FontWeight.w800,
              ),
        ),
        const SizedBox(height: AmialSpacing.sm),
        Wrap(
          spacing: AmialSpacing.xs,
          runSpacing: AmialSpacing.xs,
          children: List.generate(_currencies.length, (index) {
            final code = (_currencies[index]['currency'] ?? '').toString();
            return ChoiceChip(
              label: Text(code),
              selected: index == _currencyIndex,
              onSelected: (_) => setState(() => _currencyIndex = index),
              selectedColor: AmialColors.emoney.withValues(alpha: 0.14),
              side: BorderSide(
                color: index == _currencyIndex
                    ? AmialColors.emoney
                    : AmialColors.border,
              ),
            );
          }),
        ),
        const SizedBox(height: AmialSpacing.xs),
        Text(
          'لا يتم جمع العملات أو تحويلها تلقائياً.',
          style: Theme.of(context).textTheme.bodySmall?.copyWith(
                color: AmialColors.textSecondary,
              ),
        ),
      ],
    );
  }

  Widget _bodyForState(BuildContext context) {
    switch (_viewState) {
      case _ReportViewState.loading:
        return const Padding(
          padding: EdgeInsets.all(AmialSpacing.xxl),
          child: Center(child: CircularProgressIndicator()),
        );
      case _ReportViewState.empty:
        return _stateCard(
          context,
          Icons.receipt_long_outlined,
          'لا توجد حركة دفترية في هذه الفترة.',
          AmialColors.textSecondary,
        );
      case _ReportViewState.permissionDenied:
      case _ReportViewState.error:
      case _ReportViewState.offline:
      case _ReportViewState.maintenance:
        return _stateCard(
          context,
          Icons.error_outline_rounded,
          _stateMessage,
          AmialColors.danger,
          retry: true,
        );
      case _ReportViewState.ready:
        final row = _selectedCurrency;
        if (row == null) return const SizedBox.shrink();
        return _reportBody(context, row);
    }
  }

  Widget _stateCard(
    BuildContext context,
    IconData icon,
    String message,
    Color color, {
    bool retry = false,
  }) {
    return Container(
      padding: const EdgeInsets.all(AmialSpacing.xl),
      decoration: BoxDecoration(
        color: AmialColors.cardSurface,
        borderRadius: BorderRadius.circular(AmialSpacing.radiusLg),
        border: Border.all(color: AmialColors.border),
      ),
      child: Column(
        children: [
          Icon(icon, color: color, size: 38),
          const SizedBox(height: AmialSpacing.sm),
          Text(message, textAlign: TextAlign.center),
          if (retry) ...[
            const SizedBox(height: AmialSpacing.md),
            OutlinedButton.icon(
              onPressed: _load,
              icon: const Icon(Icons.refresh_rounded),
              label: const Text('إعادة المحاولة'),
            ),
          ],
        ],
      ),
    );
  }

  Widget _reportBody(BuildContext context, Map<String, dynamic> row) {
    switch (_type) {
      case _ReportType.expenses:
        return _flowView(context, row, inflow: false);
      case _ReportType.income:
        return _flowView(context, row, inflow: true);
      case _ReportType.statement:
        return _statementView(context, row);
    }
  }

  Widget _flowView(
    BuildContext context,
    Map<String, dynamic> row, {
    required bool inflow,
  }) {
    final amountKey = inflow ? 'inflows' : 'outflows';
    final countKey = inflow ? 'inflow_count' : 'outflow_count';
    final amount = row[amountKey] ?? '0';
    final currency = (row['currency'] ?? '').toString();
    final breakdown = _breakdown(row, amountKey);

    return Column(
      children: [
        _heroAmount(
          context,
          title: inflow ? 'إجمالي الإيرادات' : 'إجمالي المصروفات',
          amount: amount,
          currency: currency,
          count: row[countKey] ?? 0,
          positive: inflow,
        ),
        const SizedBox(height: AmialSpacing.lg),
        _breakdownCard(context, breakdown, amountKey, currency),
        const SizedBox(height: AmialSpacing.lg),
        _integrityFooter(context, row),
      ],
    );
  }

  Widget _heroAmount(
    BuildContext context, {
    required String title,
    required dynamic amount,
    required String currency,
    required dynamic count,
    required bool positive,
  }) {
    final color = positive ? AmialColors.success : AmialColors.danger;
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(AmialSpacing.xl),
      decoration: BoxDecoration(
        color: AmialColors.cardSurface,
        borderRadius: BorderRadius.circular(AmialSpacing.radiusLg),
        border: Border.all(color: color.withValues(alpha: 0.22)),
        boxShadow: AmialSpacing.cardShadow,
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(title,
              style: Theme.of(context).textTheme.titleMedium?.copyWith(
                    color: AmialColors.textSecondary,
                    fontWeight: FontWeight.w700,
                  )),
          const SizedBox(height: AmialSpacing.sm),
          Row(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              Expanded(
                child: Text(
                  _money(amount),
                  textDirection: TextDirection.ltr,
                  style: Theme.of(context).textTheme.headlineMedium?.copyWith(
                        color: color,
                        fontWeight: FontWeight.w900,
                      ),
                ),
              ),
              Text(currency,
                  style: Theme.of(context).textTheme.titleMedium?.copyWith(
                        fontWeight: FontWeight.w800,
                      )),
            ],
          ),
          const SizedBox(height: AmialSpacing.xs),
          Text(
            '$count حركة · $_periodLabel',
            style: Theme.of(context).textTheme.bodySmall?.copyWith(
                  color: AmialColors.textSecondary,
                ),
          ),
        ],
      ),
    );
  }

  List<Map<String, dynamic>> _breakdown(
    Map<String, dynamic> row,
    String amountKey,
  ) {
    final raw = row['breakdown'];
    if (raw is! List) return const [];
    final list = raw
        .whereType<Map>()
        .map((e) => Map<String, dynamic>.from(e))
        .where((e) => _decimalCompareValue(e[amountKey]) > BigInt.zero)
        .toList();
    list.sort((a, b) => _decimalCompareValue(b[amountKey])
        .compareTo(_decimalCompareValue(a[amountKey])));
    return list;
  }

  BigInt _decimalCompareValue(dynamic value) {
    var raw = (value ?? '0').toString().trim();
    final negative = raw.startsWith('-');
    if (negative) raw = raw.substring(1);
    final parts = raw.split('.');
    final whole = BigInt.tryParse(parts.first) ?? BigInt.zero;
    final fraction = parts.length > 1
        ? (parts[1] + '0000').substring(0, 4)
        : '0000';
    final scaled = whole * BigInt.from(10000) +
        (BigInt.tryParse(fraction) ?? BigInt.zero);
    return negative ? -scaled : scaled;
  }

  Widget _breakdownCard(
    BuildContext context,
    List<Map<String, dynamic>> rows,
    String amountKey,
    String currency,
  ) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(AmialSpacing.lg),
      decoration: BoxDecoration(
        color: AmialColors.cardSurface,
        borderRadius: BorderRadius.circular(AmialSpacing.radiusLg),
        border: Border.all(color: AmialColors.border),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            'التوزيع حسب المصدر',
            style: Theme.of(context).textTheme.titleMedium?.copyWith(
                  fontWeight: FontWeight.w800,
                ),
          ),
          const SizedBox(height: AmialSpacing.md),
          if (rows.isEmpty)
            Text(
              'لا توجد حركة ضمن هذا الاتجاه.',
              style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                    color: AmialColors.textSecondary,
                  ),
            )
          else
            ...rows.map((item) {
              final source = (item['source_type'] ?? '').toString();
              final label = _sourceLabels[source] ?? source.replaceAll('_', ' ');
              return Padding(
                padding: const EdgeInsets.only(bottom: AmialSpacing.sm),
                child: Row(
                  children: [
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(label,
                              style:
                                  const TextStyle(fontWeight: FontWeight.w700)),
                          Text(
                            '${item['entries'] ?? 0} حركة',
                            style: Theme.of(context).textTheme.bodySmall?.copyWith(
                                  color: AmialColors.textSecondary,
                                ),
                          ),
                        ],
                      ),
                    ),
                    Text(
                      '${_money(item[amountKey])} $currency',
                      textDirection: TextDirection.ltr,
                      style: const TextStyle(fontWeight: FontWeight.w800),
                    ),
                  ],
                ),
              );
            }),
        ],
      ),
    );
  }

  Widget _statementView(BuildContext context, Map<String, dynamic> row) {
    final currency = (row['currency'] ?? '').toString();
    final reconciled = row['reconciled'] == true;
    final operationalReconciled = row['operational_reconciled'];

    return Column(
      children: [
        Container(
          width: double.infinity,
          padding: const EdgeInsets.all(AmialSpacing.lg),
          decoration: BoxDecoration(
            color: AmialColors.cardSurface,
            borderRadius: BorderRadius.circular(AmialSpacing.radiusLg),
            border: Border.all(color: AmialColors.border),
            boxShadow: AmialSpacing.cardShadow,
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  Expanded(
                    child: Text(
                      'ملخص كشف الحساب',
                      style: Theme.of(context).textTheme.titleLarge?.copyWith(
                            fontWeight: FontWeight.w900,
                          ),
                    ),
                  ),
                  _statusPill(reconciled, 'متطابق', 'فرق'),
                ],
              ),
              const SizedBox(height: AmialSpacing.lg),
              _statementLine('الرصيد الافتتاحي', row['opening_balance'], currency),
              _statementLine('تدفقات داخلة', row['inflows'], currency,
                  color: AmialColors.success),
              _statementLine('تدفقات خارجة', row['outflows'], currency,
                  color: AmialColors.danger),
              _statementLine('صافي التسويات', row['net_adjustments'], currency,
                  color: AmialColors.warning),
              const Divider(height: AmialSpacing.xl),
              _statementLine('الرصيد الختامي', row['closing_balance'], currency,
                  strong: true),
              _statementLine('فرق التحقق', row['closing_difference'], currency),
              if (row['operational_balance'] != null) ...[
                const Divider(height: AmialSpacing.xl),
                _statementLine(
                    'رصيد المحفظة الحالي', row['operational_balance'], currency),
                _statementLine(
                    'فرق المحفظة/الدفتر', row['ledger_operational_gap'], currency),
                const SizedBox(height: AmialSpacing.xs),
                _statusPill(
                  operationalReconciled == true,
                  'المحفظة مطابقة للدفتر',
                  'توجد فجوة مصالحة',
                ),
              ],
            ],
          ),
        ),
        const SizedBox(height: AmialSpacing.lg),
        SizedBox(
          width: double.infinity,
          child: FilledButton.icon(
            onPressed: _downloading ? null : _downloadStatement,
            icon: _downloading
                ? const SizedBox(
                    width: 18,
                    height: 18,
                    child: CircularProgressIndicator(strokeWidth: 2),
                  )
                : const Icon(Icons.picture_as_pdf_outlined),
            label: const Text('تنزيل كشف الحساب PDF'),
          ),
        ),
        const SizedBox(height: AmialSpacing.lg),
        _integrityFooter(context, row),
      ],
    );
  }

  Widget _statementLine(
    String label,
    dynamic value,
    String currency, {
    Color? color,
    bool strong = false,
  }) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: AmialSpacing.xs),
      child: Row(
        children: [
          Expanded(
            child: Text(
              label,
              style: TextStyle(
                color: AmialColors.textSecondary,
                fontWeight: strong ? FontWeight.w800 : FontWeight.w600,
              ),
            ),
          ),
          Text(
            '${_money(value)} $currency',
            textDirection: TextDirection.ltr,
            style: TextStyle(
              color: color ?? AmialColors.textPrimary,
              fontWeight: strong ? FontWeight.w900 : FontWeight.w800,
            ),
          ),
        ],
      ),
    );
  }

  Widget _statusPill(bool ok, String okLabel, String badLabel) {
    final color = ok ? AmialColors.success : AmialColors.danger;
    return Container(
      padding: const EdgeInsets.symmetric(
        horizontal: AmialSpacing.sm,
        vertical: AmialSpacing.xxs,
      ),
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.10),
        borderRadius: BorderRadius.circular(999),
        border: Border.all(color: color.withValues(alpha: 0.20)),
      ),
      child: Text(
        ok ? okLabel : badLabel,
        style: TextStyle(color: color, fontWeight: FontWeight.w800),
      ),
    );
  }

  Widget _integrityFooter(BuildContext context, Map<String, dynamic> row) {
    final updated = _lastUpdatedAt;
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(AmialSpacing.md),
      decoration: BoxDecoration(
        color: AmialColors.cardSurface,
        borderRadius: BorderRadius.circular(AmialSpacing.radiusLg),
        border: Border.all(color: AmialColors.border),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Icon(Icons.shield_outlined,
              color: AmialColors.primary, size: 20),
          const SizedBox(width: AmialSpacing.sm),
          Expanded(
            child: Text(
              'مصدر التقرير: الدفتر الكامل · ${row['currency'] ?? ''}'
              '${updated == null ? '' : ' · تم التحديث الآن'}',
              style: Theme.of(context).textTheme.bodySmall?.copyWith(
                    color: AmialColors.textSecondary,
                  ),
            ),
          ),
        ],
      ),
    );
  }
}
