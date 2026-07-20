import 'package:flutter/material.dart';

/// AMIAL-THERMAL-PRINT-001 — سطر في الإيصال الحراري.
class ThermalReceiptLine {
  final String name;
  final num qty;
  final num price; // سعر الوحدة
  const ThermalReceiptLine(this.name, this.qty, this.price);

  num get total => qty * price;
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
  });

  final String storeName;
  final String? subtitle;
  final List<ThermalReceiptLine> lines;
  final num total;
  final num? paid;
  final num? change;
  final String footer;
  final DateTime? dateTime;
  final String? invoiceNo;

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
          Text(storeName, textAlign: TextAlign.center,
              style: const TextStyle(color: Colors.black, fontSize: 30, fontWeight: FontWeight.bold)),
          if (subtitle != null && subtitle!.isNotEmpty) ...[
            const SizedBox(height: 2),
            Text(subtitle!, textAlign: TextAlign.center, style: small),
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
          _divider(),
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
