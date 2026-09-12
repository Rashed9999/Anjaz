import 'dart:async';

import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amial_pay/common/widgets/custom_logo_widget.dart';
import 'package:amial_pay/data/api/api_client.dart';
import 'package:amial_pay/theme/amial_colors.dart';
import 'package:amial_pay/util/app_direction.dart';

/// AMIAL-EMAIL-OTP-RECOVERY-001
///
/// شاشة استعادة موحّدة بالبريد للمرحلة التجريبية:
/// - كلمة المرور
/// - رمز PIN للمعاملات
///
/// تبقى مسارات SMS القديمة في المشروع ولا تُحذف. كما تدعم رمز PIN الذي
/// يطلقه فريق الدعم بعد موافقة ثنائية من غير أن يرى الموظف الرمز نفسه.
class ForgetPinScreen extends StatefulWidget {
  // نبقي الوسيطين للتوافق مع RouteHelper القديم؛ الاستعادة الحالية بالبريد.
  final String? phoneNumber, countryCode;
  const ForgetPinScreen({super.key, this.phoneNumber, this.countryCode});

  @override
  State<ForgetPinScreen> createState() => _ForgetPinScreenState();
}

class _ForgetPinScreenState extends State<ForgetPinScreen> {
  final _email = TextEditingController();
  final _otp = TextEditingController();
  final _secret = TextEditingController();
  final _confirm = TextEditingController();

  String _purpose = 'password_reset';
  String? _challengeId;
  String? _verificationToken;
  int _stage = 0;
  int _resendSeconds = 0;
  bool _busy = false;
  bool _obscure = true;
  Timer? _timer;

  bool get _pinMode => _purpose == 'pin_recovery';

  @override
  void dispose() {
    _timer?.cancel();
    _email.dispose();
    _otp.dispose();
    _secret.dispose();
    _confirm.dispose();
    super.dispose();
  }

  void _message(String text, {bool error = false}) {
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(text, textDirection: appTextDirection()),
        backgroundColor: error ? AmialColors.red : AmialColors.primary,
      ),
    );
  }

  String? _validEmail() {
    final email = _email.text.trim().toLowerCase();
    if (email.isEmpty) return null;
    final ok = RegExp(r'^[^@\s]+@[^@\s]+\.[^@\s]+$').hasMatch(email);
    return ok ? email : null;
  }

  String _responseMessage(Response r, String fallback) {
    try {
      if (r.body is Map && r.body['message'] != null) {
        return '${r.body['message']}';
      }
      if (r.body is Map && r.body['errors'] is Map) {
        final values = (r.body['errors'] as Map).values;
        if (values.isNotEmpty) return '${values.first}';
      }
    } catch (_) {}
    return fallback;
  }

  void _startResend(int seconds) {
    _timer?.cancel();
    setState(() => _resendSeconds = seconds);
    _timer = Timer.periodic(const Duration(seconds: 1), (timer) {
      if (!mounted) {
        timer.cancel();
        return;
      }
      if (_resendSeconds <= 1) {
        timer.cancel();
        setState(() => _resendSeconds = 0);
      } else {
        setState(() => _resendSeconds--);
      }
    });
  }

  Future<void> _requestCode() async {
    final email = _validEmail();
    if (email == null) {
      _message('أدخل بريداً إلكترونياً صحيحاً', error: true);
      return;
    }

    setState(() => _busy = true);
    try {
      final r = await Get.find<ApiClient>().postData(
        '/api/v1/auth/email-otp/request',
        {'email': email, 'purpose': _purpose},
      );
      if ((r.statusCode ?? 500) < 200 || (r.statusCode ?? 500) >= 300) {
        _message(_responseMessage(r, 'تعذر إرسال رمز التحقق'), error: true);
        return;
      }

      final meta = r.body is Map && r.body['meta'] is Map
          ? Map<String, dynamic>.from(r.body['meta'])
          : <String, dynamic>{};
      _challengeId = meta['challenge_id']?.toString();
      final resend = int.tryParse('${meta['resend_after_seconds'] ?? 60}') ?? 60;
      _otp.clear();
      setState(() => _stage = 1);
      _startResend(resend);
      _message('تم إرسال رمز من 6 أرقام إلى بريدك. صلاحيته 5 دقائق.');
    } catch (_) {
      _message('تعذر الاتصال بالخادم', error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  void _useSupportCode() {
    final email = _validEmail();
    if (email == null) {
      _message('أدخل بريد الحساب أولاً', error: true);
      return;
    }
    _challengeId = null; // الخادم يلتقط أحدث تحدٍ نشط أطلقه الدعم لهذا البريد.
    _otp.clear();
    setState(() => _stage = 1);
    _message('أدخل الرمز الذي وصلك من أميال. فريق الدعم لا يستطيع رؤيته.');
  }

  Future<void> _verifyCode() async {
    final email = _validEmail();
    final code = _otp.text.trim();
    if (email == null || !RegExp(r'^\d{6}$').hasMatch(code)) {
      _message('أدخل رمز التحقق المكوّن من 6 أرقام', error: true);
      return;
    }

    setState(() => _busy = true);
    try {
      final body = <String, dynamic>{
        'email': email,
        'purpose': _purpose,
        'otp': code,
        if (_challengeId != null) 'challenge_id': _challengeId,
      };
      final r = await Get.find<ApiClient>().postData(
        '/api/v1/auth/email-otp/verify',
        body,
      );
      if ((r.statusCode ?? 500) < 200 || (r.statusCode ?? 500) >= 300) {
        _message(_responseMessage(r, 'رمز التحقق غير صحيح'), error: true);
        return;
      }

      final meta = r.body is Map && r.body['meta'] is Map
          ? Map<String, dynamic>.from(r.body['meta'])
          : <String, dynamic>{};
      _challengeId = meta['challenge_id']?.toString() ?? _challengeId;
      _verificationToken = meta['verification_token']?.toString();
      if (_challengeId == null || _verificationToken == null) {
        _message('تعذر إنشاء جلسة الاستعادة', error: true);
        return;
      }

      _secret.clear();
      _confirm.clear();
      setState(() => _stage = 2);
    } catch (_) {
      _message('تعذر الاتصال بالخادم', error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _finishRecovery() async {
    final email = _validEmail();
    if (email == null || _challengeId == null || _verificationToken == null) {
      _message('انتهت جلسة التحقق. ابدأ من جديد.', error: true);
      setState(() => _stage = 0);
      return;
    }

    final value = _secret.text.trim();
    final confirm = _confirm.text.trim();
    final formatOk = _pinMode
        ? RegExp(r'^\d{4,6}$').hasMatch(value)
        : RegExp(r'^\d{4}$').hasMatch(value);
    if (!formatOk) {
      _message(
        _pinMode
            ? 'رمز PIN يجب أن يكون من 4 إلى 6 أرقام'
            : 'كلمة المرور الحالية في أميال تتكون من 4 أرقام',
        error: true,
      );
      return;
    }
    if (value != confirm) {
      _message('القيمتان غير متطابقتين', error: true);
      return;
    }

    setState(() => _busy = true);
    try {
      final endpoint = _pinMode
          ? '/api/v1/auth/pin-recovery/email'
          : '/api/v1/auth/password-reset/email';
      final body = <String, dynamic>{
        'challenge_id': _challengeId,
        'email': email,
        'verification_token': _verificationToken,
        if (_pinMode) 'new_pin': value else 'password': value,
        if (_pinMode) 'new_pin_confirmation': confirm else 'password_confirmation': confirm,
      };

      final r = await Get.find<ApiClient>().postData(endpoint, body);
      if ((r.statusCode ?? 500) < 200 || (r.statusCode ?? 500) >= 300) {
        _message(_responseMessage(r, 'تعذر إكمال الاستعادة'), error: true);
        return;
      }

      setState(() => _stage = 3);
    } catch (_) {
      _message('تعذر الاتصال بالخادم', error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: Colors.white,
      appBar: AppBar(
        title: Text(_pinMode ? 'استعادة رمز PIN' : 'استعادة كلمة المرور'),
        backgroundColor: Colors.white,
        elevation: 0,
      ),
      body: SafeArea(
        child: Center(
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 520),
            child: SingleChildScrollView(
              padding: const EdgeInsets.all(20),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  const Center(child: CustomLogoWidget(height: 54)),
                  const SizedBox(height: 22),
                  if (_stage == 0) _startCard(),
                  if (_stage == 1) _otpCard(),
                  if (_stage == 2) _newSecretCard(),
                  if (_stage == 3) _successCard(),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }

  Widget _startCard() => Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          const Text(
            'استعادة آمنة عبر البريد الإلكتروني',
            textAlign: TextAlign.center,
            style: TextStyle(fontSize: 22, fontWeight: FontWeight.w700),
          ),
          const SizedBox(height: 8),
          const Text(
            'لن يرى فريق الدعم رمز التحقق أو كلمة المرور أو رمز PIN الجديد.',
            textAlign: TextAlign.center,
            style: TextStyle(color: AmialColors.textMuted, height: 1.5),
          ),
          const SizedBox(height: 22),
          SegmentedButton<String>(
            segments: const [
              ButtonSegment(
                value: 'password_reset',
                label: Text('كلمة المرور'),
                icon: Icon(Icons.lock_outline),
              ),
              ButtonSegment(
                value: 'pin_recovery',
                label: Text('رمز PIN'),
                icon: Icon(Icons.pin_outlined),
              ),
            ],
            selected: {_purpose},
            onSelectionChanged: _busy
                ? null
                : (s) => setState(() => _purpose = s.first),
          ),
          const SizedBox(height: 20),
          TextField(
            controller: _email,
            keyboardType: TextInputType.emailAddress,
            textDirection: TextDirection.ltr,
            autocorrect: false,
            decoration: const InputDecoration(
              labelText: 'البريد الإلكتروني المسجل في الحساب',
              hintText: 'name@example.com',
              border: OutlineInputBorder(),
              prefixIcon: Icon(Icons.email_outlined),
            ),
          ),
          const SizedBox(height: 16),
          FilledButton.icon(
            onPressed: _busy ? null : _requestCode,
            icon: _busy
                ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2))
                : const Icon(Icons.send_outlined),
            label: const Text('إرسال رمز التحقق'),
          ),
          if (_pinMode) ...[
            const SizedBox(height: 10),
            OutlinedButton.icon(
              onPressed: _busy ? null : _useSupportCode,
              icon: const Icon(Icons.support_agent_outlined),
              label: const Text('لدي رمز أرسله فريق الدعم'),
            ),
          ],
        ],
      );

  Widget _otpCard() => Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          const Text(
            'أدخل رمز التحقق',
            textAlign: TextAlign.center,
            style: TextStyle(fontSize: 22, fontWeight: FontWeight.w700),
          ),
          const SizedBox(height: 8),
          Text(
            'أرسلنا رمزاً من 6 أرقام إلى ${_email.text.trim()}. صلاحيته 5 دقائق ويعمل مرة واحدة فقط.',
            textAlign: TextAlign.center,
            style: const TextStyle(color: AmialColors.textMuted, height: 1.5),
          ),
          const SizedBox(height: 22),
          TextField(
            controller: _otp,
            keyboardType: TextInputType.number,
            textAlign: TextAlign.center,
            maxLength: 6,
            style: const TextStyle(fontSize: 26, letterSpacing: 8, fontWeight: FontWeight.w700),
            decoration: const InputDecoration(
              labelText: 'رمز التحقق',
              counterText: '',
              border: OutlineInputBorder(),
            ),
          ),
          const SizedBox(height: 16),
          FilledButton(
            onPressed: _busy ? null : _verifyCode,
            child: _busy
                ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2))
                : const Text('تحقق'),
          ),
          const SizedBox(height: 10),
          TextButton(
            onPressed: _busy || _resendSeconds > 0 ? null : _requestCode,
            child: Text(
              _resendSeconds > 0
                  ? 'إعادة الإرسال بعد $_resendSeconds ثانية'
                  : 'إعادة إرسال الرمز',
            ),
          ),
          TextButton(
            onPressed: _busy ? null : () => setState(() => _stage = 0),
            child: const Text('تغيير البريد أو نوع الاستعادة'),
          ),
        ],
      );

  Widget _newSecretCard() => Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(
            _pinMode ? 'اختر رمز PIN جديداً' : 'اختر كلمة مرور جديدة',
            textAlign: TextAlign.center,
            style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w700),
          ),
          const SizedBox(height: 10),
          Text(
            _pinMode
                ? 'لا يعرف فريق الدعم رمزك القديم أو الجديد.'
                : 'بعد التغيير سيتم إنهاء الجلسات المسجلة لحماية حسابك.',
            textAlign: TextAlign.center,
            style: const TextStyle(color: AmialColors.textMuted),
          ),
          const SizedBox(height: 22),
          TextField(
            controller: _secret,
            keyboardType: TextInputType.number,
            obscureText: _obscure,
            maxLength: _pinMode ? 6 : 4,
            decoration: InputDecoration(
              labelText: _pinMode ? 'رمز PIN الجديد' : 'كلمة المرور الجديدة',
              counterText: '',
              border: const OutlineInputBorder(),
              suffixIcon: IconButton(
                onPressed: () => setState(() => _obscure = !_obscure),
                icon: Icon(_obscure ? Icons.visibility_outlined : Icons.visibility_off_outlined),
              ),
            ),
          ),
          const SizedBox(height: 12),
          TextField(
            controller: _confirm,
            keyboardType: TextInputType.number,
            obscureText: _obscure,
            maxLength: _pinMode ? 6 : 4,
            decoration: InputDecoration(
              labelText: _pinMode ? 'تأكيد رمز PIN' : 'تأكيد كلمة المرور',
              counterText: '',
              border: const OutlineInputBorder(),
            ),
          ),
          const SizedBox(height: 16),
          FilledButton(
            onPressed: _busy ? null : _finishRecovery,
            child: _busy
                ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2))
                : Text(_pinMode ? 'تعيين رمز PIN' : 'تغيير كلمة المرور'),
          ),
        ],
      );

  Widget _successCard() => Column(
        children: [
          const Icon(Icons.verified_user_outlined, size: 72, color: AmialColors.primary),
          const SizedBox(height: 18),
          Text(
            _pinMode ? 'تم تغيير رمز PIN بنجاح' : 'تم تغيير كلمة المرور بنجاح',
            textAlign: TextAlign.center,
            style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w700),
          ),
          const SizedBox(height: 10),
          const Text(
            'لحماية الحساب تم إنهاء الجلسات المسجلة. يمكنك تسجيل الدخول من جديد.',
            textAlign: TextAlign.center,
            style: TextStyle(color: AmialColors.textMuted, height: 1.5),
          ),
          const SizedBox(height: 24),
          SizedBox(
            width: double.infinity,
            child: FilledButton(
              onPressed: () => Get.back(),
              child: const Text('العودة لتسجيل الدخول'),
            ),
          ),
        ],
      );
}