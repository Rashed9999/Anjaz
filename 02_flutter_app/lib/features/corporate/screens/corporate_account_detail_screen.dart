import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amial_pay/data/api/api_client.dart';
import 'package:amial_pay/theme/amial_colors.dart';

/// AMIAL-CORPORATE-ACCOUNTS-001 — تفاصيل حساب شركة: الرصيد، الأعضاء، الحركات،
/// وإجراءات (شراء على الحساب / سداد / إضافة عضو).
class CorporateAccountDetailScreen extends StatefulWidget {
  const CorporateAccountDetailScreen({super.key, required this.accountId});
  final int accountId;

  @override
  State<CorporateAccountDetailScreen> createState() => _CorporateAccountDetailScreenState();
}

class _CorporateAccountDetailScreenState extends State<CorporateAccountDetailScreen> {
  final _api = Get.find<ApiClient>();
  bool _loading = true;
  Map<String, dynamic> _account = {};
  List<Map<String, dynamic>> _members = [];
  List<Map<String, dynamic>> _movements = [];

  String get _base => '/api/v1/amial/merchant/corporate/accounts/${widget.accountId}';

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _loading = true);
    try {
      final s = await _api.getData(_base);
      if (s.statusCode == 200 && s.body is Map) {
        final meta = (s.body['meta'] ?? {}) as Map;
        _account = Map<String, dynamic>.from((meta['account'] ?? {}) as Map);
        _members = ((meta['members'] ?? []) as List).map((e) => Map<String, dynamic>.from(e as Map)).toList();
      }
      final st = await _api.getData('$_base/statement');
      if (st.statusCode == 200 && st.body is Map) {
        _movements = (((st.body['meta'] ?? {})['movements'] ?? []) as List)
            .map((e) => Map<String, dynamic>.from(e as Map)).toList();
      }
    } catch (_) {} finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  String _fmt(dynamic v) {
    final n = double.tryParse('${v ?? 0}'.replaceAll('-', '')) ?? 0;
    return n.toStringAsFixed(n == n.roundToDouble() ? 0 : 2)
        .replaceAllMapped(RegExp(r'(\d)(?=(\d{3})+(?:\.|$))'), (m) => '${m[1]},');
  }

  void _snack(String m, {bool ok = false}) => ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(content: Text(m), backgroundColor: ok ? const Color(0xFF2E7D32) : AmialColors.red));

  Future<void> _amountDialog(String title, String endpoint, String okMsg) async {
    final amt = TextEditingController();
    final note = TextEditingController();
    int? memberId;
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => StatefulBuilder(builder: (ctx, setD) => AlertDialog(
        title: Text(title),
        content: Column(mainAxisSize: MainAxisSize.min, children: [
          TextField(controller: amt, keyboardType: const TextInputType.numberWithOptions(decimal: true),
              decoration: const InputDecoration(labelText: 'المبلغ (ر.ي)', border: OutlineInputBorder())),
          if (endpoint == 'charge' && _members.isNotEmpty) ...[
            const SizedBox(height: 10),
            DropdownButtonFormField<int?>(
              initialValue: memberId,
              decoration: const InputDecoration(labelText: 'العضو (اختياري)', border: OutlineInputBorder()),
              items: [
                const DropdownMenuItem(value: null, child: Text('بدون تحديد')),
                ..._members.map((m) => DropdownMenuItem(value: m['id'] as int, child: Text('${m['member_name']}'))),
              ],
              onChanged: (v) => setD(() => memberId = v),
            ),
          ],
          const SizedBox(height: 10),
          TextField(controller: note, decoration: const InputDecoration(labelText: 'ملاحظة (اختياري)', border: OutlineInputBorder())),
        ]),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('إلغاء')),
          FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('تأكيد')),
        ],
      )),
    );
    if (ok != true || !mounted) return;
    if ((double.tryParse(amt.text) ?? 0) <= 0) { _snack('مبلغ غير صحيح'); return; }
    final r = await _api.postData('$_base/$endpoint', {
      'amount': amt.text.trim(),
      if (note.text.trim().isNotEmpty) 'note': note.text.trim(),
      if (endpoint == 'charge' && memberId != null) 'member_id': memberId,
    });
    if (r.statusCode == 201) { _snack(okMsg, ok: true); _load(); }
    else { _snack((r.body is Map ? r.body['message']?.toString() : null) ?? 'تعذّر التنفيذ'); }
  }

  Future<void> _addMemberDialog() async {
    final name = TextEditingController();
    final ident = TextEditingController();
    final limit = TextEditingController();
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('عضو جديد'),
        content: Column(mainAxisSize: MainAxisSize.min, children: [
          TextField(controller: name, decoration: const InputDecoration(labelText: 'اسم العضو', border: OutlineInputBorder())),
          const SizedBox(height: 10),
          TextField(controller: ident, decoration: const InputDecoration(labelText: 'معرّف/بطاقة/لوحة (اختياري)', border: OutlineInputBorder())),
          const SizedBox(height: 10),
          TextField(controller: limit, keyboardType: const TextInputType.numberWithOptions(decimal: true),
              decoration: const InputDecoration(labelText: 'حدّ العملية (اختياري)', border: OutlineInputBorder())),
        ]),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('إلغاء')),
          FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('إضافة')),
        ],
      ),
    );
    if (ok != true || !mounted) return;
    if (name.text.trim().isEmpty) { _snack('أدخل اسم العضو'); return; }
    final r = await _api.postData('$_base/members', {
      'member_name': name.text.trim(),
      if (ident.text.trim().isNotEmpty) 'identifier': ident.text.trim(),
      if (limit.text.trim().isNotEmpty) 'per_txn_limit': limit.text.trim(),
    });
    if (r.statusCode == 201) { _snack('تمت الإضافة', ok: true); _load(); }
    else { _snack((r.body is Map ? r.body['message']?.toString() : null) ?? 'تعذّر'); }
  }

  @override
  Widget build(BuildContext context) {
    final bal = double.tryParse('${_account['current_balance'] ?? 0}') ?? 0;
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(
        title: Text('${_account['company_name'] ?? 'حساب شركة'}'),
        backgroundColor: AmialColors.primary, foregroundColor: Colors.white,
      ),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : RefreshIndicator(
              onRefresh: _load,
              child: ListView(padding: const EdgeInsets.all(16), children: [
                // بطاقة الرصيد
                Container(
                  padding: const EdgeInsets.all(18),
                  decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(16)),
                  child: Column(children: [
                    Text('${_account['account_code'] ?? ''}',
                        style: const TextStyle(fontSize: 11, color: AmialColors.textMuted)),
                    const SizedBox(height: 8),
                    Text('${_fmt(bal)} ر.ي',
                        style: TextStyle(fontSize: 28, fontWeight: FontWeight.bold,
                            color: bal > 0 ? AmialColors.red : const Color(0xFF2E7D32))),
                    const Text('المستحقّ على الشركة', style: TextStyle(fontSize: 12, color: AmialColors.textSecondary)),
                    const Divider(height: 24),
                    Row(mainAxisAlignment: MainAxisAlignment.spaceAround, children: [
                      _stat('حدّ الائتمان', '${_fmt(_account['credit_limit'])}'),
                      _stat('المتاح', '${_fmt(_account['available'])}'),
                    ]),
                  ]),
                ),
                const SizedBox(height: 12),
                Row(children: [
                  Expanded(child: FilledButton.icon(
                    onPressed: () => _amountDialog('شراء على الحساب', 'charge', 'تم تسجيل الشراء'),
                    icon: const Icon(Icons.add_shopping_cart, size: 18),
                    label: const Text('شراء'),
                    style: FilledButton.styleFrom(backgroundColor: AmialColors.primary, minimumSize: const Size.fromHeight(48)),
                  )),
                  const SizedBox(width: 10),
                  Expanded(child: FilledButton.icon(
                    onPressed: bal > 0 ? () => _amountDialog('سداد', 'settle', 'تم تسجيل السداد') : null,
                    icon: const Icon(Icons.payments, size: 18),
                    label: const Text('سداد'),
                    style: FilledButton.styleFrom(backgroundColor: const Color(0xFF2E7D32), minimumSize: const Size.fromHeight(48)),
                  )),
                ]),
                const SizedBox(height: 18),

                // الأعضاء
                Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
                  const Text('الأعضاء المخوّلون', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 15)),
                  TextButton.icon(onPressed: _addMemberDialog, icon: const Icon(Icons.person_add, size: 18), label: const Text('إضافة')),
                ]),
                if (_members.isEmpty) const Text('لا أعضاء بعد', style: TextStyle(fontSize: 12, color: AmialColors.textMuted)),
                ..._members.map((m) => Card(
                  margin: const EdgeInsets.only(bottom: 6),
                  child: ListTile(
                    dense: true,
                    leading: const Icon(Icons.badge_outlined, color: AmialColors.primary),
                    title: Text('${m['member_name']}'),
                    subtitle: Text([
                      if ((m['identifier'] ?? '').toString().isNotEmpty) 'معرّف: ${m['identifier']}',
                      if ((double.tryParse('${m['per_txn_limit']}') ?? 0) > 0) 'حدّ: ${_fmt(m['per_txn_limit'])}',
                    ].join('  •  '), style: const TextStyle(fontSize: 11)),
                  ),
                )),
                const SizedBox(height: 18),

                // الحركات
                const Text('كشف الحساب', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 15)),
                const SizedBox(height: 6),
                if (_movements.isEmpty) const Text('لا حركات', style: TextStyle(fontSize: 12, color: AmialColors.textMuted)),
                ..._movements.map(_movementRow),
              ]),
            ),
    );
  }

  Widget _stat(String k, String v) => Column(children: [
        Text('$v ر.ي', style: const TextStyle(fontWeight: FontWeight.bold, color: AmialColors.primary)),
        Text(k, style: const TextStyle(fontSize: 11, color: AmialColors.textSecondary)),
      ]);

  Widget _movementRow(Map<String, dynamic> m) {
    final isCharge = m['type'] == 'charge' || m['type'] == 'adjustment';
    final color = isCharge ? AmialColors.red : const Color(0xFF2E7D32);
    return Container(
      margin: const EdgeInsets.only(bottom: 6),
      decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(10)),
      child: ListTile(
        dense: true,
        leading: Icon(isCharge ? Icons.receipt_long : Icons.payments, color: color, size: 20),
        title: Text('${m['type_label'] ?? m['type']}', style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 13)),
        subtitle: (m['note'] ?? '').toString().isNotEmpty ? Text('${m['note']}', style: const TextStyle(fontSize: 11)) : null,
        trailing: Text('${isCharge ? '+' : '−'}${_fmt(m['amount'])}',
            style: TextStyle(fontWeight: FontWeight.bold, color: color)),
      ),
    );
  }
}
