import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:get/get.dart';

import 'package:amial_pay/data/api/api_client.dart';
import 'package:amial_pay/features/shared/widgets/amial_pin_gate.dart';
import 'package:amial_pay/helper/amial_money.dart';
import 'package:amial_pay/theme/amial_colors.dart';

/// AMIAL-MERCHANT-CASHOUT-001 — سحب رصيد المنشأة نقداً من أي وكيل.
///
/// يُحجز المبلغ مع الرسم ويظهر رقم عملية؛ يعطيه المالك للوكيل مع هاتفه للتحقق.
/// الرقم سرّ الصرف: يُعرض هنا فقط ما دام الطلب معلّقاً.
class MerchantCashOutScreen extends StatefulWidget {
  const MerchantCashOutScreen({super.key});

  @override
  State<MerchantCashOutScreen> createState() => _MerchantCashOutScreenState();
}

class _MerchantCashOutScreenState extends State<MerchantCashOutScreen> {
  static const _base = '/api/v1/amial/merchant/cash-out';
  final _api = Get.find<ApiClient>();
  final _amount = TextEditingController();
  bool _loading = true;
  bool _busy = false;
  List<Map<String, dynamic>> _requests = [];
  Map<String, dynamic> _limits = {};

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _amount.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() => _loading = true);
    try {
      final r = await _api.getData(_base);
      if (r.statusCode == 200 && r.body is Map && r.body['success'] == true) {
        final meta = Map<String, dynamic>.from(r.body['meta'] as Map);
        setState(() {
          _limits = Map<String, dynamic>.from((meta['limits'] ?? {}) as Map);
          _requests = ((meta['requests'] ?? []) as List).map((e) => Map<String, dynamic>.from(e as Map)).toList();
        });
      }
    } catch (_) {} finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _request() async {
    final value = _amount.text.trim();
    if (!RegExp(r'^\d{1,15}(\.\d{1,2})?$').hasMatch(value) || (double.tryParse(value) ?? 0) <= 0) {
      _snack('merchant_amount_positive'.tr);
      return;
    }
    final pin = await askAmialPinInput(title: 'merchant_cash_out_pin'.tr);
    if (pin == null || pin.isEmpty) return;
    setState(() => _busy = true);
    try {
      final r = await _api.postData(_base, {'amount': value, 'pin': pin});
      final body = r.body;
      if (r.statusCode == 201 && body is Map && body['success'] == true) {
        _amount.clear();
        await _load();
      } else {
        _snack((body is Map ? body['message']?.toString() : null) ?? 'common_network_retry'.tr);
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _cancel(Map<String, dynamic> r) async {
    final res = await _api.postData('$_base/${r['id']}/cancel', {});
    if (res.statusCode != 200) _snack((res.body is Map ? res.body['message']?.toString() : null) ?? 'common_network_retry'.tr);
    await _load();
  }

  void _snack(String m) => ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(m), backgroundColor: AmialColors.red));

  @override
  Widget build(BuildContext context) {
    final pending = _requests.where((r) => r['status'] == 'pending').toList();
    final history = _requests.where((r) => r['status'] != 'pending').toList();
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(title: Text('merchant_cash_out_title'.tr)),
      body: RefreshIndicator(
        onRefresh: _load,
        child: _loading
            ? const Center(child: CircularProgressIndicator())
            : ListView(padding: const EdgeInsets.all(16), children: [
                ...pending.map(_pendingCard),
                if (pending.isEmpty) _requestCard(),
                if (history.isNotEmpty) ...[
                  const SizedBox(height: 16),
                  Text('merchant_cash_out_history'.tr, style: const TextStyle(fontWeight: FontWeight.bold)),
                  ...history.map((r) => ListTile(
                        contentPadding: EdgeInsets.zero,
                        title: Text(AmialMoney.yer(r['amount'])),
                        subtitle: Text('merchant_cash_out_fee'.trParams({'fee': AmialMoney.yer(r['fee'])})),
                        trailing: Text(('merchant_cash_out_status_' + (r['status']?.toString() ?? '')).tr,
                            style: TextStyle(color: r['status'] == 'completed' ? AmialColors.success : AmialColors.textMuted)),
                      )),
                ],
              ]),
      ),
    );
  }

  Widget _requestCard() {
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(14)),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Text('merchant_cash_out_intro'.tr, style: const TextStyle(color: AmialColors.textSecondary)),
        const SizedBox(height: 12),
        TextField(
          controller: _amount,
          keyboardType: const TextInputType.numberWithOptions(decimal: true),
          decoration: InputDecoration(labelText: 'merchant_cash_out_amount'.tr, suffixText: 'ر.ي', border: const OutlineInputBorder()),
        ),
        const SizedBox(height: 6),
        Text('merchant_cash_out_limits'.trParams({
          'per': AmialMoney.yer(_limits['max_per_request']),
          'daily': AmialMoney.yer(_limits['daily']),
        }), style: const TextStyle(fontSize: 12, color: AmialColors.textMuted)),
        const SizedBox(height: 12),
        FilledButton(onPressed: _busy ? null : _request, child: Text('merchant_cash_out_submit'.tr)),
      ]),
    );
  }

  Widget _pendingCard(Map<String, dynamic> r) {
    final code = '${r['op_code'] ?? ''}';
    return Container(
      margin: const EdgeInsets.only(bottom: 12),
      padding: const EdgeInsets.all(18),
      decoration: BoxDecoration(color: AmialColors.primary, borderRadius: BorderRadius.circular(16)),
      child: Column(children: [
        Text('merchant_cash_out_code'.tr, style: const TextStyle(color: Colors.white70)),
        const SizedBox(height: 6),
        GestureDetector(
          onTap: () => Clipboard.setData(ClipboardData(text: code)),
          child: Text(code, textDirection: TextDirection.ltr,
              style: const TextStyle(color: Colors.white, fontSize: 34, fontWeight: FontWeight.bold, letterSpacing: 4)),
        ),
        const SizedBox(height: 8),
        Text(AmialMoney.yer(r['amount']), style: const TextStyle(color: Colors.white, fontSize: 20, fontWeight: FontWeight.w600)),
        Text('merchant_cash_out_fee'.trParams({'fee': AmialMoney.yer(r['fee'])}), style: const TextStyle(color: Colors.white70, fontSize: 12)),
        const SizedBox(height: 10),
        Text('merchant_cash_out_agent_hint'.tr, textAlign: TextAlign.center, style: const TextStyle(color: Colors.white, fontSize: 13)),
        TextButton(onPressed: () => _cancel(r), child: Text('merchant_cash_out_cancel'.tr, style: const TextStyle(color: Colors.white))),
      ]),
    );
  }
}
