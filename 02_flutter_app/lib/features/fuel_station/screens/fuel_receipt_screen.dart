import 'dart:io';
import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:open_file/open_file.dart';
import 'package:path_provider/path_provider.dart';
import 'package:screenshot/screenshot.dart';
import 'package:share_plus/share_plus.dart';
import 'package:url_launcher/url_launcher.dart';
import 'package:amyal_pay/features/merchant/controllers/receipt_settings_controller.dart';
import 'package:amyal_pay/features/payments/widgets/amial_invoice_card.dart';
import 'package:amyal_pay/features/printer/services/thermal_print_service.dart';
import 'package:amyal_pay/features/printer/widgets/thermal_receipt_widget.dart';
import 'package:amyal_pay/features/printer/screens/printer_settings_screen.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';

/// AMIAL-FUEL-RECEIPT-001 — فاتورة بيع الوقود بمقاس حراري 80مم.
///
/// تظهر بعد اكتمال البيع. مصمّمة كإيصال طابعة (عرض ثابت ≈ 80مم) ويمكن:
///   • طباعتها/حفظها كصورة (تُفتح بتطبيق الطباعة/المعرض)
///   • إرسالها واتساب للعميل مباشرةً
///   • بدء «عملية جديدة» (رجوع للكاشير)
///
/// كل الأرقام تأتي من سجل البيع الحقيقي (recordSale) — ليست واجهة صورية.
class FuelReceiptScreen extends StatefulWidget {
  const FuelReceiptScreen({
    super.key,
    required this.sale,
    required this.stationName,
    this.pumpLabel,
    this.customerPhone,
  });

  /// سجل البيع كما رجع من الخادم (lastSale).
  final Map<String, dynamic> sale;
  final String stationName;
  final String? pumpLabel;
  final String? customerPhone;

  @override
  State<FuelReceiptScreen> createState() => _FuelReceiptScreenState();
}

class _FuelReceiptScreenState extends State<FuelReceiptScreen> {
  final ScreenshotController _shot = ScreenshotController();
  bool _busy = false;

  late final ReceiptSettingsController _settings;

  @override
  void initState() {
    super.initState();
    _settings = Get.isRegistered<ReceiptSettingsController>()
        ? Get.find<ReceiptSettingsController>()
        : Get.put(ReceiptSettingsController(), permanent: true);
    _settings.load();
  }

  String _fmt(dynamic v) {
    final n = v is num ? v : num.tryParse('${v ?? ''}') ?? 0;
    final s = n.toStringAsFixed(n == n.roundToDouble() ? 0 : 2);
    // فواصل الآلاف
    final parts = s.split('.');
    final intPart = parts[0].replaceAllMapped(
        RegExp(r'(\d)(?=(\d{3})+$)'), (m) => '${m[1]},');
    return parts.length > 1 ? '$intPart.${parts[1]}' : intPart;
  }

  String get _method {
    final m = '${widget.sale['payment_method'] ?? ''}';
    return switch (m) {
      'cash' => 'نقداً',
      'amial_pay' => 'أميال باي',
      'company_card' => 'بطاقة شركة',
      _ => m.isEmpty ? '—' : m,
    };
  }

  String get _ref => '${widget.sale['sale_ulid'] ?? widget.sale['id'] ?? ''}';

  String _now() {
    final d = DateTime.now();
    final h12 = d.hour % 12 == 0 ? 12 : d.hour % 12;
    final ampm = d.hour < 12 ? 'ص' : 'م';
    return '${d.year}/${d.month.toString().padLeft(2, '0')}/${d.day.toString().padLeft(2, '0')}'
        '  •  $h12:${d.minute.toString().padLeft(2, '0')} $ampm';
  }

  Future<File?> _capture() async {
    final Uint8List? bytes = await _shot.capture(pixelRatio: 3);
    if (bytes == null) return null;
    final dir = await getApplicationDocumentsDirectory();
    final f = File(
        '${dir.path}/fuel_invoice_${DateTime.now().millisecondsSinceEpoch}.png');
    await f.writeAsBytes(bytes, flush: true);
    return f;
  }

  List<ThermalReceiptLine> _thermalLines() {
    final liters = double.tryParse('${widget.sale['liters'] ?? 0}') ?? 0;
    final ppl = double.tryParse('${widget.sale['price_per_liter'] ?? 0}') ?? 0;
    final fuel = '${widget.sale['fuel_type'] ?? widget.sale['product'] ?? 'وقود'}';
    return [ThermalReceiptLine('$fuel (لتر)', liters, ppl)];
  }

  /// طباعة: على الطابعة الحرارية (بهويّة المحطة + الشعار) إن ضُبطت، وإلا صورة.
  Future<void> _print() async {
    setState(() => _busy = true);
    try {
      final svc = Get.isRegistered<ThermalPrintService>() ? Get.find<ThermalPrintService>() : null;
      final total = double.tryParse('${widget.sale['total_amount'] ?? 0}') ?? 0;
      if (svc != null && svc.config.value != null) {
        final r = await svc.printSale(
          settings: _settings.effective,
          lines: _thermalLines(),
          total: total,
          invoiceNo: _ref,
          dateTime: DateTime.now(),
        );
        if (mounted) _snack(r.message, ok: r.ok);
      } else {
        final f = await _capture();
        if (f == null) throw Exception('capture');
        await OpenFile.open(f.path, type: 'image/png');
        if (mounted) {
          ScaffoldMessenger.of(context).showSnackBar(SnackBar(
            content: const Text('لطباعة حرارية مباشرة، اضبط طابعتك'),
            action: SnackBarAction(
                label: 'إعداد', textColor: Colors.white,
                onPressed: () => Get.to(() => const PrinterSettingsScreen())),
            backgroundColor: AmyalColors.primary,
          ));
        }
      }
    } catch (_) {
      _snack('تعذّرت الطباعة — جرّب المشاركة');
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  /// إرسال واتساب: يشارك صورة الفاتورة؛ إن توفّر رقم العميل يفتح محادثته مباشرةً.
  Future<void> _whatsapp() async {
    setState(() => _busy = true);
    try {
      final f = await _capture();
      if (f == null) throw Exception('capture');
      final phone = _waPhone(widget.customerPhone);
      final caption = 'فاتورة تعبئة وقود — ${widget.stationName}\n'
          'الإجمالي: ${_fmt(widget.sale['total_amount'])} ر.ي\n'
          'المرجع: $_ref';
      // نشارك الصورة (واتساب أحد خيارات المشاركة). لو وُجد رقم صحيح نفتح
      // محادثة العميل نصياً كتأكيد إضافي.
      await Share.shareXFiles([XFile(f.path, mimeType: 'image/png')],
          text: caption);
      if (phone != null) {
        final uri = Uri.parse('https://wa.me/$phone?text=${Uri.encodeComponent(caption)}');
        if (await canLaunchUrl(uri)) {
          await launchUrl(uri, mode: LaunchMode.externalApplication);
        }
      }
    } catch (_) {
      _snack('تعذّر الإرسال عبر واتساب');
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  /// تحويل رقم يمني إلى صيغة واتساب الدولية (967…) بلا صفر بادئ.
  String? _waPhone(String? raw) {
    if (raw == null) return null;
    var p = raw.replaceAll(RegExp(r'[^0-9]'), '');
    if (p.isEmpty) return null;
    if (p.startsWith('00')) p = p.substring(2);
    if (p.startsWith('967')) return p;
    if (p.startsWith('0')) p = p.substring(1);
    if (p.length == 9) return '967$p';
    return p;
  }

  void _snack(String m, {bool ok = false}) => ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(content: Text(m), backgroundColor: ok ? const Color(0xFF2E7D32) : AmyalColors.red));

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmyalColors.background,
      appBar: AppBar(
        title: const Text('فاتورة البيع'),
        leading: IconButton(
            icon: const Icon(Icons.close), onPressed: () => Get.back()),
      ),
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(20),
        child: Column(children: [
          // شارة نجاح
          Container(
            height: 84,
            width: 84,
            decoration: const BoxDecoration(
                color: Color(0xFF2E7D32), shape: BoxShape.circle),
            child: const Icon(Icons.check_rounded, color: Colors.white, size: 46),
          ),
          const SizedBox(height: 8),
          const Text('تمّت العملية بنجاح',
              style: TextStyle(
                  fontSize: 17,
                  fontWeight: FontWeight.bold,
                  color: Color(0xFF2E7D32))),
          const SizedBox(height: 18),

          // ====== الفاتورة الموحّدة (تقرأ إعدادات التاجر، تُلتقط كاملة) ======
          Center(
            child: Screenshot(
              controller: _shot,
              child: Obx(() => AmialInvoiceCard(
                    settings: {
                      ..._settings.effective,
                      // اسم المحطة من العملية إن لم يُضبط اسم المتجر
                      'store_name': (_settings.effective['store_name'] == null ||
                              _settings.effective['store_name'] == 'المتجر')
                          ? widget.stationName
                          : _settings.effective['store_name'],
                    },
                    title: 'فاتورة تعبئة وقود',
                    rows: [
                      ('نوع الوقود', '${widget.sale['product_name'] ?? widget.sale['fuel_product'] ?? 'وقود'}'),
                      if (widget.pumpLabel != null) ('المضخّة', widget.pumpLabel!),
                      ('اللترات', '${_fmt(widget.sale['liters'])} لتر'),
                      ('السعر/لتر', '${_fmt(widget.sale['price_per_liter'])} ر.ي'),
                    ],
                    total: _fmt(widget.sale['total_amount']),
                    method: _method,
                    reference: _ref,
                    dateTime: _now(),
                    customer: widget.customerPhone,
                    totalYer: double.tryParse('${widget.sale['total_amount'] ?? 0}'),
                    currencies: _settings.currencies,
                  )),
            ),
          ),
          const SizedBox(height: 22),

          // ====== الأزرار ======
          Row(children: [
            Expanded(
              child: OutlinedButton.icon(
                onPressed: _busy ? null : _print,
                icon: const Icon(Icons.print_outlined, size: 20),
                label: const Text('طباعة'),
                style: OutlinedButton.styleFrom(
                  foregroundColor: AmyalColors.primary,
                  side: const BorderSide(color: AmyalColors.primary),
                  minimumSize: const Size.fromHeight(50),
                ),
              ),
            ),
            const SizedBox(width: 10),
            Expanded(
              child: FilledButton.icon(
                onPressed: _busy ? null : _whatsapp,
                icon: _busy
                    ? const SizedBox(
                        width: 16,
                        height: 16,
                        child: CircularProgressIndicator(
                            strokeWidth: 2, color: Colors.white))
                    : const Icon(Icons.chat, size: 20),
                label: const Text('واتساب'),
                style: FilledButton.styleFrom(
                  backgroundColor: const Color(0xFF25D366),
                  minimumSize: const Size.fromHeight(50),
                ),
              ),
            ),
          ]),
          const SizedBox(height: 10),
          FilledButton.icon(
            onPressed: () => Get.back(), // رجوع للكاشير = عملية جديدة
            icon: const Icon(Icons.add),
            label: const Text('عملية جديدة',
                style: TextStyle(fontSize: 16, fontWeight: FontWeight.w700)),
            style: FilledButton.styleFrom(
              backgroundColor: AmyalColors.primary,
              minimumSize: const Size.fromHeight(54),
            ),
          ),
        ]),
      ),
    );
  }

}
