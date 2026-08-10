import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amial_pay/features/merchant/controllers/cashier_controller.dart';
import 'package:amial_pay/theme/amial_colors.dart';

/// AMIAL-RETAIL-VERTICAL-001 · المرحلة ١ — **تفصيلُ بيعةٍ سطراً سطراً**.
///
/// ══════════════════════════════════════════════════════════════════════
/// كانت أسطرُ البيعة نصّاً مُسلسَلاً لا يُقرأ من أيّ شاشة: يُرى الإجماليّ
/// وحدَه، ولا يُعرف ما بيع ولا بكم ولا بأيّ ربح. **وصارت جدولاً** — فهذه
/// الشاشةُ هي بابُه (القاعدة ١٢: ما لا يُوصل إليه ليس مبنيّاً).
///
/// **وتكلفةٌ غيرُ معروفةٍ تُكتب «غير معروفة» ولا تُعرض صفراً** (القاعدة ٧):
/// صفرٌ هنا يُقرأ ربحاً كاملاً على بضاعةٍ لم يُعرف ثمنُ شرائها.
class CashierSaleDetailScreen extends StatefulWidget {
  final String saleUlid;
  const CashierSaleDetailScreen({super.key, required this.saleUlid});

  @override
  State<CashierSaleDetailScreen> createState() => _CashierSaleDetailScreenState();
}

class _CashierSaleDetailScreenState extends State<CashierSaleDetailScreen> {
  CashierController get c => Get.find<CashierController>();

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => c.loadSaleDetail(widget.saleUlid));
  }

  String _n(dynamic v) {
    final d = double.tryParse((v ?? '0').toString()) ?? 0;
    return d
        .toStringAsFixed(d.truncateToDouble() == d ? 0 : 2)
        .replaceAllMapped(RegExp(r'\B(?=(\d{3})+(?!\d))'), (m) => ',');
  }

  String _qty(dynamic v) {
    final d = double.tryParse((v ?? '0').toString()) ?? 0;
    return d.truncateToDouble() == d ? d.toStringAsFixed(0) : d.toStringAsFixed(3);
  }

  String _methodLabel(String? m) => switch (m) {
        'cash' => 'نقد',
        'credit' => 'أجل',
        'amial_pay' => 'أميال باي',
        'corporate' => 'حساب شركة',
        'mixed' => 'مختلط',
        _ => m ?? '—',
      };

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(
        title: const Text('تفاصيل العملية'),
        backgroundColor: AmialColors.background,
        elevation: 0,
      ),
      body: Obx(() {
        // ① جارٍ التحميل
        if (c.isLoadingSaleDetail.value) {
          return const Center(child: CircularProgressIndicator());
        }

        // ② عطلٌ — **بسببه وبزرّ إعادة**، لا شاشةٌ بيضاء
        if (c.saleDetailError.isNotEmpty) {
          return _message(
            icon: Icons.wifi_off_rounded,
            color: AmialColors.red,
            title: c.saleDetailError.value,
            action: TextButton.icon(
              onPressed: () => c.loadSaleDetail(widget.saleUlid),
              icon: const Icon(Icons.refresh_rounded, size: 18),
              label: const Text('إعادة المحاولة'),
            ),
          );
        }

        final d = c.saleDetail.value;
        if (d == null) {
          return _message(
            icon: Icons.receipt_long_outlined,
            color: AmialColors.textSecondary,
            title: 'لا توجد بيانات لهذه العملية',
          );
        }

        final sale = Map<String, dynamic>.from((d['sale'] ?? {}) as Map);
        final totals = Map<String, dynamic>.from((d['totals'] ?? {}) as Map);
        final lines = ((d['lines'] ?? []) as List)
            .map((e) => Map<String, dynamic>.from(e as Map))
            .toList();

        return ListView(
          padding: const EdgeInsets.all(16),
          children: [
            _header(sale),
            const SizedBox(height: 16),
            _totals(totals),
            const SizedBox(height: 20),
            const Text('الأصناف',
                style: TextStyle(fontWeight: FontWeight.bold, fontSize: 15)),
            const SizedBox(height: 8),

            // ③ بيعةٌ بمبلغٍ حرٍّ بلا أصناف — **حالةٌ مشروعةٌ تُقال، لا فراغ**
            if (lines.isEmpty)
              const Padding(
                padding: EdgeInsets.symmetric(vertical: 16),
                child: Text(
                  'بيعة بمبلغ حرّ — لم تُسجَّل أصناف لها',
                  style: TextStyle(color: AmialColors.textSecondary, fontSize: 13),
                ),
              )
            else
              ...lines.map(_lineCard),
          ],
        );
      }),
    );
  }

  Widget _message({
    required IconData icon,
    required Color color,
    required String title,
    Widget? action,
  }) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(32),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(icon, size: 44, color: color),
            const SizedBox(height: 12),
            Text(title,
                textAlign: TextAlign.center,
                style: const TextStyle(fontSize: 14, color: AmialColors.textSecondary)),
            if (action != null) ...[const SizedBox(height: 8), action],
          ],
        ),
      ),
    );
  }

  /// آخِرُ ثمانيةٍ من المعرّف — وهو ما يُطبع على الإيصال.
  String _shortRef(dynamic ulid) {
    final s = (ulid ?? '').toString();
    return s.length <= 8 ? s : s.substring(s.length - 8);
  }

  Widget _header(Map<String, dynamic> sale) {
    final created = DateTime.tryParse((sale['created_at'] ?? '').toString())?.toLocal();
    final when = created == null
        ? '—'
        : '${created.year}/${created.month.toString().padLeft(2, '0')}/'
            '${created.day.toString().padLeft(2, '0')} · '
            '${created.hour.toString().padLeft(2, '0')}:'
            '${created.minute.toString().padLeft(2, '0')}';

    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: AmialColors.cardSurface,
        borderRadius: BorderRadius.circular(12),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text('${_n(sale['total_amount'])} ر.ي',
              style: const TextStyle(
                  fontSize: 26, fontWeight: FontWeight.bold, color: AmialColors.primary)),
          const SizedBox(height: 6),
          Text(
            [
              _methodLabel(sale['payment_method']?.toString()),
              when,
              if ((sale['customer_name'] ?? '').toString().isNotEmpty)
                sale['customer_name'].toString(),
            ].join(' · '),
            style: const TextStyle(fontSize: 12, color: AmialColors.textSecondary),
          ),
          const SizedBox(height: 4),
          Text('#${_shortRef(sale['sale_ulid'])}',
              style: const TextStyle(fontSize: 11, color: AmialColors.textSecondary)),
        ],
      ),
    );
  }

  Widget _totals(Map<String, dynamic> t) {
    final unknownLines = (t['unknown_cost_lines'] ?? 0) is int
        ? t['unknown_cost_lines'] as int
        : int.tryParse('${t['unknown_cost_lines']}') ?? 0;

    return Column(
      children: [
        Row(
          children: [
            _stat('الإيراد', '${_n(t['revenue'])} ر.ي', AmialColors.primary),
            _stat('التكلفة', '${_n(t['known_cost'])} ر.ي', AmialColors.textSecondary),
            _stat('الربح', '${_n(t['gross_profit'])} ر.ي', Colors.green),
          ],
        ),
        // **الشفافيّةُ جزءٌ من الرقم**: هامشٌ محسوبٌ على جزءٍ من الإيراد
        // يُعرض كأنّه على كلّه ما لم يُقَل.
        if (unknownLines > 0)
          Container(
            width: double.infinity,
            margin: const EdgeInsets.only(top: 8),
            padding: const EdgeInsets.all(10),
            decoration: BoxDecoration(
              color: AmialColors.yellowDark.withValues(alpha: 0.12),
              borderRadius: BorderRadius.circular(8),
            ),
            child: Text(
              'الربح محسوب على ما عُرفت تكلفته فقط — '
              '$unknownLines سطراً بلا تكلفة مُدخَلة '
              '(${_n(t['unknown_cost_revenue'])} ر.ي)',
              style: const TextStyle(fontSize: 11, color: AmialColors.yellowDark),
            ),
          ),
      ],
    );
  }

  Widget _stat(String label, String value, Color color) {
    return Expanded(
      child: Container(
        margin: const EdgeInsets.all(4),
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(
          color: AmialColors.cardSurface,
          borderRadius: BorderRadius.circular(8),
          border: Border(bottom: BorderSide(color: color, width: 3)),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(label,
                style: const TextStyle(fontSize: 11, color: AmialColors.textSecondary)),
            const SizedBox(height: 6),
            Text(value,
                style: TextStyle(fontSize: 14, fontWeight: FontWeight.bold, color: color)),
          ],
        ),
      ),
    );
  }

  Widget _lineCard(Map<String, dynamic> l) {
    final costKnown = l['line_cost'] != null;
    final returned = double.tryParse((l['returned_quantity'] ?? '0').toString()) ?? 0;

    return Card(
      color: AmialColors.cardSurface,
      margin: const EdgeInsets.only(bottom: 8),
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(
                  child: Text((l['name'] ?? '—').toString(),
                      style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14)),
                ),
                Text('${_n(l['line_total'])} ر.ي',
                    style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14)),
              ],
            ),
            const SizedBox(height: 6),
            Text(
              '${_qty(l['quantity'])} × ${_n(l['unit_price'])} ر.ي'
              '${(double.tryParse((l['line_discount'] ?? '0').toString()) ?? 0) > 0 ? ' − خصم ${_n(l['line_discount'])}' : ''}',
              style: const TextStyle(fontSize: 12, color: AmialColors.textSecondary),
            ),
            const SizedBox(height: 8),
            Row(
              children: [
                Expanded(
                  child: Text(
                    costKnown
                        ? 'التكلفة: ${_n(l['line_cost'])} ر.ي'
                        // **لا صفر** — والفرقُ بين الاثنين قرارُ تسعير.
                        : 'التكلفة: غير معروفة',
                    style: TextStyle(
                      fontSize: 12,
                      color: costKnown ? AmialColors.textSecondary : AmialColors.yellowDark,
                    ),
                  ),
                ),
                if (costKnown)
                  Text('ربح: ${_n(l['line_profit'])} ر.ي',
                      style: const TextStyle(
                          fontSize: 12,
                          fontWeight: FontWeight.bold,
                          color: Colors.green)),
              ],
            ),
            if (returned > 0) ...[
              const SizedBox(height: 6),
              Text('مسترجَع منه: ${_qty(l['returned_quantity'])}',
                  style: const TextStyle(fontSize: 11, color: AmialColors.red)),
            ],
          ],
        ),
      ),
    );
  }
}
