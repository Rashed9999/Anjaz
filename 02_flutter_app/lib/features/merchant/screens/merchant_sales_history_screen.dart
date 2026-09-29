import 'package:flutter/material.dart';
import 'package:get/get.dart';

import 'package:amial_pay/features/merchant/domain/repositories/merchant_repo.dart';
import 'package:amial_pay/features/merchant/screens/cashier_receipt_screen.dart';
import 'package:amial_pay/theme/amial_colors.dart';

/// سجلٌ قابل للوصول لمبيعات كاشير التجزئة/البيع السريع.
///
/// هذا ليس كشف المحفظة: يعرض أيضاً النقد والآجل، ثم يفتح المستند الحقيقي
/// من الخادم لإعادة طباعته أو تنزيله. القطاعات ذات مصدر بيع مستقل تبقى في
/// مسارها القطاعي كي لا نعرض بيانات ناقصة على أنها فاتورة عامة.
class MerchantSalesHistoryScreen extends StatefulWidget {
  const MerchantSalesHistoryScreen({super.key});

  @override
  State<MerchantSalesHistoryScreen> createState() => _MerchantSalesHistoryScreenState();
}

class _MerchantSalesHistoryScreenState extends State<MerchantSalesHistoryScreen> {
  final _repo = Get.find<MerchantRepo>();
  DateTime _date = DateTime.now();
  List<Map<String, dynamic>> _sales = const [];
  bool _loading = true;
  String? _error;

  String get _dateText =>
      '${_date.year}-${_date.month.toString().padLeft(2, '0')}-${_date.day.toString().padLeft(2, '0')}';

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() { _loading = true; _error = null; });
    try {
      final response = await _repo.cashierSales(date: _dateText);
      final body = response.body;
      if (response.statusCode == 200 && body is Map && body['success'] == true) {
        final meta = body['meta'];
        final raw = meta is Map ? meta['sales'] : null;
        setState(() => _sales = raw is List
            ? raw.whereType<Map>().map((s) => Map<String, dynamic>.from(s)).toList()
            : const []);
      } else if (response.statusCode == 403) {
        setState(() => _error = 'لا تملك صلاحية عرض مبيعات نقاط البيع لهذا الحساب.');
      } else {
        setState(() => _error = 'تعذر تحميل سجل المبيعات الآن.');
      }
    } catch (_) {
      setState(() => _error = 'تعذر الاتصال. تحقق من الشبكة ثم أعد المحاولة.');
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _pickDate() async {
    final selected = await showDatePicker(
      context: context,
      initialDate: _date,
      firstDate: DateTime(2024),
      lastDate: DateTime.now(),
    );
    if (selected == null) return;
    setState(() => _date = selected);
    await _load();
  }

  Future<void> _openInvoice(Map<String, dynamic> row) async {
    final ulid = row['sale_ulid']?.toString() ?? '';
    if (!RegExp(r'^[A-Z0-9]{26}$').hasMatch(ulid)) {
      _message('رقم البيع غير صالح لفتح الفاتورة.');
      return;
    }
    _progress(true);
    try {
      final response = await _repo.cashierSale(ulid);
      final body = response.body;
      final meta = body is Map ? body['meta'] : null;
      final sale = meta is Map && meta['sale'] is Map
          ? Map<String, dynamic>.from(meta['sale'] as Map)
          : null;
      final lines = meta is Map && meta['lines'] is List ? meta['lines'] as List : const [];
      if (response.statusCode != 200 || sale == null) {
        _message(response.statusCode == 404 ? 'هذه الفاتورة لم تعد متاحة.' : 'تعذر فتح تفاصيل الفاتورة.');
        return;
      }
      sale['items'] = lines.whereType<Map>().map((line) => {
        'name': line['name'],
        'qty': line['quantity'],
        'price': line['unit_price'],
      }).toList();
      if (!mounted) return;
      await Get.to(() => CashierReceiptScreen(
        sale: sale,
        total: double.tryParse('${sale['total_amount'] ?? 0}') ?? 0,
        method: '${sale['payment_method'] ?? ''}',
        customerName: sale['customer_name']?.toString(),
        customerPhone: sale['customer_phone']?.toString(),
      ));
    } catch (_) {
      _message('تعذر الاتصال أثناء فتح الفاتورة.');
    } finally {
      _progress(false);
    }
  }

  void _progress(bool value) {
    if (mounted) setState(() => _loading = value);
  }

  void _message(String text) {
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(content: Text(text), backgroundColor: AmialColors.red),
    );
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    backgroundColor: AmialColors.background,
    appBar: AppBar(title: const Text('سجل مبيعات نقاط البيع')),
    body: RefreshIndicator(
      onRefresh: _load,
      child: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          _dateCard(),
          const SizedBox(height: 12),
          const Text('اضغط أي عملية لعرض الأصناف وطريقة الدفع وإعادة الطباعة أو تنزيل PDF.',
              style: TextStyle(fontSize: 12, color: AmialColors.textSecondary)),
          const SizedBox(height: 12),
          if (_loading) const Padding(
            padding: EdgeInsets.all(40), child: Center(child: CircularProgressIndicator()),
          ) else if (_error != null) _ErrorState(text: _error!, onRetry: _load)
          else if (_sales.isEmpty) const Padding(
            padding: EdgeInsets.all(40),
            child: Center(child: Column(children: [
              Icon(Icons.receipt_long_outlined, size: 60, color: AmialColors.textMuted),
              SizedBox(height: 10),
              Text('لا توجد مبيعات مسجلة في هذا اليوم.'),
            ])),
          ) else ..._sales.map((sale) => _SaleCard(sale: sale, onTap: () => _openInvoice(sale))),
        ],
      ),
    ),
  );

  Widget _dateCard() => Card(
    child: ListTile(
      leading: const Icon(Icons.calendar_today_outlined, color: AmialColors.primary),
      title: const Text('تاريخ المبيعات'),
      subtitle: Text(_dateText, textDirection: TextDirection.ltr),
      trailing: const Icon(Icons.chevron_left),
      onTap: _loading ? null : _pickDate,
    ),
  );
}

class _SaleCard extends StatelessWidget {
  const _SaleCard({required this.sale, required this.onTap});
  final Map<String, dynamic> sale;
  final VoidCallback onTap;

  String _method(String raw) => switch (raw) {
    'cash' => 'نقداً',
    'amial_pay' => 'أميال باي',
    'credit' => 'آجل',
    'mixed' => 'مختلط',
    'corporate' => 'حساب شركة',
    _ => raw.isEmpty ? 'غير محددة' : raw,
  };

  @override
  Widget build(BuildContext context) {
    final method = _method('${sale['payment_method'] ?? ''}');
    final status = '${sale['status'] ?? ''}';
    final refunded = double.tryParse('${sale['refunded_total'] ?? 0}') ?? 0;
    return Card(
      margin: const EdgeInsets.only(bottom: 8),
      child: ListTile(
        onTap: onTap,
        leading: const CircleAvatar(
          backgroundColor: AmialColors.background,
          child: Icon(Icons.receipt_long_outlined, color: AmialColors.primary),
        ),
        title: Text('${sale['invoice_number'] ?? sale['sale_ulid'] ?? 'فاتورة'}',
            textDirection: TextDirection.ltr, style: const TextStyle(fontWeight: FontWeight.bold)),
        subtitle: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text('الدفع: $method · ${sale['items_count'] ?? 0} أصناف'),
          if (status.isNotEmpty) Text('الحالة: $status', style: const TextStyle(fontSize: 11)),
          if (refunded > 0) Text('المسترجع: $refunded ر.ي', style: const TextStyle(fontSize: 11, color: AmialColors.red)),
        ]),
        trailing: Column(mainAxisAlignment: MainAxisAlignment.center, crossAxisAlignment: CrossAxisAlignment.end, children: [
          Text('${sale['total_amount'] ?? '0'} ر.ي', textDirection: TextDirection.ltr,
              style: const TextStyle(fontWeight: FontWeight.bold)),
          const Icon(Icons.chevron_left, size: 18),
        ]),
      ),
    );
  }
}

class _ErrorState extends StatelessWidget {
  const _ErrorState({required this.text, required this.onRetry});
  final String text;
  final Future<void> Function() onRetry;

  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.all(36),
    child: Center(child: Column(children: [
      const Icon(Icons.cloud_off_outlined, size: 56, color: AmialColors.textMuted),
      const SizedBox(height: 10), Text(text, textAlign: TextAlign.center),
      TextButton(onPressed: onRetry, child: const Text('إعادة المحاولة')),
    ])),
  );
}
