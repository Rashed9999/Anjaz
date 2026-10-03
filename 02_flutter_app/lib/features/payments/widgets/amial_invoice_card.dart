import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:qr_flutter/qr_flutter.dart';

/// AMIAL-RECEIPT-SETTINGS-001 — بطاقة الفاتورة الموحّدة (حرارية 58/80مم).
///
/// مكوّن واحد لكل القطاعات: يقرأ إعدادات التاجر (شعار/ترويسة/تذييل/هاتف/عرض
/// الورق) ويعرض فاتورة قابلة للالتقاط والطباعة. يستخدمه كاشير المتجر والوقود…
class AmialInvoiceCard extends StatelessWidget {
  const AmialInvoiceCard({
    super.key,
    required this.settings,
    required this.title,
    required this.rows,
    required this.total,
    this.currencyLabel = 'ر.ي',
    this.method,
    this.reference,
    this.dateTime,
    this.customer,
    this.totalYer,
    this.currencies = const [],
    this.verificationUrl,
    this.paidAmount,
    this.changeAmount,
    this.balanceDueAmount,
  });

  /// إعدادات الفاتورة كما تعود من الخادم (merchant/receipt-settings).
  final Map<String, dynamic> settings;
  final String title;

  /// أسطر التفاصيل (label, value) — مثل: اللترات، السعر/لتر…
  final List<(String, String)> rows;
  final String total;
  final String currencyLabel;
  final String? method;
  final String? reference;
  final String? dateTime;
  final String? customer;

  /// AMIAL-MULTI-CURRENCY-001: الإجمالي بالريال + العملات لعرض المكافئ.
  final double? totalYer;
  final List<Map<String, dynamic>> currencies;
  final String? verificationUrl;
  final String? paidAmount;
  final String? changeAmount;
  final String? balanceDueAmount;

  double get _width => (settings['paper_width'] == 58) ? 230 : 300;
  bool _flag(String k, [bool def = true]) => settings[k] == null ? def : settings[k] == true;

  /// «≈ 22.6 USD • 84.3 SAR» — مكافئ الإجمالي بالعملات الفعّالة.
  String _equivalents() {
    if (totalYer == null || totalYer! <= 0 || currencies.isEmpty) return '';
    final parts = <String>[];
    for (final c in currencies) {
      final rate = double.tryParse('${c['rate_to_base'] ?? 0}') ?? 0;
      if (rate <= 0) continue;
      final v = totalYer! / rate;
      parts.add('${v.toStringAsFixed(2)} ${c['symbol'] ?? c['code'] ?? ''}');
    }
    return parts.isEmpty ? '' : '≈ ${parts.join(' • ')}';
  }

  @override
  Widget build(BuildContext context) {
    final storeName = '${settings['store_name'] ?? 'المتجر'}';
    final header = '${settings['header_note'] ?? ''}';
    final footer = '${settings['footer_note'] ?? 'شكراً لتعاملكم معنا'}';
    final phone = '${settings['phone'] ?? ''}';
    final address = '${settings['address'] ?? ''}';
    final logoUrl = '${settings['logo_url'] ?? ''}';
    final cur = '${settings['currency_label'] ?? currencyLabel}';

    return Container(
      width: _width,
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 20),
      color: Colors.white,
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        if (_flag('show_logo') && logoUrl.isNotEmpty)
          Center(
            child: ClipRRect(
              borderRadius: BorderRadius.circular(8),
              child: Image.network(logoUrl, height: 54, fit: BoxFit.contain,
                  errorBuilder: (_, __, ___) => const SizedBox.shrink()),
            ),
          ),
        const SizedBox(height: 6),
        Text(storeName, textAlign: TextAlign.center,
            style: const TextStyle(fontSize: 17, fontWeight: FontWeight.bold, color: Colors.black)),
        if (header.isNotEmpty)
          Text(header, textAlign: TextAlign.center,
              style: const TextStyle(fontSize: 11, color: Colors.black54)),
        if (_flag('show_phone') && phone.isNotEmpty)
          Text('هاتف: $phone', textAlign: TextAlign.center,
              textDirection: TextDirection.ltr,
              style: const TextStyle(fontSize: 10, color: Colors.black54)),
        if (_flag('show_address') && address.isNotEmpty)
          Text(address, textAlign: TextAlign.center,
              style: const TextStyle(fontSize: 10, color: Colors.black54)),
        const SizedBox(height: 2),
        Text(title, textAlign: TextAlign.center,
            style: const TextStyle(fontSize: 12, color: Colors.black87, fontWeight: FontWeight.w600)),
        _dashed(),

        ...rows.map((r) => Padding(
              padding: const EdgeInsets.symmetric(vertical: 3),
              child: Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
                Flexible(child: Text(r.$2, textAlign: TextAlign.left,
                    style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w600, color: Colors.black))),
                Text(r.$1, style: const TextStyle(fontSize: 12, color: Colors.black54)),
              ]),
            )),
        _dashed(),

        Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
          Text('$total $cur',
              style: const TextStyle(fontSize: 22, fontWeight: FontWeight.bold, color: Colors.black)),
          const Text('الإجمالي',
              style: TextStyle(fontSize: 14, fontWeight: FontWeight.bold, color: Colors.black)),
        ]),
        if (paidAmount != null) _line('receipt_tendered_amount'.tr, paidAmount!),
        if (changeAmount != null) _line('receipt_change'.tr, changeAmount!),
        if (balanceDueAmount != null) _line('receipt_balance_due'.tr, balanceDueAmount!),
        if (_equivalents().isNotEmpty)
          Padding(
            padding: const EdgeInsets.only(top: 4),
            child: Text(_equivalents(), textAlign: TextAlign.center,
                textDirection: TextDirection.ltr,
                style: const TextStyle(fontSize: 11, color: Colors.black54)),
          ),
        _dashed(),

        if (method != null) _line('طريقة الدفع', method!),
        if (customer != null && customer!.isNotEmpty) _line('العميل', customer!),
        if (dateTime != null) _line('التاريخ', dateTime!),
        if (reference != null) ...[
          const SizedBox(height: 6),
          Text('مرجع: $reference', textAlign: TextAlign.center, textDirection: TextDirection.ltr,
              style: const TextStyle(fontSize: 10, color: Colors.black45)),
        ],
        _dashed(),
        if (verificationUrl != null && verificationUrl!.isNotEmpty) ...[
          Text('document_verify_invoice'.tr, textAlign: TextAlign.center,
              style: TextStyle(fontSize: 10, fontWeight: FontWeight.w700, color: Colors.black87)),
          const SizedBox(height: 5),
          Center(child: QrImageView(
            data: verificationUrl!,
            size: 92,
            padding: EdgeInsets.zero,
            backgroundColor: Colors.white,
          )),
          const SizedBox(height: 3),
          Text(verificationUrl!, textAlign: TextAlign.center,
              textDirection: TextDirection.ltr,
              style: const TextStyle(fontSize: 7.5, color: Colors.black45)),
          _dashed(),
        ],
        Text(footer, textAlign: TextAlign.center,
            style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w600, color: Colors.black)),
        const SizedBox(height: 4),
        const Text('مدعوم من أميال باي', textAlign: TextAlign.center,
            style: TextStyle(fontSize: 9, color: Colors.black38)),
      ]),
    );
  }

  Widget _line(String k, String v) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 2),
        child: Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
          Flexible(child: Text(v, overflow: TextOverflow.ellipsis,
              style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w600, color: Colors.black))),
          Text(k, style: const TextStyle(fontSize: 11, color: Colors.black54)),
        ]),
      );

  Widget _dashed() => const Padding(
        padding: EdgeInsets.symmetric(vertical: 8),
        child: ClipRect(
          child: SizedBox(
            height: 12,
            child: Text(
              '- - - - - - - - - - - - - - - - - - - - - - - - - - - - - -',
              maxLines: 1, overflow: TextOverflow.clip,
              style: TextStyle(color: Colors.black26, fontSize: 11, height: 1),
            ),
          ),
        ),
      );
}
