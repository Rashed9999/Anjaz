import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:get/get.dart';
import 'package:amial_pay/features/suppliers/controllers/suppliers_controller.dart';
import 'package:amial_pay/features/suppliers/screens/supplier_editor_screen.dart';
import 'package:amial_pay/features/suppliers/screens/purchase_order_create_screen.dart';
import 'package:amial_pay/features/suppliers/screens/po_receive_screen.dart';
import 'package:amial_pay/helper/amial_money.dart';
import 'package:amial_pay/theme/amial_colors.dart';

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
    // AMIAL-DAILY-MOVEMENT-001 — تبويبٌ ثالث: مرتجعاتُ الشراء.
    _tabs = TabController(length: 3, vsync: this);
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
                  fontSize: 13, color: AmialColors.textSecondary)),
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
                backgroundColor: AmialColors.primary,
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
        backgroundColor: ok ? AmialColors.success : AmialColors.red,
      ));

  (String, Color, Color) _poBadge(String st) => switch (st) {
        'draft' => ('مسودة', const Color(0xFF5F6B7C), const Color(0xFFEFEFEF)),
        'approved' => ('معتمد', const Color(0xFF1E5A8A), const Color(0xFFE3EEFA)),
        'partially_received' => (
            'مستلم جزئياً',
            AmialColors.success,
            AmialColors.successSurface
          ),
        'completed' => ('مكتمل', AmialColors.success, AmialColors.successSurface),
        'cancelled' => ('ملغي', AmialColors.red, AmialColors.dangerSurface),
        _ => (st, AmialColors.textMuted, const Color(0xFFEFEFEF)),
      };

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(
        title: const Text('إدارة الموردين'),
        bottom: TabBar(
          controller: _tabs,
          indicatorColor: AmialColors.yellow,
          labelColor: Colors.white,
          unselectedLabelColor: Colors.white70,
          tabs: const [
            Tab(text: 'الموردون'),
            Tab(text: 'أوامر الشراء'),
            Tab(text: 'المرتجعات'),
          ],
        ),
      ),
      floatingActionButton: FloatingActionButton.extended(
        heroTag: 'sup-add',
        backgroundColor: AmialColors.primary,
        foregroundColor: Colors.white,
        onPressed: () async {
          // **ولا يُنشأ مرتجعٌ من فراغ**: هو ردُّ بضاعةٍ استُلمت، وبابُه
          // شاشةُ الاستلام نفسُها. وزرُّ «إضافة» هنا يفتح أمراً بلا سياق.
          if (_tabs.index == 2) {
            Get.snackbar('من أين يُسجَّل المرتجع؟',
                'افتح أمر الشراء الذي استُلمت منه البضاعة، ثم «ردّ بضاعة إلى المورد».',
                backgroundColor: AmialColors.warningSurface,
                colorText: AmialColors.warning);
            return;
          }
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
        label: Text(switch (_tabs.index) {
          0 => 'إضافة مورد',
          2 => 'كيف أسجّل مرتجعاً؟',
          _ => 'أمر شراء',
        }),
      ),
      body: Obx(() {
        if (c.isLoading.value && c.suppliers.isEmpty && c.orders.isEmpty) {
          return const Center(
              child: CircularProgressIndicator(color: AmialColors.primary));
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
                  AmialColors.success,
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: _totalCard(
                  'أوامر شراء نشطة',
                  '${c.totals.value['active_po_count'] ?? 0} طلب',
                  Icons.shopping_cart_outlined,
                  AmialColors.yellowDark,
                ),
              ),
            ]),
          ),
          Expanded(
            child: TabBarView(
              controller: _tabs,
              children: [_suppliersTab(), _ordersTab(), _returnsTab()],
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
                  fontSize: 10, color: AmialColors.textMuted)),
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
              style: TextStyle(color: AmialColors.textMuted)));
    }
    return RefreshIndicator(
      onRefresh: () => c.loadAll(),
      color: AmialColors.primary,
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
                  backgroundColor: AmialColors.primary.withValues(alpha: 0.1),
                  child: Text('${s['name']}'.isNotEmpty ? '${s['name']}'[0] : '؟',
                      style: const TextStyle(
                          color: AmialColors.primary,
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
                            fontSize: 11, color: AmialColors.textMuted)),
                ]),
              ]),
              const SizedBox(height: 10),
              Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
                Text(AmialMoney.yer(debt),
                    style: TextStyle(
                        fontWeight: FontWeight.bold,
                        fontSize: 16,
                        color: debt > 0
                            ? AmialColors.red
                            : AmialColors.success)),
                const Text('المديونية الحالية',
                    style: TextStyle(
                        fontSize: 12, color: AmialColors.textSecondary)),
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
                        backgroundColor: AmialColors.primary,
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
                        foregroundColor: AmialColors.yellowDark,
                        side: const BorderSide(color: AmialColors.yellowDark),
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
              style: TextStyle(color: AmialColors.textMuted)));
    }
    return RefreshIndicator(
      onRefresh: () => c.loadAll(),
      color: AmialColors.primary,
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
                          fontSize: 12, color: AmialColors.textMuted)),
                ]),
              ]),
              const SizedBox(height: 8),
              Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
                Text(AmialMoney.yer(o['total_amount']),
                    style: const TextStyle(
                        fontWeight: FontWeight.bold,
                        color: AmialColors.primary)),
                Text('${o['created_at'] ?? ''}'.split('T').first,
                    style: const TextStyle(
                        fontSize: 11, color: AmialColors.textMuted)),
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
                          backgroundColor: AmialColors.primary,
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
                          foregroundColor: AmialColors.red,
                          side: const BorderSide(color: AmialColors.red),
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
                          backgroundColor: AmialColors.yellowDark,
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
                          foregroundColor: AmialColors.primary,
                          side: const BorderSide(color: AmialColors.border),
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

  // ══════════════════════════════════════════════════════════════════
  //  AMIAL-DAILY-MOVEMENT-001 — مرتجعاتُ الشراء
  // ══════════════════════════════════════════════════════════════════

  /// **الطلبُ يُنشأ ثمّ يُعتمَد — وهنا موضعُ الاعتماد.**
  ///
  /// وبلا هذه القائمة يبقى المرتجعُ `pending` أبداً: البضاعةُ لا تخرج
  /// والدينُ لا ينقص، **ولا رسالةَ تقول لماذا**. (القاعدة الثانية عشرة:
  /// مسارٌ بلا شاشةٍ ليس مبنيّاً.)
  Widget _returnsTab() {
    return FutureBuilder<List<Map<String, dynamic>>>(
      future: c.prList(),
      builder: (context, snap) {
        if (snap.connectionState != ConnectionState.done) {
          return const Center(
              child: CircularProgressIndicator(color: AmialColors.primary));
        }

        final rows = snap.data ?? const <Map<String, dynamic>>[];
        if (rows.isEmpty) {
          return const Center(
            child: Padding(
              padding: EdgeInsets.all(24),
              child: Text(
                  'لا مرتجعات شراء.\n\nتُسجَّل من أمر الشراء الذي استُلمت منه '
                  'البضاعة: افتحه ثم «ردّ بضاعة إلى المورد».',
                  textAlign: TextAlign.center,
                  style: TextStyle(color: AmialColors.textMuted)),
            ),
          );
        }

        return ListView.separated(
          padding: const EdgeInsets.fromLTRB(16, 8, 16, 90),
          itemCount: rows.length,
          separatorBuilder: (_, _) => const SizedBox(height: 10),
          itemBuilder: (_, i) => _returnCard(rows[i]),
        );
      },
    );
  }

  Widget _returnCard(Map<String, dynamic> r) {
    final status = '${r['status']}';
    final pending = status == 'pending';

    final (label, fg, bg) = switch (status) {
      'pending' => ('بانتظار الاعتماد', AmialColors.yellowDark,
          AmialColors.warningSurface),
      'approved' => ('معتمد', AmialColors.success, AmialColors.successSurface),
      'rejected' => ('مرفوض', AmialColors.red, AmialColors.dangerSurface),
      _ => (status, AmialColors.textMuted, const Color(0xFFEFEFEF)),
    };

    final cash = r['settlement_type'] == 'cash_refund';

    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: AmialColors.border),
      ),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(children: [
          Expanded(
            child: Text('${r['supplier']?['name'] ?? 'مورد'}',
                style: const TextStyle(
                    fontWeight: FontWeight.bold, fontSize: 14)),
          ),
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 4),
            decoration: BoxDecoration(
                color: bg, borderRadius: BorderRadius.circular(20)),
            child: Text(label,
                style: TextStyle(
                    fontSize: 11, fontWeight: FontWeight.w800, color: fg)),
          ),
        ]),
        const SizedBox(height: 8),
        Row(children: [
          Text(AmialMoney.yer(r['total_amount']),
              style: const TextStyle(
                  fontWeight: FontWeight.w900,
                  fontSize: 15,
                  color: AmialColors.red)),
          const SizedBox(width: 10),
          // **ووجهُ المال يُقرأ على البطاقة** — فمن يعتمد يعرف ما سيقع.
          Text(cash ? 'استرداد نقدي' : 'خصم من الدين',
              style: const TextStyle(
                  fontSize: 11, color: AmialColors.textSecondary)),
        ]),
        if ('${r['reason'] ?? ''}'.isNotEmpty) ...[
          const SizedBox(height: 4),
          Text('${r['reason']}',
              style: const TextStyle(
                  fontSize: 11, color: AmialColors.textMuted)),
        ],
        Text('${(r['items'] as List?)?.length ?? 0} صنف',
            style: const TextStyle(
                fontSize: 11, color: AmialColors.textMuted)),
        if (pending) ...[
          const Divider(height: 20),
          Row(children: [
            Expanded(
              child: FilledButton.icon(
                onPressed: () async {
                  final ok = await c.prApprove(r['id'] as int);
                  if (!mounted) return;
                  if (ok) {
                    setState(() {});
                    Get.snackbar('اعتُمد المرتجع',
                        'خرجت البضاعة من المخزون وتحرّك حساب المورد',
                        backgroundColor: AmialColors.successSurface,
                        colorText: AmialColors.success);
                  } else {
                    Get.snackbar('تعذّر الاعتماد', c.lastError.value,
                        backgroundColor: AmialColors.dangerSurface,
                        colorText: AmialColors.red);
                  }
                },
                icon: const Icon(Icons.task_alt, size: 16),
                label: const Text('اعتماد'),
                style: FilledButton.styleFrom(
                    backgroundColor: AmialColors.success,
                    minimumSize: const Size(0, 42)),
              ),
            ),
            const SizedBox(width: 8),
            Expanded(
              child: OutlinedButton.icon(
                onPressed: () => _rejectDialog(r['id'] as int),
                icon: const Icon(Icons.close, size: 16),
                label: const Text('رفض'),
                style: OutlinedButton.styleFrom(
                    foregroundColor: AmialColors.red,
                    side: const BorderSide(color: AmialColors.red),
                    minimumSize: const Size(0, 42)),
              ),
            ),
          ]),
        ],
      ]),
    );
  }

  Future<void> _rejectDialog(int id) async {
    final ctl = TextEditingController();

    final confirmed = await Get.dialog<bool>(AlertDialog(
      title: const Text('رفض المرتجع'),
      content: TextField(
        controller: ctl,
        maxLines: 2,
        decoration: const InputDecoration(
          labelText: 'السبب (5 أحرف على الأقل)',
          border: OutlineInputBorder(),
        ),
      ),
      actions: [
        TextButton(onPressed: () => Get.back(result: false),
            child: const Text('إلغاء')),
        FilledButton(onPressed: () => Get.back(result: true),
            child: const Text('رفض')),
      ],
    ));

    if (confirmed != true) return;

    if (ctl.text.trim().length < 5) {
      Get.snackbar('السبب مطلوب', 'اكتب سبباً واضحاً — يُحفظ مع المرتجع',
          backgroundColor: AmialColors.dangerSurface,
          colorText: AmialColors.red);
      return;
    }

    final ok = await c.prReject(id, ctl.text.trim());
    if (!mounted) return;
    if (ok) setState(() {});
  }
}
