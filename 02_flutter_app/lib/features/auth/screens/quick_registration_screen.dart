import 'dart:async';
import 'dart:io';

import 'package:amial_pay/common/widgets/amial_brand_logo.dart';
import 'package:amial_pay/data/api/api_client.dart';
import 'package:amial_pay/data/api/secure_storage_helper.dart';
import 'package:amial_pay/features/access/controllers/access_controller.dart';
import 'package:amial_pay/features/auth/controllers/auth_controller.dart';
import 'package:amial_pay/features/auth/controllers/unified_auth_controller.dart';
import 'package:amial_pay/features/auth/screens/role_router.dart';
import 'package:amial_pay/features/auth/widgets/governorate_picker.dart';
import 'package:amial_pay/theme/amial_colors.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:get/get.dart';
import 'package:image_picker/image_picker.dart';

/// AMIAL-PROGRESSIVE-KYC-APP-001
///
/// فتح محفظة العميل على مراحل واضحة:
/// 1) بيانات الاتصال + كلمة مرور دخول + PIN مالي مستقل + موافقة الشروط.
/// 2) إثبات البريد ثم إنشاء الحساب.
/// 3) إثبات ملكية الهاتف -> Tier 1 مخزّن.
/// 4) رفع إثبات الإقامة -> مراجعة بشرية قبل فتح المال.
///
/// لا هوية ولا سيلفي في التسجيل الأساسي. ترقية الهوية تتم لاحقاً من الحساب.
class QuickRegistrationScreen extends StatefulWidget {
  const QuickRegistrationScreen({super.key});

  @override
  State<QuickRegistrationScreen> createState() => _QuickRegistrationScreenState();
}

class _QuickRegistrationScreenState extends State<QuickRegistrationScreen> {
  final _name = TextEditingController();
  final _phone = TextEditingController();
  final _email = TextEditingController();
  final _password = TextEditingController();
  final _passwordConfirm = TextEditingController();
  final _pin = TextEditingController();
  final _pinConfirm = TextEditingController();
  final _emailOtp = TextEditingController();
  final _phoneOtp = TextEditingController();

  int _step = 0;
  bool _busy = false;
  bool _termsAccepted = false;
  bool _accountCreated = false;
  bool _phoneVerified = false;
  bool _residenceSubmitted = false;

  String _dialCode = '+967';
  String? _emailChallengeId;
  String? _emailVerificationToken;
  int _emailResendSeconds = 0;
  Timer? _emailTimer;

  String? _residenceGovernorate;
  XFile? _residenceEvidence;
  String? _evidenceType;
  List<Map<String, dynamic>> _evidenceOptions = const [];

  static const _fallbackEvidence = <Map<String, dynamic>>[
    {
      'code': 'lease_contract',
      'label': 'عقد إيجار باسم صاحب الحساب',
      'strength': 'strong',
      'description': 'عقد يحدد صاحب الحساب ومحل السكن الحالي.',
    },
    {
      'code': 'home_internet_contract',
      'label': 'عقد إنترنت أو خدمة منزلية',
      'strength': 'strong',
      'description': 'عقد خدمة باسم العميل وعنوان المنزل.',
    },
    {
      'code': 'employer_residence_letter',
      'label': 'خطاب جهة عمل يثبت السكن',
      'strength': 'strong',
      'description': 'خطاب حديث يتضمن عنوان الإقامة.',
    },
    {
      'code': 'education_residence_letter',
      'label': 'خطاب جامعة أو جهة تعليمية',
      'strength': 'strong',
      'description': 'خطاب حديث باسم الطالب وعنوان إقامته.',
    },
    {
      'code': 'government_residence_document',
      'label': 'مستند حكومي أو محلي يثبت السكن',
      'strength': 'strong',
      'description': 'وثيقة رسمية تربط صاحب الحساب بعنوان الإقامة.',
    },
    {
      'code': 'delivery_purchase_invoice',
      'label': 'فاتورة شراء مع عنوان التسليم',
      'strength': 'medium',
      'description': 'يجب أن يظهر اسم العميل وعنوان التسليم.',
    },
    {
      'code': 'shipping_waybill',
      'label': 'بوليصة شحن أو توصيل',
      'strength': 'medium',
      'description': 'بوليصة حديثة باسم العميل وعنوان التسليم.',
    },
    {
      'code': 'landlord_attestation',
      'label': 'إفادة مالك السكن — دليل مساعد',
      'strength': 'supporting',
      'description': 'لا يعتمد هذا النوع منفرداً.',
    },
  ];

  @override
  void dispose() {
    _emailTimer?.cancel();
    for (final c in [
      _name,
      _phone,
      _email,
      _password,
      _passwordConfirm,
      _pin,
      _pinConfirm,
      _emailOtp,
      _phoneOtp,
    ]) {
      c.dispose();
    }
    super.dispose();
  }

  String get _normalizedEmail => _email.text.trim().toLowerCase();

  String _responseMessage(Response response, String fallback) {
    final body = response.body;
    if (body is Map) {
      final direct = body['message']?.toString().trim();
      if (direct != null && direct.isNotEmpty) return direct;

      final errors = body['errors'];
      if (errors is Map && errors.isNotEmpty) {
        final first = errors.values.first;
        if (first is List && first.isNotEmpty) return first.first.toString();
        return first.toString();
      }
      if (errors is List && errors.isNotEmpty) {
        final first = errors.first;
        if (first is Map && first['message'] != null) {
          return first['message'].toString();
        }
        return first.toString();
      }
    }
    return response.statusText?.trim().isNotEmpty == true
        ? response.statusText!
        : fallback;
  }

  void _snack(String text, {bool error = true}) {
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(text),
        backgroundColor: error ? AmialColors.red : AmialColors.primary,
      ),
    );
  }

  bool _validateBasics() {
    if (_name.text.trim().length < 2) {
      _snack('أدخل اسمك كما تريد أن يظهر في حساب أميال.');
      return false;
    }
    final digits = _phone.text.replaceAll(RegExp(r'[^0-9]'), '');
    if (digits.length < 7) {
      _snack('أدخل رقم هاتف صحيح.');
      return false;
    }
    if (!RegExp(r'^[^@\s]+@[^@\s]+\.[^@\s]+$')
        .hasMatch(_normalizedEmail)) {
      _snack('أدخل بريداً إلكترونياً صحيحاً.');
      return false;
    }
    if (_password.text.length < 8) {
      _snack('registration_password_min'.tr);
      return false;
    }
    if (!RegExp(r'[^0-9]').hasMatch(_password.text)) {
      _snack('registration_password_not_numeric'.tr);
      return false;
    }
    if (_password.text != _passwordConfirm.text) {
      _snack('registration_password_confirmation_mismatch'.tr);
      return false;
    }
    if (!RegExp(r'^\d{4}$').hasMatch(_pin.text)) {
      _snack('registration_transaction_pin_digits'.tr);
      return false;
    }
    const weakPins = {
      '0000','1111','2222','3333','4444','5555','6666','7777','8888','9999',
      '1234','4321','0123',
    };
    if (weakPins.contains(_pin.text)) {
      _snack('registration_transaction_pin_weak'.tr);
      return false;
    }
    if (_pin.text != _pinConfirm.text) {
      _snack('تأكيد رمز PIN غير مطابق.');
      return false;
    }
    if (!_termsAccepted) {
      _snack('وافق على الشروط وسياسة الخصوصية للمتابعة.');
      return false;
    }
    return true;
  }

  void _startEmailCountdown(int seconds) {
    _emailTimer?.cancel();
    setState(() => _emailResendSeconds = seconds);
    _emailTimer = Timer.periodic(const Duration(seconds: 1), (timer) {
      if (!mounted) {
        timer.cancel();
        return;
      }
      if (_emailResendSeconds <= 1) {
        timer.cancel();
        setState(() => _emailResendSeconds = 0);
      } else {
        setState(() => _emailResendSeconds--);
      }
    });
  }

  Future<void> _requestEmailOtp() async {
    if (!_validateBasics() || _busy || _emailResendSeconds > 0) return;
    setState(() => _busy = true);
    try {
      final response = await Get.find<ApiClient>().postData(
        '/api/v1/auth/email-otp/request',
        {'email': _normalizedEmail, 'purpose': 'registration'},
      );
      final body = response.body;
      final meta = body is Map && body['meta'] is Map
          ? Map<String, dynamic>.from(body['meta'] as Map)
          : <String, dynamic>{};
      final challenge = meta['challenge_id']?.toString();
      final sent = response.statusCode != null &&
          response.statusCode! >= 200 &&
          response.statusCode! < 300 &&
          body is Map &&
          body['success'] == true &&
          challenge != null &&
          challenge.length == 26 &&
          meta['delivery_status'] == 'sent';

      if (!sent) {
        _snack(_responseMessage(response, 'تعذر إرسال رمز البريد.'));
        return;
      }
      _emailChallengeId = challenge;
      _emailVerificationToken = null;
      _emailOtp.clear();
      _startEmailCountdown(
        int.tryParse('${meta['resend_after_seconds'] ?? 60}') ?? 60,
      );
      setState(() => _step = 1);
      _snack('أرسلنا رمز التحقق إلى بريدك الإلكتروني.', error: false);
    } catch (_) {
      _snack('تعذر الاتصال بخدمة التحقق من البريد.');
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _verifyEmailAndCreate() async {
    if (_busy) return;
    if (!RegExp(r'^\d{6}$').hasMatch(_emailOtp.text.trim())) {
      _snack('أدخل رمز البريد المكوّن من 6 أرقام.');
      return;
    }
    if (_emailChallengeId == null) {
      _snack('جلسة البريد غير موجودة. أعد إرسال الرمز.');
      return;
    }

    setState(() => _busy = true);
    try {
      final verify = await Get.find<ApiClient>().postData(
        '/api/v1/auth/email-otp/verify',
        {
          'challenge_id': _emailChallengeId,
          'email': _normalizedEmail,
          'purpose': 'registration',
          'otp': _emailOtp.text.trim(),
        },
      );
      final verifyBody = verify.body;
      final meta = verifyBody is Map && verifyBody['meta'] is Map
          ? Map<String, dynamic>.from(verifyBody['meta'] as Map)
          : <String, dynamic>{};
      final token = meta['verification_token']?.toString();
      if (verify.statusCode == null ||
          verify.statusCode! < 200 ||
          verify.statusCode! >= 300 ||
          verifyBody is! Map ||
          verifyBody['success'] != true ||
          token == null ||
          token.length < 32) {
        _snack(_responseMessage(verify, 'رمز البريد غير صحيح أو انتهت صلاحيته.'));
        return;
      }
      _emailVerificationToken = token;

      final register = await Get.find<ApiClient>().postData(
        '/api/v1/auth/register/quick',
        {
          'full_name': _name.text.trim(),
          'dial_country_code': _dialCode,
          'phone': _phone.text.trim(),
          'email': _normalizedEmail,
          'password': _password.text,
          'password_confirmation': _passwordConfirm.text,
          'transaction_pin': _pin.text,
          'locale': Get.locale?.languageCode == 'en' ? 'en' : 'ar',
          'email_challenge_id': _emailChallengeId,
          'email_verification_token': _emailVerificationToken,
          'terms_accepted': true,
        },
      );
      final registerBody = register.body;
      if (register.statusCode == null ||
          register.statusCode! < 200 ||
          register.statusCode! >= 300 ||
          registerBody is! Map ||
          registerBody['success'] != true ||
          registerBody['data'] is! Map) {
        _snack(_responseMessage(register, 'تعذر إنشاء الحساب.'));
        return;
      }

      final data = Map<String, dynamic>.from(registerBody['data'] as Map);
      final accessToken = data['access_token']?.toString();
      _accountCreated = true;
      _emailTimer?.cancel();

      if (accessToken != null && accessToken.isNotEmpty) {
        await _adoptSession(accessToken);
        await _loadResidenceOptions();
        if (!mounted) return;
        setState(() => _step = 2);
        await _requestPhoneOtp(silentSuccess: true);
      } else {
        if (!mounted) return;
        setState(() => _step = 4);
        _snack(
          'تم إنشاء الحساب، لكن تعذر بدء الجلسة تلقائياً. سجّل الدخول لإكمال التفعيل.',
          error: false,
        );
      }
    } catch (_) {
      _snack('حدث خطأ في الاتصال أثناء إنشاء الحساب.');
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _adoptSession(String token) async {
    try {
      await Get.find<AuthController>().authRepo.saveUserToken(token);
    } catch (_) {
      try {
        Get.find<ApiClient>().updateHeader(token);
      } catch (_) {}
      try {
        await SecureStorageHelper.instance.setToken(token);
      } catch (_) {}
    }
    await UnifiedAuthController.rememberLastUser(
      name: _name.text.trim(),
      phone: '$_dialCode${_phone.text.trim()}',
      kind: 'customer',
    );
    try {
      await Get.find<AccessController>().load();
    } catch (_) {}
  }

  Future<void> _requestPhoneOtp({bool silentSuccess = false}) async {
    if (_busy && !silentSuccess) return;
    if (!silentSuccess) setState(() => _busy = true);
    try {
      final response = await Get.find<ApiClient>().postData(
        '/api/v1/customer/check-otp',
        const <String, dynamic>{},
      );
      final ok = response.statusCode == 200 &&
          response.body is Map &&
          response.body['success'] == true &&
          response.body['code']?.toString() == 'OTP_SENT';
      if (!ok) {
        _snack(
          _responseMessage(
            response,
            'الحساب جاهز، لكن تعذر إرسال رمز الهاتف الآن. يمكنك إكماله لاحقاً.',
          ),
        );
        return;
      }
      final body = Map<String, dynamic>.from(response.body as Map);
      final demoOtp = body['demo_otp']?.toString();
      if (demoOtp != null && RegExp(r'^\d{6}
    } catch (_) {
      _snack('الحساب جاهز، لكن تعذر إرسال رمز الهاتف الآن. يمكنك إكماله لاحقاً.');
    } finally {
      if (!silentSuccess && mounted) setState(() => _busy = false);
    }
  }

  Future<void> _verifyPhoneOtp() async {
    if (_busy) return;
    if (!RegExp(r'^\d{6}
      return;
    }
    setState(() => _busy = true);
    try {
      final response = await Get.find<ApiClient>().postData(
        '/api/v1/customer/verify-otp',
        {'otp': _phoneOtp.text.trim()},
      );
      if (response.statusCode != 200 || response.body is! Map) {
        _snack(_responseMessage(response, 'رمز الهاتف غير صحيح.'));
        return;
      }
      _phoneVerified = response.body['is_phone_verified'] == true;
      if (!_phoneVerified) {
        _snack(_responseMessage(response, 'لم يكتمل توثيق الهاتف.'));
        return;
      }
      if (!mounted) return;
      setState(() => _step = 3);
      _snack(
        'تم إثبات ملكية الهاتف. يبقى الحساب غير موثق مالياً حتى اعتماد إثبات السكن.',
        error: false,
      );
    } catch (_) {
      _snack('تعذر التحقق من رمز الهاتف.');
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _loadResidenceOptions() async {
    try {
      final response = await Get.find<ApiClient>()
          .getData('/api/v1/amial/me/kyc/residence');
      if (response.statusCode == 200 && response.body is Map) {
        final options = response.body['evidence_options'];
        if (options is List && options.isNotEmpty) {
          _evidenceOptions = options
              .whereType<Map>()
              .map((e) => Map<String, dynamic>.from(e))
              .toList();
        }
      }
    } catch (_) {}
    if (_evidenceOptions.isEmpty) {
      _evidenceOptions = _fallbackEvidence;
    }
    if (_evidenceType == null && _evidenceOptions.isNotEmpty) {
      _evidenceType = _evidenceOptions.first['code']?.toString();
    }
  }

  Future<void> _pickResidenceEvidence(ImageSource source) async {
    try {
      final picked = await ImagePicker().pickImage(
        source: source,
        imageQuality: 88,
        maxWidth: 2200,
      );
      if (picked != null && mounted) {
        setState(() => _residenceEvidence = picked);
      }
    } catch (_) {
      _snack('تعذر اختيار دليل السكن.');
    }
  }

  Future<void> _submitResidence() async {
    if (_busy) return;
    if (_residenceGovernorate == null || _residenceGovernorate!.isEmpty) {
      _snack('اختر محافظة إقامتك الحالية.');
      return;
    }
    if (_evidenceType == null || _evidenceType!.isEmpty) {
      _snack('اختر نوع دليل السكن.');
      return;
    }
    if (_residenceEvidence == null) {
      _snack('أرفق صورة واضحة لدليل السكن.');
      return;
    }

    setState(() => _busy = true);
    try {
      final response = await Get.find<ApiClient>().postMultipartData(
        '/api/v1/amial/me/kyc/residence',
        {
          'residence_governorate': _residenceGovernorate!,
          'evidence_type': _evidenceType!,
        },
        [MultipartBody('evidence', File(_residenceEvidence!.path))],
      );
      if (response.statusCode != 201 ||
          response.body is! Map ||
          response.body['success'] != true) {
        _snack(_responseMessage(response, 'تعذر إرسال إثبات السكن.'));
        return;
      }
      _residenceSubmitted = true;
      if (!mounted) return;
      setState(() => _step = 4);
      _snack('تم إرسال إثبات السكن للمراجعة.', error: false);
    } catch (_) {
      _snack('تعذر إرسال إثبات السكن. تحقق من الاتصال وحاول مرة أخرى.');
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  void _finish() {
    if (_accountCreated) {
      RoleRouter.navigateToHome('customer');
    } else {
      Get.back();
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: const Color(0xFFF7F8FB),
      body: SafeArea(
        child: Column(
          children: [
            _header(context),
            if (_step < 4) _progress(),
            Expanded(
              child: AnimatedSwitcher(
                duration: const Duration(milliseconds: 220),
                child: KeyedSubtree(
                  key: ValueKey(_step),
                  child: switch (_step) {
                    0 => _basicsStep(),
                    1 => _emailStep(),
                    2 => _phoneStep(),
                    3 => _residenceStep(),
                    _ => _successStep(),
                  },
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _header(BuildContext context) {
    return Container(
      padding: const EdgeInsets.fromLTRB(16, 12, 16, 14),
      color: Colors.white,
      child: Row(
        children: [
          IconButton(
            onPressed: _busy
                ? null
                : () {
                    if (_step == 0 || _accountCreated) {
                      Get.back();
                    } else {
                      setState(() => _step--);
                    }
                  },
            icon: const Icon(Icons.arrow_back_ios_new_rounded, size: 20),
          ),
          const SizedBox(width: 8),
          Container(
            width: 44,
            height: 44,
            padding: const EdgeInsets.all(6),
            decoration: BoxDecoration(
              color: AmialColors.yellow,
              borderRadius: BorderRadius.circular(14),
            ),
            child: const AmialBrandLogo(fit: BoxFit.contain),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  'افتح محفظتك بخطوات بسيطة',
                  style: Theme.of(context).textTheme.titleMedium?.copyWith(
                        fontWeight: FontWeight.w900,
                        color: AmialColors.textPrimary,
                      ),
                ),
                const SizedBox(height: 2),
                const Text(
                  'لا نطلب الهوية أو الصورة الشخصية عند إنشاء الحساب الأساسي.',
                  style: TextStyle(fontSize: 12, color: AmialColors.textMuted),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _progress() {
    const labels = ['الحساب', 'البريد', 'الهاتف', 'السكن'];
    return Container(
      color: Colors.white,
      padding: const EdgeInsets.fromLTRB(20, 2, 20, 16),
      child: Row(
        children: List.generate(labels.length, (index) {
          final active = index <= _step;
          return Expanded(
            child: Column(
              children: [
                Container(
                  height: 4,
                  margin: EdgeInsetsDirectional.only(
                    end: index == labels.length - 1 ? 0 : 5,
                  ),
                  decoration: BoxDecoration(
                    color: active ? AmialColors.primary : const Color(0xFFE2E6ED),
                    borderRadius: BorderRadius.circular(8),
                  ),
                ),
                const SizedBox(height: 6),
                Text(
                  labels[index],
                  style: TextStyle(
                    fontSize: 11,
                    fontWeight: active ? FontWeight.w800 : FontWeight.w500,
                    color: active ? AmialColors.primary : AmialColors.textMuted,
                  ),
                ),
              ],
            ),
          );
        }),
      ),
    );
  }

  Widget _page({required List<Widget> children}) {
    return SingleChildScrollView(
      padding: const EdgeInsets.all(18),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: children,
      ),
    );
  }

  Widget _card(List<Widget> children) {
    return Container(
      padding: const EdgeInsets.all(18),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(22),
        border: Border.all(color: const Color(0xFFE7EAF0)),
        boxShadow: const [
          BoxShadow(
            blurRadius: 20,
            offset: Offset(0, 8),
            color: Color(0x0C000000),
          ),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: children,
      ),
    );
  }

  Widget _title(String title, String subtitle, IconData icon) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Container(
          width: 46,
          height: 46,
          decoration: BoxDecoration(
            color: AmialColors.primary.withValues(alpha: 0.08),
            borderRadius: BorderRadius.circular(15),
          ),
          child: Icon(icon, color: AmialColors.primary),
        ),
        const SizedBox(height: 14),
        Text(
          title,
          style: const TextStyle(
            fontSize: 22,
            fontWeight: FontWeight.w900,
            color: AmialColors.textPrimary,
          ),
        ),
        const SizedBox(height: 6),
        Text(
          subtitle,
          style: const TextStyle(
            height: 1.6,
            color: AmialColors.textSecondary,
          ),
        ),
        const SizedBox(height: 20),
      ],
    );
  }

  Widget _field(
    TextEditingController controller,
    String label, {
    TextInputType? keyboardType,
    bool obscure = false,
    int? maxLength,
    List<TextInputFormatter>? formatters,
  }) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 13),
      child: TextField(
        controller: controller,
        keyboardType: keyboardType,
        obscureText: obscure,
        maxLength: maxLength,
        inputFormatters: formatters,
        decoration: InputDecoration(
          labelText: label,
          counterText: '',
          filled: true,
          fillColor: const Color(0xFFFBFCFE),
          border: OutlineInputBorder(
            borderRadius: BorderRadius.circular(14),
            borderSide: const BorderSide(color: Color(0xFFE0E4EA)),
          ),
          enabledBorder: OutlineInputBorder(
            borderRadius: BorderRadius.circular(14),
            borderSide: const BorderSide(color: Color(0xFFE0E4EA)),
          ),
        ),
      ),
    );
  }

  Widget _primaryButton(String label, VoidCallback? onPressed) {
    return FilledButton(
      onPressed: _busy ? null : onPressed,
      style: FilledButton.styleFrom(
        backgroundColor: AmialColors.primary,
        foregroundColor: Colors.white,
        minimumSize: const Size.fromHeight(52),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
      ),
      child: _busy
          ? const SizedBox(
              width: 22,
              height: 22,
              child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white),
            )
          : Text(label, style: const TextStyle(fontWeight: FontWeight.w800)),
    );
  }

  Widget _basicsStep() => _page(children: [
        _card([
          _title(
            'حسابك الأساسي',
            'أنشئ كلمة مرور للدخول وPIN مالياً مستقلاً. يمكنك توثيق الهوية ورفع الحدود لاحقاً من حسابك.',
            Icons.account_balance_wallet_outlined,
          ),
          _field(_name, 'الاسم الكامل'),
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              SizedBox(
                width: 100,
                child: DropdownButtonFormField<String>(
                  initialValue: _dialCode,
                  decoration: InputDecoration(
                    labelText: 'الدولة',
                    border: OutlineInputBorder(
                      borderRadius: BorderRadius.circular(14),
                    ),
                  ),
                  items: const [
                    DropdownMenuItem(value: '+967', child: Text('+967')),
                    DropdownMenuItem(value: '+966', child: Text('+966')),
                    DropdownMenuItem(value: '+968', child: Text('+968')),
                  ],
                  onChanged: (v) => setState(() => _dialCode = v ?? '+967'),
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: _field(
                  _phone,
                  'رقم الهاتف',
                  keyboardType: TextInputType.phone,
                  formatters: [FilteringTextInputFormatter.allow(RegExp(r'[0-9]'))],
                ),
              ),
            ],
          ),
          _field(_email, 'البريد الإلكتروني', keyboardType: TextInputType.emailAddress),
          Row(
            children: [
              Expanded(
                child: _field(
                  _password,
                  'registration_login_password_label'.tr,
                  obscure: true,
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: _field(
                  _passwordConfirm,
                  'registration_login_password_confirm_label'.tr,
                  obscure: true,
                ),
              ),
            ],
          ),
          Container(
            width: double.infinity,
            margin: const EdgeInsets.only(bottom: 12),
            padding: const EdgeInsets.all(12),
            decoration: BoxDecoration(
              color: const Color(0xFFF3F8FF),
              borderRadius: BorderRadius.circular(14),
            ),
            child: Text(
              'registration_password_pin_separation_help'.tr,
              style: TextStyle(fontSize: 12.5, height: 1.5),
            ),
          ),
          Row(
            children: [
              Expanded(
                child: _field(
                  _pin,
                  'registration_financial_pin_label'.tr,
                  keyboardType: TextInputType.number,
                  obscure: true,
                  maxLength: 6,
                  formatters: [FilteringTextInputFormatter.digitsOnly],
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: _field(
                  _pinConfirm,
                  'تأكيد PIN',
                  keyboardType: TextInputType.number,
                  obscure: true,
                  maxLength: 4,
                  formatters: [FilteringTextInputFormatter.digitsOnly],
                ),
              ),
            ],
          ),
          CheckboxListTile(
            value: _termsAccepted,
            contentPadding: EdgeInsets.zero,
            controlAffinity: ListTileControlAffinity.leading,
            title: const Text(
              'أوافق على شروط الاستخدام وسياسة الخصوصية الخاصة بأميال باي.',
              style: TextStyle(fontSize: 13, height: 1.5),
            ),
            onChanged: (v) => setState(() => _termsAccepted = v == true),
          ),
          const SizedBox(height: 10),
          _primaryButton('إرسال رمز البريد', _requestEmailOtp),
        ]),
      ]);

  Widget _emailStep() => _page(children: [
        _card([
          _title(
            'تأكيد البريد',
            'أرسلنا رمزاً من 6 أرقام إلى $_normalizedEmail. بعد نجاحه ننشئ المحفظة مباشرة.',
            Icons.mark_email_read_outlined,
          ),
          _field(
            _emailOtp,
            'رمز البريد',
            keyboardType: TextInputType.number,
            maxLength: 6,
            formatters: [FilteringTextInputFormatter.digitsOnly],
          ),
          _primaryButton('تحقق وأنشئ المحفظة', _verifyEmailAndCreate),
          const SizedBox(height: 8),
          TextButton(
            onPressed: _busy || _emailResendSeconds > 0 ? null : _requestEmailOtp,
            child: Text(
              _emailResendSeconds > 0
                  ? 'إعادة الإرسال بعد $_emailResendSeconds ثانية'
                  : 'إعادة إرسال الرمز',
            ),
          ),
        ]),
      ]);

  Widget _phoneStep() => _page(children: [
        _card([
          _title(
            'أثبت ملكية هاتفك',
            'نجح إنشاء الحساب. استخدم الرمز التجريبي 123456 لإثبات الهاتف. سيبقى الحساب غير موثق مالياً حتى اعتماد السكن.',
            Icons.phonelink_lock_outlined,
          ),
          Container(
            width: double.infinity,
            margin: const EdgeInsets.only(bottom: 12),
            padding: const EdgeInsets.all(12),
            decoration: BoxDecoration(
              color: const Color(0xFFFFF4D6),
              borderRadius: BorderRadius.circular(12),
              border: Border.all(color: const Color(0xFFE9C46A)),
            ),
            child: const Text(
              'للتجربة فقط: رمز تحقق الهاتف الحالي 123456. '
              'يجب ربط مزود SMS/OTP حقيقي وإلغاء هذا الرمز قبل الإنتاج.',
              style: TextStyle(fontSize: 12, height: 1.45),
            ),
          ),
          _field(
            _phoneOtp,
            'رمز الهاتف',
            keyboardType: TextInputType.number,
            maxLength: 4,
            formatters: [FilteringTextInputFormatter.digitsOnly],
          ),
          _primaryButton('تأكيد الهاتف', _verifyPhoneOtp),
          const SizedBox(height: 8),
          OutlinedButton.icon(
            onPressed: _busy ? null : () => _requestPhoneOtp(),
            icon: const Icon(Icons.refresh),
            label: const Text('إرسال رمز جديد'),
          ),
          const SizedBox(height: 8),
          TextButton(
            onPressed: _busy ? null : () => setState(() => _step = 4),
            child: const Text('سأكمل إثبات الهاتف لاحقاً'),
          ),
        ]),
      ]);

  Widget _residenceStep() {
    if (_evidenceOptions.isEmpty) {
      _evidenceOptions = _fallbackEvidence;
      _evidenceType ??= _evidenceOptions.first['code']?.toString();
    }
    final selected = _evidenceOptions.firstWhere(
      (e) => e['code']?.toString() == _evidenceType,
      orElse: () => _evidenceOptions.first,
    );
    final supporting = selected['strength']?.toString() == 'supporting';

    return _page(children: [
      _card([
        _title(
          'أثبت محل إقامتك الحالي',
          'المحافظة التي تسكن فيها فعلياً هي التي تحدد نطاق التشغيل. محافظة الأصل لا تستخدم لهذا القرار.',
          Icons.home_work_outlined,
        ),
        GovernoratePicker(
          label: 'محافظة الإقامة الحالية',
          value: _residenceGovernorate,
          helper: 'اختر مكان السكن الحالي، وليس محافظة الأصل.',
          onChanged: (v) => setState(() => _residenceGovernorate = v),
        ),
        const SizedBox(height: 4),
        DropdownButtonFormField<String>(
          initialValue: _evidenceType,
          isExpanded: true,
          decoration: InputDecoration(
            labelText: 'نوع دليل السكن',
            border: OutlineInputBorder(borderRadius: BorderRadius.circular(14)),
          ),
          items: _evidenceOptions
              .map(
                (e) => DropdownMenuItem<String>(
                  value: e['code']?.toString(),
                  child: Text(e['label']?.toString() ?? e['code'].toString()),
                ),
              )
              .toList(),
          onChanged: (v) => setState(() => _evidenceType = v),
        ),
        const SizedBox(height: 10),
        Container(
          padding: const EdgeInsets.all(13),
          decoration: BoxDecoration(
            color: supporting
                ? const Color(0xFFFFF8E6)
                : const Color(0xFFF3F8FF),
            borderRadius: BorderRadius.circular(14),
          ),
          child: Text(
            selected['description']?.toString() ?? '',
            style: const TextStyle(fontSize: 13, height: 1.5),
          ),
        ),
        const SizedBox(height: 14),
        if (_residenceEvidence != null)
          Container(
            padding: const EdgeInsets.all(12),
            margin: const EdgeInsets.only(bottom: 10),
            decoration: BoxDecoration(
              color: const Color(0xFFF3FAF6),
              borderRadius: BorderRadius.circular(14),
            ),
            child: const Row(
              children: [
                Icon(Icons.check_circle, color: Color(0xFF16874C)),
                SizedBox(width: 8),
                Expanded(child: Text('تم اختيار دليل السكن. راجعه قبل الإرسال.')),
              ],
            ),
          ),
        Row(
          children: [
            Expanded(
              child: OutlinedButton.icon(
                onPressed: _busy
                    ? null
                    : () => _pickResidenceEvidence(ImageSource.camera),
                icon: const Icon(Icons.photo_camera_outlined),
                label: const Text('التقاط صورة'),
              ),
            ),
            const SizedBox(width: 8),
            Expanded(
              child: OutlinedButton.icon(
                onPressed: _busy
                    ? null
                    : () => _pickResidenceEvidence(ImageSource.gallery),
                icon: const Icon(Icons.photo_library_outlined),
                label: const Text('من المعرض'),
              ),
            ),
          ],
        ),
        const SizedBox(height: 14),
        _primaryButton('إرسال للمراجعة', _submitResidence),
        const SizedBox(height: 8),
        TextButton(
          onPressed: _busy ? null : () => setState(() => _step = 4),
          child: const Text('سأرفع إثبات السكن لاحقاً'),
        ),
      ]),
    ]);
  }

  Widget _successStep() {
    final verificationState = CustomerVerificationLevel.fromTier(0);
    final message = !_phoneVerified
        ? 'تم إنشاء حسابك. أثبت ملكية الهاتف لاحقاً للانتقال إلى عميل موثق جزئيا.'
        : _residenceSubmitted
            ? 'تم إنشاء حسابك وإثبات هاتفك، وإثبات السكن الآن في قائمة المراجعة. ستفتح الحركة المالية بعد اعتماده.'
            : 'تم إنشاء حسابك وإثبات هاتفك. بقي إثبات محل الإقامة قبل تشغيل الحركة المالية.';

    return _page(children: [
      _card([
        const SizedBox(height: 8),
        Container(
          width: 76,
          height: 76,
          alignment: Alignment.center,
          decoration: BoxDecoration(
            color: const Color(0xFFEAF8F0),
            borderRadius: BorderRadius.circular(28),
          ),
          child: const Icon(Icons.check_rounded, size: 42, color: Color(0xFF16874C)),
        ),
        const SizedBox(height: 18),
        const Text(
          'محفظتك جاهزة',
          style: TextStyle(
            fontSize: 24,
            fontWeight: FontWeight.w900,
            color: AmialColors.textPrimary,
          ),
        ),
        const SizedBox(height: 8),
        Text(
          message,
          style: const TextStyle(
            height: 1.65,
            color: AmialColors.textSecondary,
          ),
        ),
        const SizedBox(height: 18),
        Container(
          padding: const EdgeInsets.all(14),
          decoration: BoxDecoration(
            color: const Color(0xFFF7F8FB),
            borderRadius: BorderRadius.circular(14),
          ),
          child: Row(
            children: [
              const Icon(Icons.shield_outlined, color: AmialColors.primary),
              const SizedBox(width: 10),
              Expanded(
                child: Text(
                  'حالة توثيقك الحالية: ${verificationState.label}. حدود المال وسياسة التشغيل تُطبّق من الخادم.',
                  style: const TextStyle(fontSize: 13, height: 1.5),
                ),
              ),
            ],
          ),
        ),
        const SizedBox(height: 18),
        _primaryButton('الدخول إلى أميال باي', _finish),
      ]),
    ]);
  }
}
).hasMatch(demoOtp)) {
        _phoneOtp.text = demoOtp;
      }
      if (!silentSuccess) {
        _snack(
          body['pilot_mode'] == true
              ? 'وضع تجريبي: رمز الهاتف الحالي هو 123456'
              : 'أرسلنا رمز التحقق إلى رقم هاتفك.',
          error: false,
        );
      }
    } catch (_) {
      _snack('الحساب جاهز، لكن تعذر إرسال رمز الهاتف الآن. يمكنك إكماله لاحقاً.');
    } finally {
      if (!silentSuccess && mounted) setState(() => _busy = false);
    }
  }

  Future<void> _verifyPhoneOtp() async {
    if (_busy) return;
    if (!RegExp(r'^\d{4}$').hasMatch(_phoneOtp.text.trim())) {
      _snack('أدخل رمز الهاتف المكوّن من 4 أرقام.');
      return;
    }
    setState(() => _busy = true);
    try {
      final response = await Get.find<ApiClient>().postData(
        '/api/v1/customer/verify-otp',
        {'otp': _phoneOtp.text.trim()},
      );
      if (response.statusCode != 200 || response.body is! Map) {
        _snack(_responseMessage(response, 'رمز الهاتف غير صحيح.'));
        return;
      }
      _phoneVerified = response.body['is_phone_verified'] == true;
      if (!_phoneVerified) {
        _snack(_responseMessage(response, 'لم يكتمل توثيق الهاتف.'));
        return;
      }
      if (!mounted) return;
      setState(() => _step = 3);
      _snack('تم إثبات ملكية الهاتف. حسابك الآن موثق جزئيا بانتظار إثبات السكن.', error: false);
    } catch (_) {
      _snack('تعذر التحقق من رمز الهاتف.');
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _loadResidenceOptions() async {
    try {
      final response = await Get.find<ApiClient>()
          .getData('/api/v1/amial/me/kyc/residence');
      if (response.statusCode == 200 && response.body is Map) {
        final options = response.body['evidence_options'];
        if (options is List && options.isNotEmpty) {
          _evidenceOptions = options
              .whereType<Map>()
              .map((e) => Map<String, dynamic>.from(e))
              .toList();
        }
      }
    } catch (_) {}
    if (_evidenceOptions.isEmpty) {
      _evidenceOptions = _fallbackEvidence;
    }
    if (_evidenceType == null && _evidenceOptions.isNotEmpty) {
      _evidenceType = _evidenceOptions.first['code']?.toString();
    }
  }

  Future<void> _pickResidenceEvidence(ImageSource source) async {
    try {
      final picked = await ImagePicker().pickImage(
        source: source,
        imageQuality: 88,
        maxWidth: 2200,
      );
      if (picked != null && mounted) {
        setState(() => _residenceEvidence = picked);
      }
    } catch (_) {
      _snack('تعذر اختيار دليل السكن.');
    }
  }

  Future<void> _submitResidence() async {
    if (_busy) return;
    if (_residenceGovernorate == null || _residenceGovernorate!.isEmpty) {
      _snack('اختر محافظة إقامتك الحالية.');
      return;
    }
    if (_evidenceType == null || _evidenceType!.isEmpty) {
      _snack('اختر نوع دليل السكن.');
      return;
    }
    if (_residenceEvidence == null) {
      _snack('أرفق صورة واضحة لدليل السكن.');
      return;
    }

    setState(() => _busy = true);
    try {
      final response = await Get.find<ApiClient>().postMultipartData(
        '/api/v1/amial/me/kyc/residence',
        {
          'residence_governorate': _residenceGovernorate!,
          'evidence_type': _evidenceType!,
        },
        [MultipartBody('evidence', File(_residenceEvidence!.path))],
      );
      if (response.statusCode != 201 ||
          response.body is! Map ||
          response.body['success'] != true) {
        _snack(_responseMessage(response, 'تعذر إرسال إثبات السكن.'));
        return;
      }
      _residenceSubmitted = true;
      if (!mounted) return;
      setState(() => _step = 4);
      _snack('تم إرسال إثبات السكن للمراجعة.', error: false);
    } catch (_) {
      _snack('تعذر إرسال إثبات السكن. تحقق من الاتصال وحاول مرة أخرى.');
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  void _finish() {
    if (_accountCreated) {
      RoleRouter.navigateToHome('customer');
    } else {
      Get.back();
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: const Color(0xFFF7F8FB),
      body: SafeArea(
        child: Column(
          children: [
            _header(context),
            if (_step < 4) _progress(),
            Expanded(
              child: AnimatedSwitcher(
                duration: const Duration(milliseconds: 220),
                child: KeyedSubtree(
                  key: ValueKey(_step),
                  child: switch (_step) {
                    0 => _basicsStep(),
                    1 => _emailStep(),
                    2 => _phoneStep(),
                    3 => _residenceStep(),
                    _ => _successStep(),
                  },
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _header(BuildContext context) {
    return Container(
      padding: const EdgeInsets.fromLTRB(16, 12, 16, 14),
      color: Colors.white,
      child: Row(
        children: [
          IconButton(
            onPressed: _busy
                ? null
                : () {
                    if (_step == 0 || _accountCreated) {
                      Get.back();
                    } else {
                      setState(() => _step--);
                    }
                  },
            icon: const Icon(Icons.arrow_back_ios_new_rounded, size: 20),
          ),
          const SizedBox(width: 8),
          Container(
            width: 44,
            height: 44,
            padding: const EdgeInsets.all(6),
            decoration: BoxDecoration(
              color: AmialColors.yellow,
              borderRadius: BorderRadius.circular(14),
            ),
            child: const AmialBrandLogo(fit: BoxFit.contain),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  'افتح محفظتك بخطوات بسيطة',
                  style: Theme.of(context).textTheme.titleMedium?.copyWith(
                        fontWeight: FontWeight.w900,
                        color: AmialColors.textPrimary,
                      ),
                ),
                const SizedBox(height: 2),
                const Text(
                  'لا نطلب الهوية أو الصورة الشخصية عند إنشاء الحساب الأساسي.',
                  style: TextStyle(fontSize: 12, color: AmialColors.textMuted),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _progress() {
    const labels = ['الحساب', 'البريد', 'الهاتف', 'السكن'];
    return Container(
      color: Colors.white,
      padding: const EdgeInsets.fromLTRB(20, 2, 20, 16),
      child: Row(
        children: List.generate(labels.length, (index) {
          final active = index <= _step;
          return Expanded(
            child: Column(
              children: [
                Container(
                  height: 4,
                  margin: EdgeInsetsDirectional.only(
                    end: index == labels.length - 1 ? 0 : 5,
                  ),
                  decoration: BoxDecoration(
                    color: active ? AmialColors.primary : const Color(0xFFE2E6ED),
                    borderRadius: BorderRadius.circular(8),
                  ),
                ),
                const SizedBox(height: 6),
                Text(
                  labels[index],
                  style: TextStyle(
                    fontSize: 11,
                    fontWeight: active ? FontWeight.w800 : FontWeight.w500,
                    color: active ? AmialColors.primary : AmialColors.textMuted,
                  ),
                ),
              ],
            ),
          );
        }),
      ),
    );
  }

  Widget _page({required List<Widget> children}) {
    return SingleChildScrollView(
      padding: const EdgeInsets.all(18),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: children,
      ),
    );
  }

  Widget _card(List<Widget> children) {
    return Container(
      padding: const EdgeInsets.all(18),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(22),
        border: Border.all(color: const Color(0xFFE7EAF0)),
        boxShadow: const [
          BoxShadow(
            blurRadius: 20,
            offset: Offset(0, 8),
            color: Color(0x0C000000),
          ),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: children,
      ),
    );
  }

  Widget _title(String title, String subtitle, IconData icon) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Container(
          width: 46,
          height: 46,
          decoration: BoxDecoration(
            color: AmialColors.primary.withValues(alpha: 0.08),
            borderRadius: BorderRadius.circular(15),
          ),
          child: Icon(icon, color: AmialColors.primary),
        ),
        const SizedBox(height: 14),
        Text(
          title,
          style: const TextStyle(
            fontSize: 22,
            fontWeight: FontWeight.w900,
            color: AmialColors.textPrimary,
          ),
        ),
        const SizedBox(height: 6),
        Text(
          subtitle,
          style: const TextStyle(
            height: 1.6,
            color: AmialColors.textSecondary,
          ),
        ),
        const SizedBox(height: 20),
      ],
    );
  }

  Widget _field(
    TextEditingController controller,
    String label, {
    TextInputType? keyboardType,
    bool obscure = false,
    int? maxLength,
    List<TextInputFormatter>? formatters,
  }) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 13),
      child: TextField(
        controller: controller,
        keyboardType: keyboardType,
        obscureText: obscure,
        maxLength: maxLength,
        inputFormatters: formatters,
        decoration: InputDecoration(
          labelText: label,
          counterText: '',
          filled: true,
          fillColor: const Color(0xFFFBFCFE),
          border: OutlineInputBorder(
            borderRadius: BorderRadius.circular(14),
            borderSide: const BorderSide(color: Color(0xFFE0E4EA)),
          ),
          enabledBorder: OutlineInputBorder(
            borderRadius: BorderRadius.circular(14),
            borderSide: const BorderSide(color: Color(0xFFE0E4EA)),
          ),
        ),
      ),
    );
  }

  Widget _primaryButton(String label, VoidCallback? onPressed) {
    return FilledButton(
      onPressed: _busy ? null : onPressed,
      style: FilledButton.styleFrom(
        backgroundColor: AmialColors.primary,
        foregroundColor: Colors.white,
        minimumSize: const Size.fromHeight(52),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
      ),
      child: _busy
          ? const SizedBox(
              width: 22,
              height: 22,
              child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white),
            )
          : Text(label, style: const TextStyle(fontWeight: FontWeight.w800)),
    );
  }

  Widget _basicsStep() => _page(children: [
        _card([
          _title(
            'حسابك الأساسي',
            'أنشئ كلمة مرور للدخول وPIN مالياً مستقلاً. يمكنك توثيق الهوية ورفع الحدود لاحقاً من حسابك.',
            Icons.account_balance_wallet_outlined,
          ),
          _field(_name, 'الاسم الكامل'),
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              SizedBox(
                width: 100,
                child: DropdownButtonFormField<String>(
                  initialValue: _dialCode,
                  decoration: InputDecoration(
                    labelText: 'الدولة',
                    border: OutlineInputBorder(
                      borderRadius: BorderRadius.circular(14),
                    ),
                  ),
                  items: const [
                    DropdownMenuItem(value: '+967', child: Text('+967')),
                    DropdownMenuItem(value: '+966', child: Text('+966')),
                    DropdownMenuItem(value: '+968', child: Text('+968')),
                  ],
                  onChanged: (v) => setState(() => _dialCode = v ?? '+967'),
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: _field(
                  _phone,
                  'رقم الهاتف',
                  keyboardType: TextInputType.phone,
                  formatters: [FilteringTextInputFormatter.allow(RegExp(r'[0-9]'))],
                ),
              ),
            ],
          ),
          _field(_email, 'البريد الإلكتروني', keyboardType: TextInputType.emailAddress),
          Row(
            children: [
              Expanded(
                child: _field(
                  _password,
                  'registration_login_password_label'.tr,
                  obscure: true,
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: _field(
                  _passwordConfirm,
                  'registration_login_password_confirm_label'.tr,
                  obscure: true,
                ),
              ),
            ],
          ),
          Container(
            width: double.infinity,
            margin: const EdgeInsets.only(bottom: 12),
            padding: const EdgeInsets.all(12),
            decoration: BoxDecoration(
              color: const Color(0xFFF3F8FF),
              borderRadius: BorderRadius.circular(14),
            ),
            child: Text(
              'registration_password_pin_separation_help'.tr,
              style: TextStyle(fontSize: 12.5, height: 1.5),
            ),
          ),
          Row(
            children: [
              Expanded(
                child: _field(
                  _pin,
                  'registration_financial_pin_label'.tr,
                  keyboardType: TextInputType.number,
                  obscure: true,
                  maxLength: 4,
                  formatters: [FilteringTextInputFormatter.digitsOnly],
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: _field(
                  _pinConfirm,
                  'تأكيد PIN',
                  keyboardType: TextInputType.number,
                  obscure: true,
                  maxLength: 4,
                  formatters: [FilteringTextInputFormatter.digitsOnly],
                ),
              ),
            ],
          ),
          CheckboxListTile(
            value: _termsAccepted,
            contentPadding: EdgeInsets.zero,
            controlAffinity: ListTileControlAffinity.leading,
            title: const Text(
              'أوافق على شروط الاستخدام وسياسة الخصوصية الخاصة بأميال باي.',
              style: TextStyle(fontSize: 13, height: 1.5),
            ),
            onChanged: (v) => setState(() => _termsAccepted = v == true),
          ),
          const SizedBox(height: 10),
          _primaryButton('إرسال رمز البريد', _requestEmailOtp),
        ]),
      ]);

  Widget _emailStep() => _page(children: [
        _card([
          _title(
            'تأكيد البريد',
            'أرسلنا رمزاً من 6 أرقام إلى $_normalizedEmail. بعد نجاحه ننشئ المحفظة مباشرة.',
            Icons.mark_email_read_outlined,
          ),
          _field(
            _emailOtp,
            'رمز البريد',
            keyboardType: TextInputType.number,
            maxLength: 6,
            formatters: [FilteringTextInputFormatter.digitsOnly],
          ),
          _primaryButton('تحقق وأنشئ المحفظة', _verifyEmailAndCreate),
          const SizedBox(height: 8),
          TextButton(
            onPressed: _busy || _emailResendSeconds > 0 ? null : _requestEmailOtp,
            child: Text(
              _emailResendSeconds > 0
                  ? 'إعادة الإرسال بعد $_emailResendSeconds ثانية'
                  : 'إعادة إرسال الرمز',
            ),
          ),
        ]),
      ]);

  Widget _phoneStep() => _page(children: [
        _card([
          _title(
            'أثبت ملكية هاتفك',
            'نجح إنشاء الحساب. إثبات الهاتف ينقلك إلى حالة عميل موثق جزئيا، لكنه لا يفتح الحركة المالية قبل اعتماد السكن.',
            Icons.phonelink_lock_outlined,
          ),
          _field(
            _phoneOtp,
            'رمز الهاتف',
            keyboardType: TextInputType.number,
            maxLength: 4,
            formatters: [FilteringTextInputFormatter.digitsOnly],
          ),
          _primaryButton('تأكيد الهاتف', _verifyPhoneOtp),
          const SizedBox(height: 8),
          OutlinedButton.icon(
            onPressed: _busy ? null : () => _requestPhoneOtp(),
            icon: const Icon(Icons.refresh),
            label: const Text('إرسال رمز جديد'),
          ),
          const SizedBox(height: 8),
          TextButton(
            onPressed: _busy ? null : () => setState(() => _step = 4),
            child: const Text('سأكمل إثبات الهاتف لاحقاً'),
          ),
        ]),
      ]);

  Widget _residenceStep() {
    if (_evidenceOptions.isEmpty) {
      _evidenceOptions = _fallbackEvidence;
      _evidenceType ??= _evidenceOptions.first['code']?.toString();
    }
    final selected = _evidenceOptions.firstWhere(
      (e) => e['code']?.toString() == _evidenceType,
      orElse: () => _evidenceOptions.first,
    );
    final supporting = selected['strength']?.toString() == 'supporting';

    return _page(children: [
      _card([
        _title(
          'أثبت محل إقامتك الحالي',
          'المحافظة التي تسكن فيها فعلياً هي التي تحدد نطاق التشغيل. محافظة الأصل لا تستخدم لهذا القرار.',
          Icons.home_work_outlined,
        ),
        GovernoratePicker(
          label: 'محافظة الإقامة الحالية',
          value: _residenceGovernorate,
          helper: 'اختر مكان السكن الحالي، وليس محافظة الأصل.',
          onChanged: (v) => setState(() => _residenceGovernorate = v),
        ),
        const SizedBox(height: 4),
        DropdownButtonFormField<String>(
          initialValue: _evidenceType,
          isExpanded: true,
          decoration: InputDecoration(
            labelText: 'نوع دليل السكن',
            border: OutlineInputBorder(borderRadius: BorderRadius.circular(14)),
          ),
          items: _evidenceOptions
              .map(
                (e) => DropdownMenuItem<String>(
                  value: e['code']?.toString(),
                  child: Text(e['label']?.toString() ?? e['code'].toString()),
                ),
              )
              .toList(),
          onChanged: (v) => setState(() => _evidenceType = v),
        ),
        const SizedBox(height: 10),
        Container(
          padding: const EdgeInsets.all(13),
          decoration: BoxDecoration(
            color: supporting
                ? const Color(0xFFFFF8E6)
                : const Color(0xFFF3F8FF),
            borderRadius: BorderRadius.circular(14),
          ),
          child: Text(
            selected['description']?.toString() ?? '',
            style: const TextStyle(fontSize: 13, height: 1.5),
          ),
        ),
        const SizedBox(height: 14),
        if (_residenceEvidence != null)
          Container(
            padding: const EdgeInsets.all(12),
            margin: const EdgeInsets.only(bottom: 10),
            decoration: BoxDecoration(
              color: const Color(0xFFF3FAF6),
              borderRadius: BorderRadius.circular(14),
            ),
            child: const Row(
              children: [
                Icon(Icons.check_circle, color: Color(0xFF16874C)),
                SizedBox(width: 8),
                Expanded(child: Text('تم اختيار دليل السكن. راجعه قبل الإرسال.')),
              ],
            ),
          ),
        Row(
          children: [
            Expanded(
              child: OutlinedButton.icon(
                onPressed: _busy
                    ? null
                    : () => _pickResidenceEvidence(ImageSource.camera),
                icon: const Icon(Icons.photo_camera_outlined),
                label: const Text('التقاط صورة'),
              ),
            ),
            const SizedBox(width: 8),
            Expanded(
              child: OutlinedButton.icon(
                onPressed: _busy
                    ? null
                    : () => _pickResidenceEvidence(ImageSource.gallery),
                icon: const Icon(Icons.photo_library_outlined),
                label: const Text('من المعرض'),
              ),
            ),
          ],
        ),
        const SizedBox(height: 14),
        _primaryButton('إرسال للمراجعة', _submitResidence),
        const SizedBox(height: 8),
        TextButton(
          onPressed: _busy ? null : () => setState(() => _step = 4),
          child: const Text('سأرفع إثبات السكن لاحقاً'),
        ),
      ]),
    ]);
  }

  Widget _successStep() {
    final verificationState = CustomerVerificationLevel.fromTier(
      _phoneVerified ? 1 : 0,
    );
    final message = !_phoneVerified
        ? 'تم إنشاء حسابك. أثبت ملكية الهاتف لاحقاً للانتقال إلى عميل موثق جزئيا.'
        : _residenceSubmitted
            ? 'تم إنشاء حسابك وإثبات هاتفك، وإثبات السكن الآن في قائمة المراجعة. ستفتح الحركة المالية بعد اعتماده.'
            : 'تم إنشاء حسابك وإثبات هاتفك. بقي إثبات محل الإقامة قبل تشغيل الحركة المالية.';

    return _page(children: [
      _card([
        const SizedBox(height: 8),
        Container(
          width: 76,
          height: 76,
          alignment: Alignment.center,
          decoration: BoxDecoration(
            color: const Color(0xFFEAF8F0),
            borderRadius: BorderRadius.circular(28),
          ),
          child: const Icon(Icons.check_rounded, size: 42, color: Color(0xFF16874C)),
        ),
        const SizedBox(height: 18),
        const Text(
          'محفظتك جاهزة',
          style: TextStyle(
            fontSize: 24,
            fontWeight: FontWeight.w900,
            color: AmialColors.textPrimary,
          ),
        ),
        const SizedBox(height: 8),
        Text(
          message,
          style: const TextStyle(
            height: 1.65,
            color: AmialColors.textSecondary,
          ),
        ),
        const SizedBox(height: 18),
        Container(
          padding: const EdgeInsets.all(14),
          decoration: BoxDecoration(
            color: const Color(0xFFF7F8FB),
            borderRadius: BorderRadius.circular(14),
          ),
          child: Row(
            children: [
              const Icon(Icons.shield_outlined, color: AmialColors.primary),
              const SizedBox(width: 10),
              Expanded(
                child: Text(
                  'حالة توثيقك الحالية: ${verificationState.label}. حدود المال وسياسة التشغيل تُطبّق من الخادم.',
                  style: const TextStyle(fontSize: 13, height: 1.5),
                ),
              ),
            ],
          ),
        ),
        const SizedBox(height: 18),
        _primaryButton('الدخول إلى أميال باي', _finish),
      ]),
    ]);
  }
}
).hasMatch(_phoneOtp.text.trim())) {
      _snack('أدخل رمز الهاتف المكوّن من 6 أرقام.');
      return;
    }
    setState(() => _busy = true);
    try {
      final response = await Get.find<ApiClient>().postData(
        '/api/v1/customer/verify-otp',
        {'otp': _phoneOtp.text.trim()},
      );
      if (response.statusCode != 200 || response.body is! Map) {
        _snack(_responseMessage(response, 'رمز الهاتف غير صحيح.'));
        return;
      }
      _phoneVerified = response.body['is_phone_verified'] == true;
      if (!_phoneVerified) {
        _snack(_responseMessage(response, 'لم يكتمل توثيق الهاتف.'));
        return;
      }
      if (!mounted) return;
      setState(() => _step = 3);
      _snack('تم إثبات ملكية الهاتف. حسابك الآن موثق جزئيا بانتظار إثبات السكن.', error: false);
    } catch (_) {
      _snack('تعذر التحقق من رمز الهاتف.');
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _loadResidenceOptions() async {
    try {
      final response = await Get.find<ApiClient>()
          .getData('/api/v1/amial/me/kyc/residence');
      if (response.statusCode == 200 && response.body is Map) {
        final options = response.body['evidence_options'];
        if (options is List && options.isNotEmpty) {
          _evidenceOptions = options
              .whereType<Map>()
              .map((e) => Map<String, dynamic>.from(e))
              .toList();
        }
      }
    } catch (_) {}
    if (_evidenceOptions.isEmpty) {
      _evidenceOptions = _fallbackEvidence;
    }
    if (_evidenceType == null && _evidenceOptions.isNotEmpty) {
      _evidenceType = _evidenceOptions.first['code']?.toString();
    }
  }

  Future<void> _pickResidenceEvidence(ImageSource source) async {
    try {
      final picked = await ImagePicker().pickImage(
        source: source,
        imageQuality: 88,
        maxWidth: 2200,
      );
      if (picked != null && mounted) {
        setState(() => _residenceEvidence = picked);
      }
    } catch (_) {
      _snack('تعذر اختيار دليل السكن.');
    }
  }

  Future<void> _submitResidence() async {
    if (_busy) return;
    if (_residenceGovernorate == null || _residenceGovernorate!.isEmpty) {
      _snack('اختر محافظة إقامتك الحالية.');
      return;
    }
    if (_evidenceType == null || _evidenceType!.isEmpty) {
      _snack('اختر نوع دليل السكن.');
      return;
    }
    if (_residenceEvidence == null) {
      _snack('أرفق صورة واضحة لدليل السكن.');
      return;
    }

    setState(() => _busy = true);
    try {
      final response = await Get.find<ApiClient>().postMultipartData(
        '/api/v1/amial/me/kyc/residence',
        {
          'residence_governorate': _residenceGovernorate!,
          'evidence_type': _evidenceType!,
        },
        [MultipartBody('evidence', File(_residenceEvidence!.path))],
      );
      if (response.statusCode != 201 ||
          response.body is! Map ||
          response.body['success'] != true) {
        _snack(_responseMessage(response, 'تعذر إرسال إثبات السكن.'));
        return;
      }
      _residenceSubmitted = true;
      if (!mounted) return;
      setState(() => _step = 4);
      _snack('تم إرسال إثبات السكن للمراجعة.', error: false);
    } catch (_) {
      _snack('تعذر إرسال إثبات السكن. تحقق من الاتصال وحاول مرة أخرى.');
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  void _finish() {
    if (_accountCreated) {
      RoleRouter.navigateToHome('customer');
    } else {
      Get.back();
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: const Color(0xFFF7F8FB),
      body: SafeArea(
        child: Column(
          children: [
            _header(context),
            if (_step < 4) _progress(),
            Expanded(
              child: AnimatedSwitcher(
                duration: const Duration(milliseconds: 220),
                child: KeyedSubtree(
                  key: ValueKey(_step),
                  child: switch (_step) {
                    0 => _basicsStep(),
                    1 => _emailStep(),
                    2 => _phoneStep(),
                    3 => _residenceStep(),
                    _ => _successStep(),
                  },
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _header(BuildContext context) {
    return Container(
      padding: const EdgeInsets.fromLTRB(16, 12, 16, 14),
      color: Colors.white,
      child: Row(
        children: [
          IconButton(
            onPressed: _busy
                ? null
                : () {
                    if (_step == 0 || _accountCreated) {
                      Get.back();
                    } else {
                      setState(() => _step--);
                    }
                  },
            icon: const Icon(Icons.arrow_back_ios_new_rounded, size: 20),
          ),
          const SizedBox(width: 8),
          Container(
            width: 44,
            height: 44,
            padding: const EdgeInsets.all(6),
            decoration: BoxDecoration(
              color: AmialColors.yellow,
              borderRadius: BorderRadius.circular(14),
            ),
            child: const AmialBrandLogo(fit: BoxFit.contain),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  'افتح محفظتك بخطوات بسيطة',
                  style: Theme.of(context).textTheme.titleMedium?.copyWith(
                        fontWeight: FontWeight.w900,
                        color: AmialColors.textPrimary,
                      ),
                ),
                const SizedBox(height: 2),
                const Text(
                  'لا نطلب الهوية أو الصورة الشخصية عند إنشاء الحساب الأساسي.',
                  style: TextStyle(fontSize: 12, color: AmialColors.textMuted),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _progress() {
    const labels = ['الحساب', 'البريد', 'الهاتف', 'السكن'];
    return Container(
      color: Colors.white,
      padding: const EdgeInsets.fromLTRB(20, 2, 20, 16),
      child: Row(
        children: List.generate(labels.length, (index) {
          final active = index <= _step;
          return Expanded(
            child: Column(
              children: [
                Container(
                  height: 4,
                  margin: EdgeInsetsDirectional.only(
                    end: index == labels.length - 1 ? 0 : 5,
                  ),
                  decoration: BoxDecoration(
                    color: active ? AmialColors.primary : const Color(0xFFE2E6ED),
                    borderRadius: BorderRadius.circular(8),
                  ),
                ),
                const SizedBox(height: 6),
                Text(
                  labels[index],
                  style: TextStyle(
                    fontSize: 11,
                    fontWeight: active ? FontWeight.w800 : FontWeight.w500,
                    color: active ? AmialColors.primary : AmialColors.textMuted,
                  ),
                ),
              ],
            ),
          );
        }),
      ),
    );
  }

  Widget _page({required List<Widget> children}) {
    return SingleChildScrollView(
      padding: const EdgeInsets.all(18),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: children,
      ),
    );
  }

  Widget _card(List<Widget> children) {
    return Container(
      padding: const EdgeInsets.all(18),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(22),
        border: Border.all(color: const Color(0xFFE7EAF0)),
        boxShadow: const [
          BoxShadow(
            blurRadius: 20,
            offset: Offset(0, 8),
            color: Color(0x0C000000),
          ),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: children,
      ),
    );
  }

  Widget _title(String title, String subtitle, IconData icon) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Container(
          width: 46,
          height: 46,
          decoration: BoxDecoration(
            color: AmialColors.primary.withValues(alpha: 0.08),
            borderRadius: BorderRadius.circular(15),
          ),
          child: Icon(icon, color: AmialColors.primary),
        ),
        const SizedBox(height: 14),
        Text(
          title,
          style: const TextStyle(
            fontSize: 22,
            fontWeight: FontWeight.w900,
            color: AmialColors.textPrimary,
          ),
        ),
        const SizedBox(height: 6),
        Text(
          subtitle,
          style: const TextStyle(
            height: 1.6,
            color: AmialColors.textSecondary,
          ),
        ),
        const SizedBox(height: 20),
      ],
    );
  }

  Widget _field(
    TextEditingController controller,
    String label, {
    TextInputType? keyboardType,
    bool obscure = false,
    int? maxLength,
    List<TextInputFormatter>? formatters,
  }) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 13),
      child: TextField(
        controller: controller,
        keyboardType: keyboardType,
        obscureText: obscure,
        maxLength: maxLength,
        inputFormatters: formatters,
        decoration: InputDecoration(
          labelText: label,
          counterText: '',
          filled: true,
          fillColor: const Color(0xFFFBFCFE),
          border: OutlineInputBorder(
            borderRadius: BorderRadius.circular(14),
            borderSide: const BorderSide(color: Color(0xFFE0E4EA)),
          ),
          enabledBorder: OutlineInputBorder(
            borderRadius: BorderRadius.circular(14),
            borderSide: const BorderSide(color: Color(0xFFE0E4EA)),
          ),
        ),
      ),
    );
  }

  Widget _primaryButton(String label, VoidCallback? onPressed) {
    return FilledButton(
      onPressed: _busy ? null : onPressed,
      style: FilledButton.styleFrom(
        backgroundColor: AmialColors.primary,
        foregroundColor: Colors.white,
        minimumSize: const Size.fromHeight(52),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
      ),
      child: _busy
          ? const SizedBox(
              width: 22,
              height: 22,
              child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white),
            )
          : Text(label, style: const TextStyle(fontWeight: FontWeight.w800)),
    );
  }

  Widget _basicsStep() => _page(children: [
        _card([
          _title(
            'حسابك الأساسي',
            'أنشئ كلمة مرور للدخول وPIN مالياً مستقلاً. يمكنك توثيق الهوية ورفع الحدود لاحقاً من حسابك.',
            Icons.account_balance_wallet_outlined,
          ),
          _field(_name, 'الاسم الكامل'),
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              SizedBox(
                width: 100,
                child: DropdownButtonFormField<String>(
                  initialValue: _dialCode,
                  decoration: InputDecoration(
                    labelText: 'الدولة',
                    border: OutlineInputBorder(
                      borderRadius: BorderRadius.circular(14),
                    ),
                  ),
                  items: const [
                    DropdownMenuItem(value: '+967', child: Text('+967')),
                    DropdownMenuItem(value: '+966', child: Text('+966')),
                    DropdownMenuItem(value: '+968', child: Text('+968')),
                  ],
                  onChanged: (v) => setState(() => _dialCode = v ?? '+967'),
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: _field(
                  _phone,
                  'رقم الهاتف',
                  keyboardType: TextInputType.phone,
                  formatters: [FilteringTextInputFormatter.allow(RegExp(r'[0-9]'))],
                ),
              ),
            ],
          ),
          _field(_email, 'البريد الإلكتروني', keyboardType: TextInputType.emailAddress),
          Row(
            children: [
              Expanded(
                child: _field(
                  _password,
                  'registration_login_password_label'.tr,
                  obscure: true,
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: _field(
                  _passwordConfirm,
                  'registration_login_password_confirm_label'.tr,
                  obscure: true,
                ),
              ),
            ],
          ),
          Container(
            width: double.infinity,
            margin: const EdgeInsets.only(bottom: 12),
            padding: const EdgeInsets.all(12),
            decoration: BoxDecoration(
              color: const Color(0xFFF3F8FF),
              borderRadius: BorderRadius.circular(14),
            ),
            child: Text(
              'registration_password_pin_separation_help'.tr,
              style: TextStyle(fontSize: 12.5, height: 1.5),
            ),
          ),
          Row(
            children: [
              Expanded(
                child: _field(
                  _pin,
                  'registration_financial_pin_label'.tr,
                  keyboardType: TextInputType.number,
                  obscure: true,
                  maxLength: 4,
                  formatters: [FilteringTextInputFormatter.digitsOnly],
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: _field(
                  _pinConfirm,
                  'تأكيد PIN',
                  keyboardType: TextInputType.number,
                  obscure: true,
                  maxLength: 4,
                  formatters: [FilteringTextInputFormatter.digitsOnly],
                ),
              ),
            ],
          ),
          CheckboxListTile(
            value: _termsAccepted,
            contentPadding: EdgeInsets.zero,
            controlAffinity: ListTileControlAffinity.leading,
            title: const Text(
              'أوافق على شروط الاستخدام وسياسة الخصوصية الخاصة بأميال باي.',
              style: TextStyle(fontSize: 13, height: 1.5),
            ),
            onChanged: (v) => setState(() => _termsAccepted = v == true),
          ),
          const SizedBox(height: 10),
          _primaryButton('إرسال رمز البريد', _requestEmailOtp),
        ]),
      ]);

  Widget _emailStep() => _page(children: [
        _card([
          _title(
            'تأكيد البريد',
            'أرسلنا رمزاً من 6 أرقام إلى $_normalizedEmail. بعد نجاحه ننشئ المحفظة مباشرة.',
            Icons.mark_email_read_outlined,
          ),
          _field(
            _emailOtp,
            'رمز البريد',
            keyboardType: TextInputType.number,
            maxLength: 6,
            formatters: [FilteringTextInputFormatter.digitsOnly],
          ),
          _primaryButton('تحقق وأنشئ المحفظة', _verifyEmailAndCreate),
          const SizedBox(height: 8),
          TextButton(
            onPressed: _busy || _emailResendSeconds > 0 ? null : _requestEmailOtp,
            child: Text(
              _emailResendSeconds > 0
                  ? 'إعادة الإرسال بعد $_emailResendSeconds ثانية'
                  : 'إعادة إرسال الرمز',
            ),
          ),
        ]),
      ]);

  Widget _phoneStep() => _page(children: [
        _card([
          _title(
            'أثبت ملكية هاتفك',
            'نجح إنشاء الحساب. إثبات الهاتف ينقلك إلى حالة عميل موثق جزئيا، لكنه لا يفتح الحركة المالية قبل اعتماد السكن.',
            Icons.phonelink_lock_outlined,
          ),
          _field(
            _phoneOtp,
            'رمز الهاتف',
            keyboardType: TextInputType.number,
            maxLength: 4,
            formatters: [FilteringTextInputFormatter.digitsOnly],
          ),
          _primaryButton('تأكيد الهاتف', _verifyPhoneOtp),
          const SizedBox(height: 8),
          OutlinedButton.icon(
            onPressed: _busy ? null : () => _requestPhoneOtp(),
            icon: const Icon(Icons.refresh),
            label: const Text('إرسال رمز جديد'),
          ),
          const SizedBox(height: 8),
          TextButton(
            onPressed: _busy ? null : () => setState(() => _step = 4),
            child: const Text('سأكمل إثبات الهاتف لاحقاً'),
          ),
        ]),
      ]);

  Widget _residenceStep() {
    if (_evidenceOptions.isEmpty) {
      _evidenceOptions = _fallbackEvidence;
      _evidenceType ??= _evidenceOptions.first['code']?.toString();
    }
    final selected = _evidenceOptions.firstWhere(
      (e) => e['code']?.toString() == _evidenceType,
      orElse: () => _evidenceOptions.first,
    );
    final supporting = selected['strength']?.toString() == 'supporting';

    return _page(children: [
      _card([
        _title(
          'أثبت محل إقامتك الحالي',
          'المحافظة التي تسكن فيها فعلياً هي التي تحدد نطاق التشغيل. محافظة الأصل لا تستخدم لهذا القرار.',
          Icons.home_work_outlined,
        ),
        GovernoratePicker(
          label: 'محافظة الإقامة الحالية',
          value: _residenceGovernorate,
          helper: 'اختر مكان السكن الحالي، وليس محافظة الأصل.',
          onChanged: (v) => setState(() => _residenceGovernorate = v),
        ),
        const SizedBox(height: 4),
        DropdownButtonFormField<String>(
          initialValue: _evidenceType,
          isExpanded: true,
          decoration: InputDecoration(
            labelText: 'نوع دليل السكن',
            border: OutlineInputBorder(borderRadius: BorderRadius.circular(14)),
          ),
          items: _evidenceOptions
              .map(
                (e) => DropdownMenuItem<String>(
                  value: e['code']?.toString(),
                  child: Text(e['label']?.toString() ?? e['code'].toString()),
                ),
              )
              .toList(),
          onChanged: (v) => setState(() => _evidenceType = v),
        ),
        const SizedBox(height: 10),
        Container(
          padding: const EdgeInsets.all(13),
          decoration: BoxDecoration(
            color: supporting
                ? const Color(0xFFFFF8E6)
                : const Color(0xFFF3F8FF),
            borderRadius: BorderRadius.circular(14),
          ),
          child: Text(
            selected['description']?.toString() ?? '',
            style: const TextStyle(fontSize: 13, height: 1.5),
          ),
        ),
        const SizedBox(height: 14),
        if (_residenceEvidence != null)
          Container(
            padding: const EdgeInsets.all(12),
            margin: const EdgeInsets.only(bottom: 10),
            decoration: BoxDecoration(
              color: const Color(0xFFF3FAF6),
              borderRadius: BorderRadius.circular(14),
            ),
            child: const Row(
              children: [
                Icon(Icons.check_circle, color: Color(0xFF16874C)),
                SizedBox(width: 8),
                Expanded(child: Text('تم اختيار دليل السكن. راجعه قبل الإرسال.')),
              ],
            ),
          ),
        Row(
          children: [
            Expanded(
              child: OutlinedButton.icon(
                onPressed: _busy
                    ? null
                    : () => _pickResidenceEvidence(ImageSource.camera),
                icon: const Icon(Icons.photo_camera_outlined),
                label: const Text('التقاط صورة'),
              ),
            ),
            const SizedBox(width: 8),
            Expanded(
              child: OutlinedButton.icon(
                onPressed: _busy
                    ? null
                    : () => _pickResidenceEvidence(ImageSource.gallery),
                icon: const Icon(Icons.photo_library_outlined),
                label: const Text('من المعرض'),
              ),
            ),
          ],
        ),
        const SizedBox(height: 14),
        _primaryButton('إرسال للمراجعة', _submitResidence),
        const SizedBox(height: 8),
        TextButton(
          onPressed: _busy ? null : () => setState(() => _step = 4),
          child: const Text('سأرفع إثبات السكن لاحقاً'),
        ),
      ]),
    ]);
  }

  Widget _successStep() {
    final verificationState = CustomerVerificationLevel.fromTier(
      _phoneVerified ? 1 : 0,
    );
    final message = !_phoneVerified
        ? 'تم إنشاء حسابك. أثبت ملكية الهاتف لاحقاً للانتقال إلى عميل موثق جزئيا.'
        : _residenceSubmitted
            ? 'تم إنشاء حسابك وإثبات هاتفك، وإثبات السكن الآن في قائمة المراجعة. ستفتح الحركة المالية بعد اعتماده.'
            : 'تم إنشاء حسابك وإثبات هاتفك. بقي إثبات محل الإقامة قبل تشغيل الحركة المالية.';

    return _page(children: [
      _card([
        const SizedBox(height: 8),
        Container(
          width: 76,
          height: 76,
          alignment: Alignment.center,
          decoration: BoxDecoration(
            color: const Color(0xFFEAF8F0),
            borderRadius: BorderRadius.circular(28),
          ),
          child: const Icon(Icons.check_rounded, size: 42, color: Color(0xFF16874C)),
        ),
        const SizedBox(height: 18),
        const Text(
          'محفظتك جاهزة',
          style: TextStyle(
            fontSize: 24,
            fontWeight: FontWeight.w900,
            color: AmialColors.textPrimary,
          ),
        ),
        const SizedBox(height: 8),
        Text(
          message,
          style: const TextStyle(
            height: 1.65,
            color: AmialColors.textSecondary,
          ),
        ),
        const SizedBox(height: 18),
        Container(
          padding: const EdgeInsets.all(14),
          decoration: BoxDecoration(
            color: const Color(0xFFF7F8FB),
            borderRadius: BorderRadius.circular(14),
          ),
          child: Row(
            children: [
              const Icon(Icons.shield_outlined, color: AmialColors.primary),
              const SizedBox(width: 10),
              Expanded(
                child: Text(
                  'حالة توثيقك الحالية: ${verificationState.label}. حدود المال وسياسة التشغيل تُطبّق من الخادم.',
                  style: const TextStyle(fontSize: 13, height: 1.5),
                ),
              ),
            ],
          ),
        ),
        const SizedBox(height: 18),
        _primaryButton('الدخول إلى أميال باي', _finish),
      ]),
    ]);
  }
}
).hasMatch(demoOtp)) {
        _phoneOtp.text = demoOtp;
      }
      if (!silentSuccess) {
        _snack(
          body['pilot_mode'] == true
              ? 'وضع تجريبي: رمز الهاتف الحالي هو 123456'
              : 'أرسلنا رمز التحقق إلى رقم هاتفك.',
          error: false,
        );
      }
    } catch (_) {
      _snack('الحساب جاهز، لكن تعذر إرسال رمز الهاتف الآن. يمكنك إكماله لاحقاً.');
    } finally {
      if (!silentSuccess && mounted) setState(() => _busy = false);
    }
  }

  Future<void> _verifyPhoneOtp() async {
    if (_busy) return;
    if (!RegExp(r'^\d{4}$').hasMatch(_phoneOtp.text.trim())) {
      _snack('أدخل رمز الهاتف المكوّن من 4 أرقام.');
      return;
    }
    setState(() => _busy = true);
    try {
      final response = await Get.find<ApiClient>().postData(
        '/api/v1/customer/verify-otp',
        {'otp': _phoneOtp.text.trim()},
      );
      if (response.statusCode != 200 || response.body is! Map) {
        _snack(_responseMessage(response, 'رمز الهاتف غير صحيح.'));
        return;
      }
      _phoneVerified = response.body['is_phone_verified'] == true;
      if (!_phoneVerified) {
        _snack(_responseMessage(response, 'لم يكتمل توثيق الهاتف.'));
        return;
      }
      if (!mounted) return;
      setState(() => _step = 3);
      _snack('تم إثبات ملكية الهاتف. حسابك الآن موثق جزئيا بانتظار إثبات السكن.', error: false);
    } catch (_) {
      _snack('تعذر التحقق من رمز الهاتف.');
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _loadResidenceOptions() async {
    try {
      final response = await Get.find<ApiClient>()
          .getData('/api/v1/amial/me/kyc/residence');
      if (response.statusCode == 200 && response.body is Map) {
        final options = response.body['evidence_options'];
        if (options is List && options.isNotEmpty) {
          _evidenceOptions = options
              .whereType<Map>()
              .map((e) => Map<String, dynamic>.from(e))
              .toList();
        }
      }
    } catch (_) {}
    if (_evidenceOptions.isEmpty) {
      _evidenceOptions = _fallbackEvidence;
    }
    if (_evidenceType == null && _evidenceOptions.isNotEmpty) {
      _evidenceType = _evidenceOptions.first['code']?.toString();
    }
  }

  Future<void> _pickResidenceEvidence(ImageSource source) async {
    try {
      final picked = await ImagePicker().pickImage(
        source: source,
        imageQuality: 88,
        maxWidth: 2200,
      );
      if (picked != null && mounted) {
        setState(() => _residenceEvidence = picked);
      }
    } catch (_) {
      _snack('تعذر اختيار دليل السكن.');
    }
  }

  Future<void> _submitResidence() async {
    if (_busy) return;
    if (_residenceGovernorate == null || _residenceGovernorate!.isEmpty) {
      _snack('اختر محافظة إقامتك الحالية.');
      return;
    }
    if (_evidenceType == null || _evidenceType!.isEmpty) {
      _snack('اختر نوع دليل السكن.');
      return;
    }
    if (_residenceEvidence == null) {
      _snack('أرفق صورة واضحة لدليل السكن.');
      return;
    }

    setState(() => _busy = true);
    try {
      final response = await Get.find<ApiClient>().postMultipartData(
        '/api/v1/amial/me/kyc/residence',
        {
          'residence_governorate': _residenceGovernorate!,
          'evidence_type': _evidenceType!,
        },
        [MultipartBody('evidence', File(_residenceEvidence!.path))],
      );
      if (response.statusCode != 201 ||
          response.body is! Map ||
          response.body['success'] != true) {
        _snack(_responseMessage(response, 'تعذر إرسال إثبات السكن.'));
        return;
      }
      _residenceSubmitted = true;
      if (!mounted) return;
      setState(() => _step = 4);
      _snack('تم إرسال إثبات السكن للمراجعة.', error: false);
    } catch (_) {
      _snack('تعذر إرسال إثبات السكن. تحقق من الاتصال وحاول مرة أخرى.');
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  void _finish() {
    if (_accountCreated) {
      RoleRouter.navigateToHome('customer');
    } else {
      Get.back();
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: const Color(0xFFF7F8FB),
      body: SafeArea(
        child: Column(
          children: [
            _header(context),
            if (_step < 4) _progress(),
            Expanded(
              child: AnimatedSwitcher(
                duration: const Duration(milliseconds: 220),
                child: KeyedSubtree(
                  key: ValueKey(_step),
                  child: switch (_step) {
                    0 => _basicsStep(),
                    1 => _emailStep(),
                    2 => _phoneStep(),
                    3 => _residenceStep(),
                    _ => _successStep(),
                  },
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _header(BuildContext context) {
    return Container(
      padding: const EdgeInsets.fromLTRB(16, 12, 16, 14),
      color: Colors.white,
      child: Row(
        children: [
          IconButton(
            onPressed: _busy
                ? null
                : () {
                    if (_step == 0 || _accountCreated) {
                      Get.back();
                    } else {
                      setState(() => _step--);
                    }
                  },
            icon: const Icon(Icons.arrow_back_ios_new_rounded, size: 20),
          ),
          const SizedBox(width: 8),
          Container(
            width: 44,
            height: 44,
            padding: const EdgeInsets.all(6),
            decoration: BoxDecoration(
              color: AmialColors.yellow,
              borderRadius: BorderRadius.circular(14),
            ),
            child: const AmialBrandLogo(fit: BoxFit.contain),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  'افتح محفظتك بخطوات بسيطة',
                  style: Theme.of(context).textTheme.titleMedium?.copyWith(
                        fontWeight: FontWeight.w900,
                        color: AmialColors.textPrimary,
                      ),
                ),
                const SizedBox(height: 2),
                const Text(
                  'لا نطلب الهوية أو الصورة الشخصية عند إنشاء الحساب الأساسي.',
                  style: TextStyle(fontSize: 12, color: AmialColors.textMuted),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _progress() {
    const labels = ['الحساب', 'البريد', 'الهاتف', 'السكن'];
    return Container(
      color: Colors.white,
      padding: const EdgeInsets.fromLTRB(20, 2, 20, 16),
      child: Row(
        children: List.generate(labels.length, (index) {
          final active = index <= _step;
          return Expanded(
            child: Column(
              children: [
                Container(
                  height: 4,
                  margin: EdgeInsetsDirectional.only(
                    end: index == labels.length - 1 ? 0 : 5,
                  ),
                  decoration: BoxDecoration(
                    color: active ? AmialColors.primary : const Color(0xFFE2E6ED),
                    borderRadius: BorderRadius.circular(8),
                  ),
                ),
                const SizedBox(height: 6),
                Text(
                  labels[index],
                  style: TextStyle(
                    fontSize: 11,
                    fontWeight: active ? FontWeight.w800 : FontWeight.w500,
                    color: active ? AmialColors.primary : AmialColors.textMuted,
                  ),
                ),
              ],
            ),
          );
        }),
      ),
    );
  }

  Widget _page({required List<Widget> children}) {
    return SingleChildScrollView(
      padding: const EdgeInsets.all(18),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: children,
      ),
    );
  }

  Widget _card(List<Widget> children) {
    return Container(
      padding: const EdgeInsets.all(18),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(22),
        border: Border.all(color: const Color(0xFFE7EAF0)),
        boxShadow: const [
          BoxShadow(
            blurRadius: 20,
            offset: Offset(0, 8),
            color: Color(0x0C000000),
          ),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: children,
      ),
    );
  }

  Widget _title(String title, String subtitle, IconData icon) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Container(
          width: 46,
          height: 46,
          decoration: BoxDecoration(
            color: AmialColors.primary.withValues(alpha: 0.08),
            borderRadius: BorderRadius.circular(15),
          ),
          child: Icon(icon, color: AmialColors.primary),
        ),
        const SizedBox(height: 14),
        Text(
          title,
          style: const TextStyle(
            fontSize: 22,
            fontWeight: FontWeight.w900,
            color: AmialColors.textPrimary,
          ),
        ),
        const SizedBox(height: 6),
        Text(
          subtitle,
          style: const TextStyle(
            height: 1.6,
            color: AmialColors.textSecondary,
          ),
        ),
        const SizedBox(height: 20),
      ],
    );
  }

  Widget _field(
    TextEditingController controller,
    String label, {
    TextInputType? keyboardType,
    bool obscure = false,
    int? maxLength,
    List<TextInputFormatter>? formatters,
  }) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 13),
      child: TextField(
        controller: controller,
        keyboardType: keyboardType,
        obscureText: obscure,
        maxLength: maxLength,
        inputFormatters: formatters,
        decoration: InputDecoration(
          labelText: label,
          counterText: '',
          filled: true,
          fillColor: const Color(0xFFFBFCFE),
          border: OutlineInputBorder(
            borderRadius: BorderRadius.circular(14),
            borderSide: const BorderSide(color: Color(0xFFE0E4EA)),
          ),
          enabledBorder: OutlineInputBorder(
            borderRadius: BorderRadius.circular(14),
            borderSide: const BorderSide(color: Color(0xFFE0E4EA)),
          ),
        ),
      ),
    );
  }

  Widget _primaryButton(String label, VoidCallback? onPressed) {
    return FilledButton(
      onPressed: _busy ? null : onPressed,
      style: FilledButton.styleFrom(
        backgroundColor: AmialColors.primary,
        foregroundColor: Colors.white,
        minimumSize: const Size.fromHeight(52),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
      ),
      child: _busy
          ? const SizedBox(
              width: 22,
              height: 22,
              child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white),
            )
          : Text(label, style: const TextStyle(fontWeight: FontWeight.w800)),
    );
  }

  Widget _basicsStep() => _page(children: [
        _card([
          _title(
            'حسابك الأساسي',
            'أنشئ كلمة مرور للدخول وPIN مالياً مستقلاً. يمكنك توثيق الهوية ورفع الحدود لاحقاً من حسابك.',
            Icons.account_balance_wallet_outlined,
          ),
          _field(_name, 'الاسم الكامل'),
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              SizedBox(
                width: 100,
                child: DropdownButtonFormField<String>(
                  initialValue: _dialCode,
                  decoration: InputDecoration(
                    labelText: 'الدولة',
                    border: OutlineInputBorder(
                      borderRadius: BorderRadius.circular(14),
                    ),
                  ),
                  items: const [
                    DropdownMenuItem(value: '+967', child: Text('+967')),
                    DropdownMenuItem(value: '+966', child: Text('+966')),
                    DropdownMenuItem(value: '+968', child: Text('+968')),
                  ],
                  onChanged: (v) => setState(() => _dialCode = v ?? '+967'),
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: _field(
                  _phone,
                  'رقم الهاتف',
                  keyboardType: TextInputType.phone,
                  formatters: [FilteringTextInputFormatter.allow(RegExp(r'[0-9]'))],
                ),
              ),
            ],
          ),
          _field(_email, 'البريد الإلكتروني', keyboardType: TextInputType.emailAddress),
          Row(
            children: [
              Expanded(
                child: _field(
                  _password,
                  'registration_login_password_label'.tr,
                  obscure: true,
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: _field(
                  _passwordConfirm,
                  'registration_login_password_confirm_label'.tr,
                  obscure: true,
                ),
              ),
            ],
          ),
          Container(
            width: double.infinity,
            margin: const EdgeInsets.only(bottom: 12),
            padding: const EdgeInsets.all(12),
            decoration: BoxDecoration(
              color: const Color(0xFFF3F8FF),
              borderRadius: BorderRadius.circular(14),
            ),
            child: Text(
              'registration_password_pin_separation_help'.tr,
              style: TextStyle(fontSize: 12.5, height: 1.5),
            ),
          ),
          Row(
            children: [
              Expanded(
                child: _field(
                  _pin,
                  'registration_financial_pin_label'.tr,
                  keyboardType: TextInputType.number,
                  obscure: true,
                  maxLength: 4,
                  formatters: [FilteringTextInputFormatter.digitsOnly],
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: _field(
                  _pinConfirm,
                  'تأكيد PIN',
                  keyboardType: TextInputType.number,
                  obscure: true,
                  maxLength: 4,
                  formatters: [FilteringTextInputFormatter.digitsOnly],
                ),
              ),
            ],
          ),
          CheckboxListTile(
            value: _termsAccepted,
            contentPadding: EdgeInsets.zero,
            controlAffinity: ListTileControlAffinity.leading,
            title: const Text(
              'أوافق على شروط الاستخدام وسياسة الخصوصية الخاصة بأميال باي.',
              style: TextStyle(fontSize: 13, height: 1.5),
            ),
            onChanged: (v) => setState(() => _termsAccepted = v == true),
          ),
          const SizedBox(height: 10),
          _primaryButton('إرسال رمز البريد', _requestEmailOtp),
        ]),
      ]);

  Widget _emailStep() => _page(children: [
        _card([
          _title(
            'تأكيد البريد',
            'أرسلنا رمزاً من 6 أرقام إلى $_normalizedEmail. بعد نجاحه ننشئ المحفظة مباشرة.',
            Icons.mark_email_read_outlined,
          ),
          _field(
            _emailOtp,
            'رمز البريد',
            keyboardType: TextInputType.number,
            maxLength: 6,
            formatters: [FilteringTextInputFormatter.digitsOnly],
          ),
          _primaryButton('تحقق وأنشئ المحفظة', _verifyEmailAndCreate),
          const SizedBox(height: 8),
          TextButton(
            onPressed: _busy || _emailResendSeconds > 0 ? null : _requestEmailOtp,
            child: Text(
              _emailResendSeconds > 0
                  ? 'إعادة الإرسال بعد $_emailResendSeconds ثانية'
                  : 'إعادة إرسال الرمز',
            ),
          ),
        ]),
      ]);

  Widget _phoneStep() => _page(children: [
        _card([
          _title(
            'أثبت ملكية هاتفك',
            'نجح إنشاء الحساب. إثبات الهاتف ينقلك إلى حالة عميل موثق جزئيا، لكنه لا يفتح الحركة المالية قبل اعتماد السكن.',
            Icons.phonelink_lock_outlined,
          ),
          _field(
            _phoneOtp,
            'رمز الهاتف',
            keyboardType: TextInputType.number,
            maxLength: 4,
            formatters: [FilteringTextInputFormatter.digitsOnly],
          ),
          _primaryButton('تأكيد الهاتف', _verifyPhoneOtp),
          const SizedBox(height: 8),
          OutlinedButton.icon(
            onPressed: _busy ? null : () => _requestPhoneOtp(),
            icon: const Icon(Icons.refresh),
            label: const Text('إرسال رمز جديد'),
          ),
          const SizedBox(height: 8),
          TextButton(
            onPressed: _busy ? null : () => setState(() => _step = 4),
            child: const Text('سأكمل إثبات الهاتف لاحقاً'),
          ),
        ]),
      ]);

  Widget _residenceStep() {
    if (_evidenceOptions.isEmpty) {
      _evidenceOptions = _fallbackEvidence;
      _evidenceType ??= _evidenceOptions.first['code']?.toString();
    }
    final selected = _evidenceOptions.firstWhere(
      (e) => e['code']?.toString() == _evidenceType,
      orElse: () => _evidenceOptions.first,
    );
    final supporting = selected['strength']?.toString() == 'supporting';

    return _page(children: [
      _card([
        _title(
          'أثبت محل إقامتك الحالي',
          'المحافظة التي تسكن فيها فعلياً هي التي تحدد نطاق التشغيل. محافظة الأصل لا تستخدم لهذا القرار.',
          Icons.home_work_outlined,
        ),
        GovernoratePicker(
          label: 'محافظة الإقامة الحالية',
          value: _residenceGovernorate,
          helper: 'اختر مكان السكن الحالي، وليس محافظة الأصل.',
          onChanged: (v) => setState(() => _residenceGovernorate = v),
        ),
        const SizedBox(height: 4),
        DropdownButtonFormField<String>(
          initialValue: _evidenceType,
          isExpanded: true,
          decoration: InputDecoration(
            labelText: 'نوع دليل السكن',
            border: OutlineInputBorder(borderRadius: BorderRadius.circular(14)),
          ),
          items: _evidenceOptions
              .map(
                (e) => DropdownMenuItem<String>(
                  value: e['code']?.toString(),
                  child: Text(e['label']?.toString() ?? e['code'].toString()),
                ),
              )
              .toList(),
          onChanged: (v) => setState(() => _evidenceType = v),
        ),
        const SizedBox(height: 10),
        Container(
          padding: const EdgeInsets.all(13),
          decoration: BoxDecoration(
            color: supporting
                ? const Color(0xFFFFF8E6)
                : const Color(0xFFF3F8FF),
            borderRadius: BorderRadius.circular(14),
          ),
          child: Text(
            selected['description']?.toString() ?? '',
            style: const TextStyle(fontSize: 13, height: 1.5),
          ),
        ),
        const SizedBox(height: 14),
        if (_residenceEvidence != null)
          Container(
            padding: const EdgeInsets.all(12),
            margin: const EdgeInsets.only(bottom: 10),
            decoration: BoxDecoration(
              color: const Color(0xFFF3FAF6),
              borderRadius: BorderRadius.circular(14),
            ),
            child: const Row(
              children: [
                Icon(Icons.check_circle, color: Color(0xFF16874C)),
                SizedBox(width: 8),
                Expanded(child: Text('تم اختيار دليل السكن. راجعه قبل الإرسال.')),
              ],
            ),
          ),
        Row(
          children: [
            Expanded(
              child: OutlinedButton.icon(
                onPressed: _busy
                    ? null
                    : () => _pickResidenceEvidence(ImageSource.camera),
                icon: const Icon(Icons.photo_camera_outlined),
                label: const Text('التقاط صورة'),
              ),
            ),
            const SizedBox(width: 8),
            Expanded(
              child: OutlinedButton.icon(
                onPressed: _busy
                    ? null
                    : () => _pickResidenceEvidence(ImageSource.gallery),
                icon: const Icon(Icons.photo_library_outlined),
                label: const Text('من المعرض'),
              ),
            ),
          ],
        ),
        const SizedBox(height: 14),
        _primaryButton('إرسال للمراجعة', _submitResidence),
        const SizedBox(height: 8),
        TextButton(
          onPressed: _busy ? null : () => setState(() => _step = 4),
          child: const Text('سأرفع إثبات السكن لاحقاً'),
        ),
      ]),
    ]);
  }

  Widget _successStep() {
    final verificationState = CustomerVerificationLevel.fromTier(
      _phoneVerified ? 1 : 0,
    );
    final message = !_phoneVerified
        ? 'تم إنشاء حسابك. أثبت ملكية الهاتف لاحقاً للانتقال إلى عميل موثق جزئيا.'
        : _residenceSubmitted
            ? 'تم إنشاء حسابك وإثبات هاتفك، وإثبات السكن الآن في قائمة المراجعة. ستفتح الحركة المالية بعد اعتماده.'
            : 'تم إنشاء حسابك وإثبات هاتفك. بقي إثبات محل الإقامة قبل تشغيل الحركة المالية.';

    return _page(children: [
      _card([
        const SizedBox(height: 8),
        Container(
          width: 76,
          height: 76,
          alignment: Alignment.center,
          decoration: BoxDecoration(
            color: const Color(0xFFEAF8F0),
            borderRadius: BorderRadius.circular(28),
          ),
          child: const Icon(Icons.check_rounded, size: 42, color: Color(0xFF16874C)),
        ),
        const SizedBox(height: 18),
        const Text(
          'محفظتك جاهزة',
          style: TextStyle(
            fontSize: 24,
            fontWeight: FontWeight.w900,
            color: AmialColors.textPrimary,
          ),
        ),
        const SizedBox(height: 8),
        Text(
          message,
          style: const TextStyle(
            height: 1.65,
            color: AmialColors.textSecondary,
          ),
        ),
        const SizedBox(height: 18),
        Container(
          padding: const EdgeInsets.all(14),
          decoration: BoxDecoration(
            color: const Color(0xFFF7F8FB),
            borderRadius: BorderRadius.circular(14),
          ),
          child: Row(
            children: [
              const Icon(Icons.shield_outlined, color: AmialColors.primary),
              const SizedBox(width: 10),
              Expanded(
                child: Text(
                  'حالة توثيقك الحالية: ${verificationState.label}. حدود المال وسياسة التشغيل تُطبّق من الخادم.',
                  style: const TextStyle(fontSize: 13, height: 1.5),
                ),
              ),
            ],
          ),
        ),
        const SizedBox(height: 18),
        _primaryButton('الدخول إلى أميال باي', _finish),
      ]),
    ]);
  }
}
