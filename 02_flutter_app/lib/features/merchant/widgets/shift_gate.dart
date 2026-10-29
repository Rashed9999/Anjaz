import 'package:flutter/material.dart';
import 'package:get/get.dart';

import 'package:amial_pay/data/api/api_client.dart';
import 'package:amial_pay/helper/amial_money.dart';
import 'package:amial_pay/theme/amial_colors.dart';

/// AMIAL-SHIFT-GATE-001 — **الشبّاكُ لا يُفتح بلا ورديّة.**
///
/// ══════════════════════════════════════════════════════════════════════
/// **بنصّ صاحب المشروع:** «لا يتمّ فتح الكاشير **حتّى لو كان مالك المتجر**
/// إلّا بفتح ورديّة تحمل اسمَه».
///
/// **والحدُّ الحقيقيُّ في الخادم** (`amial.shift` على مسارات البيع) —
/// وهذه الشاشةُ ليست الحماية: إخفاءُ الواجهة ليس أمناً (`amial-rbac`:
/// «Frontend hiding is NOT security»). **وظيفتُها ألّا يُفاجأ الكاشير**:
/// بدونها يملأ السلّةَ ويحسب الباقي والزبونُ واقف، ثمّ يُردّ ٤٠٩ عند
/// الضغط الأخير.
///
/// **وثلاثةُ قراراتٍ في السلوك:**
///
///   ① **يُفتَح بابُ الورديّة من هنا**، لا يُقال «اذهب إلى شاشةٍ أخرى».
///      خطوةٌ واحدةٌ بحقل عهدةٍ وزرّ — والرفضُ الذي لا يقول كيف يُصلَح
///      يُقرأ عطلاً.
///   ② **وعند تعذُّر القراءة يُمرَّر الكاشير ولا يُحبَس**: الشبكةُ في
///      اليمن تتقطّع، وحبسُ الشبّاك على خادمٍ لم يردّ يوقف متجراً كاملاً
///      **بينما الخادمُ نفسُه سيرفض البيعَ إن لزم**. فالحدُّ يبقى حيث هو،
///      ولا نضيف عليه قفلاً ثانياً يعمل بلا معلومة. (القاعدة السابعة:
///      «تعذّرت القراءة» ليست «لا ورديّة».)
///   ③ **ولا يُخفى الاسمُ حين تكون الورديّةُ مفتوحة** — شريطٌ رفيعٌ يقول
///      من فتحها، فيعرف الكاشيرُ باسم من ستُطبَع الفاتورة.
class ShiftGate extends StatefulWidget {
  const ShiftGate({super.key, required this.child});

  final Widget child;

  @override
  State<ShiftGate> createState() => _ShiftGateState();
}

class _ShiftGateState extends State<ShiftGate> {
  final _api = Get.find<ApiClient>();

  bool _checking = true;
  bool _opening = false;

  /// `null` تعني «لم تُقرأ بعد أو تعذّرت القراءة» — لا «لا ورديّة».
  Map<String, dynamic>? _shift;

  /// **الحدُّ مطلوبٌ أم أطفأه التاجر؟** ولا يُخمَّن: يُقرأ من الخادم.
  bool _required = true;
  bool _unreadable = false;

  final _float = TextEditingController(text: '0');

  @override
  void initState() {
    super.initState();
    _check();
  }

  @override
  void dispose() {
    _float.dispose();
    super.dispose();
  }

  Future<void> _check() async {
    if (mounted) setState(() { _checking = true; _unreadable = false; });

    try {
      final r = await _api.getData('/api/v1/amial/cashier/shift');

      if (r.statusCode == 200 && r.body is Map) {
        final meta = (r.body['meta'] ?? {}) as Map;
        final s = meta['shift'];
        setState(() {
          _shift = s is Map ? Map<String, dynamic>.from(s) : null;
          _required = meta['required'] is bool ? meta['required'] as bool : true;
        });
      } else {
        // ② تعذّرت القراءة — يُمرَّر ولا يُحبَس، والخادمُ يبقى الحدّ.
        setState(() { _unreadable = true; _shift = null; });
      }
    } catch (_) {
      if (mounted) setState(() { _unreadable = true; _shift = null; });
    } finally {
      if (mounted) setState(() => _checking = false);
    }
  }

  Future<void> _open() async {
    setState(() => _opening = true);
    try {
      final r = await _api.postData('/api/v1/amial/cashier/shift/open',
          {'opening_float': _float.text.trim().isEmpty ? '0' : _float.text.trim()});

      if (!mounted) return;

      if (r.statusCode == 201 || r.statusCode == 200) {
        await _check();
        if (mounted) {
          Get.snackbar('بدأت الوردية', 'الفواتير ستحمل اسمك حتى الإقفال',
              backgroundColor: AmialColors.successSurface,
              colorText: AmialColors.success);
        }
      } else {
        Get.snackbar('تعذّر بدء الوردية',
            (r.body is Map ? r.body['message']?.toString() : null) ?? 'حاول مجدداً',
            backgroundColor: AmialColors.dangerSurface, colorText: AmialColors.red);
      }
    } finally {
      if (mounted) setState(() => _opening = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_checking) {
      return const Scaffold(
        backgroundColor: AmialColors.background,
        body: Center(child: CircularProgressIndicator(color: AmialColors.primary)),
      );
    }

    // ② لا حبسَ على معلومةٍ لم تصل، ولا على تاجرٍ أطفأ الحدّ.
    if (_shift != null || _unreadable || !_required) {
      return _shift != null ? _withBanner() : widget.child;
    }

    return _gate();
  }

  /// ③ شريطٌ رفيعٌ يقول باسمِ من ستُطبَع الفاتورة.
  Widget _withBanner() {
    final name = '${_shift!['opened_by_name'] ?? ''}'.trim();
    if (name.isEmpty) return widget.child;

    return Column(children: [
      Material(
        color: AmialColors.successSurface,
        child: SafeArea(
          bottom: false,
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
            child: Row(children: [
              const Icon(Icons.badge_outlined, size: 15, color: AmialColors.success),
              const SizedBox(width: 6),
              Expanded(
                child: Text('وردية $name',
                    style: const TextStyle(
                        fontSize: 11.5,
                        fontWeight: FontWeight.w700,
                        color: AmialColors.success)),
              ),
              Text('عهدة ${AmialMoney.yer(_shift!['opening_float'])}',
                  style: const TextStyle(
                      fontSize: 11, color: AmialColors.textSecondary)),
            ]),
          ),
        ),
      ),
      Expanded(child: widget.child),
    ]);
  }

  /// ① البابُ يُفتح من هنا — لا يُقال «اذهب إلى شاشةٍ أخرى».
  Widget _gate() {
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(
        title: const Text('ابدأ وردية'),
        backgroundColor: AmialColors.primary,
        foregroundColor: Colors.white,
      ),
      body: ListView(padding: const EdgeInsets.all(20), children: [
        const SizedBox(height: 24),
        const Icon(Icons.point_of_sale, size: 66, color: AmialColors.primary),
        const SizedBox(height: 14),
        const Text('لا يُفتح الشبّاك بلا وردية',
            textAlign: TextAlign.center,
            style: TextStyle(fontSize: 18, fontWeight: FontWeight.w900)),
        const SizedBox(height: 8),
        const Text(
            'كل فاتورة تحمل اسم من فتح الوردية، وعند الإقفال يُجرد الدرج '
            'ويُعرف الفائض والناقص. وهذا يشمل صاحب المتجر.',
            textAlign: TextAlign.center,
            style: TextStyle(fontSize: 12.5, color: AmialColors.textSecondary, height: 1.6)),
        const SizedBox(height: 26),
        TextField(
          controller: _float,
          keyboardType: const TextInputType.numberWithOptions(decimal: true),
          textDirection: TextDirection.ltr,
          decoration: const InputDecoration(
            labelText: 'النقد الافتتاحي في الدرج (العهدة)',
            suffixText: 'ر.ي',
            border: OutlineInputBorder(),
            helperMaxLines: 2,
            helperText: 'يُطابَق عليه عند الإقفال. اتركه صفراً إن كان الدرج فارغاً.',
            helperStyle: TextStyle(fontSize: 10.5),
          ),
        ),
        const SizedBox(height: 18),
        FilledButton.icon(
          onPressed: _opening ? null : _open,
          icon: _opening
              ? const SizedBox(
                  height: 18, width: 18,
                  child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
              : const Icon(Icons.play_arrow),
          label: const Text('بدء الوردية',
              style: TextStyle(fontSize: 16, fontWeight: FontWeight.w700)),
          style: FilledButton.styleFrom(
            backgroundColor: AmialColors.primary,
            minimumSize: const Size.fromHeight(54),
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
          ),
        ),
        const SizedBox(height: 10),
        TextButton.icon(
          onPressed: _check,
          icon: const Icon(Icons.refresh, size: 16),
          label: const Text('تحديث'),
        ),
      ]),
    );
  }
}
