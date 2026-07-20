import 'dart:typed_data';
import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:screenshot/screenshot.dart';
import 'package:print_bluetooth_thermal/print_bluetooth_thermal.dart';
import 'package:esc_pos_utils_plus/esc_pos_utils_plus.dart';
import 'package:image/image.dart' as img;

/// AMIAL-THERMAL-PRINT-001 — إعداد الطابعة الحرارية المحفوظ.
class ThermalPrinterConfig {
  final String mac;
  final String name;
  final int paperMm; // 58 أو 80

  const ThermalPrinterConfig({required this.mac, required this.name, this.paperMm = 80});
}

class PrintResult {
  final bool ok;
  final String message;
  const PrintResult(this.ok, this.message);
}

/// خدمة الطباعة الحرارية.
///
/// المبدأ: نُصمّم الإيصال كـ Widget، نلتقطه صورةً، ثم نطبعه raster عبر ESC/POS.
/// هذا يجعل العربية تُطبع سليمةً تماماً (لأنها صورة، لا نصّ يعتمد على ترميز الطابعة).
class ThermalPrintService extends GetxService {
  static const _kMac = 'amial_printer_mac';
  static const _kName = 'amial_printer_name';
  static const _kPaper = 'amial_printer_paper';

  final Rxn<ThermalPrinterConfig> config = Rxn<ThermalPrinterConfig>();

  @override
  void onInit() {
    super.onInit();
    loadConfig();
  }

  Future<void> loadConfig() async {
    final p = await SharedPreferences.getInstance();
    final mac = p.getString(_kMac);
    if (mac != null && mac.isNotEmpty) {
      config.value = ThermalPrinterConfig(
        mac: mac,
        name: p.getString(_kName) ?? 'طابعة',
        paperMm: p.getInt(_kPaper) ?? 80,
      );
    }
  }

  Future<void> saveConfig(ThermalPrinterConfig c) async {
    final p = await SharedPreferences.getInstance();
    await p.setString(_kMac, c.mac);
    await p.setString(_kName, c.name);
    await p.setInt(_kPaper, c.paperMm);
    config.value = c;
  }

  Future<void> clearConfig() async {
    final p = await SharedPreferences.getInstance();
    await p.remove(_kMac);
    await p.remove(_kName);
    await p.remove(_kPaper);
    config.value = null;
  }

  Future<bool> bluetoothEnabled() async {
    try {
      return await PrintBluetoothThermal.bluetoothEnabled;
    } catch (_) {
      return false;
    }
  }

  Future<List<BluetoothInfo>> pairedPrinters() async {
    try {
      return await PrintBluetoothThermal.pairedBluetooths;
    } catch (_) {
      return [];
    }
  }

  int _dotsFor(int mm) => mm == 58 ? 384 : 576;
  PaperSize _paperSize(int mm) => mm == 58 ? PaperSize.mm58 : PaperSize.mm80;

  /// يلتقط widget الإيصال كصورة ويطبعه.
  Future<PrintResult> printWidget(Widget receipt) async {
    final cfg = config.value;
    if (cfg == null) return const PrintResult(false, 'لم يتم اختيار طابعة — افتح إعدادات الطابعة');
    final width = _dotsFor(cfg.paperMm).toDouble();
    try {
      final wrapped = Material(
        color: Colors.white,
        child: SizedBox(width: width, child: receipt),
      );
      // pixelRatio: 1 ⇒ عرض الصورة بالبكسل = عرض الورق بالنقاط (بلا إعادة قياس تقريباً).
      final png = await ScreenshotController().captureFromWidget(
        wrapped,
        pixelRatio: 1.0,
        delay: const Duration(milliseconds: 60),
      );
      return await printPng(png);
    } catch (_) {
      return const PrintResult(false, 'تعذّر تجهيز الإيصال للطباعة');
    }
  }

  /// يطبع صورة PNG جاهزة (raster) على الطابعة المحفوظة.
  Future<PrintResult> printPng(Uint8List png) async {
    final cfg = config.value;
    if (cfg == null) return const PrintResult(false, 'لم يتم اختيار طابعة');
    try {
      if (!await bluetoothEnabled()) return const PrintResult(false, 'البلوتوث مغلق — فعّله ثم أعد المحاولة');

      var image = img.decodePng(png);
      if (image == null) return const PrintResult(false, 'تعذّر قراءة الصورة');
      final targetW = _dotsFor(cfg.paperMm);
      if (image.width != targetW) image = img.copyResize(image, width: targetW);

      final profile = await CapabilityProfile.load();
      final gen = Generator(_paperSize(cfg.paperMm), profile);
      final List<int> bytes = [];
      bytes.addAll(gen.imageRaster(image, align: PosAlign.center));
      bytes.addAll(gen.feed(2));
      bytes.addAll(gen.cut());

      final connected = await PrintBluetoothThermal.connect(macPrinterAddress: cfg.mac);
      if (!connected) return const PrintResult(false, 'تعذّر الاتصال بالطابعة — تأكّد أنها مقترنة ومشغّلة');
      final sent = await PrintBluetoothThermal.writeBytes(bytes);
      await PrintBluetoothThermal.disconnect;
      return sent
          ? const PrintResult(true, 'تمت الطباعة')
          : const PrintResult(false, 'فشل إرسال البيانات للطابعة');
    } catch (_) {
      return const PrintResult(false, 'حدث خطأ أثناء الطباعة');
    }
  }
}
