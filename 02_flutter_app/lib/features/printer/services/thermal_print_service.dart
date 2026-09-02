import 'dart:io';
import 'dart:typed_data';
import 'dart:ui' as ui;
import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';
import 'package:screenshot/screenshot.dart';
import 'package:print_bluetooth_thermal/print_bluetooth_thermal.dart';
import 'package:amial_pay/features/printer/widgets/thermal_receipt_widget.dart';

/// AMIAL-THERMAL-PRINT-001 — إعداد الطابعة الحرارية المحفوظ.
class ThermalPrinterConfig {
  final String mac;
  final String name;
  final int paperMm; // 58 أو 80
  final String connection; // bluetooth | network
  final String? host;
  final int port;
  /// نبضة ESC/POS لفتح درج النقد المرتبط بالطابعة بعد الإيصال.
  final bool openCashDrawer;

  const ThermalPrinterConfig({required this.mac, required this.name, this.paperMm = 80,
    this.connection = 'bluetooth', this.host, this.port = 9100,
    this.openCashDrawer = false});
}

class PrintResult {
  final bool ok;
  final String message;
  const PrintResult(this.ok, this.message);
}

/// خدمة الطباعة الحرارية.
///
/// المبدأ: نُصمّم الإيصال Widget، نلتقطه صورةً، ثم نولّد أوامر ESC/POS raster
/// (GS v 0) يدوياً ونرسلها عبر البلوتوث. طباعة الصورة تجعل العربية تظهر سليمةً
/// (لأنها raster لا نصّ يعتمد على ترميز الطابعة). لا نعتمد إلا على
/// print_bluetooth_thermal + screenshot (الموجودة أصلاً) + dart:ui المدمج.
class ThermalPrintService extends GetxService {
  static const _kMac = 'amial_printer_mac';
  static const _kName = 'amial_printer_name';
  static const _kPaper = 'amial_printer_paper';
  static const _kConnection = 'amial_printer_connection';
  static const _kHost = 'amial_printer_host';
  static const _kPort = 'amial_printer_port';
  static const _kOpenCashDrawer = 'amial_printer_open_cash_drawer';

  final Rxn<ThermalPrinterConfig> config = Rxn<ThermalPrinterConfig>();

  @override
  void onInit() {
    super.onInit();
    loadConfig();
  }

  Future<void> loadConfig() async {
    final p = await SharedPreferences.getInstance();
    final mac = p.getString(_kMac);
    final connection = p.getString(_kConnection) ?? 'bluetooth';
    final host = p.getString(_kHost);
    if ((connection == 'network' && host != null && host.isNotEmpty) ||
        (connection == 'bluetooth' && mac != null && mac.isNotEmpty)) {
      config.value = ThermalPrinterConfig(
        mac: mac ?? '',
        name: p.getString(_kName) ?? 'طابعة',
        paperMm: p.getInt(_kPaper) ?? 80,
        connection: connection, host: host, port: p.getInt(_kPort) ?? 9100,
        openCashDrawer: p.getBool(_kOpenCashDrawer) ?? false,
      );
    }
  }

  Future<void> saveConfig(ThermalPrinterConfig c) async {
    final p = await SharedPreferences.getInstance();
    await p.setString(_kMac, c.mac);
    await p.setString(_kName, c.name);
    await p.setInt(_kPaper, c.paperMm);
    await p.setString(_kConnection, c.connection);
    if (c.host != null) await p.setString(_kHost, c.host!); else await p.remove(_kHost);
    await p.setInt(_kPort, c.port);
    await p.setBool(_kOpenCashDrawer, c.openCashDrawer);
    config.value = c;
  }

  Future<void> clearConfig() async {
    final p = await SharedPreferences.getInstance();
    await p.remove(_kMac);
    await p.remove(_kName);
    await p.remove(_kPaper);
    await p.remove(_kConnection); await p.remove(_kHost); await p.remove(_kPort);
    await p.remove(_kOpenCashDrawer);
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
      // pixelRatio: 1 ⇒ عرض الصورة بالبكسل = عرض الورق بالنقاط.
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

  /// يجلب بايتات الشعار من رابطه (لتُطبع مع الإيصال). null إن تعذّر.
  Future<Uint8List?> fetchLogoBytes(String? url) async {
    if (url == null || url.isEmpty) return null;
    try {
      final r = await http.get(Uri.parse(url)).timeout(const Duration(seconds: 6));
      if (r.statusCode == 200 && r.bodyBytes.isNotEmpty) return r.bodyBytes;
    } catch (_) {}
    return null;
  }

  /// يطبع فاتورة بيع بهويّة متجر التاجر (اسم + شعار + هاتف + عنوان + تذييل).
  Future<PrintResult> printSale({
    required Map<String, dynamic> settings,
    required List<ThermalReceiptLine> lines,
    required num total,
    String? invoiceNo,
    num? paid,
    num? change,
    num? subtotal,
    num? discount,
    num? tax,
    num? balanceDue,
    List<String> contextLines = const [],
    DateTime? dateTime,
  }) async {
    final logo = await fetchLogoBytes(settings['logo_url']?.toString());
    return printWidget(ThermalReceiptWidget.fromSettings(
      settings: settings,
      lines: lines,
      total: total,
      logoBytes: logo,
      invoiceNo: invoiceNo,
      paid: paid,
      change: change,
      subtotal: subtotal,
      discount: discount,
      tax: tax,
      balanceDue: balanceDue,
      contextLines: contextLines,
      dateTime: dateTime,
    ));
  }

  /// يطبع صورة PNG جاهزة (raster) على الطابعة المحفوظة.
  Future<PrintResult> printPng(Uint8List png) async {
    final cfg = config.value;
    if (cfg == null) return const PrintResult(false, 'لم يتم اختيار طابعة');
    try {
      final raster = await _pngToEscPosRaster(
        png,
        _dotsFor(cfg.paperMm),
        openCashDrawer: cfg.openCashDrawer,
      );
      if (raster == null) return const PrintResult(false, 'تعذّر تجهيز صورة الإيصال');
      if (cfg.connection == 'network') {
        if (cfg.host == null || cfg.host!.isEmpty) return const PrintResult(false, 'عنوان IP للطابعة مطلوب');
        final socket = await Socket.connect(cfg.host!, cfg.port, timeout: const Duration(seconds: 5));
        socket.add(raster); await socket.flush(); await socket.close();
        return const PrintResult(true, 'تمت الطباعة عبر الشبكة');
      }
      if (!await bluetoothEnabled()) return const PrintResult(false, 'البلوتوث مغلق — فعّله ثم أعد المحاولة');

      final connected = await PrintBluetoothThermal.connect(macPrinterAddress: cfg.mac);
      if (!connected) return const PrintResult(false, 'تعذّر الاتصال بالطابعة — تأكّد أنها مقترنة ومشغّلة');
      final sent = await PrintBluetoothThermal.writeBytes(raster);
      await PrintBluetoothThermal.disconnect;
      return sent
          ? const PrintResult(true, 'تمت الطباعة')
          : const PrintResult(false, 'فشل إرسال البيانات للطابعة');
    } catch (_) {
      return const PrintResult(false, 'حدث خطأ أثناء الطباعة');
    }
  }

  /// يفكّ PNG إلى RGBA عبر dart:ui، ثم يبني أوامر GS v 0 (raster bit image).
  Future<List<int>?> _pngToEscPosRaster(
    Uint8List png,
    int paperDots, {
    required bool openCashDrawer,
  }) async {
    ui.Image? image;
    try {
      final codec = await ui.instantiateImageCodec(png, targetWidth: paperDots);
      final frame = await codec.getNextFrame();
      image = frame.image;
      final byteData = await image.toByteData(format: ui.ImageByteFormat.rawRgba);
      if (byteData == null) return null;
      final rgba = byteData.buffer.asUint8List();
      return _buildRaster(rgba, image.width, image.height,
          openCashDrawer: openCashDrawer);
    } catch (_) {
      return null;
    } finally {
      image?.dispose();
    }
  }

  /// يبني أوامر ESC/POS: تهيئة + GS v 0 على شكل نطاقات + تغذية + قطع.
  List<int> _buildRaster(Uint8List rgba, int width, int height,
      {required bool openCashDrawer}) {
    final bytesPerRow = (width + 7) >> 3;
    final out = <int>[0x1B, 0x40]; // ESC @ : تهيئة الطابعة
    const bandH = 128; // نطاقات آمنة لكل الطابعات

    for (int y0 = 0; y0 < height; y0 += bandH) {
      final h = (y0 + bandH <= height) ? bandH : height - y0;
      // GS v 0 m xL xH yL yH
      out.addAll([0x1D, 0x76, 0x30, 0x00]);
      out.add(bytesPerRow & 0xFF);
      out.add((bytesPerRow >> 8) & 0xFF);
      out.add(h & 0xFF);
      out.add((h >> 8) & 0xFF);
      for (int y = 0; y < h; y++) {
        final rowBase = (y0 + y) * width;
        for (int xb = 0; xb < bytesPerRow; xb++) {
          int b = 0;
          for (int bit = 0; bit < 8; bit++) {
            final x = (xb << 3) + bit;
            if (x < width) {
              final idx = (rowBase + x) << 2;
              final r = rgba[idx];
              final g = rgba[idx + 1];
              final bl = rgba[idx + 2];
              final a = rgba[idx + 3];
              // شفاف = أبيض؛ غير ذلك حسب الإضاءة
              final lum = a < 128 ? 255 : (r * 0.299 + g * 0.587 + bl * 0.114);
              if (lum < 128) b |= (0x80 >> bit); // أسود = بِت مضيء
            }
          }
          out.add(b);
        }
      }
    }
    out.addAll([0x1B, 0x64, 0x03]); // ESC d 3 : تغذية 3 أسطر
    // ESC p m t1 t2 — النبضة القياسية لدرج النقد (Epson/ESC-POS).
    // لا تُرسل إلا عندما يفعّلها التاجر لجهاز POS الحالي، وبعد الإيصال.
    if (openCashDrawer) out.addAll(const [0x1B, 0x70, 0x00, 0x19, 0xFA]);
    out.addAll([0x1D, 0x56, 0x01]); // GS V 1 : قطع جزئي
    return out;
  }
}
