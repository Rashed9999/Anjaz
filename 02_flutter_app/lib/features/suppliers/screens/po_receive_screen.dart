import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/features/suppliers/controllers/suppliers_controller.dart';
import 'package:amyal_pay/helper/amial_money.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';

/// AMIAL-SUPPLIERS-005 — «استلام بضاعة» (التصميم 57):
/// بنود الأمر: المطلوبة / تم استلام، وإدخال «الكمية الجديدة المستلمة»
/// أو مفتاح «تم الاستلام بالكامل»، ثم تأكيد الاستلام (يحدّث المخزون
/// ومديونية المورد في الخادم). وضع للعرض فقط للأوامر المكتملة.
class PoReceiveScreen extends StatefulWidget {
  const PoReceiveScreen({super.key, required this.poId, this.readOnly = false});

  final int poId;
  final bool readOnly;

  @override
  State<PoReceiveScreen> createState() => _PoReceiveScreenState();
}

class _PoReceiveScreenState extends State<PoReceiveScreen> {
  SuppliersController get c => Get.find<SuppliersController>();

  Map<String, dynamic>? _order;
  bool _loading = true;
  bool _saving = false;

  /// itemId → الكمية الجديدة المستلمة الآن.
  final Map<int, int> _receiving = {};

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _loading = true);
    _order = await c.poShow(widget.poId);
    if (mounted) setState(() => _loading = false);
  }

  List<Map<String, dynamic>> get _items =>
      ((_order?['items'] as List?) ?? [])
          .map((e) => Map<String, dynamic>.from(e as Map))
          .toList();

  int _requested(Map<String, dynamic> it) =>
      (double.tryParse('${it['quantity'] ?? 0}') ?? 0).round();
  int _received(Map<String, dynamic> it) =>
      (double.tryParse('${it['received_quantity'] ?? 0}') ?? 0).round();
  int _remaining(Map<String, dynamic> it) => _requested(it) - _received(it);

  Future<void> _confirm() async {
    final payload = _receiving.entries
        .where((e) => e.value > 0)
        .map((e) => {'item_id': e.key, 'received_quantity': e.value})
        .toList();
    if (payload.isEmpty) {
      _snack('حدد كميات مستلمة أولاً');
      return;
    }
    setState(() => _saving = true);
    final ok = await c.poReceive(widget.poId, payload);
    if (!mounted) return;
    setState(() => _saving = false);
    if (ok) {
      Get.back(result: true);
      Get.snackbar('تم الاستلام', 'حُدّث المخزون ومديونية المورد',
          backgroundColor: const Color(0xFFE3F3E5),
          colorText: const Color(0xFF2E7D32));
    } else {
      _snack(c.lastError.value.isEmpty ? 'فشل الاستلام' : c.lastError.value);
    }
  }

  void _snack(String m) => ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(m), backgroundColor: AmyalColors.red),
      );

  @override
  Widget build(BuildContext context) {
    final ro = widget.readOnly;
    return Scaffold(
      backgroundColor: AmyalColors.background,
      appBar: AppBar(
        backgroundColor: AmyalColors.primary,
        foregroundColor: Colors.white,
        title: Text(ro ? 'تفاصيل أمر الشراء' : 'استلام بضاعة'),
      ),
      bottomNavigationBar: ro
          ? null
          : SafeArea(
              child: Padding(
                padding: const EdgeInsets.all(16),
                child: FilledButton.icon(
                  onPressed: _saving ? null : _confirm,
                  icon: _saving
                      ? const SizedBox(
                          height: 18,
                          width: 18,
                          child: CircularProgressIndicator(
                              strokeWidth: 2, color: Colors.white))
                      : const Icon(Icons.task_alt),
                  label: const Text('تأكيد الاستلام',
                      style: TextStyle(
                          fontSize: 16, fontWeight: FontWeight.w600)),
                  style: FilledButton.styleFrom(
                    backgroundColor: AmyalColors.primary,
                    minimumSize: const Size.fromHeight(54),
                    shape: RoundedRectangleBorder(
                        borderRadius: BorderRadius.circular(16)),
                  ),
                ),
              ),
            ),
      body: _loading
          ? const Center(
              child: CircularProgressIndicator(color: AmyalColors.primary))
          : _order == null
              ? const Center(child: Text('تعذّر تحميل الأمر'))
              : ListView(
                  padding: const EdgeInsets.all(16),
                  children: [
                    // ====== ترويسة الأمر ======
                    Container(
                      padding: const EdgeInsets.all(14),
                      decoration: BoxDecoration(
                        color: Colors.white,
                        borderRadius: BorderRadius.circular(16),
                      ),
                      child: Column(children: [
                        Row(
                          mainAxisAlignment: MainAxisAlignment.spaceBetween,
                          children: [
                            Text('${_order!['po_number']}',
                                textDirection: TextDirection.ltr,
                                style: const TextStyle(
                                    fontWeight: FontWeight.bold,
                                    fontSize: 15)),
                            const Text('رقم أمر الشراء',
                                style: TextStyle(
                                    fontSize: 12,
                                    color: AmyalColors.textSecondary)),
                          ],
                        ),
                        const Divider(height: 20),
                        Row(
                          mainAxisAlignment: MainAxisAlignment.spaceBetween,
                          children: [
                            Text('${_order!['supplier']?['name'] ?? ''}',
                                style: const TextStyle(
                                    fontWeight: FontWeight.w600,
                                    fontSize: 13)),
                            const Text('المورد',
                                style: TextStyle(
                                    fontSize: 12,
                                    color: AmyalColors.textSecondary)),
                          ],
                        ),
                        const SizedBox(height: 6),
                        Row(
                          mainAxisAlignment: MainAxisAlignment.spaceBetween,
                          children: [
                            Text(AmialMoney.yer(_order!['total_amount']),
                                style: const TextStyle(
                                    fontWeight: FontWeight.bold,
                                    color: AmyalColors.primary)),
                            const Text('إجمالي الأمر',
                                style: TextStyle(
                                    fontSize: 12,
                                    color: AmyalColors.textSecondary)),
                          ],
                        ),
                      ]),
                    ),
                    const SizedBox(height: 14),

                    ..._items.map((it) => _itemCard(it)),
                  ],
                ),
    );
  }

  Widget _itemCard(Map<String, dynamic> it) {
    final id = it['id'] as int;
    final remaining = _remaining(it);
    final done = remaining <= 0;
    final current = _receiving[id] ?? 0;

    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: Container(
        padding: const EdgeInsets.all(14),
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(16),
          border: Border.all(
              color: done
                  ? const Color(0xFF2E7D32).withValues(alpha: 0.4)
                  : AmyalColors.border),
        ),
        child: Column(children: [
          Row(children: [
            if (done)
              const Icon(Icons.check_circle,
                  color: Color(0xFF2E7D32), size: 20),
            const Spacer(),
            Expanded(
              flex: 4,
              child: Text('${it['name']}',
                  textAlign: TextAlign.right,
                  style: const TextStyle(
                      fontWeight: FontWeight.bold, fontSize: 14)),
            ),
          ]),
          const SizedBox(height: 6),
          Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
            Text('تم استلام: ${_received(it)}',
                style: const TextStyle(
                    fontSize: 12, color: AmyalColors.textSecondary)),
            Text('المطلوبة: ${_requested(it)}',
                style: const TextStyle(
                    fontSize: 12,
                    fontWeight: FontWeight.bold)),
          ]),
          if (!widget.readOnly && !done) ...[
            const Divider(height: 20),
            Row(children: [
              // إدخال الكمية الجديدة
              SizedBox(
                width: 90,
                child: TextField(
                  controller:
                      TextEditingController(text: current > 0 ? '$current' : '')
                        ..selection = TextSelection.collapsed(
                            offset: (current > 0 ? '$current' : '').length),
                  keyboardType: TextInputType.number,
                  inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                  textAlign: TextAlign.center,
                  style: const TextStyle(
                      fontWeight: FontWeight.bold, fontSize: 15),
                  decoration: const InputDecoration(
                    isDense: true,
                    hintText: '0',
                    border: OutlineInputBorder(),
                  ),
                  onChanged: (v) {
                    final n = int.tryParse(v) ?? 0;
                    _receiving[id] = n > remaining ? remaining : n;
                  },
                  onSubmitted: (_) => setState(() {}),
                ),
              ),
              const SizedBox(width: 8),
              const Text('الكمية الجديدة المستلمة',
                  style: TextStyle(
                      fontSize: 11, color: AmyalColors.textMuted)),
              const Spacer(),
              // تم الاستلام بالكامل
              Row(children: [
                Switch(
                  value: current >= remaining && current > 0,
                  activeThumbColor: AmyalColors.primary,
                  onChanged: (v) => setState(
                      () => _receiving[id] = v ? remaining : 0),
                ),
                const Text('بالكامل',
                    style: TextStyle(
                        fontSize: 11, color: AmyalColors.textSecondary)),
              ]),
            ]),
          ],
        ]),
      ),
    );
  }
}
