import 'dart:async';
import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amial_pay/theme/amial_colors.dart';
import 'package:amial_pay/features/shared/widgets/qr_widgets.dart';
import 'package:amial_pay/features/requested_money/controllers/payment_request_controller.dart';

/// AMIAL-QR-COLLECT-001 — استلام الدفع بـ QR لأي قطاع (طلب دفع فوري).
///
/// مكوّن قابل لإعادة الاستخدام: يُنشئ «طلب دفع» بمبلغ ثابت، يعرض QR، يستعلم عن
/// الحالة كل بضع ثوانٍ. فور دفع العميل يستدعي [onPaid] بمرجع الدفع — على المُستدعي
/// أن يُسجّل العملية ثم ينتقل للفاتورة، ويعيد true عند النجاح.
///
/// يستخدمه: كاشير المتجر، محطة الوقود، الصيدلية، الجملة… (دفع حقيقي بقيود مزدوجة).
class AmialQrCollectScreen extends StatefulWidget {
  const AmialQrCollectScreen({
    super.key,
    required this.amount,
    required this.onPaid,
    this.note,
    this.title = 'استلام الدفع — QR',
  });

  final double amount;
  final String? note;
  final String title;

  /// يُستدعى بعد دفع العميل. أعِد true عند نجاح تسجيل العملية (وتتكفّل بالتنقل).
  final Future<bool> Function(String paidTransactionId) onPaid;

  @override
  State<AmialQrCollectScreen> createState() => _AmialQrCollectScreenState();
}

enum _Stage { creating, waiting, finalizing, error }

class _AmialQrCollectScreenState extends State<AmialQrCollectScreen> {
  final _pr = Get.find<PaymentRequestController>();

  _Stage _stage = _Stage.creating;
  String? _code;
  int? _requestId;
  String _error = '';
  Timer? _poll;
  int _elapsed = 0;
  static const _timeoutSec = 180;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _create());
  }

  @override
  void dispose() {
    _poll?.cancel();
    super.dispose();
  }

  String get _qrPayload => jsonEncode({'t': 'amial_pr', 'code': _code});

  Future<void> _create() async {
    setState(() => _stage = _Stage.creating);
    final ok = await _pr.create(
      amount: widget.amount.toStringAsFixed(0),
      note: widget.note,
      shareMethod: 'qr',
    );
    if (!mounted) return;
    if (!ok) {
      setState(() {
        _stage = _Stage.error;
        _error = _pr.lastError.value.isEmpty ? 'تعذّر إنشاء طلب الدفع' : _pr.lastError.value;
      });
      return;
    }
    final meta = _pr.currentRequest.value ?? <String, dynamic>{};
    final req = (meta['request'] is Map) ? meta['request'] as Map : const {};
    _code = (meta['short_code'] ?? req['short_code'])?.toString();
    _requestId = int.tryParse('${req['id'] ?? ''}');
    if (_code == null || _code!.isEmpty) {
      setState(() { _stage = _Stage.error; _error = 'لم يصل رمز الطلب'; });
      return;
    }
    setState(() => _stage = _Stage.waiting);
    _startPolling();
  }

  void _startPolling() {
    _poll?.cancel();
    _poll = Timer.periodic(const Duration(seconds: 3), (t) async {
      if (!mounted) { t.cancel(); return; }
      _elapsed += 3;
      if (_elapsed >= _timeoutSec) {
        t.cancel();
        setState(() { _stage = _Stage.error; _error = 'انتهت مهلة الانتظار — أنشئ طلباً جديداً'; });
        return;
      }
      final data = await _pr.showByCode(_code!);
      if (!mounted || data == null) return;
      final req = (data['request'] ?? {}) as Map;
      final status = '${req['status'] ?? ''}';
      if (status == 'paid') {
        t.cancel();
        await _onPaid('${req['paid_transaction_id'] ?? ''}');
      } else if (status == 'cancelled' || status == 'expired') {
        t.cancel();
        setState(() {
          _stage = _Stage.error;
          _error = status == 'expired' ? 'انتهت صلاحية الطلب' : 'أُلغي الطلب';
        });
      } else {
        setState(() {});
      }
    });
  }

  Future<void> _onPaid(String paidTxId) async {
    setState(() => _stage = _Stage.finalizing);
    final ok = await widget.onPaid(paidTxId);
    if (!mounted) return;
    if (!ok) {
      setState(() {
        _stage = _Stage.error;
        _error = 'تم الدفع لكن تعذّر تسجيل العملية — راجع السجل';
      });
    }
    // عند النجاح: المُستدعي انتقل للفاتورة بالفعل (Get.off).
  }

  Future<void> _cancel() async {
    _poll?.cancel();
    if (_requestId != null) await _pr.cancel(_requestId!);
    if (mounted) Get.back();
  }

  String _fmt(double v) => v
      .toStringAsFixed(0)
      .replaceAllMapped(RegExp(r'(\d)(?=(\d{3})+$)'), (m) => '${m[1]},');

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(
        title: Text(widget.title),
      ),
      body: Center(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(24),
          child: switch (_stage) {
            _Stage.creating => _busy('جارٍ إنشاء طلب الدفع…'),
            _Stage.finalizing => _busy('تم الدفع — جارٍ تسجيل العملية…'),
            _Stage.error => _errorView(),
            _Stage.waiting => _waitingView(),
          },
        ),
      ),
    );
  }

  Widget _busy(String m) => Column(mainAxisSize: MainAxisSize.min, children: [
        const CircularProgressIndicator(),
        const SizedBox(height: 16),
        Text(m, style: const TextStyle(fontSize: 14)),
      ]);

  Widget _errorView() => Column(mainAxisSize: MainAxisSize.min, children: [
        const Icon(Icons.error_outline, color: AmialColors.red, size: 56),
        const SizedBox(height: 12),
        Text(_error, textAlign: TextAlign.center,
            style: const TextStyle(fontSize: 15, fontWeight: FontWeight.w600)),
        const SizedBox(height: 20),
        FilledButton.icon(
          onPressed: _create,
          icon: const Icon(Icons.refresh),
          label: const Text('إعادة المحاولة'),
          style: FilledButton.styleFrom(
              backgroundColor: AmialColors.primary, minimumSize: const Size(220, 48)),
        ),
        const SizedBox(height: 8),
        TextButton(onPressed: () => Get.back(), child: const Text('رجوع')),
      ]);

  Widget _waitingView() {
    final remain = (_timeoutSec - _elapsed).clamp(0, _timeoutSec);
    return Column(mainAxisSize: MainAxisSize.min, children: [
      Text('${_fmt(widget.amount)} ر.ي',
          style: const TextStyle(
              fontSize: 30, fontWeight: FontWeight.bold, color: AmialColors.primary)),
      const SizedBox(height: 4),
      const Text('اطلب من العميل مسح الرمز والدفع',
          style: TextStyle(fontSize: 13, color: AmialColors.textSecondary)),
      const SizedBox(height: 18),
      QrDisplayWidget(
        data: _qrPayload,
        size: 240,
        caption: 'أميال باي • رمز الدفع ${_code ?? ''}',
      ),
      const SizedBox(height: 18),
      Container(
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
        decoration: BoxDecoration(
          color: AmialColors.yellow.withValues(alpha: 0.18),
          borderRadius: BorderRadius.circular(30),
        ),
        child: Row(mainAxisSize: MainAxisSize.min, children: [
          const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2)),
          const SizedBox(width: 10),
          Text('بانتظار دفع العميل… (${remain ~/ 60}:${(remain % 60).toString().padLeft(2, '0')})',
              style: const TextStyle(
                  fontSize: 13, fontWeight: FontWeight.w600, color: AmialColors.primary)),
        ]),
      ),
      const SizedBox(height: 24),
      OutlinedButton.icon(
        onPressed: _cancel,
        icon: const Icon(Icons.close),
        label: const Text('إلغاء الطلب'),
        style: OutlinedButton.styleFrom(
            foregroundColor: AmialColors.red,
            side: const BorderSide(color: AmialColors.red),
            minimumSize: const Size(220, 48)),
      ),
    ]);
  }
}
