import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';
import 'package:amyal_pay/features/merchant/controllers/cashier_refund_controller.dart';

/// AMIAL-CASHIER-REFUND-001 — شاشة إنشاء مرتجع.
///
/// تقابل شاشتي 89/90 من التصميم:
///   - رقم الطلب + التاريخ + العميل
///   - الأصناف المشتراة مع كمياتها الأصلية
///   - العميل يختار كميات الإرجاع
///   - إجمالي مبلغ الاسترداد (يُحسب تلقائياً)
///   - طريقة الاسترداد (تتاح حسب نوع البيع الأصلي)
///   - زر التأكيد
class CashierRefundScreen extends StatefulWidget {
  final String saleUlid;
  const CashierRefundScreen({super.key, required this.saleUlid});

  @override
  State<CashierRefundScreen> createState() => _CashierRefundScreenState();
}

class _CashierRefundScreenState extends State<CashierRefundScreen> {
  late final CashierRefundController c;

  // كميات الإرجاع لكل صنف (بـ index)
  final Map<int, double> _returnQty = {};
  String _refundMethod = 'cash';
  final _reasonCtrl = TextEditingController();
  final _manualAmountCtrl = TextEditingController();

  @override
  void initState() {
    super.initState();
    c = Get.find<CashierRefundController>();
    WidgetsBinding.instance.addPostFrameCallback((_) => c.loadRefundable(widget.saleUlid));
  }

  @override
  void dispose() {
    _reasonCtrl.dispose();
    _manualAmountCtrl.dispose();
    super.dispose();
  }

  /// إجمالي محسوب من الأصناف
  double _calculatedTotal(List items) {
    double sum = 0;
    for (var i = 0; i < items.length; i++) {
      final qty = _returnQty[i] ?? 0;
      final price = double.tryParse('${items[i]['price'] ?? 0}') ?? 0;
      sum += qty * price;
    }
    return sum;
  }

  /// المبلغ النهائي للإرسال (الأصناف إن وُجدت، وإلا الإدخال اليدوي)
  String _finalAmount(List items, double remaining) {
    if (items.isNotEmpty) {
      return _calculatedTotal(items).toStringAsFixed(2);
    }
    if (_manualAmountCtrl.text.isNotEmpty) return _manualAmountCtrl.text;
    return remaining.toString();
  }

  Future<void> _submit() async {
    final info = c.refundableInfo.value!;
    final sale = info['sale'] as Map;
    final items = (sale['items'] ?? []) as List;
    final remaining = double.tryParse('${info['remaining'] ?? 0}') ?? 0;

    final amount = _finalAmount(items, remaining);
    final n = double.tryParse(amount) ?? 0;
    if (n <= 0) {
      _snack('حدّد كمية أو مبلغ صحيح');
      return;
    }
    if (n > remaining) {
      _snack('المبلغ يتجاوز المتبقّي (${remaining.toStringAsFixed(2)})');
      return;
    }

    // أصناف الإرجاع
    List<Map<String, dynamic>>? refundItems;
    if (items.isNotEmpty && _returnQty.isNotEmpty) {
      refundItems = [];
      for (var i = 0; i < items.length; i++) {
        final q = _returnQty[i] ?? 0;
        if (q > 0) {
          refundItems.add({
            'name': items[i]['name'],
            'qty': q,
            'price': items[i]['price'],
          });
        }
      }
    }

    final ok = await c.create(
      saleUlid: widget.saleUlid,
      amount: amount,
      refundMethod: _refundMethod,
      items: refundItems,
      reason: _reasonCtrl.text.trim().isEmpty ? null : _reasonCtrl.text.trim(),
    );

    if (!mounted) return;
    if (ok) {
      final status = c.lastRefund.value?['status'];
      Get.back(result: true);
      Get.snackbar(
        status == 'pending_approval' ? 'بانتظار الموافقة' : 'تم الاسترداد',
        status == 'pending_approval'
            ? 'تم إرسال المرتجع للإدارة للموافقة'
            : 'تم تسجيل المرتجع بنجاح',
        backgroundColor: Colors.green.shade100, colorText: Colors.green.shade800,
        snackPosition: SnackPosition.BOTTOM,
      );
    } else {
      _snack(c.lastError.value.isEmpty ? 'فشلت العملية' : c.lastError.value);
    }
  }

  void _snack(String m) => ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(m), backgroundColor: AmyalColors.red),
      );

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmyalColors.background,
      appBar: AppBar(
        title: const Text('مرتجع مبيعات'),
      ),
      body: Obx(() {
        if (c.isLoadingInfo.value) {
          return const Center(child: CircularProgressIndicator());
        }
        final info = c.refundableInfo.value;
        if (info == null) {
          return Center(child: Padding(
            padding: const EdgeInsets.all(24),
            child: Text(c.lastError.value.isEmpty ? 'فشل تحميل العملية' : c.lastError.value,
                style: const TextStyle(fontSize: 16), textAlign: TextAlign.center),
          ));
        }

        final sale = info['sale'] as Map;
        final remaining = double.tryParse('${info['remaining'] ?? 0}') ?? 0;
        final refundedSoFar = '${info['refunded_so_far'] ?? '0'}';
        final fullyRefunded = info['fully_refunded'] == true;
        final available = ((info['available_methods'] ?? []) as List).cast<String>();
        final items = (sale['items'] ?? []) as List;

        if (fullyRefunded) {
          return Center(
            child: Column(mainAxisAlignment: MainAxisAlignment.center, children: [
              Icon(Icons.check_circle, color: Colors.green, size: 64),
              const SizedBox(height: 12),
              const Text('استُردّ كامل المبلغ',
                  style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold)),
              const SizedBox(height: 4),
              Text('إجمالي مُسترد: $refundedSoFar ر.ي',
                  style: TextStyle(color: Colors.grey.shade600)),
            ]),
          );
        }

        return SingleChildScrollView(
          padding: const EdgeInsets.all(16),
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            // ====== بطاقة معلومات العملية ======
            Container(
              padding: const EdgeInsets.all(16),
              decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(12)),
              child: Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
                Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                    decoration: BoxDecoration(
                      color: AmyalColors.yellow.withValues(alpha: 0.3),
                      borderRadius: BorderRadius.circular(8),
                    ),
                    child: const Text('قابل للمرتجع',
                        style: TextStyle(color: AmyalColors.yellowDark, fontWeight: FontWeight.bold, fontSize: 11)),
                  ),
                  Text('#${sale['sale_ulid'].toString().substring(sale['sale_ulid'].toString().length - 8)}',
                      style: const TextStyle(fontSize: 16, fontWeight: FontWeight.bold)),
                ]),
                const Divider(height: 20),
                _infoRow('العميل', sale['customer_name'] ?? 'عميل عابر'),
                if (sale['customer_phone'] != null) _infoRow('الهاتف', sale['customer_phone']),
                _infoRow('الإجمالي', '${sale['total_amount']} ر.ي'),
                _infoRow('مُسترد سابقاً', '$refundedSoFar ر.ي', red: refundedSoFar != '0.0000' && refundedSoFar != '0'),
                _infoRow('المتبقّي للاسترداد', '${remaining.toStringAsFixed(2)} ر.ي', bold: true),
              ]),
            ),

            const SizedBox(height: 20),

            // ====== الأصناف (إن وُجدت) ======
            if (items.isNotEmpty) ...[
              const Text('الأصناف المشتراة', textAlign: TextAlign.right,
                  style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold)),
              const SizedBox(height: 8),
              ...List.generate(items.length, (i) => _itemTile(items[i] as Map, i)),
              const SizedBox(height: 8),
            ] else ...[
              const Text('مبلغ الاسترداد', textAlign: TextAlign.right,
                  style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold)),
              const SizedBox(height: 8),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
                decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(12)),
                child: TextField(
                  controller: _manualAmountCtrl,
                  keyboardType: TextInputType.number,
                  textAlign: TextAlign.right,
                  inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                  style: const TextStyle(fontSize: 22, fontWeight: FontWeight.bold, color: AmyalColors.primary),
                  decoration: InputDecoration(
                    border: InputBorder.none,
                    hintText: 'الحد الأقصى ${remaining.toStringAsFixed(0)} ر.ي',
                    suffix: const Text('ر.ي'),
                  ),
                  onChanged: (_) => setState(() {}),
                ),
              ),
            ],

            const SizedBox(height: 20),

            // ====== إجمالي الاسترداد ======
            Container(
              padding: const EdgeInsets.all(14),
              decoration: BoxDecoration(
                color: AmyalColors.primary,
                borderRadius: BorderRadius.circular(12),
              ),
              child: Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
                Text('${_finalAmount(items, remaining)} ر.ي',
                    style: const TextStyle(color: Colors.white, fontSize: 22, fontWeight: FontWeight.bold)),
                const Text('إجمالي الاسترداد', style: TextStyle(color: Colors.white70, fontSize: 13)),
              ]),
            ),

            const SizedBox(height: 20),

            // ====== طريقة الاسترداد ======
            const Text('طريقة الاسترداد', textAlign: TextAlign.right,
                style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold)),
            const SizedBox(height: 8),
            ...available.map((m) => _methodTile(m)),

            const SizedBox(height: 16),

            // ====== سبب الإرجاع ======
            TextField(
              controller: _reasonCtrl,
              textAlign: TextAlign.right,
              maxLines: 2,
              decoration: InputDecoration(
                labelText: 'سبب الإرجاع (اختياري)',
                filled: true,
                fillColor: Colors.white,
                border: OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: BorderSide.none),
              ),
            ),

            const SizedBox(height: 20),

            // ====== زر التأكيد ======
            Obx(() => FilledButton.icon(
              onPressed: c.isSubmitting.value ? null : _submit,
              icon: c.isSubmitting.value
                  ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                  : const Icon(Icons.check_circle_outline),
              label: const Text('تأكيد عملية المرتجع', style: TextStyle(fontSize: 16)),
              style: FilledButton.styleFrom(
                backgroundColor: AmyalColors.primary,
                minimumSize: const Size.fromHeight(54),
              ),
            )),

            const SizedBox(height: 12),

            // ملاحظة عن الـ approval
            Container(
              padding: const EdgeInsets.all(10),
              decoration: BoxDecoration(
                color: Colors.grey.shade100,
                borderRadius: BorderRadius.circular(8),
              ),
              child: Row(children: [
                Icon(Icons.info_outline, size: 18, color: Colors.grey.shade600),
                const SizedBox(width: 8),
                Expanded(
                  child: Text(
                    'المرتجعات أكثر من 5,000 ر.ي تحتاج موافقة الإدارة',
                    style: TextStyle(fontSize: 11, color: Colors.grey.shade700),
                  ),
                ),
              ]),
            ),
          ]),
        );
      }),
    );
  }

  Widget _infoRow(String label, dynamic value, {bool bold = false, bool red = false}) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 4),
      child: Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
        Text('$value', style: TextStyle(
          fontWeight: bold ? FontWeight.bold : FontWeight.w500,
          color: red ? AmyalColors.red : Colors.black87,
        )),
        Text(label, style: TextStyle(color: Colors.grey.shade600, fontSize: 13)),
      ]),
    );
  }

  Widget _itemTile(Map item, int index) {
    final originalQty = double.tryParse('${item['qty'] ?? 0}') ?? 0;
    final price = double.tryParse('${item['price'] ?? 0}') ?? 0;
    final current = _returnQty[index] ?? 0;

    return Container(
      margin: const EdgeInsets.only(bottom: 8),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(10)),
      child: Row(children: [
        // عداد كمية الإرجاع
        Row(children: [
          IconButton(
            icon: const Icon(Icons.remove_circle_outline),
            onPressed: current > 0 ? () => setState(() => _returnQty[index] = current - 1) : null,
          ),
          Container(
            width: 36,
            alignment: Alignment.center,
            child: Text('${current.toInt()}',
                style: const TextStyle(fontSize: 18, fontWeight: FontWeight.bold)),
          ),
          IconButton(
            icon: const Icon(Icons.add_circle, color: AmyalColors.primary),
            onPressed: current < originalQty ? () => setState(() => _returnQty[index] = current + 1) : null,
          ),
        ]),
        const Spacer(),
        // معلومات الصنف
        Expanded(
          child: Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
            Text('${item['name']}', style: const TextStyle(fontSize: 14, fontWeight: FontWeight.bold)),
            const SizedBox(height: 2),
            Text('${price.toStringAsFixed(0)} ر.ي / قطعة',
                style: TextStyle(color: Colors.grey.shade600, fontSize: 12)),
            Text('الحد الأقصى: ${originalQty.toInt()}',
                style: TextStyle(color: Colors.grey.shade500, fontSize: 11)),
          ]),
        ),
      ]),
    );
  }

  Widget _methodTile(String method) {
    final label = method == 'cash' ? 'نقدي'
        : method == 'wallet' ? 'إلى محفظة العميل'
        : 'خصم من دَيْن العميل';
    final desc = method == 'cash' ? 'سلّم النقد للعميل يدوياً'
        : method == 'wallet' ? 'ائتمان فوري لمحفظة العميل في أميال باي'
        : 'تخفيض ما عليه من دَيْن لك';
    final icon = method == 'cash' ? Icons.payments
        : method == 'wallet' ? Icons.account_balance_wallet
        : Icons.receipt_long;

    final selected = _refundMethod == method;
    return InkWell(
      borderRadius: BorderRadius.circular(12),
      onTap: () => setState(() => _refundMethod = method),
      child: Container(
        margin: const EdgeInsets.only(bottom: 8),
        padding: const EdgeInsets.all(14),
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(12),
          border: Border.all(color: selected ? AmyalColors.primary : Colors.grey.shade200, width: selected ? 2 : 1),
        ),
        child: Row(children: [
          Icon(selected ? Icons.radio_button_checked : Icons.radio_button_unchecked,
              color: selected ? AmyalColors.primary : Colors.grey),
          const SizedBox(width: 12),
          Icon(icon, color: selected ? AmyalColors.primary : Colors.grey.shade600),
          const SizedBox(width: 12),
          Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
            Text(label, style: const TextStyle(fontSize: 15, fontWeight: FontWeight.bold)),
            const SizedBox(height: 2),
            Text(desc, style: TextStyle(fontSize: 11, color: Colors.grey.shade600), textAlign: TextAlign.right),
          ])),
        ]),
      ),
    );
  }
}
