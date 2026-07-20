import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/data/api/api_client.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';
import 'package:amyal_pay/common/widgets/async_state_view.dart';

/// AMIAL-INSTALLMENTS-001 — «أقساطي» (جهة العميل).
///
/// العميل يرى عقود التقسيط المستحقّة عليه ويسدّد الأقساط من محفظته مباشرة.
class MyInstallmentsScreen extends StatefulWidget {
  const MyInstallmentsScreen({super.key});

  @override
  State<MyInstallmentsScreen> createState() => _MyInstallmentsScreenState();
}

class _MyInstallmentsScreenState extends State<MyInstallmentsScreen> {
  final _api = Get.find<ApiClient>();
  bool _loading = true;
  String? _error;
  List<Map<String, dynamic>> _contracts = [];

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() { _loading = true; _error = null; });
    try {
      final r = await _api.getData('/api/v1/amial/me/installments');
      if (r.statusCode == 200 && r.body is Map) {
        _contracts = (((r.body['meta'] ?? {})['contracts'] ?? []) as List)
            .map((e) => Map<String, dynamic>.from(e as Map)).toList();
      } else {
        _error = 'تعذّر تحميل الأقساط';
      }
    } catch (_) {
      _error = 'تعذّر الاتصال — تحقّق من الشبكة';
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  void _snack(String m, {bool ok = false}) => ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(content: Text(m), backgroundColor: ok ? const Color(0xFF2E7D32) : AmyalColors.red));

  Future<void> _pay(Map<String, dynamic> c) async {
    final amountCtrl = TextEditingController(text: '${c['monthly_amount'] ?? ''}');
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('سداد قسط'),
        content: Column(mainAxisSize: MainAxisSize.min, children: [
          Text('المتبقّي: ${c['remaining']} ر.ي', style: const TextStyle(fontWeight: FontWeight.bold)),
          const SizedBox(height: 12),
          TextField(controller: amountCtrl, keyboardType: TextInputType.number, textAlign: TextAlign.center,
              decoration: const InputDecoration(labelText: 'المبلغ', suffixText: 'ر.ي', border: OutlineInputBorder())),
        ]),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('إلغاء')),
          FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('سداد')),
        ],
      ),
    );
    if (ok != true) return;
    final r = await _api.postData('/api/v1/amial/me/installments/${c['id']}/pay',
        {'amount': double.tryParse(amountCtrl.text.trim()) ?? 0});
    if (r.statusCode == 200) {
      _snack('تم السداد من محفظتك', ok: true);
      _load();
    } else if (r.statusCode == 402) {
      _snack('رصيد المحفظة غير كافٍ');
    } else {
      _snack((r.body is Map ? r.body['message']?.toString() : null) ?? 'تعذّر السداد');
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmyalColors.background,
      appBar: AppBar(title: const Text('أقساطي'),
          backgroundColor: AmyalColors.primary, foregroundColor: Colors.white),
      body: AsyncStateView(
        loading: _loading,
        error: _error,
        isEmpty: _contracts.isEmpty,
        emptyText: 'لا توجد أقساط عليك',
        emptyIcon: Icons.receipt_long,
        onRetry: _load,
        child: ListView(padding: const EdgeInsets.all(12), children: _contracts.map(_card).toList()),
      ),
    );
  }

  Widget _card(Map<String, dynamic> c) {
    final total = double.tryParse('${c['total_payable']}') ?? 0;
    final paid = double.tryParse('${c['paid_amount']}') ?? 0;
    final progress = total > 0 ? (paid / total).clamp(0.0, 1.0) : 0.0;
    final done = c['status'] == 'completed';
    return Container(
      margin: const EdgeInsets.only(bottom: 10),
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(14)),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Row(children: [
          Expanded(child: Text('${c['item_name']?.toString().isNotEmpty == true ? c['item_name'] : 'عقد #${c['id']}'}',
              style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 15))),
          Text(done ? 'مكتمل' : '${c['months']} أشهر',
              style: TextStyle(color: done ? const Color(0xFF2E7D32) : AmyalColors.textSecondary,
                  fontWeight: FontWeight.bold, fontSize: 12)),
        ]),
        const SizedBox(height: 10),
        ClipRRect(borderRadius: BorderRadius.circular(6),
            child: LinearProgressIndicator(value: progress, minHeight: 8,
                backgroundColor: AmyalColors.background, color: AmyalColors.primary)),
        const SizedBox(height: 8),
        Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
          Text('سُدّد: $paid ر.ي', style: const TextStyle(fontSize: 12)),
          Text('متبقٍّ: ${c['remaining']} ر.ي',
              style: const TextStyle(fontSize: 12, fontWeight: FontWeight.bold, color: AmyalColors.primary)),
        ]),
        if (!done) ...[
          const SizedBox(height: 10),
          FilledButton.icon(onPressed: () => _pay(c), icon: const Icon(Icons.account_balance_wallet),
              label: Text('سداد قسط (${c['monthly_amount']} ر.ي)'),
              style: FilledButton.styleFrom(backgroundColor: AmyalColors.primary, minimumSize: const Size.fromHeight(46))),
        ],
      ]),
    );
  }
}
