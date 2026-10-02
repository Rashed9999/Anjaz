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
  String _connection = 'bluetooth';
  final _host = TextEditingController();
  final _port = TextEditingController(text: '9100');

  @override
  void initState() {
    super.initState();
    _paper = _svc.config.value?.paperMm ?? 80;
    _connection = _svc.config.value?.connection ?? 'bluetooth';
    _host.text = _svc.config.value?.host ?? '';
    _port.text = '${_svc.config.value?.port ?? 9100}';
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
    final current = _svc.config.value;
    await _svc.saveConfig(ThermalPrinterConfig(
      mac: d.macAdress,
      name: d.name,
      paperMm: _paper,
      openCashDrawer: current?.openCashDrawer ?? false,
    ));
    if (!mounted) return;
    _snack('printer_selected'.trParams({'name': d.name}), ok: true);
    setState(() {});
  }

  Future<void> _saveNetwork() async {
    final host = _host.text.trim();
    final port = int.tryParse(_port.text.trim()) ?? 9100;
    if (host.isEmpty || port < 1 || port > 65535) { _snack('printer_network_invalid'.tr); return; }
    await _svc.saveConfig(ThermalPrinterConfig(
      mac: '',
      name: 'printer_network_name'.trParams({'host': host}),
      paperMm: _paper,
      connection: 'network',
      host: host,
      port: port,
      openCashDrawer: _svc.config.value?.openCashDrawer ?? false,
    ));
    if (mounted) { _snack('printer_network_saved'.tr, ok: true); setState(() {}); }
  }

  Future<void> _setDrawer(bool value) async {
    final cfg = _svc.config.value;
    if (cfg == null) {
      _snack('printer_save_first'.tr);
      return;
    }
    await _svc.saveConfig(ThermalPrinterConfig(
      mac: cfg.mac,
      name: cfg.name,
      paperMm: cfg.paperMm,
      connection: cfg.connection,
      host: cfg.host,
      port: cfg.port,
      openCashDrawer: value,
    ));
    if (mounted) setState(() {});
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
      lines: [
        ThermalReceiptLine('printer_test_product_a'.tr, 2, 500),
        ThermalReceiptLine('printer_test_product_b'.tr, 1, 1500),
        ThermalReceiptLine('printer_test_product_c'.tr, 3, 250),
      ],
      total: 3250,
      paid: 4000,
      change: 750,
      verificationUrl: 'https://amialpay.com/verify',
      documentType: 'printer_test',
      documentId: 'TEST-0001',
      documentNumber: 'TEST-0001',
      metadata: const {'purpose': 'printer_diagnostics'},
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
        title: Text('printer_settings_title'.tr),
        actions: [
          IconButton(onPressed: _scanning ? null : _refresh, icon: const Icon(Icons.refresh), tooltip: 'printer_refresh'.tr),
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
            Expanded(child: Text(_btOn ? 'printer_bluetooth_enabled'.tr : 'printer_bluetooth_disabled'.tr,
                style: const TextStyle(fontWeight: FontWeight.w600))),
            if (!_btOn)
              TextButton(onPressed: () => AppSettingsOpener.open(), child: Text('printer_open_system_settings'.tr)),
          ]),
        ),
        const SizedBox(height: 16),
        DropdownButtonFormField<String>(
          initialValue: _connection,
          decoration: InputDecoration(labelText: 'printer_connection_method'.tr, border: const OutlineInputBorder()),
          items: [DropdownMenuItem(value: 'bluetooth', child: Text('printer_connection_bluetooth'.tr)), DropdownMenuItem(value: 'network', child: Text('printer_connection_network'.tr))],
          onChanged: (v) => setState(() => _connection = v ?? 'bluetooth'),
        ),
        const SizedBox(height: 16),
        if (_connection == 'network') ...[
          TextField(controller: _host, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: 'printer_ip_address'.tr, hintText: '192.168.1.100', border: const OutlineInputBorder())),
          const SizedBox(height: 10),
          TextField(controller: _port, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: 'printer_port'.tr, border: const OutlineInputBorder())),
          const SizedBox(height: 10),
          OutlinedButton.icon(onPressed: _saveNetwork, icon: const Icon(Icons.save), label: Text('printer_save_network'.tr)),
          const SizedBox(height: 8),
        ],

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
                  Text('printer_current_summary'.trParams({'endpoint': cfg.connection == 'network' ? '${cfg.host}:${cfg.port}' : cfg.mac, 'paper': '${cfg.paperMm}'}),
                      textDirection: TextDirection.ltr,
                      style: const TextStyle(fontSize: 11, color: AmialColors.textSecondary)),
                ]),
              ),
              IconButton(
                onPressed: () async { await _svc.clearConfig(); if (mounted) setState(() {}); },
                icon: const Icon(Icons.delete_outline, color: AmialColors.red),
                tooltip: 'printer_remove'.tr,
              ),
            ]),
          );
        }),

        // عرض الورق
        Text('printer_paper_width'.tr, style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14)),
        const SizedBox(height: 8),
        Row(children: [
          _paperChip(58, 'printer_paper_58'.tr),
          const SizedBox(width: 10),
          _paperChip(80, 'printer_paper_80'.tr),
        ]),
        Obx(() {
          final cfg = _svc.config.value;
          return SwitchListTile.adaptive(
            contentPadding: EdgeInsets.zero,
            title: Text('printer_cash_drawer_auto'.tr),
            subtitle: Text('printer_cash_drawer_auto_hint'.tr),
            value: cfg?.openCashDrawer ?? false,
            onChanged: cfg == null ? null : _setDrawer,
            activeColor: AmialColors.primary,
          );
        }),
        const SizedBox(height: 20),

        if (_connection == 'bluetooth') ...[
        // الطابعات المقترنة
        Row(children: [
          Text('printer_paired'.tr, style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14)),
          const Spacer(),
          if (_scanning) const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2)),
        ]),
        const SizedBox(height: 8),
        if (!_scanning && _devices.isEmpty)
          Container(
            padding: const EdgeInsets.all(16),
            decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(12)),
            child: Row(children: [
              const Icon(Icons.info_outline, color: AmialColors.textMuted),
              const SizedBox(width: 10),
              Expanded(child: Text('printer_none_paired'.tr,
                  style: const TextStyle(fontSize: 13))),
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
              title: Text(d.name.isEmpty ? 'printer_default_name'.tr : d.name),
              subtitle: Text(d.macAdress, textDirection: TextDirection.ltr, style: const TextStyle(fontSize: 11)),
              trailing: selected
                  ? const Icon(Icons.check_circle, color: AmialColors.primary)
                  : const Icon(Icons.radio_button_unchecked, color: AmialColors.textMuted),
              onTap: () => _select(d),
            ),
          );
        }),
        ],
        const SizedBox(height: 24),

        // طباعة تجريبية
        FilledButton.icon(
          onPressed: (_svc.config.value == null || _testing) ? null : _testPrint,
          icon: _testing
              ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
              : const Icon(Icons.receipt_long),
          label: Text(_testing ? 'printer_printing'.tr : 'printer_test_print'.tr),
          style: FilledButton.styleFrom(backgroundColor: AmialColors.primary, minimumSize: const Size.fromHeight(52)),
        ),
      ]),
    );
  }

  @override
  void dispose() { _host.dispose(); _port.dispose(); super.dispose(); }

  Widget _paperChip(int mm, String label) {
    final selected = _paper == mm;
    return Expanded(
      child: InkWell(
        borderRadius: BorderRadius.circular(12),
        onTap: () async {
          setState(() => _paper = mm);
          final cfg = _svc.config.value;
          if (cfg != null) {
            await _svc.saveConfig(ThermalPrinterConfig(
              mac: cfg.mac,
              name: cfg.name,
              paperMm: mm,
              connection: cfg.connection,
              host: cfg.host,
              port: cfg.port,
              openCashDrawer: cfg.openCashDrawer,
            ));
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
