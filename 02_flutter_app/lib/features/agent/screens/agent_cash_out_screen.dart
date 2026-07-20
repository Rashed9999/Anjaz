import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/features/agent/domain/repositories/agent_repo.dart';
import 'package:amyal_pay/features/shared/widgets/qr_widgets.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';

/// AMIAL-WD-CODE-001 — صرف سحب العميل برمز العملية.
///
/// العميل يُنشئ طلب سحب من تطبيقه فيحصل على «رمز العملية» (9 أرقام، صالح 45
/// دقيقة، والمبلغ محجوز من محفظته). يأتي للوكيل ويعطيه الرمز. الوكيل:
///   1) يُدخل الرمز → «بحث» → يرى المبلغ وبيانات العميل والوقت المتبقّي.
///   2) يتأكّد من هوية العميل (هاتفه/رقم حسابه) → «تنفيذ الصرف» → يُعطي الكاش.
/// المال ينتقل من المحجوز إلى محفظة الوكيل لحظة التنفيذ (لا اسم/رقم يدوي).
class AgentCashOutScreen extends StatefulWidget {
  const AgentCashOutScreen({super.key});

  @override
  State<AgentCashOutScreen> createState() => _AgentCashOutScreenState();
}

enum _Step { enterCode, confirm }

class _AgentCashOutScreenState extends State<AgentCashOutScreen> {
  final _repo = Get.find<AgentRepo>();
  final _codeCtrl = TextEditingController();
  final _identifierCtrl = TextEditingController();

  _Step _step = _Step.enterCode;
  bool _busy = false;

  // بيانات العملية بعد البحث
  String _amount = '';
  String _customerName = '';
  String _customerPhone = '';
  DateTime? _expiresAt;
  Timer? _tick;

  @override
  void dispose() {
    _codeCtrl.dispose();
    _identifierCtrl.dispose();
    _tick?.cancel();
    super.dispose();
  }

  void _snack(String m, {bool error = true}) =>
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
        content: Text(m),
        backgroundColor: error ? AmyalColors.red : AmyalColors.primary,
      ));

  String _msg(Response r, String fallback) {
    try {
      if (r.body is Map && r.body['message'] != null) {
        final m = r.body['message'].toString();
        if (m.isNotEmpty) return m;
      }
    } catch (_) {}
    return fallback;
  }

  Duration get _remaining {
    if (_expiresAt == null) return Duration.zero;
    final d = _expiresAt!.difference(DateTime.now());
    return d.isNegative ? Duration.zero : d;
  }

  /// مسح QR رمز السحب الظاهر في تطبيق العميل (AMIAL-WD:<code>) ثم بحث تلقائي.
  Future<void> _scan() async {
    final raw = await Get.to<String>(() => const QrScannerScreen(title: 'مسح رمز السحب'));
    if (raw == null || raw.isEmpty || !mounted) return;
    String code = raw.trim();
    if (code.startsWith('AMIAL-WD:')) {
      code = code.substring('AMIAL-WD:'.length).trim();
    } else {
      // قد يكون JSON {t:amial_wd, code} أو رقماً مجرّداً
      final m = RegExp(r'(\d{6,12})').firstMatch(code);
      if (m != null) code = m.group(1)!;
    }
    if (!RegExp(r'^\d{6,12}$').hasMatch(code)) {
      _snack('هذا الرمز ليس رمز سحب صالحاً');
      return;
    }
    _codeCtrl.text = code;
    await _lookup();
  }

  Future<void> _lookup() async {
    final code = _codeCtrl.text.trim();
    if (code.length < 6) { _snack('أدخل رمز العملية'); return; }
    setState(() => _busy = true);
    try {
      final r = await _repo.withdrawLookup(code);
      if (r.statusCode == 200 && r.body is Map && r.body['success'] == true) {
        final meta = (r.body['meta'] ?? {}) as Map;
        final cust = (meta['customer'] ?? {}) as Map;
        setState(() {
          _amount = '${meta['amount'] ?? ''}';
          _customerName = '${cust['name'] ?? ''}';
          _customerPhone = '${cust['phone'] ?? ''}';
          _expiresAt = DateTime.tryParse('${meta['expires_at'] ?? ''}')?.toLocal();
          _step = _Step.confirm;
        });
        _startTick();
      } else {
        _snack(_msg(r, 'رمز غير صحيح أو منتهٍ'));
      }
    } catch (_) {
      _snack('خطأ في الشبكة');
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  void _startTick() {
    _tick?.cancel();
    _tick = Timer.periodic(const Duration(seconds: 1), (t) {
      if (!mounted) { t.cancel(); return; }
      if (_remaining == Duration.zero) {
        t.cancel();
        _snack('انتهت صلاحية العملية — اطلب من العميل إنشاء طلب جديد');
        setState(() => _step = _Step.enterCode);
      } else {
        setState(() {});
      }
    });
  }

  Future<void> _execute() async {
    // AMIAL-WITHDRAW-FIX: رمز العملية هو المُصرّح الفعلي (أنشأه العميل على جهازه،
    // صالح ٤٥ دقيقة، والمبلغ محجوز). تأكيد الهوية اختياري — لا يمنع تنفيذ عملية
    // صحيحة. سابقاً كان إجبارياً فيفشل الصرف ولا تُسجَّل المعاملة.
    final identifier = _identifierCtrl.text.trim();
    setState(() => _busy = true);
    try {
      final r = await _repo.withdrawExecute(_codeCtrl.text.trim(), identifier);
      if (!mounted) return;
      if (r.statusCode == 200 && r.body is Map && r.body['success'] == true) {
        _tick?.cancel();
        await showDialog(
          context: context,
          builder: (ctx) => AlertDialog(
            icon: const Icon(Icons.check_circle, color: Color(0xFF2E7D32), size: 56),
            title: const Text('تم الصرف بنجاح', textAlign: TextAlign.center),
            content: Text('انتقل $_amount ر.ي إلى محفظتك. سلّم العميل الكاش الآن.',
                textAlign: TextAlign.center),
            actions: [
              FilledButton(
                onPressed: () { Navigator.pop(ctx); Get.back(); },
                child: const Text('تم'),
              ),
            ],
          ),
        );
      } else {
        _snack(_msg(r, 'تعذّر تنفيذ الصرف'));
      }
    } catch (_) {
      _snack('خطأ في الشبكة');
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmyalColors.background,
      appBar: AppBar(
        backgroundColor: AmyalColors.primary,
        foregroundColor: Colors.white,
        title: const Text('صرف سحب لعميل'),
      ),
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(16),
        child: _step == _Step.enterCode ? _enterCodeView() : _confirmView(),
      ),
    );
  }

  Widget _enterCodeView() {
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      Container(
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(
          color: AmyalColors.yellow.withValues(alpha: 0.15),
          borderRadius: BorderRadius.circular(10),
        ),
        child: const Row(children: [
          Icon(Icons.info_outline, color: AmyalColors.primary),
          SizedBox(width: 8),
          Expanded(child: Text(
            'اطلب من العميل «رمز العملية» الظاهر في تطبيقه (9 أرقام، صالح 45 دقيقة).',
            style: TextStyle(fontSize: 12.5),
          )),
        ]),
      ),
      const SizedBox(height: 20),
      TextField(
        controller: _codeCtrl,
        keyboardType: TextInputType.number,
        textAlign: TextAlign.center,
        maxLength: 9,
        inputFormatters: [FilteringTextInputFormatter.digitsOnly],
        style: const TextStyle(fontSize: 26, fontWeight: FontWeight.bold, letterSpacing: 6),
        decoration: const InputDecoration(
          labelText: 'رمز العملية',
          counterText: '',
          border: OutlineInputBorder(),
        ),
      ),
      const SizedBox(height: 20),
      FilledButton.icon(
        onPressed: _busy ? null : _lookup,
        icon: _busy
            ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(color: Colors.white, strokeWidth: 2))
            : const Icon(Icons.search),
        label: const Text('بحث عن العملية', style: TextStyle(fontSize: 16, fontWeight: FontWeight.w600)),
        style: FilledButton.styleFrom(
          backgroundColor: AmyalColors.primary, minimumSize: const Size.fromHeight(52)),
      ),
      const SizedBox(height: 10),
      OutlinedButton.icon(
        onPressed: _busy ? null : _scan,
        icon: const Icon(Icons.qr_code_scanner),
        label: const Text('مسح رمز QR للعميل', style: TextStyle(fontSize: 15, fontWeight: FontWeight.w600)),
        style: OutlinedButton.styleFrom(
          foregroundColor: AmyalColors.primary,
          side: const BorderSide(color: AmyalColors.primary),
          minimumSize: const Size.fromHeight(50)),
      ),
    ]);
  }

  Widget _confirmView() {
    final r = _remaining;
    final mm = r.inMinutes.toString().padLeft(2, '0');
    final ss = (r.inSeconds % 60).toString().padLeft(2, '0');
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      // بطاقة العملية
      Container(
        padding: const EdgeInsets.all(18),
        decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(16)),
        child: Column(children: [
          const Text('مبلغ السحب', style: TextStyle(fontSize: 12, color: AmyalColors.textSecondary)),
          const SizedBox(height: 4),
          Text('$_amount ر.ي',
              style: const TextStyle(fontSize: 30, fontWeight: FontWeight.bold, color: AmyalColors.primary)),
          const Divider(height: 26),
          _row('العميل', _customerName),
          _row('الهاتف', _customerPhone, ltr: true),
          _row('رمز العملية', _codeCtrl.text.trim(), ltr: true),
          const SizedBox(height: 10),
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
            decoration: BoxDecoration(
              color: (r.inMinutes < 5 ? AmyalColors.red : AmyalColors.primary).withValues(alpha: 0.1),
              borderRadius: BorderRadius.circular(20),
            ),
            child: Text('ينتهي خلال $mm:$ss',
                style: TextStyle(
                    fontWeight: FontWeight.bold,
                    color: r.inMinutes < 5 ? AmyalColors.red : AmyalColors.primary)),
          ),
        ]),
      ),
      const SizedBox(height: 16),
      const Text('تأكيد هوية العميل (اختياري):',
          style: TextStyle(fontSize: 13, fontWeight: FontWeight.w600)),
      const SizedBox(height: 4),
      const Text('رمز العملية وحده كافٍ للصرف. أدخل الهاتف/رقم الحساب فقط إن أردت تأكيداً إضافياً.',
          style: TextStyle(fontSize: 11.5, color: AmyalColors.textSecondary)),
      const SizedBox(height: 8),
      TextField(
        controller: _identifierCtrl,
        keyboardType: TextInputType.text,
        decoration: const InputDecoration(
          labelText: 'هاتف العميل أو رقم حسابه — اختياري',
          prefixIcon: Icon(Icons.badge_outlined),
          border: OutlineInputBorder(),
        ),
      ),
      const SizedBox(height: 20),
      FilledButton.icon(
        onPressed: _busy ? null : _execute,
        icon: _busy
            ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(color: Colors.white, strokeWidth: 2))
            : const Icon(Icons.payments),
        label: const Text('تنفيذ الصرف وتسليم الكاش', style: TextStyle(fontSize: 16, fontWeight: FontWeight.w600)),
        style: FilledButton.styleFrom(
          backgroundColor: const Color(0xFF2E7D32), minimumSize: const Size.fromHeight(54)),
      ),
      const SizedBox(height: 8),
      TextButton(
        onPressed: () { _tick?.cancel(); setState(() => _step = _Step.enterCode); },
        child: const Text('رجوع'),
      ),
    ]);
  }

  Widget _row(String k, String v, {bool ltr = false}) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 5),
        child: Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
          Flexible(child: Text(v,
              textDirection: ltr ? TextDirection.ltr : null,
              overflow: TextOverflow.ellipsis,
              style: const TextStyle(fontSize: 14, fontWeight: FontWeight.w600))),
          Text(k, style: const TextStyle(fontSize: 12, color: AmyalColors.textSecondary)),
        ]),
      );
}
