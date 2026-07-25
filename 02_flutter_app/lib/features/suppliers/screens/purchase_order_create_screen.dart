import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/features/merchant/controllers/cashier_controller.dart';
import 'package:amyal_pay/features/suppliers/controllers/suppliers_controller.dart';
import 'package:amyal_pay/helper/amial_money.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';

/// AMIAL-SUPPLIERS-004 — إنشاء أمر شراء:
/// اختيار المورد → إضافة بنود (من منتجات المخزون أو حرّة) بكمية وتكلفة
/// وحدة → إجمالي مباشر → حفظ كمسودة.
class PurchaseOrderCreateScreen extends StatefulWidget {
  const PurchaseOrderCreateScreen({
    super.key,
    this.supplierId,
    this.supplierName,
  });

  final int? supplierId;
  final String? supplierName;

  @override
  State<PurchaseOrderCreateScreen> createState() =>
      _PurchaseOrderCreateScreenState();
}

class _PoLine {
  _PoLine({this.productId, required this.name, this.qty = 1, this.cost = 0});
  int? productId;
  String name;
  int qty;
  double cost;
  double get total => qty * cost;
}

class _PurchaseOrderCreateScreenState
    extends State<PurchaseOrderCreateScreen> {
  SuppliersController get c => Get.find<SuppliersController>();
  CashierController get cash => Get.find<CashierController>();

  int? _supplierId;
  final List<_PoLine> _lines = [];
  final _notes = TextEditingController();
  bool _saving = false;

  @override
  void initState() {
    super.initState();
    _supplierId = widget.supplierId;
    if (c.suppliers.isEmpty) c.loadAll();
    if (cash.products.isEmpty) cash.loadProducts();
  }

  @override
  void dispose() {
    _notes.dispose();
    super.dispose();
  }

  double get _total => _lines.fold(0.0, (s, l) => s + l.total);

  /// إضافة بند من منتجات المخزون (التكلفة الافتراضية cost_price).
  Future<void> _addFromInventory() async {
    final p = await showModalBottomSheet<Map<String, dynamic>>(
      context: context,
      backgroundColor: Colors.white,
      shape: const RoundedRectangleBorder(
          borderRadius: BorderRadius.vertical(top: Radius.circular(24))),
      builder: (ctx) => SafeArea(
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          const Padding(
            padding: EdgeInsets.all(14),
            child: Text('اختر منتجاً من المخزون',
                style: TextStyle(fontWeight: FontWeight.bold)),
          ),
          ConstrainedBox(
            constraints: BoxConstraints(
                maxHeight: MediaQuery.of(ctx).size.height * 0.5),
            child: ListView.separated(
              shrinkWrap: true,
              itemCount: cash.products.length,
              separatorBuilder: (_, _) =>
                  const Divider(height: 1, color: AmyalColors.border),
              itemBuilder: (_, i) {
                final p = cash.products[i];
                return ListTile(
                  onTap: () => Navigator.pop(ctx, p),
                  title: Text('${p['name']}',
                      textAlign: TextAlign.right,
                      style: const TextStyle(fontSize: 14)),
                  subtitle: Text(
                      'المخزون: ${AmialMoney.fmt(p['quantity'])} — التكلفة: ${AmialMoney.fmt(p['cost_price'] ?? 0)}',
                      textAlign: TextAlign.right,
                      style: const TextStyle(fontSize: 11)),
                );
              },
            ),
          ),
        ]),
      ),
    );
    if (p == null) return;
    setState(() => _lines.add(_PoLine(
          productId: p['id'] as int?,
          name: '${p['name']}',
          cost: double.tryParse('${p['cost_price'] ?? 0}') ?? 0,
        )));
  }

  Future<void> _addFree() async {
    final name = TextEditingController();
    final cost = TextEditingController();
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('بند حرّ (غير مربوط بمنتج)'),
        content: Column(mainAxisSize: MainAxisSize.min, children: [
          TextField(
              controller: name,
              textAlign: TextAlign.right,
              decoration: const InputDecoration(labelText: 'اسم الصنف *')),
          TextField(
              controller: cost,
              keyboardType: TextInputType.number,
              inputFormatters: [FilteringTextInputFormatter.digitsOnly],
              decoration:
                  const InputDecoration(labelText: 'تكلفة الوحدة (ر.ي) *')),
        ]),
        actions: [
          TextButton(
              onPressed: () => Navigator.pop(ctx, false),
              child: const Text('إلغاء')),
          ElevatedButton(
              onPressed: () => Navigator.pop(ctx, true),
              child: const Text('إضافة')),
        ],
      ),
    );
    if (ok != true) return;
    if (name.text.trim().isEmpty) return;
    setState(() => _lines.add(_PoLine(
          name: name.text.trim(),
          cost: double.tryParse(cost.text.trim()) ?? 0,
        )));
  }

  Future<void> _save() async {
    if (_supplierId == null) {
      _snack('اختر المورد');
      return;
    }
    if (_lines.isEmpty) {
      _snack('أضف بنداً واحداً على الأقل');
      return;
    }
    if (_lines.any((l) => l.cost <= 0)) {
      _snack('حدد تكلفة وحدة لكل بند');
      return;
    }
    setState(() => _saving = true);
    final ok = await c.poCreate({
      'supplier_id': _supplierId,
      if (_notes.text.trim().isNotEmpty) 'notes': _notes.text.trim(),
      'items': _lines
          .map((l) => {
                if (l.productId != null) 'product_id': l.productId,
                'name': l.name,
                'quantity': l.qty,
                'unit_cost': l.cost.toStringAsFixed(0),
              })
          .toList(),
    });
    if (!mounted) return;
    setState(() => _saving = false);
    if (ok) {
      Get.back(result: true);
    } else {
      _snack(c.lastError.value.isEmpty ? 'فشل الإنشاء' : c.lastError.value);
    }
  }

  void _snack(String m) => ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(m), backgroundColor: AmyalColors.red),
      );

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmyalColors.background,
      appBar: AppBar(
        title: const Text('أمر شراء جديد'),
      ),
      bottomNavigationBar: SafeArea(
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Row(children: [
            Expanded(
              child: FilledButton.icon(
                onPressed: _saving ? null : _save,
                icon: _saving
                    ? const SizedBox(
                        height: 16,
                        width: 16,
                        child: CircularProgressIndicator(
                            strokeWidth: 2, color: Colors.white))
                    : const Icon(Icons.save_outlined, size: 18),
                label: Text('حفظ الأمر (${AmialMoney.yer(_total)})'),
                style: FilledButton.styleFrom(
                  backgroundColor: AmyalColors.primary,
                  minimumSize: const Size.fromHeight(52),
                ),
              ),
            ),
          ]),
        ),
      ),
      body: Obx(() {
        return ListView(
          padding: const EdgeInsets.all(16),
          children: [
            // ====== المورد ======
            const Align(
              alignment: Alignment.centerRight,
              child: Text('المورد',
                  style: TextStyle(fontWeight: FontWeight.bold, fontSize: 14)),
            ),
            const SizedBox(height: 8),
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 12),
              decoration: BoxDecoration(
                color: Colors.white,
                borderRadius: BorderRadius.circular(14),
              ),
              child: DropdownButtonHideUnderline(
                child: DropdownButton<int>(
                  value: _supplierId,
                  isExpanded: true,
                  hint: const Text('اختر المورد',
                      textAlign: TextAlign.right,
                      style: TextStyle(fontSize: 14)),
                  items: c.suppliers
                      .map((s) => DropdownMenuItem<int>(
                            value: s['id'] as int,
                            child: Text('${s['name']}',
                                textAlign: TextAlign.right,
                                style: const TextStyle(fontSize: 14)),
                          ))
                      .toList(),
                  onChanged: (v) => setState(() => _supplierId = v),
                ),
              ),
            ),
            const SizedBox(height: 16),

            // ====== البنود ======
            Row(children: [
              TextButton.icon(
                onPressed: _addFree,
                icon: const Icon(Icons.edit_note, size: 18),
                label: const Text('بند حرّ'),
              ),
              TextButton.icon(
                onPressed: _addFromInventory,
                icon: const Icon(Icons.inventory_2_outlined, size: 18),
                label: const Text('من المخزون'),
              ),
              const Spacer(),
              const Text('بنود الأمر',
                  style: TextStyle(fontWeight: FontWeight.bold, fontSize: 14)),
            ]),
            const SizedBox(height: 8),
            if (_lines.isEmpty)
              Container(
                padding: const EdgeInsets.all(24),
                decoration: BoxDecoration(
                  color: Colors.white,
                  borderRadius: BorderRadius.circular(14),
                ),
                child: const Center(
                    child: Text('أضف بنوداً من المخزون أو بنوداً حرّة',
                        style: TextStyle(
                            fontSize: 12, color: AmyalColors.textMuted))),
              )
            else
              ..._lines.asMap().entries.map((e) => _lineCard(e.key, e.value)),
            const SizedBox(height: 14),

            // ====== ملاحظات ======
            TextField(
              controller: _notes,
              textAlign: TextAlign.right,
              maxLines: 2,
              decoration: InputDecoration(
                hintText: 'ملاحظات للمورد (اختياري)',
                hintStyle: const TextStyle(fontSize: 13),
                filled: true,
                fillColor: Colors.white,
                border: OutlineInputBorder(
                    borderRadius: BorderRadius.circular(14),
                    borderSide: BorderSide.none),
              ),
            ),
            const SizedBox(height: 80),
          ],
        );
      }),
    );
  }

  Widget _lineCard(int index, _PoLine l) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 8),
      child: Container(
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(14),
        ),
        child: Column(children: [
          Row(children: [
            IconButton(
              visualDensity: VisualDensity.compact,
              icon: const Icon(Icons.delete_outline,
                  size: 18, color: AmyalColors.red),
              onPressed: () => setState(() => _lines.removeAt(index)),
            ),
            const Spacer(),
            Expanded(
              flex: 4,
              child: Text(l.name,
                  textAlign: TextAlign.right,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(
                      fontWeight: FontWeight.bold, fontSize: 13)),
            ),
          ]),
          Row(children: [
            // الكمية
            Container(
              decoration: BoxDecoration(
                color: const Color(0xFFF6F7F8),
                borderRadius: BorderRadius.circular(10),
              ),
              child: Row(children: [
                IconButton(
                  visualDensity: VisualDensity.compact,
                  icon: const Icon(Icons.add, size: 16),
                  onPressed: () => setState(() => l.qty++),
                ),
                Text('${l.qty}',
                    style: const TextStyle(fontWeight: FontWeight.bold)),
                IconButton(
                  visualDensity: VisualDensity.compact,
                  icon: const Icon(Icons.remove, size: 16),
                  onPressed: l.qty <= 1
                      ? null
                      : () => setState(() => l.qty--),
                ),
              ]),
            ),
            const SizedBox(width: 8),
            // تكلفة الوحدة
            SizedBox(
              width: 110,
              child: TextField(
                controller: TextEditingController(
                    text: l.cost > 0 ? l.cost.toStringAsFixed(0) : '')
                  ..selection = TextSelection.collapsed(
                      offset:
                          (l.cost > 0 ? l.cost.toStringAsFixed(0) : '').length),
                keyboardType: TextInputType.number,
                inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                textAlign: TextAlign.center,
                style: const TextStyle(
                    fontSize: 13, fontWeight: FontWeight.bold),
                decoration: const InputDecoration(
                  isDense: true,
                  suffixText: 'ر.ي',
                  suffixStyle: TextStyle(fontSize: 10),
                  hintText: 'تكلفة الوحدة',
                  hintStyle: TextStyle(fontSize: 10),
                  border: OutlineInputBorder(),
                ),
                onSubmitted: (v) =>
                    setState(() => l.cost = double.tryParse(v) ?? l.cost),
                onChanged: (v) => l.cost = double.tryParse(v) ?? 0,
              ),
            ),
            const Spacer(),
            Text(AmialMoney.yer(l.total),
                style: const TextStyle(
                    fontWeight: FontWeight.bold,
                    fontSize: 13,
                    color: AmyalColors.primary)),
          ]),
        ]),
      ),
    );
  }
}
