import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amial_pay/common/widgets/amial_donut_chart.dart';
import 'package:amial_pay/common/widgets/amial_form.dart';
import 'package:amial_pay/data/api/api_client.dart';
import 'package:amial_pay/features/history/controllers/transaction_history_controller.dart';
import 'package:amial_pay/features/history/domain/models/transaction_model.dart';
import 'package:amial_pay/features/reports/screens/amial_account_statement_screen.dart';
import 'package:amial_pay/helper/amial_money.dart';
import 'package:amial_pay/helper/date_converter_helper.dart';
import 'package:amial_pay/helper/pdf_downloader_helper.dart';
import 'package:amial_pay/theme/amial_colors.dart';
import 'package:amial_pay/theme/amial_spacing.dart';
import 'package:amial_pay/util/app_constants.dart';

/// AMIAL-REPORTS-002 — تقرير العميل الاحترافي.
///
/// نفس سجل العمليات يغذي الملخص والتوزيع وكشف الحساب. وإذا تعذرت القراءة
/// لا نعرض صفراً؛ نعرض حالة عدم المعرفة صراحةً.
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
  _Period _period = _Period.days30;
  _ReportType _type = _ReportType.expenses;
  _ReportViewState _viewState = _ReportViewState.loading;

  bool _downloading = false;
  String _stateMessage = '';
  List<Transactions> _txs = [];
  DateTime? _lastUpdatedAt;
  int _loadEpoch = 0;

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

  static const List<Color> _analyticsPalette = [
    AmialColors.primary,
    AmialColors.success,
    AmialColors.warning,
    AmialColors.info,
    AmialColors.cash,
    AmialColors.emoney,
  ];

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
        'offset': '1',
        'limit': '500',
        'transaction_type': 'all',
        if (_startDate != null)
          'start_date': DateConverterHelper.formatDate(_startDate!),
        if (_startDate != null)
          'end_date': DateConverterHelper.formatDate(DateTime.now()),
      };
      final qs = Uri(queryParameters: params).query;
      final response =
          await api.getData('${AppConstants.customerTransactionHistory}?$qs');

      if (!mounted || epoch != _loadEpoch) return;

      if (response.statusCode == 200 && response.body is Map) {
        final model = TransactionModel.fromJson(
          Map<String, dynamic>.from(response.body as Map),
        );
        final transactions = model.transactions ?? [];
        setState(() {
          _txs = transactions;
          _lastUpdatedAt = DateTime.now();
          _viewState = transactions.isEmpty
              ? _ReportViewState.empty
              : _ReportViewState.ready;
        });
        return;
      }

      if (response.statusCode == 403) {
        setState(() {
          _viewState = _ReportViewState.permissionDenied;
          _stateMessage = 'ليس لديك صلاحية لعرض تقارير هذا الحساب.';
        });
        return;
      }

      if (response.statusCode == 503) {
        setState(() {
          _viewState = _ReportViewState.maintenance;
          _stateMessage =
              'خدمة التقارير تحت الصيانة حالياً. بياناتك لم تُستبدل بأصفار.';
        });
        return;
      }

      if (response.statusCode == 1) {
        setState(() {
          _viewState = _ReportViewState.offline;
          _stateMessage =
              'تعذّر الاتصال بالخادم. تحقق من الشبكة ثم أعد المحاولة.';
        });
        return;
      }

      if (response.statusCode == -1) {
        setState(() {
          _viewState = _ReportViewState.error;
          _stateMessage =
              'تعذّر تحميل التقارير أثناء اتصال VPN. أوقفه ثم أعد المحاولة.';
        });
        return;
      }

      if (response.statusCode == 401) {
        setState(() {
          _viewState = _ReportViewState.error;
          _stateMessage =
              'انتهت جلسة الدخول أو لم تعد صالحة. أعد تسجيل الدخول ثم جرّب مجدداً.';
        });
        return;
      }

      setState(() {
        _viewState = _ReportViewState.error;
        _stateMessage = 'تعذّر تحميل بيانات التقارير من الخادم.';
      });
    } catch (_) {
      if (!mounted || epoch != _loadEpoch) return;
      setState(() {
        _viewState = _ReportViewState.offline;
        _stateMessage =
            'تعذّر الاتصال بالخادم. تحقق من الشبكة ثم أعد المحاولة.';
      });
    }
  }

  Future<void> _downloadStatement() async {
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
          pdfData: pdf,
          baseFileName: 'Amial_Statement',
        );
        return;
      }

      if (mounted) {
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
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(content: Text(msg), backgroundColor: AmialColors.danger),
    );
  }

  double get _totalDebit => _txs.fold(0.0, (s, t) => s + (t.debit ?? 0));
  double get _totalCredit => _txs.fold(0.0, (s, t) => s + (t.credit ?? 0));
  double get _net => _totalCredit - _totalDebit;

  int _directionCount({required bool debit}) => _txs.where((t) {
        final value = debit ? (t.debit ?? 0) : (t.credit ?? 0);
        return value > 0;
      }).length;

  List<MapEntry<String, double>> _breakdown({required bool debit}) {
    final map = <String, double>{};
    for (final t in _txs) {
      final value = debit ? (t.debit ?? 0) : (t.credit ?? 0);
      if (value <= 0) continue;
      final rawType = t.transactionType?.trim() ?? '';
      final key = _typeLabels[rawType] ?? (rawType.isNotEmpty ? rawType : 'أخرى');
      map[key] = (map[key] ?? 0) + value;
    }
    final entries = map.entries.toList()
      ..sort((a, b) => b.value.compareTo(a.value));
    return entries;
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmialColors.background,
      body: SafeArea(
        child: Column(
          children: [
            AmialScreenHeader(
              title: 'التقارير',
              actions: [
                AmialHeaderAction(
                  icon: Icons.description_outlined,
                  onTap: () => Get.to(() => const AmialAccountStatementScreen()),
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
                    AmialSpacing.xxs,
                    AmialSpacing.screen,
                    AmialSpacing.xxl,
                  ),
                  children: [
                    _reportTabs(context),
                    const SizedBox(height: AmialSpacing.lg),
                    _periodSelector(context),
                    const SizedBox(height: AmialSpacing.xl),
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
          _reportTab(
            context,
            label: 'المصروفات',
            icon: Icons.account_balance_wallet_outlined,
            type: _ReportType.expenses,
          ),
          _reportTab(
            context,
            label: 'الإيرادات',
            icon: Icons.query_stats_rounded,
            type: _ReportType.income,
          ),
          _reportTab(
            context,
            label: 'كشف الحساب',
            icon: Icons.description_outlined,
            type: _ReportType.statement,
          ),
        ],
      ),
    );
  }

  Widget _reportTab(
    BuildContext context, {
    required String label,
    required IconData icon,
    required _ReportType type,
  }) {
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
                        fontWeight:
                            selected ? FontWeight.w800 : FontWeight.w700,
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
            const Icon(
              Icons.calendar_month_outlined,
              color: AmialColors.primary,
              size: AmialSpacing.xl,
            ),
            const SizedBox(width: AmialSpacing.xs),
            Text(
              'الفترة',
              style: Theme.of(context).textTheme.titleMedium?.copyWith(
                    fontWeight: FontWeight.w800,
                    color: AmialColors.textPrimary,
                  ),
            ),
            const Spacer(),
            Text(
              _periodLabel,
              style: Theme.of(context).textTheme.bodySmall?.copyWith(
                    color: AmialColors.textSecondary,
                    fontWeight: FontWeight.w600,
                  ),
            ),
          ],
        ),
        const SizedBox(height: AmialSpacing.sm),
        SingleChildScrollView(
          scrollDirection: Axis.horizontal,
          child: Row(
            children: [
              _periodChip(context, 'هذا الشهر', _Period.month,
                  Icons.calendar_today_outlined),
              const SizedBox(width: AmialSpacing.xs),
              _periodChip(context, 'آخر 30 يوماً', _Period.days30,
                  Icons.check_circle_outline),
              const SizedBox(width: AmialSpacing.xs),
              _periodChip(context, 'آخر 90 يوماً', _Period.days90,
                  Icons.event_note_outlined),
              const SizedBox(width: AmialSpacing.xs),
              _periodChip(context, 'الكل', _Period.all, Icons.tune_rounded),
            ],
          ),
        ),
      ],
    );
  }

  Widget _periodChip(
    BuildContext context,
    String label,
    _Period period,
    IconData icon,
  ) {
    final selected = _period == period;
    return InkWell(
      onTap: () {
        if (_period == period) return;
        setState(() => _period = period);
        _load();
      },
      borderRadius: BorderRadius.circular(AmialSpacing.radiusMd),
      child: AnimatedContainer(
        duration: const Duration(milliseconds: 180),
        padding: const EdgeInsets.symmetric(
          horizontal: AmialSpacing.md,
          vertical: AmialSpacing.sm,
        ),
        decoration: BoxDecoration(
          color: selected ? AmialColors.primary : AmialColors.cardSurface,
          borderRadius: BorderRadius.circular(AmialSpacing.radiusMd),
          border: Border.all(
            color: selected ? AmialColors.primary : AmialColors.border,
          ),
          boxShadow: selected ? AmialSpacing.cardShadow : null,
        ),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(
              icon,
              size: AmialSpacing.lg,
              color: selected ? AmialColors.cardSurface : AmialColors.primary,
            ),
            const SizedBox(width: AmialSpacing.xs),
            Text(
              label,
              style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                    color: selected
                        ? AmialColors.cardSurface
                        : AmialColors.textPrimary,
                    fontWeight: FontWeight.w700,
                  ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _bodyForState(BuildContext context) {
    switch (_viewState) {
      case _ReportViewState.loading:
        return _loadingState(context);
      case _ReportViewState.empty:
        return _statusCard(
          context,
          icon: Icons.receipt_long_outlined,
          title: 'لا توجد عمليات في هذه الفترة',
          message: 'لم تُسجّل عمليات يمكن تكوين التقرير منها. جرّب فترة أوسع.',
          surface: AmialColors.cardSurface,
          accent: AmialColors.info,
          actionLabel: 'عرض كل الفترات',
          onAction: () {
            setState(() => _period = _Period.all);
            _load();
          },
        );
      case _ReportViewState.permissionDenied:
        return _statusCard(
          context,
          icon: Icons.lock_outline_rounded,
          title: 'وصول غير مسموح',
          message: _stateMessage,
          surface: AmialColors.dangerSurface,
          accent: AmialColors.danger,
        );
      case _ReportViewState.offline:
        return _statusCard(
          context,
          icon: Icons.cloud_off_outlined,
          title: 'لا يوجد اتصال',
          message: _stateMessage,
          surface: AmialColors.warningSurface,
          accent: AmialColors.warning,
          actionLabel: 'إعادة المحاولة',
          onAction: _load,
        );
      case _ReportViewState.maintenance:
        return _statusCard(
          context,
          icon: Icons.construction_outlined,
          title: 'الخدمة تحت الصيانة',
          message: _stateMessage,
          surface: AmialColors.warningSurface,
          accent: AmialColors.warning,
          actionLabel: 'تحقق مجدداً',
          onAction: _load,
        );
      case _ReportViewState.error:
        return _statusCard(
          context,
          icon: Icons.error_outline_rounded,
          title: 'تعذّر تحميل التقرير',
          message: _stateMessage,
          surface: AmialColors.dangerSurface,
          accent: AmialColors.danger,
          actionLabel: 'إعادة المحاولة',
          onAction: _load,
        );
      case _ReportViewState.ready:
        return _readyContent(context);
    }
  }

  Widget _loadingState(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(AmialSpacing.xl),
      decoration: BoxDecoration(
        color: AmialColors.cardSurface,
        borderRadius: BorderRadius.circular(AmialSpacing.radiusLg),
        border: Border.all(color: AmialColors.border),
        boxShadow: AmialSpacing.cardShadow,
      ),
      child: Column(
        children: [
          const CircularProgressIndicator(color: AmialColors.primary),
          const SizedBox(height: AmialSpacing.md),
          Text(
            'جارٍ تجهيز تقريرك…',
            style: Theme.of(context).textTheme.titleSmall?.copyWith(
                  fontWeight: FontWeight.w800,
                  color: AmialColors.textPrimary,
                ),
          ),
          const SizedBox(height: AmialSpacing.xs),
          Text(
            'نقرأ العمليات للفترة المختارة، ولا نعرض أرقاماً قبل اكتمال القراءة.',
            textAlign: TextAlign.center,
            style: Theme.of(context).textTheme.bodySmall?.copyWith(
                  color: AmialColors.textSecondary,
                  height: 1.5,
                ),
          ),
        ],
      ),
    );
  }

  Widget _statusCard(
    BuildContext context, {
    required IconData icon,
    required String title,
    required String message,
    required Color surface,
    required Color accent,
    String? actionLabel,
    VoidCallback? onAction,
  }) {
    return Container(
      padding: const EdgeInsets.all(AmialSpacing.xl),
      decoration: BoxDecoration(
        color: surface,
        borderRadius: BorderRadius.circular(AmialSpacing.radiusLg),
        border: Border.all(color: accent.withValues(alpha: 0.22)),
      ),
      child: Column(
        children: [
          Container(
            padding: const EdgeInsets.all(AmialSpacing.sm),
            decoration: BoxDecoration(
              color: accent.withValues(alpha: 0.10),
              borderRadius: BorderRadius.circular(AmialSpacing.radiusLg),
            ),
            child: Icon(icon, color: accent, size: AmialSpacing.xxl),
          ),
          const SizedBox(height: AmialSpacing.md),
          Text(
            title,
            textAlign: TextAlign.center,
            style: Theme.of(context).textTheme.titleMedium?.copyWith(
                  fontWeight: FontWeight.w800,
                  color: AmialColors.textPrimary,
                ),
          ),
          const SizedBox(height: AmialSpacing.xs),
          Text(
            message,
            textAlign: TextAlign.center,
            style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                  color: AmialColors.textSecondary,
                  height: 1.6,
                ),
          ),
          if (actionLabel != null && onAction != null) ...[
            const SizedBox(height: AmialSpacing.lg),
            FilledButton.icon(
              onPressed: onAction,
              icon: const Icon(Icons.refresh_rounded),
              label: Text(actionLabel),
              style: FilledButton.styleFrom(
                backgroundColor: accent,
                minimumSize: const Size.fromHeight(AmialSpacing.buttonHeight),
              ),
            ),
          ],
        ],
      ),
    );
  }

  Widget _readyContent(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        _summaryCards(context),
        const SizedBox(height: AmialSpacing.lg),
        if (_type == _ReportType.statement)
          _statementSection(context)
        else
          _breakdownSection(
            context,
            debit: _type == _ReportType.expenses,
          ),
        const SizedBox(height: AmialSpacing.md),
        _insightCard(context),
        if (_lastUpdatedAt != null) ...[
          const SizedBox(height: AmialSpacing.sm),
          _lastUpdated(context),
        ],
      ],
    );
  }

  Widget _summaryCards(BuildContext context) {
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Expanded(
          child: _summaryCard(
            context,
            label: 'المصروفات',
            value: _totalDebit,
            count: _directionCount(debit: true),
            color: AmialColors.danger,
            surface: AmialColors.dangerSurface,
            icon: Icons.south_rounded,
          ),
        ),
        const SizedBox(width: AmialSpacing.xs),
        Expanded(
          child: _summaryCard(
            context,
            label: 'الإيرادات',
            value: _totalCredit,
            count: _directionCount(debit: false),
            color: AmialColors.success,
            surface: AmialColors.successSurface,
            icon: Icons.north_rounded,
          ),
        ),
        const SizedBox(width: AmialSpacing.xs),
        Expanded(
          child: _summaryCard(
            context,
            label: 'الصافي',
            value: _net,
            count: _txs.length,
            color: _net < 0 ? AmialColors.danger : AmialColors.primary,
            surface: AmialColors.cardSurface,
            icon: Icons.account_balance_wallet_outlined,
          ),
        ),
      ],
    );
  }

  Widget _summaryCard(
    BuildContext context, {
    required String label,
    required double value,
    required int count,
    required Color color,
    required Color surface,
    required IconData icon,
  }) {
    return Container(
      padding: const EdgeInsets.all(AmialSpacing.sm),
      decoration: BoxDecoration(
        color: surface,
        borderRadius: BorderRadius.circular(AmialSpacing.radiusLg),
        border: Border.all(color: color.withValues(alpha: 0.12)),
        boxShadow: AmialSpacing.cardShadow,
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Container(
            padding: const EdgeInsets.all(AmialSpacing.xs),
            decoration: BoxDecoration(
              color: color.withValues(alpha: 0.10),
              borderRadius: BorderRadius.circular(AmialSpacing.radiusMd),
            ),
            child: Icon(icon, color: color, size: AmialSpacing.lg),
          ),
          const SizedBox(height: AmialSpacing.sm),
          Text(
            label,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: Theme.of(context).textTheme.bodySmall?.copyWith(
                  color: AmialColors.textSecondary,
                  fontWeight: FontWeight.w700,
                ),
          ),
          const SizedBox(height: AmialSpacing.xxs),
          FittedBox(
            fit: BoxFit.scaleDown,
            alignment: AlignmentDirectional.centerStart,
            child: Text(
              AmialMoney.yer(value),
              maxLines: 1,
              style: Theme.of(context).textTheme.titleMedium?.copyWith(
                    color: color,
                    fontWeight: FontWeight.w900,
                  ),
            ),
          ),
          const SizedBox(height: AmialSpacing.xs),
          Text(
            '$count عملية',
            style: Theme.of(context).textTheme.labelSmall?.copyWith(
                  color: AmialColors.textMuted,
                  fontWeight: FontWeight.w600,
                ),
          ),
        ],
      ),
    );
  }

  Widget _breakdownSection(BuildContext context, {required bool debit}) {
    final entries = _breakdown(debit: debit);
    final total = debit ? _totalDebit : _totalCredit;

    if (entries.isEmpty) {
      return _statusCard(
        context,
        icon: Icons.pie_chart_outline_rounded,
        title: debit ? 'لا توجد مصروفات' : 'لا توجد إيرادات',
        message: 'هناك عمليات في الفترة، لكن لا توجد حركة من هذا النوع.',
        surface: AmialColors.cardSurface,
        accent: AmialColors.info,
      );
    }

    return Container(
      padding: const EdgeInsets.all(AmialSpacing.md),
      decoration: BoxDecoration(
        color: AmialColors.cardSurface,
        borderRadius: BorderRadius.circular(AmialSpacing.radiusLg),
        border: Border.all(color: AmialColors.border),
        boxShadow: AmialSpacing.cardShadow,
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              Container(
                padding: const EdgeInsets.all(AmialSpacing.xs),
                decoration: BoxDecoration(
                  color: AmialColors.primary.withValues(alpha: 0.10),
                  borderRadius: BorderRadius.circular(AmialSpacing.radiusMd),
                ),
                child: const Icon(
                  Icons.pie_chart_outline_rounded,
                  color: AmialColors.primary,
                  size: AmialSpacing.xl,
                ),
              ),
              const SizedBox(width: AmialSpacing.sm),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      debit
                          ? 'تفصيل المصروفات حسب النوع'
                          : 'تفصيل الإيرادات حسب النوع',
                      style: Theme.of(context).textTheme.titleMedium?.copyWith(
                            fontWeight: FontWeight.w900,
                            color: AmialColors.textPrimary,
                          ),
                    ),
                    const SizedBox(height: AmialSpacing.xxs),
                    Text(
                      'التوزيع مبني على العمليات المقروءة في $_periodLabel.',
                      style: Theme.of(context).textTheme.bodySmall?.copyWith(
                            color: AmialColors.textSecondary,
                          ),
                    ),
                  ],
                ),
              ),
            ],
          ),
          const SizedBox(height: AmialSpacing.md),
          Center(
            child: AmialDonutChart(
              slices: entries,
              centerLabel:
                  debit ? 'إجمالي المصروفات' : 'إجمالي الإيرادات',
              centerValue: AmialMoney.yer(total),
              size: 220,
            ),
          ),
          const SizedBox(height: AmialSpacing.sm),
          ...List.generate(entries.length, (index) {
            final entry = entries[index];
            final ratio =
                total > 0 ? (entry.value / total).clamp(0.0, 1.0) : 0.0;
            final color = _analyticsPalette[index % _analyticsPalette.length];
            return _breakdownRow(
              context,
              entry: entry,
              ratio: ratio,
              color: color,
            );
          }),
        ],
      ),
    );
  }

  Widget _breakdownRow(
    BuildContext context, {
    required MapEntry<String, double> entry,
    required double ratio,
    required Color color,
  }) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: AmialSpacing.xs),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Container(
            padding: const EdgeInsets.all(AmialSpacing.xs),
            decoration: BoxDecoration(
              color: color.withValues(alpha: 0.10),
              borderRadius: BorderRadius.circular(AmialSpacing.radiusMd),
            ),
            child: Icon(
              _categoryIcon(entry.key),
              color: color,
              size: AmialSpacing.lg,
            ),
          ),
          const SizedBox(width: AmialSpacing.sm),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Row(
                  children: [
                    Expanded(
                      child: Text(
                        entry.key,
                        style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                              fontWeight: FontWeight.w800,
                              color: AmialColors.textPrimary,
                            ),
                      ),
                    ),
                    const SizedBox(width: AmialSpacing.xs),
                    Text(
                      '${(ratio * 100).toStringAsFixed(0)}%',
                      style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                            color: color,
                            fontWeight: FontWeight.w900,
                          ),
                    ),
                  ],
                ),
                const SizedBox(height: AmialSpacing.xxs),
                Text(
                  AmialMoney.yer(entry.value),
                  style: Theme.of(context).textTheme.bodySmall?.copyWith(
                        color: AmialColors.textSecondary,
                        fontWeight: FontWeight.w700,
                      ),
                ),
                const SizedBox(height: AmialSpacing.xs),
                ClipRRect(
                  borderRadius: BorderRadius.circular(AmialSpacing.radiusSm),
                  child: LinearProgressIndicator(
                    value: ratio,
                    minHeight: AmialSpacing.xs,
                    backgroundColor: AmialColors.border,
                    color: color,
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  IconData _categoryIcon(String label) {
    if (label.contains('تحويل')) return Icons.send_rounded;
    if (label.contains('سحب')) return Icons.storefront_outlined;
    if (label.contains('إيداع')) return Icons.savings_outlined;
    if (label.contains('شحن')) return Icons.add_card_outlined;
    if (label.contains('دفع') || label.contains('مدفوعات')) {
      return Icons.payments_outlined;
    }
    if (label.contains('رسوم')) return Icons.percent_rounded;
    if (label.contains('مكافآت')) return Icons.card_giftcard_outlined;
    return Icons.receipt_long_outlined;
  }

  Widget _statementSection(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(AmialSpacing.lg),
      decoration: BoxDecoration(
        color: AmialColors.cardSurface,
        borderRadius: BorderRadius.circular(AmialSpacing.radiusLg),
        border: Border.all(color: AmialColors.border),
        boxShadow: AmialSpacing.cardShadow,
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Container(
            padding: const EdgeInsets.all(AmialSpacing.md),
            decoration: BoxDecoration(
              color: AmialColors.primary.withValues(alpha: 0.08),
              borderRadius: BorderRadius.circular(AmialSpacing.radiusMd),
            ),
            child: Row(
              children: [
                Container(
                  padding: const EdgeInsets.all(AmialSpacing.xs),
                  decoration: BoxDecoration(
                    color: AmialColors.primary.withValues(alpha: 0.12),
                    borderRadius: BorderRadius.circular(AmialSpacing.radiusMd),
                  ),
                  child: const Icon(
                    Icons.description_outlined,
                    color: AmialColors.primary,
                    size: AmialSpacing.xl,
                  ),
                ),
                const SizedBox(width: AmialSpacing.sm),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        'كشف الحساب',
                        style: Theme.of(context).textTheme.titleMedium?.copyWith(
                              fontWeight: FontWeight.w900,
                              color: AmialColors.textPrimary,
                            ),
                      ),
                      const SizedBox(height: AmialSpacing.xxs),
                      Text(
                        '${_txs.length} عملية ضمن $_periodLabel',
                        style: Theme.of(context).textTheme.bodySmall?.copyWith(
                              color: AmialColors.textSecondary,
                            ),
                      ),
                    ],
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(height: AmialSpacing.md),
          FilledButton.icon(
            onPressed: _downloading ? null : _downloadStatement,
            icon: _downloading
                ? const SizedBox(
                    width: AmialSpacing.lg,
                    height: AmialSpacing.lg,
                    child: CircularProgressIndicator(
                      strokeWidth: 2,
                      color: AmialColors.cardSurface,
                    ),
                  )
                : const Icon(Icons.picture_as_pdf_outlined),
            label: Text(
              _downloading ? 'جارٍ تجهيز الملف…' : 'تنزيل كشف الحساب PDF',
            ),
            style: FilledButton.styleFrom(
              backgroundColor: AmialColors.primary,
              minimumSize: const Size.fromHeight(AmialSpacing.buttonHeight),
              shape: RoundedRectangleBorder(
                borderRadius: BorderRadius.circular(AmialSpacing.radiusMd),
              ),
            ),
          ),
          const SizedBox(height: AmialSpacing.xs),
          OutlinedButton.icon(
            onPressed: () => Get.to(() => const AmialAccountStatementScreen()),
            icon: const Icon(Icons.open_in_new_rounded),
            label: const Text('فتح كشف الحساب التفصيلي'),
            style: OutlinedButton.styleFrom(
              foregroundColor: AmialColors.primary,
              minimumSize: const Size.fromHeight(AmialSpacing.buttonHeight),
              side: const BorderSide(color: AmialColors.border),
              shape: RoundedRectangleBorder(
                borderRadius: BorderRadius.circular(AmialSpacing.radiusMd),
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _insightCard(BuildContext context) {
    final positive = _net >= 0;
    final accent = positive ? AmialColors.success : AmialColors.danger;
    final surface =
        positive ? AmialColors.successSurface : AmialColors.dangerSurface;

    String title;
    String message;
    if (_totalCredit > 0) {
      final ratio = (_totalDebit / _totalCredit) * 100;
      title = positive ? 'صافي الفترة موجب' : 'المصروفات أعلى من الإيرادات';
      message = positive
          ? 'المصروفات تعادل ${ratio.toStringAsFixed(1)}% من الإيرادات، وصافي الفترة ${AmialMoney.yer(_net)}.'
          : 'المصروفات تعادل ${ratio.toStringAsFixed(1)}% من الإيرادات، والفارق ${AmialMoney.yer(_net.abs())}.';
    } else {
      title = 'لا توجد إيرادات في الفترة';
      message =
          'المصروفات المقروءة هي ${AmialMoney.yer(_totalDebit)}، ولا توجد إيرادات مسجلة ضمن $_periodLabel.';
    }

    return Container(
      padding: const EdgeInsets.all(AmialSpacing.md),
      decoration: BoxDecoration(
        color: surface,
        borderRadius: BorderRadius.circular(AmialSpacing.radiusLg),
        border: Border.all(color: accent.withValues(alpha: 0.18)),
      ),
      child: Row(
        children: [
          Container(
            padding: const EdgeInsets.all(AmialSpacing.sm),
            decoration: BoxDecoration(
              color: accent.withValues(alpha: 0.10),
              borderRadius: BorderRadius.circular(AmialSpacing.radiusMd),
            ),
            child: Icon(
              positive
                  ? Icons.trending_up_rounded
                  : Icons.trending_down_rounded,
              color: accent,
              size: AmialSpacing.xl,
            ),
          ),
          const SizedBox(width: AmialSpacing.sm),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  title,
                  style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                        color: AmialColors.textPrimary,
                        fontWeight: FontWeight.w900,
                      ),
                ),
                const SizedBox(height: AmialSpacing.xxs),
                Text(
                  message,
                  style: Theme.of(context).textTheme.bodySmall?.copyWith(
                        color: AmialColors.textSecondary,
                        height: 1.55,
                      ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _lastUpdated(BuildContext context) {
    final time = _lastUpdatedAt!;
    final minute = time.minute.toString().padLeft(2, '0');
    final hour =
        time.hour > 12 ? time.hour - 12 : (time.hour == 0 ? 12 : time.hour);
    final period = time.hour >= 12 ? 'م' : 'ص';

    return Row(
      mainAxisAlignment: MainAxisAlignment.center,
      children: [
        const Icon(
          Icons.schedule_rounded,
          size: AmialSpacing.lg,
          color: AmialColors.textMuted,
        ),
        const SizedBox(width: AmialSpacing.xs),
        Text(
          'آخر تحديث: اليوم، $hour:$minute $period',
          style: Theme.of(context).textTheme.bodySmall?.copyWith(
                color: AmialColors.textMuted,
                fontWeight: FontWeight.w600,
              ),
        ),
      ],
    );
  }
}
