import 'package:amial_pay/common/widgets/amial_brand_logo.dart';
import 'package:amial_pay/features/auth/controllers/unified_auth_controller.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:get/get.dart';

/// تفعيل حسابٍ اعتمده موثّق — من داخل التطبيق.
///
/// بعد اعتماد الطلب يصل العميلَ بريدٌ فيه رمزٌ من ست خانات. هنا يُدخل بريدَه والرمز، ويختار كلمة مرور ورمز PIN، فيُفتح حسابه
/// مباشرةً: لا صفحة ويب ولا «نسيت كلمة المرور». الخادم (`POST /auth/activate`) هو الذي يتحقق من الرمز ويثبّت البريد.
class ActivateAccountScreen extends StatefulWidget {
  const ActivateAccountScreen({super.key, this.initialEmail = ''});

  final String initialEmail;

  @override
  State<ActivateAccountScreen> createState() => _ActivateAccountScreenState();
}

class _ActivateAccountScreenState extends State<ActivateAccountScreen> {
  final _formKey = GlobalKey<FormState>();
  late final TextEditingController _email =
      TextEditingController(text: widget.initialEmail);
  final _code = TextEditingController();
  final _password = TextEditingController();
  final _password2 = TextEditingController();
  final _pin = TextEditingController();
  final _pin2 = TextEditingController();
  bool _obscure = true;

  @override
  void dispose() {
    for (final c in [_email, _code, _password, _password2, _pin, _pin2]) {
      c.dispose();
    }
    super.dispose();
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
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(message)));
    if (controller.activatedOnly.value && Navigator.of(context).canPop()) {
      // التفعيل تمّ ولم يُفتح الدخول تلقائياً: يعود العميل إلى شاشة الدخول.
      Navigator.of(context).pop();
    }
  }

  String? _required(String? v, String what) =>
      (v == null || v.trim().isEmpty) ? 'أدخل $what' : null;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('تفعيل الحساب')),
      body: SafeArea(
        child: ListView(
          padding: const EdgeInsets.all(24),
          children: [
            const Center(child: AmialBrandLogo(height: 72)),
            const SizedBox(height: 16),
            const Text(
              'تم اعتماد حسابك',
              textAlign: TextAlign.center,
              style: TextStyle(fontSize: 22, fontWeight: FontWeight.bold),
            ),
            const SizedBox(height: 8),
            const Text(
              'أدخل بريدك والرمز الذي وصلك عليه، ثم اختر كلمة مرور ورمز PIN لتبدأ.',
              textAlign: TextAlign.center,
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
                      border: OutlineInputBorder(),
                    ),
                    validator: (v) => _required(v, 'بريدك الإلكتروني'),
                  ),
                  const SizedBox(height: 12),
                  TextFormField(
                    controller: _code,
                    keyboardType: TextInputType.number,
                    textDirection: TextDirection.ltr,
                    maxLength: 6,
                    inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                    decoration: const InputDecoration(
                      labelText: 'رمز التفعيل (6 أرقام)',
                      border: OutlineInputBorder(),
                      counterText: '',
                    ),
                    validator: (v) => (v == null || v.trim().length != 6)
                        ? 'الرمز من 6 أرقام'
                        : null,
                  ),
                  const SizedBox(height: 12),
                  TextFormField(
                    controller: _password,
                    obscureText: _obscure,
                    decoration: InputDecoration(
                      labelText: 'كلمة المرور (8 أحرف فأكثر)',
                      border: const OutlineInputBorder(),
                      suffixIcon: IconButton(
                        icon: Icon(_obscure
                            ? Icons.visibility_outlined
                            : Icons.visibility_off_outlined),
                        onPressed: () => setState(() => _obscure = !_obscure),
                      ),
                    ),
                    validator: (v) =>
                        (v == null || v.length < 8) ? 'ثمانية أحرف على الأقل' : null,
                  ),
                  const SizedBox(height: 12),
                  TextFormField(
                    controller: _password2,
                    obscureText: _obscure,
                    decoration: const InputDecoration(
                      labelText: 'تأكيد كلمة المرور',
                      border: OutlineInputBorder(),
                    ),
                    validator: (v) =>
                        v != _password.text ? 'كلمتا المرور غير متطابقتين' : null,
                  ),
                  const SizedBox(height: 12),
                  TextFormField(
                    controller: _pin,
                    obscureText: true,
                    keyboardType: TextInputType.number,
                    maxLength: 6,
                    inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                    decoration: const InputDecoration(
                      labelText: 'رمز PIN للعمليات (4 إلى 6 أرقام)',
                      border: OutlineInputBorder(),
                      counterText: '',
                    ),
                    validator: (v) => (v == null || v.length < 4 || v.length > 6)
                        ? 'من 4 إلى 6 أرقام'
                        : null,
                  ),
                  const SizedBox(height: 12),
                  TextFormField(
                    controller: _pin2,
                    obscureText: true,
                    keyboardType: TextInputType.number,
                    maxLength: 6,
                    inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                    decoration: const InputDecoration(
                      labelText: 'تأكيد رمز PIN',
                      border: OutlineInputBorder(),
                      counterText: '',
                    ),
                    validator: (v) => v != _pin.text ? 'الرمزان غير متطابقين' : null,
                  ),
                ],
              ),
            ),
            const SizedBox(height: 20),
            Obx(() {
              final loading = Get.find<UnifiedAuthController>().isSubmitting.value;
              return FilledButton.icon(
                onPressed: loading ? null : _submit,
                icon: loading
                    ? const SizedBox(
                        width: 18,
                        height: 18,
                        child: CircularProgressIndicator(strokeWidth: 2),
                      )
                    : const Icon(Icons.verified_user_outlined),
                label: Text(loading ? 'جارٍ التفعيل…' : 'تفعيل الحساب'),
              );
            }),
          ],
        ),
      ),
    );
  }
}
