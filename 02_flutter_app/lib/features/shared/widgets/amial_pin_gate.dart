import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amial_pay/data/api/api_client.dart';
import 'package:amial_pay/features/shared/widgets/amial_numpad.dart';
import 'package:amial_pay/features/shared/widgets/amial_pin_dots.dart';
import 'package:amial_pay/theme/amial_colors.dart';
import 'package:amial_pay/util/secure_screen.dart';

/// AMIAL-PIN-GATE-001 + AMIAL-PIN-LENGTH-UNIFY-001
///
/// بوّابة رمز المعاملات (PIN) الموحّدة — تُعرض بعد الدخول وقبل كل عملية مالية
/// (تحويل/سحب/صندوق عائلي/دفع آمن…). تتحقّق من الرمز عبر الخادم
/// (POST /api/v1/amial/verify-pin) وتُرجع true عند النجاح.
///
/// تدعم الرموز من 4 إلى 6 أرقام لتتطابق مع عقد تفعيل الحساب المعتمد من موثّق
/// (`ActivateAccountScreen`) واستعادة الرمز (`ForgetPinScreen`) وأمان الحساب.
Future<bool> askAmialPin({String title = 'أدخل رمز PIN'}) async {
  final result = await Get.to<bool>(
    () => _AmialPinGateScreen(title: title),
    fullscreenDialog: true,
  );
  return result == true;
}

/// جمع رمز PIN فقط (بلا تحقق مسبق من الخادم) — يُستخدم عندما تكون نقطة النهاية
/// نفسها هي من يتحقّق من الرمز (مثل transfer/initiate). يدعم 4 إلى 6 أرقام.
Future<String?> askAmialPinInput({String title = 'أدخل رمز PIN'}) async {
  return await Get.to<String>(
    () => _AmialPinInputScreen(title: title),
    fullscreenDialog: true,
  );
}

int _effectiveDotsCount(String value) => value.length > 4 ? value.length : 4;

class _AmialPinInputScreen extends StatefulWidget {
  const _AmialPinInputScreen({required this.title});
  final String title;

  @override
  State<_AmialPinInputScreen> createState() => _AmialPinInputScreenState();
}

class _AmialPinInputScreenState extends State<_AmialPinInputScreen> {
  final TextEditingController _pin = TextEditingController();

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

  void _confirm() {
    if (_pin.text.length < 4) return;
    Get.back(result: _pin.text);
  }

  @override
  Widget build(BuildContext context) {
    final canConfirm = _pin.text.length >= 4;
    return Scaffold(
      backgroundColor: AmialColors.background,
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
                color: AmialColors.primary.withValues(alpha: 0.1),
                shape: BoxShape.circle,
              ),
              child: const Icon(
                Icons.lock_outline_rounded,
                color: AmialColors.primary,
                size: 40,
              ),
            ),
            const SizedBox(height: 20),
            const Text(
              'أدخل رمز المعاملات (4 إلى 6 أرقام) ثم اضغط تأكيد',
              textAlign: TextAlign.center,
              style: TextStyle(fontSize: 15, color: Color(0xFF5F6B7C)),
            ),
            const SizedBox(height: 24),
            AmialPinDots(
              controller: _pin,
              length: _effectiveDotsCount(_pin.text),
            ),
            const SizedBox(height: 22),
            AmialNumpad(
              controller: _pin,
              maxLength: 6,
              compact: true,
              onChanged: (v) {
                setState(() {});
                if (v.length == 6) _confirm();
              },
            ),
            const SizedBox(height: 24),
            ElevatedButton(
              onPressed: canConfirm ? _confirm : null,
              style: ElevatedButton.styleFrom(
                backgroundColor: AmialColors.primary,
                foregroundColor: Colors.white,
                padding: const EdgeInsets.symmetric(vertical: 15),
              ),
              child: const Text(
                'تأكيد',
                style: TextStyle(fontSize: 16, fontWeight: FontWeight.w600),
              ),
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
    if (_checking) return;
    if (_pin.text.length < 4) {
      setState(() => _error = 'أدخل رمز PIN المكوّن من 4 إلى 6 أرقام');
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
        if (mounted) Get.back(result: true);
        return;
      }
      String msg = 'رمز PIN غير صحيح';
      try {
        if (r.body is Map && r.body['message'] != null) {
          msg = '${r.body['message']}';
        }
      } catch (_) {}
      if (!mounted) return;
      setState(() {
        _checking = false;
        _error = msg;
        _pin.clear();
      });
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _checking = false;
        _error = 'تعذّر الاتصال بالخادم — تحقّق من الشبكة ثم حاول مجدداً';
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final canVerify = !_checking && _pin.text.length >= 4;
    return Scaffold(
      backgroundColor: AmialColors.background,
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(24, 8, 24, 24),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Align(
                alignment: Alignment.centerLeft,
                child: IconButton(
                  icon: const Icon(
                    Icons.close_rounded,
                    color: AmialColors.textSecondary,
                  ),
                  onPressed: () => Get.back(result: false),
                ),
              ),
              const SizedBox(height: 4),
              Container(
                height: 84,
                width: 84,
                alignment: Alignment.center,
                decoration: BoxDecoration(
                  color: AmialColors.primary.withValues(alpha: 0.1),
                  shape: BoxShape.circle,
                ),
                child: const Icon(
                  Icons.lock_outline_rounded,
                  color: AmialColors.primary,
                  size: 40,
                ),
              ),
              const SizedBox(height: 18),
              Text(
                widget.title,
                textAlign: TextAlign.center,
                style: const TextStyle(
                  fontSize: 19,
                  fontWeight: FontWeight.bold,
                  color: Color(0xFF1A2433),
                ),
              ),
              const SizedBox(height: 6),
              const Text(
                'أدخل رمزك السري (4 إلى 6 أرقام) ثم اضغط تأكيد',
                textAlign: TextAlign.center,
                style: TextStyle(fontSize: 13.5, color: AmialColors.textSecondary),
              ),
              const SizedBox(height: 26),
              AmialPinDots(
                controller: _pin,
                length: _effectiveDotsCount(_pin.text),
                error: _error.isNotEmpty,
              ),
              const SizedBox(height: 22),
              AmialNumpad(
                controller: _pin,
                maxLength: 6,
                compact: true,
                onChanged: (v) {
                  setState(() {
                    if (_error.isNotEmpty && v.isNotEmpty) _error = '';
                  });
                  if (v.length == 6) _verify();
                },
              ),
              if (_error.isNotEmpty) ...[
                const SizedBox(height: 12),
                Text(
                  _error,
                  textAlign: TextAlign.center,
                  style: const TextStyle(color: AmialColors.red, fontSize: 13),
                ),
              ],
              const SizedBox(height: 24),
              ElevatedButton(
                onPressed: canVerify ? _verify : null,
                style: ElevatedButton.styleFrom(
                  backgroundColor: AmialColors.primary,
                  foregroundColor: Colors.white,
                  padding: const EdgeInsets.symmetric(vertical: 15),
                ),
                child: _checking
                    ? const SizedBox(
                        height: 20,
                        width: 20,
                        child: CircularProgressIndicator(
                          color: Colors.white,
                          strokeWidth: 2,
                        ),
                      )
                    : const Text(
                        'تأكيد',
                        style: TextStyle(fontSize: 16, fontWeight: FontWeight.w600),
                      ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
