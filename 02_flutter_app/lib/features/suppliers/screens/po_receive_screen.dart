import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:get/get.dart';
import 'package:amial_pay/features/suppliers/controllers/suppliers_controller.dart';
import 'package:amial_pay/helper/amial_money.dart';
import 'package:amial_pay/features/suppliers/screens/purchase_return_screen.dart';
import 'package:amial_pay/theme/amial_colors.dart';

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

  /// AMIAL-DAILY-MOVEMENT-001 — **ما دُفع نقداً لحظةَ الاستلام.**
  ///
  /// كان كلُّ استلامٍ يرفع دينَ المورد بلا استثناء، فمن اشترى نقداً من
  /// مورّدٍ عابرٍ لا يجد إلّا خمسَ خطوات — أو لا يسجّل الشراءَ إطلاقاً،
  /// **فيغيب الشراءُ النقديُّ عن الحركة اليوميّة كلِّها**.
  final TextEditingController _paidNow = TextEditingController();

  @override
  void dispose() {
    _paidNow.dispose();
    super.dispose();
  }

  /// قيمةُ ما يُستلَم الآن — **تُحسب من البنود لا من إجمالي الأمر**:
  /// الاستلامُ الجزئيُّ أقلُّ من الأمر، وعرضُ إجمالي الأمر هنا يُغري
  /// بدفع ثمن ما لم يصل.
  String get _valueNow {
    var total = 0.0;
    for (final it in _items) {
      final n = _receiving[it['id'] as int] ?? 0;
      if (n <= 0) continue;
      total += n * (double.tryParse('${it['unit_cost'] ?? 0}') ?? 0);
    }
    return total.toStringAsFixed(2);
  }

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
    final paid = _paidNow.text.trim();

    // **ولا يُرسَل ما يتجاوز قيمةَ المستلَم** — الخادمُ يرفضه، والرفضُ
    // بعد ضغطةٍ أسوأُ من منعٍ قبلها.
    if (paid.isNotEmpty) {
      final p = double.tryParse(paid) ?? 0;
      if (p > (double.tryParse(_valueNow) ?? 0)) {
        _snack('المدفوع نقداً أكبر من قيمة المستلَم '
            '(${AmialMoney.yer(_valueNow)}). لسداد دَينٍ سابق استعمل «سداد دفعة».');
        return;
      }
    }

    setState(() => _saving = true);
    final ok = await c.poReceive(widget.poId, payload,
        paidNow: paid.isEmpty ? null : paid);
    if (!mounted) return;
    setState(() => _saving = false);
    if (ok) {
      Get.back(result: true);
      Get.snackbar('تم الاستلام',
          paid.isEmpty
              ? 'حُدّث المخزون ومديونية المورد'
              : 'حُدّث المخزون، ودُفع ${AmialMoney.yer(paid)} نقداً',
          backgroundColor: AmialColors.successSurface,
          colorText: AmialColors.success);
    } else {
      _snack(c.lastError.value.isEmpty ? 'فشل الاستلام' : c.lastError.value);
    }
  }

  void _snack(String m) => ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(m), backgroundColor: AmialColors.red),
      );

  @override
  Widget build(BuildContext context) {
    final ro = widget.readOnly;
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(
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
                    backgroundColor: AmialColors.primary,
                    minimumSize: const Size.fromHeight(54),
                    shape: RoundedRectangleBorder(
                        borderRadius: BorderRadius.circular(16)),
                  ),
                ),
              ),
            ),
      body: _loading
          ? const Center(
              child: CircularProgressIndicator(color: AmialColors.primary))
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
                                    color: AmialColors.textSecondary)),
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
                                    color: AmialColors.textSecondary)),
                          ],
                        ),
                        const SizedBox(height: 6),
                        Row(
                          mainAxisAlignment: MainAxisAlignment.spaceBetween,
                          children: [
                            Text(AmialMoney.yer(_order!['total_amount']),
                                style: const TextStyle(
                                    fontWeight: FontWeight.bold,
                                    color: AmialColors.primary)),
                            const Text('إجمالي الأمر',
                                style: TextStyle(
                                    fontSize: 12,
                                    color: AmialColors.textSecondary)),
                          ],
                        ),
                      ]),
                    ),
                    const SizedBox(height: 14),

                    ..._items.map((it) => _itemCard(it)),

                    if (!ro) _cashBox(),

                    // AMIAL-DAILY-MOVEMENT-001 — **بابُ الردّ حيث يقع
                    // الاكتشاف.** التالفُ يُرى لحظةَ الاستلام أو بعده
                    // بيوم، وشاشةٌ منفصلةٌ في قائمةٍ أخرى لا يصلها أحد.
                    // (القاعدة الثانية عشرة: مسارٌ بلا رابطٍ ليس مبنيّاً.)
                    if (_receivedAnything) _returnDoor(),
                  ],
                ),
    );
  }

  bool get _receivedAnything => _items.any((it) => _received(it) > 0);

  /// **حقلُ الدفع النقديّ عند الاستلام.**
  ///
  /// ويُعرَض معه **قيمةُ ما يُستلَم الآن** لا إجمالي الأمر — فالاستلامُ
  /// الجزئيُّ أقلُّ، ورقمٌ أكبرُ هنا يُغري بدفع ثمن ما لم يصل.
  Widget _cashBox() => Container(
        margin: const EdgeInsets.only(top: 4, bottom: 10),
        padding: const EdgeInsets.all(14),
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(16),
          border: Border.all(color: AmialColors.border),
        ),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(children: [
            const Icon(Icons.payments_outlined,
                size: 18, color: AmialColors.cash),
            const SizedBox(width: 8),
            const Expanded(
              child: Text('المدفوع نقداً الآن (اختياري)',
                  style: TextStyle(fontWeight: FontWeight.bold, fontSize: 13)),
            ),
            Text('قيمة المستلَم: ${AmialMoney.yer(_valueNow)}',
                style: const TextStyle(
                    fontSize: 11, color: AmialColors.textSecondary)),
          ]),
          const SizedBox(height: 10),
          TextField(
            controller: _paidNow,
            keyboardType: const TextInputType.numberWithOptions(decimal: true),
            textDirection: TextDirection.ltr,
            decoration: const InputDecoration(
              isDense: true,
              hintText: '0',
              border: OutlineInputBorder(),
              helperMaxLines: 3,
              helperText: 'اتركه فارغاً للشراء الآجل. وما يزيد عن قيمة '
                  'المستلَم ليس شراءً نقدياً بل سداد دَينٍ سابق — بابه '
                  '«سداد دفعة» في ملف المورد.',
              helperStyle: TextStyle(fontSize: 10),
            ),
            onChanged: (_) => setState(() {}),
          ),
        ]),
      );

  /// **بابُ ردّ البضاعة إلى المورد.**
  Widget _returnDoor() => Padding(
        padding: const EdgeInsets.only(top: 6, bottom: 24),
        child: OutlinedButton.icon(
          onPressed: () async {
            final done = await Get.to<bool>(() => PurchaseReturnScreen(
                  poId: widget.poId,
                  supplierId: (_order?['supplier_id'] as num?)?.toInt() ??
                      (_order?['supplier']?['id'] as num?)?.toInt() ??
                      0,
                  items: _items.where((it) => _received(it) > 0).toList(),
                ));
            if (done == true) _load();
          },
          icon: const Icon(Icons.assignment_return_outlined, size: 18),
          label: const Text('ردّ بضاعة إلى المورد (تالف أو زائد)'),
          style: OutlinedButton.styleFrom(
            foregroundColor: AmialColors.red,
            side: const BorderSide(color: AmialColors.red),
            minimumSize: const Size.fromHeight(48),
            shape:
                RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
          ),
        ),
      );

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
                  ? AmialColors.success.withValues(alpha: 0.4)
                  : AmialColors.border),
        ),
        child: Column(children: [
          Row(children: [
            if (done)
              const Icon(Icons.check_circle,
                  color: AmialColors.success, size: 20),
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
                    fontSize: 12, color: AmialColors.textSecondary)),
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
                      fontSize: 11, color: AmialColors.textMuted)),
              const Spacer(),
              // تم الاستلام بالكامل
              Row(children: [
                Switch(
                  value: current >= remaining && current > 0,
                  activeThumbColor: AmialColors.primary,
                  onChanged: (v) => setState(
                      () => _receiving[id] = v ? remaining : 0),
                ),
                const Text('بالكامل',
                    style: TextStyle(
                        fontSize: 11, color: AmialColors.textSecondary)),
              ]),
            ]),
          ],
        ]),
      ),
    );
  }
}
