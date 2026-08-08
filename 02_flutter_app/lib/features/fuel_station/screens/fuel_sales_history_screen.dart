import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:get/get.dart';
import 'package:open_file/open_file.dart';
import 'package:share_plus/share_plus.dart';
import 'package:amial_pay/data/api/api_client.dart';

import 'package:intl/intl.dart';
import 'package:amial_pay/theme/amial_colors.dart';
import 'package:amial_pay/features/fuel_station/controllers/fuel_station_controller.dart';

/// AMIAL-FUEL-002 — سجل مبيعات الوقود + تنزيل PDF لإيصال أي عملية.
class FuelSalesHistoryScreen extends StatefulWidget {
  const FuelSalesHistoryScreen({super.key});

  @override
  State<FuelSalesHistoryScreen> createState() => _FuelSalesHistoryScreenState();
}

class _FuelSalesHistoryScreenState extends State<FuelSalesHistoryScreen> {
  late final FuelStationController c;
  String _filterMethod = 'all';

  @override
  void initState() {
    super.initState();
    c = Get.find<FuelStationController>();
    WidgetsBinding.instance.addPostFrameCallback((_) => c.loadSales());
  }

  Future<void> _applyFilter() async {
    final filters = <String, dynamic>{};
    if (_filterMethod != 'all') filters['payment_method'] = _filterMethod;
    await c.loadSales(filters: filters);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(
        title: const Text('سجل المبيعات'),
      ),
      body: Column(children: [
        // ====== فلتر ======
        Container(
          padding: const EdgeInsets.all(12),
          color: Colors.white,
          child: Row(children: [
            const Icon(Icons.filter_alt, size: 18, color: AmialColors.primary),
            const SizedBox(width: 8),
            Expanded(child: SingleChildScrollView(
              scrollDirection: Axis.horizontal,
              child: Row(children: [
                _filterChip('all', 'الكل'),
                const SizedBox(width: 6),
                _filterChip('cash', 'نقدي', Colors.green),
                const SizedBox(width: 6),
                _filterChip('amial_pay', 'أميال باي', Colors.blue),
                const SizedBox(width: 6),
                _filterChip('company_card', 'شركات', Colors.orange),
              ]),
            )),
          ]),
        ),
        // ====== القائمة ======
        Expanded(child: Obx(() {
          if (c.isLoading.value && c.sales.isEmpty) {
            return const Center(child: CircularProgressIndicator());
          }
          if (c.sales.isEmpty) {
            return Center(
              child: Column(mainAxisAlignment: MainAxisAlignment.center, children: [
                Icon(Icons.receipt_long, size: 64, color: Colors.grey.shade400),
                const SizedBox(height: 12),
                Text('لا توجد مبيعات', style: TextStyle(color: Colors.grey.shade600, fontSize: 15)),
              ]),
            );
          }
          return RefreshIndicator(
            onRefresh: _applyFilter,
            child: ListView.builder(
              padding: const EdgeInsets.all(12),
              itemCount: c.sales.length,
              itemBuilder: (_, i) => _saleCard(c.sales[i]),
            ),
          );
        })),
      ]),
    );
  }

  Widget _filterChip(String value, String label, [Color? color]) {
    final selected = _filterMethod == value;
    final defaultColor = color ?? AmialColors.primary;
    return InkWell(
      borderRadius: BorderRadius.circular(16),
      onTap: () { if (mounted) { setState(() => _filterMethod = value); _applyFilter(); } }, // AMIAL-FIX-006
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
        decoration: BoxDecoration(
          color: selected ? defaultColor : Colors.transparent,
          borderRadius: BorderRadius.circular(16),
          border: Border.all(color: selected ? defaultColor : Colors.grey.shade300),
        ),
        child: Text(label, style: TextStyle(
          color: selected ? Colors.white : Colors.black87,
          fontWeight: selected ? FontWeight.bold : FontWeight.normal,
          fontSize: 12,
        )),
      ),
    );
  }

  Widget _saleCard(Map<String, dynamic> sale) {
    final createdAt = sale['created_at'] != null
        ? DateTime.tryParse(sale['created_at'])
        : null;
    final method = sale['payment_method']?.toString() ?? '';
    final methodLabel = method == 'cash' ? 'نقدي'
        : method == 'amial_pay' ? 'أميال باي' : 'شركة';
    final methodColor = method == 'cash' ? Colors.green
        : method == 'amial_pay' ? Colors.blue : Colors.orange;
    final methodIcon = method == 'cash' ? Icons.payments
        : method == 'amial_pay' ? Icons.qr_code : Icons.business;

    return InkWell(
      borderRadius: BorderRadius.circular(12),
      onTap: () => _showSaleDetails(sale),
      child: Container(
        margin: const EdgeInsets.only(bottom: 8),
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(12)),
        child: Row(children: [
          // أيقونة طريقة الدفع
          Container(
            width: 44, height: 44,
            decoration: BoxDecoration(
              color: methodColor.withValues(alpha: 0.1),
              borderRadius: BorderRadius.circular(10),
            ),
            child: Icon(methodIcon, color: methodColor, size: 22),
          ),
          const SizedBox(width: 10),
          Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Row(children: [
              Text('${sale['liters']} لتر',
                  style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14)),
              const SizedBox(width: 6),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 1),
                decoration: BoxDecoration(
                  color: methodColor.withValues(alpha: 0.15),
                  borderRadius: BorderRadius.circular(4),
                ),
                child: Text(methodLabel,
                    style: TextStyle(color: methodColor, fontSize: 10, fontWeight: FontWeight.bold)),
              ),
            ]),
            const SizedBox(height: 2),
            if (createdAt != null)
              Text(DateFormat('MM-dd HH:mm').format(createdAt),
                  style: TextStyle(color: Colors.grey.shade600, fontSize: 11)),
            if (sale['vehicle_plate'] != null)
              Text('سيارة: ${sale['vehicle_plate']}',
                  style: TextStyle(color: Colors.grey.shade600, fontSize: 11)),
          ])),
          Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
            Text('${sale['total_amount']}',
                style: const TextStyle(fontSize: 16, fontWeight: FontWeight.bold, color: AmialColors.primary)),
            const Text('ر.ي', style: TextStyle(fontSize: 10, color: Colors.grey)),
          ]),
        ]),
      ),
    );
  }

  void _showSaleDetails(Map<String, dynamic> sale) {
    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
      ),
      builder: (_) => Padding(
        padding: const EdgeInsets.all(16),
        child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Container(
            width: 40, height: 4,
            decoration: BoxDecoration(color: Colors.grey.shade300, borderRadius: BorderRadius.circular(2)),
          ),
          const SizedBox(height: 12),
          const Center(child: Text('تفاصيل البيع',
              style: TextStyle(fontSize: 17, fontWeight: FontWeight.bold))),
          const Divider(),
          _detailRow('رقم العملية', '#${sale['sale_ulid']?.toString().substring((sale['sale_ulid']?.toString().length ?? 8) - 8)}'),
          _detailRow('الكمية', '${sale['liters']} لتر'),
          _detailRow('السعر للّتر', '${sale['price_per_liter']} ر.ي'),
          _detailRow('الإجمالي', '${sale['total_amount']} ر.ي', bold: true),
          _detailRow('طريقة الدفع', _methodLabel(sale['payment_method'])),
          if (sale['vehicle_plate'] != null) _detailRow('السيارة', sale['vehicle_plate']),
          if (sale['driver_name'] != null) _detailRow('السائق', sale['driver_name']),
          if (sale['meter_reading_before'] != null)
            _detailRow('عدّاد قبل/بعد',
                '${sale['meter_reading_before']} → ${sale['meter_reading_after']}'),
          const SizedBox(height: 16),
          Row(children: [
            Expanded(child: OutlinedButton.icon(
              // AMIAL-PDF-DOWNLOAD-001 — **يُنزّل فعلاً.**
              //
              // كان ينسخ نصّاً ويقول «افتح المتصفّح». والمنسوخُ مسارُ API
              // نسبيٌّ خلف المصادقة — فلا هو رابطٌ كامل، ولا المتصفّحُ
              // يملك رمزاً يفتحه به. زرٌّ يَعِد ولا يفي.
              onPressed: () async {
                final ulid = '${sale['sale_ulid'] ?? ''}';
                if (ulid.isEmpty) {
                  Get.snackbar('تعذّر', 'رقم العمليّة غير متاح',
                      backgroundColor: AmialColors.red.withValues(alpha: 0.15));
                  return;
                }

                Get.snackbar('جارٍ التنزيل…', 'يُجلب الإيصال من الخادم',
                    duration: const Duration(seconds: 2),
                    backgroundColor: AmialColors.yellow.withValues(alpha: 0.3));

                String? failure;
                final path = await Get.find<ApiClient>().downloadFile(
                  c.receiptUrl(ulid),
                  fileName: 'amial_receipt_$ulid.pdf',
                  onError: (r) => failure = r,
                );

                if (path == null) {
                  Get.snackbar('تعذّر التنزيل', failure ?? 'سببٌ غير معروف',
                      backgroundColor: AmialColors.red.withValues(alpha: 0.15));
                  return;
                }

                // **ويُفتح بما في الجهاز.** فملفٌّ يُحفظ ولا يُفتح كأنّه
                // لم يُنزَّل: لا يعرف الكاشيرُ أين ذهب.
                final opened = await OpenFile.open(path, type: 'application/pdf');

                if (opened.type != ResultType.done) {
                  // ولا يبقى بلا مخرج: تُعرض المشاركة فيرسله واتساب أو يحفظه.
                  await Share.shareXFiles(
                    [XFile(path, mimeType: 'application/pdf')],
                    text: 'إيصال أميال باي',
                  );
                }
              },
              icon: const Icon(Icons.picture_as_pdf, color: AmialColors.red),
              label: const Text('تنزيل إيصال PDF'),
              style: OutlinedButton.styleFrom(side: const BorderSide(color: AmialColors.red), foregroundColor: AmialColors.red),
            )),
          ]),
          const SizedBox(height: 8),
        ]),
      ),
    );
  }

  Widget _detailRow(String label, dynamic value, {bool bold = false}) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 6),
      child: Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
        Text('$value', style: TextStyle(
          fontWeight: bold ? FontWeight.bold : FontWeight.w500,
          color: bold ? AmialColors.primary : Colors.black87,
          fontSize: bold ? 16 : 14,
        )),
        Text(label, style: TextStyle(color: Colors.grey.shade600, fontSize: 13)),
      ]),
    );
  }

  String _methodLabel(dynamic method) => switch (method?.toString()) {
    'cash' => '💵 نقدي',
    'amial_pay' => '📱 أميال باي',
    'company_card' => '🏢 بطاقة شركة',
    _ => '$method',
  };
}
