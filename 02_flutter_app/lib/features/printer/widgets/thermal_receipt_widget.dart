import 'dart:typed_data';
import 'package:flutter/material.dart';

/// AMIAL-THERMAL-PRINT-001 — سطر في الإيصال الحراري.
class ThermalReceiptLine {
  final String name;
  final num qty;
  final num price; // سعر الوحدة
  final num? lineTotal;
  final String? details;
  const ThermalReceiptLine(this.name, this.qty, this.price, {this.lineTotal, this.details});

  num get total => lineTotal ?? qty * price;
}

/// إيصال حراري بتصميم مناسب للطباعة (أبيض/أسود، عالي التباين).
///
/// يُرسم ثم يُلتقط صورةً ويُطبع raster — لذا تظهر العربية سليمةً.
class ThermalReceiptWidget extends StatelessWidget {
  const ThermalReceiptWidget({
    super.key,
    required this.storeName,
    required this.lines,
    required this.total,
    this.subtitle,
    this.footer = 'شكراً لتعاملكم معنا',
    this.dateTime,
    this.invoiceNo,
    this.paid,
    this.change,
    this.subtotal,
    this.discount,
    this.tax,
    this.balanceDue,
    this.contextLines = const [],
    this.logoBytes,
    this.phone,
    this.address,
  });

  /// يبني الإيصال من إعدادات متجر التاجر (اسم/شعار/هاتف/عنوان/تذييل).
  factory ThermalReceiptWidget.fromSettings({
    required Map<String, dynamic> settings,
    required List<ThermalReceiptLine> lines,
    required num total,
    Uint8List? logoBytes,
    String? invoiceNo,
    DateTime? dateTime,
    num? paid,
    num? change,
    num? subtotal,
    num? discount,
    num? tax,
    num? balanceDue,
    List<String> contextLines = const [],
  }) {
    String s(String k, [String d = '']) => '${settings[k] ?? d}';
    bool flag(String k) => settings[k] == true || settings[k] == 1 || settings[k] == '1';
    return ThermalReceiptWidget(
      storeName: s('store_name', 'المتجر'),
      subtitle: s('header_note'),
      footer: s('footer_note', 'شكراً لتعاملكم معنا'),
      phone: flag('show_phone') ? s('phone') : null,
      address: flag('show_address') ? s('address') : null,
      logoBytes: flag('show_logo') ? logoBytes : null,
      lines: lines,
      total: total,
      invoiceNo: invoiceNo,
      dateTime: dateTime,
      paid: paid,
      change: change,
      subtotal: subtotal,
      discount: discount,
      tax: tax,
      balanceDue: balanceDue,
      contextLines: contextLines,
    );
  }

  final String storeName;
  final String? subtitle;
  final List<ThermalReceiptLine> lines;
  final num total;
  final num? paid;
  final num? change;
  final num? subtotal;
  final num? discount;
  final num? tax;
  final num? balanceDue;
  final List<String> contextLines;
  final String footer;
  final DateTime? dateTime;
  final String? invoiceNo;
  final Uint8List? logoBytes;
  final String? phone;
  final String? address;

  String _money(num v) => v.toStringAsFixed(0);

  @override
  Widget build(BuildContext context) {
    final dt = dateTime ?? DateTime.now();
    final ts = '${dt.year}-${dt.month.toString().padLeft(2, '0')}-${dt.day.toString().padLeft(2, '0')} '
        '${dt.hour.toString().padLeft(2, '0')}:${dt.minute.toString().padLeft(2, '0')}';
    const black = TextStyle(color: Colors.black, fontSize: 22, fontWeight: FontWeight.w600, height: 1.25);
    const small = TextStyle(color: Colors.black, fontSize: 18, height: 1.2);

    return Directionality(
      textDirection: TextDirection.rtl,
      child: Container(
        color: Colors.white,
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 16),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
          if (logoBytes != null) ...[
            Center(child: Image.memory(logoBytes!, height: 90, fit: BoxFit.contain,
                errorBuilder: (_, __, ___) => const SizedBox.shrink())),
            const SizedBox(height: 8),
          ],
          Text(storeName, textAlign: TextAlign.center,
              style: const TextStyle(color: Colors.black, fontSize: 30, fontWeight: FontWeight.bold)),
          if (subtitle != null && subtitle!.isNotEmpty) ...[
            const SizedBox(height: 2),
            Text(subtitle!, textAlign: TextAlign.center, style: small),
          ],
          if (phone != null && phone!.isNotEmpty) ...[
            const SizedBox(height: 2),
            Text('هاتف: $phone', textAlign: TextAlign.center, style: small),
          ],
          if (address != null && address!.isNotEmpty) ...[
            const SizedBox(height: 2),
            Text(address!, textAlign: TextAlign.center, style: small),
          ],
          const SizedBox(height: 10),
          if (invoiceNo != null) Text('فاتورة: $invoiceNo', style: small),
          Text('التاريخ: $ts', style: small),
          _divider(),
          // رأس الأعمدة
          Row(children: const [
            Expanded(flex: 5, child: Text('الصنف', style: TextStyle(color: Colors.black, fontSize: 18, fontWeight: FontWeight.bold))),
            Expanded(flex: 2, child: Text('كمية', textAlign: TextAlign.center, style: TextStyle(color: Colors.black, fontSize: 18, fontWeight: FontWeight.bold))),
            Expanded(flex: 3, child: Text('الإجمالي', textAlign: TextAlign.left, style: TextStyle(color: Colors.black, fontSize: 18, fontWeight: FontWeight.bold))),
          ]),
          _divider(),
          ...lines.map((l) => Padding(
                padding: const EdgeInsets.symmetric(vertical: 3),
                child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Expanded(flex: 5, child: Text(l.name, style: small)),
                  Expanded(flex: 2, child: Text('${l.qty}', textAlign: TextAlign.center, style: small)),
                  Expanded(flex: 3, child: Text(_money(l.total), textAlign: TextAlign.left, style: small)),
                ]),
              )),
          if (lines.any((line) => line.details != null && line.details!.isNotEmpty)) ...[
            const SizedBox(height: 3),
            ...lines.where((line) => line.details != null && line.details!.isNotEmpty).map(
                  (line) => Text('${line.name}: ${line.details}', style: small.copyWith(fontSize: 16)),
                ),
          ],
          if (contextLines.isNotEmpty) ...[
            _divider(),
            ...contextLines.map((line) => Text(line, style: small)),
          ],
          _divider(),
          if (subtotal != null) Row(children: [
            const Expanded(child: Text('المجموع الفرعي', style: small)),
            Text('${_money(subtotal!)} ر.ي', style: small),
          ]),
          if (discount != null && discount! > 0) Row(children: [
            const Expanded(child: Text('الخصم', style: small)),
            Text('- ${_money(discount!)} ر.ي', style: small),
          ]),
          if (tax != null && tax! > 0) Row(children: [
            const Expanded(child: Text('الضريبة', style: small)),
            Text('${_money(tax!)} ر.ي', style: small),
          ]),
          Row(children: [
            const Expanded(child: Text('الإجمالي', style: TextStyle(color: Colors.black, fontSize: 26, fontWeight: FontWeight.bold))),
            Text('${_money(total)} ر.ي', style: black.copyWith(fontSize: 26, fontWeight: FontWeight.bold)),
          ]),
          if (paid != null) ...[
            const SizedBox(height: 4),
            Row(children: [
              const Expanded(child: Text('المدفوع', style: small)),
              Text('${_money(paid!)} ر.ي', style: small),
            ]),
          ],
          if (change != null) ...[
            Row(children: [
              const Expanded(child: Text('الباقي', style: small)),
              Text('${_money(change!)} ر.ي', style: small),
            ]),
          ],
          if (balanceDue != null && balanceDue! > 0) ...[
            Row(children: [
              const Expanded(child: Text('المتبقي', style: small)),
              Text('${_money(balanceDue!)} ر.ي', style: black),
            ]),
          ],
          _divider(),
          const SizedBox(height: 4),
          Text(footer, textAlign: TextAlign.center, style: black.copyWith(fontSize: 20)),
          const SizedBox(height: 6),
          const Text('أميال باي', textAlign: TextAlign.center,
              style: TextStyle(color: Colors.black, fontSize: 16, fontWeight: FontWeight.w500)),
        ]),
      ),
    );
  }

  Widget _divider() => const Padding(
        padding: EdgeInsets.symmetric(vertical: 6),
        child: DottedLine(),
      );
}

/// سند محفظة حراري؛ لا نسمّيه فاتورة ولا نحشره في جدول أصناف بيع.
class ThermalVoucherWidget extends StatelessWidget {
  const ThermalVoucherWidget({
    super.key,
    required this.title,
    required this.documentNumber,
    required this.amount,
    required this.fee,
    required this.finalAmount,
    required this.finalLabel,
    required this.transactionNumber,
    required this.verificationCode,
    this.fromName,
    this.toName,
    this.issuedAt,
  });

  final String title;
  final String documentNumber;
  final num amount;
  final num fee;
  final num finalAmount;
  final String finalLabel;
  final String transactionNumber;
  final String verificationCode;
  final String? fromName;
  final String? toName;
  final DateTime? issuedAt;

  String _money(num value) => value.toStringAsFixed(0);

  @override
  Widget build(BuildContext context) {
    final dt = issuedAt ?? DateTime.now();
    final timestamp = '${dt.year}-${dt.month.toString().padLeft(2, '0')}-${dt.day.toString().padLeft(2, '0')} '
        '${dt.hour.toString().padLeft(2, '0')}:${dt.minute.toString().padLeft(2, '0')}';
    const normal = TextStyle(color: Colors.black, fontSize: 18, height: 1.3);
    const strong = TextStyle(color: Colors.black, fontSize: 20, fontWeight: FontWeight.bold, height: 1.3);

    Widget row(String label, String value, {bool bold = false}) => Padding(
          padding: const EdgeInsets.symmetric(vertical: 3),
          child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Expanded(child: Text(label, style: bold ? strong : normal)),
            Expanded(child: Text(value, textAlign: TextAlign.left, style: bold ? strong : normal)),
          ]),
        );

    return Directionality(
      textDirection: TextDirection.rtl,
      child: Container(
        color: Colors.white,
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 16),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
          const Text('أميال باي', textAlign: TextAlign.center,
              style: TextStyle(color: Colors.black, fontSize: 30, fontWeight: FontWeight.bold)),
          const SizedBox(height: 3),
          Text(title, textAlign: TextAlign.center, style: strong.copyWith(fontSize: 24)),
          const SizedBox(height: 8),
          row('رقم السند', documentNumber),
          row('التاريخ', timestamp),
          const DottedLine(),
          if (fromName != null && fromName!.isNotEmpty) row('من', fromName!),
          if (toName != null && toName!.isNotEmpty) row('إلى', toName!),
          const DottedLine(),
          row('المبلغ', '${_money(amount)} ر.ي'),
          row('الرسوم', '${_money(fee)} ر.ي'),
          row(finalLabel, '${_money(finalAmount)} ر.ي', bold: true),
          const DottedLine(),
          row('مرجع العملية', transactionNumber),
          row('رمز التحقق', verificationCode),
          const SizedBox(height: 8),
          const Text('سند إلكتروني - الطباعة لا تنشئ معاملة جديدة',
              textAlign: TextAlign.center, style: normal),
        ]),
      ),
    );
  }
}

/// خطّ منقّط للفصل (أوضح من Divider في الطباعة).
class DottedLine extends StatelessWidget {
  const DottedLine({super.key});

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(builder: (context, c) {
      final count = (c.maxWidth / 6).floor();
      return Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: List.generate(count, (_) => const SizedBox(
              width: 3, height: 2,
              child: DecoratedBox(decoration: BoxDecoration(color: Colors.black)),
            )),
      );
    });
  }
}
