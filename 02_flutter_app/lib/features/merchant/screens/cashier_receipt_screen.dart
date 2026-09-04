import 'dart:io';
import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:open_file/open_file.dart';
import 'package:path_provider/path_provider.dart';
import 'package:screenshot/screenshot.dart';
import 'package:amial_pay/data/api/api_client.dart';
import 'package:amial_pay/features/merchant/controllers/receipt_settings_controller.dart';
import 'package:amial_pay/features/merchant/screens/cashier_pos_screen.dart';
import 'package:amial_pay/features/merchant/widgets/invoice_whatsapp_sheet.dart';
import 'package:amial_pay/features/merchant/widgets/merchant_invoice_actions.dart';
import 'package:amial_pay/features/payments/widgets/amial_invoice_card.dart';
import 'package:amial_pay/features/printer/services/thermal_print_service.dart';
import 'package:amial_pay/features/printer/widgets/thermal_receipt_widget.dart';
import 'package:amial_pay/features/printer/screens/printer_settings_screen.dart';
import 'package:amial_pay/theme/amial_colors.dart';

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
    this.invoicePath,
    this.invoiceTitle,
    this.nextSalePage,
    this.nextSaleRoute,
    this.currencySymbol,
    this.baseTotal,
  });

  final Map<String, dynamic> sale;
  final double total;
  final String method;

  // ═════════════════════════════════════════════════════════════════
  // AMIAL-MULTI-CURRENCY-003 — **الإيصالُ يقول عملتَه.**
  //
  // كانت كلُّ الأرقام هنا تُنسَّق بـ`AmialMoney.yer` و`totalYer`، فبيعةٌ
  // بعشرين دولاراً كانت تُطبَع «٢٠ ر.ي». **رقمٌ صحيحٌ بعملةٍ كاذبة** —
  // وهو العطلُ الذي كلّف هذا المشروعَ في تسعيرة الباقات (٣٥ ر.س عُرضت
  // «ر.ي»، والفرقُ سبعون ضعفاً).
  //
  // و`baseTotal` يبقى بالريال ليصحّ سطرُ المكافئات في أسفل الفاتورة —
  // فهو محسوبٌ من الإجماليّ بالأساس.
  // ═════════════════════════════════════════════════════════════════

  /// علامةُ عملة البيعة. `null` = الأساس (ر.ي).
  final String? currencySymbol;

  /// المكافئُ بالعملة الأساس — لسطر «≈». `null` = `total` نفسُه.
  final double? baseTotal;
  final String? customerName;
  final String? customerPhone;

  /// أميال باي: بانتظار دفع العميل عبر QR.
  final bool pendingPayment;

  /// مسار PDF القطاعي. كاشير الصيدلية يقرأ `pharmacy_sales` لا `merchant_sales`.
  final String? invoicePath;

  /// عنوان الإيصال المعروض، مثل «فاتورة صيدلية».
  final String? invoiceTitle;

  /// لا تعُد إلى الكاشير العام بعد بيع قطاعي متخصص.
  final Widget Function()? nextSalePage;

  /// بديلٌ مسمّى لعملية جديدة في قطاعٍ متخصص. المسار يمنع الحلقة بين
  /// شاشة الإيصال وشاشات القطاعات (مثل المطعم) ويعيد المستخدم إلى مساحة
  /// عمله الصحيحة بدلاً من كاشير التجزئة العام.
  final String? nextSaleRoute;

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
    _settings.load().then((_) {
      if (_settings.effective['auto_print_receipts'] == true && mounted) {
        WidgetsBinding.instance.addPostFrameCallback((_) => _print());
      }
    });
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

  /// AMIAL-MULTI-CURRENCY-003 — علامةُ عملة البيعة، والأساسُ افتراضاً.
  String get _sym => widget.currencySymbol ?? 'ر.ي';

  /// مبلغٌ **بعملة البيعة** — لا بالريال دائماً.
  String _money(double v) => '${_fmtNum(v)} $_sym';

  String _fmtNum(double v) => v
      .toStringAsFixed(v == v.roundToDouble() ? 0 : 2)
      .replaceAllMapped(RegExp(r'(\d)(?=(\d{3})+(?:\.|$))'), (m) => '${m[1]},');

  String _now() {
    final d = DateTime.now();
    return '${d.year}/${d.month.toString().padLeft(2, '0')}/${d.day.toString().padLeft(2, '0')}  •  '
        '${d.hour.toString().padLeft(2, '0')}:${d.minute.toString().padLeft(2, '0')}';
  }

  List<(String, String)> _rows() {
    final items = (widget.sale['items'] as List?) ?? const [];
    if (items.isEmpty) return const [];
    return items.take(15).map<(String, String)>((e) {
      final m = e as Map;
      final name = '${m['name'] ?? m['product_trade_name'] ?? ''}';
      final qty = int.tryParse('${m['qty'] ?? m['quantity'] ?? 1}') ?? 1;
      final price = double.tryParse('${m['price'] ?? m['unit_price'] ?? 0}') ?? 0;
      final line = (price * qty);
      return ('$name ×$qty', _money(line));
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
      return ThermalReceiptLine('${m['name'] ?? m['product_trade_name'] ?? ''}',
          int.tryParse('${m['qty'] ?? m['quantity'] ?? 1}') ?? 1,
          double.tryParse('${m['price'] ?? m['unit_price'] ?? 0}') ?? 0);
    }).toList();
  }

  /// طباعة: على الطابعة الحرارية (بهويّة المتجر + الشعار) إن كانت مضبوطة،
  /// وإلا حفظ الفاتورة صورةً وفتحها (احتياطي يعمل دائماً).
  Future<void> _print() async {
    setState(() => _busy = true);
    try {
      final svc = Get.isRegistered<ThermalPrintService>() ? Get.find<ThermalPrintService>() : null;
      if (svc != null && svc.config.value != null) {
        final discount = double.tryParse('${widget.sale['discount_amount'] ?? 0}') ?? 0;
        final subtotal = double.tryParse('${widget.sale['subtotal'] ?? ''}') ?? (widget.total + discount);
        final isCredit = widget.method == 'credit';
        final r = await svc.printSale(
          settings: _settings.effective,
          lines: _thermalLines(),
          total: widget.total,
          subtotal: subtotal,
          discount: discount,
          paid: isCredit ? 0 : widget.total,
          balanceDue: isCredit ? widget.total : 0,
          contextLines: [
            'طريقة الدفع: $_methodLabel',
            if ((widget.customerName ?? widget.customerPhone ?? '').isNotEmpty)
              'العميل: ${widget.customerName ?? widget.customerPhone}',
            'مرجع البيع: $_ref',
          ],
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
            backgroundColor: AmialColors.primary,
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
    final store = '${_settings.effective['store_name'] ?? widget.sale['store_name'] ?? 'التاجر'}';
    await InvoiceWhatsAppSheet.open(
      context,
      invoiceNumber: _ref,
      initialPhone: widget.customerPhone,
      captureFile: _capture,
      message: 'فاتورة بيع من $store\n'
          'رقم الفاتورة: $_ref\n'
          'الإجمالي: ${_money(widget.total)}\n'
          'طريقة الدفع: $_methodLabel',
    );
  }

  /// PDF من الخادم، لا تحويل صورة الشاشة إلى ملف باسم PDF.
  Future<void> _downloadPdf() async {
    final ulid = _ref;
    if (!RegExp(r'^[A-Z0-9]{26}$').hasMatch(ulid)) {
      _snack('رقم الفاتورة غير صالح للتنزيل');
      return;
    }
    setState(() => _busy = true);
    try {
      String? failure;
      final path = await Get.find<ApiClient>().downloadFile(
        widget.invoicePath ?? '/api/v1/amial/merchant/cashier/sales/$ulid/invoice',
        fileName: 'amial_invoice_$ulid.pdf',
        onError: (message) => failure = message,
      );
      if (path == null) {
        _snack(failure ?? 'تعذّر تنزيل الفاتورة');
        return;
      }
      await OpenFile.open(path, type: 'application/pdf');
      _snack('تم تنزيل الفاتورة PDF', ok: true);
    } catch (_) {
      _snack('تعذّر تنزيل الفاتورة');
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  void _snack(String m, {bool ok = false}) => ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(content: Text(m), backgroundColor: ok ? AmialColors.success : AmialColors.red));

  @override
  Widget build(BuildContext context) {
    final waiting = widget.pendingPayment;
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(
        title: Text(waiting ? 'بانتظار الدفع' : 'تم التحصيل'),
        leading: IconButton(icon: const Icon(Icons.close), onPressed: () => Get.back()),
      ),
      body: ListView(padding: const EdgeInsets.all(20), children: [
        // شارة الحالة
        Center(
          child: Container(
            height: 88, width: 88,
            decoration: BoxDecoration(
                color: waiting ? AmialColors.yellow : AmialColors.success,
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
                color: waiting ? AmialColors.yellowDark : AmialColors.success)),
        const SizedBox(height: 18),

        // الفاتورة الموحّدة
        Center(
          child: Screenshot(
            controller: _shot,
            child: Obx(() => AmialInvoiceCard(
                  settings: _settings.effective,
                  title: widget.invoiceTitle ?? (widget.method == 'credit' ? 'فاتورة بيع آجل' : 'فاتورة بيع'),
                  rows: _rows(),
                  total: _fmtNum(widget.total),
                  currencyLabel: _sym,
                  method: _methodLabel,
                  reference: _ref,
                  dateTime: _now(),
                  customer: widget.customerName ?? widget.customerPhone,
                  // **المكافئُ يُحسب من الأساس** — لا من مبلغٍ بالدولار،
                  // وإلّا ضُرب سعرُ الصرف مرّتين.
                  totalYer: widget.baseTotal ?? widget.total,
                  currencies: _settings.currencies,
                )),
          ),
        ),
        const SizedBox(height: 22),

        if (!waiting) ...[
          MerchantInvoiceActions(
            busy: _busy,
            onPrint: _print,
            onWhatsApp: _whatsapp,
            onPdf: _downloadPdf,
          ),
          const SizedBox(height: 10),
        ],
        FilledButton.icon(
          onPressed: () {
            final route = widget.nextSaleRoute;
            if (route != null && route.isNotEmpty) {
              Get.offAllNamed(route);
              return;
            }
            Get.off(widget.nextSalePage ?? () => const CashierPosScreen());
          },
          icon: const Icon(Icons.add),
          label: const Text('عملية جديدة',
              style: TextStyle(fontSize: 16, fontWeight: FontWeight.w700)),
          style: FilledButton.styleFrom(
              backgroundColor: AmialColors.primary, minimumSize: const Size.fromHeight(54)),
        ),
      ]),
    );
  }
}
