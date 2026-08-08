import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:get/get.dart';
import 'package:amial_pay/features/auth/controllers/unified_auth_controller.dart';
import 'package:amial_pay/features/auth/screens/amial_registration_wizard_screen.dart';
import 'package:amial_pay/features/auth/screens/amial_biometric_setup_screen.dart';
import 'package:local_auth/local_auth.dart';
import 'package:amial_pay/features/forget_pin/screens/forget_pin_screen.dart';
import 'package:amial_pay/features/amial/screens/account_recovery_screen.dart';
import 'package:amial_pay/features/setting/screens/support_screen.dart';
import 'package:amial_pay/features/language/widgets/amial_language_switch.dart';
import 'package:amial_pay/theme/amial_colors.dart';
import 'package:amial_pay/util/images.dart';
import 'package:amial_pay/common/widgets/amial_button.dart';
import 'package:amial_pay/util/secure_screen.dart';
import 'package:amial_pay/common/widgets/amial_build_stamp.dart';

/// AMIAL-UNIFIED-AUTH-002 — شاشة دخول واحدة بقائمة نوع الحساب.
///
/// بدل أربعة تبويبات منفصلة، أصبحت الشاشة نموذجاً واحداً:
///   • قائمة منسدلة تختار نوع الحساب (عميل / تاجر / نقطة بيع).
///   • الأساس دائماً: المُعرِّف + كلمة المرور — وتظهر حقول إضافية حسب النوع
///     احتراماً لعقد الخادم (التاجر يحتاج رقم تاجر، نقطة البيع تحتاج رقمها).
///   • صندوق «بيانات تجريبية» أسفل النموذج يُعبّئ الحقول بضغطة حسب النوع.
///
/// ══════════════════════════════════════════════════════════════════════
/// **AMIAL-LOGIN-GATES-001 — ولا وكيلَ ولا أدمن هنا.**
///
/// كانت القائمة تعرض خمسة أنواع. وقِيس فوجد **صفرُ شاشةٍ للوكيل وصفرُ
/// شاشةٍ للأدمن في التطبيق كلّه**: من دخل بأيّهما وصل إلى
/// `WebPortalNoticeScreen` — لافتةٌ تقول «استعمل بوّابة الويب».
///
/// **فبابان يُفتحان على لافتة.** ولا يقف الضررُ عند الحيرة: يُدخل الوكيلُ
/// رقمَه وكلمتَه في شاشةٍ لا تخدمه، ويرى الأدمنُ حقلَ بريدٍ يوحي بأنّ
/// لوحة الإدارة في جيبه. والتطبيق للعميل والتاجر ونقطة البيع.
///
/// **ولم يُحذف شيءٌ من الخادم:** `loginAgentStep1` و`loginAdmin` تبقيان —
/// بوّابتا الويب (`/agent/login` و`/admin/auth/login`) تستعملانهما.
/// وإخفاءُ الواجهة ليس حمايةً، والحمايةُ حيث كانت: في الوسائط.
///
/// **ويبقى `RoleRouter` يوجّه دورَي agent/admin إلى لافتة الويب** — فجلسةٌ
/// محفوظةٌ من نسخةٍ أقدم قد تحمل أحدَهما، ومن يُرقّي التطبيق لا يُترك
/// أمام شاشةٍ بيضاء.
enum AccountKind { customer, merchant, pos }

extension _KindMeta on AccountKind {
  String get label => switch (this) {
        AccountKind.customer => 'عميل',
        AccountKind.merchant => 'تاجر',
        AccountKind.pos => 'نقطة بيع',
      };

  IconData get icon => switch (this) {
        AccountKind.customer => Icons.person,
        AccountKind.merchant => Icons.store,
        AccountKind.pos => Icons.point_of_sale,
      };

  Color get color => switch (this) {
        AccountKind.customer => const Color(0xFF053391),
        AccountKind.merchant => const Color(0xFF1B9E4B),
        AccountKind.pos => const Color(0xFF0E7C7B),
      };

  /// وصف قصير يوضّح لمن هذا النوع.
  String get hint => switch (this) {
        AccountKind.customer => 'محفظتك الشخصية: تحويل، فواتير، سحب',
        AccountKind.merchant => 'حساب المتجر الرئيسي',
        AccountKind.pos => 'دخول موظّف على نقطة بيع تابعة لتاجر',
      };
}

class UnifiedLoginScreen extends StatefulWidget {
  const UnifiedLoginScreen({super.key});

  @override
  State<UnifiedLoginScreen> createState() => _UnifiedLoginScreenState();
}

class _UnifiedLoginScreenState extends State<UnifiedLoginScreen> {
  final _formKey = GlobalKey<FormState>();

  AccountKind _kind = AccountKind.customer;

  final _phoneCtrl = TextEditingController();
  final _passwordCtrl = TextEditingController();
  final _merchantNumCtrl = TextEditingController();
  final _posNumCtrl = TextEditingController();

  bool _obscure = true;
  /// AMIAL-LOGIN-UI-002: بادئة الشبكة المختارة داخل حقل الهاتف.
  String _netPrefix = '77';

  /// AMIAL-LOGIN-UI-003: هوية آخر مستخدم على هذا الجهاز (اسم + رقم، بلا
  /// بيانات اعتماد) — تُستعمل للتحية وتعبئة الرقم.
  ({String name, String phone, String kind})? _lastUser;

  // AMIAL-SEC-CAPTURE-001: كلمة المرور تُكتب في هذه الشاشة — نمنع لقطة الشاشة
  // وتسجيلها ما دامت معروضة، ونُعيد السماح فور مغادرتها (فتبقى الإيصالات
  // وبقيّة الشاشات قابلة للتصوير والمشاركة).
  @override
  void initState() {
    super.initState();
    SecureScreen.enable();
    final u = UnifiedAuthController.readLastUser();
    if (u != null) {
      _lastUser = u;
      _phoneCtrl.text = u.phone;
      final p = u.phone.replaceAll(RegExp(r'[^0-9]'), '');
      if (p.length >= 2 &&
          const ['77', '78', '73', '71', '70'].contains(p.substring(0, 2))) {
        _netPrefix = p.substring(0, 2);
      }
      _kind = AccountKind.values.firstWhere(
        (k) => k.name == u.kind,
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

  void _switchKind(AccountKind k) {
    setState(() {
      _kind = k;
    });
  }

  // ============================================================
  // الإرسال — يوجّه لكل دور حسب عقد الخادم
  // ============================================================
  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;
    final ctrl = Get.find<UnifiedAuthController>();
    bool ok = false;

    switch (_kind) {
      case AccountKind.customer:
        ok = await ctrl.loginCustomer(
          nationalId: '',
          phone: _phoneCtrl.text.trim(),
          password: _passwordCtrl.text,
        );
        // AMIAL-BIO-002: لم نعد نفرض شاشة «تفعيل الدخول السريع» بعد الدخول.
        // كانت تُقحَم بعد كل دخول ما دامت البصمة غير مفعّلة — أي في كل مرة
        // لمن لا يريدها، فتبدو شاشة طفيلية بين الدخول والرئيسية. التفعيل
        // متاح حين يريده المستخدم: زرّ «الدخول بالبصمة» في شاشة الدخول،
        // ومفتاح «تسجيل الدخول البيومتري» في الإعدادات.
        break;

      case AccountKind.merchant:
        ok = await ctrl.loginMerchant(
          merchantNumber: _merchantNumCtrl.text.trim(),
          phone: _phoneCtrl.text.trim(),
          password: _passwordCtrl.text,
        );
        break;

      case AccountKind.pos:
        ok = await ctrl.loginMerchant(
          merchantNumber: _merchantNumCtrl.text.trim(),
          phone: _phoneCtrl.text.trim(),
          password: _passwordCtrl.text,
          posNumber: _posNumCtrl.text.trim(),
        );
        break;

    }

    if (!mounted) return;
    if (ok) {
      // AMIAL-LOGIN-UI-003: نحفظ الاسم والرقم فقط — لا رمز سري ولا رمز وصول.
      await UnifiedAuthController.rememberLastUser(
        name: ctrl.displayName,
        phone: _phoneCtrl.text.trim(),
        kind: _kind.name,
      );
      ctrl.navigateToHomeForRole();
    } else {
      _error(ctrl.lastError.value.isNotEmpty
          ? ctrl.lastError.value
          : 'فشل تسجيل الدخول');
    }
  }


  /// AMIAL-BIO-001: دخول ببصمة الإصبع (للعميل فقط).
  Future<void> _bioLogin() async {
    try {
      if (!await AmialBiometricSetupScreen.isEnabled()) {
        // AMIAL-BIO-002: بدل رسالة خطأ تُغلق الطريق، نفتح شاشة التفعيل —
        // هذا ما يتوقّعه من ضغط الزرّ. وتحتاج الشاشة الرقم والرمز، فإن كان
        // الحقلان فارغين نطلب ملأهما أولاً.
        final phone = _phoneCtrl.text.trim();
        final pass = _passwordCtrl.text;
        if (phone.isEmpty || pass.length < 4) {
          _error('أدخل رقمك وكلمة مرورك أولاً لتفعيل الدخول بالبصمة');
          return;
        }
        if (!mounted) return;
        await Get.to(() =>
            AmialBiometricSetupScreen(phone: phone, password: pass));
        if (!await AmialBiometricSetupScreen.isEnabled()) return;
      }
      final auth = LocalAuthentication();
      final okBio = await auth.authenticate(
        localizedReason: 'الدخول إلى أميال باي',
        biometricOnly: true,
        persistAcrossBackgrounding: true,
      );
      if (!okBio || !mounted) return;
      final creds = await AmialBiometricSetupScreen.savedCredentials();
      if (creds == null) return;
      final ctrl = Get.find<UnifiedAuthController>();
      final ok = await ctrl.loginCustomer(
          nationalId: '', phone: creds.$1, password: creds.$2);
      if (!mounted) return;
      if (ok) {
        ctrl.navigateToHomeForRole();
      } else {
        _error(ctrl.lastError.value);
      }
    } catch (_) {
      _error('تعذّر التحقق الحيوي');
    }
  }

  void _error(String m) {
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(content: Text(m), backgroundColor: AmialColors.red),
    );
  }

  // ============================================================
  // بيانات تجريبية حسب النوع
  // ============================================================
  List<_Demo> get _demos => switch (_kind) {
        AccountKind.customer => [
            _Demo('عميل تجريبي', () {
              _phoneCtrl.text = '777100001';
              _passwordCtrl.text = 'Pass@2026';
            }),
            _Demo('مستلِم للتحويل', () {
              _phoneCtrl.text = '777100002';
              _passwordCtrl.text = 'Pass@2026';
            }),
          ],
        AccountKind.merchant || AccountKind.pos => [
            _Demo('⛽ محطة وقود', () {
              _merchantNumCtrl.text = 'AM-FUEL-004';
              _phoneCtrl.text = '777200004';
              _passwordCtrl.text = 'Pass@2026';
            }, highlight: true),
            _Demo('🛒 بقالة', () {
              _merchantNumCtrl.text = 'AM-GROC-001';
              _phoneCtrl.text = '777200001';
              _passwordCtrl.text = 'Pass@2026';
            }),
            _Demo('💊 صيدلية', () {
              _merchantNumCtrl.text = 'AM-PHAR-003';
              _phoneCtrl.text = '777200003';
              _passwordCtrl.text = 'Pass@2026';
            }),
            _Demo('🍽️ مطعم', () {
              _merchantNumCtrl.text = 'AM-REST-002';
              _phoneCtrl.text = '777200002';
              _passwordCtrl.text = 'Pass@2026';
            }),
            _Demo('📦 جملة', () {
              _merchantNumCtrl.text = 'AM-WHOL-005';
              _phoneCtrl.text = '777200005';
              _passwordCtrl.text = 'Pass@2026';
            }),
          ],
      };

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmialColors.background,
      body: SafeArea(
        child: ListView(
          padding: const EdgeInsets.fromLTRB(20, 0, 20, 20),
          children: [
            _topBar(),
            const SizedBox(height: 14),
            _greeting(),
            const SizedBox(height: 16),
            _kindSelector(),
            const SizedBox(height: 14),
            Form(key: _formKey, child: _formFields()),
            const SizedBox(height: 10),
            _forgotLink(),
            const SizedBox(height: 8),
            _primaryAction(),
            const SizedBox(height: 10),
            _altLoginRow(),
            const SizedBox(height: 14),
            _createAccountLine(),
            const SizedBox(height: 14),
            _demoBox(),
            const _LastLoginNote(),
            const SizedBox(height: 18),
            _quickLinks(),
            const SizedBox(height: 12),
            const Center(child: AmialBuildStamp()),
          ],
        ),
      ),
    );
  }

  // ===== الشريط العلوي: شعار في الوسط، لغة ومساعدة على الطرفين =====
  // AMIAL-LOGIN-UI-003: كانت كتلة زرقاء متدرّجة بارتفاع ~200 بكسل تحمل
  // الشعار والعنوان والوصف ورقم الإصدار — تبتلع ثلث الشاشة قبل أي حقل.
  // المحافظ المهنية تضع شريطاً خفيفاً وتترك المساحة للنموذج.
  Widget _topBar() {
    return Padding(
      padding: const EdgeInsets.only(top: 6, bottom: 2),
      child: Row(
        children: [
          const AmialLanguageChip(),
          const Spacer(),
          Image.asset(Images.logo, height: 38, fit: BoxFit.contain),
          const Spacer(),
          InkWell(
            onTap: () => Get.to(() => const SupportScreen()),
            borderRadius: BorderRadius.circular(12),
            child: Container(
              width: 38,
              height: 38,
              decoration: BoxDecoration(
                color: Colors.white,
                borderRadius: BorderRadius.circular(12),
                border: Border.all(color: AmialColors.border),
              ),
              child: const Icon(Icons.headset_mic_outlined,
                  size: 19, color: AmialColors.primary),
            ),
          ),
        ],
      ),
    );
  }

  // ===== التحية: يعرف من يقف أمامه =====
  Widget _greeting() {
    final u = _lastUser;
    if (u == null) {
      return const Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text('مرحباً بك',
              textAlign: TextAlign.center,
              style: TextStyle(
                  fontSize: 21,
                  fontWeight: FontWeight.bold,
                  color: Color(0xFF1A2433))),
          SizedBox(height: 3),
          Text('سجّل الدخول للمتابعة إلى محفظتك',
              textAlign: TextAlign.center,
              style: TextStyle(fontSize: 12.5, color: AmialColors.textSecondary)),
        ],
      );
    }
    return Column(
      children: [
        Text(_dayGreeting,
            style: const TextStyle(
                fontSize: 12.5, color: AmialColors.textSecondary)),
        const SizedBox(height: 2),
        Text(u.name.isEmpty ? 'أهلاً بعودتك' : 'أهلاً، ${u.name}',
            textAlign: TextAlign.center,
            style: const TextStyle(
                fontSize: 21,
                fontWeight: FontWeight.bold,
                color: Color(0xFF1A2433))),
        TextButton(
          onPressed: _forgetUser,
          style: TextButton.styleFrom(
              minimumSize: Size.zero,
              padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
              tapTargetSize: MaterialTapTargetSize.shrinkWrap),
          child: const Text('لستَ أنت؟ تسجيل بحساب آخر',
              style: TextStyle(fontSize: 11.5, color: AmialColors.textMuted)),
        ),
      ],
    );
  }

  String get _dayGreeting {
    final h = DateTime.now().hour;
    if (h < 12) return 'صباح الخير';
    if (h < 17) return 'طاب يومك';
    return 'مساء الخير';
  }

  Future<void> _forgetUser() async {
    await UnifiedAuthController.forgetLastUser();
    if (!mounted) return;
    setState(() {
      _lastUser = null;
      _phoneCtrl.clear();
      _passwordCtrl.clear();
    });
  }

  Widget _forgotLink() {
    return Align(
      alignment: Alignment.centerLeft,
      child: TextButton(
        onPressed: () => Get.to(() => const ForgetPinScreen()),
        style: TextButton.styleFrom(
            minimumSize: Size.zero,
            padding: const EdgeInsets.symmetric(horizontal: 4, vertical: 2),
            tapTargetSize: MaterialTapTargetSize.shrinkWrap),
        child: const Text('نسيت كلمة المرور؟',
            style: TextStyle(fontSize: 12.5, color: AmialColors.primary)),
      ),
    );
  }

  // ===== صفّ الدخول البديل: بصمة + بدون إنترنت =====
  Widget _altLoginRow() {
    if (_kind != AccountKind.customer) return const SizedBox.shrink();
    return Row(
      children: [
        Expanded(
          child: _altButton(
            icon: Icons.fingerprint_rounded,
            label: 'الدخول بالبصمة',
            onTap: _bioLogin,
          ),
        ),
        const SizedBox(width: 10),
        Expanded(
          child: _altButton(
            icon: Icons.wifi_off_rounded,
            label: 'دخول بدون نت',
            enabled: false,
            onTap: _offlineLoginSoon,
          ),
        ),
      ],
    );
  }

  /// AMIAL-LOGIN-OFFLINE-000: الزرّ ظاهر ومعطّل عمداً — الميزة لم تُبنَ بعد.
  /// نُعلن ذلك صراحةً بدل فشل صامت: الضغط يشرح الحال ولا يوهم بالعمل.
  void _offlineLoginSoon() {
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(
      const SnackBar(
        content: Text('الدخول بدون إنترنت قيد التطوير — سيتوفّر قريباً'),
        backgroundColor: AmialColors.primary,
      ),
    );
  }

  Widget _altButton({
    required IconData icon,
    required String label,
    required VoidCallback onTap,
    bool enabled = true,
  }) {
    final fg = enabled ? AmialColors.primary : AmialColors.textMuted;
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(14),
      child: Container(
        height: 48,
        decoration: BoxDecoration(
          color: enabled ? Colors.white : const Color(0xFFF7F8FA),
          borderRadius: BorderRadius.circular(14),
          border: Border.all(
              color: enabled
                  ? AmialColors.primary.withValues(alpha: 0.30)
                  : AmialColors.border),
        ),
        child: Row(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Icon(icon, size: 19, color: fg),
            const SizedBox(width: 7),
            Flexible(
              child: Text(label,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: TextStyle(
                      fontSize: 12.5, fontWeight: FontWeight.w600, color: fg)),
            ),
          ],
        ),
      ),
    );
  }

  Widget _createAccountLine() {
    return Center(
      child: Wrap(
        alignment: WrapAlignment.center,
        crossAxisAlignment: WrapCrossAlignment.center,
        children: [
          const Text('ليس لديك حساب؟ ',
              style: TextStyle(fontSize: 13, color: AmialColors.textSecondary)),
          InkWell(
            onTap: () => Get.to(() => const AmialRegistrationWizardScreen()),
            child: const Text('أنشئ حساباً جديداً',
                style: TextStyle(
                    fontSize: 13,
                    fontWeight: FontWeight.bold,
                    color: AmialColors.primary)),
          ),
        ],
      ),
    );
  }

  // ===== اختصارات ما قبل الدخول =====
  // AMIAL-LOGIN-UI-003: من نسي رمزه أو تعطّل حسابه يحتاج مساعدة *قبل* الدخول
  // لا بعده. المحافظ المهنية تضع هذه الاختصارات في شاشة الدخول نفسها.
  Widget _quickLinks() {
    return Row(
      mainAxisAlignment: MainAxisAlignment.spaceEvenly,
      children: [
        _quickLink(Icons.support_agent_rounded, 'خدمة العملاء',
            () => Get.to(() => const SupportScreen())),
        _quickLink(Icons.restore_rounded, 'استعادة حساب',
            () => Get.to(() => const AccountRecoveryScreen())),
        _quickLink(Icons.lock_reset_rounded, 'نسيت كلمة المرور',
            () => Get.to(() => const ForgetPinScreen())),
      ],
    );
  }

  Widget _quickLink(IconData icon, String label, VoidCallback onTap) {
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(14),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 4),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Container(
              width: 46,
              height: 46,
              decoration: BoxDecoration(
                color: Colors.white,
                borderRadius: BorderRadius.circular(14),
                border: Border.all(color: AmialColors.border),
              ),
              child: Icon(icon, size: 21, color: AmialColors.primary),
            ),
            const SizedBox(height: 6),
            Text(label,
                style: const TextStyle(
                    fontSize: 11, color: AmialColors.textSecondary)),
          ],
        ),
      ),
    );
  }

  // ===== القائمة المنسدلة لنوع الحساب =====
  Widget _kindSelector() {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 4),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: AmialColors.border),
      ),
      child: DropdownButtonHideUnderline(
        child: DropdownButton<AccountKind>(
          value: _kind,
          isExpanded: true,
          borderRadius: BorderRadius.circular(14),
          icon: const Icon(Icons.keyboard_arrow_down_rounded,
              color: AmialColors.primary),
          items: AccountKind.values.map((k) {
            return DropdownMenuItem<AccountKind>(
              value: k,
              child: Row(
                children: [
                  Container(
                    width: 34,
                    height: 34,
                    decoration: BoxDecoration(
                      color: k.color.withValues(alpha: 0.12),
                      borderRadius: BorderRadius.circular(10),
                    ),
                    child: Icon(k.icon, size: 19, color: k.color),
                  ),
                  const SizedBox(width: 10),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Text(k.label,
                            style: const TextStyle(
                                fontSize: 15, fontWeight: FontWeight.w700)),
                        Text(k.hint,
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: const TextStyle(
                                fontSize: 10.5, color: AmialColors.textMuted)),
                      ],
                    ),
                  ),
                ],
              ),
            );
          }).toList(),
          onChanged: (k) {
            if (k != null) _switchKind(k);
          },
        ),
      ),
    );
  }

  // ===== الحقول: تتبدّل حسب النوع =====
  Widget _formFields() {
    return AnimatedSize(
      duration: const Duration(milliseconds: 220),
      curve: Curves.easeOut,
      child: Column(
        key: ValueKey(_kind),
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          // ---- حقول خاصّة بالنوع ----
          if (_kind == AccountKind.merchant || _kind == AccountKind.pos) ...[
            _field(
              controller: _merchantNumCtrl,
              label: 'رقم التاجر',
              icon: Icons.store,
              validator: (v) =>
                  (v == null || v.trim().length < 3) ? 'رقم تاجر غير صحيح' : null,
            ),
            const SizedBox(height: 12),
          ],
          if (_kind == AccountKind.pos) ...[
            _field(
              controller: _posNumCtrl,
              label: 'رقم نقطة البيع',
              hint: 'POS-001',
              icon: Icons.point_of_sale,
              validator: (v) =>
                  (v == null || v.trim().isEmpty) ? 'مطلوب' : null,
            ),
            const SizedBox(height: 12),
          ],
          // ---- الهاتف: لكل الأنواع ----
          ...[
            // AMIAL-LOGIN-UI-002: كانت بادئات الشبكات خمس رقاقات عائمة أسفل
            // الحقل بلا عنوان ولا سياق — تبدو أزراراً مبعثرة. صارت قائمة
            // داخل الحقل نفسه، كما في كل تطبيق يطلب رقم هاتف.
            _field(
              controller: _phoneCtrl,
              label: 'رقم الجوال',
              hint: '7XXXXXXXX',
              icon: Icons.phone_outlined,
              keyboard: TextInputType.phone,
              prefix: _NetworkPrefixPicker(
                value: _netPrefix,
                onPick: (p) {
                  setState(() => _netPrefix = p);
                  final rest = _phoneCtrl.text.replaceAll(RegExp(r'[^0-9]'), '');
                  // لا نُكرّر البادئة إن كان المستخدم كتبها بنفسه
                  final tail = rest.length >= 2 &&
                          const ['77', '78', '73', '71', '70'].contains(rest.substring(0, 2))
                      ? rest.substring(2)
                      : rest;
                  _phoneCtrl.text = p + tail;
                  _phoneCtrl.selection = TextSelection.fromPosition(
                      TextPosition(offset: _phoneCtrl.text.length));
                },
              ),
              validator: (v) =>
                  (v == null || v.trim().length < 6) ? 'رقم غير صحيح' : null,
            ),
            const SizedBox(height: 12),
          ],

          // ---- كلمة المرور: دائماً ----
          // AMIAL-PIN-SEPARATION-001: كان اسمها «الرمز السري» وهو اسم رمز
          // المعاملات (4 أرقام). فالعميل الذي غيّر رمز معاملاته يأتي هنا
          // فيُدخله ويفشل — والحقل يؤكّد له أنه المطلوب. اسمان مختلفان
          // لشيئين مختلفين.
          _field(
            controller: _passwordCtrl,
            label: 'كلمة المرور',
            icon: Icons.lock_outline,
            obscure: _obscure,
            suffix: IconButton(
              icon: Icon(_obscure ? Icons.visibility_off : Icons.visibility),
              onPressed: () => setState(() => _obscure = !_obscure),
            ),
            validator: (v) =>
                (v == null || v.length < 4) ? 'كلمة المرور قصيرة' : null,
          ),
        ],
      ),
    );
  }


  /// AMIAL-LOGIN-UI-002: حدود موحّدة بنصف قطر 14 ولون AmialColors.border —
  /// كانت OutlineInputBorder الافتراضية (زوايا 4 ورمادي Material) فتختلف عن
  /// قائمة نوع الحساب فوقها مباشرةً.
  Widget _field({
    required TextEditingController controller,
    required String label,
    required IconData icon,
    String? hint,
    TextInputType? keyboard,
    bool obscure = false,
    bool ltr = false,
    Widget? suffix,
    Widget? prefix,
    String? Function(String?)? validator,
  }) {
    OutlineInputBorder side(Color c, [double w = 1]) => OutlineInputBorder(
          borderRadius: BorderRadius.circular(14),
          borderSide: BorderSide(color: c, width: w),
        );

    return TextFormField(
      controller: controller,
      keyboardType: keyboard,
      obscureText: obscure,
      textDirection: ltr ? TextDirection.ltr : null,
      decoration: InputDecoration(
        labelText: label,
        hintText: hint,
        prefixIcon: prefix == null
            ? Icon(icon, color: AmialColors.textMuted)
            : Row(mainAxisSize: MainAxisSize.min, children: [
                const SizedBox(width: 10),
                Icon(icon, size: 20, color: AmialColors.textMuted),
                const SizedBox(width: 6),
                prefix,
              ]),
        suffixIcon: suffix,
        filled: true,
        fillColor: Colors.white,
        contentPadding:
            const EdgeInsets.symmetric(horizontal: 14, vertical: 16),
        border: side(AmialColors.border),
        enabledBorder: side(AmialColors.border),
        focusedBorder: side(AmialColors.primary, 1.4),
        errorBorder: side(AmialColors.red),
        focusedErrorBorder: side(AmialColors.red, 1.4),
      ),
      validator: validator,
    );
  }

  Widget _primaryAction() {
    return Obx(() {
      final ctrl = Get.find<UnifiedAuthController>();
      final busy = ctrl.isSubmitting.value;
      return AmialButton(
        label: busy ? 'جارٍ تسجيل الدخول...' : 'دخول',
        loading: busy,
        onPressed: busy ? null : _submit,
      );
    });
  }

  // ===== صندوق البيانات التجريبية (يتبدّل حسب النوع) =====
  Widget _demoBox() {
    final entries = _demos;
    if (entries.isEmpty) return const SizedBox.shrink();
    return Container(
      padding: const EdgeInsets.fromLTRB(12, 10, 12, 12),
      decoration: BoxDecoration(
        color: AmialColors.yellow.withValues(alpha: 0.12),
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: AmialColors.yellow.withValues(alpha: 0.5)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              const Icon(Icons.vpn_key_rounded, size: 16, color: AmialColors.primary),
              const SizedBox(width: 6),
              Text('بيانات تجريبية (${_kind.label}) — اضغط للتعبئة',
                  style: const TextStyle(
                      fontSize: 12,
                      fontWeight: FontWeight.w700,
                      color: AmialColors.primary)),
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
                        color: e.highlight ? Colors.white : AmialColors.primary)),
                backgroundColor:
                    e.highlight ? AmialColors.primary : Colors.white,
                side: BorderSide(
                    color: e.highlight
                        ? AmialColors.primary
                        : AmialColors.primary.withValues(alpha: 0.35)),
                onPressed: () {
                  e.onFill();
                  setState(() {});
                  ScaffoldMessenger.of(context).showSnackBar(SnackBar(
                    content: Text('تم تعبئة بيانات: ${e.label}'),
                    duration: const Duration(milliseconds: 900),
                    backgroundColor: AmialColors.primary,
                  ));
                },
              );
            }).toList(),
          ),
        ],
      ),
    );
  }

}

// ============================================================
class _Demo {
  final String label;
  final VoidCallback onFill;
  final bool highlight;
  const _Demo(this.label, this.onFill, {this.highlight = false});
}

// ============================================================
// بادئات أرقام اليمن — AMIAL-UI-002
// ============================================================
/// AMIAL-LOGIN-UI-002 — قائمة بادئة الشبكة داخل حقل الهاتف.
///
/// بديل خمس رقاقات كانت تطفو أسفل الحقل بلا عنوان. هنا البادئة جزء من
/// الحقل: يراها المستخدم قبل الكتابة ويفهم أن الرقم يبدأ بها.
class _NetworkPrefixPicker extends StatelessWidget {
  final String value;
  final ValueChanged<String> onPick;
  const _NetworkPrefixPicker({required this.value, required this.onPick});

  static const prefixes = ['77', '78', '73', '71', '70'];

  @override
  Widget build(BuildContext context) {
    return DropdownButtonHideUnderline(
      child: DropdownButton<String>(
        value: prefixes.contains(value) ? value : prefixes.first,
        isDense: true,
        borderRadius: BorderRadius.circular(12),
        icon: const Icon(Icons.arrow_drop_down_rounded,
            size: 20, color: AmialColors.textMuted),
        style: const TextStyle(
            fontSize: 14.5,
            fontWeight: FontWeight.w700,
            color: AmialColors.primary),
        items: prefixes
            .map((p) => DropdownMenuItem<String>(
                  value: p,
                  child: Text(p, textDirection: TextDirection.ltr),
                ))
            .toList(),
        onChanged: (p) {
          if (p != null) onPick(p);
        },
      ),
    );
  }
}

// ============================================================
// آخر تسجيل دخول — AMIAL-SEC-LOGIN-001
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
        color: AmialColors.primary.withValues(alpha: 0.04),
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: AmialColors.primary.withValues(alpha: 0.12)),
      ),
      child: Row(
        children: [
          const Icon(Icons.shield_outlined, size: 20, color: AmialColors.primary),
          const SizedBox(width: 10),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const Text('آخر تسجيل دخول لهذا الجهاز',
                    style: TextStyle(
                        fontSize: 12,
                        fontWeight: FontWeight.w600,
                        color: AmialColors.primary)),
                const SizedBox(height: 2),
                Text(
                  '🕒 ${_fmt(last.at)}'
                  '${last.zone.isNotEmpty ? '   •   📍 ${last.zone}' : ''}',
                  style: const TextStyle(
                      fontSize: 11.5, color: AmialColors.textSecondary),
                ),
                const Text('إن لم تكن أنت، غيّر كلمة مرورك فوراً.',
                    style: TextStyle(fontSize: 10.5, color: AmialColors.textMuted)),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
