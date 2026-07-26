import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/data/api/api_client.dart';
import 'package:amyal_pay/features/shared/widgets/amial_numpad.dart';
import 'package:amyal_pay/features/shared/widgets/amial_pin_dots.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';
import 'package:amyal_pay/util/secure_screen.dart';

/// AMIAL-PIN-GATE-001
///
/// بوّابة رمز المعاملات (PIN) الموحّدة — تُعرض بعد الدخول وقبل كل عملية مالية
/// (تحويل/سحب/صندوق عائلي/دفع آمن…). تتحقّق من الرمز عبر الخادم
/// (POST /api/v1/amial/verify-pin) وتُرجع true عند النجاح.
///
/// الاستخدام:
///   final ok = await askAmialPin(title: 'تأكيد السحب');
///   if (!ok) return;
Future<bool> askAmialPin({String title = 'أدخل رمز PIN'}) async {
  final result = await Get.to<bool>(
    () => _AmialPinGateScreen(title: title),
    fullscreenDialog: true,
  );
  return result == true;
}

/// AMIAL-TRANSFER-V2: جمع رمز PIN فقط (بلا تحقق من الخادم) — يُستخدم عندما
/// تكون نقطة النهاية نفسها هي من يتحقّق من الرمز (مثل transfer/initiate).
/// يرجع الرمز المكوَّن من 4 أرقام، أو null إن أغلق المستخدم الشاشة.
Future<String?> askAmialPinInput({String title = 'أدخل رمز PIN'}) async {
  // Get.to يُرجع Future<String?>? (قابلاً للـ null) — نجعلها async وننتظرها
  // حتى يتطابق نوع الإرجاع Future<String?>.
  return await Get.to<String>(
    () => _AmialPinInputScreen(title: title),
    fullscreenDialog: true,
  );
}

class _AmialPinInputScreen extends StatefulWidget {
  const _AmialPinInputScreen({required this.title});
  final String title;

  @override
  State<_AmialPinInputScreen> createState() => _AmialPinInputScreenState();
}

class _AmialPinInputScreenState extends State<_AmialPinInputScreen> {
  final TextEditingController _pin = TextEditingController();

  // AMIAL-SEC-CAPTURE-001: الرمز السري يُكتب هنا — نمنع لقطة الشاشة وتسجيلها
  // ما دامت الشاشة معروضة، ونُعيد السماح فور مغادرتها.
  @override
  void initState() {
    super.initState();
    SecureScreen.enable();
  }

  @override
  void dispose() {
    SecureScreen.disable();
    _pin.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmyalColors.background,
      appBar: AppBar(
        title: Text(widget.title),
        leading: IconButton(
          icon: const Icon(Icons.close),
          onPressed: () => Get.back(),
        ),
      ),
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(24),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            const SizedBox(height: 24),
            Container(
              height: 84,
              width: 84,
              alignment: Alignment.center,
              decoration: BoxDecoration(
                color: AmyalColors.primary.withValues(alpha: 0.1),
                shape: BoxShape.circle,
              ),
              child: const Icon(Icons.lock_outline_rounded,
                  color: AmyalColors.primary, size: 40),
            ),
            const SizedBox(height: 20),
            const Text(
              'أدخل رمز المعاملات (PIN) لتأكيد التحويل',
              textAlign: TextAlign.center,
              style: TextStyle(fontSize: 15, color: Color(0xFF5F6B7C)),
            ),
            const SizedBox(height: 24),
            AmialPinDots(controller: _pin),
            const SizedBox(height: 22),
            AmialNumpad(
              controller: _pin,
              maxLength: 4,
              rtl: true,
              onChanged: (v) {
                setState(() {});
                if (v.length == 4) Get.back(result: v);
              },
            ),
          ],
        ),
      ),
    );
  }
}

class _AmialPinGateScreen extends StatefulWidget {
  const _AmialPinGateScreen({required this.title});

  final String title;

  @override
  State<_AmialPinGateScreen> createState() => _AmialPinGateScreenState();
}

class _AmialPinGateScreenState extends State<_AmialPinGateScreen> {
  final TextEditingController _pin = TextEditingController();
  bool _checking = false;
  String _error = '';


  // AMIAL-SEC-CAPTURE-001: بوّابة الرمز السري — بلا تصوير ولا تسجيل شاشة.
  @override
  void initState() {
    super.initState();
    SecureScreen.enable();
  }

  @override
  void dispose() {
    SecureScreen.disable();
    _pin.dispose();
    super.dispose();
  }

  Future<void> _verify() async {
    if (_pin.text.length < 4) {
      setState(() => _error = 'أدخل رمز PIN المكوّن من 4 أرقام');
      return;
    }
    setState(() {
      _checking = true;
      _error = '';
    });
    try {
      final api = Get.find<ApiClient>();
      final r = await api.postData('/api/v1/amial/verify-pin', {'pin': _pin.text});
      final ok = r.statusCode == 200 &&
          r.body is Map &&
          r.body['success'] == true;
      if (ok) {
        Get.back(result: true);
        return;
      }
      String msg = 'رمز PIN غير صحيح';
      try {
        if (r.body is Map && r.body['message'] != null) {
          msg = '${r.body['message']}';
        }
      } catch (_) {}
      setState(() {
        _checking = false;
        _error = msg;
        _pin.clear();
      });
    } catch (_) {
      setState(() {
        _checking = false;
        _error = 'تعذّر الاتصال بالخادم — حاول مجدداً';
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmyalColors.background,
      // AMIAL-PIN-UI-002: بلا AppBar ملوّن — شاشة قفل، لا صفحة محتوى.
      body: SafeArea(
        child: SingleChildScrollView(
        padding: const EdgeInsets.fromLTRB(24, 8, 24, 24),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Align(
              alignment: Alignment.centerLeft,
              child: IconButton(
                icon: const Icon(Icons.close_rounded,
                    color: AmyalColors.textSecondary),
                onPressed: () => Get.back(result: false),
              ),
            ),
            const SizedBox(height: 4),
            Container(
              height: 84,
              width: 84,
              alignment: Alignment.center,
              decoration: BoxDecoration(
                color: AmyalColors.primary.withValues(alpha: 0.1),
                shape: BoxShape.circle,
              ),
              child: const Icon(Icons.lock_outline_rounded,
                  color: AmyalColors.primary, size: 40),
            ),
            const SizedBox(height: 18),
            Text(
              widget.title,
              textAlign: TextAlign.center,
              style: const TextStyle(
                  fontSize: 19,
                  fontWeight: FontWeight.bold,
                  color: Color(0xFF1A2433)),
            ),
            const SizedBox(height: 6),
            const Text(
              'أدخل رمزك السري للمتابعة',
              textAlign: TextAlign.center,
              style: TextStyle(fontSize: 13.5, color: AmyalColors.textSecondary),
            ),
            const SizedBox(height: 26),

            // AMIAL-PIN-UI-002: أربع نقاط تمتلئ مع الإدخال.
            AmialPinDots(controller: _pin, error: _error.isNotEmpty),
            const SizedBox(height: 22),
            AmialNumpad(
              controller: _pin,
              maxLength: 4,
              rtl: true,
              onChanged: (v) {
                setState(() {});
                if (v.length == 4) _verify();
              },
            ),
            if (_error.isNotEmpty) ...[
              const SizedBox(height: 12),
              Text(_error,
                  textAlign: TextAlign.center,
                  style: const TextStyle(color: AmyalColors.red, fontSize: 13)),
            ],
            const SizedBox(height: 24),
            ElevatedButton(
              onPressed: _checking ? null : _verify,
              style: ElevatedButton.styleFrom(
                backgroundColor: AmyalColors.primary,
                foregroundColor: Colors.white,
                padding: const EdgeInsets.symmetric(vertical: 15),
              ),
              child: _checking
                  ? const SizedBox(
                      height: 20,
                      width: 20,
                      child: CircularProgressIndicator(
                          color: Colors.white, strokeWidth: 2))
                  : const Text('تأكيد',
                      style: TextStyle(fontSize: 16, fontWeight: FontWeight.w600)),
            ),
          ],
        ),
      ),
      ),
    );
  }
}
