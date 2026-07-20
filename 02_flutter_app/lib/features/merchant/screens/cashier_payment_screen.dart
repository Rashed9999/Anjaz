import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/data/api/api_client.dart';
import 'package:amyal_pay/features/access/widgets/access_gate.dart';
import 'package:amyal_pay/features/merchant/controllers/cashier_controller.dart';
import 'package:amyal_pay/features/merchant/screens/cashier_receipt_screen.dart';
import 'package:amyal_pay/features/payments/screens/amial_qr_collect_screen.dart';
import 'package:amyal_pay/helper/amial_money.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';

/// AMIAL-POS-002 — «تأكيد الدفع» (التصميم 37):
/// بطاقة الإجمالي المطلوب سداده + اختيار وسيلة واحدة:
/// نقدي / أميال باي (موصى به) / آجل (قيد على حساب العميل) / مختلط (قريباً)
/// ثم «تأكيد ومعالجة الدفع».
class CashierPaymentScreen extends StatefulWidget {
  const CashierPaymentScreen({
    super.key,
    required this.total,
    this.freeAmount = false,
  });

  final double total;

  /// بيع بمبلغ حرّ (بلا أسطر سلة).
  final bool freeAmount;

  @override
  State<CashierPaymentScreen> createState() => _CashierPaymentScreenState();
}

class _CashierPaymentScreenState extends State<CashierPaymentScreen> {
  CashierController get c => Get.find<CashierController>();
  String _method = 'amial_pay';
  bool _busy = false;

  // AMIAL-PROMOTIONS-001 — خصم مطبَّق (عرض تلقائي أو كوبون)
  double _discount = 0;
  int? _promotionId;
  String? _promoLabel;

  double get _net => (widget.total - _discount).clamp(0, widget.total).toDouble();

  /// يقيّم خصماً على الفاتورة (تلقائي أو بكوبون) ويُطبّقه.
  Future<void> _applyDiscount() async {
    final codeCtrl = TextEditingController();
    final go = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('خصم / كوبون'),
        content: TextField(
          controller: codeCtrl,
          textDirection: TextDirection.ltr,
          decoration: const InputDecoration(
            labelText: 'رمز الكوبون (اتركه فارغاً للعرض التلقائي)',
            border: OutlineInputBorder(),
          ),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('إلغاء')),
          FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('تطبيق')),
        ],
      ),
    );
    if (go != true || !mounted) return;
    setState(() => _busy = true);
    try {
      final api = Get.find<ApiClient>();
      final r = await api.postData('/api/v1/amial/merchant/promotions/apply', {
        'subtotal': widget.total,
        if (codeCtrl.text.trim().isNotEmpty) 'code': codeCtrl.text.trim(),
      });
      if (r.statusCode == 402) { _snack('العروض متاحة في باقة ستارتر فأعلى'); return; }
      if (r.statusCode == 200 && r.body is Map) {
        final meta = (r.body['meta'] ?? {}) as Map;
        final disc = double.tryParse('${meta['discount'] ?? 0}') ?? 0;
        if (disc <= 0) { _snack('لا يوجد خصم منطبق'); return; }
        setState(() {
          _discount = disc;
          _promotionId = meta['promotion_id'] is int ? meta['promotion_id'] as int : int.tryParse('${meta['promotion_id']}');
          _promoLabel = meta['label']?.toString();
        });
        _snack('طُبّق خصم ${disc.toStringAsFixed(0)} ر.ي', ok: true);
      } else {
        _snack((r.body is Map ? r.body['message']?.toString() : null) ?? 'تعذّر تطبيق الخصم');
      }
    } catch (_) {
      _snack('خطأ في الشبكة');
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  void _clearDiscount() => setState(() { _discount = 0; _promotionId = null; _promoLabel = null; });

  Future<void> _confirm() async {
    switch (_method) {
      case 'cash':
        await _recordAndShowReceipt('cash');
      case 'amial_pay':
        await _amialPay();
      case 'credit':
        await _credit();
      case 'corporate':
        await _corporate();
      case 'mixed':
        await _mixed();
    }
  }

  /// مختلط: التاجر يحدّد الجزء المحفظي، والباقي نقد. يُحصَّل المحفظي عبر QR ثم
  /// يُسجَّل البيع بالتقسيم كاملاً (نقد + محفظة = الإجمالي بعد الخصم).
  Future<void> _mixed() async {
    final walletCtrl = TextEditingController(text: (_net / 2).toStringAsFixed(0));
    final go = await showDialog<bool>(
      context: context,
      builder: (ctx) => StatefulBuilder(builder: (ctx, setLocal) {
        final wallet = double.tryParse(walletCtrl.text.trim()) ?? 0;
        final cash = (_net - wallet).clamp(0, _net).toDouble();
        return AlertDialog(
          title: const Text('دفع مختلط'),
          content: Column(mainAxisSize: MainAxisSize.min, children: [
            Text('الإجمالي: ${AmialMoney.yer(_net)}',
                style: const TextStyle(fontWeight: FontWeight.bold)),
            const SizedBox(height: 12),
            TextField(
              controller: walletCtrl,
              keyboardType: TextInputType.number,
              textAlign: TextAlign.center,
              onChanged: (_) => setLocal(() {}),
              decoration: const InputDecoration(
                labelText: 'الجزء المدفوع محفظةً (أميال باي)',
                suffixText: 'ر.ي', border: OutlineInputBorder(),
              ),
            ),
            const SizedBox(height: 10),
            Container(
              padding: const EdgeInsets.all(10),
              decoration: BoxDecoration(color: AmyalColors.background, borderRadius: BorderRadius.circular(8)),
              child: Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
                Text('نقداً: ${AmialMoney.yer(cash)}',
                    style: const TextStyle(fontWeight: FontWeight.bold, color: Color(0xFF2E7D32))),
                Text('محفظةً: ${AmialMoney.yer(wallet)}',
                    style: const TextStyle(fontWeight: FontWeight.bold, color: AmyalColors.primary)),
              ]),
            ),
          ]),
          actions: [
            TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('إلغاء')),
            FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('متابعة')),
          ],
        );
      }),
    );
    if (go != true || !mounted) return;

    final wallet = (double.tryParse(walletCtrl.text.trim()) ?? 0).clamp(0, _net).toDouble();
    final cash = (_net - wallet).clamp(0, _net).toDouble();

    // كله نقد → لا حاجة لـ QR
    if (wallet <= 0) {
      final sale = await c.recordSale(
        total: _net, method: 'mixed', cashAmount: cash, walletAmount: 0,
        discountAmount: _discount, promotionId: _promotionId,
      );
      if (!mounted) return;
      if (sale == null) { if (c.lastError.value.isNotEmpty) _snack(c.lastError.value); return; }
      Get.off(() => CashierReceiptScreen(sale: sale, total: _net, method: 'mixed'));
      return;
    }

    // حصّل الجزء المحفظي عبر QR ثم سجّل البيع المختلط كاملاً
    Get.to(() => AmialQrCollectScreen(
          amount: wallet,
          note: 'دفع مختلط — جزء محفظة',
          onPaid: (paidTxId) async {
            final sale = await c.recordSale(
              total: _net, method: 'mixed', paidTransactionId: paidTxId,
              cashAmount: cash, walletAmount: wallet,
              discountAmount: _discount, promotionId: _promotionId,
            );
            if (sale == null) return false;
            Get.off(() => CashierReceiptScreen(sale: sale, total: _net, method: 'mixed'));
            return true;
          },
        ));
  }

  /// حساب شركة: يختار التاجر شركة (وعضواً اختيارياً) فيُقيَّد البيع على دَينها.
  Future<void> _corporate() async {
    setState(() => _busy = true);
    final api = Get.find<ApiClient>();
    Map<String, dynamic>? picked;
    try {
      final r = await api.getData('/api/v1/amial/merchant/corporate/accounts');
      if (r.statusCode == 402) { _snack('حسابات الشركات متاحة في الباقة المؤسسية'); return; }
      final list = (r.statusCode == 200 && r.body is Map)
          ? (((r.body['meta'] ?? {})['accounts'] ?? []) as List)
              .map((e) => Map<String, dynamic>.from(e as Map)).toList()
          : <Map<String, dynamic>>[];
      if (list.isEmpty) { _snack('لا توجد شركات — أضِفها من «حسابات الشركات»'); return; }
      if (!mounted) return;
      picked = await showModalBottomSheet<Map<String, dynamic>>(
        context: context,
        builder: (ctx) => SafeArea(child: ListView(shrinkWrap: true, children: [
          const Padding(padding: EdgeInsets.all(14),
              child: Text('اختر الشركة', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 15))),
          ...list.map((a) => ListTile(
                leading: const Icon(Icons.business, color: AmyalColors.primary),
                title: Text('${a['company_name']}'),
                subtitle: Text('المتاح: ${a['available']} ر.ي'),
                onTap: () => Navigator.pop(ctx, a),
              )),
        ])),
      );
    } catch (_) {
      _snack('خطأ في الشبكة');
    } finally {
      if (mounted) setState(() => _busy = false);
    }
    if (picked == null || !mounted) return;

    setState(() => _busy = true);
    final sale = await c.recordSale(
      total: _net,
      method: 'corporate',
      corporateAccountId: picked['id'] as int,
      discountAmount: _discount,
      promotionId: _promotionId,
    );
    if (!mounted) return;
    setState(() => _busy = false);
    if (sale == null) {
      if (c.lastError.value.isNotEmpty) _snack(c.lastError.value);
      return;
    }
    Get.off(() => CashierReceiptScreen(
          sale: sale,
          total: _net,
          method: 'corporate',
          customerName: '${picked!['company_name']}',
        ));
  }

  Future<void> _recordAndShowReceipt(String method,
      {Map<String, String>? customer, String? creditDueDate}) async {
    setState(() => _busy = true);
    final sale = await c.recordSale(
      total: _net,
      method: method,
      customer: customer,
      creditDueDate: creditDueDate,
      discountAmount: _discount,
      promotionId: _promotionId,
    );
    if (!mounted) return;
    setState(() => _busy = false);
    if (sale == null) {
      if (c.lastError.value.isNotEmpty) _snack(c.lastError.value);
      return;
    }
    Get.off(() => CashierReceiptScreen(
          sale: sale,
          total: _net,
          method: method,
          customerName: customer?['name'],
        ));
  }

  /// أميال باي: يعرض QR بمبلغ ثابت يمسحه العميل ويدفع من محفظته، ثم يُسجَّل
  /// البيع مربوطاً بمرجع الدفع وتُفتح الفاتورة تلقائياً (استقبال حقيقي).
  Future<void> _amialPay() async {
    Get.to(() => AmialQrCollectScreen(
          amount: _net,
          note: 'دفع مشتريات',
          onPaid: (paidTxId) async {
            final sale = await c.recordSale(
              total: _net,
              method: 'amial_pay',
              paidTransactionId: paidTxId,
              discountAmount: _discount,
              promotionId: _promotionId,
            );
            if (sale == null) return false;
            Get.off(() => CashierReceiptScreen(
                  sale: sale,
                  total: _net,
                  method: 'amial_pay',
                ));
            return true;
          },
        ));
  }

  /// آجل: بيانات العميل (اسم + رقم) وتاريخ استحقاق اختياري.
  Future<void> _credit() async {
    final name = TextEditingController();
    final phone = TextEditingController();
    DateTime? due;
    final ok = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.white,
      shape: const RoundedRectangleBorder(
          borderRadius: BorderRadius.vertical(top: Radius.circular(24))),
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setLocal) => Padding(
          padding: EdgeInsets.fromLTRB(
              20, 16, 20, 20 + MediaQuery.of(ctx).viewInsets.bottom),
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            Container(
              width: 44,
              height: 4,
              decoration: BoxDecoration(
                  color: Colors.grey.shade300,
                  borderRadius: BorderRadius.circular(2)),
            ),
            const SizedBox(height: 16),
            const Text('بيع آجل — بيانات العميل',
                style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold)),
            const SizedBox(height: 6),
            Text('سيُقيَّد ${AmialMoney.yer(widget.total)} على حساب العميل في دفتر الديون',
                style: const TextStyle(
                    fontSize: 12, color: AmyalColors.textSecondary)),
            const SizedBox(height: 16),
            TextField(
              controller: name,
              textAlign: TextAlign.right,
              decoration: const InputDecoration(
                  labelText: 'اسم العميل *', border: OutlineInputBorder()),
            ),
            const SizedBox(height: 10),
            TextField(
              controller: phone,
              keyboardType: TextInputType.phone,
              textAlign: TextAlign.right,
              decoration: const InputDecoration(
                  labelText: 'رقم العميل *',
                  hintText: '77XXXXXXX',
                  border: OutlineInputBorder()),
            ),
            const SizedBox(height: 10),
            OutlinedButton.icon(
              icon: const Icon(Icons.calendar_month_outlined, size: 18),
              label: Text(due == null
                  ? 'تاريخ الاستحقاق (اختياري)'
                  : 'الاستحقاق: ${due!.year}/${due!.month}/${due!.day}'),
              style: OutlinedButton.styleFrom(
                  minimumSize: const Size.fromHeight(46)),
              onPressed: () async {
                final d = await showDatePicker(
                  context: ctx,
                  initialDate: DateTime.now().add(const Duration(days: 30)),
                  firstDate: DateTime.now(),
                  lastDate: DateTime.now().add(const Duration(days: 365)),
                );
                if (d != null) setLocal(() => due = d);
              },
            ),
            const SizedBox(height: 16),
            FilledButton(
              onPressed: () {
                if (name.text.trim().isEmpty || phone.text.trim().isEmpty) {
                  ScaffoldMessenger.of(ctx).showSnackBar(const SnackBar(
                      content: Text('اسم العميل ورقمه مطلوبان'),
                      backgroundColor: AmyalColors.red));
                  return;
                }
                Navigator.pop(ctx, true);
              },
              style: FilledButton.styleFrom(
                  backgroundColor: AmyalColors.primary,
                  minimumSize: const Size.fromHeight(52)),
              child: const Text('تسجيل البيع الآجل'),
            ),
          ]),
        ),
      ),
    );
    if (ok != true || !mounted) return;
    await _recordAndShowReceipt(
      'credit',
      customer: {'name': name.text.trim(), 'phone': phone.text.trim()},
      creditDueDate: due == null
          ? null
          : '${due!.year}-${due!.month.toString().padLeft(2, '0')}-${due!.day.toString().padLeft(2, '0')}',
    );
  }

  void _snack(String m, {bool ok = false}) => ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(m), backgroundColor: ok ? const Color(0xFF2E7D32) : AmyalColors.red),
      );

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmyalColors.background,
      appBar: AppBar(
        backgroundColor: AmyalColors.primary,
        foregroundColor: Colors.white,
        title: const Text('تأكيد الدفع'),
      ),
      body: ListView(
        padding: const EdgeInsets.all(20),
        children: [
          // ====== الإجمالي ======
          Container(
            padding: const EdgeInsets.all(22),
            decoration: BoxDecoration(
              gradient: LinearGradient(
                colors: [
                  AmyalColors.yellow.withValues(alpha: 0.25),
                  Colors.white,
                ],
                begin: Alignment.topCenter,
                end: Alignment.bottomCenter,
              ),
              borderRadius: BorderRadius.circular(18),
              border:
                  Border.all(color: AmyalColors.yellow.withValues(alpha: 0.6)),
            ),
            child: Column(children: [
              const Text('إجمالي المطلوب سداده',
                  style: TextStyle(
                      fontSize: 13, color: AmyalColors.textSecondary)),
              const SizedBox(height: 8),
              if (_discount > 0)
                Text(AmialMoney.yer(widget.total),
                    style: const TextStyle(
                        fontSize: 16,
                        color: AmyalColors.textMuted,
                        decoration: TextDecoration.lineThrough)),
              Text(AmialMoney.yer(_net),
                  style: const TextStyle(
                      fontSize: 34,
                      fontWeight: FontWeight.bold,
                      color: AmyalColors.primary)),
              if (_discount > 0)
                Padding(
                  padding: const EdgeInsets.only(top: 6),
                  child: Text('خصم ${AmialMoney.yer(_discount)}${_promoLabel != null ? ' • $_promoLabel' : ''}',
                      style: const TextStyle(
                          fontSize: 12, fontWeight: FontWeight.bold, color: Color(0xFF2E7D32))),
                ),
            ]),
          ),
          const SizedBox(height: 12),

          // ====== خصم / كوبون (باقة ستارتر فأعلى) ======
          AccessGate(feature: 'promotions', child: Align(
            alignment: Alignment.centerRight,
            child: _discount > 0
                ? TextButton.icon(
                    onPressed: _busy ? null : _clearDiscount,
                    icon: const Icon(Icons.close, size: 18, color: AmyalColors.red),
                    label: const Text('إزالة الخصم', style: TextStyle(color: AmyalColors.red)),
                  )
                : OutlinedButton.icon(
                    onPressed: _busy ? null : _applyDiscount,
                    icon: const Icon(Icons.local_offer_outlined, size: 18),
                    label: const Text('تطبيق خصم / كوبون'),
                    style: OutlinedButton.styleFrom(
                        foregroundColor: AmyalColors.primary,
                        side: const BorderSide(color: AmyalColors.primary)),
                  ),
          )),
          const SizedBox(height: 10),

          Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: const [
            Text('الرجاء تحديد خيار واحد',
                style: TextStyle(fontSize: 11, color: AmyalColors.textMuted)),
            Text('اختر وسيلة الدفع',
                style: TextStyle(fontSize: 15, fontWeight: FontWeight.bold)),
          ]),
          const SizedBox(height: 12),

          _methodCard(
            value: 'cash',
            icon: Icons.payments_outlined,
            title: 'نقدي',
            subtitle: 'الدفع بالعملة المحلية يدوياً',
          ),
          _methodCard(
            value: 'amial_pay',
            icon: Icons.qr_code_2,
            title: 'أميال باي',
            subtitle: 'العميل يدفع من تطبيقه عبر QR فوراً',
            recommended: true,
          ),
          _methodCard(
            value: 'credit',
            icon: Icons.calendar_today_outlined,
            title: 'آجل',
            subtitle: 'قيد العملية على حساب العميل',
          ),
          _methodCard(
            value: 'mixed',
            icon: Icons.call_split,
            title: 'مختلط',
            subtitle: 'جزء نقداً وجزء من محفظة أميال باي',
          ),
          AccessGate(feature: 'corporate_accounts', child: _methodCard(
            value: 'corporate',
            icon: Icons.business_center_outlined,
            title: 'حساب شركة',
            subtitle: 'قيد العملية على حساب شركة (ضمن حدّ الائتمان)',
          )),
          const SizedBox(height: 20),

          FilledButton.icon(
            onPressed: _busy ? null : _confirm,
            icon: _busy
                ? const SizedBox(
                    height: 18,
                    width: 18,
                    child: CircularProgressIndicator(
                        strokeWidth: 2, color: Colors.white))
                : const Icon(Icons.keyboard_double_arrow_left),
            label: const Text('تأكيد ومعالجة الدفع',
                style: TextStyle(fontSize: 16, fontWeight: FontWeight.w600)),
            style: FilledButton.styleFrom(
              backgroundColor: AmyalColors.primary,
              minimumSize: const Size.fromHeight(56),
              shape:
                  RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
            ),
          ),
          const SizedBox(height: 8),
          TextButton(
            onPressed: () => Get.back(),
            child: const Text('إلغاء العملية',
                style: TextStyle(color: AmyalColors.textSecondary)),
          ),
        ],
      ),
    );
  }

  Widget _methodCard({
    required String value,
    required IconData icon,
    required String title,
    required String subtitle,
    bool recommended = false,
    bool disabled = false,
  }) {
    final selected = _method == value;
    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: InkWell(
        onTap: disabled
            ? () => _snack('الدفع المختلط سيتوفّر قريباً')
            : () => setState(() => _method = value),
        borderRadius: BorderRadius.circular(16),
        child: Container(
          padding: const EdgeInsets.all(14),
          decoration: BoxDecoration(
            color: disabled
                ? Colors.white.withValues(alpha: 0.6)
                : selected
                    ? const Color(0xFFEDF3EF)
                    : Colors.white,
            borderRadius: BorderRadius.circular(16),
            border: Border.all(
              color: selected && !disabled
                  ? AmyalColors.primary
                  : AmyalColors.border,
              width: selected && !disabled ? 1.6 : 1,
            ),
          ),
          child: Row(children: [
            if (recommended)
              Container(
                padding:
                    const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                decoration: BoxDecoration(
                  color: AmyalColors.yellow,
                  borderRadius: BorderRadius.circular(8),
                ),
                child: const Text('موصى به',
                    style: TextStyle(
                        fontSize: 9,
                        fontWeight: FontWeight.bold,
                        color: Color(0xFF053391))),
              ),
            const Spacer(),
            Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
              Text(title,
                  style: TextStyle(
                      fontWeight: FontWeight.bold,
                      fontSize: 15,
                      color: disabled ? AmyalColors.textMuted : Colors.black87)),
              Text(subtitle,
                  style: const TextStyle(
                      fontSize: 11, color: AmyalColors.textMuted)),
            ]),
            const SizedBox(width: 12),
            Container(
              height: 46,
              width: 46,
              decoration: BoxDecoration(
                color: selected && !disabled
                    ? AmyalColors.primary
                    : const Color(0xFFE9EEF6),
                borderRadius: BorderRadius.circular(14),
              ),
              child: Icon(icon,
                  size: 22,
                  color: selected && !disabled
                      ? Colors.white
                      : AmyalColors.primary),
            ),
          ]),
        ),
      ),
    );
  }
}
