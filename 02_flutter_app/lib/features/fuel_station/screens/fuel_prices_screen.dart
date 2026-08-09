import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:get/get.dart';
import 'package:amial_pay/theme/amial_colors.dart';
import 'package:amial_pay/features/fuel_station/controllers/fuel_station_controller.dart';
import 'package:amial_pay/features/fuel_station/controllers/fuel_vertical_controller.dart';
import 'package:amial_pay/features/fuel_station/widgets/fuel_state_view.dart';

/// AMIAL-FUEL-VERTICAL-001 · المرحلة ٨ — الأسعار: اقتراحٌ ثمّ اعتماد.
///
/// **والسعرُ أخطرُ رقمٍ في المحطّة.** من يملك تغييرَه وحدَه يبيع بأقلّ
/// ويأخذ الفرق — **ولا يظهر في أيّ جردٍ لأنّ اللترات مطابقة**. ولذلك
/// فُصلت اليدُ التي تقترح عن اليد التي تعتمد.
class FuelPricesScreen extends StatefulWidget {
  const FuelPricesScreen({super.key});

  @override
  State<FuelPricesScreen> createState() => _FuelPricesScreenState();
}

class _FuelPricesScreenState extends State<FuelPricesScreen> {
  late final FuelVerticalController c;
  late final FuelStationController station;

  @override
  void initState() {
    super.initState();
    c = Get.find<FuelVerticalController>();
    station = Get.find<FuelStationController>();

    WidgetsBinding.instance.addPostFrameCallback((_) async {
      await station.loadProducts();
      await c.loadPendingPrices();
    });
  }

  Future<void> _refresh() async {
    await station.loadProducts();
    await c.loadPendingPrices();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(title: const Text('الأسعار')),
      body: RefreshIndicator(
        onRefresh: _refresh,
        child: FuelStateView(
          c: c,
          isEmpty: station.products.isEmpty && c.pendingPrices.isEmpty,
          emptyTitle: 'لا أنواع وقود معرّفة',
          emptyHint: 'أضف نوع وقود من الإعدادات ليظهر سعره هنا.',
          emptyIcon: Icons.price_change_outlined,
          onRetry: _refresh,
          child: Obx(() => ListView(
                padding: const EdgeInsets.all(16),
                children: [
                  if (c.pendingPrices.isNotEmpty) ...[
                    _header('بانتظار الاعتماد', Icons.pending_actions_rounded),
                    for (final p in c.pendingPrices) _pendingCard(p),
                    const SizedBox(height: 20),
                  ],
                  _header('الأسعار السارية', Icons.local_offer_rounded),
                  for (final p in station.products) _productCard(p),
                  const SizedBox(height: 24),
                ],
              )),
        ),
      ),
    );
  }

  Widget _header(String t, IconData i) => Padding(
        padding: const EdgeInsets.only(bottom: 8, top: 4),
        child: Row(children: [
          Icon(i, size: 18, color: AmialColors.textSecondary),
          const SizedBox(width: 8),
          Text(t, style: TextStyle(
              fontWeight: FontWeight.bold, color: AmialColors.textSecondary)),
        ]),
      );

  Widget _pendingCard(Map<String, dynamic> p) {
    return Card(
      key: Key('fuel-pending-price-${p['id']}'),
      color: const Color(0xFFFFF8E1),
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(children: [
            Text('${p['product']}', style: const TextStyle(fontWeight: FontWeight.bold)),
            const Spacer(),
            Text('${p['price_per_liter']} ريال/لتر',
                style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16)),
          ]),
          const SizedBox(height: 6),
          Text('السبب: ${p['reason']}',
              style: TextStyle(fontSize: 12, color: AmialColors.textSecondary)),
          const SizedBox(height: 12),

          if (c.can('fuel.price.approve'))
            SizedBox(
              width: double.infinity,
              child: ElevatedButton.icon(
                key: Key('fuel-approve-price-${p['id']}'),
                onPressed: () => _confirmApprove(p),
                style: ElevatedButton.styleFrom(
                    backgroundColor: Colors.green.shade700,
                    foregroundColor: Colors.white),
                icon: const Icon(Icons.check_rounded, size: 18),
                label: const Text('اعتماد — يسري فوراً'),
              ),
            )
          else
            // **الزرُّ غائبٌ ويُقال لماذا.**
            Text('الاعتماد يحتاج صلاحية لا تملكها',
                style: TextStyle(fontSize: 12, color: AmialColors.textMuted)),
        ]),
      ),
    );
  }

  Widget _productCard(Map<String, dynamic> p) {
    return Card(
      key: Key('fuel-product-${p['id']}'),
      child: ListTile(
        leading: CircleAvatar(
          backgroundColor: AmialColors.primary.withValues(alpha: 0.1),
          child: Icon(Icons.local_gas_station_rounded, color: AmialColors.primary),
        ),
        title: Text('${p['name']}'),
        subtitle: Text('${p['price_per_liter']} ريال/لتر'),
        trailing: c.can('fuel.price.propose')
            ? TextButton(
                key: Key('fuel-propose-${p['id']}'),
                onPressed: () => _proposeDialog(p),
                child: const Text('اقتراح سعر'),
              )
            : null,
      ),
    );
  }

  Future<void> _proposeDialog(Map<String, dynamic> p) async {
    final priceCtrl = TextEditingController();
    final reasonCtrl = TextEditingController();

    final ok = await Get.dialog<bool>(AlertDialog(
      title: Text('سعر جديد لـ ${p['name']}'),
      content: Column(mainAxisSize: MainAxisSize.min, children: [
        Text('السعر الحالي ${p['price_per_liter']}',
            style: const TextStyle(fontSize: 12)),
        const SizedBox(height: 12),
        TextField(
          key: const Key('fuel-propose-price'),
          controller: priceCtrl,
          autofocus: true,
          keyboardType: const TextInputType.numberWithOptions(decimal: true),
          inputFormatters: [FilteringTextInputFormatter.allow(RegExp(r'[0-9.]'))],
          decoration: const InputDecoration(labelText: 'السعر المقترح (ريال/لتر)'),
        ),
        const SizedBox(height: 12),
        TextField(
          key: const Key('fuel-propose-reason'),
          controller: reasonCtrl,
          decoration: const InputDecoration(
            labelText: 'السبب',
            helperText: 'إلزامي — بلا سبب لا يُراجَع القرار',
          ),
        ),
      ]),
      actions: [
        TextButton(onPressed: () => Get.back(result: false), child: const Text('إلغاء')),
        ElevatedButton(
          key: const Key('fuel-propose-save'),
          onPressed: () => Get.back(result: true),
          child: const Text('اقتراح'),
        ),
      ],
    ));

    if (ok != true) return;

    final done = await c.proposePrice({
      'fuel_product_id': p['id'],
      'price_per_liter': priceCtrl.text.trim(),
      'reason': reasonCtrl.text.trim(),
    });

    if (!mounted) return;
    _snack(done ? 'اقتُرح السعر — لن يسري قبل الاعتماد' : c.lastError.value,
        error: !done);
  }

  Future<void> _confirmApprove(Map<String, dynamic> p) async {
    final ok = await Get.dialog<bool>(AlertDialog(
      title: const Text('تأكيد اعتماد السعر'),
      content: Text('سيصير سعر ${p['product']} هو ${p['price_per_liter']} ريال/لتر، '
          'ويسري على كل بيعة بعد الآن.'),
      actions: [
        TextButton(onPressed: () => Get.back(result: false), child: const Text('تراجع')),
        ElevatedButton(
          key: const Key('fuel-approve-confirm'),
          onPressed: () => Get.back(result: true),
          child: const Text('اعتمد'),
        ),
      ],
    ));

    if (ok != true) return;

    final done = await c.approvePrice(p['id'] as int);

    if (!mounted) return;

    if (done) await station.loadProducts();
    _snack(done ? 'اعتُمد السعر وسرى' : c.lastError.value, error: !done);
  }

  void _snack(String msg, {bool error = false}) {
    if (msg.trim().isEmpty) return;
    Get.snackbar(error ? 'تنبيه' : 'تم', msg,
        backgroundColor: error ? Colors.red.shade50 : Colors.green.shade50,
        colorText: error ? Colors.red.shade900 : Colors.green.shade900,
        snackPosition: SnackPosition.BOTTOM,
        duration: const Duration(seconds: 4));
  }
}
