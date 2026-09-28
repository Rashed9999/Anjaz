import 'package:flutter/material.dart';
import 'package:get/get.dart';

import 'package:amial_pay/features/merchant/domain/repositories/merchant_repo.dart';
import 'package:amial_pay/helper/amial_money.dart';
import 'package:amial_pay/theme/amial_colors.dart';
import 'package:amial_pay/util/app_direction.dart';

/// مستحقات مالك المنشأة؛ لا تستدعي أبداً شاشة أو API سحب العميل.
class MerchantPayoutScreen extends StatefulWidget {
  const MerchantPayoutScreen({super.key});

  @override
  State<MerchantPayoutScreen> createState() => _MerchantPayoutScreenState();
}

class _MerchantPayoutScreenState extends State<MerchantPayoutScreen> {
  final _repo = Get.find<MerchantRepo>();
  final _amount = TextEditingController();
  final _note = TextEditingController();
  List<dynamic> _items = const [];
  bool _loading = true;
  bool _submitting = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _amount.dispose();
    _note.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() { _loading = true; _error = null; });
    try {
      final r = await _repo.payoutRequests();
      final body = r.body;
      if (r.statusCode == 200 && body is Map && body['success'] == true) {
        setState(() => _items = List<dynamic>.from((body['meta']?['requests'] ?? const [])));
      } else {
        setState(() => _error = 'تعذر تحميل طلبات السحب الآن.');
      }
    } catch (_) {
      setState(() => _error = 'تعذر الاتصال. تحقق من الشبكة ثم أعد المحاولة.');
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _submit() async {
    final value = _amount.text.trim();
    if ((double.tryParse(value) ?? 0) <= 0) {
      Get.snackbar('المبلغ غير صحيح', 'أدخل مبلغاً أكبر من صفر.');
      return;
    }
    final confirmed = await Get.dialog<bool>(AlertDialog(
      title: const Text('تأكيد طلب السحب'),
      content: Text('سيُحجز ${AmialMoney.yer(value)} حتى تعتمد الإدارة الطلب وتزوّدك بتعليمات الاستلام.'),
      actions: [
        TextButton(onPressed: () => Get.back(result: false), child: const Text('إلغاء')),
        FilledButton(onPressed: () => Get.back(result: true), child: const Text('إرسال الطلب')),
      ],
    ));
    if (confirmed != true) return;

    setState(() => _submitting = true);
    try {
      final r = await _repo.requestPayout(amount: value, note: _note.text);
      final body = r.body;
      if (r.statusCode == 201 && body is Map && body['success'] == true) {
        _amount.clear(); _note.clear();
        Get.snackbar('تم الإرسال', 'حُجز المبلغ وبانتظار اعتماد الإدارة.');
        await _load();
      } else {
        Get.snackbar('تعذر الإرسال', 'راجع رصيدك أو أعد المحاولة لاحقاً.');
      }
    } catch (_) {
      Get.snackbar('تعذر الإرسال', 'تعذر الاتصال. لم يُنشأ طلب جديد.');
    } finally {
      if (mounted) setState(() => _submitting = false);
    }
  }

  Future<void> _confirm(Map<String, dynamic> item) async {
    final ulid = item['payout_ulid']?.toString();
    if (ulid == null || ulid.isEmpty) return;
    final ok = await Get.dialog<bool>(AlertDialog(
      title: const Text('تأكيد استلام النقد'),
      content: const Text('أكّد فقط بعد أن تستلم المبلغ فعلياً. لا يمكن التراجع عن التأكيد.'),
      actions: [
        TextButton(onPressed: () => Get.back(result: false), child: const Text('ليس بعد')),
        FilledButton(onPressed: () => Get.back(result: true), child: const Text('استلمت النقد')),
      ],
    ));
    if (ok != true) return;
    try {
      final r = await _repo.confirmPayoutHandover(ulid);
      if (r.statusCode == 200 && r.body is Map && r.body['success'] == true) {
        Get.snackbar('تم التأكيد', 'سُجّل استلامك للنقد.');
        await _load();
      } else {
        Get.snackbar('تعذر التأكيد', 'هذا التسليم غير متاح للتأكيد الآن.');
      }
    } catch (_) {
      Get.snackbar('تعذر التأكيد', 'تحقق من الشبكة ثم أعد المحاولة.');
    }
  }

  @override
  Widget build(BuildContext context) => Directionality(
    textDirection: appTextDirection(),
    child: Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(title: const Text('سحب مستحقات المتجر')),
      body: RefreshIndicator(
        onRefresh: _load,
        child: ListView(padding: const EdgeInsets.all(16), children: [
          const Text('اطلب السحب، ثم تظهر لك تعليمات الاستلام بعد اعتماد الإدارة.',
              style: TextStyle(color: AmialColors.textSecondary)),
          const SizedBox(height: 16),
          TextField(controller: _amount, keyboardType: const TextInputType.numberWithOptions(decimal: true),
              decoration: const InputDecoration(labelText: 'المبلغ', suffixText: 'ر.ي', border: OutlineInputBorder())),
          const SizedBox(height: 10),
          TextField(controller: _note, maxLength: 500,
              decoration: const InputDecoration(labelText: 'ملاحظة للإدارة (اختياري)', border: OutlineInputBorder())),
          const SizedBox(height: 4),
          FilledButton.icon(onPressed: _submitting ? null : _submit,
              icon: _submitting ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2)) : const Icon(Icons.account_balance_outlined),
              label: Text(_submitting ? 'جارٍ الإرسال…' : 'طلب سحب')),
          const SizedBox(height: 22),
          const Text('طلبات السحب', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 16)),
          const SizedBox(height: 8),
          if (_loading) const Padding(padding: EdgeInsets.all(30), child: Center(child: CircularProgressIndicator()))
          else if (_error != null) _Retry(error: _error!, onRetry: _load)
          else if (_items.isEmpty) const Padding(padding: EdgeInsets.all(30), child: Center(child: Text('لا توجد طلبات سحب بعد.')))
          else ..._items.whereType<Map>().map((raw) => _PayoutCard(item: Map<String, dynamic>.from(raw), onConfirm: _confirm)),
        ]),
      ),
    ),
  );
}

class _Retry extends StatelessWidget {
  const _Retry({required this.error, required this.onRetry});
  final String error; final VoidCallback onRetry;
  @override
  Widget build(BuildContext context) => Center(child: Column(children: [Text(error), TextButton(onPressed: onRetry, child: const Text('إعادة المحاولة'))]));
}

class _PayoutCard extends StatelessWidget {
  const _PayoutCard({required this.item, required this.onConfirm});
  final Map<String, dynamic> item;
  final ValueChanged<Map<String, dynamic>> onConfirm;

  @override
  Widget build(BuildContext context) {
    final status = item['status']?.toString() ?? 'pending';
    final instructions = item['collection_instructions']?.toString();
    final canConfirm = status == 'paid' && (item['handover_ulid']?.toString().isNotEmpty ?? false);
    final color = switch (status) { 'approved' => Colors.blue, 'paid' => AmialColors.success, 'rejected' => Colors.red, _ => Colors.orange };
    final label = switch (status) { 'approved' => 'معتمد', 'paid' => 'صُرف بانتظار تأكيد الاستلام', 'rejected' => 'مرفوض', _ => 'بانتظار الاعتماد' };
    return Card(child: Padding(padding: const EdgeInsets.all(14), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      Row(children: [Expanded(child: Text(AmialMoney.yer(item['amount']?.toString() ?? '0'), style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 17))), Text(label, style: TextStyle(color: color, fontWeight: FontWeight.bold))]),
      if (instructions != null && instructions.isNotEmpty) ...[const SizedBox(height: 9), const Text('تعليمات الاستلام', style: TextStyle(fontWeight: FontWeight.w600)), Text(instructions)],
      if (status == 'rejected' && (item['rejection_reason']?.toString().isNotEmpty ?? false)) ...[const SizedBox(height: 8), Text('سبب الرفض: ${item['rejection_reason']}', style: const TextStyle(color: Colors.red))],
      if (canConfirm) ...[const SizedBox(height: 12), OutlinedButton.icon(onPressed: () => onConfirm(item), icon: const Icon(Icons.verified_outlined), label: const Text('تأكيد استلام النقد'))],
    ])));
  }
}
