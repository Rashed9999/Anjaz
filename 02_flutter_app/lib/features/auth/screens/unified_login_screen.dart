import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:get/get.dart';
import 'package:local_auth/local_auth.dart';
import 'package:amial_pay/common/widgets/amial_build_stamp.dart';
import 'package:amial_pay/features/auth/controllers/unified_auth_controller.dart';
import 'package:amial_pay/features/auth/screens/amial_biometric_setup_screen.dart';
import 'package:amial_pay/features/auth/screens/amial_registration_wizard_screen.dart';
import 'package:amial_pay/features/auth/screens/quick_receive_screen.dart';
import 'package:amial_pay/features/forget_pin/screens/forget_pin_screen.dart';
import 'package:amial_pay/features/language/widgets/amial_language_switch.dart';
import 'package:amial_pay/theme/amial_colors.dart';
import 'package:amial_pay/theme/amial_spacing.dart';
import 'package:amial_pay/util/images.dart';
import 'package:amial_pay/util/secure_screen.dart';

enum AccountKind { customer, merchant, pos }

extension _KindMeta on AccountKind {
  String get label => switch (this) {
        AccountKind.customer => 'العميل',
        AccountKind.merchant => 'التاجر',
        AccountKind.pos => 'نقطة البيع',
      };

  IconData get icon => switch (this) {
        AccountKind.customer => Icons.account_balance_wallet_outlined,
        AccountKind.merchant => Icons.storefront_outlined,
        AccountKind.pos => Icons.point_of_sale_outlined,
      };

  String get title => switch (this) {
        AccountKind.customer => 'مرحباً بك في أميال باي',
        AccountKind.merchant => 'إدارة أعمالك بنمو وثقة',
        AccountKind.pos => 'نقطة بيع جاهزة للعمل',
      };

  String get subtitle => switch (this) {
        AccountKind.customer =>
          'محفظتك الرقمية الآمنة للتحويل واستلام الأموال بسهولة.',
        AccountKind.merchant =>
          'دخول آمن إلى حساب متجرك، العمليات والتقارير.',
        AccountKind.pos =>
          'دخول تشغيلي مخصص لنقطة البيع دون كشف حساب المالك.',
      };

  String get formTitle => switch (this) {
        AccountKind.customer => 'تسجيل الدخول',
        AccountKind.merchant => 'دخول التاجر',
        AccountKind.pos => 'دخول نقطة البيع',
      };

  String get submitLabel => switch (this) {
        AccountKind.customer => 'تسجيل الدخول',
        AccountKind.merchant => 'دخول التاجر',
        AccountKind.pos => 'فتح نقطة البيع',
      };
}

/// AMIAL-LOGIN-UI-004 — شاشة واحدة تقنياً وثلاث تجارب متخصصة.
class UnifiedLoginScreen extends StatefulWidget {
  const UnifiedLoginScreen({super.key});

  @override
  State<UnifiedLoginScreen> createState() => _UnifiedLoginScreenState();
}

class _UnifiedLoginScreenState extends State<UnifiedLoginScreen> {
  final _formKey = GlobalKey<FormState>();
  final _phoneCtrl = TextEditingController();
  final _passwordCtrl = TextEditingController();
  final _merchantNumCtrl = TextEditingController();
  final _posNumCtrl = TextEditingController();

  AccountKind _kind = AccountKind.customer;
  bool _obscure = true;
  ({String name, String phone, String kind})? _lastUser;

  @override
  void initState() {
    super.initState();
    SecureScreen.enable();
    final last = UnifiedAuthController.readLastUser();
    if (last != null) {
      _lastUser = last;
      _phoneCtrl.text = last.phone;
      _kind = AccountKind.values.firstWhere(
        (item) => item.name == last.kind,
        orElse: () => AccountKind.customer,
      );
    }
  }

  @override
  void dispose() {
    SecureScreen.disable();
    _phoneCtrl.dispose();
    _passwordCtrl.dispose();
    _merchantNumCtrl.dispose();
    _posNumCtrl.dispose();
    super.dispose();
  }

  void _switchKind(AccountKind kind) {
    if (_kind == kind) return;
    setState(() {
      _kind = kind;
      _passwordCtrl.clear();
      if (kind == AccountKind.customer) {
        _merchantNumCtrl.clear();
        _posNumCtrl.clear();
      }
    });
  }

  Future<void> _submit() async {
    if (!(_formKey.currentState?.validate() ?? false)) return;
    final controller = Get.find<UnifiedAuthController>();
    var ok = false;

    switch (_kind) {
      case AccountKind.customer:
        ok = await controller.loginCustomer(
          nationalId: '',
          phone: _phoneCtrl.text.trim(),
          password: _passwordCtrl.text,
        );
        break;
      case AccountKind.merchant:
        ok = await controller.loginMerchant(
          merchantNumber: _merchantNumCtrl.text.trim(),
          phone: _phoneCtrl.text.trim(),
          password: _passwordCtrl.text,
        );
        break;
      case AccountKind.pos:
        ok = await controller.loginMerchant(
          merchantNumber: _merchantNumCtrl.text.trim(),
          phone: _phoneCtrl.text.trim(),
          password: _passwordCtrl.text,
          posNumber: _posNumCtrl.text.trim(),
        );
        break;
    }

    if (!mounted) return;
    if (!ok) {
      _snack(
        controller.lastError.value.isNotEmpty
            ? controller.lastError.value
            : 'تعذّر تسجيل الدخول',
        danger: true,
      );
      return;
    }

    await UnifiedAuthController.rememberLastUser(
      name: controller.displayName,
      phone: _phoneCtrl.text.trim(),
      kind: _kind.name,
    );
    controller.navigateToHomeForRole();
  }

  Future<void> _bioLogin() async {
    if (_kind != AccountKind.customer) return;
    try {
      if (!await AmialBiometricSetupScreen.isEnabled()) {
        final phone = _phoneCtrl.text.trim();
        final password = _passwordCtrl.text;
        if (phone.isEmpty || password.length < 4) {
          _snack(
            'أدخل رقم الهاتف وكلمة المرور أولاً لتفعيل الدخول بالبصمة',
            danger: true,
          );
          return;
        }
        if (!mounted) return;
        await Get.to(() => AmialBiometricSetupScreen(
              phone: phone,
              password: password,
            ));
        if (!await AmialBiometricSetupScreen.isEnabled()) return;
      }

      final accepted = await LocalAuthentication().authenticate(
        localizedReason: 'الدخول إلى أميال باي',
        biometricOnly: true,
        persistAcrossBackgrounding: true,
      );
      if (!accepted || !mounted) return;

      final credentials = await AmialBiometricSetupScreen.savedCredentials();
      if (credentials == null) return;
      final controller = Get.find<UnifiedAuthController>();
      final ok = await controller.loginCustomer(
        nationalId: '',
        phone: credentials.$1,
        password: credentials.$2,
      );
      if (!mounted) return;
      if (ok) {
        controller.navigateToHomeForRole();
      } else {
        _snack(controller.lastError.value, danger: true);
      }
    } catch (_) {
      _snack('تعذّر التحقق الحيوي على هذا الجهاز', danger: true);
    }
  }

  void _openRecovery() {
    if (_kind == AccountKind.customer) {
      Get.to(() => ForgetPinScreen(
            phoneNumber: _phoneCtrl.text.trim(),
            countryCode: '+967',
          ));
      return;
    }
    _snack(
      _kind == AccountKind.merchant
          ? 'استرداد حساب التاجر يتم عبر قناة الاسترداد أو الدعم المعتمد.'
          : 'بيانات نقطة البيع يعيدها مالك الحساب أو مدير المتجر.',
    );
  }

  void _openQuickReceive() {
    final last = _lastUser;
    if (last == null || last.kind != 'customer' || last.phone.trim().isEmpty) {
      _snack('سجّل دخول العميل مرة واحدة على هذا الجهاز لتفعيل الاستلام السريع.');
      return;
    }
    Get.to(() => QuickReceiveScreen(
          displayName: last.name,
          paymentAddress: last.phone,
        ));
  }

  void _snack(String message, {bool danger = false}) {
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(message),
        backgroundColor: danger ? AmialColors.danger : AmialColors.info,
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final dark = _kind != AccountKind.customer;
    return Scaffold(
      backgroundColor: AmialColors.background,
      body: SafeArea(
        child: Directionality(
          textDirection: TextDirection.rtl,
          child: ListView(
            padding: EdgeInsets.zero,
            children: [
              _hero(context, dark: dark),
              Transform.translate(
                offset: const Offset(0, -AmialSpacing.lg),
                child: Padding(
                  padding: const EdgeInsets.symmetric(
                    horizontal: AmialSpacing.screen,
                  ),
                  child: _loginCard(context),
                ),
              ),
              Padding(
                padding: const EdgeInsets.fromLTRB(
                  AmialSpacing.screen,
                  0,
                  AmialSpacing.screen,
                  AmialSpacing.xl,
                ),
                child: Column(
                  children: [
                    _securityStrip(context),
                    const SizedBox(height: AmialSpacing.sm),
                    const AmialBuildStamp(),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _hero(BuildContext context, {required bool dark}) {
    final bg = dark ? AmialColors.primaryDark : AmialColors.cardSurface;
    final fg = dark ? AmialColors.cardSurface : AmialColors.primary;
    final secondary = dark
        ? AmialColors.cardSurface.withValues(alpha: 0.82)
        : AmialColors.textSecondary;

    return Container(
      padding: const EdgeInsets.fromLTRB(
        AmialSpacing.screen,
        AmialSpacing.sm,
        AmialSpacing.screen,
        AmialSpacing.xxl + AmialSpacing.xl,
      ),
      decoration: BoxDecoration(
        color: bg,
        borderRadius: const BorderRadius.vertical(
          bottom: Radius.circular(AmialSpacing.radiusXl),
        ),
      ),
      child: Column(
        children: [
          Row(
            children: [
              AmialLanguageChip(onDark: dark),
              const Spacer(),
              _kindSelector(context, dark: dark),
            ],
          ),
          const SizedBox(height: AmialSpacing.xl),
          Container(
            width: AmialSpacing.xxl * 2.4,
            height: AmialSpacing.xxl * 2.4,
            padding: const EdgeInsets.all(AmialSpacing.xs),
            decoration: BoxDecoration(
              color: AmialColors.yellow,
              borderRadius: BorderRadius.circular(AmialSpacing.radiusLg),
              boxShadow: AmialSpacing.cardShadow,
            ),
            child: Image.asset(Images.logo, fit: BoxFit.contain),
          ),
          const SizedBox(height: AmialSpacing.lg),
          if (_kind != AccountKind.customer) ...[
            _roleBadge(context),
            const SizedBox(height: AmialSpacing.sm),
          ],
          Text(
            _kind.title,
            textAlign: TextAlign.center,
            style: Theme.of(context).textTheme.headlineMedium?.copyWith(
                  color: fg,
                  fontWeight: FontWeight.w900,
                  height: 1.25,
                ),
          ),
          const SizedBox(height: AmialSpacing.xs),
          Text(
            _kind.subtitle,
            textAlign: TextAlign.center,
            style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                  color: secondary,
                  height: 1.65,
                  fontWeight: FontWeight.w500,
                ),
          ),
          if (_kind == AccountKind.pos) ...[
            const SizedBox(height: AmialSpacing.lg),
            Row(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                _heroBadge(context, Icons.verified_user_outlined, 'آمن'),
                const SizedBox(width: AmialSpacing.xs),
                _heroBadge(context, Icons.bolt_outlined, 'سريع'),
                const SizedBox(width: AmialSpacing.xs),
                _heroBadge(context, Icons.point_of_sale_outlined, 'تشغيلي'),
              ],
            ),
          ],
        ],
      ),
    );
  }

  Widget _kindSelector(BuildContext context, {required bool dark}) {
    return PopupMenuButton<AccountKind>(
      tooltip: 'نوع الحساب',
      onSelected: _switchKind,
      itemBuilder: (_) => AccountKind.values
          .map((kind) => PopupMenuItem<AccountKind>(
                value: kind,
                child: Row(
                  children: [
                    Icon(kind.icon, color: AmialColors.primary),
                    const SizedBox(width: AmialSpacing.sm),
                    Text(kind.label),
                  ],
                ),
              ))
          .toList(),
      child: Container(
        padding: const EdgeInsets.symmetric(
          horizontal: AmialSpacing.sm,
          vertical: AmialSpacing.xs,
        ),
        decoration: BoxDecoration(
          color: dark
              ? AmialColors.cardSurface.withValues(alpha: 0.12)
              : AmialColors.background,
          borderRadius: BorderRadius.circular(AmialSpacing.radiusXl),
          border: Border.all(
            color: dark
                ? AmialColors.cardSurface.withValues(alpha: 0.22)
                : AmialColors.border,
          ),
        ),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(
              _kind.icon,
              size: AmialSpacing.lg,
              color: dark ? AmialColors.cardSurface : AmialColors.primary,
            ),
            const SizedBox(width: AmialSpacing.xs),
            Text(
              _kind.label,
              style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                    color:
                        dark ? AmialColors.cardSurface : AmialColors.textPrimary,
                    fontWeight: FontWeight.w800,
                  ),
            ),
            const SizedBox(width: AmialSpacing.xxs),
            Icon(
              Icons.expand_more,
              size: AmialSpacing.lg,
              color: dark ? AmialColors.cardSurface : AmialColors.textMuted,
            ),
          ],
        ),
      ),
    );
  }

  Widget _roleBadge(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(
        horizontal: AmialSpacing.md,
        vertical: AmialSpacing.xs,
      ),
      decoration: BoxDecoration(
        color: AmialColors.yellow.withValues(alpha: 0.16),
        borderRadius: BorderRadius.circular(AmialSpacing.radiusXl),
        border: Border.all(color: AmialColors.yellow),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(_kind.icon, color: AmialColors.yellow, size: AmialSpacing.lg),
          const SizedBox(width: AmialSpacing.xs),
          Text(
            _kind == AccountKind.merchant ? 'بوابة التاجر' : 'أميال باي POS',
            style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                  color: AmialColors.yellow,
                  fontWeight: FontWeight.w800,
                ),
          ),
        ],
      ),
    );
  }

  Widget _heroBadge(BuildContext context, IconData icon, String label) {
    return Container(
      padding: const EdgeInsets.symmetric(
        horizontal: AmialSpacing.sm,
        vertical: AmialSpacing.xs,
      ),
      decoration: BoxDecoration(
        color: AmialColors.cardSurface.withValues(alpha: 0.10),
        borderRadius: BorderRadius.circular(AmialSpacing.radiusXl),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(icon, color: AmialColors.yellow, size: AmialSpacing.md),
          const SizedBox(width: AmialSpacing.xxs),
          Text(
            label,
            style: Theme.of(context).textTheme.bodySmall?.copyWith(
                  color: AmialColors.cardSurface,
                  fontWeight: FontWeight.w700,
                ),
          ),
        ],
      ),
    );
  }

  Widget _loginCard(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(AmialSpacing.lg),
      decoration: BoxDecoration(
        color: AmialColors.cardSurface,
        borderRadius: BorderRadius.circular(AmialSpacing.radiusXl),
        border: Border.all(color: AmialColors.border),
        boxShadow: AmialSpacing.cardShadow,
      ),
      child: Form(
        key: _formKey,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(
              _kind.formTitle,
              style: Theme.of(context).textTheme.headlineSmall?.copyWith(
                    color: AmialColors.textPrimary,
                    fontWeight: FontWeight.w900,
                  ),
            ),
            const SizedBox(height: AmialSpacing.xxs),
            Text(
              _formSubtitle,
              style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                    color: AmialColors.textSecondary,
                    height: 1.55,
                  ),
            ),
            const SizedBox(height: AmialSpacing.lg),
            if (_kind != AccountKind.customer) ...[
              _field(
                controller: _merchantNumCtrl,
                label: 'رقم التاجر',
                hint: 'AM-XXXX-000',
                icon: Icons.storefront_outlined,
                validator: _required('أدخل رقم التاجر'),
              ),
              const SizedBox(height: AmialSpacing.sm),
            ],
            if (_kind == AccountKind.pos) ...[
              _field(
                controller: _posNumCtrl,
                label: 'رقم نقطة البيع',
                hint: 'POS-000123',
                icon: Icons.point_of_sale_outlined,
                validator: _required('أدخل رقم نقطة البيع'),
              ),
              const SizedBox(height: AmialSpacing.sm),
            ],
            _field(
              controller: _phoneCtrl,
              label: 'رقم الهاتف',
              hint: '7XXXXXXXX',
              icon: Icons.phone_outlined,
              keyboardType: TextInputType.phone,
              inputFormatters: [
                FilteringTextInputFormatter.allow(RegExp(r'[0-9+]')),
              ],
              validator: (value) {
                final digits = (value ?? '').replaceAll(RegExp(r'[^0-9]'), '');
                return digits.length < 7 ? 'أدخل رقم هاتف صحيح' : null;
              },
            ),
            const SizedBox(height: AmialSpacing.sm),
            _field(
              controller: _passwordCtrl,
              label: 'كلمة المرور',
              hint: 'أدخل كلمة المرور',
              icon: Icons.lock_outline,
              obscureText: _obscure,
              validator: (value) =>
                  (value ?? '').length < 4 ? 'أدخل كلمة المرور' : null,
              onSubmitted: (_) => _submit(),
              suffix: IconButton(
                tooltip: _obscure ? 'إظهار كلمة المرور' : 'إخفاء كلمة المرور',
                onPressed: () => setState(() => _obscure = !_obscure),
                icon: Icon(
                  _obscure
                      ? Icons.visibility_outlined
                      : Icons.visibility_off_outlined,
                  color: AmialColors.textMuted,
                ),
              ),
            ),
            Align(
              alignment: Alignment.centerLeft,
              child: TextButton(
                onPressed: _openRecovery,
                child: const Text('نسيت كلمة المرور؟'),
              ),
            ),
            const SizedBox(height: AmialSpacing.xs),
            Obx(() {
              final loading =
                  Get.find<UnifiedAuthController>().isSubmitting.value;
              return FilledButton.icon(
                onPressed: loading ? null : _submit,
                icon: loading
                    ? const SizedBox(
                        width: AmialSpacing.lg,
                        height: AmialSpacing.lg,
                        child: CircularProgressIndicator(
                          strokeWidth: 2,
                          color: AmialColors.cardSurface,
                        ),
                      )
                    : Icon(_kind.icon),
                label: Text(loading ? 'جارٍ التحقق…' : _kind.submitLabel),
                style: FilledButton.styleFrom(
                  backgroundColor: _kind == AccountKind.merchant
                      ? AmialColors.primaryDark
                      : AmialColors.primary,
                  foregroundColor: AmialColors.cardSurface,
                  minimumSize:
                      const Size.fromHeight(AmialSpacing.buttonHeight),
                  shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(AmialSpacing.radiusMd),
                  ),
                ),
              );
            }),
            if (_kind == AccountKind.customer) ...[
              const SizedBox(height: AmialSpacing.md),
              _divider(context),
              const SizedBox(height: AmialSpacing.md),
              OutlinedButton.icon(
                onPressed: _bioLogin,
                icon: const Icon(Icons.fingerprint_rounded),
                label: const Text('الدخول بالبصمة'),
                style: OutlinedButton.styleFrom(
                  foregroundColor: AmialColors.primary,
                  side: const BorderSide(color: AmialColors.border),
                  minimumSize:
                      const Size.fromHeight(AmialSpacing.buttonHeight),
                  shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(AmialSpacing.radiusMd),
                  ),
                ),
              ),
              const SizedBox(height: AmialSpacing.sm),
              _quickReceiveCard(context),
              const SizedBox(height: AmialSpacing.md),
              Row(
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  Text(
                    'ليس لديك حساب؟',
                    style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                          color: AmialColors.textSecondary,
                        ),
                  ),
                  TextButton(
                    onPressed: () =>
                        Get.to(() => const AmialRegistrationWizardScreen()),
                    child: const Text('إنشاء حساب جديد'),
                  ),
                ],
              ),
            ] else ...[
              const SizedBox(height: AmialSpacing.md),
              _businessNote(context),
            ],
          ],
        ),
      ),
    );
  }

  String get _formSubtitle => switch (_kind) {
        AccountKind.customer => 'أدخل بياناتك للوصول إلى محفظتك بأمان.',
        AccountKind.merchant =>
          'استخدم بيانات حساب المالك أو الحساب الإداري للتاجر.',
        AccountKind.pos =>
          'أدخل بيانات نقطة البيع المسجلة. PIN الموظف يحتاج عقداً مخصصاً من الخادم.',
      };

  String? Function(String?) _required(String message) =>
      (value) => value == null || value.trim().isEmpty ? message : null;

  Widget _field({
    required TextEditingController controller,
    required String label,
    required String hint,
    required IconData icon,
    required String? Function(String?) validator,
    TextInputType? keyboardType,
    bool obscureText = false,
    Widget? suffix,
    List<TextInputFormatter>? inputFormatters,
    ValueChanged<String>? onSubmitted,
  }) {
    return TextFormField(
      controller: controller,
      keyboardType: keyboardType,
      obscureText: obscureText,
      inputFormatters: inputFormatters,
      onFieldSubmitted: onSubmitted,
      validator: validator,
      decoration: InputDecoration(
        labelText: label,
        hintText: hint,
        prefixIcon: Icon(icon, color: AmialColors.primary),
        suffixIcon: suffix,
        filled: true,
        fillColor: AmialColors.cardSurface,
        border: OutlineInputBorder(
          borderRadius: BorderRadius.circular(AmialSpacing.radiusMd),
          borderSide: const BorderSide(color: AmialColors.border),
        ),
        enabledBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(AmialSpacing.radiusMd),
          borderSide: const BorderSide(color: AmialColors.border),
        ),
        focusedBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(AmialSpacing.radiusMd),
          borderSide: const BorderSide(color: AmialColors.primary),
        ),
      ),
    );
  }

  Widget _divider(BuildContext context) => Row(
        children: [
          const Expanded(child: Divider(color: AmialColors.border)),
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: AmialSpacing.sm),
            child: Text(
              'أو',
              style: Theme.of(context).textTheme.bodySmall?.copyWith(
                    color: AmialColors.textMuted,
                  ),
            ),
          ),
          const Expanded(child: Divider(color: AmialColors.border)),
        ],
      );

  Widget _quickReceiveCard(BuildContext context) {
    final available = _lastUser?.kind == 'customer' &&
        (_lastUser?.phone.trim().isNotEmpty ?? false);
    return InkWell(
      onTap: _openQuickReceive,
      borderRadius: BorderRadius.circular(AmialSpacing.radiusLg),
      child: Container(
        padding: const EdgeInsets.all(AmialSpacing.md),
        decoration: BoxDecoration(
          color: available ? AmialColors.warningSurface : AmialColors.background,
          borderRadius: BorderRadius.circular(AmialSpacing.radiusLg),
          border: Border.all(
            color: available ? AmialColors.yellowDark : AmialColors.border,
          ),
        ),
        child: Row(
          children: [
            Container(
              width: AmialSpacing.xxl + AmialSpacing.md,
              height: AmialSpacing.xxl + AmialSpacing.md,
              decoration: BoxDecoration(
                color: AmialColors.yellowLight,
                borderRadius: BorderRadius.circular(AmialSpacing.radiusMd),
              ),
              child: const Icon(
                Icons.qr_code_2_rounded,
                color: AmialColors.primary,
              ),
            ),
            const SizedBox(width: AmialSpacing.sm),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    'استلام سريع',
                    style: Theme.of(context).textTheme.titleMedium?.copyWith(
                          color: AmialColors.textPrimary,
                          fontWeight: FontWeight.w800,
                        ),
                  ),
                  const SizedBox(height: AmialSpacing.xxs),
                  Text(
                    available
                        ? 'اعرض رمز الاستلام دون تسجيل الدخول.'
                        : 'يُفعّل بعد أول دخول ناجح على هذا الجهاز.',
                    style: Theme.of(context).textTheme.bodySmall?.copyWith(
                          color: AmialColors.textSecondary,
                        ),
                  ),
                ],
              ),
            ),
            Icon(
              available ? Icons.arrow_back_ios_new_rounded : Icons.lock_outline,
              size: AmialSpacing.md,
              color: available ? AmialColors.primary : AmialColors.textMuted,
            ),
          ],
        ),
      ),
    );
  }

  Widget _businessNote(BuildContext context) {
    final pos = _kind == AccountKind.pos;
    return Container(
      padding: const EdgeInsets.all(AmialSpacing.md),
      decoration: BoxDecoration(
        color: pos ? AmialColors.warningSurface : AmialColors.background,
        borderRadius: BorderRadius.circular(AmialSpacing.radiusLg),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(
            pos ? Icons.verified_user_outlined : Icons.shield_outlined,
            color: pos ? AmialColors.warning : AmialColors.primary,
          ),
          const SizedBox(width: AmialSpacing.sm),
          Expanded(
            child: Text(
              pos
                  ? 'لا نعرض PIN موظف وهمياً قبل أن يدعمه الخادم. حالياً تستخدم الشاشة عقد نقطة البيع الحقيقي.'
                  : 'بعد الدخول يوجهك أميال إلى قطاع تجارتك الصحيح ويطبق الباقة والصلاحيات.',
              style: Theme.of(context).textTheme.bodySmall?.copyWith(
                    color: AmialColors.textSecondary,
                    height: 1.6,
                  ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _securityStrip(BuildContext context) => Container(
        padding: const EdgeInsets.all(AmialSpacing.md),
        decoration: BoxDecoration(
          color: AmialColors.cardSurface,
          borderRadius: BorderRadius.circular(AmialSpacing.radiusLg),
          border: Border.all(color: AmialColors.border),
        ),
        child: Row(
          children: [
            const Icon(Icons.security_outlined, color: AmialColors.primary),
            const SizedBox(width: AmialSpacing.sm),
            Expanded(
              child: Text(
                'بيانات الدخول محمية ولا تُحفظ كلمة المرور في شاشة الدخول.',
                style: Theme.of(context).textTheme.bodySmall?.copyWith(
                      color: AmialColors.textSecondary,
                    ),
              ),
            ),
          ],
        ),
      );
}
