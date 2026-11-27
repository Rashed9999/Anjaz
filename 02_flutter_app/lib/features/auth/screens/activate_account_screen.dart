import 'dart:async';

import 'package:amial_pay/common/widgets/amial_brand_logo.dart';
import 'package:amial_pay/features/auth/controllers/unified_auth_controller.dart';
import 'package:amial_pay/helper/arabic_digits_input_formatter.dart';
import 'package:amial_pay/theme/amial_colors.dart';
import 'package:amial_pay/theme/amial_spacing.dart';
import 'package:amial_pay/util/app_direction.dart';
import 'package:amial_pay/util/secure_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:get/get.dart';

/// تفعيل حسابٍ اعتمده موثّق — من داخل التطبيق.
///
/// بعد اعتماد الطلب يصل العميلَ بريدٌ فيه رمزٌ من ست خانات. هنا يُدخل بريدَه والرمز،
/// ويختار كلمة مرور ورمز PIN مستقلّين، فيُفتح حسابه مباشرةً.
/// كما يتيح إعادة طلب رمز التفعيل عند انتهاء صلاحيته دون مغادرة الشاشة.
class ActivateAccountScreen extends StatefulWidget {
  const ActivateAccountScreen({super.key, this.initialEmail = ''});

  final String initialEmail;

  @override
  State<ActivateAccountScreen> createState() => _ActivateAccountScreenState();
}

class _ActivateAccountScreenState extends State<ActivateAccountScreen> {
  static const Set<String> _weakPins = {
    '0000', '1111', '2222', '3333', '4444', '5555',
    '6666', '7777', '8888', '9999', '1234', '4321', '0123',
  };

  final _formKey = GlobalKey<FormState>();
  late final TextEditingController _email =
      TextEditingController(text: widget.initialEmail);
  final _code = TextEditingController();
  final _password = TextEditingController();
  final _password2 = TextEditingController();
  final _pin = TextEditingController();
  final _pin2 = TextEditingController();

  bool _obscure = true;
  bool _resending = false;
  int _resendCooldown = 0;
  Timer? _cooldownTimer;

  @override
  void initState() {
    super.initState();
    SecureScreen.enable();
  }

  @override
  void dispose() {
    SecureScreen.disable();
    _cooldownTimer?.cancel();
    for (final c in [_email, _code, _password, _password2, _pin, _pin2]) {
      c.dispose();
    }
    super.dispose();
  }

  void _snack(String message, {bool danger = false}) {
    if (!mounted || message.trim().isEmpty) return;
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(message, textDirection: appTextDirection()),
        backgroundColor: danger ? AmialColors.danger : AmialColors.primary,
      ),
    );
  }

  void _startCooldown(int seconds) {
    _cooldownTimer?.cancel();
    setState(() => _resendCooldown = seconds);
    _cooldownTimer = Timer.periodic(const Duration(seconds: 1), (t) {
      if (!mounted) {
        t.cancel();
        return;
      }
      if (_resendCooldown <= 1) {
        t.cancel();
        setState(() => _resendCooldown = 0);
      } else {
        setState(() => _resendCooldown--);
      }
    });
  }

  String? _validateEmail(String? v) {
    final value = (v ?? '').trim();
    if (value.isEmpty) return 'أدخل بريدك الإلكتروني';
    if (!RegExp(r'^[^@\s]+@[^@\s]+\.[^@\s]+$').hasMatch(value)) {
      return 'أدخل بريداً إلكترونياً صحيحاً';
    }
    return null;
  }

  Future<void> _resendActivationCode() async {
    if (_resending || _resendCooldown > 0) return;
    final emailErr = _validateEmail(_email.text);
    if (emailErr != null) {
      _snack(emailErr, danger: true);
      return;
    }

    setState(() => _resending = true);
    try {
      final result = await Get.find<UnifiedAuthController>()
          .requestActivationCode(_email.text.trim());
      if (!mounted) return;
      _snack(result.message, danger: !result.ok);
      if (result.ok && result.resendAfterSeconds > 0) {
        _startCooldown(result.resendAfterSeconds);
      }
    } finally {
      if (mounted) setState(() => _resending = false);
    }
  }

  Future<void> _submit() async {
    if (!(_formKey.currentState?.validate() ?? false)) return;
    final controller = Get.find<UnifiedAuthController>();
    final ok = await controller.activateAccount(
      email: _email.text.trim(),
      otp: _code.text.trim(),
      password: _password.text,
      pin: _pin.text,
    );
    if (!mounted) return;
    if (ok) {
      controller.navigateToHomeForRole();
      return;
    }
    final message = controller.lastError.value;
    _snack(
      message.isNotEmpty ? message : 'تعذّر تفعيل الحساب',
      danger: !controller.activatedOnly.value,
    );
    if (controller.activatedOnly.value && Navigator.of(context).canPop()) {
      Navigator.of(context).pop();
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(title: const Text('تفعيل الحساب')),
      body: SafeArea(
        child: Directionality(
          textDirection: appTextDirection(),
          child: ListView(
            padding: const EdgeInsets.all(AmialSpacing.lg),
            children: [
              Container(
                padding: const EdgeInsets.all(AmialSpacing.lg),
                decoration: BoxDecoration(
                  color: AmialColors.cardSurface,
                  borderRadius: BorderRadius.circular(AmialSpacing.radiusXl),
                  border: Border.all(color: AmialColors.border),
                  boxShadow: AmialSpacing.cardShadow,
                ),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    const Center(child: AmialBrandLogo(height: 72)),
                    const SizedBox(height: 16),
                    const Text(
                      'تم اعتماد حسابك',
                      textAlign: TextAlign.center,
                      style: TextStyle(
                        fontSize: 22,
                        fontWeight: FontWeight.w900,
                        color: AmialColors.primary,
                      ),
                    ),
                    const SizedBox(height: 8),
                    const Text(
                      'أدخل بريدك والرمز المكوّن من 6 أرقام الذي وصلك، ثم اختر كلمة مرور للدخول ورمز PIN مستقلاً للعمليات المالية.',
                      textAlign: TextAlign.center,
                      style: TextStyle(
                        color: AmialColors.textSecondary,
                        height: 1.55,
                      ),
                    ),
                    const SizedBox(height: 20),
                    Form(
                      key: _formKey,
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.stretch,
                        children: [
                          TextFormField(
                            controller: _email,
                            keyboardType: TextInputType.emailAddress,
                            textDirection: TextDirection.ltr,
                            autofillHints: const [AutofillHints.email],
                            decoration: const InputDecoration(
                              labelText: 'البريد الإلكتروني',
                              hintText: 'name@example.com',
                              prefixIcon: Icon(Icons.email_outlined),
                              border: OutlineInputBorder(),
                            ),
                            validator: _validateEmail,
                          ),
                          const SizedBox(height: 12),
                          TextFormField(
                            controller: _code,
                            keyboardType: TextInputType.number,
                            textDirection: TextDirection.ltr,
                            maxLength: 6,
                            inputFormatters: [
                              const ArabicDigitsNormalizingFormatter(),
                              FilteringTextInputFormatter.digitsOnly,
                            ],
                            decoration: const InputDecoration(
                              labelText: 'رمز التفعيل (6 أرقام)',
                              prefixIcon: Icon(Icons.verified_outlined),
                              border: OutlineInputBorder(),
                              counterText: '',
                            ),
                            validator: (v) =>
                                (v == null || v.trim().length != 6)
                                    ? 'الرمز من 6 أرقام'
                                    : null,
                          ),
                          Align(
                            alignment: Alignment.centerLeft,
                            child: TextButton.icon(
                              onPressed: (_resending || _resendCooldown > 0)
                                  ? null
                                  : _resendActivationCode,
                              icon: _resending
                                  ? const SizedBox(
                                      width: 14,
                                      height: 14,
                                      child: CircularProgressIndicator(strokeWidth: 2),
                                    )
                                  : const Icon(Icons.refresh_rounded, size: 18),
                              label: Text(
                                _resendCooldown > 0
                                    ? 'إعادة إرسال الرمز خلال $_resendCooldown ثانية'
                                    : 'لم يصلك الرمز أو انتهت صلاحيته؟ أعد الإرسال',
                              ),
                            ),
                          ),
                          const SizedBox(height: 6),
                          TextFormField(
                            controller: _password,
                            obscureText: _obscure,
                            decoration: InputDecoration(
                              labelText: 'كلمة مرور الدخول (8 أحرف فأكثر)',
                              prefixIcon: const Icon(Icons.lock_outline),
                              border: const OutlineInputBorder(),
                              suffixIcon: IconButton(
                                icon: Icon(_obscure
                                    ? Icons.visibility_outlined
                                    : Icons.visibility_off_outlined),
                                onPressed: () =>
                                    setState(() => _obscure = !_obscure),
                              ),
                            ),
                            validator: (v) {
                              if (v == null || v.length < 8) {
                                return 'ثمانية أحرف على الأقل';
                              }
                              if (RegExp(r'^\d+$').hasMatch(v)) {
                                return 'كلمة المرور يجب أن تحتوي على حرف أو رمز واحد على الأقل';
                              }
                              return null;
                            },
                          ),
                          const SizedBox(height: 12),
                          TextFormField(
                            controller: _password2,
                            obscureText: _obscure,
                            decoration: const InputDecoration(
                              labelText: 'تأكيد كلمة المرور',
                              prefixIcon: Icon(Icons.lock_reset_outlined),
                              border: OutlineInputBorder(),
                            ),
                            validator: (v) => v != _password.text
                                ? 'كلمتا المرور غير متطابقتين'
                                : null,
                          ),
                          const SizedBox(height: 12),
                          TextFormField(
                            controller: _pin,
                            obscureText: true,
                            keyboardType: TextInputType.number,
                            maxLength: 6,
                            inputFormatters: [
                              const ArabicDigitsNormalizingFormatter(),
                              FilteringTextInputFormatter.digitsOnly,
                            ],
                            decoration: const InputDecoration(
                              labelText: 'رمز PIN للعمليات (4 إلى 6 أرقام)',
                              prefixIcon: Icon(Icons.pin_outlined),
                              border: OutlineInputBorder(),
                              counterText: '',
                            ),
                            validator: (v) {
                              if (v == null || v.length < 4 || v.length > 6) {
                                return 'من 4 إلى 6 أرقام';
                              }
                              if (_weakPins.contains(v)) {
                                return 'اختر رمز PIN غير متكرر ولا متسلسل (تجنّب 1234 و0000)';
                              }
                              if (v == _password.text) {
                                return 'رمز PIN يجب أن يختلف عن كلمة المرور';
                              }
                              return null;
                            },
                          ),
                          const SizedBox(height: 12),
                          TextFormField(
                            controller: _pin2,
                            obscureText: true,
                            keyboardType: TextInputType.number,
                            maxLength: 6,
                            inputFormatters: [
                              const ArabicDigitsNormalizingFormatter(),
                              FilteringTextInputFormatter.digitsOnly,
                            ],
                            decoration: const InputDecoration(
                              labelText: 'تأكيد رمز PIN',
                              prefixIcon: Icon(Icons.pin_outlined),
                              border: OutlineInputBorder(),
                              counterText: '',
                            ),
                            validator: (v) => v != _pin.text
                                ? 'الرمزان غير متطابقين'
                                : null,
                          ),
                        ],
                      ),
                    ),
                    const SizedBox(height: 20),
                    Obx(() {
                      final loading =
                          Get.find<UnifiedAuthController>().isSubmitting.value;
                      return FilledButton.icon(
                        onPressed: loading ? null : _submit,
                        icon: loading
                            ? const SizedBox(
                                width: 18,
                                height: 18,
                                child: CircularProgressIndicator(
                                  strokeWidth: 2,
                                  color: Colors.white,
                                ),
                              )
                            : const Icon(Icons.verified_user_outlined),
                        label: Text(loading ? 'جارٍ التفعيل…' : 'تفعيل الحساب'),
                        style: FilledButton.styleFrom(
                          backgroundColor: AmialColors.primary,
                          foregroundColor: Colors.white,
                          minimumSize:
                              const Size.fromHeight(AmialSpacing.buttonHeight),
                        ),
                      );
                    }),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
