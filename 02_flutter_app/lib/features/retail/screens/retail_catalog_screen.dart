import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amial_pay/common/widgets/vertical_state_view.dart';
import 'package:amial_pay/features/retail/controllers/retail_vertical_controller.dart';
import 'package:amial_pay/theme/amial_colors.dart';

/// AMIAL-RETAIL-VERTICAL-001 · المرحلة ١٠ — **محرّكُ الأصناف**.
///
/// التصنيفاتُ شجرةً، والعلاماتُ والوحدات. **وكان التصنيفُ نصّاً حرّاً**:
/// «مشروبات» و«مشروبات » و«المشروبات» ثلاثةُ تصنيفاتٍ في التقرير.
class RetailCatalogScreen extends StatefulWidget {
  const RetailCatalogScreen({super.key});

  @override
  State<RetailCatalogScreen> createState() => _RetailCatalogScreenState();
}

class _RetailCatalogScreenState extends State<RetailCatalogScreen> {
  RetailVerticalController get c => Get.find<RetailVerticalController>();

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => c.loadCatalog());
  }

  Future<void> _add(String kind) async {
    final name = TextEditingController();

    final go = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(switch (kind) {
          'category' => 'تصنيف جديد',
          'brand' => 'علامة جديدة',
          _ => 'وحدة جديدة',
        }),
        content: TextField(
          controller: name,
          autofocus: true,
          decoration: const InputDecoration(labelText: 'الاسم'),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('إلغاء')),
          ElevatedButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('حفظ')),
        ],
      ),
    );

    if (go != true || name.text.trim().isEmpty) return;

    final data = {'name': name.text.trim()};
    final ok = switch (kind) {
      'category' => await c.addCategory(data),
      'brand' => await c.addBrand(data),
      _ => await c.addUnit({...data, 'decimals': 0}),
    };

    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
      content: Text(ok ? 'تمت الإضافة' : c.lastError.value),
      backgroundColor: ok ? Colors.green : AmialColors.red,
    ));
    if (ok) await c.loadCatalog();
  }

  @override
  Widget build(BuildContext context) {
    return DefaultTabController(
      length: 3,
      child: Scaffold(
        backgroundColor: AmialColors.background,
        appBar: AppBar(
          title: const Text('الأصناف والتصنيفات'),
          backgroundColor: AmialColors.background,
          elevation: 0,
          bottom: const TabBar(tabs: [
            Tab(text: 'التصنيفات'),
            Tab(text: 'العلامات'),
            Tab(text: 'الوحدات'),
          ]),
        ),
        body: Obx(() => VerticalStateView(
              c: c,
              isEmpty: c.categories.isEmpty && c.brands.isEmpty && c.units.isEmpty,
              emptyTitle: 'لا تصنيفات ولا علامات بعد',
              emptyHint: 'التصنيفُ شجرةٌ تُجيب «كم بعتُ من المواد الغذائية؟».',
              emptyIcon: Icons.category_outlined,
              onRetry: c.loadCatalog,
              grantedBy: 'مالك المتجر أو المدير',
              child: TabBarView(children: [
                _categoryTab(),
                _simpleTab(c.brands, 'brand', 'لا علامات'),
                _unitsTab(),
              ]),
            )),
      ),
    );
  }

  Widget _categoryTab() {
    return Stack(children: [
      ListView(
        padding: const EdgeInsets.all(16),
        children: c.categories.map((n) => _node(n, 0)).toList(),
      ),
      _fab('category'),
    ]);
  }

  Widget _node(Map<String, dynamic> n, int depth) {
    final children = ((n['children'] ?? []) as List)
        .map((e) => Map<String, dynamic>.from(e as Map))
        .toList();

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Padding(
          padding: EdgeInsets.only(right: depth * 18.0, bottom: 6),
          child: Row(children: [
            Icon(depth == 0 ? Icons.folder_outlined : Icons.subdirectory_arrow_left_rounded,
                size: 18, color: AmialColors.primary),
            const SizedBox(width: 8),
            Expanded(child: Text('${n['name']}', style: const TextStyle(fontSize: 14))),
            Text('${n['products_count'] ?? 0} صنفاً',
                style: const TextStyle(fontSize: 11, color: AmialColors.textMuted)),
          ]),
        ),
        ...children.map((ch) => _node(ch, depth + 1)),
      ],
    );
  }

  Widget _simpleTab(List<Map<String, dynamic>> rows, String kind, String empty) {
    return Stack(children: [
      rows.isEmpty
          ? Center(child: Text(empty,
              style: const TextStyle(color: AmialColors.textMuted)))
          : ListView(
              padding: const EdgeInsets.all(16),
              children: rows
                  .map((b) => ListTile(
                        dense: true,
                        title: Text('${b['name']}', style: const TextStyle(fontSize: 14)),
                        subtitle: Text('${b['code']}',
                            style: const TextStyle(
                                fontSize: 11, color: AmialColors.textMuted)),
                      ))
                  .toList(),
            ),
      _fab(kind),
    ]);
  }

  Widget _unitsTab() {
    return Stack(children: [
      c.units.isEmpty
          ? const Center(child: Text('لا وحدات',
              style: TextStyle(color: AmialColors.textMuted)))
          : ListView(
              padding: const EdgeInsets.all(16),
              children: c.units
                  .map((u) => ListTile(
                        dense: true,
                        title: Text('${u['name']}', style: const TextStyle(fontSize: 14)),
                        subtitle: Text(
                          // **الكسرُ بنيةٌ لا تنسيق**: نصفُ حبّةٍ خطأ،
                          // ونصفُ كيلو صواب.
                          '${u['code']} · ${u['decimals']} خانة عشرية',
                          style: const TextStyle(
                              fontSize: 11, color: AmialColors.textMuted),
                        ),
                      ))
                  .toList(),
            ),
      _fab('unit'),
    ]);
  }

  Widget _fab(String kind) {
    return Positioned(
      bottom: 16,
      left: 16,
      child: Obx(() => c.can(RetailVerticalController.pCatalogManage)
          ? FloatingActionButton(
              onPressed: () => _add(kind),
              backgroundColor: AmialColors.primary,
              child: const Icon(Icons.add, color: Colors.white),
            )
          : const SizedBox.shrink()),
    );
  }
}
