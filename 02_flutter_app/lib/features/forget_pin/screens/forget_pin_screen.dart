import 'dart:async';
import 'package:amial_pay/util/app_direction.dart';
import 'package:amial_pay/features/auth/domain/reposotories/auth_repo.dart';
import 'package:amial_pay/helper/route_helper.dart';

import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amial_pay/common/widgets/custom_logo_widget.dart';
import 'package:amial_pay/data/api/api_client.dart';
import 'package:amial_pay/theme/amial_colors.dart';

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
  int _codeTtlSeconds = 300;
  DateTime? _verificationExpiresAt;
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
    if (_busy || _resendSeconds > 0) return;
    final email = _validEmail();
    if (email == null) {
      _message('email_otp_valid_email'.tr, error: true);
      return;
    }

    setState(() => _busy = true);
    try {
      final r = await Get.find<ApiClient>().postData(
        '/api/v1/auth/email-otp/request',
        {'email': email, 'purpose': _purpose},
      );
      if (!mounted) return;
      if (r.statusCode != 200 || r.body is! Map || r.body['success'] != true) {
        _message(_responseMessage(r, 'email_otp_send_failed'.tr), error: true);
        return;
      }

      final meta = r.body is Map && r.body['meta'] is Map
          ? Map<String, dynamic>.from(r.body['meta'])
          : <String, dynamic>{};
      final challenge = meta['challenge_id']?.toString();
      final resend = int.tryParse('${meta['resend_after_seconds']}');
      final expires = int.tryParse('${meta['expires_in_seconds']}');
      if (challenge == null || challenge.length != 26 || resend == null || expires == null || expires <= 0) {
        _message('email_otp_invalid_session'.tr, error: true);
        return;
      }
      _challengeId = challenge;
      _verificationToken = null;
      _verificationExpiresAt = null;
      _codeTtlSeconds = expires;
      _otp.clear();
      setState(() => _stage = 1);
      _startResend(resend);
      _message(_responseMessage(r, 'email_otp_conditional_notice'.tr));
    } catch (_) {
      _message('email_otp_connection_failed'.tr, error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  void _useSupportCode() {
    final email = _validEmail();
    if (email == null) {
      _message('email_otp_email_first'.tr, error: true);
      return;
    }
    _challengeId = null; // الخادم يلتقط أحدث تحدٍ نشط أطلقه الدعم لهذا البريد.
    _verificationToken = null;
    _verificationExpiresAt = null;
    _otp.clear();
    setState(() => _stage = 1);
    _message('email_otp_support_hint'.tr);
  }

  Future<void> _verifyCode() async {
    if (_busy) return;
    final email = _validEmail();
    final code = _otp.text.trim();
    if (email == null || !RegExp(r'^\d{6}$').hasMatch(code)) {
      _message('email_otp_six_digits'.tr, error: true);
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
      if (!mounted) return;
      if (r.statusCode != 200 || r.body is! Map || r.body['success'] != true) {
        _message(_responseMessage(r, 'email_otp_wrong_code'.tr), error: true);
        return;
      }

      final meta = r.body is Map && r.body['meta'] is Map
          ? Map<String, dynamic>.from(r.body['meta'])
          : <String, dynamic>{};
      _challengeId = meta['challenge_id']?.toString() ?? _challengeId;
      _verificationToken = meta['verification_token']?.toString();
      final expires = int.tryParse('${meta['verification_expires_in_seconds']}');
      if (_challengeId?.length != 26 || (_verificationToken?.length ?? 0) < 32 || expires == null || expires <= 0) {
        _message('email_otp_invalid_session'.tr, error: true);
        return;
      }
      _verificationExpiresAt = DateTime.now().add(Duration(seconds: expires));

      _secret.clear();
      _confirm.clear();
      setState(() => _stage = 2);
    } catch (_) {
      _message('email_otp_connection_failed'.tr, error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _finishRecovery() async {
    if (_busy) return;
    final email = _validEmail();
    if (email == null || _challengeId == null || _verificationToken == null
        || _verificationExpiresAt == null || !DateTime.now().isBefore(_verificationExpiresAt!)) {
      _message('email_otp_expired_session'.tr, error: true);
      setState(() => _stage = 0);
      return;
    }

    final value = _pinMode ? _secret.text.trim() : _secret.text;
    final confirm = _pinMode ? _confirm.text.trim() : _confirm.text;
    final formatOk = _pinMode
        ? RegExp(r'^\d{4,6}$').hasMatch(value)
        : value.length >= 4 && value.length <= 64;
    if (!formatOk) {
      _message(
        _pinMode
            ? 'email_otp_pin_format'.tr
            : 'email_otp_password_format'.tr,
        error: true,
      );
      return;
    }
    if (value != confirm) {
      _message('email_otp_mismatch'.tr, error: true);
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
      if (!mounted) return;
      if (r.statusCode != 200 || r.body is! Map || r.body['success'] != true) {
        _message(_responseMessage(r, 'email_otp_action_failed'.tr), error: true);
        if (r.statusCode == 409 || r.statusCode == 410) {
          _verificationToken = null;
          _verificationExpiresAt = null;
          setState(() => _stage = 0);
        }
        return;
      }

      final repo = Get.find<AuthRepo>();
      await repo.removeUserToken();
      repo.removeUserData();
      if (!mounted) return;
      setState(() => _stage = 3);
    } catch (_) {
      _message('email_otp_connection_failed'.tr, error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: Colors.white,
      appBar: AppBar(
        title: Text(_pinMode ? 'email_otp_pin_recovery'.tr : 'email_otp_password_recovery'.tr),
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
          Text(
            'email_otp_secure_recovery'.tr,
            textAlign: TextAlign.center,
            style: TextStyle(fontSize: 22, fontWeight: FontWeight.w700),
          ),
          const SizedBox(height: 8),
          Text(
            'email_otp_support_privacy'.tr,
            textAlign: TextAlign.center,
            style: TextStyle(color: AmialColors.textMuted, height: 1.5),
          ),
          const SizedBox(height: 22),
          SegmentedButton<String>(
            segments: [
              ButtonSegment(
                value: 'password_reset',
                label: Text('email_otp_password'.tr),
                icon: Icon(Icons.lock_outline),
              ),
              ButtonSegment(
                value: 'pin_recovery',
                label: Text('email_otp_pin'.tr),
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
            enabled: !_busy,
            keyboardType: TextInputType.emailAddress,
            textDirection: TextDirection.ltr,
            autocorrect: false,
            decoration: InputDecoration(
              labelText: 'email_otp_account_email'.tr,
              hintText: 'name@example.com',
              border: OutlineInputBorder(),
              prefixIcon: Icon(Icons.email_outlined),
            ),
          ),
          const SizedBox(height: 16),
          FilledButton.icon(
            onPressed: _busy || _resendSeconds > 0 ? null : _requestCode,
            icon: _busy
                ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2))
                : const Icon(Icons.send_outlined),
            label: Text(_resendSeconds > 0
                ? 'email_otp_resend_wait'.trParams({'seconds': '$_resendSeconds'})
                : 'email_otp_send'.tr),
          ),
          if (_pinMode) ...[
            const SizedBox(height: 10),
            OutlinedButton.icon(
              onPressed: _busy ? null : _useSupportCode,
              icon: const Icon(Icons.support_agent_outlined),
              label: Text('email_otp_support_code'.tr),
            ),
          ],
        ],
      );

  Widget _otpCard() => Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(
            'email_otp_enter_code'.tr,
            textAlign: TextAlign.center,
            style: TextStyle(fontSize: 22, fontWeight: FontWeight.w700),
          ),
          const SizedBox(height: 8),
          Text(
            'email_otp_inbox_hint'.trParams({'email': _email.text.trim(), 'seconds': '$_codeTtlSeconds'}),
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
            decoration: InputDecoration(
              labelText: 'email_otp_code'.tr,
              counterText: '',
              border: OutlineInputBorder(),
            ),
          ),
          const SizedBox(height: 16),
          FilledButton(
            onPressed: _busy ? null : _verifyCode,
            child: _busy
                ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2))
                : Text('email_otp_verify'.tr),
          ),
          const SizedBox(height: 10),
          TextButton(
            onPressed: _busy || _resendSeconds > 0 ? null : _requestCode,
            child: Text(
              _resendSeconds > 0
                  ? 'email_otp_resend_wait'.trParams({'seconds': '$_resendSeconds'})
                  : 'email_otp_resend'.tr,
            ),
          ),
          TextButton(
            onPressed: _busy ? null : () => setState(() => _stage = 0),
            child: Text('email_otp_change_recovery'.tr),
          ),
        ],
      );

  Widget _newSecretCard() => Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(
            _pinMode ? 'email_otp_choose_pin'.tr : 'email_otp_choose_password'.tr,
            textAlign: TextAlign.center,
            style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w700),
          ),
          const SizedBox(height: 10),
          Text(
            _pinMode
                ? 'email_otp_pin_privacy'.tr
                : 'email_otp_session_notice'.tr,
            textAlign: TextAlign.center,
            style: const TextStyle(color: AmialColors.textMuted),
          ),
          const SizedBox(height: 22),
          TextField(
            controller: _secret,
            keyboardType: _pinMode ? TextInputType.number : TextInputType.visiblePassword,
            obscureText: _obscure,
            maxLength: _pinMode ? 6 : 64,
            decoration: InputDecoration(
              labelText: _pinMode ? 'email_otp_new_pin'.tr : 'email_otp_new_password'.tr,
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
            keyboardType: _pinMode ? TextInputType.number : TextInputType.visiblePassword,
            obscureText: _obscure,
            maxLength: _pinMode ? 6 : 64,
            decoration: InputDecoration(
              labelText: _pinMode ? 'email_otp_confirm_pin'.tr : 'email_otp_confirm_password'.tr,
              counterText: '',
              border: const OutlineInputBorder(),
            ),
          ),
          const SizedBox(height: 16),
          FilledButton(
            onPressed: _busy ? null : _finishRecovery,
            child: _busy
                ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2))
                : Text(_pinMode ? 'email_otp_set_pin'.tr : 'email_otp_change_password'.tr),
          ),
          TextButton(
            onPressed: _busy ? null : () => setState(() {
              _stage = 0;
              _verificationToken = null;
              _verificationExpiresAt = null;
            }),
            child: Text('email_otp_change_recovery'.tr),
          ),
        ],
      );

  Widget _successCard() => Column(
        children: [
          const Icon(Icons.verified_user_outlined, size: 72, color: AmialColors.primary),
          const SizedBox(height: 18),
          Text(
            _pinMode ? 'email_otp_pin_success'.tr : 'email_otp_password_success'.tr,
            textAlign: TextAlign.center,
            style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w700),
          ),
          const SizedBox(height: 10),
          Text(
            'email_otp_sessions_ended'.tr,
            textAlign: TextAlign.center,
            style: TextStyle(color: AmialColors.textMuted, height: 1.5),
          ),
          const SizedBox(height: 24),
          SizedBox(
            width: double.infinity,
            child: FilledButton(
              onPressed: () => Get.offAllNamed(RouteHelper.getUnifiedLoginRoute()),
              child: Text('email_otp_back_login'.tr),
            ),
          ),
        ],
      );
}
