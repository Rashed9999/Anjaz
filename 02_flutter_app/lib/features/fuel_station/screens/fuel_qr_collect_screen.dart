import 'dart:async';
import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';
import 'package:amyal_pay/features/shared/widgets/qr_widgets.dart';
import 'package:amyal_pay/features/requested_money/controllers/payment_request_controller.dart';
import 'package:amyal_pay/features/fuel_station/controllers/fuel_station_controller.dart';
import 'package:amyal_pay/features/fuel_station/screens/fuel_receipt_screen.dart';

/// AMIAL-FUEL-QR-001 — استلام الدفع بـ QR (طلب دفع فوري).
///
/// التاجر يحدّد المبلغ في الكاشير، فتُنشأ «طلب دفع» بمبلغ ثابت ويُعرض QR.
/// العميل يمسحه من تطبيقه ويؤكّد الدفع من محفظته. تستعلم هذه الشاشة عن الحالة
/// كل بضع ثوانٍ؛ فور الدفع تُسجّل عملية البيع مربوطةً بمرجع الدفع وتفتح الفاتورة.
///
/// دفع حقيقي يحرّك المال (قيود مزدوجة) — ليس واجهة صورية.
class FuelQrCollectScreen extends StatefulWidget {
  const FuelQrCollectScreen({
    super.key,
    required this.saleData,
    required this.amount,
    required this.stationName,
    this.pumpLabel,
    this.productName,
  });

  /// بيانات البيع الأساسية (المضخّة/الوقود/اللترات/السعر) — بلا طريقة الدفع.
  final Map<String, dynamic> saleData;
  final double amount;
  final String stationName;
  final String? pumpLabel;
  final String? productName;

  @override
  State<FuelQrCollectScreen> createState() => _FuelQrCollectScreenState();
}

enum _Stage { creating, waiting, paying, done, error }

class _FuelQrCollectScreenState extends State<FuelQrCollectScreen> {
  final _pr = Get.find<PaymentRequestController>();
  final _fuel = Get.find<FuelStationController>();

  _Stage _stage = _Stage.creating;
  String? _code; // short_code
  int? _requestId; // للإلغاء
  String _error = '';
  Timer? _poll;
  int _elapsed = 0; // ثوانٍ منذ العرض (لانتهاء الصلاحية المرئي)
  static const _timeoutSec = 180; // 3 دقائق

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _createRequest());
  }

  @override
  void dispose() {
    _poll?.cancel();
    super.dispose();
  }

  /// نصّ QR — JSON يميّزه تطبيق العميل عن رمز التاجر الثابت.
  String get _qrPayload => jsonEncode({'t': 'amial_pr', 'code': _code});

  Future<void> _createRequest() async {
    setState(() => _stage = _Stage.creating);
    final ok = await _pr.create(
      amount: widget.amount.toStringAsFixed(0),
      note: 'دفع وقود — ${widget.productName ?? ''}'.trim(),
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
    final meta = _pr.currentRequest.value;
    _code = (meta['short_code'] ?? meta['request']?['short_code'])?.toString();
    _requestId = int.tryParse('${meta['request']?['id'] ?? ''}');
    if (_code == null || _code!.isEmpty) {
      setState(() {
        _stage = _Stage.error;
        _error = 'لم يصل رمز الطلب من الخادم';
      });
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
        setState(() {
          _stage = _Stage.error;
          _error = 'انتهت مهلة الانتظار — أنشئ طلباً جديداً';
        });
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
        setState(() {}); // حدّث المؤقّت المرئي
      }
    });
  }

  Future<void> _onPaid(String paidTxId) async {
    setState(() => _stage = _Stage.paying);
    // سجّل البيع مربوطاً بمرجع الدفع الذي نفّذه العميل.
    final data = Map<String, dynamic>.from(widget.saleData)
      ..['payment_method'] = 'amial_pay'
      ..['paid_transaction_id'] = paidTxId;
    final ok = await _fuel.recordSale(data);
    if (!mounted) return;
    if (!ok) {
      setState(() {
        _stage = _Stage.error;
        _error = _fuel.lastError.value.isEmpty
            ? 'تم الدفع لكن تعذّر تسجيل البيع — راجع السجل'
            : _fuel.lastError.value;
      });
      return;
    }
    _fuel.loadSales();
    setState(() => _stage = _Stage.done);
    // افتح الفاتورة الحرارية
    final s = Map<String, dynamic>.from(_fuel.lastSale.value ?? {});
    s.putIfAbsent('total_amount', () => widget.amount);
    s.putIfAbsent('product_name', () => widget.productName);
    Get.off(() => FuelReceiptScreen(
          sale: s,
          stationName: widget.stationName,
          pumpLabel: widget.pumpLabel,
        ));
  }

  Future<void> _cancel() async {
    _poll?.cancel();
    if (_requestId != null) {
      await _pr.cancel(_requestId!);
    }
    if (mounted) Get.back();
  }

  String _fmt(double v) => v
      .toStringAsFixed(0)
      .replaceAllMapped(RegExp(r'(\d)(?=(\d{3})+$)'), (m) => '${m[1]},');

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmyalColors.background,
      appBar: AppBar(
        backgroundColor: AmyalColors.primary,
        foregroundColor: Colors.white,
        title: const Text('استلام الدفع — QR'),
      ),
      body: Center(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(24),
          child: switch (_stage) {
            _Stage.creating => _busy('جارٍ إنشاء طلب الدفع…'),
            _Stage.paying => _busy('تم الدفع — جارٍ تسجيل البيع…'),
            _Stage.done => _busy('تمّ — فتح الفاتورة…'),
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
        const Icon(Icons.error_outline, color: AmyalColors.red, size: 56),
        const SizedBox(height: 12),
        Text(_error, textAlign: TextAlign.center,
            style: const TextStyle(fontSize: 15, fontWeight: FontWeight.w600)),
        const SizedBox(height: 20),
        FilledButton.icon(
          onPressed: _createRequest,
          icon: const Icon(Icons.refresh),
          label: const Text('إعادة المحاولة'),
          style: FilledButton.styleFrom(
              backgroundColor: AmyalColors.primary,
              minimumSize: const Size(220, 48)),
        ),
        const SizedBox(height: 8),
        TextButton(onPressed: () => Get.back(), child: const Text('رجوع')),
      ]);

  Widget _waitingView() {
    final remain = (_timeoutSec - _elapsed).clamp(0, _timeoutSec);
    return Column(mainAxisSize: MainAxisSize.min, children: [
      Text('${_fmt(widget.amount)} ر.ي',
          style: const TextStyle(
              fontSize: 30, fontWeight: FontWeight.bold, color: AmyalColors.primary)),
      const SizedBox(height: 4),
      const Text('اطلب من العميل مسح الرمز والدفع',
          style: TextStyle(fontSize: 13, color: AmyalColors.textSecondary)),
      const SizedBox(height: 18),
      QrDisplayWidget(
        data: _qrPayload,
        size: 240,
        caption: 'أميال باي • رمز الدفع ${_code ?? ''}',
      ),
      const SizedBox(height: 18),
      // حالة الانتظار الحيّة
      Container(
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
        decoration: BoxDecoration(
          color: AmyalColors.yellow.withValues(alpha: 0.18),
          borderRadius: BorderRadius.circular(30),
        ),
        child: Row(mainAxisSize: MainAxisSize.min, children: [
          const SizedBox(
              width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2)),
          const SizedBox(width: 10),
          Text('بانتظار دفع العميل… (${remain ~/ 60}:${(remain % 60).toString().padLeft(2, '0')})',
              style: const TextStyle(
                  fontSize: 13, fontWeight: FontWeight.w600, color: AmyalColors.primary)),
        ]),
      ),
      const SizedBox(height: 24),
      OutlinedButton.icon(
        onPressed: _cancel,
        icon: const Icon(Icons.close),
        label: const Text('إلغاء الطلب'),
        style: OutlinedButton.styleFrom(
            foregroundColor: AmyalColors.red,
            side: const BorderSide(color: AmyalColors.red),
            minimumSize: const Size(220, 48)),
      ),
    ]);
  }
}
