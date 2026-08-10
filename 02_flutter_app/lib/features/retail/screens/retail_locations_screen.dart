import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amial_pay/common/widgets/vertical_state_view.dart';
import 'package:amial_pay/features/retail/controllers/retail_vertical_controller.dart';
import 'package:amial_pay/theme/amial_colors.dart';

/// AMIAL-RETAIL-VERTICAL-001 · المرحلة ١٠ — **المواقع والمستودعات**.
///
/// **والمستودعُ ليس متجراً**: يستلم ويخزّن ويجهّز تحويلاتٍ ولا يبيع.
/// وجعلُه فرعاً يُدخله في تقارير المبيعات بصفرٍ دائم.
class RetailLocationsScreen extends StatefulWidget {
  const RetailLocationsScreen({super.key});

  @override
  State<RetailLocationsScreen> createState() => _RetailLocationsScreenState();
}

class _RetailLocationsScreenState extends State<RetailLocationsScreen> {
  RetailVerticalController get c => Get.find<RetailVerticalController>();

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => c.loadLocations());
  }

  Future<void> _add() async {
    final name = TextEditingController();
    final code = TextEditingController();
    String kind = 'store';

    final go = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('موقع جديد'),
        content: StatefulBuilder(
          builder: (_, setLocal) => Column(mainAxisSize: MainAxisSize.min, children: [
            TextField(controller: name,
                decoration: const InputDecoration(labelText: 'الاسم')),
            TextField(controller: code,
                decoration: const InputDecoration(labelText: 'الرمز (مثل WH1)')),
            const SizedBox(height: 12),
            DropdownButtonFormField<String>(
              initialValue: kind,
              decoration: const InputDecoration(labelText: 'النوع'),
              items: const [
                DropdownMenuItem(value: 'store', child: Text('متجر — يبيع')),
                DropdownMenuItem(value: 'warehouse', child: Text('مستودع — لا يبيع')),
              ],
              onChanged: (v) => setLocal(() => kind = v ?? kind),
            ),
          ]),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('إلغاء')),
          ElevatedButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('حفظ')),
        ],
      ),
    );

    if (go != true) return;

    final ok = await c.addLocation({
      'name': name.text.trim(), 'code': code.text.trim(), 'kind': kind,
    });

    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
      content: Text(ok ? 'أُضيف الموقع' : c.lastError.value),
      backgroundColor: ok ? Colors.green : AmialColors.red,
    ));
    if (ok) await c.loadLocations();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(
        title: const Text('المواقع والمستودعات'),
        backgroundColor: AmialColors.background,
        elevation: 0,
      ),
      floatingActionButton: Obx(() => c.can(RetailVerticalController.pLocationManage)
          ? FloatingActionButton(
              onPressed: _add,
              backgroundColor: AmialColors.primary,
              child: const Icon(Icons.add, color: Colors.white),
            )
          : const SizedBox.shrink()),
      body: RefreshIndicator(
        onRefresh: c.loadLocations,
        child: Obx(() => VerticalStateView(
              c: c,
              isEmpty: c.locations.isEmpty,
              emptyTitle: 'لا مواقع',
              emptyHint: 'بلا مواقعَ يصير مخزون كلّ الفروع رقماً واحداً.',
              emptyIcon: Icons.warehouse_outlined,
              onRetry: c.loadLocations,
              grantedBy: 'مالك المتجر',
              child: ListView(
                padding: const EdgeInsets.all(16),
                children: c.locations.map((l) => Card(
                      color: AmialColors.cardSurface,
                      margin: const EdgeInsets.only(bottom: 8),
                      child: ListTile(
                        leading: Icon(
                          l['kind'] == 'warehouse'
                              ? Icons.warehouse_outlined
                              : Icons.store_outlined,
                          color: AmialColors.primary,
                        ),
                        title: Text('${l['name']}',
                            style: const TextStyle(
                                fontWeight: FontWeight.bold, fontSize: 14)),
                        subtitle: Text(
                          '${l['code']}'
                          '${l['city'] != null ? ' · ${l['city']}' : ''}'
                          ' · ${l['kind'] == 'warehouse' ? 'مستودع — لا يبيع' : 'متجر'}',
                          style: const TextStyle(
                              fontSize: 12, color: AmialColors.textMuted),
                        ),
                        trailing: l['is_default'] == true
                            ? const Chip(
                                label: Text('الافتراضي',
                                    style: TextStyle(fontSize: 10)),
                                visualDensity: VisualDensity.compact)
                            : null,
                      ),
                    )).toList(),
              ),
            )),
      ),
    );
  }
}
