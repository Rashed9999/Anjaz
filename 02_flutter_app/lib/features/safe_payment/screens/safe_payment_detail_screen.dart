import 'package:amial_pay/helper/amial_money.dart';
import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amial_pay/features/safe_payment/controllers/safe_payment_controller.dart';
import 'package:amial_pay/features/safe_payment/domain/models/safe_payment_models.dart';
import 'package:amial_pay/features/safe_payment/widgets/delivery_code_card.dart';
import 'package:amial_pay/features/safe_payment/widgets/dispute_sheet.dart';
import 'package:amial_pay/features/safe_payment/widgets/evidence_gallery.dart';
import 'package:amial_pay/features/safe_payment/widgets/evidence_picker_sheet.dart';
import 'package:amial_pay/features/safe_payment/widgets/trust_card.dart';
import 'package:amial_pay/theme/amial_colors.dart';

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
                backgroundColor: AmialColors.primary, foregroundColor: Colors.white),
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
                backgroundColor: AmialColors.primary, foregroundColor: Colors.white),
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
        backgroundColor: success ? Colors.green.shade700 : AmialColors.red,
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(
        title: const Text('تفاصيل الطلب'),
      ),
      body: Obx(() {
        final ctrl = Get.find<SafePaymentController>();
        final payment = ctrl.selectedPayment.value;
        if (ctrl.isLoading.value || payment == null) {
          return const Center(child: CircularProgressIndicator(color: AmialColors.primary));
        }
        final actions = ctrl.availableActions.value;
        final isBuyer = ctrl.yourRole.value == 'buyer';

        return RefreshIndicator(
          onRefresh: () => ctrl.loadDetail(widget.ulid),
          color: AmialColors.primary,
          child: ListView(
            padding: const EdgeInsets.all(16),
            children: [
              // ====== Status banner ======
              Container(
                padding: const EdgeInsets.all(16),
                decoration: BoxDecoration(
                  color: AmialColors.yellow.withValues(alpha: 0.2),
                  borderRadius: BorderRadius.circular(8),
                ),
                child: Row(
                  children: [
                    Icon(payment.isDisputed ? Icons.warning : Icons.shield,
                        color: AmialColors.primary, size: 28),
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
                                  fontSize: 12, color: AmialColors.primary)),
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
                  border: Border.all(color: AmialColors.border),
                ),
                child: Column(
                  children: [
                    const Text('المبلغ',
                        style: TextStyle(
                            fontSize: 12, color: AmialColors.textSecondary)),
                    Text(
                      AmialMoney.yer(payment.amount),
                      style: const TextStyle(
                          fontSize: 28, fontWeight: FontWeight.bold,
                          color: AmialColors.primary),
                    ),
                    if (double.parse(payment.heldAmount) > 0) ...[
                      const SizedBox(height: 8),
                      Container(
                        padding: const EdgeInsets.symmetric(
                            horizontal: 12, vertical: 4),
                        decoration: BoxDecoration(
                          color: AmialColors.yellow.withValues(alpha: 0.3),
                          borderRadius: BorderRadius.circular(12),
                        ),
                        child: Text('محجوز: '+AmialMoney.yer(payment.heldAmount),
                            style: const TextStyle(fontSize: 11)),
                      ),
                    ],
                    if (double.parse(payment.platformFee) > 0) ...[
                      const SizedBox(height: 4),
                      Text('رسوم الخدمة: '+AmialMoney.yer(payment.platformFee),
                          style: const TextStyle(
                              fontSize: 10, color: AmialColors.textMuted)),
                    ],
                  ],
                ),
              ),
              const SizedBox(height: 16),

              // AMIAL-DESIGN: «مراحل الضمان» — دفع المبلغ → استلام السلعة → تحرير الأموال
              _StagesStrip(status: payment.status),
              const SizedBox(height: 16),

              // ====== AMIAL-SAFEPAY-CODE-001: رمز التسليم ======
              // موضعه فوق كل شيء بعد الحالة مقصود: هو أهمّ ما في الشاشة
              // لحظة اللقاء، ومن يبحث عنه في الأسفل يجده متأخّراً.
              ..._buildDeliveryCodeBlock(ctrl, payment, isBuyer),

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

              // ====== AMIAL-SAFEPAY-TRUST-001: سجلّ الطرف المقابل ======
              if (ctrl.counterpartyTrust.value != null) ...[
                TrustCard(
                  trust: ctrl.counterpartyTrust.value!,
                  counterpartyName: isBuyer
                      ? (payment.seller?.displayName ?? 'البائع')
                      : (payment.buyer?.displayName ?? 'المشتري'),
                ),
                const SizedBox(height: 16),
              ],

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
                            color: AmialColors.textSecondary)),
                    Text(payment.deliveryTerms!,
                        style: const TextStyle(fontSize: 12)),
                  ],
                ],
              ),
              const SizedBox(height: 16),

              // ====== AMIAL-SAFEPAY-EVIDENCE-001: الأدلّة ======
              ..._buildEvidenceBlock(ctrl, payment, isBuyer),

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

  /// رمز التسليم: يُعرض للمشتري ويُدخَل عند البائع — ولا يظهر لأيّهما بعد
  /// تأكيده أو بعد انتهاء العملية، لأن رمزاً مستهلكاً على الشاشة يوهم بأن
  /// هناك خطوة باقية.
  List<Widget> _buildDeliveryCodeBlock(
      SafePaymentController ctrl, AmialSafePayment payment, bool isBuyer) {
    if (payment.isTerminal || ctrl.deliveryCodeVerified.value) {
      if (!ctrl.deliveryCodeVerified.value) return const [];
      return [
        Container(
          padding: const EdgeInsets.all(12),
          decoration: BoxDecoration(
            color: const Color(0xFFE8F5E9),
            borderRadius: BorderRadius.circular(10),
          ),
          child: const Row(children: [
            Icon(Icons.verified_user_rounded, size: 18, color: Color(0xFF2E7D32)),
            SizedBox(width: 8),
            Expanded(
              child: Text('تُوثِّق هذه العملية بتأكيد التسليم برمز المشتري.',
                  style: TextStyle(fontSize: 11.5, color: Color(0xFF2E7D32))),
            ),
          ]),
        ),
        const SizedBox(height: 16),
      ];
    }

    if (isBuyer) {
      final code = ctrl.deliveryCode.value;
      if (code.isEmpty) return const [];
      return [BuyerDeliveryCodeCard(code: code), const SizedBox(height: 16)];
    }

    // البائع: الإدخال متاح في الحالتين اللتين يقبلهما الخادم فقط.
    if (!['funded', 'in_delivery'].contains(payment.status)) return const [];

    return [SellerDeliveryCodeEntry(ulid: widget.ulid), const SizedBox(height: 16)];
  }

  /// المرحلة التي يُرفَع إليها الدليل الآن — تُشتقّ من الدور والحالة بدل أن
  /// يُطلب من المستخدم اختيارها. من يُسأل «في أيّ مرحلة؟» يختار خطأً.
  ({String stage, String label, String hint})? _evidenceTarget(
      String status, bool isBuyer) {
    if (status == 'disputed') {
      return isBuyer
          ? (
              stage: 'dispute',
              label: 'إضافة أدلّة للنزاع',
              hint: 'صور تُظهر المشكلة: التلف، النقص، الاختلاف عن الوصف، أو شاشة المحادثة.',
            )
          : (
              stage: 'dispute',
              label: 'إضافة أدلّتك ردّاً',
              hint: 'أثبت ما سلّمت: صور البضاعة قبل الشحن، إيصال الناقل، أو محادثة الاتفاق.',
            );
    }

    if (!isBuyer) {
      return switch (status) {
        'funded' || 'in_delivery' => (
            stage: 'in_delivery',
            label: 'إرفاق أدلّة الشحن',
            hint: 'صوّر البضاعة قبل إغلاق الطرد، والطرد مغلقاً، وإيصال الناقل إن وُجد.',
          ),
        'delivered' => (
            stage: 'delivered',
            label: 'إرفاق أدلّة التسليم',
            hint: 'صورة لحظة التسليم أو توقيع المستلم — تحسم دعوى «لم يصلني».',
          ),
        _ => null,
      };
    }

    return switch (status) {
      'delivered' => (
          stage: 'delivered',
          label: 'توثيق ما وصلك',
          hint: 'صوّر الطرد قبل فتحه ثم بعد فتحه. هذه الصور حمايتك إن ظهر خلل لاحقاً.',
        ),
      _ => null,
    };
  }

  List<Widget> _buildEvidenceBlock(
      SafePaymentController ctrl, AmialSafePayment payment, bool isBuyer) {
    final target = _evidenceTarget(payment.status, isBuyer);

    return [
      EvidenceGallery(evidence: Map.of(ctrl.evidence)),
      if (target != null) ...[
        const SizedBox(height: 8),
        OutlinedButton.icon(
          onPressed: () => _openEvidenceSheet(
              stage: target.stage, title: target.label, hint: target.hint),
          icon: const Icon(Icons.add_a_photo_outlined, size: 19),
          label: Text(target.label),
          style: OutlinedButton.styleFrom(
            foregroundColor: AmialColors.primary,
            minimumSize: const Size(double.infinity, 46),
          ),
        ),
      ],
      const SizedBox(height: 16),
    ];
  }

  /// النزاع ثم الأدلّة في نفَس واحد.
  ///
  /// من يفتح نزاعاً ثم يُترك في الشاشة نفسها نادراً ما يعود ليرفع صوراً —
  /// فيصل للإدارة ادّعاء بلا سند. سَوقُه إلى الرفع مباشرةً وهو في لحظة
  /// انفعاله بالمشكلة هو أنجع وقت لطلب الدليل.
  Future<void> _openDispute() async {
    final opened = await DisputeSheet.open(context, ulid: widget.ulid);
    if (!opened || !mounted) return;

    ScaffoldMessenger.of(context).showSnackBar(
      const SnackBar(
        content: Text('فُتح النزاع — المبلغ محجوز حتى المراجعة'),
        backgroundColor: Color(0xFF2E7D32),
      ),
    );

    await _openEvidenceSheet(
      stage: 'dispute',
      title: 'أضف أدلّة النزاع الآن',
      hint: 'صور تُظهر المشكلة: التلف، النقص، الاختلاف عن الوصف، أو شاشة المحادثة. '
          'النزاع المدعوم بالصور يُحسم أسرع وأعدل.',
    );
  }

  Future<void> _openEvidenceSheet({
    required String stage,
    required String title,
    required String hint,
  }) async {
    final uploaded = await EvidencePickerSheet.open(
      context,
      ulid: widget.ulid,
      stage: stage,
      title: title,
      hint: hint,
    );
    if (!uploaded || !mounted) return;

    await Get.find<SafePaymentController>().refreshEvidence(widget.ulid);
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(
      const SnackBar(content: Text('رُفعت الأدلّة ✓'), backgroundColor: Color(0xFF2E7D32)),
    );
  }

  List<Widget> _buildActions(SafePaymentController ctrl, AmialSafePaymentActions a) {
    final widgets = <Widget>[];
    final ulid = widget.ulid;

    if (a.sellerAccept) {
      widgets.add(_actionButton('قبول الطلب', Icons.check_circle, Colors.green.shade700,
        () => _confirmAction('قبول الطلب', 'هل تقبل تنفيذ هذه العملية؟',
          () => ctrl.sellerAccept(ulid)),
      ));
    }
    if (a.sellerReject) {
      widgets.add(_actionButton('رفض الطلب', Icons.cancel, AmialColors.red, () {
        _showReasonDialog(
          title: 'رفض الطلب',
          hint: 'سبب الرفض...',
          onConfirm: (reason) => ctrl.sellerReject(ulid, reason),
        );
      }));
    }
    if (a.sellerMarkInDelivery) {
      widgets.add(_actionButton('بدء التسليم', Icons.local_shipping, AmialColors.primary,
        () => _confirmAction('تأكيد بدء التسليم', 'هل بدأت تسليم السلعة؟',
          () => ctrl.sellerMarkInDelivery(ulid)),
      ));
    }
    if (a.sellerMarkDelivered) {
      // التأكيد بلا رمز يبقى متاحاً (الشحن البعيد لا يلتقي فيه الطرفان)، لكن
      // يُقال للبائع صراحةً إنه أضعف — فهو ادّعاء من طرف واحد يُنازَع فيه.
      widgets.add(_actionButton('تأكيد التسليم بدون رمز', Icons.done_all, Colors.orange.shade800,
        () => _confirmAction(
          'تأكيد التسليم بدون رمز',
          'التأكيد برمز المشتري أقوى: لا يُنازَع فيه. أما التأكيد بدونه فهو '
          'إقرارٌ منك وحدك، وإن أنكر المشتري الاستلام احتجتَ أدلّة الشحن.\n\n'
          'هل تريد المتابعة بدون رمز؟',
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
      widgets.add(_actionButton('فتح نزاع', Icons.warning, AmialColors.red, _openDispute));
    }

    if (widgets.isEmpty) {
      widgets.add(Container(
        padding: const EdgeInsets.all(12),
        alignment: Alignment.center,
        child: const Text('لا توجد إجراءات متاحة في هذه المرحلة',
            style: TextStyle(color: AmialColors.textMuted, fontSize: 12)),
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

/// AMIAL-DESIGN: شريط «مراحل الضمان» الأفقي (3 محطات) حسب حالة العملية.
class _StagesStrip extends StatelessWidget {
  final String status;
  const _StagesStrip({required this.status});

  /// 0 = دفع المبلغ، 1 = استلام السلعة، 2 = تحرير الأموال
  int get _stage {
    switch (status) {
      case 'pending_seller_acceptance':
      case 'funded':
      case 'in_delivery':
        return 0;
      case 'delivered':
      case 'disputed':
        return 1;
      case 'buyer_confirmed':
      case 'released_to_seller':
        return 2;
      default:
        return 0; // ملغي/مسترد — تبقى المحطة الأولى
    }
  }

  @override
  Widget build(BuildContext context) {
    const labels = ['دفع المبلغ', 'استلام السلعة', 'تحرير الأموال'];
    const icons = [
      Icons.payments_outlined,
      Icons.inventory_2_outlined,
      Icons.check_circle_outline,
    ];
    final current = _stage;

    return Container(
      padding: const EdgeInsets.symmetric(vertical: 16, horizontal: 8),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: AmialColors.border),
      ),
      child: Column(children: [
        const Align(
          alignment: Alignment.centerRight,
          child: Padding(
            padding: EdgeInsets.only(right: 8, bottom: 12),
            child: Text('مراحل الضمان',
                style: TextStyle(fontWeight: FontWeight.bold, fontSize: 14)),
          ),
        ),
        Row(children: [
          for (var i = 0; i < 3; i++) ...[
            if (i > 0)
              Expanded(
                child: Container(
                  height: 3,
                  color: i <= current
                      ? AmialColors.primary
                      : const Color(0xFFE5E7EB),
                ),
              ),
            Column(children: [
              Container(
                height: 46,
                width: 46,
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  color: i < current
                      ? const Color(0xFFE3EAE6)
                      : i == current
                          ? (current == 2
                              ? AmialColors.primary
                              : AmialColors.yellow)
                          : const Color(0xFFF0F1F3),
                ),
                child: Icon(
                  i < current ? Icons.check : icons[i],
                  size: 20,
                  color: i < current
                      ? AmialColors.primary
                      : i == current
                          ? (current == 2 ? Colors.white : AmialColors.primary)
                          : AmialColors.textMuted,
                ),
              ),
              const SizedBox(height: 6),
              Text(labels[i],
                  style: TextStyle(
                      fontSize: 10,
                      fontWeight:
                          i == current ? FontWeight.bold : FontWeight.normal,
                      color: i <= current
                          ? AmialColors.primary
                          : AmialColors.textMuted)),
            ]),
          ],
        ]),
      ]),
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
        border: Border.all(color: AmialColors.border),
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
                    color: AmialColors.textSecondary)),
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
          SizedBox(width: 80, child: Text(label, style: const TextStyle(fontSize: 12, color: AmialColors.textSecondary))),
          Expanded(child: Text(value, style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w500))),
        ],
      ),
    );
  }
}

class _TimelineItem extends StatelessWidget {
  final AmialSafePaymentEvent event;
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
              color: AmialColors.primary,
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
                      style: const TextStyle(fontSize: 11, color: AmialColors.textMuted)),
                if (event.createdAt != null)
                  Text(_fmt(event.createdAt!),
                      style: const TextStyle(fontSize: 10, color: AmialColors.textMuted)),
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
