import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/features/merchant/controllers/cashier_controller.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';

/// AMIAL-AUDIT-001 — «تدقيق المخزون / الجرد» (التصاميم 56/65):
/// لكل منتج: الكمية المسجلة (الدفترية) مقابل «الكمية الفعلية» تُدخل بعدّاد،
/// مع شارة (مطابق / نقص / زيادة) وملخص الفروقات، ثم «إتمام عملية الجرد»
/// يعتمد الكميات الفعلية دفعة واحدة.
class InventoryAuditScreen extends StatefulWidget {
  const InventoryAuditScreen({super.key});

  @override
  State<InventoryAuditScreen> createState() => _InventoryAuditScreenState();
}

class _InventoryAuditScreenState extends State<InventoryAuditScreen> {
  CashierController get c => Get.find<CashierController>();

  /// productId → الكمية المعدودة فعلياً.
  final Map<int, int> _counted = {};
  final _search = TextEditingController();
  bool _applying = false;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => c.loadProducts());
  }

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  int _book(Map<String, dynamic> p) =>
      (double.tryParse('${p['quantity'] ?? 0}') ?? 0).round();

  int _actual(Map<String, dynamic> p) {
    final id = p['id'] as int;
    return _counted[id] ?? _book(p);
  }

  int _diff(Map<String, dynamic> p) => _actual(p) - _book(p);

  List<Map<String, dynamic>> get _visible {
    final q = _search.text.trim();
    return c.products.where((p) {
      if (p['is_active'] == false || p['is_active'] == 0) return false;
      if (q.isNotEmpty &&
          !'${p['name']}'.contains(q) &&
          !'${p['barcode'] ?? ''}'.contains(q)) {
        return false;
      }
      return true;
    }).toList();
  }

  /// المنتجات المعدَّلة فعلاً (فرق ≠ 0).
  List<Map<String, dynamic>> get _changed =>
      c.products.where((p) => _counted.containsKey(p['id']) && _diff(p) != 0).toList();

  Future<void> _apply() async {
    final changed = _changed;
    if (changed.isEmpty) {
      _snack('لا فروقات لاعتمادها', ok: true);
      return;
    }
    final sure = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('اعتماد الجرد؟'),
        content: Text(
          'سيتم تحديث مخزون ${changed.length} منتج بالكميات الفعلية المعدودة. '
          'هذا الإجراء يعدّل أرصدة المخزون مباشرة.',
          textAlign: TextAlign.right,
        ),
        actions: [
          TextButton(
              onPressed: () => Navigator.pop(ctx, false),
              child: const Text('تراجع')),
          ElevatedButton(
            onPressed: () => Navigator.pop(ctx, true),
            style: ElevatedButton.styleFrom(
                backgroundColor: AmyalColors.primary,
                foregroundColor: Colors.white),
            child: const Text('اعتماد'),
          ),
        ],
      ),
    );
    if (sure != true) return;

    setState(() => _applying = true);
    var okCount = 0;
    for (final p in changed) {
      final id = p['id'] as int;
      final ok = await c.updateProduct(id, {'quantity': _counted[id]});
      if (ok) okCount++;
    }
    if (!mounted) return;
    setState(() {
      _applying = false;
      _counted.clear();
    });
    _snack(
      okCount == changed.length
          ? 'تم اعتماد الجرد — حُدّث $okCount منتج'
          : 'اعتُمد $okCount من ${changed.length} — أعد المحاولة للبقية',
      ok: okCount == changed.length,
    );
  }

  void _snack(String m, {bool ok = false}) =>
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
        content: Text(m),
        backgroundColor: ok ? const Color(0xFF2E7D32) : AmyalColors.red,
      ));

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmyalColors.background,
      appBar: AppBar(
        title: const Text('تدقيق المخزون'),
      ),
      bottomNavigationBar: SafeArea(
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Obx(() {
            // لمس products حتى يعيد Obx البناء عند التحديث
            final _ = c.products.length;
            final diffs = _changed.length;
            return FilledButton.icon(
              onPressed: _applying ? null : _apply,
              icon: _applying
                  ? const SizedBox(
                      height: 18,
                      width: 18,
                      child: CircularProgressIndicator(
                          strokeWidth: 2, color: Colors.white))
                  : const Icon(Icons.checklist_rounded),
              label: Text(
                  diffs == 0
                      ? 'إتمام عملية الجرد'
                      : 'إتمام الجرد واعتماد $diffs فرق',
                  style: const TextStyle(
                      fontSize: 15, fontWeight: FontWeight.w600)),
              style: FilledButton.styleFrom(
                backgroundColor: AmyalColors.primary,
                minimumSize: const Size.fromHeight(54),
                shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(16)),
              ),
            );
          }),
        ),
      ),
      body: Obx(() {
        final items = _visible;
        final total = c.products.length;
        final discovered = c.products
            .where((p) => _counted.containsKey(p['id']) && _diff(p) != 0)
            .length;

        return Column(children: [
          // ====== الملخص ======
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 14, 16, 6),
            child: Row(children: [
              Expanded(
                child: _stat('إجمالي العناصر', '$total', AmyalColors.primary),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: _stat('الفروقات المكتشفة', '$discovered',
                    discovered > 0 ? AmyalColors.red : const Color(0xFF2E7D32)),
              ),
            ]),
          ),

          // ====== البحث ======
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 6),
            child: TextField(
              controller: _search,
              onChanged: (_) => setState(() {}),
              decoration: InputDecoration(
                hintText: 'بحث عن منتج أو رمز...',
                hintStyle: const TextStyle(fontSize: 13),
                prefixIcon: const Icon(Icons.search, size: 20),
                filled: true,
                fillColor: Colors.white,
                contentPadding: EdgeInsets.zero,
                border: OutlineInputBorder(
                  borderRadius: BorderRadius.circular(14),
                  borderSide: BorderSide.none,
                ),
              ),
            ),
          ),

          Expanded(
            child: c.isLoadingProducts.value && c.products.isEmpty
                ? const Center(
                    child:
                        CircularProgressIndicator(color: AmyalColors.primary))
                : ListView.separated(
                    padding: const EdgeInsets.fromLTRB(16, 6, 16, 16),
                    itemCount: items.length,
                    separatorBuilder: (_, _) => const SizedBox(height: 10),
                    itemBuilder: (_, i) => _auditCard(items[i]),
                  ),
          ),
        ]);
      }),
    );
  }

  Widget _stat(String label, String value, Color color) {
    return Container(
      padding: const EdgeInsets.symmetric(vertical: 12),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(14),
      ),
      child: Column(children: [
        Text(value,
            style: TextStyle(
                fontSize: 20, fontWeight: FontWeight.bold, color: color)),
        Text(label,
            style: const TextStyle(fontSize: 11, color: AmyalColors.textMuted)),
      ]),
    );
  }

  Widget _auditCard(Map<String, dynamic> p) {
    final id = p['id'] as int;
    final book = _book(p);
    final actual = _actual(p);
    final diff = actual - book;
    final touched = _counted.containsKey(id);

    final (label, fg, bg) = diff == 0
        ? ('مطابق', const Color(0xFF2E7D32), const Color(0xFFE3F3E5))
        : diff < 0
            ? ('$diff نقص', AmyalColors.red, const Color(0xFFFDE7E7))
            : ('+$diff زيادة', const Color(0xFF2E7D32), const Color(0xFFE3F3E5));

    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(
            color: touched && diff != 0
                ? fg.withValues(alpha: 0.5)
                : AmyalColors.border),
      ),
      child: Column(children: [
        Row(children: [
          if (touched)
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
              decoration: BoxDecoration(
                color: bg,
                borderRadius: BorderRadius.circular(10),
              ),
              child: Text(label,
                  style: TextStyle(
                      fontSize: 10, fontWeight: FontWeight.bold, color: fg)),
            ),
          const Spacer(),
          Expanded(
            flex: 3,
            child: Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
              Text('${p['name']}',
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(
                      fontWeight: FontWeight.bold, fontSize: 14)),
              if ('${p['barcode'] ?? ''}'.isNotEmpty)
                Text('SKU: ${p['barcode']}',
                    textDirection: TextDirection.ltr,
                    style: const TextStyle(
                        fontSize: 10, color: AmyalColors.textMuted)),
            ]),
          ),
        ]),
        const SizedBox(height: 10),
        Row(children: [
          // الكمية الفعلية (عدّاد + إدخال مباشر)
          Container(
            decoration: BoxDecoration(
              color: const Color(0xFFF6F7F8),
              borderRadius: BorderRadius.circular(10),
              border: Border.all(
                  color: touched && diff != 0
                      ? fg.withValues(alpha: 0.6)
                      : Colors.transparent),
            ),
            child: Row(children: [
              IconButton(
                visualDensity: VisualDensity.compact,
                icon: const Icon(Icons.add_circle_outline, size: 18),
                onPressed: () =>
                    setState(() => _counted[id] = actual + 1),
              ),
              SizedBox(
                width: 46,
                child: TextField(
                  controller: TextEditingController(text: '$actual')
                    ..selection = TextSelection.collapsed(
                        offset: '$actual'.length),
                  keyboardType: TextInputType.number,
                  inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                  textAlign: TextAlign.center,
                  style: const TextStyle(
                      fontWeight: FontWeight.bold, fontSize: 15),
                  decoration: const InputDecoration(
                      border: InputBorder.none, isDense: true),
                  onSubmitted: (v) => setState(
                      () => _counted[id] = int.tryParse(v) ?? actual),
                ),
              ),
              IconButton(
                visualDensity: VisualDensity.compact,
                icon: const Icon(Icons.remove_circle_outline, size: 18),
                onPressed: actual <= 0
                    ? null
                    : () => setState(() => _counted[id] = actual - 1),
              ),
            ]),
          ),
          const SizedBox(width: 6),
          const Text('الكمية الفعلية',
              style: TextStyle(fontSize: 10, color: AmyalColors.textMuted)),
          const Spacer(),
          Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
            Text('$book',
                style: const TextStyle(
                    fontSize: 16, fontWeight: FontWeight.bold)),
            const Text('الكمية المسجلة',
                style: TextStyle(fontSize: 10, color: AmyalColors.textMuted)),
          ]),
        ]),
      ]),
    );
  }
}
