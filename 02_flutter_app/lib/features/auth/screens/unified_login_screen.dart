import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/features/auth/controllers/unified_auth_controller.dart';
import 'package:amyal_pay/features/auth/screens/amial_registration_wizard_screen.dart';
import 'package:amyal_pay/features/auth/screens/amial_biometric_setup_screen.dart';
import 'package:local_auth/local_auth.dart';
import 'package:amyal_pay/features/forget_pin/screens/forget_pin_screen.dart';
import 'package:amyal_pay/features/amyal/screens/account_recovery_screen.dart';
import 'package:amyal_pay/features/setting/screens/support_screen.dart';
import 'package:amyal_pay/features/language/screens/change_language_screen.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';
import 'package:amyal_pay/util/images.dart';
import 'package:package_info_plus/package_info_plus.dart';

/// AMIAL-UNIFIED-AUTH-001 (v1.5)
///
/// شاشة تسجيل دخول موحدة لكل الأدوار:
///   - عميل (Customer): هوية + هاتف + كلمة مرور
///   - تاجر (Merchant): رقم تاجر + هاتف + كلمة مرور + رقم POS اختياري
///   - وكيل (Agent): رقم وكيل + كلمة مرور → OTP
class UnifiedLoginScreen extends StatefulWidget {
  const UnifiedLoginScreen({super.key});

  @override
  State<UnifiedLoginScreen> createState() => _UnifiedLoginScreenState();
}

class _UnifiedLoginScreenState extends State<UnifiedLoginScreen>
    with SingleTickerProviderStateMixin {
  late TabController _tabController;

  @override
  void initState() {
    super.initState();
    _tabController = TabController(length: 4, vsync: this);
  }

  @override
  void dispose() {
    _tabController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmyalColors.background,
      body: SafeArea(
        child: Column(
          children: [
            // ====== Header ======
            // AMYAL-UI-002: صندوق أزرق أصغر (يكفي أعلى الشاشة)، شعار أكبر في
            // دائرة بيضاء، مع دخول متحرّك سلس. علامة الإصدار صغيرة وهادئة.
            Container(
              padding: const EdgeInsets.fromLTRB(20, 26, 20, 18),
              decoration: const BoxDecoration(
                gradient: LinearGradient(
                  colors: [AmyalColors.primary, Color(0xFF1D4FB8)],
                  begin: Alignment.topLeft,
                  end: Alignment.bottomRight,
                ),
                borderRadius: BorderRadius.vertical(bottom: Radius.circular(24)),
              ),
              child: TweenAnimationBuilder<double>(
                tween: Tween<double>(begin: 0, end: 1),
                duration: const Duration(milliseconds: 650),
                curve: Curves.easeOut,
                builder: (context, t, child) => Opacity(
                  opacity: t.clamp(0.0, 1.0),
                  child: Transform.translate(
                    offset: Offset(0, (1 - t) * 12),
                    child: Transform.scale(scale: 0.94 + 0.06 * t, child: child),
                  ),
                ),
                child: Column(
                  children: [
                    Container(
                      padding: const EdgeInsets.all(14),
                      decoration: BoxDecoration(
                        color: Colors.white.withValues(alpha: 0.14),
                        shape: BoxShape.circle,
                      ),
                      child: Image.asset(Images.logo, height: 58, width: 58),
                    ),
                    const SizedBox(height: 12),
                    const Text(
                      'أميال باي',
                      style: TextStyle(
                          color: Colors.white,
                          fontSize: 28,
                          fontWeight: FontWeight.bold),
                    ),
                    const SizedBox(height: 4),
                    const Text(
                      'دفع سريع وآمن',
                      style: TextStyle(color: Colors.white70, fontSize: 13),
                    ),
                    // AMIAL-BUILD-STAMP: علامة إصدار مرئية تُقرأ تلقائياً من البناء
                    // (package_info) — لا تتقادم أبداً. تؤكّد أن الـAPK المثبّت أحدث.
                    const SizedBox(height: 8),
                    const _VersionStamp(),
                  ],
                ),
              ),
            ),

            // ====== Tabs ======
            Container(
              color: Colors.white,
              child: TabBar(
                controller: _tabController,
                indicatorColor: AmyalColors.primary,
                labelColor: AmyalColors.primary,
                unselectedLabelColor: AmyalColors.textMuted,
                // AMYAL-UI-002: أيقونة ملوّنة مميّزة لكل نوع حساب.
                tabs: const [
                  Tab(icon: Icon(Icons.person, color: Color(0xFF053391)), text: 'عميل'),
                  Tab(icon: Icon(Icons.store, color: Color(0xFF1B9E4B)), text: 'تاجر'),
                  Tab(icon: Icon(Icons.business_center, color: Color(0xFFE08A00)), text: 'وكيل'),
                  Tab(icon: Icon(Icons.admin_panel_settings_outlined, color: Color(0xFFB3261E)), text: 'أدمن'),
                ],
              ),
            ),

            // ====== Tab Views ======
            Expanded(
              child: TabBarView(
                controller: _tabController,
                children: const [
                  _CustomerLoginTab(),
                  _MerchantLoginTab(),
                  _AgentLoginTab(),
                  _AdminLoginTab(),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

// ============================================================
// Customer Login Tab
// ============================================================
class _CustomerLoginTab extends StatefulWidget {
  const _CustomerLoginTab();

  @override
  State<_CustomerLoginTab> createState() => _CustomerLoginTabState();
}

class _CustomerLoginTabState extends State<_CustomerLoginTab> {
  final _formKey = GlobalKey<FormState>();
  final _nationalIdCtrl = TextEditingController();
  final _phoneCtrl = TextEditingController();
  final _passwordCtrl = TextEditingController();
  bool _obscurePassword = true;

  @override
  void dispose() {
    _nationalIdCtrl.dispose();
    _phoneCtrl.dispose();
    _passwordCtrl.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;
    final ctrl = Get.find<UnifiedAuthController>();
    final success = await ctrl.loginCustomer(
      nationalId: '', // AMIAL: الدخول بالهاتف + كلمة المرور فقط (الهوية تخصّ KYC)
      phone: _phoneCtrl.text.trim(),
      password: _passwordCtrl.text,
    );
    if (success && mounted) {
      // AMIAL-BIO-001: اعرض «تفعيل الدخول السريع» مرة واحدة بعد أول دخول ناجح
      try {
        if (!await AmialBiometricSetupScreen.isEnabled()) {
          await Get.to(() => AmialBiometricSetupScreen(
                phone: _phoneCtrl.text.trim(),
                password: _passwordCtrl.text,
              ));
        }
      } catch (_) {}
      ctrl.navigateToHomeForRole();
    } else if (!success && mounted) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(ctrl.lastError.value),
            backgroundColor: AmyalColors.red),
      );
    }
  }

  /// AMIAL-BIO-001: دخول ببصمة الإصبع — تحقق حيوي ثم دخول بالبيانات المحفوظة.
  Future<void> _bioLogin() async {
    try {
      if (!await AmialBiometricSetupScreen.isEnabled()) {
        if (mounted) {
          ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
              content: Text('فعّل الدخول السريع أولاً بالدخول بكلمة المرور'),
              backgroundColor: AmyalColors.red));
        }
        return;
      }
      final auth = LocalAuthentication();
      final ok = await auth.authenticate(
        localizedReason: 'الدخول إلى أميال باي',
        biometricOnly: true,
        persistAcrossBackgrounding: true,
      );
      if (!ok || !mounted) return;
      final creds = await AmialBiometricSetupScreen.savedCredentials();
      if (creds == null) return;
      final ctrl = Get.find<UnifiedAuthController>();
      final success = await ctrl.loginCustomer(
          nationalId: '', phone: creds.$1, password: creds.$2);
      if (success && mounted) {
        ctrl.navigateToHomeForRole();
      } else if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(
            content: Text(ctrl.lastError.value),
            backgroundColor: AmyalColors.red));
      }
    } catch (_) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
            content: Text('تعذّر التحقق الحيوي'),
            backgroundColor: AmyalColors.red));
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return SingleChildScrollView(
      padding: const EdgeInsets.all(20),
      child: Form(
        key: _formKey,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            const Padding(
              padding: EdgeInsets.symmetric(vertical: 12),
              child: Text('تسجيل دخول العميل',
                  style: TextStyle(fontSize: 16, fontWeight: FontWeight.w600)),
            ),
            _DemoCredsCard(entries: [
              _DemoEntry('عميل تجريبي', () {
                _phoneCtrl.text = '777100001';
                _passwordCtrl.text = 'Pass@2026';
              }),
              _DemoEntry('مستلِم للتحويل', () {
                _phoneCtrl.text = '777100002';
                _passwordCtrl.text = 'Pass@2026';
              }),
            ]),
            const SizedBox(height: 12),
            TextFormField(
              controller: _phoneCtrl,
              keyboardType: TextInputType.phone,
              decoration: const InputDecoration(
                labelText: 'رقم الجوال *',
                hintText: '7XXXXXXXX',
                prefixIcon: Icon(Icons.phone_outlined),
                border: OutlineInputBorder(),
              ),
              validator: (v) => (v == null || v.length < 6) ? 'رقم غير صحيح' : null,
            ),
            const SizedBox(height: 8),
            // AMYAL-UI-002: بادئات أرقام اليمن — ضغطة واحدة لبدء الرقم.
            _PhonePrefixChips(
              onPick: (p) {
                _phoneCtrl.text = p;
                _phoneCtrl.selection = TextSelection.fromPosition(
                    TextPosition(offset: _phoneCtrl.text.length));
                setState(() {});
              },
            ),
            const SizedBox(height: 12),
            TextFormField(
              controller: _passwordCtrl,
              obscureText: _obscurePassword,
              decoration: InputDecoration(
                labelText: 'كلمة المرور *',
                prefixIcon: const Icon(Icons.lock_outline),
                suffixIcon: IconButton(
                  icon: Icon(_obscurePassword ? Icons.visibility_off : Icons.visibility),
                  onPressed: () => setState(() => _obscurePassword = !_obscurePassword),
                ),
                border: const OutlineInputBorder(),
              ),
              validator: (v) => (v == null || v.length < 6) ? 'كلمة المرور 6 أحرف على الأقل' : null,
            ),
            const SizedBox(height: 20),
            Obx(() {
              final ctrl = Get.find<UnifiedAuthController>();
              return ElevatedButton(
                onPressed: ctrl.isSubmitting.value ? null : _submit,
                style: ElevatedButton.styleFrom(
                  backgroundColor: AmyalColors.primary,
                  foregroundColor: Colors.white,
                  padding: const EdgeInsets.symmetric(vertical: 14),
                ),
                child: ctrl.isSubmitting.value
                    ? const Row(
                        mainAxisAlignment: MainAxisAlignment.center,
                        children: [
                          SizedBox(
                              height: 18, width: 18,
                              child: CircularProgressIndicator(
                                  color: Colors.white, strokeWidth: 2)),
                          SizedBox(width: 10),
                          Text('جارٍ تسجيل الدخول...',
                              style: TextStyle(fontSize: 15, fontWeight: FontWeight.w600)),
                        ],
                      )
                    : const Text('دخول', style: TextStyle(fontSize: 16, fontWeight: FontWeight.w600)),
              );
            }),
            const SizedBox(height: 12),
            // AMIAL-BIO-001: دخول سريع ببصمة الإصبع — يظهر فقط إن كان مُفعّلاً.
            FutureBuilder<bool>(
              future: AmialBiometricSetupScreen.isEnabled(),
              builder: (context, snap) {
                if (snap.data != true) return const SizedBox.shrink();
                return Padding(
                  padding: const EdgeInsets.only(bottom: 4),
                  child: OutlinedButton.icon(
                    onPressed: _bioLogin,
                    icon: const Icon(Icons.fingerprint, size: 22),
                    label: const Text('الدخول ببصمة الإصبع'),
                    style: OutlinedButton.styleFrom(
                      foregroundColor: AmyalColors.primary,
                      side: const BorderSide(color: AmyalColors.primary),
                      padding: const EdgeInsets.symmetric(vertical: 13),
                      minimumSize: const Size.fromHeight(48),
                    ),
                  ),
                );
              },
            ),
            const SizedBox(height: 8),
            // AMIAL: رابط إنشاء حساب جديد (بالبيانات والوثائق)
            TextButton(
              onPressed: () => Get.to(() => const AmialRegistrationWizardScreen()),
              child: const Text('ليس لديك حساب؟ أنشئ حساباً الآن',
                  style: TextStyle(
                      color: AmyalColors.primary, fontWeight: FontWeight.w600)),
            ),
            // AMIAL-AUDIT: مساران كانا غير موصولين من الدخول الموحّد
            Row(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                TextButton(
                  onPressed: () => Get.to(() => const ForgetPinScreen()),
                  child: const Text('نسيت كلمة المرور؟',
                      style: TextStyle(color: Color(0xFF5F6B7C), fontSize: 13)),
                ),
                const Text('|', style: TextStyle(color: Color(0xFFCBD5D1))),
                TextButton(
                  onPressed: () => Get.to(() => const AccountRecoveryScreen()),
                  child: const Text('استعادة الحساب (رقم جديد)',
                      style: TextStyle(color: Color(0xFF5F6B7C), fontSize: 13)),
                ),
              ],
            ),
            const SizedBox(height: 4),
            const Divider(height: 1),
            const SizedBox(height: 4),
            // AMYAL-UI-002: تذييل — مركز المساعدة + تغيير اللغة.
            Row(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                TextButton.icon(
                  onPressed: () => Get.to(() => const SupportScreen()),
                  icon: const Icon(Icons.help_outline, size: 18, color: AmyalColors.primary),
                  label: const Text('مركز المساعدة',
                      style: TextStyle(color: AmyalColors.primary, fontSize: 13)),
                ),
                const Text('|', style: TextStyle(color: Color(0xFFCBD5D1))),
                TextButton.icon(
                  onPressed: () => Get.to(() => const ChooseLanguageScreen()),
                  icon: const Icon(Icons.language, size: 18, color: AmyalColors.primary),
                  label: const Text('اللغة',
                      style: TextStyle(color: AmyalColors.primary, fontSize: 13)),
                ),
              ],
            ),
            // AMYAL-SEC-LOGIN-001: آخر تسجيل دخول (يظهر فقط إن وُجد سجلّ سابق).
            const _LastLoginNote(),
          ],
        ),
      ),
    );
  }
}

// ============================================================
// Last-login security note — AMYAL-SEC-LOGIN-001
// ============================================================
class _LastLoginNote extends StatelessWidget {
  const _LastLoginNote();

  String _fmt(String iso) {
    final dt = DateTime.tryParse(iso);
    if (dt == null) return iso;
    final l = dt.toLocal();
    String two(int n) => n.toString().padLeft(2, '0');
    return '${l.year}/${two(l.month)}/${two(l.day)} - ${two(l.hour)}:${two(l.minute)}';
  }

  @override
  Widget build(BuildContext context) {
    final last = UnifiedAuthController.readLastLogin();
    if (last == null) return const SizedBox.shrink();
    return Container(
      margin: const EdgeInsets.only(top: 8),
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
      decoration: BoxDecoration(
        color: AmyalColors.primary.withValues(alpha: 0.04),
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: AmyalColors.primary.withValues(alpha: 0.12)),
      ),
      child: Row(
        children: [
          const Icon(Icons.shield_outlined, size: 20, color: AmyalColors.primary),
          const SizedBox(width: 10),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const Text('آخر تسجيل دخول لهذا الجهاز',
                    style: TextStyle(
                        fontSize: 12,
                        fontWeight: FontWeight.w600,
                        color: AmyalColors.primary)),
                const SizedBox(height: 2),
                Text(
                  '🕒 ${_fmt(last.at)}'
                  '${last.zone.isNotEmpty ? '   •   📍 ${last.zone}' : ''}',
                  style: const TextStyle(
                      fontSize: 11.5, color: AmyalColors.textSecondary),
                ),
                if (last.ip.isNotEmpty)
                  Text('IP: ${last.ip}',
                      style: const TextStyle(
                          fontSize: 10, color: AmyalColors.textMuted)),
                const Text('إن لم تكن أنت، غيّر كلمة المرور فوراً.',
                    style: TextStyle(fontSize: 10.5, color: AmyalColors.textMuted)),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

// ============================================================
// Phone prefix chips (Yemen) — AMYAL-UI-002
// ============================================================
class _PhonePrefixChips extends StatelessWidget {
  final void Function(String prefix) onPick;
  const _PhonePrefixChips({required this.onPick});

  @override
  Widget build(BuildContext context) {
    const prefixes = ['77', '78', '73', '71', '70'];
    return Align(
      alignment: Alignment.centerRight,
      child: Wrap(
        spacing: 6,
        children: prefixes.map((p) {
          return ActionChip(
            visualDensity: VisualDensity.compact,
            materialTapTargetSize: MaterialTapTargetSize.shrinkWrap,
            label: Text(p,
                style: const TextStyle(
                    fontSize: 12.5,
                    fontWeight: FontWeight.w600,
                    color: AmyalColors.primary)),
            backgroundColor: AmyalColors.primary.withValues(alpha: 0.06),
            side: BorderSide(color: AmyalColors.primary.withValues(alpha: 0.25)),
            onPressed: () => onPick(p),
          );
        }).toList(),
      ),
    );
  }
}

// ============================================================
// Merchant Login Tab
// ============================================================
class _MerchantLoginTab extends StatefulWidget {
  const _MerchantLoginTab();

  @override
  State<_MerchantLoginTab> createState() => _MerchantLoginTabState();
}

class _MerchantLoginTabState extends State<_MerchantLoginTab> {
  final _formKey = GlobalKey<FormState>();
  final _merchantNumCtrl = TextEditingController();
  final _phoneCtrl = TextEditingController();
  final _passwordCtrl = TextEditingController();
  final _posNumCtrl = TextEditingController();
  bool _obscurePassword = true;
  bool _isPosLogin = false;

  @override
  void dispose() {
    _merchantNumCtrl.dispose();
    _phoneCtrl.dispose();
    _passwordCtrl.dispose();
    _posNumCtrl.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;
    final ctrl = Get.find<UnifiedAuthController>();
    final success = await ctrl.loginMerchant(
      merchantNumber: _merchantNumCtrl.text.trim(),
      phone: _phoneCtrl.text.trim(),
      password: _passwordCtrl.text,
      posNumber: _isPosLogin ? _posNumCtrl.text.trim() : null,
    );
    if (success && mounted) {
      ctrl.navigateToHomeForRole();
    } else if (!success && mounted) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(ctrl.lastError.value),
            backgroundColor: AmyalColors.red),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    return SingleChildScrollView(
      padding: const EdgeInsets.all(20),
      child: Form(
        key: _formKey,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            const Padding(
              padding: EdgeInsets.symmetric(vertical: 12),
              child: Text('تسجيل دخول التاجر',
                  style: TextStyle(fontSize: 16, fontWeight: FontWeight.w600)),
            ),
            _DemoCredsCard(entries: [
              _DemoEntry('⛽ محطة وقود', () {
                _merchantNumCtrl.text = 'AM-FUEL-004';
                _phoneCtrl.text = '777200004';
                _passwordCtrl.text = 'Pass@2026';
              }, highlight: true),
              _DemoEntry('🛒 بقالة', () {
                _merchantNumCtrl.text = 'AM-GROC-001';
                _phoneCtrl.text = '777200001';
                _passwordCtrl.text = 'Pass@2026';
              }),
              _DemoEntry('💊 صيدلية', () {
                _merchantNumCtrl.text = 'AM-PHAR-003';
                _phoneCtrl.text = '777200003';
                _passwordCtrl.text = 'Pass@2026';
              }),
              _DemoEntry('🍽️ مطعم', () {
                _merchantNumCtrl.text = 'AM-REST-002';
                _phoneCtrl.text = '777200002';
                _passwordCtrl.text = 'Pass@2026';
              }),
              _DemoEntry('📦 جملة', () {
                _merchantNumCtrl.text = 'AM-WHOL-005';
                _phoneCtrl.text = '777200005';
                _passwordCtrl.text = 'Pass@2026';
              }),
              _DemoEntry('🐟 بيع سريع', () {
                _merchantNumCtrl.text = 'AM-FISH-006';
                _phoneCtrl.text = '777200006';
                _passwordCtrl.text = 'Pass@2026';
              }),
            ]),
            const SizedBox(height: 12),
            TextFormField(
              controller: _merchantNumCtrl,
              decoration: const InputDecoration(
                labelText: 'رقم التاجر *',
                prefixIcon: Icon(Icons.store),
                border: OutlineInputBorder(),
              ),
              validator: (v) => (v == null || v.length < 3) ? 'رقم تاجر غير صحيح' : null,
            ),
            const SizedBox(height: 12),
            TextFormField(
              controller: _phoneCtrl,
              keyboardType: TextInputType.phone,
              decoration: const InputDecoration(
                labelText: 'رقم الجوال *',
                prefixIcon: Icon(Icons.phone_outlined),
                border: OutlineInputBorder(),
              ),
              validator: (v) => (v == null || v.length < 6) ? 'رقم غير صحيح' : null,
            ),
            const SizedBox(height: 12),
            TextFormField(
              controller: _passwordCtrl,
              obscureText: _obscurePassword,
              decoration: InputDecoration(
                labelText: 'كلمة المرور *',
                prefixIcon: const Icon(Icons.lock_outline),
                suffixIcon: IconButton(
                  icon: Icon(_obscurePassword ? Icons.visibility_off : Icons.visibility),
                  onPressed: () => setState(() => _obscurePassword = !_obscurePassword),
                ),
                border: const OutlineInputBorder(),
              ),
              validator: (v) => (v == null || v.length < 6) ? 'كلمة المرور قصيرة' : null,
            ),
            const SizedBox(height: 16),
            CheckboxListTile(
              title: const Text('دخول كموظف نقطة بيع'),
              subtitle: const Text('سجل بـ POS number',
                  style: TextStyle(fontSize: 11)),
              value: _isPosLogin,
              activeColor: AmyalColors.primary,
              onChanged: (v) => setState(() => _isPosLogin = v ?? false),
              controlAffinity: ListTileControlAffinity.leading,
              dense: true,
            ),
            if (_isPosLogin)
              Padding(
                padding: const EdgeInsets.only(top: 12),
                child: TextFormField(
                  controller: _posNumCtrl,
                  decoration: const InputDecoration(
                    labelText: 'رقم نقطة البيع *',
                    hintText: 'POS-001',
                    prefixIcon: Icon(Icons.point_of_sale),
                    border: OutlineInputBorder(),
                  ),
                  validator: (v) {
                    if (!_isPosLogin) return null;
                    return (v == null || v.isEmpty) ? 'مطلوب' : null;
                  },
                ),
              ),
            const SizedBox(height: 20),
            Obx(() {
              final ctrl = Get.find<UnifiedAuthController>();
              return ElevatedButton(
                onPressed: ctrl.isSubmitting.value ? null : _submit,
                style: ElevatedButton.styleFrom(
                  backgroundColor: AmyalColors.primary,
                  foregroundColor: Colors.white,
                  padding: const EdgeInsets.symmetric(vertical: 14),
                ),
                child: ctrl.isSubmitting.value
                    ? const SizedBox(
                        height: 20, width: 20,
                        child: CircularProgressIndicator(
                            color: Colors.white, strokeWidth: 2))
                    : const Text('دخول', style: TextStyle(fontSize: 16, fontWeight: FontWeight.w600)),
              );
            }),
          ],
        ),
      ),
    );
  }
}

// ============================================================
// Agent Login Tab (2-step with OTP)
// ============================================================
class _AgentLoginTab extends StatefulWidget {
  const _AgentLoginTab();

  @override
  State<_AgentLoginTab> createState() => _AgentLoginTabState();
}

class _AgentLoginTabState extends State<_AgentLoginTab> {
  final _formKey = GlobalKey<FormState>();
  final _agentNumCtrl = TextEditingController();
  final _passwordCtrl = TextEditingController();
  final _otpCtrl = TextEditingController();
  bool _obscurePassword = true;
  bool _otpStep = false;
  String _maskedPhone = '';

  @override
  void dispose() {
    _agentNumCtrl.dispose();
    _passwordCtrl.dispose();
    _otpCtrl.dispose();
    super.dispose();
  }

  Future<void> _submitStep1() async {
    if (!_formKey.currentState!.validate()) return;
    final ctrl = Get.find<UnifiedAuthController>();
    final result = await ctrl.loginAgentStep1(
      agentNumber: _agentNumCtrl.text.trim(),
      password: _passwordCtrl.text,
    );
    if (result != null && mounted) {
      setState(() {
        _otpStep = true;
        _maskedPhone = result;
      });
    } else if (mounted) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(ctrl.lastError.value),
            backgroundColor: AmyalColors.red),
      );
    }
  }

  Future<void> _submitOtp() async {
    if (_otpCtrl.text.length != 6) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('الرمز 6 أرقام')),
      );
      return;
    }
    final ctrl = Get.find<UnifiedAuthController>();
    final success = await ctrl.loginAgentStep2(_otpCtrl.text.trim());
    if (success && mounted) {
      ctrl.navigateToHomeForRole();
    } else if (!success && mounted) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(ctrl.lastError.value),
            backgroundColor: AmyalColors.red),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    return SingleChildScrollView(
      padding: const EdgeInsets.all(20),
      child: Form(
        key: _formKey,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            const Padding(
              padding: EdgeInsets.symmetric(vertical: 12),
              child: Text('تسجيل دخول الوكيل',
                  style: TextStyle(fontSize: 16, fontWeight: FontWeight.w600)),
            ),
            if (!_otpStep) ...[
              _DemoCredsCard(entries: [
                _DemoEntry('وكيل تجريبي', () {
                  _agentNumCtrl.text = 'AG-001';
                  _passwordCtrl.text = 'Pass@2026';
                }),
              ]),
              const SizedBox(height: 12),
              // ====== Step 1 ======
              TextFormField(
                controller: _agentNumCtrl,
                decoration: const InputDecoration(
                  labelText: 'رقم الوكيل *',
                  prefixIcon: Icon(Icons.business_center),
                  border: OutlineInputBorder(),
                ),
                validator: (v) => (v == null || v.length < 3) ? 'رقم وكيل غير صحيح' : null,
              ),
              const SizedBox(height: 12),
              TextFormField(
                controller: _passwordCtrl,
                obscureText: _obscurePassword,
                decoration: InputDecoration(
                  labelText: 'كلمة المرور *',
                  prefixIcon: const Icon(Icons.lock_outline),
                  suffixIcon: IconButton(
                    icon: Icon(_obscurePassword ? Icons.visibility_off : Icons.visibility),
                    onPressed: () => setState(() => _obscurePassword = !_obscurePassword),
                  ),
                  border: const OutlineInputBorder(),
                ),
                validator: (v) => (v == null || v.length < 6) ? 'كلمة المرور قصيرة' : null,
              ),
              const SizedBox(height: 20),
              Obx(() {
                final ctrl = Get.find<UnifiedAuthController>();
                return ElevatedButton(
                  onPressed: ctrl.isSubmitting.value ? null : _submitStep1,
                  style: ElevatedButton.styleFrom(
                    backgroundColor: AmyalColors.primary,
                    foregroundColor: Colors.white,
                    padding: const EdgeInsets.symmetric(vertical: 14),
                  ),
                  child: ctrl.isSubmitting.value
                      ? const SizedBox(
                          height: 20, width: 20,
                          child: CircularProgressIndicator(
                              color: Colors.white, strokeWidth: 2))
                      : const Text('إرسال رمز التحقق',
                          style: TextStyle(fontSize: 16, fontWeight: FontWeight.w600)),
                );
              }),
            ] else ...[
              // ====== Step 2: OTP ======
              Container(
                padding: const EdgeInsets.all(12),
                decoration: BoxDecoration(
                  color: AmyalColors.yellow.withValues(alpha: 0.2),
                  borderRadius: BorderRadius.circular(8),
                ),
                child: Column(
                  children: [
                    const Icon(Icons.sms, color: AmyalColors.primary, size: 32),
                    const SizedBox(height: 8),
                    Text('تم إرسال رمز التحقق إلى $_maskedPhone',
                        textAlign: TextAlign.center,
                        style: const TextStyle(fontSize: 12)),
                  ],
                ),
              ),
              const SizedBox(height: 16),
              TextFormField(
                controller: _otpCtrl,
                keyboardType: TextInputType.number,
                maxLength: 6,
                textAlign: TextAlign.center,
                style: const TextStyle(
                    fontSize: 24, fontWeight: FontWeight.bold, letterSpacing: 8),
                decoration: const InputDecoration(
                  labelText: 'رمز التحقق (6 أرقام)',
                  border: OutlineInputBorder(),
                ),
              ),
              const SizedBox(height: 16),
              Obx(() {
                final ctrl = Get.find<UnifiedAuthController>();
                return ElevatedButton(
                  onPressed: ctrl.isSubmitting.value ? null : _submitOtp,
                  style: ElevatedButton.styleFrom(
                    backgroundColor: AmyalColors.primary,
                    foregroundColor: Colors.white,
                    padding: const EdgeInsets.symmetric(vertical: 14),
                  ),
                  child: ctrl.isSubmitting.value
                      ? const SizedBox(
                          height: 20, width: 20,
                          child: CircularProgressIndicator(
                              color: Colors.white, strokeWidth: 2))
                      : const Text('تأكيد ودخول',
                          style: TextStyle(fontSize: 16, fontWeight: FontWeight.w600)),
                );
              }),
              const SizedBox(height: 8),
              TextButton(
                onPressed: () => setState(() {
                  _otpStep = false;
                  _otpCtrl.clear();
                }),
                child: const Text('رجوع'),
              ),
            ],
          ],
        ),
      ),
    );
  }
}

// ============================================================
// Admin Login Tab — بريد + كلمة مرور (لوحة الإدارة داخل التطبيق)
// ============================================================
class _AdminLoginTab extends StatefulWidget {
  const _AdminLoginTab();

  @override
  State<_AdminLoginTab> createState() => _AdminLoginTabState();
}

class _AdminLoginTabState extends State<_AdminLoginTab> {
  final _formKey = GlobalKey<FormState>();
  final _emailCtrl = TextEditingController();
  final _passwordCtrl = TextEditingController();
  bool _obscure = true;

  @override
  void dispose() {
    _emailCtrl.dispose();
    _passwordCtrl.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;
    final ctrl = Get.find<UnifiedAuthController>();
    final ok = await ctrl.loginAdmin(
      email: _emailCtrl.text.trim(),
      password: _passwordCtrl.text,
    );
    if (ok && mounted) {
      ctrl.navigateToHomeForRole();
    } else if (mounted) {
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
          content: Text(ctrl.lastError.value),
          backgroundColor: AmyalColors.red));
    }
  }

  @override
  Widget build(BuildContext context) {
    return SingleChildScrollView(
      padding: const EdgeInsets.all(20),
      child: Form(
        key: _formKey,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            const Padding(
              padding: EdgeInsets.symmetric(vertical: 12),
              child: Text('دخول مدير النظام',
                  style: TextStyle(fontSize: 16, fontWeight: FontWeight.w600)),
            ),
            _DemoCredsCard(entries: [
              _DemoEntry('أدمن تجريبي', () {
                _emailCtrl.text = 'admin@amyalpay.com';
                _passwordCtrl.text = 'Pass@2026';
              }),
            ]),
            const SizedBox(height: 12),
            TextFormField(
              controller: _emailCtrl,
              keyboardType: TextInputType.emailAddress,
              textDirection: TextDirection.ltr,
              decoration: const InputDecoration(
                labelText: 'البريد الإلكتروني *',
                hintText: 'admin@amyalpay.com',
                prefixIcon: Icon(Icons.alternate_email),
                border: OutlineInputBorder(),
              ),
              validator: (v) =>
                  (v == null || !v.contains('@')) ? 'بريد غير صحيح' : null,
            ),
            const SizedBox(height: 12),
            TextFormField(
              controller: _passwordCtrl,
              obscureText: _obscure,
              decoration: InputDecoration(
                labelText: 'كلمة المرور *',
                prefixIcon: const Icon(Icons.lock_outline),
                suffixIcon: IconButton(
                  icon:
                      Icon(_obscure ? Icons.visibility_off : Icons.visibility),
                  onPressed: () => setState(() => _obscure = !_obscure),
                ),
                border: const OutlineInputBorder(),
              ),
              validator: (v) =>
                  (v == null || v.length < 6) ? '6 أحرف على الأقل' : null,
            ),
            const SizedBox(height: 20),
            Obx(() {
              final ctrl = Get.find<UnifiedAuthController>();
              return ElevatedButton.icon(
                onPressed: ctrl.isSubmitting.value ? null : _submit,
                icon: ctrl.isSubmitting.value
                    ? const SizedBox(
                        height: 18,
                        width: 18,
                        child: CircularProgressIndicator(
                            color: Colors.white, strokeWidth: 2))
                    : const Icon(Icons.admin_panel_settings_outlined),
                label: const Text('دخول لوحة الإدارة',
                    style:
                        TextStyle(fontSize: 16, fontWeight: FontWeight.w600)),
                style: ElevatedButton.styleFrom(
                  backgroundColor: AmyalColors.primary,
                  foregroundColor: Colors.white,
                  padding: const EdgeInsets.symmetric(vertical: 14),
                ),
              );
            }),
            const SizedBox(height: 16),
            const Text(
              'لوحة الإدارة الكاملة متاحة أيضاً عبر المتصفح على مسار /admin في خادمك.',
              textAlign: TextAlign.center,
              style: TextStyle(fontSize: 11, color: AmyalColors.textMuted),
            ),
          ],
        ),
      ),
    );
  }
}

// ============================================================
// Demo credentials helper — تعبئة بيانات الدخول التجريبية بضغطة واحدة
// ============================================================
class _DemoEntry {
  final String label;
  final VoidCallback onFill;
  final bool highlight;
  const _DemoEntry(this.label, this.onFill, {this.highlight = false});
}

class _DemoCredsCard extends StatelessWidget {
  final List<_DemoEntry> entries;
  const _DemoCredsCard({required this.entries});

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.fromLTRB(12, 10, 12, 12),
      decoration: BoxDecoration(
        color: AmyalColors.yellow.withValues(alpha: 0.12),
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: AmyalColors.yellow.withValues(alpha: 0.5)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Row(
            children: [
              Icon(Icons.vpn_key_rounded, size: 16, color: AmyalColors.primary),
              SizedBox(width: 6),
              Text('بيانات تجريبية — اضغط للتعبئة',
                  style: TextStyle(
                      fontSize: 12,
                      fontWeight: FontWeight.w700,
                      color: AmyalColors.primary)),
            ],
          ),
          const SizedBox(height: 8),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: entries.map((e) {
              return ActionChip(
                label: Text(e.label,
                    style: TextStyle(
                        fontSize: 12.5,
                        fontWeight:
                            e.highlight ? FontWeight.bold : FontWeight.w500,
                        color: e.highlight ? Colors.white : AmyalColors.primary)),
                backgroundColor:
                    e.highlight ? AmyalColors.primary : Colors.white,
                side: BorderSide(
                    color: e.highlight
                        ? AmyalColors.primary
                        : AmyalColors.primary.withValues(alpha: 0.35)),
                onPressed: () {
                  e.onFill();
                  ScaffoldMessenger.of(context).showSnackBar(
                    SnackBar(
                      content: Text('تم تعبئة بيانات: ${e.label}'),
                      duration: const Duration(milliseconds: 900),
                      backgroundColor: AmyalColors.primary,
                    ),
                  );
                },
              );
            }).toList(),
          ),
        ],
      ),
    );
  }
}

/// AMIAL-BUILD-STAMP — علامة الإصدار: تُقرأ تلقائياً من بيانات البناء
/// (package_info) فلا تتقادم مع كل إصدار. تظهر أسفل شعار الدخول.
class _VersionStamp extends StatefulWidget {
  const _VersionStamp();

  @override
  State<_VersionStamp> createState() => _VersionStampState();
}

class _VersionStampState extends State<_VersionStamp> {
  String _label = '';

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final info = await PackageInfo.fromPlatform();
      if (!mounted) return;
      // AMYAL-UI-002: تنسيق هادئ وصغير — «الإصدار 1.33.0 (1330)».
      setState(() => _label = 'الإصدار ${info.version} (${info.buildNumber})');
    } catch (_) {
      if (mounted) setState(() => _label = '');
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_label.isEmpty) return const SizedBox(height: 14);
    return Text(
      _label,
      style: TextStyle(
          color: Colors.white.withValues(alpha: 0.6),
          fontSize: 10.5,
          fontWeight: FontWeight.w500),
    );
  }
}
