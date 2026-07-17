import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:share_plus/share_plus.dart';
import 'package:amyal_pay/features/merchant/screens/cashier_pos_screen.dart';
import 'package:amyal_pay/helper/amial_money.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';

/// AMIAL-POS-003 — «تم التحصيل» (التصميم 38/85):
/// شارة نجاح + بطاقة الإيصال (المبلغ/الوسيلة/المرجع/العميل/الحالة)
/// + مشاركة رقمياً + «عملية جديدة» (عودة للكاشير).
class CashierReceiptScreen extends StatelessWidget {
  const CashierReceiptScreen({
    super.key,
    required this.sale,
    required this.total,
    required this.method,
    this.customerName,
    this.pendingPayment = false,
  });

  final Map<String, dynamic> sale;
  final double total;
  final String method;
  final String? customerName;

  /// أميال باي: بانتظار دفع العميل عبر QR.
  final bool pendingPayment;

  String get _methodLabel => switch (method) {
        'cash' => 'تحصيل نقدي',
        'credit' => 'بيع آجل',
        'amial_pay' => 'أميال باي (QR)',
        _ => method,
      };

  String get _ref => '${sale['sale_ulid'] ?? sale['id'] ?? ''}';

  Future<void> _share() async {
    await Share.share('''
إيصال بيع — أميال باي
النوع: $_methodLabel
المبلغ: ${AmialMoney.yer(total)}
${customerName != null ? 'العميل: $customerName\n' : ''}المرجع: $_ref
''');
  }

  @override
  Widget build(BuildContext context) {
    final waiting = pendingPayment;
    return Scaffold(
      backgroundColor: AmyalColors.background,
      appBar: AppBar(
        backgroundColor: AmyalColors.primary,
        foregroundColor: Colors.white,
        title: Text(waiting ? 'بانتظار الدفع' : 'تم التحصيل'),
        leading: IconButton(
          icon: const Icon(Icons.close),
          onPressed: () => Get.back(),
        ),
      ),
      body: ListView(
        padding: const EdgeInsets.all(24),
        children: [
          const SizedBox(height: 8),
          // ====== شارة الحالة ======
          Center(
            child: Container(
              height: 104,
              width: 104,
              decoration: BoxDecoration(
                color: waiting ? AmyalColors.yellow : AmyalColors.primary,
                shape: BoxShape.circle,
              ),
              child: Icon(
                  waiting ? Icons.qr_code_2 : Icons.check_rounded,
                  color: waiting ? const Color(0xFF053391) : Colors.white,
                  size: 56),
            ),
          ),
          const SizedBox(height: 18),
          Text(
            waiting ? 'بانتظار دفع العميل' : 'تم التحصيل ($_methodLabel)',
            textAlign: TextAlign.center,
            style: const TextStyle(
                fontSize: 19,
                fontWeight: FontWeight.bold,
                color: AmyalColors.primary),
          ),
          const SizedBox(height: 6),
          Text(
            waiting
                ? 'اطلب من العميل مسح QR متجرك ودفع المبلغ — تُربط العملية بهذا البيع تلقائياً'
                : 'تمت عملية الدفع بنجاح وتسجيلها في النظام',
            textAlign: TextAlign.center,
            style: const TextStyle(
                fontSize: 13, color: AmyalColors.textSecondary, height: 1.6),
          ),
          const SizedBox(height: 24),

          // ====== بطاقة الإيصال ======
          Container(
            padding: const EdgeInsets.all(18),
            decoration: BoxDecoration(
              color: Colors.white,
              borderRadius: BorderRadius.circular(18),
            ),
            child: Column(children: [
              const Text('المبلغ الإجمالي',
                  style: TextStyle(
                      fontSize: 12, color: AmyalColors.textSecondary)),
              const SizedBox(height: 6),
              Text(AmialMoney.yer(total),
                  style: const TextStyle(
                      fontSize: 28,
                      fontWeight: FontWeight.bold,
                      color: AmyalColors.primary)),
              const Divider(height: 28),
              _row('نوع العملية', _methodLabel,
                  chip: true,
                  chipColor: waiting
                      ? const Color(0xFFFBF3D9)
                      : const Color(0xFFE3F3E5),
                  chipFg: waiting
                      ? const Color(0xFFB8860B)
                      : const Color(0xFF2E7D32)),
              if (customerName != null) _row('العميل', customerName!),
              _row('رقم المرجع', _ref, ltr: true),
              _row('التاريخ والوقت', _now()),
              _row('حالة الدفع', waiting ? 'قيد الانتظار' : 'مكتمل',
                  chip: true,
                  chipColor: waiting
                      ? const Color(0xFFFBF3D9)
                      : const Color(0xFFEFEFEF),
                  chipFg: waiting
                      ? const Color(0xFFB8860B)
                      : Colors.black87),
            ]),
          ),
          const SizedBox(height: 26),

          // ====== الأزرار ======
          FilledButton.icon(
            onPressed: () => Get.off(() => const CashierPosScreen()),
            icon: const Icon(Icons.add),
            label: const Text('عملية جديدة',
                style: TextStyle(fontSize: 16, fontWeight: FontWeight.w600)),
            style: FilledButton.styleFrom(
              backgroundColor: AmyalColors.primary,
              minimumSize: const Size.fromHeight(54),
              shape: RoundedRectangleBorder(
                  borderRadius: BorderRadius.circular(16)),
            ),
          ),
          const SizedBox(height: 10),
          OutlinedButton.icon(
            onPressed: _share,
            icon: const Icon(Icons.share, size: 18),
            label: const Text('مشاركة الإيصال رقمياً'),
            style: OutlinedButton.styleFrom(
              foregroundColor: AmyalColors.primary,
              side: const BorderSide(color: AmyalColors.yellowDark),
              minimumSize: const Size.fromHeight(52),
              shape: RoundedRectangleBorder(
                  borderRadius: BorderRadius.circular(16)),
            ),
          ),
        ],
      ),
    );
  }

  String _now() {
    final d = DateTime.now();
    final h12 = d.hour % 12 == 0 ? 12 : d.hour % 12;
    final ampm = d.hour < 12 ? 'ص' : 'م';
    return '${d.year}/${d.month}/${d.day} • $h12:${d.minute.toString().padLeft(2, '0')} $ampm';
  }

  Widget _row(String label, String value,
      {bool chip = false, Color? chipColor, Color? chipFg, bool ltr = false}) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 6),
      child: Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
        chip
            ? Container(
                padding:
                    const EdgeInsets.symmetric(horizontal: 10, vertical: 3),
                decoration: BoxDecoration(
                  color: chipColor,
                  borderRadius: BorderRadius.circular(10),
                ),
                child: Text(value,
                    style: TextStyle(
                        fontSize: 11,
                        fontWeight: FontWeight.w600,
                        color: chipFg)),
              )
            : Flexible(
                child: Text(value,
                    textDirection: ltr ? TextDirection.ltr : null,
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(
                        fontSize: 13, fontWeight: FontWeight.w600)),
              ),
        Text(label,
            style:
                const TextStyle(fontSize: 12, color: AmyalColors.textSecondary)),
      ]),
    );
  }
}
