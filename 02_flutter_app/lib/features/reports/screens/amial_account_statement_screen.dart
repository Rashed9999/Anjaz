import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/data/api/api_client.dart';
import 'package:amyal_pay/common/widgets/amial_form.dart';
import 'package:amyal_pay/helper/amial_money.dart';
import 'package:amyal_pay/helper/pdf_downloader_helper.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';
import 'package:http/http.dart' as http;
import 'package:amyal_pay/data/api/secure_storage_helper.dart';
import 'package:amyal_pay/util/app_constants.dart';

/// AMIAL-STATEMENT-001 — كشف حساب المحفظة.
///
/// الفرق عن «الإيصالات»: الإيصال مستند عن عملية واحدة، والكشف **بيان محاسبي**
/// لفترة — كل الحركات بعمودَي مدين ودائن ورصيد جارٍ، مع رصيد افتتاحي وختامي.
/// وهو المستند الذي يُطلب عند إثبات دخل أو تسوية خلاف.
///
/// الجدول يُمرَّر أفقياً: سبعة أعمدة لا تتّسع لعرض هاتف بخطّ مقروء، وضغطها
/// يجعلها غير قابلة للقراءة. التمرير الأفقي أصدق من التصغير.
class AmialAccountStatementScreen extends StatefulWidget {
  const AmialAccountStatementScreen({super.key});

  @override
  State<AmialAccountStatementScreen> createState() =>
      _AmialAccountStatementScreenState();
}

class _AmialAccountStatementScreenState
    extends State<AmialAccountStatementScreen> {
  final _api = Get.find<ApiClient>();

  bool _loading = true;
  String _error = '';
  List<Map<String, dynamic>> _rows = const [];

  String _openingBalance = '0';
  String _closingBalance = '0';
  String _totalDebit = '0';
  String _totalCredit = '0';
  bool _truncated = false;

  late DateTime _from;
  late DateTime _to;

  @override
  void initState() {
    super.initState();
    _to = DateTime.now();
    _from = _to.subtract(const Duration(days: 30));
    _load();
  }

  String _d(DateTime d) =>
      '${d.year}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';

  Future<void> _load() async {
    if (mounted) {
      setState(() {
        _loading = true;
        _error = '';
      });
    }
    try {
      final r = await _api.getData(
          '/api/v1/amial/statement?from=${_d(_from)}&to=${_d(_to)}');
      if (r.statusCode == 200 && r.body is Map) {
        final meta = (r.body as Map)['meta'];
        if (meta is Map) {
          _rows = ((meta['items'] ?? []) as List)
              .whereType<Map>()
              .map((e) => Map<String, dynamic>.from(e))
              .toList();
          _openingBalance = '${meta['opening_balance'] ?? '0'}';
          _closingBalance = '${meta['closing_balance'] ?? '0'}';
          _totalDebit = '${meta['total_debit'] ?? '0'}';
          _totalCredit = '${meta['total_credit'] ?? '0'}';
          _truncated = meta['truncated'] == true;
        }
      } else {
        _error = 'تعذّر جلب الكشف — حاول مجدداً';
      }
    } catch (_) {
      _error = 'تعذّر الاتصال بالخادم';
    }
    if (mounted) setState(() => _loading = false);
  }

  Future<void> _pickRange() async {
    final picked = await showDateRangePicker(
      context: context,
      firstDate: DateTime.now().subtract(const Duration(days: 366)),
      lastDate: DateTime.now(),
      initialDateRange: DateTimeRange(start: _from, end: _to),
      locale: const Locale('ar'),
      builder: (ctx, child) => Theme(
        data: Theme.of(ctx).copyWith(
          colorScheme: const ColorScheme.light(primary: AmyalColors.primary),
        ),
        child: child!,
      ),
    );
    if (picked == null || !mounted) return;
    setState(() {
      _from = picked.start;
      _to = picked.end;
    });
    _load();
  }

  /// تصدير الكشف PDF.
  ///
  /// نجلب البايتات بأنفسنا لا عبر مساعد يأخذ رابطاً: الملف يُولَّد لحظياً على
  /// الخادم وقد يُقطع الاتصال على شبكة جوّال بطيئة، فنحتاج مهلة أطول وإعادة
  /// محاولة — نفس النمط المستعمل في تحميل الإيصال.
  Future<void> _export() async {
    if (!mounted) return;
    final messenger = ScaffoldMessenger.of(context);
    messenger.showSnackBar(
      const SnackBar(content: Text('جارٍ تحضير الكشف...')),
    );

    String? token;
    try {
      token = await SecureStorageHelper.instance.getToken();
    } catch (_) {}

    final url = '${AppConstants.baseUrl}'
        '/api/v1/amial/statement/pdf?from=${_d(_from)}&to=${_d(_to)}';
    final headers = {
      if (token != null && token.isNotEmpty) 'Authorization': 'Bearer $token',
      'Accept': 'application/pdf',
    };

    for (var attempt = 1; attempt <= 3; attempt++) {
      try {
        final resp = await http
            .get(Uri.parse(url), headers: headers)
            .timeout(const Duration(seconds: 60));

        final ct = resp.headers['content-type'] ?? '';
        if (resp.statusCode != 200 || ct.contains('json')) {
          if (!mounted) return;
          messenger.showSnackBar(
            SnackBar(content: Text('تعذّر تحميل الكشف (${resp.statusCode})')),
          );
          return;
        }
        if (resp.bodyBytes.isEmpty) throw Exception('ملفّ فارغ');

        await PdfDownloaderHelper.downloadAndOpenPdf(
          pdfData: resp.bodyBytes,
          baseFileName: 'statement-${_d(_from)}-${_d(_to)}',
        );
        return;
      } catch (_) {
        if (attempt == 3) {
          if (!mounted) return;
          messenger.showSnackBar(
            const SnackBar(content: Text('تعذّر تحميل الكشف — حاول مجدداً')),
          );
          return;
        }
        await Future.delayed(Duration(seconds: attempt * 2));
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmyalColors.background,
      body: SafeArea(
        child: Column(children: [
          AmialScreenHeader(
            title: 'كشف حساب',
            actions: [
              AmialHeaderAction(
                  icon: Icons.file_download_outlined, onTap: _export),
              AmialHeaderAction(
                  icon: Icons.date_range_rounded, onTap: _pickRange),
            ],
          ),
          _rangeBar(),
          Expanded(child: _body()),
          if (!_loading && _error.isEmpty) _totalsBar(),
        ]),
      ),
    );
  }

  Widget _rangeBar() {
    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 0, 16, 10),
      child: InkWell(
        onTap: _pickRange,
        borderRadius: BorderRadius.circular(20),
        child: Container(
          padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
          decoration: BoxDecoration(
            color: Colors.white,
            borderRadius: BorderRadius.circular(20),
            border: Border.all(color: AmyalColors.border),
          ),
          child: Row(mainAxisAlignment: MainAxisAlignment.center, children: [
            const Icon(Icons.calendar_today_rounded,
                size: 14, color: AmyalColors.primary),
            const SizedBox(width: 8),
            Text('${_d(_from)}  —  ${_d(_to)}',
                textDirection: TextDirection.ltr,
                style: const TextStyle(
                    fontSize: 12.5,
                    fontWeight: FontWeight.w600,
                    color: Color(0xFF1A2433))),
          ]),
        ),
      ),
    );
  }

  Widget _body() {
    if (_loading) {
      return const Center(
          child: CircularProgressIndicator(color: AmyalColors.primary));
    }
    if (_error.isNotEmpty) {
      return Center(
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          const Icon(Icons.cloud_off_rounded,
              size: 40, color: AmyalColors.textMuted),
          const SizedBox(height: 10),
          Text(_error,
              style: const TextStyle(color: AmyalColors.textSecondary)),
          const SizedBox(height: 10),
          TextButton(onPressed: _load, child: const Text('إعادة المحاولة')),
        ]),
      );
    }
    if (_rows.isEmpty) {
      return const Center(
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          Icon(Icons.receipt_long_outlined,
              size: 44, color: AmyalColors.textMuted),
          SizedBox(height: 10),
          Text('لا توجد حركات في هذه الفترة',
              style: TextStyle(
                  fontSize: 15,
                  fontWeight: FontWeight.bold,
                  color: Color(0xFF1A2433))),
          SizedBox(height: 4),
          Text('غيّر الفترة من زرّ التاريخ أعلاه',
              style: TextStyle(fontSize: 12, color: AmyalColors.textSecondary)),
        ]),
      );
    }

    return Column(children: [
      if (_truncated)
        Container(
          width: double.infinity,
          margin: const EdgeInsets.fromLTRB(16, 0, 16, 8),
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
          decoration: BoxDecoration(
            color: AmyalColors.yellow.withValues(alpha: 0.18),
            borderRadius: BorderRadius.circular(10),
          ),
          child: const Text(
            'الفترة تحوي حركات أكثر مما يُعرض — صدّر الكشف PDF للقائمة الكاملة',
            style: TextStyle(fontSize: 11.5, color: Color(0xFF1A2433)),
          ),
        ),
      Expanded(
        child: SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(16, 0, 16, 12),
          child: SingleChildScrollView(
            scrollDirection: Axis.horizontal,
            child: _table(),
          ),
        ),
      ),
    ]);
  }

  // عرض ثابت لكل عمود — الجدول يُمرَّر أفقياً بدل ضغط النصّ حتى يُقرأ.
  static const double _wDate = 82;
  static const double _wType = 96;
  static const double _wStatement = 230;
  static const double _wAmount = 92;
  static const double _wBalance = 100;

  Widget _table() {
    return Container(
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: const Color(0xFFDDE2EA)),
      ),
      child: Column(children: [
        _headerRow(),
        ...List.generate(_rows.length, (i) => _dataRow(_rows[i], i)),
      ]),
    );
  }

  Widget _headerRow() {
    Widget cell(String t, double w) => Container(
          width: w,
          padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 10),
          alignment: Alignment.center,
          child: Text(t,
              textAlign: TextAlign.center,
              style: const TextStyle(
                  fontSize: 11,
                  fontWeight: FontWeight.bold,
                  color: Colors.white)),
        );

    return Container(
      decoration: const BoxDecoration(
        color: AmyalColors.primary,
        borderRadius: BorderRadius.vertical(top: Radius.circular(13)),
      ),
      child: Row(children: [
        cell('التاريخ', _wDate),
        cell('نوع العملية', _wType),
        cell('البيان', _wStatement),
        cell('مدين (عليكم)', _wAmount),
        cell('دائن (لكم)', _wAmount),
        cell('الرصيد', _wBalance),
      ]),
    );
  }

  Widget _dataRow(Map<String, dynamic> r, int index) {
    final debit = double.tryParse('${r['debit'] ?? 0}') ?? 0;
    final credit = double.tryParse('${r['credit'] ?? 0}') ?? 0;
    final date = DateTime.tryParse('${r['date'] ?? ''}');

    Widget cell(Widget child, double w, {Alignment align = Alignment.center}) =>
        Container(
          width: w,
          padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 9),
          alignment: align,
          child: child,
        );

    Widget txt(String t,
            {Color? color, FontWeight w = FontWeight.w500, double size = 11}) =>
        Text(t,
            textAlign: TextAlign.center,
            style: TextStyle(fontSize: size, fontWeight: w, color: color));

    return Container(
      decoration: BoxDecoration(
        color: index.isEven ? Colors.white : const Color(0xFFF7F9FC),
        border: const Border(
            top: BorderSide(color: Color(0xFFECEFF4), width: 1)),
      ),
      child: Row(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        cell(
          txt(date == null ? '—' : _d(date), size: 10.5),
          _wDate,
        ),
        cell(txt('${r['type_label'] ?? ''}', w: FontWeight.w600), _wType),
        cell(
          Text('${r['statement'] ?? ''}',
              style: const TextStyle(
                  fontSize: 10.5, height: 1.5, color: Color(0xFF3A4557))),
          _wStatement,
          align: Alignment.centerRight,
        ),
        cell(
          debit > 0
              ? txt(AmialMoney.yer('$debit'),
                  color: AmyalColors.red, w: FontWeight.bold)
              : txt('—', color: AmyalColors.textMuted),
          _wAmount,
        ),
        cell(
          credit > 0
              ? txt(AmialMoney.yer('$credit'),
                  color: const Color(0xFF16A34A), w: FontWeight.bold)
              : txt('—', color: AmyalColors.textMuted),
          _wAmount,
        ),
        cell(
          txt(AmialMoney.yer('${r['balance'] ?? 0}'),
              w: FontWeight.bold, color: AmyalColors.primary),
          _wBalance,
        ),
      ]),
    );
  }

  /// شريط الإجماليات — يبقى مثبّتاً أسفل الشاشة فيُقرأ بلا تمرير.
  Widget _totalsBar() {
    Widget item(String label, String value, Color color) => Expanded(
          child: Column(children: [
            Text(label,
                style: const TextStyle(
                    fontSize: 10, color: AmyalColors.textSecondary)),
            const SizedBox(height: 2),
            Text(AmialMoney.yer(value),
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: TextStyle(
                    fontSize: 12, fontWeight: FontWeight.bold, color: color)),
          ]),
        );

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
      decoration: const BoxDecoration(
        color: Colors.white,
        border: Border(top: BorderSide(color: Color(0xFFE6E9EF))),
      ),
      child: Row(children: [
        item('افتتاحي', _openingBalance, AmyalColors.textSecondary),
        item('مدين', _totalDebit, AmyalColors.red),
        item('دائن', _totalCredit, const Color(0xFF16A34A)),
        item('ختامي', _closingBalance, AmyalColors.primary),
      ]),
    );
  }
}
