import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:permission_handler/permission_handler.dart';
import 'package:print_bluetooth_thermal/print_bluetooth_thermal.dart';
import 'package:amial_pay/theme/amial_colors.dart';
import 'package:amial_pay/features/printer/services/thermal_print_service.dart';
import 'package:amial_pay/features/printer/widgets/thermal_receipt_widget.dart';
import 'package:amial_pay/features/merchant/controllers/receipt_settings_controller.dart';

/// AMIAL-THERMAL-PRINT-001 — «إعدادات الطابعة الحرارية».
///
/// اختيار طابعة بلوتوث مقترنة + عرض الورق (58/80مم) + طباعة تجريبية.
class PrinterSettingsScreen extends StatefulWidget {
  const PrinterSettingsScreen({super.key});

  @override
  State<PrinterSettingsScreen> createState() => _PrinterSettingsScreenState();
}

class _PrinterSettingsScreenState extends State<PrinterSettingsScreen> {
  final ThermalPrintService _svc = Get.find<ThermalPrintService>();
  List<BluetoothInfo> _devices = [];
  bool _scanning = false;
  bool _btOn = false;
  bool _testing = false;
  int _paper = 80;

  @override
  void initState() {
    super.initState();
    _paper = _svc.config.value?.paperMm ?? 80;
    _refresh();
  }

  Future<void> _refresh() async {
    setState(() => _scanning = true);
    // أذونات أندرويد 12+
    await [Permission.bluetoothConnect, Permission.bluetoothScan].request();
    final on = await _svc.bluetoothEnabled();
    final list = on ? await _svc.pairedPrinters() : <BluetoothInfo>[];
    if (!mounted) return;
    setState(() {
      _btOn = on;
      _devices = list;
      _scanning = false;
    });
  }

  Future<void> _select(BluetoothInfo d) async {
    await _svc.saveConfig(ThermalPrinterConfig(mac: d.macAdress, name: d.name, paperMm: _paper));
    if (!mounted) return;
    _snack('تم اختيار الطابعة: ${d.name}', ok: true);
    setState(() {});
  }

  Future<void> _testPrint() async {
    setState(() => _testing = true);
    // AMIAL: اطبع بهويّة متجر التاجر الحقيقية (اسم + شعار) لا نصّ ثابت.
    final rc = Get.isRegistered<ReceiptSettingsController>()
        ? Get.find<ReceiptSettingsController>()
        : Get.put(ReceiptSettingsController(), permanent: true);
    try {
      await rc.load();
    } catch (_) {}
    final r = await _svc.printSale(
      settings: rc.effective,
      invoiceNo: 'TEST-0001',
      lines: const [
        ThermalReceiptLine('منتج تجريبي أ', 2, 500),
        ThermalReceiptLine('منتج تجريبي ب', 1, 1500),
        ThermalReceiptLine('صنف طويل الاسم للتجربة', 3, 250),
      ],
      total: 3250,
      paid: 4000,
      change: 750,
    );
    if (!mounted) return;
    setState(() => _testing = false);
    _snack(r.message, ok: r.ok);
  }

  void _snack(String m, {bool ok = false}) => ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(content: Text(m), backgroundColor: ok ? AmialColors.success : AmialColors.red));

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(
        title: const Text('إعدادات الطابعة'),
        actions: [
          IconButton(onPressed: _scanning ? null : _refresh, icon: const Icon(Icons.refresh), tooltip: 'تحديث'),
        ],
      ),
      body: ListView(padding: const EdgeInsets.all(16), children: [
        // حالة البلوتوث
        Container(
          padding: const EdgeInsets.all(14),
          decoration: BoxDecoration(
            color: _btOn ? const Color(0xFFE8F5E9) : const Color(0xFFFDECEA),
            borderRadius: BorderRadius.circular(12),
          ),
          child: Row(children: [
            Icon(_btOn ? Icons.bluetooth_connected : Icons.bluetooth_disabled,
                color: _btOn ? AmialColors.success : AmialColors.red),
            const SizedBox(width: 10),
            Expanded(child: Text(_btOn ? 'البلوتوث مفعّل' : 'البلوتوث مغلق — فعّله من إعدادات الهاتف',
                style: const TextStyle(fontWeight: FontWeight.w600))),
            if (!_btOn)
              TextButton(onPressed: () => AppSettingsOpener.open(), child: const Text('فتح الإعدادات')),
          ]),
        ),
        const SizedBox(height: 16),

        // الطابعة الحالية
        Obx(() {
          final cfg = _svc.config.value;
          if (cfg == null) {
            return const SizedBox.shrink();
          }
          return Container(
            margin: const EdgeInsets.only(bottom: 16),
            padding: const EdgeInsets.all(14),
            decoration: BoxDecoration(
              color: Colors.white,
              borderRadius: BorderRadius.circular(12),
              border: Border.all(color: AmialColors.primary.withValues(alpha: 0.4), width: 1.5),
            ),
            child: Row(children: [
              const Icon(Icons.print, color: AmialColors.primary, size: 28),
              const SizedBox(width: 12),
              Expanded(
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text(cfg.name, style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 15)),
                  Text('${cfg.mac}  •  ورق ${cfg.paperMm}مم',
                      textDirection: TextDirection.ltr,
                      style: const TextStyle(fontSize: 11, color: AmialColors.textSecondary)),
                ]),
              ),
              IconButton(
                onPressed: () async { await _svc.clearConfig(); if (mounted) setState(() {}); },
                icon: const Icon(Icons.delete_outline, color: AmialColors.red),
                tooltip: 'إزالة',
              ),
            ]),
          );
        }),

        // عرض الورق
        const Text('عرض الورق', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 14)),
        const SizedBox(height: 8),
        Row(children: [
          _paperChip(58, '58 مم'),
          const SizedBox(width: 10),
          _paperChip(80, '80 مم'),
        ]),
        const SizedBox(height: 20),

        // الطابعات المقترنة
        Row(children: [
          const Text('الطابعات المقترنة', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 14)),
          const Spacer(),
          if (_scanning) const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2)),
        ]),
        const SizedBox(height: 8),
        if (!_scanning && _devices.isEmpty)
          Container(
            padding: const EdgeInsets.all(16),
            decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(12)),
            child: const Row(children: [
              Icon(Icons.info_outline, color: AmialColors.textMuted),
              SizedBox(width: 10),
              Expanded(child: Text('لا توجد طابعات مقترنة. اقترن بالطابعة من إعدادات بلوتوث الهاتف أولاً، ثم اضغط تحديث.',
                  style: TextStyle(fontSize: 13))),
            ]),
          ),
        ..._devices.map((d) {
          final selected = _svc.config.value?.mac == d.macAdress;
          return Card(
            elevation: 0,
            color: Colors.white,
            shape: RoundedRectangleBorder(
              borderRadius: BorderRadius.circular(12),
              side: BorderSide(color: selected ? AmialColors.primary : AmialColors.border),
            ),
            child: ListTile(
              leading: Icon(Icons.print, color: selected ? AmialColors.primary : Colors.grey.shade600),
              title: Text(d.name.isEmpty ? 'طابعة' : d.name),
              subtitle: Text(d.macAdress, textDirection: TextDirection.ltr, style: const TextStyle(fontSize: 11)),
              trailing: selected
                  ? const Icon(Icons.check_circle, color: AmialColors.primary)
                  : const Icon(Icons.radio_button_unchecked, color: AmialColors.textMuted),
              onTap: () => _select(d),
            ),
          );
        }),
        const SizedBox(height: 24),

        // طباعة تجريبية
        FilledButton.icon(
          onPressed: (_svc.config.value == null || _testing) ? null : _testPrint,
          icon: _testing
              ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
              : const Icon(Icons.receipt_long),
          label: Text(_testing ? 'جارٍ الطباعة…' : 'طباعة تجريبية'),
          style: FilledButton.styleFrom(backgroundColor: AmialColors.primary, minimumSize: const Size.fromHeight(52)),
        ),
      ]),
    );
  }

  Widget _paperChip(int mm, String label) {
    final selected = _paper == mm;
    return Expanded(
      child: InkWell(
        borderRadius: BorderRadius.circular(12),
        onTap: () async {
          setState(() => _paper = mm);
          final cfg = _svc.config.value;
          if (cfg != null) {
            await _svc.saveConfig(ThermalPrinterConfig(mac: cfg.mac, name: cfg.name, paperMm: mm));
          }
        },
        child: Container(
          padding: const EdgeInsets.symmetric(vertical: 14),
          decoration: BoxDecoration(
            color: selected ? AmialColors.primary : Colors.white,
            borderRadius: BorderRadius.circular(12),
            border: Border.all(color: selected ? AmialColors.primary : AmialColors.border),
          ),
          child: Text(label, textAlign: TextAlign.center,
              style: TextStyle(color: selected ? Colors.white : Colors.black87, fontWeight: FontWeight.w600)),
        ),
      ),
    );
  }
}

/// فتح إعدادات النظام (لتفعيل البلوتوث) — يستخدم app_settings الموجودة.
class AppSettingsOpener {
  static Future<void> open() async {
    try {
      await openAppSettings();
    } catch (_) {}
  }
}
