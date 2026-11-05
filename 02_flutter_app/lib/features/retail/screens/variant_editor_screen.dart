import 'package:flutter/material.dart';
import 'package:get/get.dart';

import 'package:amial_pay/features/retail/controllers/retail_vertical_controller.dart';
import 'package:amial_pay/theme/amial_colors.dart';

/// AMIAL-VARIANT-EDITOR-001 — **تبويبُ «الأنواع»: سعرٌ ومخزونٌ لكلّ متغيّر.**
///
/// ══════════════════════════════════════════════════════════════════════
/// **الثمنُ الذي دُفع:** كان التوليدُ **باباً بلا عودة**. تُولَّد تسعةُ
/// متغيّراتٍ ثمّ:
///
///   · لا مسارَ يقرؤها — فالشاشةُ تولّد ولا ترى ما ولّدت.
///   · وكلُّها بمخزونِ صفر، فالكاشيرُ يقول «نفذ» على كلّ شيء.
///   · **والمخزونُ الذي قِيل «ينتظر التوزيع» لا مكانَ يُوزَّع فيه.**
///
/// فهذه الشاشةُ هي المكان: سعرٌ ومخزونٌ وباركودٌ لكلّ نوع، كما وصفه صاحبُ
/// المشروع بنصّه.
///
/// **والمخزونُ يمرّ بحركةِ مخزونٍ لا بكتابةٍ مباشرة** — فكتابةُ الرقم
/// تجعل الجردَ يقارن رقماً بنفسه، ويضيع أثرُ من غيّره ومتى. (القاعدة
/// السادسة: الرقمُ يُحسب من مصدره.)
class VariantEditorScreen extends StatefulWidget {
  const VariantEditorScreen({
    super.key,
    required this.productId,
    required this.productName,
  });

  final int productId;
  final String productName;

  @override
  State<VariantEditorScreen> createState() => _VariantEditorScreenState();
}

class _VariantEditorScreenState extends State<VariantEditorScreen> {
  final _c = Get.find<RetailVerticalController>();

  List<Map<String, dynamic>> _variants = [];
  String _allocated = '0';
  bool _loading = true;
  String? _error;

  /// محرّراتٌ لكلّ صفّ — تُبنى مرّةً فلا يفقد المستعملُ ما كتب عند إعادة الرسم.
  final Map<int, TextEditingController> _price = {};
  final Map<int, TextEditingController> _qty = {};
  final Set<int> _saving = {};

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    for (final c in _price.values) {
      c.dispose();
    }
    for (final c in _qty.values) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });

    final data = await _c.loadVariants(widget.productId);

    if (!mounted) return;
    setState(() {
      _loading = false;

      if (data == null) {
        _error = _c.lastError.value.isEmpty
            ? 'تعذّر تحميل الأنواع'
            : _c.lastError.value;
        return;
      }

      _variants = ((data['variants'] ?? []) as List)
          .map((e) => Map<String, dynamic>.from(e as Map))
          .toList();
      _allocated = '${data['allocated_total'] ?? '0'}';

      for (final v in _variants) {
        final id = v['id'] as int;
        _price.putIfAbsent(id,
            () => TextEditingController(text: _trim('${v['price']}')));
        _qty.putIfAbsent(id,
            () => TextEditingController(text: _trim('${v['quantity']}')));
      }
    });
  }

  /// «2000.0000» ⇒ «2000» — فحقلٌ فيه أربعةُ أصفارٍ يُعاد كتابتُه كلَّ مرّة.
  String _trim(String v) {
    final d = double.tryParse(v);
    if (d == null) return v;

    return d == d.roundToDouble() ? d.toStringAsFixed(0) : '$d';
  }

  Future<void> _save(Map<String, dynamic> v) async {
    final id = v['id'] as int;
    setState(() => _saving.add(id));

    final ok = await _c.saveVariant(id, {
      'price': _price[id]!.text.trim(),
      'quantity': _qty[id]!.text.trim(),
    });

    if (!mounted) return;
    setState(() => _saving.remove(id));

    if (!ok) {
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
        content: Text(_c.lastError.value.isEmpty ? 'تعذّر الحفظ' : _c.lastError.value),
        backgroundColor: AmialColors.red,
      ));
      return;
    }

    // **ويُعاد التحميلُ** — فالمخزونُ يُحسم في الخادم بحركةٍ، والرقمُ
    // المعروضُ يجب أن يكون ما استقرّ هناك لا ما كُتب هنا.
    await _load();
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
      content: Text('حُفظ ${v['display_name']}'),
      backgroundColor: AmialColors.success,
    ));
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(
        title: const Text('الأنواع'),
        backgroundColor: AmialColors.primary,
        foregroundColor: Colors.white,
        actions: [
          IconButton(
            tooltip: 'تحديث',
            icon: const Icon(Icons.refresh),
            onPressed: _loading ? null : _load,
          ),
        ],
      ),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : _error != null
              ? Center(
                  child: Padding(
                    padding: const EdgeInsets.all(24),
                    child: Column(mainAxisSize: MainAxisSize.min, children: [
                      const Icon(Icons.error_outline, size: 56, color: AmialColors.yellowDark),
                      const SizedBox(height: 12),
                      Text(_error!,
                          textAlign: TextAlign.center,
                          style: const TextStyle(fontSize: 15, fontWeight: FontWeight.w600)),
                      const SizedBox(height: 16),
                      OutlinedButton.icon(
                        onPressed: _load,
                        icon: const Icon(Icons.refresh),
                        label: const Text('إعادة المحاولة'),
                      ),
                    ]),
                  ),
                )
              : RefreshIndicator(
                  onRefresh: _load,
                  child: ListView(
                    padding: const EdgeInsets.all(12),
                    children: [
                      _header(),
                      if (_variants.isEmpty)
                        const Padding(
                          padding: EdgeInsets.symmetric(vertical: 40),
                          child: Center(child: Text('لا أنواع لهذا الصنف بعد')),
                        ),
                      ..._variants.map(_row),
                    ],
                  ),
                ),
    );
  }

  Widget _header() => Container(
        margin: const EdgeInsets.only(bottom: 12),
        padding: const EdgeInsets.all(14),
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(14),
        ),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(widget.productName,
              style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16)),
          const SizedBox(height: 6),
          Text(
            'مجموع المخزون الموزَّع: $_allocated',
            style: const TextStyle(fontSize: 12.5, color: AmialColors.textSecondary),
          ),
          const SizedBox(height: 6),
          const Text(
            'الصنف الأصل صار مِظلّةً لا تُباع، ومخزونُه يُوزَّع هنا على '
            'الأنواع. وكلُّ نوعٍ يُباع ويُخزَّن وحدَه.',
            style: TextStyle(fontSize: 11.5, height: 1.5, color: AmialColors.textMuted),
          ),
        ]),
      );

  Widget _row(Map<String, dynamic> v) {
    final id = v['id'] as int;
    final busy = _saving.contains(id);
    final qty = double.tryParse('${v['quantity']}') ?? 0;

    return Container(
      margin: const EdgeInsets.only(bottom: 10),
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(14),
        border: qty <= 0
            // **والصفرُ يُوسَم** — فهو ما يجعل الكاشيرَ يقول «نفذ»، ويجب
            // أن يُرى هنا قبل أن يُرى هناك.
            ? Border.all(color: AmialColors.red.withValues(alpha: 0.35))
            : null,
      ),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(children: [
          Expanded(
            child: Text('${v['display_name']}',
                style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14)),
          ),
          if (qty <= 0)
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 3),
              decoration: BoxDecoration(
                color: AmialColors.dangerSurface,
                borderRadius: BorderRadius.circular(8),
              ),
              child: const Text('لا مخزون',
                  style: TextStyle(fontSize: 10, color: AmialColors.red)),
            ),
        ]),
        if ('${v['sku'] ?? ''}'.isNotEmpty) ...[
          const SizedBox(height: 2),
          Text('${v['sku']}',
              textDirection: TextDirection.ltr,
              style: const TextStyle(fontSize: 10.5, color: AmialColors.textMuted)),
        ],
        const SizedBox(height: 10),
        Row(children: [
          Expanded(
            child: TextField(
              controller: _price[id],
              keyboardType: const TextInputType.numberWithOptions(decimal: true),
              textAlign: TextAlign.right,
              decoration: const InputDecoration(
                labelText: 'السعر (ر.ي)',
                isDense: true,
                border: OutlineInputBorder(),
              ),
            ),
          ),
          const SizedBox(width: 8),
          Expanded(
            child: TextField(
              controller: _qty[id],
              keyboardType: const TextInputType.numberWithOptions(decimal: true),
              textAlign: TextAlign.right,
              decoration: const InputDecoration(
                labelText: 'المخزون',
                isDense: true,
                border: OutlineInputBorder(),
              ),
            ),
          ),
          const SizedBox(width: 8),
          SizedBox(
            height: 42,
            child: FilledButton(
              onPressed: busy ? null : () => _save(v),
              style: FilledButton.styleFrom(
                backgroundColor: AmialColors.primary,
                padding: const EdgeInsets.symmetric(horizontal: 14),
              ),
              child: busy
                  ? const SizedBox(
                      width: 16,
                      height: 16,
                      child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                  : const Icon(Icons.check, size: 18),
            ),
          ),
        ]),
      ]),
    );
  }
}
