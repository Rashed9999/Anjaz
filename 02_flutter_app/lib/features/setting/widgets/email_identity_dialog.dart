import 'dart:async';

import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amial_pay/data/api/api_client.dart';

/// Keeps the challenge on screen after a bad code or network failure so the
/// owner can retry or resend without starting the password step again.
class EmailIdentityDialog extends StatefulWidget {
  final String initialEmail;
  const EmailIdentityDialog({super.key, required this.initialEmail});

  @override
  State<EmailIdentityDialog> createState() => _EmailIdentityDialogState();
}

class _EmailIdentityDialogState extends State<EmailIdentityDialog> {
  final _form = GlobalKey<FormState>();
  late final _email = TextEditingController(text: widget.initialEmail);
  final _password = TextEditingController();
  final _code = TextEditingController();
  String? _challenge;
  String? _error;
  bool _busy = false;
  int _resend = 0;
  Timer? _timer;

  @override
  void dispose() {
    _timer?.cancel();
    _email.dispose();
    _password.dispose();
    _code.dispose();
    super.dispose();
  }

  void _countdown(int seconds) {
    _timer?.cancel();
    setState(() => _resend = seconds.clamp(0, 3600).toInt());
    _timer = Timer.periodic(const Duration(seconds: 1), (timer) {
      if (!mounted || _resend <= 1) {
        timer.cancel();
        if (mounted) setState(() => _resend = 0);
      } else {
        setState(() => _resend--);
      }
    });
  }

  void _failure(Response response) {
    final body = response.body;
    setState(() => _error = body is Map && body['message'] is String
        ? body['message'] as String : 'email_otp_action_failed'.tr);
    if (body is Map && body['meta'] is Map) {
      final retry = int.tryParse('${body['meta']['retry_after'] ?? ''}');
      if (retry != null) _countdown(retry);
    }
  }

  Future<void> _requestCode() async {
    if (_busy || _resend > 0 || (_challenge == null && _form.currentState?.validate() != true)) return;
    setState(() { _busy = true; _error = null; });
    try {
      final response = await Get.find<ApiClient>().postData('/api/v1/auth/email-change/request', {
        'new_email': _email.text.trim().toLowerCase(),
        'current_password': _password.text,
      });
      if (!mounted) return;
      final body = response.body;
      if (response.statusCode != 200 || body is! Map || body['success'] != true) {
        _failure(response);
        return;
      }
      final meta = body['meta'];
      final id = meta is Map ? meta['challenge_id']?.toString() : null;
      final seconds = meta is Map ? int.tryParse('${meta['resend_after_seconds']}') : null;
      if (id == null || id.length != 26 || seconds == null || meta['delivery_status'] != 'sent') {
        setState(() => _error = 'email_otp_invalid_session'.tr);
        return;
      }
      setState(() { _challenge = id; _code.clear(); });
      _countdown(seconds);
    } catch (_) {
      if (mounted) setState(() => _error = 'email_otp_connection_failed'.tr);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _confirm() async {
    if (_busy || _challenge == null || _form.currentState?.validate() != true) return;
    setState(() { _busy = true; _error = null; });
    try {
      final response = await Get.find<ApiClient>().postData('/api/v1/auth/email-change/confirm', {
        'challenge_id': _challenge,
        'new_email': _email.text.trim().toLowerCase(),
        'otp': _code.text.trim(),
      });
      if (!mounted) return;
      if (response.statusCode != 200 || response.body is! Map || response.body['success'] != true) {
        _failure(response);
        return;
      }
      Navigator.of(context).pop(true);
    } catch (_) {
      if (mounted) setState(() => _error = 'email_otp_connection_failed'.tr);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => PopScope(
    canPop: !_busy,
    child: AlertDialog(
      title: Text('email_identity_manage'.tr),
      content: SingleChildScrollView(
        child: Form(
          key: _form,
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            Text('email_identity_help'.tr),
            const SizedBox(height: 16),
            TextFormField(
              controller: _email,
              readOnly: _busy || _challenge != null,
              keyboardType: TextInputType.emailAddress,
              textDirection: TextDirection.ltr,
              autocorrect: false,
              decoration: InputDecoration(labelText: 'email_identity_address'.tr),
              validator: (value) => RegExp(r'^[^@\s]+@[^@\s]+\.[^@\s]+$').hasMatch((value ?? '').trim())
                  ? null : 'email_otp_valid_email'.tr,
            ),
            if (_challenge == null) TextFormField(
              controller: _password,
              enabled: !_busy,
              obscureText: true,
              decoration: InputDecoration(labelText: 'email_identity_password'.tr),
              validator: (value) => value == null || value.isEmpty ? 'email_identity_password_required'.tr : null,
            ),
            if (_challenge != null) ...[
              const SizedBox(height: 12),
              TextFormField(
                controller: _code,
                enabled: !_busy,
                keyboardType: TextInputType.number,
                autofillHints: const [AutofillHints.oneTimeCode],
                maxLength: 6,
                decoration: InputDecoration(labelText: 'email_otp_code'.tr),
                validator: (value) => RegExp(r'^\d{6}$').hasMatch((value ?? '').trim()) ? null : 'email_otp_six_digits'.tr,
              ),
              TextButton(
                onPressed: _busy || _resend > 0 ? null : _requestCode,
                child: Text(_resend > 0 ? 'email_otp_resend_wait'.trParams({'seconds': '$_resend'}) : 'email_otp_resend'.tr),
              ),
            ],
            if (_error != null) Text(_error!, style: TextStyle(color: Theme.of(context).colorScheme.error)),
          ]),
        ),
      ),
      actions: [
        TextButton(onPressed: _busy ? null : () => Navigator.of(context).pop(false), child: Text('email_otp_cancel'.tr)),
        FilledButton(
          onPressed: _busy || (_challenge == null && _resend > 0) ? null : _challenge == null ? _requestCode : _confirm,
          child: _busy
              ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2))
              : Text(_challenge == null ? 'email_otp_send'.tr : 'email_identity_confirm'.tr),
        ),
      ],
    ),
  );
}
