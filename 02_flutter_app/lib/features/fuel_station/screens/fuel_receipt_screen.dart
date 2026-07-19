import 'dart:io';
import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:open_file/open_file.dart';
import 'package:path_provider/path_provider.dart';
import 'package:screenshot/screenshot.dart';
import 'package:share_plus/share_plus.dart';
import 'package:url_launcher/url_launcher.dart';
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

  /// طباعة/حفظ: يلتقط الإيصال صورةً ويفتحه بتطبيق النظام (طباعة أو حفظ بالمعرض).
  Future<void> _print() async {
    setState(() => _busy = true);
    try {
      final f = await _capture();
      if (f == null) throw Exception('capture');
      await OpenFile.open(f.path, type: 'image/png');
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

  void _snack(String m) => ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(content: Text(m), backgroundColor: AmyalColors.red));

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmyalColors.background,
      appBar: AppBar(
        backgroundColor: AmyalColors.primary,
        foregroundColor: Colors.white,
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

          // ====== الفاتورة الحرارية 80مم (تُلتقط كاملة) ======
          Center(
            child: Screenshot(
              controller: _shot,
              child: Container(
                width: 300, // ≈ 80مم عند pixelRatio عالٍ
                padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 22),
                color: Colors.white,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    // الترويسة
                    Text(widget.stationName,
                        textAlign: TextAlign.center,
                        style: const TextStyle(
                            fontSize: 18,
                            fontWeight: FontWeight.bold,
                            color: Colors.black)),
                    const SizedBox(height: 2),
                    const Text('فاتورة تعبئة وقود',
                        textAlign: TextAlign.center,
                        style: TextStyle(fontSize: 12, color: Colors.black54)),
                    const SizedBox(height: 2),
                    const Text('مدعوم من أميال باي',
                        textAlign: TextAlign.center,
                        style: TextStyle(fontSize: 10, color: Colors.black38)),
                    _dashed(),

                    _line('نوع الوقود', '${widget.sale['product_name'] ?? widget.sale['fuel_product'] ?? 'وقود'}'),
                    if (widget.pumpLabel != null) _line('المضخّة', widget.pumpLabel!),
                    _line('اللترات', '${_fmt(widget.sale['liters'])} لتر'),
                    _line('السعر/لتر', '${_fmt(widget.sale['price_per_liter'])} ر.ي'),
                    _dashed(),

                    // الإجمالي بارز
                    Row(
                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                      children: [
                        Text('${_fmt(widget.sale['total_amount'])} ر.ي',
                            style: const TextStyle(
                                fontSize: 22,
                                fontWeight: FontWeight.bold,
                                color: Colors.black)),
                        const Text('الإجمالي',
                            style: TextStyle(
                                fontSize: 14,
                                fontWeight: FontWeight.bold,
                                color: Colors.black)),
                      ],
                    ),
                    _dashed(),

                    _line('طريقة الدفع', _method),
                    if (widget.customerPhone != null &&
                        widget.customerPhone!.isNotEmpty)
                      _line('العميل', widget.customerPhone!),
                    _line('التاريخ', _now()),
                    const SizedBox(height: 6),
                    Text('مرجع: $_ref',
                        textDirection: TextDirection.ltr,
                        textAlign: TextAlign.center,
                        style: const TextStyle(fontSize: 10, color: Colors.black45)),
                    _dashed(),
                    const Text('شكراً لتعاملكم معنا',
                        textAlign: TextAlign.center,
                        style: TextStyle(
                            fontSize: 12,
                            fontWeight: FontWeight.w600,
                            color: Colors.black)),
                  ],
                ),
              ),
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

  Widget _line(String k, String v) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 3),
        child: Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
          Flexible(
            child: Text(v,
                textAlign: TextAlign.left,
                style: const TextStyle(
                    fontSize: 13, fontWeight: FontWeight.w600, color: Colors.black)),
          ),
          Text(k, style: const TextStyle(fontSize: 12, color: Colors.black54)),
        ]),
      );

  Widget _dashed() => const Padding(
        padding: EdgeInsets.symmetric(vertical: 8),
        child: ClipRect(
          child: SizedBox(
            height: 12,
            child: Text(
              '- - - - - - - - - - - - - - - - - - - - - - - - - - - - - -',
              maxLines: 1,
              overflow: TextOverflow.clip,
              style: TextStyle(color: Colors.black26, fontSize: 11, height: 1),
            ),
          ),
        ),
      );
}
