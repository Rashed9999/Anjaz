import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/data/api/api_client.dart';
import 'package:amyal_pay/features/corporate/screens/corporate_account_detail_screen.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';

/// AMIAL-CORPORATE-ACCOUNTS-001 — «حسابات الشركات» (B2B، الباقة المؤسسية).
///
/// شركة تفتح حساباً بحدّ ائتمان؛ أعضاؤها يشترون على الحساب ويُسوّى دورياً.
class CorporateAccountsScreen extends StatefulWidget {
  const CorporateAccountsScreen({super.key});

  @override
  State<CorporateAccountsScreen> createState() => _CorporateAccountsScreenState();
}

class _CorporateAccountsScreenState extends State<CorporateAccountsScreen> {
  final _api = Get.find<ApiClient>();
  bool _loading = true;
  String? _error;
  String _totalOutstanding = '0';
  List<Map<String, dynamic>> _accounts = [];

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() { _loading = true; _error = null; });
    try {
      final dash = await _api.getData('/api/v1/amial/merchant/corporate/dashboard');
      if (dash.statusCode == 402) {
        setState(() { _error = 'حسابات الشركات متاحة في الباقة المؤسسية'; _loading = false; });
        return;
      }
      if (dash.statusCode == 200 && dash.body is Map) {
        _totalOutstanding = '${(dash.body['meta'] ?? {})['total_outstanding'] ?? '0'}';
      }
      final r = await _api.getData('/api/v1/amial/merchant/corporate/accounts');
      if (r.statusCode == 200 && r.body is Map && r.body['success'] == true) {
        setState(() => _accounts = (((r.body['meta'] ?? {})['accounts'] ?? []) as List)
            .map((e) => Map<String, dynamic>.from(e as Map)).toList());
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

  Future<void> _createDialog() async {
    final name = TextEditingController();
    final contact = TextEditingController();
    final phone = TextEditingController();
    final limit = TextEditingController();
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('حساب شركة جديد'),
        content: SingleChildScrollView(child: Column(mainAxisSize: MainAxisSize.min, children: [
          _f(name, 'اسم الشركة'),
          _f(contact, 'الشخص المسؤول (اختياري)'),
          _f(phone, 'الهاتف (اختياري)', ltr: true),
          _f(limit, 'حدّ الائتمان (ر.ي)', number: true),
        ])),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('إلغاء')),
          FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('إنشاء')),
        ],
      ),
    );
    if (ok != true || !mounted) return;
    if (name.text.trim().isEmpty || (double.tryParse(limit.text) ?? -1) < 0) {
      _snack('أدخل الاسم وحدّ ائتمان صحيح'); return;
    }
    final r = await _api.postData('/api/v1/amial/merchant/corporate/accounts', {
      'company_name': name.text.trim(),
      'contact_person': contact.text.trim(),
      'contact_phone': phone.text.trim(),
      'credit_limit': limit.text.trim(),
    });
    if (r.statusCode == 201) { _snack('تم إنشاء الحساب', ok: true); _load(); }
    else { _snack((r.body is Map ? r.body['message']?.toString() : null) ?? 'تعذّر الإنشاء'); }
  }

  Widget _f(TextEditingController c, String label, {bool number = false, bool ltr = false}) => Padding(
        padding: const EdgeInsets.only(bottom: 10),
        child: TextField(
          controller: c,
          textDirection: ltr ? TextDirection.ltr : null,
          keyboardType: number ? const TextInputType.numberWithOptions(decimal: true) : null,
          decoration: InputDecoration(labelText: label, border: const OutlineInputBorder()),
        ),
      );

  void _snack(String m, {bool ok = false}) => ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(content: Text(m), backgroundColor: ok ? const Color(0xFF2E7D32) : AmyalColors.red));

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmyalColors.background,
      appBar: AppBar(
        title: const Text('حسابات الشركات'),
        backgroundColor: AmyalColors.primary, foregroundColor: Colors.white,
      ),
      floatingActionButton: _error == null
          ? FloatingActionButton.extended(
              onPressed: _createDialog, backgroundColor: AmyalColors.primary,
              icon: const Icon(Icons.add_business), label: const Text('شركة جديدة'))
          : null,
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : _error != null
              ? Center(child: Padding(padding: const EdgeInsets.all(24),
                  child: Column(mainAxisSize: MainAxisSize.min, children: [
                    const Icon(Icons.workspace_premium, size: 56, color: AmyalColors.yellowDark),
                    const SizedBox(height: 12),
                    Text(_error!, textAlign: TextAlign.center,
                        style: const TextStyle(fontSize: 15, fontWeight: FontWeight.w600)),
                  ])))
              : RefreshIndicator(
                  onRefresh: _load,
                  child: ListView(padding: const EdgeInsets.all(16), children: [
                    Container(
                      padding: const EdgeInsets.all(18),
                      decoration: BoxDecoration(color: AmyalColors.primary, borderRadius: BorderRadius.circular(16)),
                      child: Column(children: [
                        const Text('إجمالي المستحقّ على الشركات', style: TextStyle(color: Colors.white70, fontSize: 12)),
                        const SizedBox(height: 6),
                        Text('${_fmt(_totalOutstanding)} ر.ي',
                            style: const TextStyle(color: Colors.white, fontSize: 26, fontWeight: FontWeight.bold)),
                      ]),
                    ),
                    const SizedBox(height: 14),
                    if (_accounts.isEmpty)
                      const Padding(padding: EdgeInsets.symmetric(vertical: 50),
                          child: Center(child: Text('لا توجد شركات — أضِف أول شركة'))),
                    ..._accounts.map(_card),
                  ]),
                ),
    );
  }

  Widget _card(Map<String, dynamic> a) {
    final bal = double.tryParse('${a['current_balance'] ?? 0}') ?? 0;
    final suspended = a['status'] != 'active';
    return Container(
      margin: const EdgeInsets.only(bottom: 10),
      decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(14)),
      child: ListTile(
        contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 6),
        leading: CircleAvatar(
          backgroundColor: AmyalColors.primary.withValues(alpha: 0.1),
          child: const Icon(Icons.business, color: AmyalColors.primary)),
        title: Text('${a['company_name'] ?? ''}', style: const TextStyle(fontWeight: FontWeight.bold)),
        subtitle: Text('${a['account_code']}  •  المتاح: ${_fmt(a['available'])} ر.ي'
            '${suspended ? '  •  موقوف' : ''}', style: const TextStyle(fontSize: 11)),
        trailing: Column(mainAxisAlignment: MainAxisAlignment.center, crossAxisAlignment: CrossAxisAlignment.end, children: [
          Text('${_fmt(bal)} ر.ي', style: TextStyle(
              fontWeight: FontWeight.bold, color: bal > 0 ? AmyalColors.red : const Color(0xFF2E7D32))),
          const Icon(Icons.chevron_left, color: AmyalColors.textMuted, size: 18),
        ]),
        onTap: () async {
          await Get.to(() => CorporateAccountDetailScreen(accountId: a['id'] as int));
          _load();
        },
      ),
    );
  }
}
