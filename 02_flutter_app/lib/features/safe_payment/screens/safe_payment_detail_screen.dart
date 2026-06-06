import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/features/safe_payment/controllers/safe_payment_controller.dart';
import 'package:amyal_pay/features/safe_payment/domain/models/safe_payment_models.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';

/// AMIAL-SAFE-PAYMENT-001 (v1.1)
class SafePaymentDetailScreen extends StatefulWidget {
  final String ulid;
  const SafePaymentDetailScreen({super.key, required this.ulid});

  @override
  State<SafePaymentDetailScreen> createState() =>
      _SafePaymentDetailScreenState();
}

class _SafePaymentDetailScreenState extends State<SafePaymentDetailScreen> {
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      Get.find<SafePaymentController>().loadDetail(widget.ulid);
    });
  }

  Future<void> _showReasonDialog({
    required String title,
    required String hint,
    required Future<bool> Function(String reason) onConfirm,
  }) async {
    final ctrl = TextEditingController();
    final formKey = GlobalKey<FormState>();

    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(title),
        content: Form(
          key: formKey,
          child: TextFormField(
            controller: ctrl,
            maxLines: 3,
            maxLength: 500,
            decoration: InputDecoration(
              hintText: hint,
              border: const OutlineInputBorder(),
            ),
            validator: (v) {
              if (v == null || v.trim().length < 5) return 'السبب 5 أحرف على الأقل';
              return null;
            },
          ),
        ),
        actions: [
          TextButton(
              onPressed: () => Navigator.pop(ctx, false),
              child: const Text('إلغاء')),
          ElevatedButton(
            onPressed: () async {
              if (!formKey.currentState!.validate()) return;
              final success = await onConfirm(ctrl.text.trim());
              if (success) {
                Navigator.pop(ctx, true);
              }
            },
            style: ElevatedButton.styleFrom(
                backgroundColor: AmyalColors.primary, foregroundColor: Colors.white),
            child: const Text('تأكيد'),
          ),
        ],
      ),
    );

    if (ok == true && mounted) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
            content: Text('تم بنجاح'),
            backgroundColor: Color(0xFF2E7D32)),
      );
    }
  }

  Future<void> _confirmAction(String label, String hint, Future<bool> Function() action) async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(label),
        content: Text(hint),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('إلغاء')),
          ElevatedButton(
            onPressed: () => Navigator.pop(ctx, true),
            style: ElevatedButton.styleFrom(
                backgroundColor: AmyalColors.primary, foregroundColor: Colors.white),
            child: const Text('تأكيد'),
          ),
        ],
      ),
    );
    if (confirmed != true) return;
    final success = await action();
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(success ? 'تم بنجاح' : Get.find<SafePaymentController>().lastError.value),
        backgroundColor: success ? Colors.green.shade700 : AmyalColors.red,
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmyalColors.background,
      appBar: AppBar(
        backgroundColor: AmyalColors.primary,
        foregroundColor: Colors.white,
        title: const Text('تفاصيل الطلب'),
      ),
      body: Obx(() {
        final ctrl = Get.find<SafePaymentController>();
        final payment = ctrl.selectedPayment.value;
        if (ctrl.isLoading.value || payment == null) {
          return const Center(child: CircularProgressIndicator(color: AmyalColors.primary));
        }
        final actions = ctrl.availableActions.value;
        final isBuyer = ctrl.yourRole.value == 'buyer';

        return RefreshIndicator(
          onRefresh: () => ctrl.loadDetail(widget.ulid),
          color: AmyalColors.primary,
          child: ListView(
            padding: const EdgeInsets.all(16),
            children: [
              // ====== Status banner ======
              Container(
                padding: const EdgeInsets.all(16),
                decoration: BoxDecoration(
                  color: AmyalColors.yellow.withOpacity(0.2),
                  borderRadius: BorderRadius.circular(8),
                ),
                child: Row(
                  children: [
                    Icon(payment.isDisputed ? Icons.warning : Icons.shield,
                        color: AmyalColors.primary, size: 28),
                    const SizedBox(width: 12),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(payment.title,
                              style: const TextStyle(
                                  fontWeight: FontWeight.bold, fontSize: 16)),
                          Text(payment.arabicStatusLabel,
                              style: const TextStyle(
                                  fontSize: 12, color: AmyalColors.primary)),
                        ],
                      ),
                    ),
                  ],
                ),
              ),
              const SizedBox(height: 16),

              // ====== Amount card ======
              Container(
                padding: const EdgeInsets.all(20),
                decoration: BoxDecoration(
                  color: Colors.white,
                  borderRadius: BorderRadius.circular(8),
                  border: Border.all(color: AmyalColors.border),
                ),
                child: Column(
                  children: [
                    const Text('المبلغ',
                        style: TextStyle(
                            fontSize: 12, color: AmyalColors.textSecondary)),
                    Text(
                      '${payment.amount} ر.س',
                      style: const TextStyle(
                          fontSize: 28, fontWeight: FontWeight.bold,
                          color: AmyalColors.primary),
                    ),
                    if (double.parse(payment.heldAmount) > 0) ...[
                      const SizedBox(height: 8),
                      Container(
                        padding: const EdgeInsets.symmetric(
                            horizontal: 12, vertical: 4),
                        decoration: BoxDecoration(
                          color: AmyalColors.yellow.withOpacity(0.3),
                          borderRadius: BorderRadius.circular(12),
                        ),
                        child: Text('محجوز: ${payment.heldAmount} ر.س',
                            style: const TextStyle(fontSize: 11)),
                      ),
                    ],
                    if (double.parse(payment.platformFee) > 0) ...[
                      const SizedBox(height: 4),
                      Text('رسوم الخدمة: ${payment.platformFee} ر.س',
                          style: const TextStyle(
                              fontSize: 10, color: AmyalColors.textMuted)),
                    ],
                  ],
                ),
              ),
              const SizedBox(height: 16),

              // ====== Parties ======
              _Section(
                title: 'الأطراف',
                children: [
                  _DetailRow('البائع', payment.seller?.displayName ?? '-'),
                  _DetailRow('المشتري', payment.buyer?.displayName ?? '-'),
                  _DetailRow('دورك', isBuyer ? 'مشتري' : 'بائع'),
                ],
              ),
              const SizedBox(height: 16),

              // ====== Description ======
              _Section(
                title: 'تفاصيل السلعة',
                children: [
                  Padding(
                    padding: const EdgeInsets.symmetric(vertical: 4),
                    child: Text(payment.description,
                        style: const TextStyle(fontSize: 13)),
                  ),
                  if (payment.deliveryTerms != null &&
                      payment.deliveryTerms!.isNotEmpty) ...[
                    const Divider(height: 16),
                    const Text('شروط التسليم:',
                        style: TextStyle(
                            fontSize: 12, fontWeight: FontWeight.w600,
                            color: AmyalColors.textSecondary)),
                    Text(payment.deliveryTerms!,
                        style: const TextStyle(fontSize: 12)),
                  ],
                ],
              ),
              const SizedBox(height: 16),

              // ====== Timeline ======
              if (payment.events != null && payment.events!.isNotEmpty)
                _Section(
                  title: 'التسلسل الزمني',
                  children: payment.events!
                      .map((e) => _TimelineItem(event: e))
                      .toList(),
                ),

              const SizedBox(height: 24),

              // ====== Available actions ======
              ..._buildActions(ctrl, actions),
            ],
          ),
        );
      }),
    );
  }

  List<Widget> _buildActions(SafePaymentController ctrl, AmyalSafePaymentActions a) {
    final widgets = <Widget>[];
    final ulid = widget.ulid;

    if (a.sellerAccept) {
      widgets.add(_actionButton('قبول الطلب', Icons.check_circle, Colors.green.shade700,
        () => _confirmAction('قبول الطلب', 'هل تقبل تنفيذ هذه العملية؟',
          () => ctrl.sellerAccept(ulid)),
      ));
    }
    if (a.sellerReject) {
      widgets.add(_actionButton('رفض الطلب', Icons.cancel, AmyalColors.red, () {
        _showReasonDialog(
          title: 'رفض الطلب',
          hint: 'سبب الرفض...',
          onConfirm: (reason) => ctrl.sellerReject(ulid, reason),
        );
      }));
    }
    if (a.sellerMarkInDelivery) {
      widgets.add(_actionButton('بدء التسليم', Icons.local_shipping, AmyalColors.primary,
        () => _confirmAction('تأكيد بدء التسليم', 'هل بدأت تسليم السلعة؟',
          () => ctrl.sellerMarkInDelivery(ulid)),
      ));
    }
    if (a.sellerMarkDelivered) {
      widgets.add(_actionButton('تأكيد التسليم', Icons.done_all, Colors.green.shade700,
        () => _confirmAction('تأكيد التسليم', 'هل تم تسليم السلعة للمشتري؟',
          () => ctrl.sellerMarkDelivered(ulid)),
      ));
    }
    if (a.buyerConfirm) {
      widgets.add(_actionButton('تأكيد الاستلام (إفراج للبائع)', Icons.verified,
        Colors.green.shade700,
        () => _confirmAction('تأكيد الاستلام',
          'هل استلمت السلعة كما هي موصوفة؟ سيتم إفراج المبلغ للبائع.',
          () => ctrl.buyerConfirm(ulid)),
      ));
    }
    if (a.buyerCancel) {
      widgets.add(_actionButton('إلغاء واسترداد', Icons.refresh, Colors.orange, () {
        _showReasonDialog(
          title: 'إلغاء الطلب',
          hint: 'سبب الإلغاء...',
          onConfirm: (reason) => ctrl.buyerCancel(ulid, reason),
        );
      }));
    }
    if (a.buyerDispute) {
      widgets.add(_actionButton('فتح نزاع', Icons.warning, AmyalColors.red, () {
        _showReasonDialog(
          title: 'فتح نزاع',
          hint: 'اشرح المشكلة بالتفصيل (10 أحرف على الأقل)...',
          onConfirm: (reason) => ctrl.buyerDispute(ulid, reason),
        );
      }));
    }

    if (widgets.isEmpty) {
      widgets.add(Container(
        padding: const EdgeInsets.all(12),
        alignment: Alignment.center,
        child: const Text('لا توجد إجراءات متاحة في هذه المرحلة',
            style: TextStyle(color: AmyalColors.textMuted, fontSize: 12)),
      ));
    }

    return widgets;
  }

  Widget _actionButton(String label, IconData icon, Color color, VoidCallback onTap) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 8),
      child: ElevatedButton.icon(
        onPressed: onTap,
        icon: Icon(icon),
        label: Text(label),
        style: ElevatedButton.styleFrom(
          backgroundColor: color,
          foregroundColor: Colors.white,
          minimumSize: const Size(double.infinity, 48),
        ),
      ),
    );
  }
}

class _Section extends StatelessWidget {
  final String title;
  final List<Widget> children;
  const _Section({required this.title, required this.children});

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(8),
        border: Border.all(color: AmyalColors.border),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Padding(
            padding: const EdgeInsets.only(bottom: 8),
            child: Text(title,
                style: const TextStyle(
                    fontWeight: FontWeight.bold,
                    fontSize: 13,
                    color: AmyalColors.textSecondary)),
          ),
          ...children,
        ],
      ),
    );
  }
}

class _DetailRow extends StatelessWidget {
  final String label;
  final String value;
  const _DetailRow(this.label, this.value);

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 3),
      child: Row(
        children: [
          SizedBox(width: 80, child: Text(label, style: const TextStyle(fontSize: 12, color: AmyalColors.textSecondary))),
          Expanded(child: Text(value, style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w500))),
        ],
      ),
    );
  }
}

class _TimelineItem extends StatelessWidget {
  final AmyalSafePaymentEvent event;
  const _TimelineItem({required this.event});

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 4),
      child: Row(
        children: [
          Container(
            width: 8, height: 8,
            decoration: const BoxDecoration(
              color: AmyalColors.primary,
              shape: BoxShape.circle,
            ),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(event.arabicEventLabel,
                    style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 12)),
                if (event.note != null && event.note!.isNotEmpty)
                  Text(event.note!,
                      style: const TextStyle(fontSize: 11, color: AmyalColors.textMuted)),
                if (event.createdAt != null)
                  Text(_fmt(event.createdAt!),
                      style: const TextStyle(fontSize: 10, color: AmyalColors.textMuted)),
              ],
            ),
          ),
        ],
      ),
    );
  }

  String _fmt(DateTime d) {
    return '${d.year}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')} '
        '${d.hour.toString().padLeft(2, '0')}:${d.minute.toString().padLeft(2, '0')}';
  }
}
