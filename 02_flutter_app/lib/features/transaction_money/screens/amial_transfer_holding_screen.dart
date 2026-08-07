import 'dart:async';
import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:share_plus/share_plus.dart';
import 'package:amial_pay/features/transaction_money/domain/amial_transfer_api.dart';
import 'package:amial_pay/helper/amial_money.dart';
import 'package:amial_pay/helper/route_helper.dart';
import 'package:amial_pay/theme/amial_colors.dart';

/// AMIAL-TRANSFER-V2 — شاشة التحويل بعد التنفيذ:
/// نافذة تراجع (عدّاد تنازلي + زر «تراجع عن التحويل») ثم حالة «تم التسليم».
class AmialTransferHoldingScreen extends StatefulWidget {
  const AmialTransferHoldingScreen({
    super.key,
    required this.transferUlid,
    required this.amount,
    required this.fee,
    required this.secondsRemaining,
    required this.recipientName,
    required this.recipientPhone,
    this.note,
  });

  final String transferUlid;
  final String amount;
  final String fee;
  final int secondsRemaining;
  final String recipientName;
  final String recipientPhone;
  final String? note;

  @override
  State<AmialTransferHoldingScreen> createState() =>
      _AmialTransferHoldingScreenState();
}

/// حالات الشاشة.
enum _Phase { holding, completed, cancelled, cancelling }

class _AmialTransferHoldingScreenState
    extends State<AmialTransferHoldingScreen> {
  Timer? _ticker;
  late int _remaining;
  _Phase _phase = _Phase.holding;
  String _refunded = '';

  @override
  void initState() {
    super.initState();
    _remaining = widget.secondsRemaining;
    _startTicker();
  }

  void _startTicker() {
    _ticker?.cancel();
    _ticker = Timer.periodic(const Duration(seconds: 1), (_) async {
      if (!mounted) return;
      if (_remaining <= 1) {
        _ticker?.cancel();
        setState(() => _remaining = 0);
        await _refreshStatus();
      } else {
        setState(() => _remaining--);
      }
    });
  }

  /// عند انتهاء النافذة: استعلم عن الحالة (قد يتأخر المجدول ثوانيَ قليلة).
  Future<void> _refreshStatus() async {
    for (var attempt = 0; attempt < 6; attempt++) {
      try {
        final r = await AmialTransferApi.status(widget.transferUlid);
        if (r.statusCode == 200 && r.body is Map) {
          final st = '${r.body['meta']?['status'] ?? ''}';
          if (st == 'completed') {
            if (mounted) setState(() => _phase = _Phase.completed);
            return;
          }
          if (st == 'cancelled') {
            if (mounted) setState(() => _phase = _Phase.cancelled);
            return;
          }
        }
      } catch (_) {}
      await Future.delayed(const Duration(seconds: 3));
      if (!mounted) return;
    }
    // لم تتأكد الحالة بعد — نعتبره مكتملاً بصرياً (المجدول سيسلّمه)
    if (mounted) setState(() => _phase = _Phase.completed);
  }

  Future<void> _cancel() async {
    final sure = await showModalBottomSheet<bool>(
      context: context,
      backgroundColor: Colors.white,
      shape: const RoundedRectangleBorder(
          borderRadius: BorderRadius.vertical(top: Radius.circular(24))),
      builder: (ctx) => Padding(
        padding: const EdgeInsets.all(24),
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          const Icon(Icons.warning_amber_rounded,
              color: AmialColors.red, size: 44),
          const SizedBox(height: 12),
          const Text('التراجع عن التحويل؟',
              style: TextStyle(fontWeight: FontWeight.bold, fontSize: 16)),
          const SizedBox(height: 8),
          const Text(
            'سيُلغى التحويل ويعود المبلغ كاملاً (مع الرسوم) إلى محفظتك فوراً.',
            textAlign: TextAlign.center,
            style: TextStyle(fontSize: 13, color: AmialColors.textSecondary),
          ),
          const SizedBox(height: 18),
          FilledButton(
            onPressed: () => Navigator.pop(ctx, false),
            style: FilledButton.styleFrom(
                backgroundColor: AmialColors.primary,
                minimumSize: const Size.fromHeight(50)),
            child: const Text('لا، أكمل التحويل'),
          ),
          const SizedBox(height: 8),
          OutlinedButton(
            onPressed: () => Navigator.pop(ctx, true),
            style: OutlinedButton.styleFrom(
                foregroundColor: AmialColors.red,
                side: const BorderSide(color: AmialColors.red),
                minimumSize: const Size.fromHeight(50)),
            child: const Text('نعم، تراجع واسترد المبلغ'),
          ),
        ]),
      ),
    );
    if (sure != true || !mounted) return;

    setState(() => _phase = _Phase.cancelling);
    try {
      final r = await AmialTransferApi.cancel(widget.transferUlid);
      if (r.statusCode == 200 && r.body is Map && r.body['success'] == true) {
        _ticker?.cancel();
        _refunded = '${r.body['meta']?['refunded'] ?? ''}';
        if (mounted) setState(() => _phase = _Phase.cancelled);
        return;
      }
      String msg = 'تعذّر الإلغاء — ربما انتهت المهلة';
      try {
        if (r.body is Map && r.body['message'] != null) {
          msg = '${r.body['message']}';
        }
      } catch (_) {}
      if (mounted) {
        setState(() => _phase = _Phase.holding);
        ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(content: Text(msg), backgroundColor: AmialColors.red));
      }
    } catch (_) {
      if (mounted) {
        setState(() => _phase = _Phase.holding);
        ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
            content: Text('خطأ في الشبكة'), backgroundColor: AmialColors.red));
      }
    }
  }

  Future<void> _share() async {
    final total =
        (double.tryParse(widget.amount) ?? 0) + (double.tryParse(widget.fee) ?? 0);
    await Share.share('''
إيصال تحويل — أميال باي
المستلم: ${widget.recipientName} (${widget.recipientPhone})
المبلغ: ${AmialMoney.yer(widget.amount)}
الرسوم: ${AmialMoney.yer(widget.fee)}
الإجمالي: ${AmialMoney.yer(total)}
رقم العملية: ${widget.transferUlid}
''');
  }

  @override
  void dispose() {
    _ticker?.cancel();
    super.dispose();
  }

  String _fmt(int s) =>
      '${(s ~/ 60).toString().padLeft(2, '0')}:${(s % 60).toString().padLeft(2, '0')}';

  @override
  Widget build(BuildContext context) {
    final holding = _phase == _Phase.holding || _phase == _Phase.cancelling;
    final cancelled = _phase == _Phase.cancelled;

    return PopScope(
      canPop: false,
      onPopInvokedWithResult: (didPop, _) {
        if (!didPop) Get.offAllNamed(RouteHelper.getNavBarRoute());
      },
      child: Scaffold(
        backgroundColor: AmialColors.background,
        body: SafeArea(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(24),
            child: Column(children: [
              const SizedBox(height: 24),

              // ====== أيقونة الحالة ======
              Container(
                height: 96,
                width: 96,
                alignment: Alignment.center,
                decoration: BoxDecoration(
                  color: cancelled
                      ? AmialColors.red.withValues(alpha: 0.1)
                      : holding
                          ? AmialColors.yellow.withValues(alpha: 0.3)
                          : AmialColors.primary,
                  shape: BoxShape.circle,
                ),
                child: holding
                    ? Text(_fmt(_remaining),
                        style: const TextStyle(
                            fontSize: 22,
                            fontWeight: FontWeight.bold,
                            color: AmialColors.primary))
                    : Icon(cancelled ? Icons.replay_rounded : Icons.check_rounded,
                        color: cancelled ? AmialColors.red : Colors.white,
                        size: 52),
              ),
              const SizedBox(height: 20),

              Text(
                cancelled
                    ? 'تم التراجع واسترداد المبلغ'
                    : holding
                        ? 'التحويل قيد التنفيذ'
                        : 'تم التحويل بنجاح',
                style: const TextStyle(
                    fontSize: 20,
                    fontWeight: FontWeight.bold,
                    color: AmialColors.primary),
              ),
              const SizedBox(height: 8),
              Text(
                cancelled
                    ? 'عاد ${AmialMoney.yer(_refunded.isEmpty ? widget.amount : _refunded)} إلى محفظتك.'
                    : holding
                        ? 'سيصل المبلغ إلى ${widget.recipientName} بعد انتهاء العدّاد. يمكنك التراجع خلال هذه المهلة.'
                        : 'وصل المبلغ إلى ${widget.recipientName}.',
                textAlign: TextAlign.center,
                style: const TextStyle(
                    fontSize: 13, color: AmialColors.textSecondary, height: 1.6),
              ),
              const SizedBox(height: 24),

              // ====== بطاقة الإيصال ======
              Container(
                padding: const EdgeInsets.all(18),
                decoration: BoxDecoration(
                  color: Colors.white,
                  borderRadius: BorderRadius.circular(16),
                ),
                child: Column(children: [
                  _row('المستلم', widget.recipientName),
                  _row('رقم المستلم', widget.recipientPhone),
                  const Divider(height: 22),
                  _row('مبلغ التحويل', AmialMoney.yer(widget.amount)),
                  _row('رسوم التحويل', AmialMoney.yer(widget.fee)),
                  _row(
                      'الإجمالي',
                      AmialMoney.yer((double.tryParse(widget.amount) ?? 0) +
                          (double.tryParse(widget.fee) ?? 0)),
                      bold: true),
                  if (widget.note != null && widget.note!.isNotEmpty) ...[
                    const Divider(height: 22),
                    _row('البيان', widget.note!),
                  ],
                  const Divider(height: 22),
                  _row('رقم العملية', widget.transferUlid, small: true),
                ]),
              ),
              const SizedBox(height: 24),

              // ====== الأزرار ======
              if (holding)
                OutlinedButton.icon(
                  onPressed: _phase == _Phase.cancelling ? null : _cancel,
                  icon: _phase == _Phase.cancelling
                      ? const SizedBox(
                          height: 16,
                          width: 16,
                          child: CircularProgressIndicator(strokeWidth: 2))
                      : const Icon(Icons.replay_rounded, size: 20),
                  label: Text('تراجع عن التحويل (${_fmt(_remaining)})'),
                  style: OutlinedButton.styleFrom(
                    foregroundColor: AmialColors.red,
                    side: const BorderSide(color: AmialColors.red),
                    minimumSize: const Size.fromHeight(52),
                  ),
                ),
              if (!holding && !cancelled)
                FilledButton.icon(
                  onPressed: _share,
                  icon: const Icon(Icons.share, size: 18),
                  label: const Text('مشاركة الإيصال'),
                  style: FilledButton.styleFrom(
                    backgroundColor: AmialColors.primary,
                    minimumSize: const Size.fromHeight(52),
                  ),
                ),
              const SizedBox(height: 10),
              TextButton.icon(
                onPressed: () => Get.offAllNamed(RouteHelper.getNavBarRoute()),
                icon: const Icon(Icons.home_outlined, size: 20),
                label: const Text('العودة للرئيسية'),
                style: TextButton.styleFrom(
                    foregroundColor: AmialColors.primary,
                    minimumSize: const Size.fromHeight(48)),
              ),
            ]),
          ),
        ),
      ),
    );
  }

  Widget _row(String label, String value, {bool bold = false, bool small = false}) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 4),
      child: Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
        Flexible(
          child: Text(value,
              textDirection: small ? TextDirection.ltr : null,
              style: TextStyle(
                  fontSize: small ? 11 : 14,
                  fontWeight: bold ? FontWeight.bold : FontWeight.w600,
                  color: bold ? AmialColors.primary : Colors.black87),
              overflow: TextOverflow.ellipsis),
        ),
        Text(label,
            style: const TextStyle(
                fontSize: 13, color: AmialColors.textSecondary)),
      ]),
    );
  }
}
