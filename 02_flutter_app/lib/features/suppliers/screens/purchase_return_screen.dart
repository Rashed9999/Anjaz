import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:get/get.dart';
import 'package:amial_pay/features/suppliers/controllers/suppliers_controller.dart';
import 'package:amial_pay/helper/amial_money.dart';
import 'package:amial_pay/theme/amial_colors.dart';

/// AMIAL-DAILY-MOVEMENT-001 — **ردُّ البضاعة إلى المورد.**
///
/// ══════════════════════════════════════════════════════════════════════
/// لم يكن في أميال بابٌ واحدٌ لهذا. فمن استلم تالفاً أو منتهياً أو زائداً
/// عن أمره لا يجد إلّا «تسويةً يدويّة» في دفتر المورد:
///
///   · **المخزونُ لا ينقص** — فيبقى التالفُ معروضاً ويشتريه زبون.
///   · **والقيمةُ بلا نسبة** — نقصُ الدين يُقرأ سداداً، فتقريرُ المشتريات
///     يقول إنّ التاجرَ اشترى ما ردَّه.
///
/// **وثلاثةُ قراراتٍ تُقرأ على الشاشة نفسِها:**
///
///   ① **لا يُردُّ إلّا ما استُلم** — والسقفُ مكتوبٌ بجانب كلّ صنف،
///      والحقلُ يُقصّ عنده. فالرفضُ بعد ضغطةٍ أسوأُ من منعٍ قبلها.
///   ② **ووجهُ المال يُختار صراحةً**: خصمٌ من دين المورد، أو استردادٌ
///      نقديّ. **ولا خيارَ افتراضيَّ صامت** — الخلطُ يُنقص الدينَ ويقبض
///      النقدَ معاً، أي ردٌّ يُحاسَب مرّتين.
///   ③ **والبضاعةُ لا تتحرّك قبل الاعتماد** — يُنشأ الطلبُ ثمّ يُعتمَد،
///      وهو ما تقوله الشاشةُ قبل الإرسال لا بعده.
class PurchaseReturnScreen extends StatefulWidget {
  const PurchaseReturnScreen({
    super.key,
    required this.poId,
    required this.supplierId,
    required this.items,
  });

  final int poId;
  final int supplierId;

  /// بنودُ الأمر التي استُلم منها شيء.
  final List<Map<String, dynamic>> items;

  @override
  State<PurchaseReturnScreen> createState() => _PurchaseReturnScreenState();
}

class _PurchaseReturnScreenState extends State<PurchaseReturnScreen> {
  SuppliersController get c => Get.find<SuppliersController>();

  /// itemId → الكمية المردودة.
  final Map<int, int> _returning = {};
  final TextEditingController _reason = TextEditingController();

  String _settlement = 'credit_note';
  bool _saving = false;

  @override
  void dispose() {
    _reason.dispose();
    super.dispose();
  }

  // ① السقفُ: المُستلَمُ ناقصَ ما رُدّ.
  int _returnable(Map<String, dynamic> it) {
    final received = (double.tryParse('${it['received_quantity'] ?? 0}') ?? 0);
    final returned = (double.tryParse('${it['returned_quantity'] ?? 0}') ?? 0);
    final left = received - returned;
    return left <= 0 ? 0 : left.round();
  }

  String get _total {
    var t = 0.0;
    for (final it in widget.items) {
      final n = _returning[it['id'] as int] ?? 0;
      if (n <= 0) continue;
      t += n * (double.tryParse('${it['unit_cost'] ?? 0}') ?? 0);
    }
    return t.toStringAsFixed(2);
  }

  Future<void> _submit() async {
    final lines = _returning.entries
        .where((e) => e.value > 0)
        .map((e) => {'purchase_order_item_id': e.key, 'quantity': e.value})
        .toList();

    if (lines.isEmpty) {
      _snack('حدد الكميات المردودة أولاً');
      return;
    }

    if (_reason.text.trim().length < 3) {
      // **والسببُ يُطلَب هنا ولو لم يُلزِمه الخادم**: مرتجعٌ بلا سببٍ
      // يُقرأ بعد شهرٍ خطأً في الجرد، ولا أحدَ يذكر ماذا جرى.
      _snack('اكتب سبب الردّ (تالف، منتهي، زائد عن الأمر…)');
      return;
    }

    setState(() => _saving = true);
    final ok = await c.prCreate({
      'supplier_id': widget.supplierId,
      'purchase_order_id': widget.poId,
      'settlement_type': _settlement,
      'reason': _reason.text.trim(),
      'items': lines,
    });
    if (!mounted) return;
    setState(() => _saving = false);

    if (!ok) {
      _snack(c.lastError.value.isEmpty ? 'فشل تسجيل المرتجع' : c.lastError.value);
      return;
    }

    Get.back(result: true);
    Get.snackbar('سُجّل المرتجع',
        'يُعتمد من قائمة المرتجعات لتخرج البضاعة ويتحرّك حساب المورد',
        backgroundColor: AmialColors.successSurface,
        colorText: AmialColors.success);
  }

  void _snack(String m) => ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(m), backgroundColor: AmialColors.red),
      );

  @override
  Widget build(BuildContext context) {
    final returnable =
        widget.items.where((it) => _returnable(it) > 0).toList();

    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(title: const Text('ردّ بضاعة إلى المورد')),
      bottomNavigationBar: returnable.isEmpty
          ? null
          : SafeArea(
              child: Padding(
                padding: const EdgeInsets.all(16),
                child: FilledButton.icon(
                  onPressed: _saving ? null : _submit,
                  icon: _saving
                      ? const SizedBox(
                          height: 18,
                          width: 18,
                          child: CircularProgressIndicator(
                              strokeWidth: 2, color: Colors.white))
                      : const Icon(Icons.assignment_return_outlined),
                  label: Text('تسجيل مرتجع بـ ${AmialMoney.yer(_total)}',
                      style: const TextStyle(
                          fontSize: 15, fontWeight: FontWeight.w600)),
                  style: FilledButton.styleFrom(
                    backgroundColor: AmialColors.red,
                    minimumSize: const Size.fromHeight(54),
                    shape: RoundedRectangleBorder(
                        borderRadius: BorderRadius.circular(16)),
                  ),
                ),
              ),
            ),
      body: returnable.isEmpty
          ? const Center(
              child: Padding(
                padding: EdgeInsets.all(24),
                child: Text(
                    'لا بضاعة قابلة للردّ في هذا الأمر — لم يُستلم منه شيء بعد، '
                    'أو رُدّ كل ما استُلم.',
                    textAlign: TextAlign.center,
                    style: TextStyle(color: AmialColors.textSecondary)),
              ),
            )
          : ListView(
              padding: const EdgeInsets.all(16),
              children: [
                // ③ ما يقع عند الاعتماد — يُقال قبل الإرسال.
                Container(
                  padding: const EdgeInsets.all(12),
                  decoration: BoxDecoration(
                    color: AmialColors.warningSurface,
                    borderRadius: BorderRadius.circular(14),
                  ),
                  child: const Row(children: [
                    Icon(Icons.info_outline,
                        size: 18, color: AmialColors.warning),
                    SizedBox(width: 8),
                    Expanded(
                      child: Text(
                          'يُسجَّل الطلب الآن. وعند اعتماده تخرج البضاعة من '
                          'المخزون ويتحرّك حساب المورد — لا قبل ذلك.',
                          style: TextStyle(
                              fontSize: 11, color: AmialColors.warning)),
                    ),
                  ]),
                ),
                const SizedBox(height: 14),

                ...returnable.map(_itemCard),

                const SizedBox(height: 6),
                _settlementBox(),
                const SizedBox(height: 12),

                TextField(
                  controller: _reason,
                  maxLines: 2,
                  decoration: const InputDecoration(
                    labelText: 'سبب الردّ',
                    hintText: 'تالف · منتهي الصلاحية · زائد عن الأمر…',
                    border: OutlineInputBorder(),
                  ),
                ),
                const SizedBox(height: 24),
              ],
            ),
    );
  }

  /// ② وجهُ المال — **يُختار صراحةً ولا يُخمَّن.**
  Widget _settlementBox() => Container(
        padding: const EdgeInsets.all(14),
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(16),
          border: Border.all(color: AmialColors.border),
        ),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          const Text('كيف تُسوّى قيمة المرتجع؟',
              style: TextStyle(fontWeight: FontWeight.bold, fontSize: 13)),
          const SizedBox(height: 4),
          const Text('الوجهان لا يُجمعان: خصمٌ من الدين أو استردادٌ نقدي.',
              style: TextStyle(fontSize: 11, color: AmialColors.textMuted)),
          const SizedBox(height: 8),
          _choice('credit_note', 'خصم من دين المورد',
              'يقلّ ما عليك للمورد بقيمة المرتجع', Icons.receipt_long_outlined),
          const SizedBox(height: 8),
          _choice('cash_refund', 'استُرِدّ نقداً',
              'الدين لا يتغيّر — المال عاد إلى الدرج', Icons.payments_outlined),
        ]),
      );

  /// **خيارٌ يُضغط بكامل مساحته** — لا دائرةٌ صغيرةٌ وحدَها.
  Widget _choice(String value, String title, String note, IconData icon) {
    final on = _settlement == value;

    return InkWell(
      onTap: () => setState(() => _settlement = value),
      borderRadius: BorderRadius.circular(12),
      child: Container(
        padding: const EdgeInsets.all(11),
        decoration: BoxDecoration(
          color: on
              ? AmialColors.primary.withValues(alpha: 0.06)
              : Colors.transparent,
          borderRadius: BorderRadius.circular(12),
          border: Border.all(
              color: on ? AmialColors.primary : AmialColors.border,
              width: on ? 1.6 : 1),
        ),
        child: Row(children: [
          Icon(on ? Icons.radio_button_checked : Icons.radio_button_unchecked,
              size: 18,
              color: on ? AmialColors.primary : AmialColors.textMuted),
          const SizedBox(width: 10),
          Icon(icon, size: 16, color: AmialColors.textSecondary),
          const SizedBox(width: 8),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(title,
                    style: TextStyle(
                        fontSize: 13,
                        fontWeight: on ? FontWeight.w900 : FontWeight.w600)),
                Text(note,
                    style: const TextStyle(
                        fontSize: 11, color: AmialColors.textMuted)),
              ],
            ),
          ),
        ]),
      ),
    );
  }

  Widget _itemCard(Map<String, dynamic> it) {
    final id = it['id'] as int;
    final max = _returnable(it);
    final current = _returning[id] ?? 0;

    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: Container(
        padding: const EdgeInsets.all(14),
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(16),
          border: Border.all(color: AmialColors.border),
        ),
        child: Column(children: [
          Row(children: [
            Expanded(
              child: Text('${it['name']}',
                  style: const TextStyle(
                      fontWeight: FontWeight.bold, fontSize: 14)),
            ),
            Text(AmialMoney.yer(it['unit_cost']),
                style: const TextStyle(
                    fontSize: 12, color: AmialColors.textSecondary)),
          ]),
          const SizedBox(height: 8),
          Row(children: [
            SizedBox(
              width: 90,
              child: TextField(
                keyboardType: TextInputType.number,
                inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                textAlign: TextAlign.center,
                style:
                    const TextStyle(fontWeight: FontWeight.bold, fontSize: 15),
                decoration: const InputDecoration(
                  isDense: true,
                  hintText: '0',
                  border: OutlineInputBorder(),
                ),
                // ① **يُقصُّ عند السقف** — ولا يُترَك للخادم يرفض.
                onChanged: (v) {
                  final n = int.tryParse(v) ?? 0;
                  setState(() => _returning[id] = n > max ? max : n);
                },
              ),
            ),
            const SizedBox(width: 10),
            Expanded(
              child: Text('المتاح للردّ: $max',
                  style: const TextStyle(
                      fontSize: 12, color: AmialColors.textSecondary)),
            ),
            if (current > 0)
              Text(AmialMoney.yer(
                  (current * (double.tryParse('${it['unit_cost'] ?? 0}') ?? 0))
                      .toStringAsFixed(2)),
                  style: const TextStyle(
                      fontSize: 12,
                      fontWeight: FontWeight.w900,
                      color: AmialColors.red)),
          ]),
        ]),
      ),
    );
  }
}
