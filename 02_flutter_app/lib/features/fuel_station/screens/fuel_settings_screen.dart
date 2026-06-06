import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';
import 'package:amyal_pay/features/fuel_station/controllers/fuel_station_controller.dart';

/// AMIAL-FUEL-001 — شاشة إعدادات المحطة.
/// تابات: 1) المضخّات 2) الأنواع والأسعار 3) بيانات المحطة
class FuelSettingsScreen extends StatefulWidget {
  const FuelSettingsScreen({super.key});

  @override
  State<FuelSettingsScreen> createState() => _FuelSettingsScreenState();
}

class _FuelSettingsScreenState extends State<FuelSettingsScreen> {
  late final FuelStationController c;

  @override
  void initState() {
    super.initState();
    c = Get.find<FuelStationController>();
    WidgetsBinding.instance.addPostFrameCallback((_) async {
      await Future.wait([c.loadStation(), c.loadPumps(), c.loadProducts()]);
    });
  }

  @override
  Widget build(BuildContext context) {
    return DefaultTabController(
      length: 3,
      child: Scaffold(
        backgroundColor: AmyalColors.background,
        appBar: AppBar(
          title: const Text('إعدادات المحطة'),
          backgroundColor: AmyalColors.primary,
          foregroundColor: Colors.white,
          bottom: const TabBar(
            indicatorColor: AmyalColors.yellow,
            labelColor: Colors.white,
            unselectedLabelColor: Colors.white70,
            tabs: [
              Tab(text: 'المضخّات', icon: Icon(Icons.local_gas_station, size: 18)),
              Tab(text: 'الأنواع والأسعار', icon: Icon(Icons.attach_money, size: 18)),
              Tab(text: 'بيانات المحطة', icon: Icon(Icons.info, size: 18)),
            ],
          ),
        ),
        body: TabBarView(children: [
          _pumpsTab(),
          _productsTab(),
          _stationTab(),
        ]),
      ),
    );
  }

  // ==================== Tab 1: المضخّات ====================
  Widget _pumpsTab() {
    return Obx(() {
      if (c.isLoading.value && c.pumps.isEmpty) {
        return const Center(child: CircularProgressIndicator());
      }
      return Scaffold(
        backgroundColor: AmyalColors.background,
        floatingActionButton: FloatingActionButton.extended(
          backgroundColor: AmyalColors.primary,
          onPressed: _addPumpDialog,
          icon: const Icon(Icons.add),
          label: const Text('مضخّة جديدة'),
        ),
        body: c.pumps.isEmpty
            ? _emptyState(
                Icons.local_gas_station,
                'لم تُضِف أيّ مضخّة بعد',
                'اضغط الزر بالأسفل لإضافة أوّل مضخّة',
              )
            : ListView.builder(
                padding: const EdgeInsets.all(16),
                itemCount: c.pumps.length,
                itemBuilder: (_, i) => _pumpCard(c.pumps[i]),
              ),
      );
    });
  }

  Widget _pumpCard(Map<String, dynamic> pump) {
    final isMechanical = pump['pump_type'] == 'mechanical';
    final isActive = pump['is_active'] == true;

    return Container(
      margin: const EdgeInsets.only(bottom: 10),
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(12),
        border: !isActive ? Border.all(color: Colors.red.shade200) : null,
      ),
      child: Row(children: [
        Container(
          width: 50, height: 50,
          decoration: BoxDecoration(
            color: isActive ? AmyalColors.primary.withOpacity(0.1) : Colors.grey.shade200,
            borderRadius: BorderRadius.circular(12),
          ),
          child: Icon(Icons.local_gas_station,
              color: isActive ? AmyalColors.primary : Colors.grey, size: 26),
        ),
        const SizedBox(width: 12),
        Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(children: [
            Text('مضخّة ${pump['pump_number']}',
                style: const TextStyle(fontSize: 16, fontWeight: FontWeight.bold)),
            const SizedBox(width: 8),
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
              decoration: BoxDecoration(
                color: isMechanical
                    ? AmyalColors.yellow.withOpacity(0.3)
                    : Colors.blue.withOpacity(0.15),
                borderRadius: BorderRadius.circular(8),
              ),
              child: Text(isMechanical ? 'يدوية' : 'إلكترونية',
                  style: const TextStyle(fontSize: 10, fontWeight: FontWeight.bold)),
            ),
          ]),
          if (pump['pump_name'] != null)
            Text(pump['pump_name'], style: TextStyle(color: Colors.grey.shade600, fontSize: 12)),
          const SizedBox(height: 4),
          if (isMechanical)
            Text('العدّاد: ${pump['current_meter_reading']}',
                style: TextStyle(color: Colors.grey.shade700, fontSize: 11)),
          if (!isActive)
            Text('غير نشطة', style: TextStyle(color: Colors.red.shade600, fontSize: 11)),
        ])),
        Switch(
          value: isActive,
          activeColor: Colors.green,
          onChanged: (v) => c.updatePump(pump['id'], {'is_active': v}),
        ),
      ]),
    );
  }

  void _addPumpDialog() {
    final numberCtrl = TextEditingController();
    final nameCtrl = TextEditingController();
    final meterCtrl = TextEditingController(text: '0');
    String pumpType = 'mechanical';

    showDialog(context: context, builder: (ctx) => StatefulBuilder(
      builder: (_, setSt) => AlertDialog(
        title: const Text('إضافة مضخّة'),
        content: SingleChildScrollView(
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            TextField(
              controller: numberCtrl,
              keyboardType: TextInputType.number,
              inputFormatters: [FilteringTextInputFormatter.digitsOnly],
              decoration: const InputDecoration(labelText: 'رقم المضخّة *'),
            ),
            const SizedBox(height: 8),
            TextField(
              controller: nameCtrl,
              decoration: const InputDecoration(labelText: 'الوصف (اختياري)'),
            ),
            const SizedBox(height: 12),
            const Align(alignment: Alignment.centerRight,
                child: Text('نوع المضخّة:', style: TextStyle(fontWeight: FontWeight.bold))),
            const SizedBox(height: 4),
            Row(children: [
              Expanded(child: RadioListTile<String>(
                title: const Text('يدوية', style: TextStyle(fontSize: 13)),
                value: 'mechanical',
                groupValue: pumpType,
                onChanged: (v) => setSt(() => pumpType = v!),
                contentPadding: EdgeInsets.zero,
                dense: true,
              )),
              Expanded(child: RadioListTile<String>(
                title: const Text('إلكترونية', style: TextStyle(fontSize: 13)),
                value: 'electronic',
                groupValue: pumpType,
                onChanged: (v) => setSt(() => pumpType = v!),
                contentPadding: EdgeInsets.zero,
                dense: true,
              )),
            ]),
            if (pumpType == 'mechanical') ...[
              const SizedBox(height: 4),
              TextField(
                controller: meterCtrl,
                keyboardType: const TextInputType.numberWithOptions(decimal: true),
                decoration: const InputDecoration(labelText: 'قراءة العدّاد الحالية'),
              ),
            ],
          ]),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('إلغاء')),
          Obx(() => FilledButton(
            onPressed: c.isSubmitting.value ? null : () async {
              if (numberCtrl.text.isEmpty) return;
              final data = <String, dynamic>{
                'pump_number': int.tryParse(numberCtrl.text) ?? 0,
                'pump_type': pumpType,
                if (nameCtrl.text.isNotEmpty) 'pump_name': nameCtrl.text,
                if (pumpType == 'mechanical') 'initial_meter_reading': meterCtrl.text,
              };
              final ok = await c.addPump(data);
              if (!mounted) return;
              if (ok) Navigator.pop(ctx);
              else ScaffoldMessenger.of(context).showSnackBar(
                SnackBar(content: Text(c.lastError.value), backgroundColor: AmyalColors.red),
              );
            },
            child: const Text('إضافة'),
          )),
        ],
      ),
    ));
  }

  // ==================== Tab 2: الأنواع والأسعار ====================
  Widget _productsTab() {
    return Obx(() {
      if (c.isLoading.value && c.products.isEmpty) {
        return const Center(child: CircularProgressIndicator());
      }
      return Scaffold(
        backgroundColor: AmyalColors.background,
        floatingActionButton: FloatingActionButton.extended(
          backgroundColor: AmyalColors.yellowDark,
          foregroundColor: Colors.white,
          onPressed: _addProductDialog,
          icon: const Icon(Icons.add),
          label: const Text('نوع جديد'),
        ),
        body: c.products.isEmpty
            ? _emptyState(
                Icons.attach_money,
                'لم تُضِف أيّ نوع وقود',
                'بنزين، ديزل... كلٌّ بسعره',
              )
            : ListView.builder(
                padding: const EdgeInsets.all(16),
                itemCount: c.products.length,
                itemBuilder: (_, i) => _productCard(c.products[i]),
              ),
      );
    });
  }

  Widget _productCard(Map<String, dynamic> product) {
    return InkWell(
      borderRadius: BorderRadius.circular(12),
      onTap: () => _updatePriceDialog(product),
      child: Container(
        margin: const EdgeInsets.only(bottom: 10),
        padding: const EdgeInsets.all(14),
        decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(12)),
        child: Row(children: [
          Container(
            width: 50, height: 50,
            decoration: BoxDecoration(
              color: AmyalColors.yellow.withOpacity(0.2),
              borderRadius: BorderRadius.circular(12),
            ),
            child: const Icon(Icons.local_gas_station, color: AmyalColors.yellowDark, size: 24),
          ),
          const SizedBox(width: 12),
          Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(product['name'] ?? '',
                style: const TextStyle(fontSize: 16, fontWeight: FontWeight.bold)),
            if (product['product_code'] != null)
              Text('${product['product_code']}',
                  style: TextStyle(color: Colors.grey.shade600, fontSize: 11)),
          ])),
          Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
            Text('${product['price_per_liter']} ر.ي',
                style: const TextStyle(fontSize: 18, fontWeight: FontWeight.bold, color: AmyalColors.primary)),
            Text('للّتر', style: TextStyle(color: Colors.grey.shade600, fontSize: 11)),
          ]),
          const SizedBox(width: 4),
          const Icon(Icons.edit, color: Colors.grey, size: 18),
        ]),
      ),
    );
  }

  void _addProductDialog() {
    final nameCtrl = TextEditingController();
    final codeCtrl = TextEditingController();
    final priceCtrl = TextEditingController();

    showDialog(context: context, builder: (ctx) => AlertDialog(
      title: const Text('إضافة نوع وقود'),
      content: Column(mainAxisSize: MainAxisSize.min, children: [
        TextField(controller: nameCtrl, decoration: const InputDecoration(labelText: 'الاسم * (بنزين 91...)')),
        const SizedBox(height: 8),
        TextField(controller: codeCtrl, decoration: const InputDecoration(labelText: 'الرمز (اختياري)')),
        const SizedBox(height: 8),
        TextField(
          controller: priceCtrl,
          keyboardType: const TextInputType.numberWithOptions(decimal: true),
          decoration: const InputDecoration(labelText: 'السعر للّتر *', suffixText: 'ر.ي'),
        ),
      ]),
      actions: [
        TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('إلغاء')),
        Obx(() => FilledButton(
          onPressed: c.isSubmitting.value ? null : () async {
            if (nameCtrl.text.isEmpty || priceCtrl.text.isEmpty) return;
            final ok = await c.addProduct({
              'name': nameCtrl.text.trim(),
              if (codeCtrl.text.isNotEmpty) 'product_code': codeCtrl.text.trim(),
              'price_per_liter': priceCtrl.text.trim(),
            });
            if (!mounted) return;
            if (ok) Navigator.pop(ctx);
            else ScaffoldMessenger.of(context).showSnackBar(
              SnackBar(content: Text(c.lastError.value), backgroundColor: AmyalColors.red),
            );
          },
          child: const Text('إضافة'),
        )),
      ],
    ));
  }

  void _updatePriceDialog(Map<String, dynamic> product) {
    final priceCtrl = TextEditingController(text: '${product['price_per_liter']}');

    showDialog(context: context, builder: (ctx) => AlertDialog(
      title: Text('تعديل سعر ${product['name']}'),
      content: TextField(
        controller: priceCtrl,
        keyboardType: const TextInputType.numberWithOptions(decimal: true),
        autofocus: true,
        decoration: const InputDecoration(labelText: 'السعر الجديد للّتر', suffixText: 'ر.ي'),
      ),
      actions: [
        TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('إلغاء')),
        Obx(() => FilledButton(
          onPressed: c.isSubmitting.value ? null : () async {
            if (priceCtrl.text.isEmpty) return;
            final ok = await c.updateProductPrice(product['id'], priceCtrl.text.trim());
            if (!mounted) return;
            if (ok) Navigator.pop(ctx);
            else ScaffoldMessenger.of(context).showSnackBar(
              SnackBar(content: Text(c.lastError.value), backgroundColor: AmyalColors.red),
            );
          },
          child: const Text('حفظ'),
        )),
      ],
    ));
  }

  // ==================== Tab 3: بيانات المحطة ====================
  Widget _stationTab() {
    return Obx(() {
      final station = c.station.value;
      final nameCtrl = TextEditingController(text: station?['station_name']?.toString() ?? '');
      final licenseCtrl = TextEditingController(text: station?['license_number']?.toString() ?? '');
      final cityCtrl = TextEditingController(text: station?['city']?.toString() ?? '');
      final addressCtrl = TextEditingController(text: station?['address']?.toString() ?? '');

      return SingleChildScrollView(
        padding: const EdgeInsets.all(16),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          _settingField(nameCtrl, 'اسم المحطة *', Icons.business),
          _settingField(licenseCtrl, 'رقم الرخصة', Icons.description),
          _settingField(cityCtrl, 'المدينة', Icons.location_city),
          _settingField(addressCtrl, 'العنوان التفصيلي', Icons.map, maxLines: 2),
          const SizedBox(height: 24),
          FilledButton.icon(
            onPressed: c.isSubmitting.value ? null : () async {
              if (nameCtrl.text.isEmpty) return;
              final ok = await c.saveStation({
                'station_name': nameCtrl.text.trim(),
                if (licenseCtrl.text.isNotEmpty) 'license_number': licenseCtrl.text.trim(),
                if (cityCtrl.text.isNotEmpty) 'city': cityCtrl.text.trim(),
                if (addressCtrl.text.isNotEmpty) 'address': addressCtrl.text.trim(),
              });
              if (!mounted) return;
              ScaffoldMessenger.of(context).showSnackBar(SnackBar(
                content: Text(ok ? 'تم الحفظ' : c.lastError.value),
                backgroundColor: ok ? Colors.green : AmyalColors.red,
              ));
            },
            icon: const Icon(Icons.save),
            label: const Text('حفظ البيانات'),
            style: FilledButton.styleFrom(
              backgroundColor: AmyalColors.primary,
              minimumSize: const Size.fromHeight(50),
            ),
          ),
        ]),
      );
    });
  }

  Widget _settingField(TextEditingController ctrl, String label, IconData icon, {int maxLines = 1}) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 6),
      child: TextField(
        controller: ctrl,
        maxLines: maxLines,
        textAlign: TextAlign.right,
        decoration: InputDecoration(
          labelText: label,
          prefixIcon: Icon(icon, color: AmyalColors.primary),
          filled: true, fillColor: Colors.white,
          border: OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: BorderSide.none),
        ),
      ),
    );
  }

  Widget _emptyState(IconData icon, String title, String subtitle) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(32),
        child: Column(mainAxisAlignment: MainAxisAlignment.center, children: [
          Icon(icon, size: 64, color: Colors.grey.shade400),
          const SizedBox(height: 16),
          Text(title, style: const TextStyle(fontSize: 16, fontWeight: FontWeight.bold)),
          const SizedBox(height: 4),
          Text(subtitle, style: TextStyle(color: Colors.grey.shade600), textAlign: TextAlign.center),
        ]),
      ),
    );
  }
}
