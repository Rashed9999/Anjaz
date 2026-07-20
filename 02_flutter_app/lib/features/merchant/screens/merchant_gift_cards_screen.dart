import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/data/api/api_client.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';

/// AMIAL-GIFT-CARDS-001 — «بطاقات الهدايا» (باقة الأعمال فأعلى).
///
/// التاجر يصدر بطاقات برصيد مخزَّن، يستعلم عنها، يشحنها، يلغيها، ويستبدل منها.
class MerchantGiftCardsScreen extends StatefulWidget {
  const MerchantGiftCardsScreen({super.key});

  @override
  State<MerchantGiftCardsScreen> createState() => _MerchantGiftCardsScreenState();
}

class _MerchantGiftCardsScreenState extends State<MerchantGiftCardsScreen> {
  final _api = Get.find<ApiClient>();
  bool _loading = true;
  String? _error;
  List<Map<String, dynamic>> _cards = [];

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() { _loading = true; _error = null; });
    try {
      final r = await _api.getData('/api/v1/amial/merchant/gift-cards');
      if (r.statusCode == 402) {
        setState(() { _error = 'بطاقات الهدايا متاحة في باقة الأعمال فأعلى'; _loading = false; });
        return;
      }
      if (r.statusCode == 200 && r.body is Map) {
        setState(() => _cards = (((r.body['meta'] ?? {})['cards'] ?? []) as List)
            .map((e) => Map<String, dynamic>.from(e as Map)).toList());
      }
    } catch (_) { _error = 'خطأ في الشبكة'; }
    finally { if (mounted) setState(() => _loading = false); }
  }

  void _snack(String m, {bool ok = false}) => ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(content: Text(m), backgroundColor: ok ? const Color(0xFF2E7D32) : AmyalColors.red));

  Future<void> _issue() async {
    final amount = TextEditingController();
    final name = TextEditingController();
    final phone = TextEditingController();
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('إصدار بطاقة هدية'),
        content: Column(mainAxisSize: MainAxisSize.min, children: [
          TextField(controller: amount, keyboardType: TextInputType.number,
              decoration: const InputDecoration(labelText: 'قيمة البطاقة ر.ي *', border: OutlineInputBorder())),
          const SizedBox(height: 10),
          TextField(controller: name, decoration: const InputDecoration(labelText: 'اسم المستفيد (اختياري)', border: OutlineInputBorder())),
          const SizedBox(height: 10),
          TextField(controller: phone, keyboardType: TextInputType.phone,
              decoration: const InputDecoration(labelText: 'هاتف المستفيد (اختياري — لتظهر له)', border: OutlineInputBorder())),
        ]),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('إلغاء')),
          FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('إصدار')),
        ],
      ),
    );
    if (ok != true) return;
    if ((double.tryParse(amount.text.trim()) ?? 0) <= 0) { _snack('أدخل قيمة صحيحة'); return; }
    final r = await _api.postData('/api/v1/amial/merchant/gift-cards', {
      'amount': amount.text.trim(),
      if (name.text.trim().isNotEmpty) 'name': name.text.trim(),
      if (phone.text.trim().isNotEmpty) 'phone': phone.text.trim(),
    });
    if (r.statusCode == 201 && r.body is Map) {
      final card = (r.body['meta'] ?? {})['card'] ?? {};
      _load();
      if (mounted) _showCode('${card['code']}', '${card['balance']}');
    } else {
      _snack((r.body is Map ? r.body['message']?.toString() : null) ?? 'تعذّر الإصدار');
    }
  }

  void _showCode(String code, String balance) => showDialog(
        context: context,
        builder: (ctx) => AlertDialog(
          title: const Row(children: [Icon(Icons.card_giftcard, color: AmyalColors.primary), SizedBox(width: 8), Text('البطاقة جاهزة')]),
          content: Column(mainAxisSize: MainAxisSize.min, children: [
            SelectableText(code, textDirection: TextDirection.ltr,
                style: const TextStyle(fontFamily: 'monospace', fontSize: 22, fontWeight: FontWeight.bold, color: AmyalColors.primary)),
            const SizedBox(height: 6),
            Text('الرصيد: $balance ر.ي'),
          ]),
          actions: [
            TextButton.icon(onPressed: () { Clipboard.setData(ClipboardData(text: code)); _snack('نُسخ الكود', ok: true); },
                icon: const Icon(Icons.copy, size: 18), label: const Text('نسخ')),
            FilledButton(onPressed: () => Navigator.pop(ctx), child: const Text('تم')),
          ],
        ),
      );

  Future<void> _action(String card, String action) async {
    if (action == 'void') {
      final r = await _api.postData('/api/v1/amial/merchant/gift-cards/void', {'code': card});
      if (r.statusCode == 200) { _snack('أُلغيت البطاقة', ok: true); _load(); } else { _snack('تعذّر'); }
      return;
    }
    // topup / redeem: اطلب مبلغاً
    final amt = TextEditingController();
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(action == 'topup' ? 'شحن البطاقة' : 'استبدال من البطاقة'),
        content: TextField(controller: amt, keyboardType: TextInputType.number, autofocus: true,
            decoration: const InputDecoration(labelText: 'المبلغ ر.ي', border: OutlineInputBorder())),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('إلغاء')),
          FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('تأكيد')),
        ],
      ),
    );
    if (ok != true) return;
    final r = await _api.postData('/api/v1/amial/merchant/gift-cards/$action', {'code': card, 'amount': amt.text.trim()});
    if (r.statusCode == 200) { _snack(action == 'topup' ? 'تم الشحن' : 'تم الاستبدال', ok: true); _load(); }
    else { _snack((r.body is Map ? r.body['message']?.toString() : null) ?? 'تعذّر'); }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmyalColors.background,
      appBar: AppBar(title: const Text('بطاقات الهدايا'), backgroundColor: AmyalColors.primary, foregroundColor: Colors.white),
      floatingActionButton: _error == null
          ? FloatingActionButton.extended(onPressed: _issue, backgroundColor: AmyalColors.primary,
              icon: const Icon(Icons.add), label: const Text('بطاقة جديدة'))
          : null,
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : _error != null
              ? Center(child: Padding(padding: const EdgeInsets.all(24),
                  child: Column(mainAxisSize: MainAxisSize.min, children: [
                    const Icon(Icons.workspace_premium, size: 56, color: AmyalColors.yellowDark),
                    const SizedBox(height: 12),
                    Text(_error!, textAlign: TextAlign.center, style: const TextStyle(fontSize: 15, fontWeight: FontWeight.w600)),
                  ])))
              : RefreshIndicator(
                  onRefresh: _load,
                  child: ListView(padding: const EdgeInsets.all(12), children: [
                    if (_cards.isEmpty) const Padding(padding: EdgeInsets.symmetric(vertical: 40),
                        child: Center(child: Text('لا بطاقات — أصدِر بطاقة هدية'))),
                    ..._cards.map(_card),
                  ]),
                ),
    );
  }

  Widget _card(Map<String, dynamic> c) {
    final status = '${c['status']}';
    final active = status == 'active';
    final color = status == 'void' ? AmyalColors.red : status == 'depleted' ? AmyalColors.textSecondary : const Color(0xFF2E7D32);
    return Container(
      margin: const EdgeInsets.only(bottom: 8),
      decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(12)),
      child: ListTile(
        leading: CircleAvatar(backgroundColor: color.withValues(alpha: 0.12), child: Icon(Icons.card_giftcard, color: color)),
        title: Text('${c['code']}', textDirection: TextDirection.ltr, style: const TextStyle(fontWeight: FontWeight.bold, fontFamily: 'monospace')),
        subtitle: Text('${c['balance']} / ${c['initial_balance']} ر.ي${c['issued_to_name'] != null ? ' • ${c['issued_to_name']}' : ''}',
            style: const TextStyle(fontSize: 12)),
        trailing: active
            ? PopupMenuButton<String>(
                onSelected: (v) => _action('${c['code']}', v),
                itemBuilder: (_) => const [
                  PopupMenuItem(value: 'redeem', child: Text('استبدال')),
                  PopupMenuItem(value: 'topup', child: Text('شحن')),
                  PopupMenuItem(value: 'void', child: Text('إلغاء')),
                ],
              )
            : Text({'void': 'ملغاة', 'depleted': 'مستنفدة'}[status] ?? status,
                style: TextStyle(color: color, fontSize: 11, fontWeight: FontWeight.bold)),
      ),
    );
  }
}
