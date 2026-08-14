import 'package:flutter/material.dart';
import 'package:get/get.dart';

import 'package:amial_pay/features/retail/controllers/retail_vertical_controller.dart';
import 'package:amial_pay/theme/amial_colors.dart';

/// AMIAL-VARIANTS-REACH-001 — **متغيّراتُ الصنف: لونٌ ومقاسٌ ووزن.**
///
/// ══════════════════════════════════════════════════════════════════════
/// **الثمن الذي دُفع:** سأل صاحبُ المشروع: «عند إضافة منتج بالنسبة لتاجرٍ
/// من التطبيق، هل يوجد متغيّرات؟»
///
/// والجوابُ كان: **في الخادم نعم، وفي التطبيق لا.**
///
///   · `ProductCatalogService::generateVariants` كاملة: ضربٌ ديكارتيّ
///     للمحاور، وسقفُ ٢٠٠ متغيّرٍ في المرّة، وإعادةُ التوليد لا تكرّر ما
///     وُلد، والأبُ يصير مِظلّةً لا يُباع.
///   · المسارُ مسجَّلٌ ومحروسٌ بـ`capability:retail.variants`.
///   · **والمستودعُ في التطبيق يحمل النداءَ** — `generateVariants` في
///     `retail_vertical_repo.dart`.
///   · ولا متحكّمَ يناديه، ولا شاشةَ تفتحه.
///
/// **مبنيٌّ ولا يُوصَل إليه** — من أوّله إلى آخره إلّا آخر شبر.
/// ══════════════════════════════════════════════════════════════════════
class ProductVariantsScreen extends StatefulWidget {
  final int productId;
  final String productName;

  const ProductVariantsScreen({
    super.key,
    required this.productId,
    required this.productName,
  });

  @override
  State<ProductVariantsScreen> createState() => _ProductVariantsScreenState();
}

class _Axis {
  final TextEditingController name;
  final TextEditingController values;
  _Axis(String n, String v)
      : name = TextEditingController(text: n),
        values = TextEditingController(text: v);

  void dispose() {
    name.dispose();
    values.dispose();
  }
}

class _ProductVariantsScreenState extends State<ProductVariantsScreen> {
  final _c = Get.find<RetailVerticalController>();
  final List<_Axis> _axes = [_Axis('', '')];

  @override
  void dispose() {
    for (final a in _axes) {
      a.dispose();
    }
    super.dispose();
  }

  /// المحاورُ الصالحةُ وحدَها — محورٌ بلا اسمٍ أو بلا قيمٍ يُهمَل.
  Map<String, List<String>> _collect() {
    final out = <String, List<String>>{};
    for (final a in _axes) {
      final name = a.name.text.trim();
      final values = a.values.text
          .split(RegExp(r'[,،\n]'))
          .map((v) => v.trim())
          .where((v) => v.isNotEmpty)
          .toSet()   // «أحمر، أحمر» لا تُنتج صنفين
          .toList();
      if (name.isNotEmpty && values.isNotEmpty) out[name] = values;
    }
    return out;
  }

  /// **العددُ يُحسب قبل الضغط لا بعده.**
  ///
  /// ثلاثةُ محاورَ بعشرِ قيمٍ = ألفُ صنفٍ في ضغطةٍ واحدة، ولا يُتراجع عنها
  /// بضغطة. والخادمُ يرفض ما فوق ٢٠٠ — فيُقال العددُ هنا قبل أن يُرفض هناك.
  int _combinations(Map<String, List<String>> axes) =>
      axes.isEmpty ? 0 : axes.values.fold(1, (t, v) => t * v.length);

  Future<void> _submit() async {
    final axes = _collect();
    final n = _combinations(axes);

    if (axes.isEmpty) {
      Get.snackbar('لا محاور', 'أدخل محوراً واحداً على الأقلّ بقيمه');
      return;
    }
    if (n > 200) {
      Get.snackbar('كثيرٌ جداً', 'هذه المحاور تُنتج $n متغيّراً — الحدّ ٢٠٠ في المرّة');
      return;
    }

    final ok = await Get.dialog<bool>(AlertDialog(
      title: const Text('توليد المتغيّرات'),
      content: Text('سيُنشأ $n صنفاً من «${widget.productName}».\n\n'
          'ويصير الأصلُ مِظلّةً لا تُباع وحدَها — فبيعُه يعني بيعَ صنفٍ بلا لون.'),
      actions: [
        TextButton(onPressed: () => Get.back(result: false), child: const Text('تراجع')),
        FilledButton(onPressed: () => Get.back(result: true), child: const Text('توليد')),
      ],
    ));

    if (ok != true) return;

    final done = await _c.generateVariants(widget.productId, axes);

    if (!mounted) return;

    if (done) {
      Get.back(result: true);
      Get.snackbar('تمّ', 'وُلّد $n متغيّراً — كلٌّ منها صنفٌ يُباع ويُخزَّن');
    } else {
      // **سببُ الرفض يُعرض** — و«فشلت العملية» تُرسل التاجرَ يعيد المحاولة
      // بلا تغيير. والباقةُ الناقصةُ تُقال باسمها.
      Get.snackbar('تعذّر', _c.lastError.value.isEmpty
          ? 'تعذّر توليدُ المتغيّرات'
          : _c.lastError.value);
    }
  }

  @override
  Widget build(BuildContext context) {
    final axes = _collect();
    final n = _combinations(axes);

    return Scaffold(
      appBar: AppBar(
        title: const Text('متغيّرات الصنف'),
        backgroundColor: AmialColors.primary,
        foregroundColor: Colors.white,
      ),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          Card(
            child: Padding(
              padding: const EdgeInsets.all(12),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(widget.productName,
                      style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 15)),
                  const SizedBox(height: 6),
                  const Text(
                    'المحور اسمٌ وقيم: «اللون: أحمر، أزرق» و«المقاس: S، L» '
                    'تُنتج أربعةَ أصناف — لكلٍّ سعرُه ومخزونُه وباركودُه.',
                    style: TextStyle(fontSize: 12, color: AmialColors.textSecondary),
                  ),
                ],
              ),
            ),
          ),
          const SizedBox(height: 12),

          for (var i = 0; i < _axes.length; i++)
            Card(
              key: ValueKey(_axes[i]),
              child: Padding(
                padding: const EdgeInsets.all(12),
                child: Column(
                  children: [
                    Row(
                      children: [
                        Expanded(
                          child: TextField(
                            controller: _axes[i].name,
                            onChanged: (_) => setState(() {}),
                            decoration: const InputDecoration(
                              labelText: 'اسم المحور',
                              hintText: 'اللون · المقاس · الوزن',
                              isDense: true,
                            ),
                          ),
                        ),
                        if (_axes.length > 1)
                          IconButton(
                            icon: const Icon(Icons.delete_outline, color: AmialColors.red),
                            onPressed: () => setState(() {
                              _axes.removeAt(i).dispose();
                            }),
                          ),
                      ],
                    ),
                    const SizedBox(height: 8),
                    TextField(
                      controller: _axes[i].values,
                      onChanged: (_) => setState(() {}),
                      maxLines: 2,
                      decoration: const InputDecoration(
                        labelText: 'القيم',
                        hintText: 'أحمر، أزرق، أسود',
                        helperText: 'افصل بفاصلةٍ أو سطرٍ جديد',
                        isDense: true,
                      ),
                    ),
                  ],
                ),
              ),
            ),

          TextButton.icon(
            onPressed: _axes.length >= 3
                // **ثلاثةُ محاورَ سقفٌ عمليّ**: أربعةٌ بخمسِ قيمٍ = ٦٢٥،
                // ويرفضها الخادم. فيُمنع هنا بسببه لا بصمت.
                ? null
                : () => setState(() => _axes.add(_Axis('', ''))),
            icon: const Icon(Icons.add),
            label: Text(_axes.length >= 3 ? 'ثلاثةُ محاورَ حدّاً أقصى' : 'محورٌ آخر'),
          ),

          const SizedBox(height: 8),
          Container(
            padding: const EdgeInsets.all(12),
            decoration: BoxDecoration(
              color: (n > 200 ? AmialColors.red : AmialColors.primary).withValues(alpha: 0.08),
              borderRadius: BorderRadius.circular(10),
            ),
            child: Row(
              children: [
                Icon(n > 200 ? Icons.warning_amber : Icons.grid_view,
                    color: n > 200 ? AmialColors.red : AmialColors.primary),
                const SizedBox(width: 10),
                Expanded(
                  child: Text(
                    n == 0
                        ? 'لا محاور بعد'
                        : (n > 200
                            ? '$n متغيّراً — أكثر من الحدّ (٢٠٠)'
                            : 'سيُنشأ $n صنفاً'),
                    style: TextStyle(
                      fontWeight: FontWeight.bold,
                      color: n > 200 ? AmialColors.red : AmialColors.primary,
                    ),
                  ),
                ),
              ],
            ),
          ),

          const SizedBox(height: 16),
          Obx(() => FilledButton.icon(
                // **الزرُّ يُعطَّل بسببٍ مرئيّ** — والعددُ فوقه يقوله.
                onPressed: (_c.isSubmitting.value || n == 0 || n > 200) ? null : _submit,
                icon: _c.isSubmitting.value
                    ? const SizedBox(
                        width: 16, height: 16,
                        child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                    : const Icon(Icons.auto_awesome_motion),
                label: const Text('توليد المتغيّرات'),
                style: FilledButton.styleFrom(
                  backgroundColor: AmialColors.primary,
                  minimumSize: const Size.fromHeight(48),
                ),
              )),
        ],
      ),
    );
  }
}
