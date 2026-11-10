import 'package:flutter/material.dart';
import 'package:get/get.dart';

import 'package:amial_pay/data/api/api_client.dart';
import 'package:amial_pay/features/shared/widgets/amial_pin_gate.dart';
import 'package:amial_pay/helper/amial_money.dart';
import 'package:amial_pay/theme/amial_colors.dart';

/// AMIAL-BUSINESS-REFUND-001 — استردادات «أميال أعمال» بانتظار موافقة المالك.
///
/// المال يخرج من محفظة التاجر هنا فقط: موافقة برمز PIN لكل طلب، أو تفويضٌ
/// يضعه المالك برمز PIN (حدٌّ للعملية وحدٌّ يومي) فيُنفَّذ ما دونه تلقائياً.
class MerchantBusinessRefundsScreen extends StatefulWidget {
  const MerchantBusinessRefundsScreen({super.key});

  @override
  State<MerchantBusinessRefundsScreen> createState() => _MerchantBusinessRefundsScreenState();
}

class _MerchantBusinessRefundsScreenState extends State<MerchantBusinessRefundsScreen> {
  static const _base = '/api/v1/amial/merchant/business-refunds';
  final _api = Get.find<ApiClient>();
  bool _loading = true;
  bool _busy = false;
  bool _linked = true;
  String? _error;
  List<Map<String, dynamic>> _pending = [];
  List<Map<String, dynamic>> _recent = [];
  Map<String, dynamic> _mandate = {};

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() { _loading = true; _error = null; });
    try {
      final r = await _api.getData(_base);
      final body = r.body;
      if (r.statusCode == 200 && body is Map && body['success'] == true) {
        final meta = Map<String, dynamic>.from(body['meta'] as Map);
        setState(() {
          _linked = meta['linked'] == true;
          _pending = _list(meta['pending']);
          _recent = _list(meta['recent']);
          _mandate = meta['mandate'] is Map ? Map<String, dynamic>.from(meta['mandate'] as Map) : {};
        });
      } else {
        setState(() => _error = 'business_refund_load_failed'.tr);
      }
    } catch (_) {
      setState(() => _error = 'common_network_retry'.tr);
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  List<Map<String, dynamic>> _list(dynamic v) =>
      (v is List ? v : const []).map((e) => Map<String, dynamic>.from(e as Map)).toList();

  Future<void> _approve(Map<String, dynamic> r) async {
    final ok = await Get.dialog<bool>(AlertDialog(
      title: Text('business_refund_confirm_title'.tr),
      content: Text('business_refund_confirm_body'.trParams({
        'amount': AmialMoney.yer(r['amount']),
        'customer': '${r['customer_masked_name'] ?? '—'}',
      })),
      actions: [
        TextButton(onPressed: () => Get.back(result: false), child: Text('common_cancel'.tr)),
        FilledButton(onPressed: () => Get.back(result: true), child: Text('business_refund_approve'.tr)),
      ],
    ));
    if (ok != true) return;
    final pin = await askAmialPinInput(title: 'business_refund_pin_title'.tr);
    if (pin == null || pin.isEmpty) return;
    await _send(() => _api.postData('$_base/${r['refund_id']}/approve', {'pin': pin}), 'business_refund_done'.tr);
  }

  Future<void> _reject(Map<String, dynamic> r) async {
    final note = TextEditingController();
    final ok = await Get.dialog<bool>(AlertDialog(
      title: Text('business_refund_reject_title'.tr),
      content: TextField(controller: note, maxLength: 255, decoration: InputDecoration(labelText: 'business_refund_reject_reason'.tr)),
      actions: [
        TextButton(onPressed: () => Get.back(result: false), child: Text('common_cancel'.tr)),
        FilledButton(style: FilledButton.styleFrom(backgroundColor: AmialColors.red), onPressed: () => Get.back(result: true), child: Text('business_refund_reject'.tr)),
      ],
    ));
    if (ok != true) return;
    await _send(() => _api.postData('$_base/${r['refund_id']}/reject', {'note': note.text.trim()}), 'business_refund_rejected'.tr);
  }

  Future<void> _editMandate() async {
    final per = TextEditingController(text: _plain(_mandate['per_refund']));
    final daily = TextEditingController(text: _plain(_mandate['daily']));
    final ok = await Get.dialog<bool>(AlertDialog(
      title: Text('business_refund_mandate_title'.tr),
      content: Column(mainAxisSize: MainAxisSize.min, children: [
        Text('business_refund_mandate_help'.tr, style: const TextStyle(fontSize: 12, color: AmialColors.textMuted)),
        const SizedBox(height: 10),
        TextField(controller: per, keyboardType: const TextInputType.numberWithOptions(decimal: true),
            decoration: InputDecoration(labelText: 'business_refund_mandate_per'.tr, suffixText: 'ر.ي')),
        TextField(controller: daily, keyboardType: const TextInputType.numberWithOptions(decimal: true),
            decoration: InputDecoration(labelText: 'business_refund_mandate_daily'.tr, suffixText: 'ر.ي')),
      ]),
      actions: [
        TextButton(onPressed: () => Get.back(result: false), child: Text('common_cancel'.tr)),
        FilledButton(onPressed: () => Get.back(result: true), child: Text('business_refund_mandate_save'.tr)),
      ],
    ));
    if (ok != true) return;
    final pin = await askAmialPinInput(title: 'business_refund_pin_title'.tr);
    if (pin == null || pin.isEmpty) return;
    await _send(() => _api.putData('$_base/mandate', {'per_refund': per.text.trim(), 'daily': daily.text.trim(), 'pin': pin}),
        'business_refund_mandate_saved'.tr);
  }

  Future<void> _send(Future<Response> Function() call, String success) async {
    if (_busy) return;
    setState(() => _busy = true);
    try {
      final r = await call();
      final body = r.body;
      if (r.statusCode == 200 && body is Map && body['success'] == true) {
        Get.snackbar('business_refunds_title'.tr, success, backgroundColor: AmialColors.success, colorText: Colors.white);
        await _load();
      } else {
        Get.snackbar('business_refunds_title'.tr, (body is Map ? body['message']?.toString() : null) ?? 'common_network_retry'.tr,
            backgroundColor: AmialColors.red, colorText: Colors.white);
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  String _plain(dynamic v) {
    final n = double.tryParse('${v ?? 0}') ?? 0;
    return n == 0 ? '' : n.toStringAsFixed(n == n.roundToDouble() ? 0 : 2);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(title: Text('business_refunds_title'.tr)),
      body: RefreshIndicator(
        onRefresh: _load,
        child: _loading
            ? const Center(child: CircularProgressIndicator())
            : ListView(padding: const EdgeInsets.all(16), children: [
                if (_error != null) Padding(padding: const EdgeInsets.all(12), child: Text(_error!, style: const TextStyle(color: AmialColors.red))),
                if (!_linked) Padding(padding: const EdgeInsets.all(24), child: Text('business_refund_not_linked'.tr, textAlign: TextAlign.center)),
                if (_linked) ...[
                  _mandateCard(),
                  const SizedBox(height: 16),
                  Text('business_refund_pending'.tr, style: const TextStyle(fontWeight: FontWeight.bold)),
                  const SizedBox(height: 8),
                  if (_pending.isEmpty)
                    Padding(padding: const EdgeInsets.symmetric(vertical: 20), child: Text('business_refund_none'.tr, textAlign: TextAlign.center, style: const TextStyle(color: AmialColors.textMuted))),
                  ..._pending.map(_pendingCard),
                  if (_recent.isNotEmpty) ...[
                    const SizedBox(height: 16),
                    Text('business_refund_recent'.tr, style: const TextStyle(fontWeight: FontWeight.bold)),
                    const SizedBox(height: 8),
                    ..._recent.map(_recentTile),
                  ],
                ],
              ]),
      ),
    );
  }

  Widget _mandateCard() {
    final per = double.tryParse('${_mandate['per_refund'] ?? 0}') ?? 0;
    final active = per > 0;
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(14)),
      child: Row(children: [
        Icon(active ? Icons.verified_user : Icons.lock_outline, color: active ? AmialColors.success : AmialColors.textMuted),
        const SizedBox(width: 10),
        Expanded(child: Text(active
            ? 'business_refund_mandate_on'.trParams({'per': AmialMoney.yer(_mandate['per_refund']), 'daily': AmialMoney.yer(_mandate['daily'])})
            : 'business_refund_mandate_off'.tr)),
        TextButton(onPressed: _busy ? null : _editMandate, child: Text('business_refund_mandate_edit'.tr)),
      ]),
    );
  }

  Widget _pendingCard(Map<String, dynamic> r) {
    return Container(
      margin: const EdgeInsets.only(bottom: 10),
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(14), border: Border.all(color: AmialColors.primary.withValues(alpha: 0.2))),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(children: [
          Expanded(child: Text(AmialMoney.yer(r['amount']), style: const TextStyle(fontSize: 20, fontWeight: FontWeight.bold))),
          Text('${r['business_reference'] ?? ''}', style: const TextStyle(color: AmialColors.textMuted)),
        ]),
        const SizedBox(height: 4),
        Text('business_refund_to'.trParams({'customer': '${r['customer_masked_name'] ?? '—'}'})),
        const SizedBox(height: 10),
        Row(children: [
          Expanded(child: FilledButton(onPressed: _busy ? null : () => _approve(r), child: Text('business_refund_approve'.tr))),
          const SizedBox(width: 8),
          OutlinedButton(onPressed: _busy ? null : () => _reject(r), child: Text('business_refund_reject'.tr, style: const TextStyle(color: AmialColors.red))),
        ]),
      ]),
    );
  }

  Widget _recentTile(Map<String, dynamic> r) {
    final status = '${r['status']}';
    final color = status == 'completed' ? AmialColors.success : AmialColors.red;
    return ListTile(
      contentPadding: EdgeInsets.zero,
      title: Text(AmialMoney.yer(r['amount'])),
      subtitle: Text('${r['business_reference'] ?? ''} · ${r['customer_masked_name'] ?? ''}'),
      trailing: Text(('business_refund_status_' + status).tr, style: TextStyle(color: color, fontWeight: FontWeight.w600)),
    );
  }
}
