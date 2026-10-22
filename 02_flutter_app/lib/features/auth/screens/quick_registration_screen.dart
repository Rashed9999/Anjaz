import 'dart:async';
import 'dart:io';

import 'package:amial_pay/common/widgets/amial_brand_logo.dart';
import 'package:amial_pay/data/api/api_client.dart';
import 'package:amial_pay/data/api/secure_storage_helper.dart';
import 'package:amial_pay/features/access/controllers/access_controller.dart';
import 'package:amial_pay/features/auth/controllers/auth_controller.dart';
import 'package:amial_pay/features/auth/controllers/unified_auth_controller.dart';
import 'package:amial_pay/features/auth/widgets/governorate_picker.dart';
import 'package:amial_pay/features/kyc_verification/screens/customer_verification_review_screen.dart';
import 'package:amial_pay/features/kyc_verification/widgets/yemen_residence_picker.dart';
import 'package:amial_pay/theme/amial_colors.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:get/get.dart';
import 'package:image_picker/image_picker.dart';
import 'package:url_launcher/url_launcher.dart';

/// AMIAL-PROGRESSIVE-KYC-APP-001
///
/// فتح محفظة العميل على مراحل واضحة:
/// 1) بيانات الاتصال + كلمة مرور دخول + PIN مالي مستقل + موافقة الشروط.
/// 2) إثبات البريد ثم إنشاء الحساب.
/// 3) إثبات ملكية الهاتف — لا يرفع حالة التوثيق وحده.
/// 4) محافظة الميلاد + عنوان السكن الحالي + دليل السكن.
/// 5) اعتماد السكن يرفع العميل إلى «موثق جزئيا»؛ نطاق التشغيل مستقل.
///
/// لا هوية ولا سيلفي في التسجيل الأساسي. ترقية الهوية تتم لاحقاً من الحساب.
class QuickRegistrationScreen extends StatefulWidget {
  const QuickRegistrationScreen({super.key});

  @override
  State<QuickRegistrationScreen> createState() => _QuickRegistrationScreenState();
}

class _QuickRegistrationScreenState extends State<QuickRegistrationScreen> {
  final _givenName = TextEditingController();
  final _fatherName = TextEditingController();
  final _grandfatherName = TextEditingController();
  final _familyName = TextEditingController();
  final _phone = TextEditingController();
  final _email = TextEditingController();
  final _password = TextEditingController();
  final _passwordConfirm = TextEditingController();
  final _pin = TextEditingController();
  final _pinConfirm = TextEditingController();
  final _emailOtp = TextEditingController();
  final _phoneOtp = TextEditingController();
  final _residenceArea = TextEditingController();
  final _residenceLandmark = TextEditingController();

  int _step = 0;
  bool _busy = false;
  bool _termsAccepted = false;
  bool _accountCreated = false;
  bool _phoneVerified = false;
  bool _residenceSubmitted = false;

  String _dialCode = '+967';
  String? _gender;
  String? _emailChallengeId;
  String? _emailVerificationToken;
  String? _openingApplicationUlid;
  String? _openingAccessToken;
  String? _muwathaqPolicySummary;
  bool _muwathaqPolicyLoading = false;
  int _emailResendSeconds = 0;
  Timer? _emailTimer;

  String? _birthGovernorate;
  YemenResidenceSelection _residenceLocation = const YemenResidenceSelection();
  XFile? _residenceEvidence;
  XFile? _identityEvidence;
  String? _verificationPath;
  String? _openingStatus;
  String? _decisionReason;
  List<String> _nextOpeningSteps = const [];

  bool get _requiresIdentity => _verificationPath == 'standard_customer';
  String? _evidenceType;
  List<Map<String, dynamic>> _evidenceOptions = const [];

  static const _openingIdKey = 'muwathaq_opening_application_ulid';
  static const _openingTokenKey = 'muwathaq_opening_access_token';

  @override
  void initState() {
    super.initState();
    _restoreOpeningProgress();
  }

  Future<void> _restoreOpeningProgress() async {
    try {
      final id = await SecureStorageHelper.instance.getSecure(_openingIdKey);
      final token = await SecureStorageHelper.instance.getSecure(_openingTokenKey);
      if (id == null || id.length != 26 || token == null || token.length < 32) return;

      final response = await Get.find<ApiClient>().getData(
        '/api/v1/auth/account-opening/$id/status',
        headers: {'Authorization': 'Bearer $token'},
      );
      final body = response.body;
      final data = body is Map && body['success'] == true && body['data'] is Map
          ? Map<String, dynamic>.from(body['data'] as Map) : null;
      if (data == null) return;

      _adoptOpeningStatus(data, id, token);
    } catch (_) {
      // لا نمسح البيانات عند انقطاع الشبكة؛ قد يعود المستخدم فيكمل لاحقاً.
    }
  }

  void _adoptOpeningStatus(Map<String, dynamic> data, String id, String token) {
    final steps = data['next_steps'] is List
        ? List<String>.from(data['next_steps']) : <String>[];
    final path = data['verification_path']?.toString();
    if (!mounted) return;
    setState(() {
      _openingApplicationUlid = id;
      _openingAccessToken = token;
      _accountCreated = true;
      _verificationPath = path;
      _gender = path == 'standard_customer' ? 'male'
          : path == 'women_progressive' ? 'female' : _gender;
      _openingStatus = data['status']?.toString();
      _decisionReason = data['decision_reason']?.toString();
      _nextOpeningSteps = steps;
      _phoneVerified = !steps.contains('confirm_phone');
      _residenceSubmitted = !steps.contains('submit_residence');
      _step = steps.contains('confirm_phone') ? 2
          : steps.contains('submit_residence') ? 3
          : steps.contains('submit_identity_document') ? 4 : 5;
    });
  }

  Future<void> _refreshOpeningStatus() async {
    final id = _openingApplicationUlid, token = _openingAccessToken;
    if (id == null || token == null) return;
    try {
      final response = await Get.find<ApiClient>().getData(
        '/api/v1/auth/account-opening/$id/status',
        headers: {'Authorization': 'Bearer $token'},
      );
      final body = response.body;
      if (response.statusCode == 403) {
        _snack('انتهت جلسة الاستكمال. استعد طلبك بالبريد وكلمة المرور.');
        return;
      }
      if (body is Map && body['success'] == true && body['data'] is Map) {
        _adoptOpeningStatus(Map<String, dynamic>.from(body['data'] as Map), id, token);
      } else {
        _snack(_responseMessage(response, 'تعذر تحديث حالة الطلب.'));
      }
    } catch (_) {
      _snack('تعذر الاتصال بموثّق لتحديث الحالة.');
    }
  }

  Future<void> _resumeExistingOpening() async {
    if (_busy) return;
    final email = TextEditingController(text: _normalizedEmail);
    final password = TextEditingController();
    final credentials = await showDialog<Map<String, String>>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        title: const Text('استكمال طلب موجود'),
        content: Column(mainAxisSize: MainAxisSize.min, children: [
          const Text('أدخل البريد المسجل وكلمة المرور لاستعادة جلسة استكمال موثّق. لن يُنشأ حساب جديد.'),
          TextField(controller: email,
            keyboardType: TextInputType.emailAddress,
            autocorrect: false,
            decoration: const InputDecoration(labelText: 'البريد الإلكتروني')),
          TextField(controller: password, obscureText: true,
            decoration: const InputDecoration(labelText: 'كلمة المرور')),
        ]),
        actions: [
          TextButton(onPressed: () => Navigator.pop(dialogContext),
            child: const Text('إلغاء')),
          FilledButton(onPressed: () => Navigator.pop(dialogContext, {
            'email': email.text.trim().toLowerCase(),
            'password': password.text,
          }), child: const Text('استعادة الطلب')),
        ],
      ),
    );
    email.dispose();
    password.dispose();
    if (credentials == null || !mounted) return;
    if (credentials['email']?.isEmpty != false || credentials['password']?.isEmpty != false) {
      _snack('أدخل البريد وكلمة المرور.');return;
    }
    setState(() => _busy = true);
    try {
      final response = await Get.find<ApiClient>().postData(
        '/api/v1/auth/account-opening/resume', credentials,
      );
      final body=response.body;
      if (response.statusCode != 200 || body is! Map
          || body['success'] != true || body['data'] is! Map) {
        _snack(_responseMessage(response, 'تعذر استعادة الطلب.'));return;
      }
      final data=Map<String, dynamic>.from(body['data'] as Map);
      final id=data['application_ulid']?.toString();
      final token=data['account_opening_access_token']?.toString();
      if (id == null || id.length != 26) {
        _snack('الطلب غير صالح للاستكمال.');return;
      }
      if (token == null || token.length < 32) {
        _snack('الطلب حُسم بالفعل. حالته: '+(data['status']?.toString() ?? 'غير معروفة'));
        return;
      }
      _openingApplicationUlid=id;
      _openingAccessToken=token;
      await _persistOpeningProgress();
      await _refreshOpeningStatus();
      _snack('تمت استعادة طلبك الحالي بأمان.', error: false);
    } catch (_) {
      _snack('تعذر استعادة طلب التوثيق. تحقق من الاتصال.');
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _persistOpeningProgress() async {
    if (_openingApplicationUlid == null || _openingAccessToken == null) return;
    await SecureStorageHelper.instance.setSecure(_openingIdKey, _openingApplicationUlid!);
    await SecureStorageHelper.instance.setSecure(_openingTokenKey, _openingAccessToken!);
  }

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
      _givenName,
      _fatherName,
      _grandfatherName,
      _familyName,
      _phone,
      _email,
      _password,
      _passwordConfirm,
      _pin,
      _pinConfirm,
      _emailOtp,
      _phoneOtp,
      _residenceArea,
      _residenceLandmark,
    ]) {
      c.dispose();
    }
    super.dispose();
  }

  String get _declaredLegalName => [
        _givenName.text.trim(),
        _fatherName.text.trim(),
        _grandfatherName.text.trim(),
        _familyName.text.trim(),
      ].where((part) => part.isNotEmpty).join(' ');

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
    final nameParts = <String, String>{
      'الاسم': _givenName.text.trim(),
      'اسم الأب': _fatherName.text.trim(),
      'اسم الجد': _grandfatherName.text.trim(),
      'اللقب / اسم العائلة': _familyName.text.trim(),
    };
    for (final entry in nameParts.entries) {
      if (entry.value.length < 2) {
        _snack('أدخل ${entry.key} الحقيقي كما يظهر في وثائقك الرسمية.');
        return false;
      }
      if (!RegExp(r"^[A-Za-z\u0600-\u06FF\s\-']+$").hasMatch(entry.value)) {
        _snack('${entry.key} يحتوي على أحرف غير صالحة.');
        return false;
      }
    }
    if (_gender == null) {
      _snack('اختر الجنس ليحدد النظام مسار فتح الحساب الصحيح.');
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

  Future<bool> _loadMuwathaqPolicy({bool showFailure = true}) async {
    final gender = _gender;
    if (gender == null) {
      if (showFailure) _snack('اختر الجنس أولاً ليعرض موثّق متطلبات فتح الحساب.');
      return false;
    }

    if (mounted) setState(() => _muwathaqPolicyLoading = true);
    try {
      final response = await Get.find<ApiClient>().getData(
        '/api/v1/auth/account-opening/contract?account_type=customer&gender=$gender',
      );
      final body = response.body;
      final data = body is Map && body['data'] is Map
          ? Map<String, dynamic>.from(body['data'] as Map)
          : null;
      if (response.statusCode != 200 || body is! Map || body['success'] != true || data == null) {
        if (showFailure) _snack(_responseMessage(response, 'تعذر تحميل سياسة موثّق.'));
        return false;
      }

      final requirements = data['review_requirements'] is Map
          ? Map<String, dynamic>.from(data['review_requirements'] as Map)
          : <String, dynamic>{};
      final labels = requirements.values
          .map((value) => value.toString())
          .where((value) => value.isNotEmpty)
          .join(' · ');
      if (mounted) {
        setState(() => _muwathaqPolicySummary = labels);
      }
      return true;
    } catch (_) {
      if (showFailure) _snack('تعذر الاتصال بموثّق. حاول لاحقاً.');
      return false;
    } finally {
      if (mounted) setState(() => _muwathaqPolicyLoading = false);
    }
  }

  Future<void> _requestEmailOtp() async {
    if (!_validateBasics() || _busy || _emailResendSeconds > 0) return;
    if (!await _loadMuwathaqPolicy()) return;
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
          'given_name': _givenName.text.trim(),
          'father_name': _fatherName.text.trim(),
          'grandfather_name': _grandfatherName.text.trim(),
          'family_name': _familyName.text.trim(),
          'gender': _gender,
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
      final applicationUlid = data['muwathaq_application_ulid']?.toString();
      final openingToken = data['account_opening_access_token']?.toString();
      if (applicationUlid == null || applicationUlid.length != 26 ||
          openingToken == null || openingToken.length < 32) {
        _snack('تم إنشاء الطلب لكن تعذر فتح جلسة إكمال موثّق. لا تسجل الدخول؛ أعد المحاولة من الدعم.');
        return;
      }
      _accountCreated = true;
      _openingApplicationUlid = applicationUlid;
      _openingAccessToken = openingToken;
      _verificationPath = data['verification_path']?.toString();
      _openingStatus = data['account_status']?.toString();
      _nextOpeningSteps = ['confirm_phone', 'submit_residence',
        if (_verificationPath == 'standard_customer') 'submit_identity_document'];
      await _persistOpeningProgress();
      _emailTimer?.cancel();
      if (!mounted) return;
      setState(() => _step = 2);
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
      name: _declaredLegalName,
      phone: '$_dialCode${_phone.text.trim()}',
      kind: 'customer',
    );
    try {
      await Get.find<AccessController>().load();
    } catch (_) {}
  }

  Future<void> _requestPhoneOtp({bool silentSuccess = false}) async {
    // لا نستخدم نقطة OTP القديمة: هذه الجلسة لا تملك Auth token، ولا يجوز
    // أن تمنحها إياه. في بيئة التجربة فقط يوضح موثّق رمز الاختبار.
    if (!silentSuccess) {
      _snack('وضع تجريبي فقط: أدخل الرمز 123456. عند تشغيل مزود الرسائل سيصل رمز حقيقي.', error: false);
    }
  }

  Future<void> _verifyPhoneOtp() async {
    if (_busy) return;
    if (_openingApplicationUlid == null || _openingAccessToken == null) {
      _snack('جلسة إكمال موثّق غير متاحة. لا يمكن متابعة الطلب بأمان.');
      return;
    }
    if (!RegExp(r'^\d{6}$').hasMatch(_phoneOtp.text.trim())) {
      _snack('أدخل رمز الهاتف المكوّن من 6 أرقام.');
      return;
    }

    setState(() => _busy = true);
    try {
      final response = await Get.find<ApiClient>().postData(
        '/api/v1/auth/account-opening/$_openingApplicationUlid/phone/confirm',
        {'code': _phoneOtp.text.trim()},
        headers: {'Authorization': 'Bearer $_openingAccessToken'},
      );
      if (response.statusCode != 200 || response.body is! Map ||
          response.body['success'] != true) {
        _snack(_responseMessage(response, 'رمز الهاتف غير صحيح أو انتهت جلسة الفتح.'));
        return;
      }
      if (!mounted) return;
      setState(() {
        _phoneVerified = true;
        _nextOpeningSteps = List<String>.from(_nextOpeningSteps)
          ..remove('confirm_phone');
        _step = 3;
      });
      _snack('تم تسجيل إثبات الهاتف. أكمل السكن وإثبات الإقامة.', error: false);
    } catch (_) {
      _snack('تعذر التحقق من رمز الهاتف.');
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _loadResidenceOptions() async {
    // Pending applicants do NOT have a wallet bearer token. The old
    // /me/kyc/residence endpoint requires an authenticated financial account,
    // so attempting it here always fails. Use safe opening-stage options.
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

    if (_birthGovernorate == null || _birthGovernorate!.isEmpty) {
      _snack('اختر محافظة الميلاد.');
      return;
    }
    if (!_residenceLocation.hasRequired) {
      _snack('اختر محافظة السكن الحالية والمديرية.');
      return;
    }
    if (_residenceArea.text.trim().length < 2) {
      _snack('اكتب اسم الحي أو المنطقة التي تسكن فيها.');
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

    final evidence = _evidenceOptions.firstWhere(
      (item) => item['code']?.toString() == _evidenceType,
      orElse: () => <String, dynamic>{'label': _evidenceType!},
    );

    final confirmed = await Get.to<bool>(
      () => CustomerVerificationReviewScreen(
        targetTier: 1,
        rows: [
          VerificationReviewRow('الاسم القانوني المصرّح به', _declaredLegalName),
          VerificationReviewRow('رقم الهاتف', '$_dialCode${_phone.text.trim()}'),
          VerificationReviewRow(
            'محافظة الميلاد',
            GovernoratePicker.nameOf(_birthGovernorate) ?? _birthGovernorate!,
          ),
          VerificationReviewRow(
            'محافظة السكن',
            GovernoratePicker.nameOf(_residenceLocation.governorateCode) ??
                _residenceLocation.governorateCode!,
          ),
          VerificationReviewRow(
            'المديرية',
            _residenceLocation.districtName ?? '—',
          ),
          VerificationReviewRow('الحي / المنطقة', _residenceArea.text.trim()),
          VerificationReviewRow(
            'أقرب معلم',
            _residenceLandmark.text.trim(),
          ),
          VerificationReviewRow(
            'نوع إثبات السكن',
            evidence['label']?.toString() ?? _evidenceType!,
          ),
        ],
        documents: [
          VerificationReviewDocument(
            label: 'إثبات محل السكن',
            path: _residenceEvidence!.path,
          ),
        ],
        onConfirm: _submitResidenceConfirmed,
      ),
    );

    if (confirmed == true && mounted) {
      setState(() {
        _residenceSubmitted = true;
        _nextOpeningSteps = List<String>.from(_nextOpeningSteps)
          ..remove('submit_residence');
        if (_requiresIdentity && !_nextOpeningSteps.contains('submit_identity_document')) {
          _nextOpeningSteps.add('submit_identity_document');
        }
        _step = _requiresIdentity ? 4 : 5;
      });
      _snack(
        _requiresIdentity
            ? 'تم إرسال إثبات السكن. ارفع وثيقة الهوية لاستكمال الطلب.'
            : 'تم إرسال السكن؛ مسار المرأة الأولي لا يشترط الهوية أو السيلفي.',
        error: false,
      );
    }
  }

  Future<bool> _submitResidenceConfirmed() async {
    if (_busy) return false;
    setState(() => _busy = true);

    try {
      if (_openingApplicationUlid == null || _openingAccessToken == null) {
        _snack('جلسة إكمال موثّق غير متاحة.');
        return false;
      }
      final response = await Get.find<ApiClient>().postMultipartData(
        '/api/v1/auth/account-opening/$_openingApplicationUlid/residence',
        {
          'governorate': _residenceLocation.governorateCode!,
          'district': _residenceLocation.districtName ?? '${_residenceLocation.districtId!}',
          'area': _residenceArea.text.trim(),
          if (_residenceLandmark.text.trim().isNotEmpty)
            'landmark': _residenceLandmark.text.trim(),
          'evidence_type': _evidenceType!,
        },
        [MultipartBody('evidence', File(_residenceEvidence!.path))],
        headers: {'Authorization': 'Bearer $_openingAccessToken'},
      );

      if (response.statusCode != 200 ||
          response.body is! Map ||
          response.body['success'] != true) {
        _snack(_responseMessage(response, 'تعذر إرسال إثبات السكن.'));
        return false;
      }
      return true;
    } catch (_) {
      _snack('تعذر إرسال إثبات السكن. تحقق من الاتصال وحاول مرة أخرى.');
      return false;
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  void _finish() {
    Get.back();
  }

  @override
  Widget build(BuildContext context) {
    // مصدر إنشاء الهوية والحساب هو موثّق. هذه الشاشة لا تجمع كلمة مرور أو
    // PIN ولا تستدعي أي endpoint ينشئ مستخدماً في أميال باي.
    return Scaffold(
      appBar: AppBar(title: const Text('فتح حساب')),
      body: Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            const AmialBrandLogo(height: 88),
            const SizedBox(height: 20),
            const Text('فتح الحساب يتم عبر موثّق', style: TextStyle(fontSize: 22, fontWeight: FontWeight.bold), textAlign: TextAlign.center),
            const SizedBox(height: 10),
            const Text('موثّق يستلم بيانات الطلب وأدلته، وبعد الاعتماد يهيّئ أميال باي المحفظة ويرسل لك طريقة دخول آمنة.', textAlign: TextAlign.center),
            const SizedBox(height: 24),
            FilledButton.icon(
              onPressed: () async {
                final ok = await launchUrl(Uri.parse('https://muwathaq.amialpay.com/open-account?type=customer'), mode: LaunchMode.externalApplication);
                if (!ok && context.mounted) _snack('تعذر فتح بوابة موثّق.');
              },
              icon: const Icon(Icons.verified_user_outlined),
              label: const Text('الانتقال إلى موثّق'),
            ),
          ]),
        ),
      ),
    );
    return Scaffold(
      backgroundColor: const Color(0xFFF7F8FB),
      body: SafeArea(
        child: Column(
          children: [
            _header(context),
            if (_step < 5) _progress(),
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
                    4 => _requiresIdentity ? _identityStep() : _successStep(),
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
                  'طلب فتح حساب في أميال باي',
                  style: Theme.of(context).textTheme.titleMedium?.copyWith(
                        fontWeight: FontWeight.w900,
                        color: AmialColors.textPrimary,
                      ),
                ),
                const SizedBox(height: 2),
                const Text(
                  'المتطلبات تختلف حسب مسار موثّق. لا محفظة قبل الاعتماد.',
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
    final labels = ['الحساب', 'البريد', 'الهاتف', 'السكن',
      if (_requiresIdentity) 'الهوية'];
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
        children: [
          ...children,
          if (_accountCreated && _step >= 2 && _step <= 4)
            TextButton.icon(
              onPressed: _busy ? null : _resumeExistingOpening,
              icon: const Icon(Icons.lock_reset_outlined),
              label: const Text('استعادة جلسة الاستكمال إذا انتهت صلاحيتها'),
            ),
        ],
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
    ValueChanged<String>? onChanged,
  }) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 13),
      child: TextField(
        controller: controller,
        keyboardType: keyboardType,
        obscureText: obscure,
        maxLength: maxLength,
        inputFormatters: formatters,
        onChanged: onChanged,
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
            'أنشئ كلمة مرور للدخول وPIN مالياً مستقلاً. يُحدد موثّق المتطلبات المناسبة لمسار حسابك قبل التفعيل.',
            Icons.account_balance_wallet_outlined,
          ),
          TextButton.icon(
            onPressed: _busy ? null : _resumeExistingOpening,
            icon: const Icon(Icons.assignment_return_outlined),
            label: const Text('لدي طلب سابق — استكمال التوثيق'),
          ),
          Container(
            width: double.infinity,
            margin: const EdgeInsets.only(bottom: 14),
            padding: const EdgeInsets.all(13),
            decoration: BoxDecoration(
              color: const Color(0xFFFFF8E6),
              borderRadius: BorderRadius.circular(14),
              border: Border.all(color: const Color(0xFFE9C46A)),
            ),
            child: const Text(
              'اكتب اسمك الحقيقي كما يظهر في وثائقك الرسمية. '
              'سيتم مطابقة هذا الاسم مع إثبات السكن ثم الهوية عند رفع مستوى التوثيق. '
              'الاسم المستعار أو اسم النشاط قد يوقف اعتماد التوثيق.',
              style: TextStyle(fontSize: 12.5, height: 1.55),
            ),
          ),
          Row(
            children: [
              Expanded(child: _field(_givenName, 'الاسم *', onChanged: (_) => setState(() {}))),
              const SizedBox(width: 10),
              Expanded(child: _field(_fatherName, 'اسم الأب *', onChanged: (_) => setState(() {}))),
            ],
          ),
          Row(
            children: [
              Expanded(child: _field(_grandfatherName, 'اسم الجد *', onChanged: (_) => setState(() {}))),
              const SizedBox(width: 10),
              Expanded(child: _field(_familyName, 'اللقب / اسم العائلة *', onChanged: (_) => setState(() {}))),
            ],
          ),
          if (_declaredLegalName.isNotEmpty)
            Container(
              width: double.infinity,
              margin: const EdgeInsets.only(bottom: 14),
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(
                color: const Color(0xFFF3F8FF),
                borderRadius: BorderRadius.circular(14),
              ),
              child: Text(
                'الاسم القانوني المصرّح به: $_declaredLegalName',
                style: const TextStyle(
                  fontSize: 12.5,
                  height: 1.5,
                  fontWeight: FontWeight.w700,
                ),
              ),
            ),
          DropdownButtonFormField<String>(
            value: _gender,
            decoration: InputDecoration(
              labelText: 'الجنس *',
              border: OutlineInputBorder(
                borderRadius: BorderRadius.circular(14),
              ),
            ),
            items: const [
              DropdownMenuItem(value: 'female', child: Text('أنثى')),
              DropdownMenuItem(value: 'male', child: Text('ذكر')),
            ],
            onChanged: (value) {
              setState(() {
                _gender = value;
                _muwathaqPolicySummary = null;
              });
              if (value != null) _loadMuwathaqPolicy(showFailure: false);
            },
          ),
          if (_muwathaqPolicyLoading)
            const Padding(
              padding: EdgeInsets.only(top: 10),
              child: LinearProgressIndicator(minHeight: 2),
            )
          else if (_muwathaqPolicySummary != null)
            Container(
              width: double.infinity,
              margin: const EdgeInsets.only(top: 10),
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(
                color: const Color(0xFFF0F8F4),
                borderRadius: BorderRadius.circular(14),
                border: Border.all(color: const Color(0xFFCDE7D7)),
              ),
              child: Text(
                'متطلبات موثّق بعد إرسال الطلب: $_muwathaqPolicySummary',
                style: const TextStyle(fontSize: 12.5, height: 1.55),
              ),
            ),
          const SizedBox(height: 12),
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
            'أرسلنا رمزاً من 6 أرقام إلى $_normalizedEmail. بعد نجاحه نرسل طلب فتح الحساب إلى موثّق.',
            Icons.mark_email_read_outlined,
          ),
          _field(
            _emailOtp,
            'رمز البريد',
            keyboardType: TextInputType.number,
            maxLength: 6,
            formatters: [FilteringTextInputFormatter.digitsOnly],
          ),
          _primaryButton('تحقق وأرسل طلب فتح الحساب', _verifyEmailAndCreate),
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
            maxLength: 6,
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
            onPressed: _busy ? null : () => setState(() => _step = 5),
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
          'بيانات الميلاد والسكن الحالي',
          'يمكن لأي عميل التسجيل والتوثيق مهما كانت محافظته. نطاق التشغيل يحدد توفر الخدمات المالية فقط ولا يرفض إنشاء الحساب.',
          Icons.home_work_outlined,
        ),
        Container(
          width: double.infinity,
          margin: const EdgeInsets.only(bottom: 14),
          padding: const EdgeInsets.all(13),
          decoration: BoxDecoration(
            color: const Color(0xFFF3F8FF),
            borderRadius: BorderRadius.circular(14),
            border: Border.all(color: const Color(0xFFD9E7FA)),
          ),
          child: Text(
            'الاسم الذي سيُقارن بإثبات السكن: $_declaredLegalName\n'
            'تأكد أن إثبات السكن يحمل اسمك الحقيقي. الاختلاف الجوهري سيوقف اعتماد التوثيق الجزئي للمراجعة.',
            style: const TextStyle(fontSize: 12.5, height: 1.55),
          ),
        ),
        GovernoratePicker(
          label: 'محافظة الميلاد',
          value: _birthGovernorate,
          helper: 'بيان تعريفي فقط ولا يؤثر على نطاق تشغيل أميال.',
          onChanged: (v) => setState(() => _birthGovernorate = v),
        ),
        const SizedBox(height: 10),
        YemenResidencePicker(
          onChanged: (value) => setState(() => _residenceLocation = value),
        ),
        const SizedBox(height: 10),
        _field(_residenceArea, 'اسم الحي / المنطقة *'),
        _field(_residenceLandmark, 'أقرب معلم — اختياري'),
        const SizedBox(height: 2),
        Container(
          padding: const EdgeInsets.all(13),
          decoration: BoxDecoration(
            color: const Color(0xFFF3F8FF),
            borderRadius: BorderRadius.circular(14),
          ),
          child: const Text(
            'بعد اعتماد السكن تصبح حالة الحساب «عميل موثق جزئيا». '
            'إذا كانت محافظة السكن خارج نطاق التشغيل الحالي، يبقى الحساب مسجلاً وموثقاً لكن الخدمات المالية المقيدة بالتغطية لن تعمل حتى تتوسع الخدمة.',
            style: TextStyle(fontSize: 12.5, height: 1.55),
          ),
        ),
        const SizedBox(height: 14),
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
        _primaryButton('إرسال السكن للمراجعة', _submitResidence),
        const SizedBox(height: 8),
        TextButton(
          onPressed: _busy ? null : () => setState(() => _step = 5),
          child: const Text('سأرفع إثبات السكن لاحقاً'),
        ),
      ]),
    ]);
  }

  Future<void> _pickIdentityEvidence(ImageSource source) async {
    try {
      final selected = await ImagePicker().pickImage(
        source: source, imageQuality: 88, maxWidth: 2200,
      );
      if (selected != null && mounted) {
        setState(() => _identityEvidence = selected);
      }
    } catch (_) {
      _snack('تعذر اختيار وثيقة الهوية.');
    }
  }

  Future<void> _submitIdentityEvidence() async {
    if (_busy) return;
    if (!_requiresIdentity) {
      _snack('وثيقة الهوية ليست مطلوبة في المستوى الأول لمسار المرأة.');
      return;
    }
    if (_openingApplicationUlid == null || _openingAccessToken == null) {
      _snack('جلسة موثّق غير متاحة. استعد طلبك أولاً.');
      return;
    }
    if (_identityEvidence == null) {
      _snack('أرفق صورة واضحة لوثيقة الهوية.');
      return;
    }
    setState(() => _busy = true);
    try {
      final response = await Get.find<ApiClient>().postMultipartData(
        '/api/v1/auth/account-opening/$_openingApplicationUlid/evidence/identity_document',
        <String, String>{},
        [MultipartBody('evidence', File(_identityEvidence!.path))],
        headers: {'Authorization': 'Bearer $_openingAccessToken'},
      );
      if (response.statusCode != 200 || response.body is! Map
          || response.body['success'] != true) {
        _snack(_responseMessage(response, 'لم تنجح عملية رفع الهوية.'));
        return;
      }
      _identityEvidence = null;
      if (!mounted) return;
      setState(() {
        _nextOpeningSteps = List<String>.from(_nextOpeningSteps)
          ..remove('submit_identity_document');
        _step = 5;
      });
      _snack('حُفظت وثيقة الهوية وأُرسلت للمراجعة. لم تُفعّل المحفظة بعد.',
        error: false);
    } catch (_) {
      _snack('تعذر إرسال الهوية. تحقق من الاتصال وحاول ثانية.');
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Widget _identityStep() => _page(children: [
    _card([
      _title('وثيقة هوية صاحب الحساب',
        'مسار العميل الذكر يتطلب رفع وثيقة هوية بعد الهاتف والسكن. يراجعها موثّق قبل الاعتماد.',
        Icons.badge_outlined),
      const SizedBox(height: 12),
      if (_identityEvidence != null)
        const Text('تم اختيار الوثيقة. راجع وضوحها قبل الإرسال.'),
      Row(children: [
        Expanded(child: OutlinedButton.icon(
          onPressed: _busy ? null : () => _pickIdentityEvidence(ImageSource.camera),
          icon: const Icon(Icons.camera_alt_outlined),
          label: const Text('التقاط الهوية'))),
        const SizedBox(width: 8),
        Expanded(child: OutlinedButton.icon(
          onPressed: _busy ? null : () => _pickIdentityEvidence(ImageSource.gallery),
          icon: const Icon(Icons.photo_library_outlined),
          label: const Text('من المعرض'))),
      ]),
      const SizedBox(height: 16),
      _primaryButton('إرسال الهوية إلى موثّق', _submitIdentityEvidence),
      TextButton(onPressed: _busy ? null : () => setState(() => _step = 5),
        child: const Text('سأرفع الهوية لاحقاً')),
    ]),
  ]);

  Widget _successStep() {
    final pendingPhone = _nextOpeningSteps.contains('confirm_phone') || !_phoneVerified;
    final pendingResidence = _nextOpeningSteps.contains('submit_residence') || !_residenceSubmitted;
    final pendingIdentity = _requiresIdentity && _nextOpeningSteps.contains('submit_identity_document');
    final message = _openingStatus == 'needs_more_info'
        ? 'طلب موثّق استكمال أدلة. اقرأ سبب الطلب أدناه ثم حدّث الملف المناسب.'
        : _openingStatus == 'approved'
        ? 'تم اعتماد طلب فتح الحساب. يمكنك تسجيل الدخول الآن.'
        : _openingStatus == 'awaiting_admin_approval'
          ? 'وافق موثّق على الأدلة. بانتظار قرار الإدارة النهائي قبل فتح المحفظة.'
          : _openingStatus == 'rejected'
            ? 'تم رفض فتح الحساب. راجع السبب وتواصل مع الدعم.'
            : (pendingPhone || pendingResidence || pendingIdentity)
              ? 'طلبك محفوظ لكنه غير مكتمل. استخدم الأزرار أدناه لاستكمال الأدلة المطلوبة.'
              : 'وصلت أدلة فتح الحساب للمراجعة. لن تُنشأ محفظة قبل موافقة موثّق والإدارة.';

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
          'طلب فتح الحساب قيد المراجعة',
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
                  'اختيار الجنس يحدد المسار تلقائياً: المسار المتدرج مخصص للحسابات النسائية، والحساب القياسي للذكور. لا تختار مستوى توثيق بنفسك.',
                  style: const TextStyle(fontSize: 13, height: 1.5),
                ),
              ),
            ],
          ),
        ),
        const SizedBox(height: 18),
        if (_decisionReason != null && _decisionReason!.isNotEmpty)
          Text(_decisionReason!, style: const TextStyle(color: AmialColors.red)),
        if (pendingPhone)
          _primaryButton('استكمال إثبات الهاتف', () => setState(() => _step = 2)),
        if (pendingResidence)
          _primaryButton('استكمال إثبات السكن', () => setState(() => _step = 3)),
        if (pendingIdentity && !pendingPhone && !pendingResidence)
          _primaryButton('رفع وثيقة هوية الذكر', () => setState(() => _step = 4)),
        if (_openingStatus == 'needs_more_info') ...[
          _primaryButton('إعادة مراجعة إثبات الهاتف', () => setState(() => _step = 2)),
          _primaryButton('استكمال بيانات السكن', () => setState(() => _step = 3)),
          if (_requiresIdentity)
            _primaryButton('تصحيح وثيقة الهوية', () => setState(() => _step = 4)),
        ],
        _primaryButton('تحديث حالة الطلب', _refreshOpeningStatus),
        TextButton(onPressed: _busy ? null : _resumeExistingOpening,
          child: const Text('انتهت صلاحية الجلسة؟ استعد طلبك')),
        _primaryButton('العودة لتسجيل الدخول', _finish),
      ]),
    ]);
  }
}