import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import 'package:amial_pay/theme/amial_colors.dart';

/// AMIAL-CALCULATOR-001 — **حاسبةٌ بجوار الشبّاك، مجّانيّةٌ للجميع.**
///
/// ══════════════════════════════════════════════════════════════════════
/// **بنصّ صاحب المشروع:** «أريد إضافة ميزةٍ مجّانيّةٍ لكلّ التجّار وكلّ
/// الباقات، وهي الآلةُ الحاسبة، **وتكون قريبةً من الكاشير** لو أراد حسابَ
/// شيءٍ ما».
///
/// **وثلاثةُ قراراتٍ تُقرأ ولا تُخمَّن:**
///
///   ① **لا قدرةَ ولا `AccessGate`.** أداةٌ لا تُحرّك ريالاً ولا تقرأ
///      حساباً ولا تكتب سطراً — فحجبُها خلف باقةٍ بيعُ آلةٍ حاسبةٍ في
///      هاتفٍ فيه واحدةٌ مجّاناً. **وحدُّ `core()` نفسُه**: ما لا يُنتج
///      رقماً في سجلٍّ لا يُسعَّر.
///
///   ② **ورقةٌ تعلو الشاشة لا شاشةٌ مستقلّة.** من يحسب وهو على الشبّاك
///      يحسب **والسلّةُ أمامه** — وفتحُ شاشةٍ يُخرجه من السلّة، فيعود
///      فيجدها كما تركها أو لا يعود. والورقةُ تُغلَق بسحبةٍ فيبقى مكانه.
///
///   ③ **ولا تُلصَق النتيجةُ في حقلٍ من تلقائها.** حاسبةٌ تكتب في حقل
///      المبلغ تُنتج بيعةً برقمٍ لم يقصده أحد — والزرُّ «نسخ» يترك
///      القرارَ لصاحبه. (وهذا أهمُّها: أداةُ راحةٍ لا تلمس مالاً.)
///
/// **وترتيبُ اللوحة هو ترتيبُ لوحة الاتصال** — نفسُ حجّة `AmialNumpad`:
/// من حفظ الأرقام بحركة يده لا يُطلب منه أن يتعلّم ترتيباً جديداً.
///
/// يظهر في : التطبيق ← لوحة التجزئة · البيع السريع · الصيدليّة · شاشة
/// الكاشير · شاشة بيع الصيدليّة. وفي لوحة الإدارة: لا — أداةُ تاجر.
class QuickCalculatorSheet extends StatefulWidget {
  const QuickCalculatorSheet({super.key});

  /// البابُ الوحيد — فلا تُنسَخ نافذتُها في كلّ شاشة.
  static Future<void> open(BuildContext context) {
    return showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (_) => const QuickCalculatorSheet(),
    );
  }

  @override
  State<QuickCalculatorSheet> createState() => _QuickCalculatorSheetState();
}

class _QuickCalculatorSheetState extends State<QuickCalculatorSheet> {
  /// ما يُعرَض في السطر الكبير — نصٌّ لا رقم، فالمستعمل يكتب على مهله.
  String _entry = '0';

  /// الطرفُ المحفوظ والعمليّةُ المعلّقة؛ `null` تعني «لا شيءَ ينتظر».
  double? _left;
  String? _op;

  /// آخرُ عمليّةٍ كاملةٍ تُعرَض فوق السطر — فيرى صاحبُها ما حسبه.
  String _tape = '';

  /// **بعد `=` يبدأ الرقمُ التالي من جديد** — وإلّا التصق بالنتيجة.
  bool _fresh = false;

  static const _maxDigits = 12;

  double get _value => double.tryParse(_entry) ?? 0;

  void _tapDigit(String d) {
    setState(() {
      if (_fresh) {
        _entry = '0';
        _fresh = false;
      }

      if (d == '.') {
        if (!_entry.contains('.')) _entry = '$_entry.';

        return;
      }

      final digitsOnly = _entry.replaceAll(RegExp(r'[^0-9]'), '');
      if (digitsOnly.length >= _maxDigits) return;

      _entry = (_entry == '0' && d != '.') ? d : '$_entry$d';
    });
  }

  void _tapOp(String op) {
    setState(() {
      if (_op != null && !_fresh) {
        _applyPending();
      } else {
        _left = _value;
      }

      _op = op;
      _fresh = true;
      _tape = '${_fmt(_left ?? 0)} $op';
    });
  }

  /// **القسمةُ على صفرٍ تُقال ولا تُخرِج `Infinity`** — ورمزٌ لا يُقرأ
  /// بالعربيّة يُوقف صاحبَه ولا يخبره بشيء. (القاعدة السابعة.)
  void _applyPending() {
    final l = _left ?? 0;
    final r = _value;

    if (_op == '÷' && r == 0) {
      _entry = 'لا تُقسَم على صفر';
      _left = null;
      _op = null;
      _fresh = true;

      return;
    }

    final out = switch (_op) {
      '+' => l + r,
      '−' => l - r,
      '×' => l * r,
      '÷' => l / r,
      '%' => l * r / 100,
      _ => r,
    };

    _left = out;
    _entry = _fmt(out);
  }

  void _equals() {
    if (_op == null) return;

    setState(() {
      final line = '${_fmt(_left ?? 0)} $_op ${_entry}';
      _applyPending();
      _tape = _entry.startsWith('لا') ? '' : '$line =';
      _op = null;
      _left = null;
      _fresh = true;
    });
  }

  void _clear() => setState(() {
        _entry = '0';
        _left = null;
        _op = null;
        _tape = '';
        _fresh = false;
      });

  void _backspace() => setState(() {
        if (_fresh || _entry.length <= 1 || double.tryParse(_entry) == null) {
          _entry = '0';
          _fresh = false;

          return;
        }
        _entry = _entry.substring(0, _entry.length - 1);
        if (_entry.isEmpty || _entry == '-') _entry = '0';
      });

  /// **ولا أصفارٌ عشريّةٌ زائدة** — «١٥٠٠» لا «1500.0».
  String _fmt(double v) {
    if (v == v.roundToDouble() && v.abs() < 1e15) {
      return v.toStringAsFixed(0);
    }

    return v
        .toStringAsFixed(4)
        .replaceFirst(RegExp(r'0+$'), '')
        .replaceFirst(RegExp(r'\.$'), '');
  }

  Future<void> _copy() async {
    await Clipboard.setData(ClipboardData(text: _entry));
    if (!mounted) return;

    ScaffoldMessenger.of(context).showSnackBar(
      const SnackBar(content: Text('نُسخ الناتج'), duration: Duration(seconds: 2)),
    );
  }

  @override
  Widget build(BuildContext context) {
    return SafeArea(
      top: false,
      child: Container(
        decoration: const BoxDecoration(
          color: AmialColors.background,
          borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
        ),
        padding: const EdgeInsets.fromLTRB(12, 8, 12, 12),
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          Container(
            width: 42, height: 4,
            margin: const EdgeInsets.only(bottom: 10),
            decoration: BoxDecoration(
              color: AmialColors.textMuted,
              borderRadius: BorderRadius.circular(2),
            ),
          ),

          _display(),
          const SizedBox(height: 10),

          // **الترتيبُ ترتيبُ لوحة الاتصال** — ولا يُبعثَر ولا يُعكَس.
          Directionality(
            textDirection: TextDirection.ltr,
            child: Column(mainAxisSize: MainAxisSize.min, children: [
              Row(children: [
                _key('C', onTap: _clear, tone: _Tone.warn),
                _key('%', onTap: () => _tapOp('%'), tone: _Tone.op),
                _key('⌫', onTap: _backspace, tone: _Tone.warn),
                _key('÷', onTap: () => _tapOp('÷'), tone: _Tone.op),
              ]),
              for (final row in const [['7', '8', '9'], ['4', '5', '6'], ['1', '2', '3']])
                Row(children: [
                  for (final d in row) _key(d, onTap: () => _tapDigit(d)),
                  _key(const {'7': '×', '4': '−', '1': '+'}[row[0]]!,
                      onTap: () => _tapOp(const {'7': '×', '4': '−', '1': '+'}[row[0]]!),
                      tone: _Tone.op),
                ]),
              Row(children: [
                _key('0', onTap: () => _tapDigit('0')),
                _key('00', onTap: () {
                  _tapDigit('0');
                  _tapDigit('0');
                }),
                _key('.', onTap: () => _tapDigit('.')),
                _key('=', onTap: _equals, tone: _Tone.primary),
              ]),
            ]),
          ),
        ]),
      ),
    );
  }

  Widget _display() {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.fromLTRB(14, 14, 14, 10),
      decoration: BoxDecoration(
        color: AmialColors.cardSurface,
        borderRadius: BorderRadius.circular(14),
      ),
      child: Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
        SizedBox(
          height: 18,
          child: Text(_tape,
              key: const Key('calc-tape'),
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              textDirection: TextDirection.ltr,
              style: const TextStyle(fontSize: 13, color: AmialColors.textMuted)),
        ),
        const SizedBox(height: 4),
        FittedBox(
          fit: BoxFit.scaleDown,
          alignment: Alignment.centerRight,
          child: Text(_entry,
              key: const Key('calc-display'),
              textDirection: TextDirection.ltr,
              style: const TextStyle(
                  fontSize: 34,
                  fontWeight: FontWeight.bold,
                  fontFeatures: [FontFeature.tabularFigures()],
                  color: AmialColors.textPrimary)),
        ),
        Align(
          alignment: AlignmentDirectional.centerStart,
          child: TextButton.icon(
            key: const Key('calc-copy'),
            onPressed: _copy,
            icon: const Icon(Icons.copy_rounded, size: 16),
            label: const Text('نسخ الناتج', style: TextStyle(fontSize: 12)),
          ),
        ),
      ]),
    );
  }

  Widget _key(String label, {required VoidCallback onTap, _Tone tone = _Tone.digit}) {
    final (Color bg, Color fg) = switch (tone) {
      _Tone.primary => (AmialColors.primary, Colors.white),
      _Tone.op => (AmialColors.cardSurface, AmialColors.primary),
      _Tone.warn => (AmialColors.cardSurface, AmialColors.danger),
      _Tone.digit => (AmialColors.cardSurface, AmialColors.textPrimary),
    };

    return Expanded(
      child: Padding(
        padding: const EdgeInsets.all(4),
        child: Material(
          color: bg,
          borderRadius: BorderRadius.circular(12),
          child: InkWell(
            key: Key('calc-key-$label'),
            borderRadius: BorderRadius.circular(12),
            onTap: onTap,
            child: SizedBox(
              height: 54,
              child: Center(
                child: Text(label,
                    style: TextStyle(
                        fontSize: 21, fontWeight: FontWeight.w600, color: fg)),
              ),
            ),
          ),
        ),
      ),
    );
  }
}

enum _Tone { digit, op, warn, primary }
