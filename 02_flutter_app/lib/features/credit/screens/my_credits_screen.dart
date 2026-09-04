import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amial_pay/data/api/api_client.dart';
import 'package:amial_pay/features/shared/widgets/amial_pin_gate.dart';
import 'package:amial_pay/theme/amial_colors.dart';
import 'package:amial_pay/helper/date_converter_helper.dart';

/// AMIAL-CUSTOMER-CREDIT-VIEW-001 — «فواتيري الآجلة».
///
/// يعرض للعميل ما عليه من آجل لدى كل تاجر (الرصيد المستحقّ + كشف الفواتير)،
/// يظهر لحظة تسجيل التاجر بيعاً آجلاً على العميل. بيانات حقيقية من الخادم.
class MyCreditsScreen extends StatefulWidget {
  const MyCreditsScreen({super.key});

  @override
  State<MyCreditsScreen> createState() => _MyCreditsScreenState();
}

class _MyCreditsScreenState extends State<MyCreditsScreen> {
  final _api = Get.find<ApiClient>();
  bool _loading = true;
  String _totalOwed = '0';
  List<Map<String, dynamic>> _accounts = [];
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() { _loading = true; _error = null; });
    try {
      final r = await _api.getData('/api/v1/amial/customer/credits');
      if (r.statusCode == 200 && r.body is Map && r.body['success'] == true) {
        final meta = (r.body['meta'] ?? {}) as Map;
        setState(() {
          _totalOwed = '${meta['total_owed'] ?? '0'}';
          _accounts = ((meta['accounts'] ?? []) as List)
              .map((e) => Map<String, dynamic>.from(e as Map))
              .toList();
        });
      } else {
        _error = 'تعذّر تحميل البيانات';
      }
    } catch (_) {
      _error = 'خطأ في الشبكة';
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  String _fmt(dynamic v) {
    final n = double.tryParse('${v ?? 0}') ?? 0;
    return n.toStringAsFixed(n == n.roundToDouble() ? 0 : 2)
        .replaceAllMapped(RegExp(r'(\d)(?=(\d{3})+(?:\.|$))'), (m) => '${m[1]},');
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(
        title: const Text('فواتيري الآجلة'),
      ),
      body: RefreshIndicator(
        onRefresh: _load,
        child: _loading
            ? const Center(child: CircularProgressIndicator())
            : ListView(
                padding: const EdgeInsets.all(16),
                children: [
                  // إجمالي ما عليّ
                  Container(
                    padding: const EdgeInsets.all(20),
                    decoration: BoxDecoration(
                      color: AmialColors.primary,
                      borderRadius: BorderRadius.circular(18),
                    ),
                    child: Column(children: [
                      const Text('إجمالي ما عليّ (آجل)',
                          style: TextStyle(color: Colors.white70, fontSize: 13)),
                      const SizedBox(height: 6),
                      Text('${_fmt(_totalOwed)} ر.ي',
                          style: const TextStyle(
                              color: Colors.white, fontSize: 30, fontWeight: FontWeight.bold)),
                    ]),
                  ),
                  const SizedBox(height: 16),
                  if (_error != null)
                    Padding(
                      padding: const EdgeInsets.all(20),
                      child: Text(_error!, textAlign: TextAlign.center,
                          style: const TextStyle(color: AmialColors.red)),
                    ),
                  if (_accounts.isEmpty && _error == null)
                    const Padding(
                      padding: EdgeInsets.symmetric(vertical: 60),
                      child: Column(children: [
                        Icon(Icons.check_circle_outline, size: 64, color: AmialColors.success),
                        SizedBox(height: 12),
                        Text('لا يوجد آجل عليك — كل حساباتك مسدّدة',
                            textAlign: TextAlign.center),
                      ]),
                    ),
                  ..._accounts.map(_accountCard),
                ],
              ),
      ),
    );
  }

  Widget _accountCard(Map<String, dynamic> a) {
    final balance = double.tryParse('${a['current_balance'] ?? 0}') ?? 0;
    final settled = balance <= 0;
    return Container(
      margin: const EdgeInsets.only(bottom: 10),
      decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(14)),
      child: ListTile(
        contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 6),
        leading: CircleAvatar(
          backgroundColor: (settled ? AmialColors.success : AmialColors.primary).withValues(alpha: 0.1),
          child: Icon(Icons.storefront,
              color: settled ? AmialColors.success : AmialColors.primary),
        ),
        title: Text('${a['merchant_name'] ?? 'تاجر'}',
            style: const TextStyle(fontWeight: FontWeight.bold)),
        subtitle: Text(settled ? 'مسدّد' : 'مستحقّ عليك',
            style: TextStyle(fontSize: 12, color: settled ? AmialColors.success : AmialColors.red)),
        trailing: Column(mainAxisAlignment: MainAxisAlignment.center, crossAxisAlignment: CrossAxisAlignment.end, children: [
          Text('${_fmt(a['current_balance'])} ر.ي',
              style: TextStyle(fontWeight: FontWeight.bold,
                  color: settled ? AmialColors.success : AmialColors.red)),
          const Icon(Icons.chevron_left, color: AmialColors.textMuted, size: 18),
        ]),
        onTap: () => Get.to(() => _CreditStatementScreen(
              accountId: a['account_id'] as int,
              merchantName: '${a['merchant_name'] ?? 'تاجر'}',
            )),
      ),
    );
  }
}

/// كشف حساب آجل واحد — الفواتير والسدادات.
class _CreditStatementScreen extends StatefulWidget {
  const _CreditStatementScreen({required this.accountId, required this.merchantName});
  final int accountId;
  final String merchantName;

  @override
  State<_CreditStatementScreen> createState() => _CreditStatementScreenState();
}

class _CreditStatementScreenState extends State<_CreditStatementScreen> {
  final _api = Get.find<ApiClient>();
  bool _loading = true;
  String _balance = '0';
  List<Map<String, dynamic>> _movements = [];
  List<Map<String, dynamic>> _invoices = [];

  /// AMIAL-CREDIT-GAP-001 — **الفرقُ بين الرصيد ومجموع الفواتير.**
  ///
  /// قائمةُ الفواتير تقتصر على قيود البيع عمداً، والتعديلُ اليدويُّ
  /// الموجب (دَينٌ قديمٌ مُرحَّل) في الرصيد ولا سطرَ له. فيقرأ العميلُ
  /// «عليّ ٥٠٠» فوق و«١٥٠٠» في البطاقة، **ولا شيءَ يفسّر الألف**.
  /// (القاعدة السابعة: الغيابُ يُقال ولا يُترَك فراغاً.)
  String _unlinked = '0';
  String _unlinkedNote = '';

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _loading = true);
    try {
      final r = await _api.getData('/api/v1/amial/customer/credits/${widget.accountId}/statement');
      if (r.statusCode == 200 && r.body is Map && r.body['success'] == true) {
        final meta = (r.body['meta'] ?? {}) as Map;
        setState(() {
          _balance = '${meta['current_balance'] ?? '0'}';
          _movements = ((meta['movements'] ?? []) as List)
              .map((e) => Map<String, dynamic>.from(e as Map))
              .toList();
          _invoices = ((meta['invoices'] ?? []) as List)
              .map((e) => Map<String, dynamic>.from(e as Map))
              .toList();
          _unlinked = '${meta['unlinked_balance'] ?? '0'}';
          _unlinkedNote = '${meta['unlinked_note_ar'] ?? ''}';
        });
      }
    } catch (_) {} finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  String _fmt(dynamic v) {
    final n = double.tryParse('${v ?? 0}'.replaceAll('-', '')) ?? 0;
    return n.toStringAsFixed(n == n.roundToDouble() ? 0 : 2);
  }

  Future<void> _settle({Map<String, dynamic>? invoice}) async {
    final balance = double.tryParse('${invoice?['remaining'] ?? _balance}') ?? 0;
    if (balance <= 0) return;
    final amtCtrl = TextEditingController(text: balance.toStringAsFixed(0));

    final amount = await showDialog<String>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(invoice == null ? 'سداد الآجل' : 'سداد فاتورة آجلة'),
        content: Column(mainAxisSize: MainAxisSize.min, children: [
          Text('المستحقّ: ${_fmt(invoice?['remaining'] ?? _balance)} ر.ي',
              style: const TextStyle(fontWeight: FontWeight.bold, color: AmialColors.red)),
          const SizedBox(height: 12),
          TextField(
            controller: amtCtrl,
            keyboardType: const TextInputType.numberWithOptions(decimal: true),
            decoration: const InputDecoration(
                labelText: 'مبلغ السداد', suffixText: 'ر.ي', border: OutlineInputBorder()),
          ),
          const SizedBox(height: 6),
          const Text('يُخصم من محفظتك ويُضاف للتاجر فوراً.',
              style: TextStyle(fontSize: 11, color: AmialColors.textMuted)),
        ]),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('إلغاء')),
          FilledButton(onPressed: () => Navigator.pop(ctx, amtCtrl.text.trim()), child: const Text('متابعة')),
        ],
      ),
    );
    if (amount == null || amount.isEmpty || !mounted) return;
    final val = double.tryParse(amount) ?? 0;
    if (val <= 0 || val > balance) { _snack('مبلغ غير صحيح'); return; }

    final pin = await askAmialPinInput(title: 'أدخل رمز الدخول لتأكيد السداد');
    if (pin == null || pin.isEmpty || !mounted) return;

    final r = await _api.postData(
      '/api/v1/amial/customer/credits/${widget.accountId}/settle',
      {
        'amount': amount,
        'pin': pin,
        if (invoice?['movement_ulid'] != null)
          'sale_movement_ulid': invoice!['movement_ulid'],
      },
    );
    if (!mounted) return;
    if (r.statusCode == 200) {
      _snack('تم السداد بنجاح ✓', ok: true);
      _load();
    } else {
      final msg = (r.body is Map ? r.body['message']?.toString() : null) ?? 'تعذّر السداد';
      _snack(msg);
    }
  }

  void _snack(String m, {bool ok = false}) => ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(content: Text(m), backgroundColor: ok ? AmialColors.success : AmialColors.red));

  String _date(String? iso) {
    if (iso == null) return '';
    final d = DateConverterHelper.tryFromApi(iso);
    if (d == null) return '';
    return '${d.year}/${d.month.toString().padLeft(2, '0')}/${d.day.toString().padLeft(2, '0')}';
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(
        title: Text(widget.merchantName),
      ),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : RefreshIndicator(
              onRefresh: _load,
              child: ListView(padding: const EdgeInsets.all(16), children: [
                Container(
                  padding: const EdgeInsets.all(16),
                  decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(14)),
                  child: Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
                    Text('${_fmt(_balance)} ر.ي',
                        style: const TextStyle(fontSize: 22, fontWeight: FontWeight.bold, color: AmialColors.red)),
                    const Text('الرصيد المستحقّ', style: TextStyle(color: AmialColors.textSecondary)),
                  ]),
                ),
                if ((double.tryParse(_balance) ?? 0) > 0) ...[
                  const SizedBox(height: 10),
                  FilledButton.icon(
                    onPressed: _settle,
                    icon: const Icon(Icons.account_balance_wallet),
                    label: const Text('سداد من محفظتي'),
                    style: FilledButton.styleFrom(
                        backgroundColor: AmialColors.success,
                        minimumSize: const Size.fromHeight(50)),
                  ),
                ],
                const SizedBox(height: 12),
                if (_invoices.isNotEmpty) ...[
                  const Text('الفواتير المستحقة',
                      style: TextStyle(fontWeight: FontWeight.bold, fontSize: 16)),
                  const SizedBox(height: 8),
                  ..._invoices.map(_invoiceRow),
                  const SizedBox(height: 8),
                ],
                if ((double.tryParse(_unlinked) ?? 0) > 0) _unlinkedBox(),
                ..._movements.map(_movementRow),
              ]),
            ),
    );
  }

  Widget _movementRow(Map<String, dynamic> m) {
    final type = '${m['type']}';
    final isDebt = type == 'sale' || type == 'adjustment';
    final color = isDebt ? AmialColors.red : AmialColors.success;
    final sign = isDebt ? '+' : '−';
    return Container(
      margin: const EdgeInsets.only(bottom: 8),
      decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(12)),
      child: ListTile(
        leading: Icon(isDebt ? Icons.receipt_long : Icons.payments, color: color),
        title: Text('${m['type_label'] ?? type}', style: const TextStyle(fontWeight: FontWeight.w600)),
        subtitle: Text([
          _date('${m['created_at']}'),
          if (m['reference_number'] != null) 'مرجع: ${m['reference_number']}',
          if (m['note'] != null) '${m['note']}',
        ].where((s) => s.isNotEmpty).join(' • '), style: const TextStyle(fontSize: 11)),
        trailing: Text('$sign${_fmt(m['amount'])}',
            style: TextStyle(fontWeight: FontWeight.bold, color: color, fontSize: 15)),
      ),
    );
  }

  Widget _invoiceRow(Map<String, dynamic> invoice) {
    final due = '${invoice['due_date'] ?? ''}';
    final note = '${invoice['note'] ?? ''}';
    final reference = '${invoice['reference_number'] ?? ''}';
    final remaining = _fmt(invoice['remaining']);
    return Container(
      margin: const EdgeInsets.only(bottom: 8),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: AmialColors.red.withValues(alpha: 0.18)),
      ),
      child: ListTile(
        leading: const Icon(Icons.receipt_long, color: AmialColors.red),
        title: Text(reference.isEmpty ? 'فاتورة آجلة' : 'فاتورة $reference',
            style: const TextStyle(fontWeight: FontWeight.w700)),
        subtitle: Text([
          if (due.isNotEmpty) 'الاستحقاق: $due',
          if (note.isNotEmpty) note,
        ].join(' • '), style: const TextStyle(fontSize: 11)),
        trailing: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          crossAxisAlignment: CrossAxisAlignment.end,
          children: [
            Text('$remaining ر.ي',
                style: const TextStyle(fontWeight: FontWeight.bold, color: AmialColors.red)),
            const Text('سداد جزئي أو كامل', style: TextStyle(fontSize: 10, color: AmialColors.textMuted)),
          ],
        ),
        onTap: () => _settle(invoice: invoice),
      ),
    );
  }

  /// **ما ليس فاتورةً يُقال بنصّه** — ولا يُخترَع له سطرُ فاتورةٍ وهميّ
  /// بزرّ سدادٍ لا يقبله المحرّك.
  Widget _unlinkedBox() => Container(
        margin: const EdgeInsets.only(bottom: 12),
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(
          color: AmialColors.warningSurface,
          borderRadius: BorderRadius.circular(12),
          border: Border.all(color: AmialColors.warning.withValues(alpha: 0.35)),
        ),
        child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
          const Icon(Icons.info_outline, size: 18, color: AmialColors.warning),
          const SizedBox(width: 8),
          Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text('${_fmt(_unlinked)} ر.ي خارج الفواتير أعلاه',
                  style: const TextStyle(
                      fontWeight: FontWeight.w800,
                      fontSize: 13,
                      color: AmialColors.warning)),
              if (_unlinkedNote.isNotEmpty)
                Padding(
                  padding: const EdgeInsets.only(top: 3),
                  child: Text(_unlinkedNote,
                      style: const TextStyle(
                          fontSize: 11, color: AmialColors.textSecondary)),
                ),
            ]),
          ),
        ]),
      );
}
