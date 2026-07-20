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
import 'package:amyal_pay/features/merchant/screens/cashier_pos_screen.dart';
import 'package:amyal_pay/features/payments/widgets/amial_invoice_card.dart';
import 'package:amyal_pay/features/printer/services/thermal_print_service.dart';
import 'package:amyal_pay/features/printer/widgets/thermal_receipt_widget.dart';
import 'package:amyal_pay/features/printer/screens/printer_settings_screen.dart';
import 'package:amyal_pay/helper/amial_money.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';

/// AMIAL-POS-003 / AMIAL-RECEIPT-SETTINGS-001 — «تم التحصيل».
///
/// يعرض الفاتورة الموحّدة (تقرأ إعدادات التاجر) مع: طباعة + إرسال واتساب +
/// عملية جديدة. موصولة ببيانات البيع الحقيقية.
class CashierReceiptScreen extends StatefulWidget {
  const CashierReceiptScreen({
    super.key,
    required this.sale,
    required this.total,
    required this.method,
    this.customerName,
    this.customerPhone,
    this.pendingPayment = false,
  });

  final Map<String, dynamic> sale;
  final double total;
  final String method;
  final String? customerName;
  final String? customerPhone;

  /// أميال باي: بانتظار دفع العميل عبر QR.
  final bool pendingPayment;

  @override
  State<CashierReceiptScreen> createState() => _CashierReceiptScreenState();
}

class _CashierReceiptScreenState extends State<CashierReceiptScreen> {
  final ScreenshotController _shot = ScreenshotController();
  late final ReceiptSettingsController _settings;
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    _settings = Get.isRegistered<ReceiptSettingsController>()
        ? Get.find<ReceiptSettingsController>()
        : Get.put(ReceiptSettingsController(), permanent: true);
    _settings.load();
  }

  String get _methodLabel => switch (widget.method) {
        'cash' => 'نقداً',
        'credit' => 'بيع آجل',
        'amial_pay' => 'أميال باي',
        'mixed' => 'مختلط (نقد + محفظة)',
        'corporate' => 'حساب شركة',
        _ => widget.method,
      };

  String get _ref => '${widget.sale['sale_ulid'] ?? widget.sale['id'] ?? ''}';

  String _fmtNum(double v) => v
      .toStringAsFixed(v == v.roundToDouble() ? 0 : 2)
      .replaceAllMapped(RegExp(r'(\d)(?=(\d{3})+(?:\.|$))'), (m) => '${m[1]},');

  String _now() {
    final d = DateTime.now();
    final h12 = d.hour % 12 == 0 ? 12 : d.hour % 12;
    final ampm = d.hour < 12 ? 'ص' : 'م';
    return '${d.year}/${d.month.toString().padLeft(2, '0')}/${d.day.toString().padLeft(2, '0')}  •  '
        '$h12:${d.minute.toString().padLeft(2, '0')} $ampm';
  }

  List<(String, String)> _rows() {
    final items = (widget.sale['items'] as List?) ?? const [];
    if (items.isEmpty) return const [];
    return items.take(15).map<(String, String)>((e) {
      final m = e as Map;
      final name = '${m['name'] ?? ''}';
      final qty = int.tryParse('${m['qty'] ?? 1}') ?? 1;
      final price = double.tryParse('${m['price'] ?? 0}') ?? 0;
      final line = (price * qty);
      return ('$name ×$qty', AmialMoney.yer(line));
    }).toList();
  }

  Future<File?> _capture() async {
    final Uint8List? bytes = await _shot.capture(pixelRatio: 3);
    if (bytes == null) return null;
    final dir = await getApplicationDocumentsDirectory();
    final f = File('${dir.path}/invoice_${DateTime.now().millisecondsSinceEpoch}.png');
    await f.writeAsBytes(bytes, flush: true);
    return f;
  }

  List<ThermalReceiptLine> _thermalLines() {
    final items = (widget.sale['items'] as List?) ?? const [];
    return items.map<ThermalReceiptLine>((e) {
      final m = e as Map;
      return ThermalReceiptLine('${m['name'] ?? ''}',
          int.tryParse('${m['qty'] ?? 1}') ?? 1, double.tryParse('${m['price'] ?? 0}') ?? 0);
    }).toList();
  }

  /// طباعة: على الطابعة الحرارية (بهويّة المتجر + الشعار) إن كانت مضبوطة،
  /// وإلا حفظ الفاتورة صورةً وفتحها (احتياطي يعمل دائماً).
  Future<void> _print() async {
    setState(() => _busy = true);
    try {
      final svc = Get.isRegistered<ThermalPrintService>() ? Get.find<ThermalPrintService>() : null;
      if (svc != null && svc.config.value != null) {
        final r = await svc.printSale(
          settings: _settings.effective,
          lines: _thermalLines(),
          total: widget.total,
          invoiceNo: _ref,
          dateTime: DateTime.now(),
        );
        if (mounted) _snack(r.message, ok: r.ok);
      } else {
        // لا طابعة مضبوطة — احفظ صورةً وافتحها، مع دعوة لإعداد الطابعة.
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

  Future<void> _whatsapp() async {
    setState(() => _busy = true);
    try {
      final f = await _capture();
      if (f == null) throw Exception('capture');
      final caption = 'فاتورة بيع — ${widget.sale['store_name'] ?? ''}\n'
          'الإجمالي: ${AmialMoney.yer(widget.total)}\nمرجع: $_ref';
      await Share.shareXFiles([XFile(f.path, mimeType: 'image/png')], text: caption);
      final phone = _waPhone(widget.customerPhone);
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
    final waiting = widget.pendingPayment;
    return Scaffold(
      backgroundColor: AmyalColors.background,
      appBar: AppBar(
        backgroundColor: AmyalColors.primary,
        foregroundColor: Colors.white,
        title: Text(waiting ? 'بانتظار الدفع' : 'تم التحصيل'),
        leading: IconButton(icon: const Icon(Icons.close), onPressed: () => Get.back()),
      ),
      body: ListView(padding: const EdgeInsets.all(20), children: [
        // شارة الحالة
        Center(
          child: Container(
            height: 88, width: 88,
            decoration: BoxDecoration(
                color: waiting ? AmyalColors.yellow : const Color(0xFF2E7D32),
                shape: BoxShape.circle),
            child: Icon(waiting ? Icons.qr_code_2 : Icons.check_rounded,
                color: waiting ? const Color(0xFF053391) : Colors.white, size: 48),
          ),
        ),
        const SizedBox(height: 12),
        Text(waiting ? 'بانتظار دفع العميل' : 'تمّت العملية بنجاح',
            textAlign: TextAlign.center,
            style: TextStyle(
                fontSize: 17, fontWeight: FontWeight.bold,
                color: waiting ? AmyalColors.yellowDark : const Color(0xFF2E7D32))),
        const SizedBox(height: 18),

        // الفاتورة الموحّدة
        Center(
          child: Screenshot(
            controller: _shot,
            child: Obx(() => AmialInvoiceCard(
                  settings: _settings.effective,
                  title: widget.method == 'credit' ? 'فاتورة بيع آجل' : 'فاتورة بيع',
                  rows: _rows(),
                  total: _fmtNum(widget.total),
                  method: _methodLabel,
                  reference: _ref,
                  dateTime: _now(),
                  customer: widget.customerName ?? widget.customerPhone,
                  totalYer: widget.total,
                  currencies: _settings.currencies,
                )),
          ),
        ),
        const SizedBox(height: 22),

        if (!waiting) ...[
          Row(children: [
            Expanded(child: OutlinedButton.icon(
              onPressed: _busy ? null : _print,
              icon: const Icon(Icons.print_outlined, size: 20),
              label: const Text('طباعة'),
              style: OutlinedButton.styleFrom(
                  foregroundColor: AmyalColors.primary,
                  side: const BorderSide(color: AmyalColors.primary),
                  minimumSize: const Size.fromHeight(50)),
            )),
            const SizedBox(width: 10),
            Expanded(child: FilledButton.icon(
              onPressed: _busy ? null : _whatsapp,
              icon: _busy
                  ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(color: Colors.white, strokeWidth: 2))
                  : const Icon(Icons.chat, size: 20),
              label: const Text('واتساب'),
              style: FilledButton.styleFrom(
                  backgroundColor: const Color(0xFF25D366), minimumSize: const Size.fromHeight(50)),
            )),
          ]),
          const SizedBox(height: 10),
        ],
        FilledButton.icon(
          onPressed: () => Get.off(() => const CashierPosScreen()),
          icon: const Icon(Icons.add),
          label: const Text('عملية جديدة',
              style: TextStyle(fontSize: 16, fontWeight: FontWeight.w700)),
          style: FilledButton.styleFrom(
              backgroundColor: AmyalColors.primary, minimumSize: const Size.fromHeight(54)),
        ),
      ]),
    );
  }
}
