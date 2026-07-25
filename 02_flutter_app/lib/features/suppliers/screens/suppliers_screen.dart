import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/features/suppliers/controllers/suppliers_controller.dart';
import 'package:amyal_pay/features/suppliers/screens/supplier_editor_screen.dart';
import 'package:amyal_pay/features/suppliers/screens/purchase_order_create_screen.dart';
import 'package:amyal_pay/features/suppliers/screens/po_receive_screen.dart';
import 'package:amyal_pay/helper/amial_money.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';

/// AMIAL-SUPPLIERS-002 — «إدارة الموردين» (التصميم 68) + «أوامر الشراء» (67):
/// إجماليات (المديونية / أوامر نشطة) + تبويبا الموردين وأوامر الشراء،
/// بطاقات موردين (سداد / طلب شراء) وأوامر بحالات وإجراءات (اعتماد/استلام).
class SuppliersScreen extends StatefulWidget {
  const SuppliersScreen({super.key});

  @override
  State<SuppliersScreen> createState() => _SuppliersScreenState();
}

class _SuppliersScreenState extends State<SuppliersScreen>
    with SingleTickerProviderStateMixin {
  SuppliersController get c => Get.find<SuppliersController>();
  late final TabController _tabs;

  @override
  void initState() {
    super.initState();
    _tabs = TabController(length: 2, vsync: this);
    WidgetsBinding.instance.addPostFrameCallback((_) => c.loadAll());
  }

  @override
  void dispose() {
    _tabs.dispose();
    super.dispose();
  }

  Future<void> _paymentDialog(Map<String, dynamic> s) async {
    final amount = TextEditingController();
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text('سداد للمورد ${s['name']}'),
        content: Column(mainAxisSize: MainAxisSize.min, children: [
          Text('المديونية الحالية: ${AmialMoney.yer(s['current_debt'])}',
              style: const TextStyle(
                  fontSize: 13, color: AmyalColors.textSecondary)),
          const SizedBox(height: 12),
          TextField(
            controller: amount,
            autofocus: true,
            keyboardType: TextInputType.number,
            inputFormatters: [FilteringTextInputFormatter.digitsOnly],
            textAlign: TextAlign.center,
            style: const TextStyle(fontSize: 22, fontWeight: FontWeight.bold),
            decoration: const InputDecoration(
                hintText: '0', suffixText: 'ر.ي'),
          ),
        ]),
        actions: [
          TextButton(
              onPressed: () => Navigator.pop(ctx, false),
              child: const Text('إلغاء')),
          ElevatedButton(
            onPressed: () => Navigator.pop(ctx, true),
            style: ElevatedButton.styleFrom(
                backgroundColor: AmyalColors.primary,
                foregroundColor: Colors.white),
            child: const Text('تسجيل السداد'),
          ),
        ],
      ),
    );
    if (ok != true || !mounted) return;
    final v = amount.text.trim();
    if ((double.tryParse(v) ?? 0) <= 0) return;
    final saved = await c.payment(s['id'] as int, v);
    if (!mounted) return;
    _snack(saved ? 'تم تسجيل السداد' : c.lastError.value, ok: saved);
  }

  void _snack(String m, {bool ok = false}) =>
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
        content: Text(m),
        backgroundColor: ok ? const Color(0xFF2E7D32) : AmyalColors.red,
      ));

  (String, Color, Color) _poBadge(String st) => switch (st) {
        'draft' => ('مسودة', const Color(0xFF5F6B7C), const Color(0xFFEFEFEF)),
        'approved' => ('معتمد', const Color(0xFF1E5A8A), const Color(0xFFE3EEFA)),
        'partially_received' => (
            'مستلم جزئياً',
            const Color(0xFF2E7D32),
            const Color(0xFFE3F3E5)
          ),
        'completed' => ('مكتمل', const Color(0xFF2E7D32), const Color(0xFFE3F3E5)),
        'cancelled' => ('ملغي', AmyalColors.red, const Color(0xFFFDE7E7)),
        _ => (st, AmyalColors.textMuted, const Color(0xFFEFEFEF)),
      };

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmyalColors.background,
      appBar: AppBar(
        title: const Text('إدارة الموردين'),
        bottom: TabBar(
          controller: _tabs,
          indicatorColor: AmyalColors.yellow,
          labelColor: Colors.white,
          unselectedLabelColor: Colors.white70,
          tabs: const [
            Tab(text: 'الموردون'),
            Tab(text: 'أوامر الشراء'),
          ],
        ),
      ),
      floatingActionButton: FloatingActionButton.extended(
        heroTag: 'sup-add',
        backgroundColor: AmyalColors.primary,
        foregroundColor: Colors.white,
        onPressed: () async {
          if (_tabs.index == 0) {
            final saved =
                await Get.to<bool>(() => const SupplierEditorScreen());
            if (saved == true) c.loadAll();
          } else {
            final saved = await Get.to<bool>(
                () => const PurchaseOrderCreateScreen());
            if (saved == true) c.loadAll();
          }
        },
        icon: const Icon(Icons.add),
        label: Text(_tabs.index == 0 ? 'إضافة مورد' : 'أمر شراء'),
      ),
      body: Obx(() {
        if (c.isLoading.value && c.suppliers.isEmpty && c.orders.isEmpty) {
          return const Center(
              child: CircularProgressIndicator(color: AmyalColors.primary));
        }
        return Column(children: [
          // ====== الإجماليات ======
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 14, 16, 6),
            child: Row(children: [
              Expanded(
                child: _totalCard(
                  'إجمالي المديونية',
                  AmialMoney.yer(c.totals.value['total_debt'] ?? 0),
                  Icons.account_balance_wallet_outlined,
                  const Color(0xFF2E7D32),
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: _totalCard(
                  'أوامر شراء نشطة',
                  '${c.totals.value['active_po_count'] ?? 0} طلب',
                  Icons.shopping_cart_outlined,
                  AmyalColors.yellowDark,
                ),
              ),
            ]),
          ),
          Expanded(
            child: TabBarView(
              controller: _tabs,
              children: [_suppliersTab(), _ordersTab()],
            ),
          ),
        ]);
      }),
    );
  }

  Widget _totalCard(String label, String value, IconData icon, Color color) {
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: color.withValues(alpha: 0.3)),
      ),
      child: Row(children: [
        Container(
          height: 40,
          width: 40,
          decoration: BoxDecoration(
            color: color.withValues(alpha: 0.12),
            borderRadius: BorderRadius.circular(12),
          ),
          child: Icon(icon, color: color, size: 20),
        ),
        const Spacer(),
        Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
          Text(label,
              style: const TextStyle(
                  fontSize: 10, color: AmyalColors.textMuted)),
          FittedBox(
            child: Text(value,
                style: TextStyle(
                    fontSize: 15,
                    fontWeight: FontWeight.bold,
                    color: color)),
          ),
        ]),
      ]),
    );
  }

  Widget _suppliersTab() {
    if (c.suppliers.isEmpty) {
      return const Center(
          child: Text('لا موردون بعد — أضف أول مورد',
              style: TextStyle(color: AmyalColors.textMuted)));
    }
    return RefreshIndicator(
      onRefresh: () => c.loadAll(),
      color: AmyalColors.primary,
      child: ListView.separated(
        padding: const EdgeInsets.fromLTRB(16, 8, 16, 90),
        itemCount: c.suppliers.length,
        separatorBuilder: (_, _) => const SizedBox(height: 10),
        itemBuilder: (_, i) {
          final s = c.suppliers[i];
          final debt = double.tryParse('${s['current_debt'] ?? 0}') ?? 0;
          return Container(
            padding: const EdgeInsets.all(14),
            decoration: BoxDecoration(
              color: Colors.white,
              borderRadius: BorderRadius.circular(16),
            ),
            child: Column(children: [
              Row(children: [
                CircleAvatar(
                  radius: 20,
                  backgroundColor: AmyalColors.primary.withValues(alpha: 0.1),
                  child: Text('${s['name']}'.isNotEmpty ? '${s['name']}'[0] : '؟',
                      style: const TextStyle(
                          color: AmyalColors.primary,
                          fontWeight: FontWeight.bold)),
                ),
                const Spacer(),
                Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
                  Text('${s['name']}',
                      style: const TextStyle(
                          fontWeight: FontWeight.bold, fontSize: 15)),
                  if ('${s['contact_person'] ?? ''}'.isNotEmpty)
                    Text('${s['contact_person']}',
                        style: const TextStyle(
                            fontSize: 11, color: AmyalColors.textMuted)),
                ]),
              ]),
              const SizedBox(height: 10),
              Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
                Text(AmialMoney.yer(debt),
                    style: TextStyle(
                        fontWeight: FontWeight.bold,
                        fontSize: 16,
                        color: debt > 0
                            ? AmyalColors.red
                            : const Color(0xFF2E7D32))),
                const Text('المديونية الحالية',
                    style: TextStyle(
                        fontSize: 12, color: AmyalColors.textSecondary)),
              ]),
              const SizedBox(height: 10),
              Row(children: [
                Expanded(
                  child: FilledButton.icon(
                    onPressed: () async {
                      final saved = await Get.to<bool>(() =>
                          PurchaseOrderCreateScreen(
                              supplierId: s['id'] as int,
                              supplierName: '${s['name']}'));
                      if (saved == true) c.loadAll();
                    },
                    icon: const Icon(Icons.add_shopping_cart, size: 16),
                    label: const Text('طلب شراء'),
                    style: FilledButton.styleFrom(
                        backgroundColor: AmyalColors.primary,
                        minimumSize: const Size(0, 42)),
                  ),
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: OutlinedButton.icon(
                    onPressed: debt <= 0 ? null : () => _paymentDialog(s),
                    icon: const Icon(Icons.payments_outlined, size: 16),
                    label: const Text('سداد'),
                    style: OutlinedButton.styleFrom(
                        foregroundColor: AmyalColors.yellowDark,
                        side: const BorderSide(color: AmyalColors.yellowDark),
                        minimumSize: const Size(0, 42)),
                  ),
                ),
              ]),
            ]),
          );
        },
      ),
    );
  }

  Widget _ordersTab() {
    if (c.orders.isEmpty) {
      return const Center(
          child: Text('لا أوامر شراء بعد',
              style: TextStyle(color: AmyalColors.textMuted)));
    }
    return RefreshIndicator(
      onRefresh: () => c.loadAll(),
      color: AmyalColors.primary,
      child: ListView.separated(
        padding: const EdgeInsets.fromLTRB(16, 8, 16, 90),
        itemCount: c.orders.length,
        separatorBuilder: (_, _) => const SizedBox(height: 10),
        itemBuilder: (_, i) {
          final o = c.orders[i];
          final st = '${o['status']}';
          final (label, fg, bg) = _poBadge(st);
          return Container(
            padding: const EdgeInsets.all(14),
            decoration: BoxDecoration(
              color: Colors.white,
              borderRadius: BorderRadius.circular(16),
            ),
            child: Column(children: [
              Row(children: [
                Container(
                  padding: const EdgeInsets.symmetric(
                      horizontal: 10, vertical: 3),
                  decoration: BoxDecoration(
                    color: bg,
                    borderRadius: BorderRadius.circular(10),
                  ),
                  child: Text(label,
                      style: TextStyle(
                          fontSize: 11,
                          color: fg,
                          fontWeight: FontWeight.w600)),
                ),
                const Spacer(),
                Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
                  Text('${o['po_number']}',
                      textDirection: TextDirection.ltr,
                      style: const TextStyle(
                          fontWeight: FontWeight.bold, fontSize: 14)),
                  Text('${o['supplier']?['name'] ?? ''}',
                      style: const TextStyle(
                          fontSize: 12, color: AmyalColors.textMuted)),
                ]),
              ]),
              const SizedBox(height: 8),
              Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
                Text(AmialMoney.yer(o['total_amount']),
                    style: const TextStyle(
                        fontWeight: FontWeight.bold,
                        color: AmyalColors.primary)),
                Text('${o['created_at'] ?? ''}'.split('T').first,
                    style: const TextStyle(
                        fontSize: 11, color: AmyalColors.textMuted)),
              ]),
              const SizedBox(height: 10),
              Row(children: [
                if (st == 'draft') ...[
                  Expanded(
                    child: FilledButton.icon(
                      onPressed: () async {
                        final ok = await c.poApprove(o['id'] as int);
                        if (!mounted) return;
                        _snack(ok ? 'تم الاعتماد' : c.lastError.value, ok: ok);
                      },
                      icon: const Icon(Icons.check_circle_outline, size: 16),
                      label: const Text('اعتماد'),
                      style: FilledButton.styleFrom(
                          backgroundColor: AmyalColors.primary,
                          minimumSize: const Size(0, 40)),
                    ),
                  ),
                  const SizedBox(width: 8),
                  Expanded(
                    child: OutlinedButton(
                      onPressed: () async {
                        final ok = await c.poCancel(o['id'] as int);
                        if (!mounted) return;
                        _snack(ok ? 'أُلغي الأمر' : c.lastError.value, ok: ok);
                      },
                      style: OutlinedButton.styleFrom(
                          foregroundColor: AmyalColors.red,
                          side: const BorderSide(color: AmyalColors.red),
                          minimumSize: const Size(0, 40)),
                      child: const Text('إلغاء'),
                    ),
                  ),
                ] else if (st == 'approved' || st == 'partially_received')
                  Expanded(
                    child: FilledButton.icon(
                      onPressed: () async {
                        final saved = await Get.to<bool>(
                            () => PoReceiveScreen(poId: o['id'] as int));
                        if (saved == true) c.loadAll();
                      },
                      icon: const Icon(Icons.move_to_inbox_outlined, size: 16),
                      label: Text(st == 'approved'
                          ? 'استلام البضاعة'
                          : 'استكمال الاستلام'),
                      style: FilledButton.styleFrom(
                          backgroundColor: AmyalColors.yellowDark,
                          minimumSize: const Size(0, 40)),
                    ),
                  )
                else
                  Expanded(
                    child: OutlinedButton.icon(
                      onPressed: () async {
                        final saved = await Get.to<bool>(
                            () => PoReceiveScreen(
                                poId: o['id'] as int, readOnly: true));
                        if (saved == true) c.loadAll();
                      },
                      icon: const Icon(Icons.receipt_long_outlined, size: 16),
                      label: const Text('عرض التفاصيل'),
                      style: OutlinedButton.styleFrom(
                          foregroundColor: AmyalColors.primary,
                          side: const BorderSide(color: AmyalColors.border),
                          minimumSize: const Size(0, 40)),
                    ),
                  ),
              ]),
            ]),
          );
        },
      ),
    );
  }
}
