import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amial_pay/data/api/api_client.dart';
import 'package:amial_pay/theme/amial_colors.dart';

/// AMIAL-INSTALLMENTS-001 — «البيع بالتقسيط» (باقة التاجر برو فأعلى).
///
/// التاجر يضبط الشروط (دفعة أولى، مدد، هامش، رسوم تأخير، ضمانات) ثم ينشئ عقود
/// تقسيم لعملائه المسجّلين؛ تُحصَّل الدفعة الأولى فوراً وتُسدَّد الأقساط من المحفظة.
class MerchantInstallmentsScreen extends StatefulWidget {
  const MerchantInstallmentsScreen({super.key});

  @override
  State<MerchantInstallmentsScreen> createState() => _MerchantInstallmentsScreenState();
}

class _MerchantInstallmentsScreenState extends State<MerchantInstallmentsScreen> {
  final _api = Get.find<ApiClient>();
  bool _loading = true;
  String? _error;

  Map<String, dynamic> _plan = {};
  List<Map<String, dynamic>> _contracts = [];

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() { _loading = true; _error = null; });
    try {
      final r = await _api.getData('/api/v1/amial/merchant/installments/plan');
      if (r.statusCode == 402) {
        setState(() { _error = 'البيع بالتقسيط متاح في باقة التاجر برو فأعلى'; _loading = false; });
        return;
      }
      if (r.statusCode == 200 && r.body is Map) {
        _plan = Map<String, dynamic>.from(((r.body['meta'] ?? {})['plan'] ?? {}) as Map);
      }
      final rc = await _api.getData('/api/v1/amial/merchant/installments/contracts');
      if (rc.statusCode == 200 && rc.body is Map) {
        _contracts = (((rc.body['meta'] ?? {})['contracts'] ?? []) as List)
            .map((e) => Map<String, dynamic>.from(e as Map)).toList();
      }
    } catch (_) {
      _error = 'خطأ في الشبكة';
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  void _snack(String m, {bool ok = false}) => ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(content: Text(m), backgroundColor: ok ? const Color(0xFF2E7D32) : AmialColors.red));

  List<int> get _durations =>
      ((_plan['durations'] ?? [3, 6, 12]) as List).map((e) => int.tryParse('$e') ?? 0).where((e) => e > 0).toList();

  Future<void> _editTerms() async {
    final down = TextEditingController(text: '${_plan['down_payment_percent'] ?? '25'}');
    final markup = TextEditingController(text: '${_plan['markup_percent'] ?? '0'}');
    final lateFee = TextEditingController(text: '${_plan['late_fee_percent'] ?? '0'}');
    final maxAmt = TextEditingController(text: '${_plan['max_amount'] ?? '0'}');
    final durations = TextEditingController(text: _durations.join('،'));
    bool active = _plan['is_active'] == true;
    bool kyc = _plan['require_kyc'] == true;
    bool guarantor = _plan['require_guarantor'] == true;

    final ok = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.white,
      shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(24))),
      builder: (ctx) => StatefulBuilder(builder: (ctx, setLocal) => Padding(
        padding: EdgeInsets.fromLTRB(20, 16, 20, 20 + MediaQuery.of(ctx).viewInsets.bottom),
        child: SingleChildScrollView(
          child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            const Text('شروط التقسيط', textAlign: TextAlign.center,
                style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold)),
            const SizedBox(height: 12),
            SwitchListTile(value: active, activeColor: AmialColors.primary,
                contentPadding: EdgeInsets.zero, title: const Text('تفعيل البيع بالتقسيط'),
                onChanged: (v) => setLocal(() => active = v)),
            _num(down, 'الدفعة الأولى % (ضمان الجدّية)'),
            const SizedBox(height: 10),
            _num(markup, 'هامش المرابحة % (0 = بلا فوائد)'),
            const SizedBox(height: 10),
            _num(lateFee, 'رسم التأخير % على القسط المتأخر'),
            const SizedBox(height: 10),
            _num(maxAmt, 'سقف التمويل ر.ي (ضمان الحدّ، 0 = بلا حدّ)'),
            const SizedBox(height: 10),
            TextField(controller: durations,
                decoration: const InputDecoration(labelText: 'المدد المتاحة (أشهر، مفصولة بفاصلة)',
                    hintText: '3، 6، 12', border: OutlineInputBorder())),
            SwitchListTile(value: kyc, activeColor: AmialColors.primary,
                contentPadding: EdgeInsets.zero, title: const Text('اشتراط توثيق الهوية (KYC)'),
                subtitle: const Text('ضمان هوية العميل', style: TextStyle(fontSize: 11)),
                onChanged: (v) => setLocal(() => kyc = v)),
            SwitchListTile(value: guarantor, activeColor: AmialColors.primary,
                contentPadding: EdgeInsets.zero, title: const Text('اشتراط كفيل مسجّل'),
                subtitle: const Text('ضمان إضافي', style: TextStyle(fontSize: 11)),
                onChanged: (v) => setLocal(() => guarantor = v)),
            const SizedBox(height: 14),
            FilledButton(onPressed: () => Navigator.pop(ctx, true),
                style: FilledButton.styleFrom(backgroundColor: AmialColors.primary, minimumSize: const Size.fromHeight(50)),
                child: const Text('حفظ الشروط')),
          ]),
        ),
      )),
    );
    if (ok != true || !mounted) return;
    final durList = durations.text.split(RegExp(r'[،,\s]+')).map((e) => int.tryParse(e.trim())).whereType<int>().toList();
    final r = await _api.postData('/api/v1/amial/merchant/installments/plan', {
      'is_active': active,
      'down_payment_percent': down.text.trim(),
      'markup_percent': markup.text.trim(),
      'late_fee_percent': lateFee.text.trim(),
      'max_amount': maxAmt.text.trim(),
      'durations': durList.isEmpty ? [3, 6, 12] : durList,
      'require_kyc': kyc,
      'require_guarantor': guarantor,
    });
    if (r.statusCode == 200) { _snack('تم حفظ الشروط', ok: true); _load(); } else { _snack('تعذّر الحفظ'); }
  }

  Future<void> _newContract() async {
    if (_plan['is_active'] != true) { _snack('فعّل التقسيط من الشروط أولاً'); return; }
    final phone = TextEditingController();
    final item = TextEditingController();
    final price = TextEditingController();
    final guarantor = TextEditingController();
    int months = _durations.isNotEmpty ? _durations.first : 3;
    Map<String, dynamic>? quote;

    await showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.white,
      shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(24))),
      builder: (ctx) => StatefulBuilder(builder: (ctx, setLocal) {
        Future<void> doQuote() async {
          final p = double.tryParse(price.text.trim()) ?? 0;
          if (p <= 0) { _snack('أدخل السعر'); return; }
          final r = await _api.postData('/api/v1/amial/merchant/installments/quote', {'principal': p, 'months': months});
          if (r.statusCode == 200 && r.body is Map) {
            setLocal(() => quote = Map<String, dynamic>.from(((r.body['meta'] ?? {})['quote'] ?? {}) as Map));
          } else {
            _snack((r.body is Map ? r.body['message']?.toString() : null) ?? 'تعذّر الحساب');
          }
        }

        Future<void> doCreate() async {
          final p = double.tryParse(price.text.trim()) ?? 0;
          if (phone.text.trim().isEmpty || p <= 0) { _snack('أكمل هاتف العميل والسعر'); return; }
          final r = await _api.postData('/api/v1/amial/merchant/installments/contracts', {
            'customer_phone': phone.text.trim(),
            'principal': p,
            'months': months,
            if (item.text.trim().isNotEmpty) 'item_name': item.text.trim(),
            if (guarantor.text.trim().isNotEmpty) 'guarantor_phone': guarantor.text.trim(),
          });
          if (r.statusCode == 201) {
            if (mounted) Navigator.pop(ctx);
            _snack('تم إنشاء عقد التقسيط وتحصيل الدفعة الأولى', ok: true);
            _load();
          } else {
            _snack((r.body is Map ? r.body['message']?.toString() : null) ?? 'تعذّر إنشاء العقد');
          }
        }

        return Padding(
          padding: EdgeInsets.fromLTRB(20, 16, 20, 20 + MediaQuery.of(ctx).viewInsets.bottom),
          child: SingleChildScrollView(
            child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              const Text('عقد تقسيط جديد', textAlign: TextAlign.center,
                  style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold)),
              const SizedBox(height: 12),
              TextField(controller: phone, keyboardType: TextInputType.phone,
                  decoration: const InputDecoration(labelText: 'هاتف العميل (مسجّل) *', border: OutlineInputBorder())),
              const SizedBox(height: 10),
              TextField(controller: item,
                  decoration: const InputDecoration(labelText: 'اسم السلعة (اختياري)', border: OutlineInputBorder())),
              const SizedBox(height: 10),
              TextField(controller: price, keyboardType: TextInputType.number, onChanged: (_) => setLocal(() => quote = null),
                  decoration: const InputDecoration(labelText: 'السعر ر.ي *', border: OutlineInputBorder())),
              const SizedBox(height: 10),
              DropdownButtonFormField<int>(
                initialValue: months,
                decoration: const InputDecoration(labelText: 'المدّة', border: OutlineInputBorder()),
                items: _durations.map((m) => DropdownMenuItem(value: m, child: Text('$m أشهر'))).toList(),
                onChanged: (v) => setLocal(() { months = v ?? months; quote = null; }),
              ),
              const SizedBox(height: 10),
              TextField(controller: guarantor, keyboardType: TextInputType.phone,
                  decoration: InputDecoration(
                      labelText: _plan['require_guarantor'] == true ? 'هاتف الكفيل *' : 'هاتف الكفيل (اختياري)',
                      border: const OutlineInputBorder())),
              const SizedBox(height: 12),
              OutlinedButton.icon(onPressed: doQuote, icon: const Icon(Icons.calculate),
                  label: const Text('احسب القسط')),
              if (quote != null) ...[
                const SizedBox(height: 12),
                Container(
                  padding: const EdgeInsets.all(14),
                  decoration: BoxDecoration(color: AmialColors.primary.withValues(alpha: 0.06), borderRadius: BorderRadius.circular(12)),
                  child: Column(children: [
                    _qRow('الدفعة الأولى (تُحصَّل الآن)', '${quote!['down_payment']} ر.ي', bold: true),
                    _qRow('المبلغ المموّل', '${quote!['financed_amount']} ر.ي'),
                    _qRow('هامش المرابحة', '${quote!['markup_amount']} ر.ي'),
                    const Divider(),
                    _qRow('القسط الشهري', '${quote!['monthly_amount']} ر.ي', bold: true, color: AmialColors.primary),
                    _qRow('إجمالي ما يدفعه العميل', '${quote!['grand_total']} ر.ي'),
                  ]),
                ),
              ],
              const SizedBox(height: 12),
              FilledButton.icon(onPressed: doCreate, icon: const Icon(Icons.handshake),
                  label: const Text('إنشاء العقد وتحصيل الدفعة'),
                  style: FilledButton.styleFrom(backgroundColor: const Color(0xFF2E7D32), minimumSize: const Size.fromHeight(52))),
            ]),
          ),
        );
      }),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(title: const Text('البيع بالتقسيط'),
          backgroundColor: AmialColors.primary, foregroundColor: Colors.white,
          actions: _error == null && !_loading
              ? [IconButton(onPressed: _editTerms, icon: const Icon(Icons.tune), tooltip: 'الشروط')]
              : null),
      floatingActionButton: _error == null && !_loading
          ? FloatingActionButton.extended(onPressed: _newContract,
              backgroundColor: AmialColors.primary, icon: const Icon(Icons.add), label: const Text('عقد جديد'))
          : null,
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : _error != null
              ? Center(child: Padding(padding: const EdgeInsets.all(24),
                  child: Column(mainAxisSize: MainAxisSize.min, children: [
                    const Icon(Icons.workspace_premium, size: 56, color: AmialColors.yellowDark),
                    const SizedBox(height: 12),
                    Text(_error!, textAlign: TextAlign.center,
                        style: const TextStyle(fontSize: 15, fontWeight: FontWeight.w600)),
                  ])))
              : RefreshIndicator(
                  onRefresh: _load,
                  child: ListView(padding: const EdgeInsets.all(12), children: [
                    _statusCard(),
                    const SizedBox(height: 12),
                    const Text('العقود', style: TextStyle(fontSize: 15, fontWeight: FontWeight.bold)),
                    const SizedBox(height: 8),
                    if (_contracts.isEmpty) const Padding(padding: EdgeInsets.symmetric(vertical: 30),
                        child: Center(child: Text('لا عقود بعد'))),
                    ..._contracts.map(_contractTile),
                  ]),
                ),
    );
  }

  Widget _statusCard() {
    final active = _plan['is_active'] == true;
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(12)),
      child: Row(children: [
        Icon(active ? Icons.check_circle : Icons.pause_circle_outline,
            color: active ? const Color(0xFF2E7D32) : AmialColors.textSecondary),
        const SizedBox(width: 8),
        Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(active ? 'التقسيط مُفعّل' : 'التقسيط غير مُفعّل',
              style: const TextStyle(fontWeight: FontWeight.bold)),
          Text('دفعة أولى ${_plan['down_payment_percent'] ?? '-'}% • هامش ${_plan['markup_percent'] ?? '0'}% • مدد: ${_durations.join('، ')}',
              style: const TextStyle(fontSize: 11, color: AmialColors.textSecondary)),
        ])),
        TextButton(onPressed: _editTerms, child: const Text('تعديل')),
      ]),
    );
  }

  Widget _contractTile(Map<String, dynamic> c) {
    final statusLabel = {'active': 'نشط', 'completed': 'مكتمل', 'defaulted': 'متعثّر', 'cancelled': 'ملغى'}[c['status']] ?? c['status'];
    final statusColor = c['status'] == 'completed' ? const Color(0xFF2E7D32)
        : c['status'] == 'defaulted' ? AmialColors.red : AmialColors.primary;
    return Container(
      margin: const EdgeInsets.only(bottom: 8),
      decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(12)),
      child: ListTile(
        leading: const CircleAvatar(backgroundColor: Color(0x1A0D47A1), child: Icon(Icons.handshake, color: AmialColors.primary)),
        title: Text('${c['item_name']?.toString().isNotEmpty == true ? c['item_name'] : 'عقد #${c['id']}'} — ${c['months']} أشهر',
            style: const TextStyle(fontWeight: FontWeight.bold)),
        subtitle: Text('متبقٍّ: ${c['remaining']} من ${c['total_payable']} ر.ي • قسط ${c['monthly_amount']}',
            style: const TextStyle(fontSize: 11)),
        trailing: Container(
          padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
          decoration: BoxDecoration(color: statusColor.withValues(alpha: 0.12), borderRadius: BorderRadius.circular(8)),
          child: Text('$statusLabel', style: TextStyle(fontSize: 11, fontWeight: FontWeight.bold, color: statusColor)),
        ),
      ),
    );
  }

  Widget _num(TextEditingController c, String label) => TextField(
        controller: c,
        keyboardType: const TextInputType.numberWithOptions(decimal: true),
        decoration: InputDecoration(labelText: label, border: const OutlineInputBorder(), isDense: true),
      );

  Widget _qRow(String k, String v, {bool bold = false, Color? color}) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 3),
        child: Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
          Text(v, style: TextStyle(fontWeight: bold ? FontWeight.bold : FontWeight.normal, color: color)),
          Text(k, style: const TextStyle(fontSize: 12, color: AmialColors.textSecondary)),
        ]),
      );
}
