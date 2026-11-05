import 'package:flutter/material.dart';
import 'package:get/get.dart';

import 'package:amial_pay/features/retail/controllers/retail_vertical_controller.dart';
import 'package:amial_pay/theme/amial_colors.dart';

/// AMIAL-PRODUCT-ATTRIBUTES-001 — **مكتبةُ السمات: تُعرَّف مرّةً.**
///
/// ══════════════════════════════════════════════════════════════════════
/// طلب صاحبُ المشروع تدفّقَ WooCommerce: **السماتُ أوّلاً** («اللون» ثمّ
/// «تكوين العناصر»: أحمر · أزرق)، ثمّ تُختار في كلّ منتج.
///
/// **والقائمُ كان يكتب القيمَ نصّاً حرّاً في كلّ توليد.** وثمنُه يظهر بعد
/// المنتج العاشر: «أحمر» و«احمر» قيمتان مختلفتان تماماً، **فمتغيّران
/// للون واحدٍ ينقسم مخزونُهما بينهما** ولا يمسكه شيء.
///
/// والخادمُ يطبّع الهمزاتِ والألفَ المقصورةَ والتشكيل، **فالكتابتان
/// تلتقيان قيمةً واحدة** — وهذه الشاشةُ تُغني عن الكتابة أصلاً.
class ProductAttributesScreen extends StatefulWidget {
  const ProductAttributesScreen({super.key});

  @override
  State<ProductAttributesScreen> createState() => _ProductAttributesScreenState();
}

class _ProductAttributesScreenState extends State<ProductAttributesScreen> {
  final _c = Get.find<RetailVerticalController>();
  List<Map<String, dynamic>> _attrs = [];
  bool _loading = true;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });

    final list = await _c.loadAttributes();

    if (!mounted) return;
    setState(() {
      _loading = false;
      if (list == null) {
        // **الفشلُ يُقال ولا يُعرَض فراغاً** — «لا سمات» على نداءٍ ردَّ
        // ٤٠٢ أو ٤٠١ يُقرأ «فحصنا فلم نجد». (القاعدة السابعة.)
        _error = _c.lastError.value.isEmpty
            ? 'تعذّر تحميل السمات'
            : _c.lastError.value;
      } else {
        _attrs = list;
      }
    });
  }

  Future<void> _addAttribute() async {
    final name = TextEditingController();
    final terms = TextEditingController();

    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('سمة جديدة'),
        content: Column(mainAxisSize: MainAxisSize.min, children: [
          TextField(
            controller: name,
            textAlign: TextAlign.right,
            decoration: const InputDecoration(
              labelText: 'اسم السمة',
              hintText: 'اللون · المقاس · الوزن',
              border: OutlineInputBorder(),
            ),
          ),
          const SizedBox(height: 12),
          TextField(
            controller: terms,
            textAlign: TextAlign.right,
            maxLines: 3,
            decoration: const InputDecoration(
              labelText: 'القيم — تُفصَل بفاصلة',
              hintText: 'أحمر، أزرق، أخضر',
              border: OutlineInputBorder(),
            ),
          ),
          const SizedBox(height: 8),
          const Text(
            'تُعرَّف مرّةً وتُستعمَل في كلّ منتج. والقيمُ المتشابهةُ إملاءً '
            'تلتقي واحدةً («أحمر» و«احمر»).',
            style: TextStyle(fontSize: 11, color: AmialColors.textSecondary),
          ),
        ]),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('إلغاء')),
          FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('إضافة')),
        ],
      ),
    );

    if (ok != true || !mounted) return;
    if (name.text.trim().isEmpty) {
      _snack('اسم السمة مطلوب');
      return;
    }

    final done = await _c.addAttribute(name.text.trim(), _split(terms.text));
    if (!mounted) return;
    done ? _load() : _snack(_c.lastError.value);
  }

  Future<void> _addTerms(Map<String, dynamic> attr) async {
    final terms = TextEditingController();

    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text('قيم «${attr['name']}»'),
        content: TextField(
          controller: terms,
          textAlign: TextAlign.right,
          maxLines: 3,
          decoration: const InputDecoration(
            labelText: 'قيم جديدة — تُفصَل بفاصلة',
            border: OutlineInputBorder(),
          ),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('إلغاء')),
          FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('إضافة')),
        ],
      ),
    );

    if (ok != true || !mounted) return;
    final list = _split(terms.text);
    if (list.isEmpty) return;

    final done = await _c.addAttributeTerms(attr['id'] as int, list);
    if (!mounted) return;
    done ? _load() : _snack(_c.lastError.value);
  }

  List<String> _split(String raw) => raw
      .split(RegExp(r'[,،\n]'))
      .map((v) => v.trim())
      .where((v) => v.isNotEmpty)
      .toList();

  void _snack(String m) => ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(content: Text(m.isEmpty ? 'تعذّر التنفيذ' : m),
          backgroundColor: AmialColors.red));

  Future<void> _confirmDelete(Map<String, dynamic> attr) async {
    final n = ((attr['terms'] ?? []) as List).length;

    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text('حذف «${attr['name']}»'),
        // **ويُقال ما يُحذف معها** — سمةٌ فيها عشرُ قيمٍ تذهب كلُّها.
        content: Text('ستُحذف السمةُ و$n قيمةً معها.\n\n'
            'والمتغيّراتُ المولَّدةُ سابقاً لا تتأثّر — هي أصنافٌ قائمةٌ '
            'بذاتها.'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('تراجع')),
          FilledButton(
            style: FilledButton.styleFrom(backgroundColor: AmialColors.red),
            onPressed: () => Navigator.pop(ctx, true),
            child: const Text('حذف'),
          ),
        ],
      ),
    );

    if (ok != true || !mounted) return;
    final done = await _c.deleteAttribute(attr['id'] as int);
    if (!mounted) return;
    done ? _load() : _snack(_c.lastError.value);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(
        title: const Text('سمات المنتجات'),
        backgroundColor: AmialColors.primary,
        foregroundColor: Colors.white,
      ),
      floatingActionButton: _error == null
          ? FloatingActionButton.extended(
              onPressed: _addAttribute,
              backgroundColor: AmialColors.primary,
              icon: const Icon(Icons.add),
              label: const Text('سمة جديدة'),
            )
          : null,
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : _error != null
              ? _errorView()
              : RefreshIndicator(
                  onRefresh: _load,
                  child: ListView(
                    padding: const EdgeInsets.all(12),
                    children: [
                      const Padding(
                        padding: EdgeInsets.all(8),
                        child: Text(
                          'عرّف السمةَ وقيمَها مرّةً — ثمّ اخترها عند توليد '
                          'أنواع أيّ منتج، بدل كتابة القيم في كلّ مرّة.',
                          style: TextStyle(
                              fontSize: 12,
                              height: 1.5,
                              color: AmialColors.textSecondary),
                        ),
                      ),
                      if (_attrs.isEmpty)
                        const Padding(
                          padding: EdgeInsets.symmetric(vertical: 40),
                          child: Center(
                            child: Text('لا سمات بعد — ابدأ بـ«اللون» أو «المقاس»'),
                          ),
                        ),
                      ..._attrs.map(_card),
                      const SizedBox(height: 80),
                    ],
                  ),
                ),
    );
  }

  Widget _errorView() => Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            const Icon(Icons.workspace_premium, size: 56, color: AmialColors.yellowDark),
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
      );

  Widget _card(Map<String, dynamic> attr) {
    final terms = ((attr['terms'] ?? []) as List)
        .map((e) => Map<String, dynamic>.from(e as Map))
        .toList();

    return Container(
      margin: const EdgeInsets.only(bottom: 10),
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(14),
      ),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(children: [
          Expanded(
            child: Text('${attr['name']}',
                style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 15)),
          ),
          IconButton(
            tooltip: 'إضافة قيم',
            icon: const Icon(Icons.add_circle_outline, color: AmialColors.primary),
            onPressed: () => _addTerms(attr),
          ),
          IconButton(
            tooltip: 'حذف السمة',
            icon: const Icon(Icons.delete_outline, color: AmialColors.red),
            onPressed: () => _confirmDelete(attr),
          ),
        ]),
        const SizedBox(height: 6),
        if (terms.isEmpty)
          // **سمةٌ بلا قيمٍ لا تولّد شيئاً** — فتُقال حالتُها ولا تُترَك
          // تبدو جاهزة.
          const Text('لا قيم بعد — أضِف قيمةً واحدةً على الأقلّ لتُستعمَل',
              style: TextStyle(fontSize: 11.5, color: AmialColors.red))
        else
          Wrap(
            spacing: 6,
            runSpacing: 6,
            children: terms
                .map((t) => Chip(
                      label: Text('${t['value']}', style: const TextStyle(fontSize: 12)),
                      onDeleted: () async {
                        final done = await _c.deleteAttributeTerm(t['id'] as int);
                        if (!mounted) return;
                        done ? _load() : _snack(_c.lastError.value);
                      },
                      deleteIconColor: AmialColors.textMuted,
                      backgroundColor: AmialColors.background,
                    ))
                .toList(),
          ),
      ]),
    );
  }
}
