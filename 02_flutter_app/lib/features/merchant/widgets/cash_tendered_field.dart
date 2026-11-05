import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import 'package:amial_pay/helper/amial_money.dart';
import 'package:amial_pay/theme/amial_colors.dart';

/// AMIAL-CASH-TENDERED-001 — **المبلغُ المستلم، والباقي يُحسب لا يُذكَر ذهناً.**
///
/// ══════════════════════════════════════════════════════════════════════
/// **من أين جاءت:** أرسل صاحبُ المشروع شاشاتِ تطبيقٍ محاسبيٍّ منافس، وفي
/// ذيل فاتورته سطران: «المبلغ المستلم» و«صافي الفاتوره». وقِيس فليس في
/// أميال حقلٌ للمستلم ولا للباقي إطلاقاً — **والكاشيرُ يحسب الباقيَ في
/// رأسه في كلّ بيعةٍ نقديّة**، والزبونُ واقفٌ ينتظر.
///
/// **وأداةٌ مشتركةٌ لا نصٌّ في كلّ شاشة:** الكاشيرُ العامُّ وشبّاكُ
/// الصيدليّة يفعلان الشيءَ نفسَه، ونسختان تفترقان بعد أوّل تعديل فتحسب
/// إحداهما الباقيَ بغير ما تحسبه الأخرى — **ورقمان لشيءٍ واحدٍ في يد
/// بائعَين**.
///
/// **ولا يُلزَم الإدخال.** كثيرٌ من البيوع يُسلَّم فيها المبلغُ بالضبط،
/// وحقلٌ إجباريٌّ يُبطئ الشبّاكَ بلا فائدة. فالحقلُ اختياريٌّ ويظهر
/// الباقي حين يُملأ.
class CashTenderedField extends StatefulWidget {
  const CashTenderedField({
    super.key,
    required this.net,
    required this.onChanged,
    this.symbol,
  });

  /// صافي الفاتورة — ما يجب أن يدفعه الزبون.
  final double net;

  /// يُنادى بالمستلَم، و`null` حين يُفرَّغ الحقل.
  final ValueChanged<double?> onChanged;

  /// رمزُ العملة إن كانت غيرَ الأساس — فالباقي بعملة ما استُلم.
  final String? symbol;

  @override
  State<CashTenderedField> createState() => _CashTenderedFieldState();
}

class _CashTenderedFieldState extends State<CashTenderedField> {
  final _ctrl = TextEditingController();
  double? _received;

  @override
  void dispose() {
    _ctrl.dispose();
    super.dispose();
  }

  String _fmt(double v) =>
      widget.symbol == null ? AmialMoney.yer(v) : '${v.toStringAsFixed(2)} ${widget.symbol}';

  /// **أوراقٌ مقترحةٌ تُحسب من الفاتورة لا تُكتب قائمةً ثابتة.**
  ///
  /// قائمةٌ محفورة (٥٠٠ · ١٠٠٠ · ٥٠٠٠) لا تنفع فاتورةً بـ٤٧٠٠٠ ولا أخرى
  /// بـ٣٠٠. فتُقرَّب الفاتورةُ إلى أعلى ورقةٍ معقولة، ويُعرَض «بالضبط».
  List<double> get _suggestions {
    final n = widget.net;
    if (n <= 0) return const [];

    final out = <double>{n};

    for (final step in [500.0, 1000.0, 5000.0, 10000.0]) {
      final up = (n / step).ceil() * step;
      if (up > n) out.add(up);
    }

    final list = out.toList()..sort();

    return list.take(4).toList();
  }

  void _set(double? v) {
    setState(() => _received = v);
    widget.onChanged(v);
  }

  @override
  Widget build(BuildContext context) {
    // **والباقي لا يُعرَض سالباً**: نقصٌ في المستلَم ليس «باقياً بالسالب»
    // بل «ناقصٌ من الفاتورة»، ويُقال بلفظه. (القاعدة السابعة.)
    final r = _received;
    final change = r == null ? null : r - widget.net;

    return Container(
      margin: const EdgeInsets.only(bottom: 10),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: AmialColors.cardSurface,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: AmialColors.border),
      ),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Row(children: [
          const Icon(Icons.payments_outlined, size: 18, color: AmialColors.primary),
          const SizedBox(width: 6),
          const Expanded(
            child: Text('المبلغ المستلم (اختياري)',
                style: TextStyle(fontSize: 14, fontWeight: FontWeight.bold)),
          ),
          if (r != null)
            TextButton(
              onPressed: () { _ctrl.clear(); _set(null); },
              child: const Text('مسح', style: TextStyle(color: AmialColors.red)),
            ),
        ]),
        const SizedBox(height: 8),
        TextField(
          controller: _ctrl,
          key: const Key('cash-tendered'),
          keyboardType: const TextInputType.numberWithOptions(decimal: true),
          inputFormatters: [FilteringTextInputFormatter.allow(RegExp(r'[0-9.]'))],
          textDirection: TextDirection.ltr,
          textAlign: TextAlign.left,
          decoration: InputDecoration(
            isDense: true,
            hintText: widget.net.toStringAsFixed(0),
            border: const OutlineInputBorder(),
          ),
          onChanged: (v) {
            final parsed = double.tryParse(v.trim());
            _set(parsed != null && parsed > 0 ? parsed : null);
          },
        ),
        const SizedBox(height: 8),
        Wrap(spacing: 6, runSpacing: 6, children: [
          for (final s in _suggestions)
            ActionChip(
              label: Text(s == widget.net ? 'بالضبط' : s.toStringAsFixed(0),
                  style: const TextStyle(fontSize: 12)),
              onPressed: () {
                _ctrl.text = s.toStringAsFixed(0);
                _set(s);
              },
            ),
        ]),
        if (change != null) ...[
          const SizedBox(height: 10),
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
            decoration: BoxDecoration(
              color: (change < 0 ? AmialColors.red : AmialColors.success)
                  .withValues(alpha: 0.08),
              borderRadius: BorderRadius.circular(8),
            ),
            child: Row(children: [
              Expanded(
                child: Text(change < 0 ? 'ناقصٌ من الفاتورة' : 'الباقي للزبون',
                    style: const TextStyle(fontSize: 13, fontWeight: FontWeight.bold)),
              ),
              Text(_fmt(change.abs()),
                  textDirection: TextDirection.ltr,
                  style: TextStyle(
                    fontSize: 16,
                    fontWeight: FontWeight.bold,
                    color: change < 0 ? AmialColors.red : AmialColors.success,
                  )),
            ]),
          ),
        ],
      ]),
    );
  }
}
