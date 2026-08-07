import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amial_pay/features/withdraw/controllers/customer_withdraw_controller.dart';
import 'package:amial_pay/helper/amial_money.dart';
import 'package:amial_pay/theme/amial_colors.dart';

/// AMIAL-WD-HISTORY-001 — «سجل طلبات السحب»:
/// بحث برقم العملية + تصفية (الكل/قيد الانتظار/المكتملة/الملغية) + بطاقات
/// بحالة ملوّنة، حسب تصميم أميال.
class WithdrawHistoryScreen extends StatefulWidget {
  const WithdrawHistoryScreen({super.key});

  @override
  State<WithdrawHistoryScreen> createState() => _WithdrawHistoryScreenState();
}

class _WithdrawHistoryScreenState extends State<WithdrawHistoryScreen> {
  late final CustomerWithdrawController c;
  final _search = TextEditingController();
  String _filter = 'all';

  static const _filters = [
    ('all', 'الكل'),
    ('pending', 'قيد الانتظار'),
    ('completed', 'المكتملة'),
    ('cancelled', 'الملغية'),
  ];

  @override
  void initState() {
    super.initState();
    c = Get.find<CustomerWithdrawController>();
    c.loadHistory(status: 'all');
  }

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  List<Map<String, dynamic>> get _visible {
    final q = _search.text.trim().toUpperCase();
    return c.historyRequests.where((r) {
      final st = '${r['status']}';
      if (_filter != 'all') {
        // «الملغية» تشمل المنتهية الصلاحية أيضاً
        if (_filter == 'cancelled' && !(st == 'cancelled' || st == 'expired')) {
          return false;
        }
        if (_filter != 'cancelled' && st != _filter) return false;
      }
      if (q.isNotEmpty && !'${r['op_code']}'.toUpperCase().contains(q)) {
        return false;
      }
      return true;
    }).toList();
  }

  (String, Color, Color) _statusChip(String st) {
    switch (st) {
      case 'completed':
        return ('مكتمل', const Color(0xFF2E7D32), const Color(0xFFE3F3E5));
      case 'pending':
        return ('قيد الانتظار', const Color(0xFFB8860B), const Color(0xFFFBF3D9));
      case 'cancelled':
        return ('ملغي', AmialColors.red, const Color(0xFFFDE7E7));
      case 'expired':
        return ('منتهي', AmialColors.textMuted, const Color(0xFFEFEFEF));
      default:
        return (st, AmialColors.textMuted, const Color(0xFFEFEFEF));
    }
  }

  String _fmtDate(dynamic raw) {
    try {
      final d = DateTime.parse('$raw').toLocal();
      final h12 = d.hour % 12 == 0 ? 12 : d.hour % 12;
      final ampm = d.hour < 12 ? 'ص' : 'م';
      return '${d.year}/${d.month}/${d.day} • '
          '$h12:${d.minute.toString().padLeft(2, '0')} $ampm';
    } catch (_) {
      return '';
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(
        title: const Text('سجل طلبات السحب'),
      ),
      body: Obx(() {
        final items = _visible;
        return RefreshIndicator(
          onRefresh: () => c.loadHistory(status: 'all'),
          color: AmialColors.primary,
          child: Column(children: [
            // ====== البحث ======
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 16, 16, 8),
              child: TextField(
                controller: _search,
                onChanged: (_) => setState(() {}),
                decoration: InputDecoration(
                  hintText: 'ابحث عن رقم العملية...',
                  hintStyle: const TextStyle(fontSize: 13),
                  prefixIcon: const Icon(Icons.search, size: 20),
                  filled: true,
                  fillColor: Colors.white,
                  contentPadding: const EdgeInsets.symmetric(vertical: 0),
                  border: OutlineInputBorder(
                    borderRadius: BorderRadius.circular(14),
                    borderSide: BorderSide.none,
                  ),
                ),
              ),
            ),

            // ====== التصفية ======
            SizedBox(
              height: 44,
              child: ListView(
                scrollDirection: Axis.horizontal,
                padding: const EdgeInsets.symmetric(horizontal: 16),
                children: _filters.map((f) {
                  final selected = _filter == f.$1;
                  return Padding(
                    padding: const EdgeInsets.only(left: 8),
                    child: ChoiceChip(
                      label: Text(f.$2, style: const TextStyle(fontSize: 12)),
                      selected: selected,
                      selectedColor: AmialColors.primary,
                      backgroundColor: Colors.white,
                      labelStyle: TextStyle(
                          color: selected ? Colors.white : AmialColors.primary),
                      onSelected: (_) => setState(() => _filter = f.$1),
                    ),
                  );
                }).toList(),
              ),
            ),

            // ====== القائمة ======
            Expanded(
              child: c.isLoadingHistory.value
                  ? const Center(
                      child:
                          CircularProgressIndicator(color: AmialColors.primary))
                  : items.isEmpty
                      ? ListView(children: const [
                          SizedBox(height: 120),
                          Center(
                              child: Text('لا توجد طلبات سحب',
                                  style:
                                      TextStyle(color: AmialColors.textMuted))),
                        ])
                      : ListView.separated(
                          padding: const EdgeInsets.all(16),
                          itemCount: items.length,
                          separatorBuilder: (_, _) => const SizedBox(height: 10),
                          itemBuilder: (_, i) => _card(items[i]),
                        ),
            ),
          ]),
        );
      }),
    );
  }

  Widget _card(Map<String, dynamic> r) {
    final st = '${r['status']}';
    final (label, fg, bg) = _statusChip(st);
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(14),
      ),
      child: Column(children: [
        Row(children: [
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 3),
            decoration: BoxDecoration(
              color: bg,
              borderRadius: BorderRadius.circular(10),
            ),
            child: Text(label,
                style: TextStyle(
                    fontSize: 11, color: fg, fontWeight: FontWeight.w600)),
          ),
          const Spacer(),
          Text('#WD-${r['op_code'] ?? r['id']}',
              style: const TextStyle(
                  fontSize: 12, color: AmialColors.textMuted)),
          const SizedBox(width: 10),
          CircleAvatar(
            radius: 18,
            backgroundColor: bg,
            child: Icon(
                st == 'completed'
                    ? Icons.account_balance_outlined
                    : st == 'pending'
                        ? Icons.storefront_outlined
                        : Icons.money_off_csred_outlined,
                color: fg,
                size: 18),
          ),
        ]),
        const SizedBox(height: 10),
        Row(children: [
          Text(AmialMoney.yer(r['amount']),
              style: TextStyle(
                fontSize: 18,
                fontWeight: FontWeight.bold,
                color: st == 'cancelled' || st == 'expired'
                    ? AmialColors.textMuted
                    : AmialColors.primary,
                decoration: st == 'cancelled' || st == 'expired'
                    ? TextDecoration.lineThrough
                    : null,
              )),
          const Spacer(),
          Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
            const Text('التاريخ والوقت',
                style: TextStyle(fontSize: 10, color: AmialColors.textMuted)),
            Text(_fmtDate(r['created_at'] ?? r['expires_at']),
                style: const TextStyle(fontSize: 12)),
          ]),
        ]),
      ]),
    );
  }
}
