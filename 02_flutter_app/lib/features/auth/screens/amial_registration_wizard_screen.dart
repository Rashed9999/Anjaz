import 'dart:async';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:amial_pay/features/access/domain/vertical_catalog.dart';
import 'package:get/get.dart';
import 'package:image_picker/image_picker.dart';
import 'package:geolocator/geolocator.dart';
import 'package:amial_pay/features/auth/widgets/governorate_picker.dart';
import 'package:amial_pay/data/api/api_client.dart';
import 'package:amial_pay/theme/amial_colors.dart';
import 'package:amial_pay/features/auth/widgets/signature_pad_widget.dart';
import 'package:amial_pay/features/auth/screens/unified_login_screen.dart';
import 'package:amial_pay/common/widgets/amial_button.dart';

/// AMIAL-REG-WIZARD-001 + AMIAL-EMAIL-OTP-REG-001
///
/// معالج فتح الحساب الموحّد للعميل/التاجر/الوكيل. في المرحلة التجريبية
/// يثبت البريد عبر OTP آمن قبل إنشاء الحساب، بينما يبقى SMS في الخادم
/// متاحاً لإعادة التفعيل لاحقاً من الإعدادات.
class AmialRegistrationWizardScreen extends StatefulWidget {
  const AmialRegistrationWizardScreen({super.key});

  @override
  State<AmialRegistrationWizardScreen> createState() =>
      _AmialRegistrationWizardScreenState();
}

class _AmialRegistrationWizardScreenState
    extends State<AmialRegistrationWizardScreen> {
  final PageController _page = PageController();
  int _step = 0;
  bool _submitting = false;
  bool _otpSent = false;
  String? _emailChallengeId;
  String? _emailVerificationToken;
  int _otpResendSeconds = 0;
  Timer? _otpTimer;

  static const int _lastInputStep = 9;
  static const int _successStep = 10;

  final _name1 = TextEditingController();
  final _name2 = TextEditingController();
  final _name3 = TextEditingController();
  final _name4 = TextEditingController();
  final _dob = TextEditingController();
  final _email = TextEditingController();
  final _occupation = TextEditingController();
  String _gender = 'male';
  String _accountType = 'customer';
  String _businessType = 'retail';

  List<VerticalOption> _verticals = VerticalCatalog.builtIn.values.toList();
  final _storeName = TextEditingController();
  String? _agentNumber;
  String? _merchantNumber;
  final _phone = TextEditingController();

  String _dialCode = '+967';
  static const List<({String code, String label})> _dialCodes = [
    (code: '+967', label: '🇾🇪 اليمن'),
    (code: '+966', label: '🇸🇦 السعوديّة'),
    (code: '+971', label: '🇦🇪 الإمارات'),
    (code: '+968', label: '🇴🇲 عُمان'),
    (code: '+974', label: '🇶🇦 قطر'),
    (code: '+965', label: '🇰🇼 الكويت'),
    (code: '+973', label: '🇧🇭 البحرين'),
    (code: '+962', label: '🇯🇴 الأردنّ'),
    (code: '+20', label: '🇪🇬 مصر'),
    (code: '+253', label: '🇩🇯 جيبوتي'),
    (code: '+252', label: '🇸🇴 الصومال'),
    (code: '+249', label: '🇸🇩 السودان'),
    (code: '+90', label: '🇹🇷 تركيا'),
    (code: '+44', label: '🇬🇧 بريطانيا'),
    (code: '+1', label: '🇺🇸 أمريكا/كندا'),
    (code: '+49', label: '🇩🇪 ألمانيا'),
    (code: '+60', label: '🇲🇾 ماليزيا'),
  ];

  final _idNumber = TextEditingController();
  String _idType = 'nid';
  final _idIssue = TextEditingController();
  final _idExpiry = TextEditingController();
  String? _originGov;
  String? _residenceGov;

  final _addrDir = TextEditingController();
  final _addrArea = TextEditingController();
  final _addrStreet = TextEditingController();
  final _addrLandmark = TextEditingController();

  XFile? _docIdFront;
  XFile? _docIdBack;
  XFile? _docSelfie;
  XFile? _docAddress;

  Map<String, XFile> get _typedDocs => {
        if (_docIdFront != null) 'kyc_id_front': _docIdFront!,
        if (_docIdBack != null) 'kyc_id_back': _docIdBack!,
        if (_docSelfie != null) 'kyc_selfie': _docSelfie!,
        if (_docAddress != null) 'kyc_address_proof': _docAddress!,
      };

  final _nameEn = TextEditingController();
  final _countryOfBirth = TextEditingController(text: 'اليمن');
  final _idPlaceOfIssue = TextEditingController();
  final _employerName = TextEditingController();
  final _jobTitle = TextEditingController();
  final _workAddress = TextEditingController();
  final _monthlyIncome = TextEditingController();
  final _pepPosition = TextEditingController();
  final _kin2Name = TextEditingController();
  final _kin2Phone = TextEditingController();
  final _kin2Relation = TextEditingController();

  String _maritalStatus = 'single';
  String _housingType = 'owned';
  String _incomeSource = 'salary';
  String _accountPurpose = 'savings';
  bool? _isPep;

  final _kinName = TextEditingController();
  final _kinPhone = TextEditingController();
  final _kinRelation = TextEditingController();

  final GlobalKey<SignaturePadState> _sigKey1 = GlobalKey<SignaturePadState>();
  final GlobalKey<SignaturePadState> _sigKey2 = GlobalKey<SignaturePadState>();
  final GlobalKey<SignaturePadState> _sigKey3 = GlobalKey<SignaturePadState>();

  bool _agreeTerms = false;
  bool _agreePolicy = false;
  bool _declareAccuracy = false;

  final _pin = TextEditingController();
  final _pinConfirm = TextEditingController();
  final _otp = TextEditingController();

  bool _locating = false;
  String? _locationNotice;
  bool? _inServiceArea;

  @override
  void initState() {
    super.initState();
    VerticalCatalog.load().then((list) {
      if (!mounted) return;
      setState(() => _verticals = list);
    });
  }

  @override
  void dispose() {
    _otpTimer?.cancel();
    _page.dispose();
    for (final c in [
      _name1, _name2, _name3, _name4, _dob, _email, _occupation, _phone,
      _idNumber, _idIssue, _idExpiry,
      _addrDir, _addrArea, _addrStreet, _addrLandmark,
      _nameEn, _countryOfBirth, _idPlaceOfIssue, _employerName, _jobTitle,
      _workAddress, _monthlyIncome, _pepPosition,
      _kin2Name, _kin2Phone, _kin2Relation,
      _kinName, _kinPhone, _kinRelation, _pin, _pinConfirm, _otp, _storeName,
    ]) {
      c.dispose();
    }
    super.dispose();
  }

  String _govName(String? code) => GovernoratePicker.nameOf(code) ?? '';

  String get _normalizedEmail => _email.text.trim().toLowerCase();

  bool get _emailValid =>
      RegExp(r'^[^@\s]+@[^@\s]+\.[^@\s]+$').hasMatch(_normalizedEmail);

  void _snack(String msg, {bool error = true}) {
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(msg),
        backgroundColor: error ? AmialColors.red : AmialColors.primary,
      ),
    );
  }

  String _responseMessage(Response r, String fallback) {
    try {
      if (r.body is Map && r.body['message'] != null) {
        return '${r.body['message']}';
      }
      if (r.body is Map && r.body['errors'] is List && (r.body['errors'] as List).isNotEmpty) {
        final first = (r.body['errors'] as List).first;
        if (first is Map && first['message'] != null) return '${first['message']}';
      }
      if (r.body is Map && r.body['errors'] is Map) {
        final values = (r.body['errors'] as Map).values;
        if (values.isNotEmpty) return '${values.first}';
      }
    } catch (_) {}
    return fallback;
  }

  void _startOtpCountdown(int seconds) {
    _otpTimer?.cancel();
    if (mounted) setState(() => _otpResendSeconds = seconds);
    _otpTimer = Timer.periodic(const Duration(seconds: 1), (timer) {
      if (!mounted) {
        timer.cancel();
        return;
      }
      if (_otpResendSeconds <= 1) {
        timer.cancel();
        setState(() => _otpResendSeconds = 0);
      } else {
        setState(() => _otpResendSeconds--);
      }
    });
  }

  void _invalidateEmailOtp() {
    _otpTimer?.cancel();
    _otpSent = false;
    _emailChallengeId = null;
    _emailVerificationToken = null;
    _otpResendSeconds = 0;
    _otp.clear();
  }

  Future<void> _pickDate(TextEditingController c, {bool future = false}) async {
    final now = DateTime.now();
    final first = future ? now : DateTime(1930);
    final last = future ? DateTime(2060) : now;
    final initial = future ? now : DateTime(2000);
    final picked = await showDatePicker(
      context: context,
      initialDate: initial,
      firstDate: first,
      lastDate: last,
    );
    if (picked != null) {
      final m = picked.month.toString().padLeft(2, '0');
      final d = picked.day.toString().padLeft(2, '0');
      setState(() => c.text = '${picked.year}-$m-$d');
    }
  }

  bool _validateStep(int s) {
    switch (s) {
      case 0:
        if (_accountType == 'merchant' && _storeName.text.trim().isEmpty) {
          _snack('أدخل اسم المتجر');
          return false;
        }
        if (_name1.text.trim().isEmpty || _name2.text.trim().isEmpty ||
            _name3.text.trim().isEmpty || _name4.text.trim().isEmpty) {
          _snack('أدخل الاسم الرباعي كاملاً');
          return false;
        }
        if (_dob.text.trim().isEmpty) {
          _snack('اختر تاريخ الميلاد');
          return false;
        }
        if (!_emailValid) {
          _snack('أدخل بريداً إلكترونياً صحيحاً — سيصل إليه رمز التحقق والاستعادة');
          return false;
        }
        if (_phone.text.trim().length < 6) {
          _snack('أدخل رقم هاتف صحيح');
          return false;
        }
        return true;
      case 1:
        if (_idNumber.text.trim().isEmpty) {
          _snack('أدخل رقم الهوية');
          return false;
        }
        return true;
      case 2:
        if (_residenceGov == null) {
          _snack('اختر محافظة السكن');
          return false;
        }
        if (_addrDir.text.trim().isEmpty || _addrArea.text.trim().isEmpty) {
          _snack('أدخل المديرية والحي على الأقل');
          return false;
        }
        return true;
      case 3:
        if (_isPep == null) {
          _snack('أجب عن سؤال المنصب السياسيّ — لا يُترك بلا جواب');
          return false;
        }
        if (_isPep == true && _pepPosition.text.trim().isEmpty) {
          _snack('اذكر المنصب صراحةً — «نعم» وحدها لا تُحقَّق');
          return false;
        }
        return true;
      case 4:
        if (_docIdFront == null || _docIdBack == null || _docSelfie == null) {
          _snack('أرفق وجهَ الهوية وظهرَها وصورةً شخصية');
          return false;
        }
        return true;
      case 5:
        if (_kinName.text.trim().isEmpty || _kinPhone.text.trim().isEmpty) {
          _snack('أدخل بيانات الشخص القريب');
          return false;
        }
        return true;
      case 6:
        if ((_sigKey1.currentState?.isEmpty ?? true) ||
            (_sigKey2.currentState?.isEmpty ?? true) ||
            (_sigKey3.currentState?.isEmpty ?? true)) {
          _snack('الرجاء التوقيع في المربّعات الثلاثة');
          return false;
        }
        return true;
      case 7:
        if (!_agreeTerms || !_agreePolicy || !_declareAccuracy) {
          _snack('الرجاء الموافقة على جميع الإقرارات');
          return false;
        }
        return true;
      case 8:
        if (_pin.text.length != 4 || !RegExp(r'^\d{4}$').hasMatch(_pin.text)) {
          _snack('كلمة المرور يجب أن تكون 4 أرقام');
          return false;
        }
        if (_pin.text != _pinConfirm.text) {
          _snack('كلمتا المرور غير متطابقتين');
          return false;
        }
        return true;
      case 9:
        if (_emailChallengeId == null) {
          _snack('اطلب رمز تحقق جديداً للبريد الإلكتروني');
          return false;
        }
        if (!RegExp(r'^\d{6}$').hasMatch(_otp.text.trim())) {
          _snack('أدخل رمز التحقق المكوّن من 6 أرقام');
          return false;
        }
        return true;
      default:
        return true;
    }
  }

  Future<void> _next() async {
    if (_submitting) return;
    if (!_validateStep(_step)) return;

    if (_step == 8 && !_otpSent) {
      final sent = await _sendOtp();
      if (!sent) return;
    }

    if (_step == _lastInputStep) {
      await _submit();
      return;
    }
    setState(() => _step++);
    _page.animateToPage(_step,
        duration: const Duration(milliseconds: 250), curve: Curves.easeInOut);
  }

  void _back() {
    if (_step == 0) {
      Get.back();
      return;
    }
    setState(() => _step--);
    _page.animateToPage(_step,
        duration: const Duration(milliseconds: 250), curve: Curves.easeInOut);
  }

  Future<void> _detectLocation() async {
    setState(() {
      _locating = true;
      _locationNotice = null;
      _inServiceArea = null;
    });

    try {
      if (!await Geolocator.isLocationServiceEnabled()) {
        setState(() => _locationNotice =
            'خدمة الموقع (GPS) مُطفأة في جهازك. شغّلها ثم أعد المحاولة، أو أدخل عنوانك يدوياً.');
        return;
      }

      var permission = await Geolocator.checkPermission();
      if (permission == LocationPermission.denied) {
        permission = await Geolocator.requestPermission();
      }
      if (permission == LocationPermission.deniedForever) {
        setState(() => _locationNotice =
            'إذن الموقع مرفوض نهائياً. يمكنك السماح به من إعدادات التطبيق، أو إدخال عنوانك يدوياً.');
        return;
      }
      if (permission == LocationPermission.denied) {
        setState(() => _locationNotice = 'لم تسمح بالوصول إلى الموقع. أدخل عنوانك يدوياً.');
        return;
      }

      final position = await Geolocator.getCurrentPosition(
        locationSettings: const LocationSettings(
          accuracy: LocationAccuracy.medium,
          timeLimit: Duration(seconds: 20),
        ),
      );

      final r = await Get.find<ApiClient>().postData(
        '/api/v1/amial/geo/resolve-zone',
        {'latitude': position.latitude, 'longitude': position.longitude},
      );

      final data = (r.body is Map) ? r.body['data'] : null;
      if (data is! Map) {
        setState(() => _locationNotice =
            'تعذّر تحديد المحافظة من موقعك. أدخل عنوانك يدوياً.');
        return;
      }

      setState(() {
        _inServiceArea = data['in_service_area'] == true;
        _locationNotice = (data['notice'] as String?) ?? 'تم تحديد موقعك.';
        final code = data['governorate_code'];
        if (code is String && code.isNotEmpty) _residenceGov = code;
      });
    } catch (_) {
      setState(() => _locationNotice = 'تعذّر تحديد موقعك الآن. أدخل عنوانك يدوياً.');
    } finally {
      if (mounted) setState(() => _locating = false);
    }
  }

  Future<bool> _sendOtp() async {
    if (!_emailValid) {
      _snack('أدخل بريداً إلكترونياً صحيحاً أولاً');
      return false;
    }
    if (_otpResendSeconds > 0) return false;

    setState(() => _submitting = true);
    try {
      final r = await Get.find<ApiClient>().postData(
        '/api/v1/auth/email-otp/request',
        {'email': _normalizedEmail, 'purpose': 'registration'},
      );
      final status = r.statusCode ?? 500;
      if (status < 200 || status >= 300) {
        _snack(_responseMessage(r, 'تعذر إرسال رمز التحقق إلى البريد'));
        return false;
      }

      final meta = r.body is Map && r.body['meta'] is Map
          ? Map<String, dynamic>.from(r.body['meta'])
          : <String, dynamic>{};
      final challenge = meta['challenge_id']?.toString();
      if (challenge == null || challenge.length != 26) {
        _snack('لم يُنشئ الخادم جلسة تحقق صالحة. حاول مرة أخرى.');
        return false;
      }

      _emailChallengeId = challenge;
      _emailVerificationToken = null;
      _otp.clear();
      _otpSent = true;
      final resend = int.tryParse('${meta['resend_after_seconds'] ?? 60}') ?? 60;
      _startOtpCountdown(resend);
      _snack('تم إرسال رمز من 6 أرقام إلى $_normalizedEmail. صلاحيته 5 دقائق.', error: false);
      return true;
    } catch (_) {
      _snack('تعذر الاتصال بالخادم لإرسال رمز التحقق');
      return false;
    } finally {
      if (mounted) setState(() => _submitting = false);
    }
  }

  Future<bool> _verifyRegistrationOtp() async {
    if (_emailVerificationToken != null) return true;
    final challenge = _emailChallengeId;
    if (challenge == null || !_emailValid) return false;

    try {
      final r = await Get.find<ApiClient>().postData(
        '/api/v1/auth/email-otp/verify',
        {
          'challenge_id': challenge,
          'email': _normalizedEmail,
          'purpose': 'registration',
          'otp': _otp.text.trim(),
        },
      );
      final status = r.statusCode ?? 500;
      if (status < 200 || status >= 300) {
        _snack(_responseMessage(r, 'رمز التحقق غير صحيح أو انتهت صلاحيته'));
        return false;
      }

      final meta = r.body is Map && r.body['meta'] is Map
          ? Map<String, dynamic>.from(r.body['meta'])
          : <String, dynamic>{};
      final token = meta['verification_token']?.toString();
      final returnedChallenge = meta['challenge_id']?.toString();
      if (token == null || token.length < 32) {
        _snack('تعذر إنشاء جلسة التحقق. اطلب رمزاً جديداً.');
        return false;
      }
      _emailVerificationToken = token;
      if (returnedChallenge != null && returnedChallenge.length == 26) {
        _emailChallengeId = returnedChallenge;
      }
      return true;
    } catch (_) {
      _snack('تعذر الاتصال بالخادم للتحقق من الرمز');
      return false;
    }
  }

  Future<XFile?> _pickOne() async {
    try {
      return await ImagePicker().pickImage(source: ImageSource.gallery);
    } catch (_) {
      _snack('تعذّر اختيار الصورة');
      return null;
    }
  }

  Future<void> _submit() async {
    setState(() => _submitting = true);
    try {
      if (!await _verifyRegistrationOtp()) return;
      if (_emailChallengeId == null || _emailVerificationToken == null) {
        _snack('تحقق من البريد الإلكتروني أولاً');
        return;
      }

      final signature = await _sigKey1.currentState?.exportBase64Png();
      final lastName = [_name2.text, _name3.text, _name4.text]
          .map((s) => s.trim())
          .where((s) => s.isNotEmpty)
          .join(' ');
      final address = [
        _govName(_residenceGov), _addrDir.text, _addrArea.text,
        _addrStreet.text, _addrLandmark.text,
      ].map((s) => s.trim()).where((s) => s.isNotEmpty).join('، ');

      final fields = <String, String>{
        'f_name': _name1.text.trim(),
        'l_name': lastName,
        'father_name': _name2.text.trim(),
        'grandfather_name': _name3.text.trim(),
        'name_en': _nameEn.text.trim(),
        'country_of_birth': _countryOfBirth.text.trim(),
        'id_place_of_issue': _idPlaceOfIssue.text.trim(),
        'marital_status': _maritalStatus,
        'residence_district': _addrDir.text.trim(),
        'residence_area': _addrArea.text.trim(),
        'residence_landmark': _addrLandmark.text.trim(),
        'housing_type': _housingType,
        'employer_name': _employerName.text.trim(),
        'job_title': _jobTitle.text.trim(),
        'work_address': _workAddress.text.trim(),
        'income_source': _incomeSource,
        'account_purpose': _accountPurpose,
        'monthly_income': _monthlyIncome.text.trim(),
        'monthly_income_currency': 'YER',
        'kin2_name': _kin2Name.text.trim(),
        'kin2_phone': _kin2Phone.text.trim(),
        'kin2_relation': _kin2Relation.text.trim(),
        if (_isPep != null) 'is_pep': _isPep! ? '1' : '0',
        if (_isPep == true) 'pep_position': _pepPosition.text.trim(),
        'gender': _gender,
        'occupation': _occupation.text.trim(),
        'dial_country_code': _dialCode,
        'phone': _phone.text.trim(),
        'email': _normalizedEmail,
        'email_challenge_id': _emailChallengeId!,
        'email_verification_token': _emailVerificationToken!,
        'password': _pin.text,
        'otp': _otp.text.trim(),
        'date_of_birth': _dob.text.trim(),
        'identification_number': _idNumber.text.trim(),
        'identification_type': _idType,
        'identification_issue_date': _idIssue.text.trim(),
        'identification_expiry_date': _idExpiry.text.trim(),
        'address': address,
        if (_originGov != null) 'origin_governorate': _originGov!,
        if (_residenceGov != null) 'residence_governorate': _residenceGov!,
        'kin_name': _kinName.text.trim(),
        'kin_phone': _kinPhone.text.trim(),
        'kin_relation': _kinRelation.text.trim(),
        'declaration_accepted': '1',
        'account_type': _accountType,
        if (_accountType == 'merchant') 'store_name': _storeName.text.trim(),
        if (_accountType == 'merchant') 'business_type': _businessType,
        if (signature != null) 'signature': signature,
      };

      final parts = <MultipartBody>[
        for (final e in _typedDocs.entries) MultipartBody(e.key, File(e.value.path)),
        for (final x in _typedDocs.values)
          MultipartBody('identification_image[]', File(x.path)),
      ];

      final r = await Get.find<ApiClient>().postMultipartData(
        '/api/v1/auth/register/email', fields, parts,
      );

      final ok = r.statusCode == 200 &&
          (r.body is Map) &&
          ('${r.body['message'] ?? ''}').toLowerCase().contains('success');
      if (ok) {
        _otpTimer?.cancel();
        setState(() {
          _agentNumber = (r.body is Map) ? r.body['agent_number']?.toString() : null;
          _merchantNumber = (r.body is Map) ? r.body['merchant_number']?.toString() : null;
          _step = _successStep;
          _submitting = false;
        });
        _page.animateToPage(_successStep,
            duration: const Duration(milliseconds: 250), curve: Curves.easeInOut);
        return;
      }
      _snack(_responseMessage(r, 'تعذّر إكمال التسجيل'));
    } catch (_) {
      _snack('خطأ في الاتصال');
    } finally {
      if (mounted) setState(() => _submitting = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    const titles = [
      'المعلومات الشخصية', 'معلومات الهوية', 'العنوان', 'العمل ومصدر الدخل',
      'وثائق الهوية', 'شخص قريب', 'التوقيع الإلكتروني', 'الإقرارات',
      'كلمة المرور', 'رمز التحقق',
    ];
    return Scaffold(
      backgroundColor: Colors.white,
      body: SafeArea(
        child: Column(
        children: [
          if (_step < _successStep) ...[
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 8, 16, 4),
              child: Row(
                children: [
                  InkWell(
                    onTap: _back,
                    borderRadius: BorderRadius.circular(12),
                    child: Container(
                      width: 38, height: 38,
                      decoration: BoxDecoration(
                        color: AmialColors.background,
                        borderRadius: BorderRadius.circular(12),
                      ),
                      child: const Icon(Icons.arrow_forward,
                          size: 19, color: Color(0xFF1A2433)),
                    ),
                  ),
                  const Spacer(),
                  Text('الخطوة ${_step + 1} من ${titles.length}',
                      style: const TextStyle(
                          fontSize: 12,
                          fontWeight: FontWeight.w600,
                          color: AmialColors.textMuted)),
                ],
              ),
            ),
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
              child: Row(
                children: List.generate(titles.length, (i) {
                  final done = i <= _step;
                  return Expanded(
                    child: Container(
                      height: 4,
                      margin: EdgeInsets.only(left: i == titles.length - 1 ? 0 : 4),
                      decoration: BoxDecoration(
                        color: done ? AmialColors.primary : const Color(0xFFE6E9EF),
                        borderRadius: BorderRadius.circular(4),
                      ),
                    ),
                  );
                }),
              ),
            ),
            Padding(
              padding: const EdgeInsets.fromLTRB(20, 6, 20, 12),
              child: Align(
                alignment: Alignment.centerRight,
                child: Text(
                  titles[_step],
                  style: const TextStyle(
                      fontSize: 23,
                      fontWeight: FontWeight.bold,
                      color: Color(0xFF1A2433)),
                ),
              ),
            ),
          ],
          Expanded(
            child: PageView(
              controller: _page,
              physics: const NeverScrollableScrollPhysics(),
              children: [
                _stepPersonal(),
                _stepIdentity(),
                _stepAddress(),
                _stepWork(),
                _stepDocuments(),
                _stepKin(),
                _stepSignature(),
                _stepDeclarations(),
                _stepPin(),
                _stepOtp(),
                _stepSuccess(),
              ],
            ),
          ),
          if (_step < _successStep) _bottomBar(),
        ],
        ),
      ),
    );
  }

  Widget _bottomBar() {
    return Container(
      decoration: const BoxDecoration(
        color: Colors.white,
        border: Border(top: BorderSide(color: Color(0xFFEEF1F5))),
      ),
      child: SafeArea(
        top: false,
        child: Padding(
          padding: const EdgeInsets.fromLTRB(16, 12, 16, 12),
          child: Row(
            children: [
              Expanded(
                child: AmialButton(
                  label: _step == 0 ? 'إلغاء' : 'السابق',
                  kind: AmialButtonKind.outline,
                  onPressed: _submitting ? null : _back,
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                flex: 2,
                child: AmialButton(
                  label: _step == _lastInputStep ? 'إنشاء الحساب' : 'التالي',
                  loading: _submitting,
                  onPressed: _submitting ? null : _next,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _wrap(List<Widget> children) => SingleChildScrollView(
        padding: const EdgeInsets.all(20),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: children),
      );

  Widget _field(TextEditingController c, String label,
      {TextInputType? type, int maxLines = 1, int? maxLength,
      ValueChanged<String>? onChanged}) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 14),
      child: TextField(
        controller: c,
        keyboardType: type,
        maxLines: maxLines,
        maxLength: maxLength,
        onChanged: onChanged,
        decoration: InputDecoration(
          labelText: label,
          border: const OutlineInputBorder(),
          counterText: '',
        ),
      ),
    );
  }

  Widget _sectionNote(String t) => Padding(
        padding: const EdgeInsets.only(bottom: 16),
        child: Text(t, style: const TextStyle(color: Color(0xFF5F6B7C), fontSize: 13)),
      );

  Widget _dateField(TextEditingController c, String label, {bool future = false}) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 14),
      child: TextField(
        controller: c,
        readOnly: true,
        onTap: () => _pickDate(c, future: future),
        decoration: InputDecoration(
          labelText: label,
          border: const OutlineInputBorder(),
          suffixIcon: const Icon(Icons.calendar_today, size: 18),
        ),
      ),
    );
  }

  Widget _stepPersonal() => _wrap([
        _sectionNote('اختر نوع الحساب ثم أدخل اسمك الرباعي كما في وثيقة الهوية.'),
        SegmentedButton<String>(
          segments: const [
            ButtonSegment(value: 'customer', label: Text('عميل'), icon: Icon(Icons.person_outline)),
            ButtonSegment(value: 'merchant', label: Text('تاجر'), icon: Icon(Icons.storefront_outlined)),
            ButtonSegment(value: 'agent', label: Text('وكيل'), icon: Icon(Icons.handshake_outlined)),
          ],
          selected: {_accountType},
          onSelectionChanged: (s) => setState(() => _accountType = s.first),
        ),
        if (_accountType == 'merchant') ...[
          const SizedBox(height: 14),
          _field(_storeName, 'اسم المتجر *'),
          DropdownButtonFormField<String>(
            value: _verticals.any((o) => o.code == _businessType) ? _businessType : null,
            decoration: const InputDecoration(labelText: 'نوع النشاط', border: OutlineInputBorder()),
            items: _verticals
                .map((o) => DropdownMenuItem(value: o.code, child: Text(o.label)))
                .toList(),
            onChanged: (v) => setState(() => _businessType = v ?? 'retail'),
          ),
        ],
        const SizedBox(height: 14),
        _field(_name1, 'الاسم الأول *'),
        _field(_name2, 'اسم الأب *'),
        _field(_name3, 'اسم الجد *'),
        _field(_name4, 'اسم العائلة *'),
        _field(_nameEn, 'الاسم بالإنجليزيّة (كما في الجواز) *'),
        _field(_countryOfBirth, 'بلد الميلاد'),
        DropdownButtonFormField<String>(
          key: const Key('reg-marital-status'),
          initialValue: _maritalStatus,
          decoration: const InputDecoration(
              labelText: 'الحالة الاجتماعيّة', border: OutlineInputBorder()),
          items: const [
            DropdownMenuItem(value: 'single', child: Text('أعزب')),
            DropdownMenuItem(value: 'married', child: Text('متزوّج')),
            DropdownMenuItem(value: 'divorced', child: Text('مطلّق')),
            DropdownMenuItem(value: 'widowed', child: Text('أرمل')),
          ],
          onChanged: (v) => setState(() => _maritalStatus = v ?? 'single'),
        ),
        const SizedBox(height: 14),
        _dateField(_dob, 'تاريخ الميلاد *'),
        DropdownButtonFormField<String>(
          value: _gender,
          decoration: const InputDecoration(labelText: 'الجنس', border: OutlineInputBorder()),
          items: const [
            DropdownMenuItem(value: 'male', child: Text('ذكر')),
            DropdownMenuItem(value: 'female', child: Text('أنثى')),
          ],
          onChanged: (v) => setState(() => _gender = v ?? 'male'),
        ),
        const SizedBox(height: 14),
        _field(_occupation, 'المهنة (اختياري)'),
        _field(
          _email,
          'البريد الإلكتروني *',
          type: TextInputType.emailAddress,
          onChanged: (_) {
            if (_otpSent || _emailChallengeId != null || _emailVerificationToken != null) {
              setState(_invalidateEmailOtp);
            }
          },
        ),
        _sectionNote('سنرسل رمز التحقق إلى هذا البريد، وسيُستخدم أيضاً لاستعادة الحساب بأمان.'),
        Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
          SizedBox(
            width: 132,
            child: DropdownButtonFormField<String>(
              key: const Key('reg-dial-code'),
              initialValue: _dialCode,
              isExpanded: true,
              decoration: const InputDecoration(
                labelText: 'الدولة',
                border: OutlineInputBorder(),
                contentPadding: EdgeInsets.symmetric(horizontal: 8, vertical: 12),
              ),
              items: [
                for (final c in _dialCodes)
                  DropdownMenuItem(
                    value: c.code,
                    child: Text('${c.label}  ${c.code}', overflow: TextOverflow.ellipsis),
                  ),
              ],
              onChanged: (v) => setState(() => _dialCode = v ?? '+967'),
            ),
          ),
          const SizedBox(width: 8),
          Expanded(child: _field(_phone, 'رقم الجوال *', type: TextInputType.phone)),
        ]),
      ]);

  Widget _stepIdentity() => _wrap([
        _sectionNote('اختر نوع وثيقة الهوية وأدخل رقمها، ومحافظة الأصل كما هي مدوّنة في الوثيقة.'),
        GovernoratePicker(
          label: 'محافظة الأصل (حسب الهوية)',
          value: _originGov,
          helper: 'كما هي في وثيقة الهوية — قد تختلف عن محافظة سكنك',
          onChanged: (v) => setState(() => _originGov = v),
        ),
        DropdownButtonFormField<String>(
          value: _idType,
          decoration: const InputDecoration(labelText: 'نوع الوثيقة', border: OutlineInputBorder()),
          items: const [
            DropdownMenuItem(value: 'nid', child: Text('بطاقة شخصية')),
            DropdownMenuItem(value: 'passport', child: Text('جواز سفر')),
            DropdownMenuItem(value: 'driving_licence', child: Text('رخصة قيادة')),
            DropdownMenuItem(value: 'trade_license', child: Text('سجل تجاري')),
          ],
          onChanged: (v) => setState(() => _idType = v ?? 'nid'),
        ),
        const SizedBox(height: 14),
        _field(_idNumber, 'رقم الهوية *'),
        _dateField(_idIssue, 'تاريخ الإصدار'),
        _dateField(_idExpiry, 'تاريخ الانتهاء', future: true),
      ]);

  Widget _stepWork() => _wrap([
        _sectionNote('بيانات عملك ومصدر دخلك — تُبنى عليها حدودُ حسابك، وتُقاس بها العمليّاتُ غيرُ المعتادة.'),
        _field(_jobTitle, 'المسمّى الوظيفيّ / المنصب'),
        _field(_employerName, 'جهة العمل'),
        _field(_workAddress, 'عنوان العمل'),
        DropdownButtonFormField<String>(
          key: const Key('reg-income-source'),
          initialValue: _incomeSource,
          decoration: const InputDecoration(
              labelText: 'المصدر الأساسيّ للدخل *', border: OutlineInputBorder()),
          items: const [
            DropdownMenuItem(value: 'salary', child: Text('راتب')),
            DropdownMenuItem(value: 'business', child: Text('تجارة')),
            DropdownMenuItem(value: 'investment', child: Text('استثمار')),
            DropdownMenuItem(value: 'rent', child: Text('إيجارات')),
            DropdownMenuItem(value: 'asset_sale', child: Text('بيع أصول')),
            DropdownMenuItem(value: 'inheritance', child: Text('ميراث')),
            DropdownMenuItem(value: 'remittance', child: Text('حوالات')),
            DropdownMenuItem(value: 'other', child: Text('أخرى')),
          ],
          onChanged: (v) => setState(() => _incomeSource = v ?? 'salary'),
        ),
        const SizedBox(height: 14),
        _field(_monthlyIncome, 'الدخل الشهريّ التقريبيّ (ريال يمنيّ)',
            type: TextInputType.number),
        DropdownButtonFormField<String>(
          key: const Key('reg-account-purpose'),
          initialValue: _accountPurpose,
          decoration: const InputDecoration(
              labelText: 'الغرض من فتح الحساب *', border: OutlineInputBorder()),
          items: const [
            DropdownMenuItem(value: 'savings', child: Text('توفير')),
            DropdownMenuItem(value: 'salary', child: Text('استلام راتب')),
            DropdownMenuItem(value: 'business', child: Text('نشاط تجاريّ')),
            DropdownMenuItem(value: 'remittance', child: Text('استلام حوالات')),
            DropdownMenuItem(value: 'payments', child: Text('مدفوعات يوميّة')),
            DropdownMenuItem(value: 'other', child: Text('أخرى')),
          ],
          onChanged: (v) => setState(() => _accountPurpose = v ?? 'savings'),
        ),
        const SizedBox(height: 20),
        const Text('هل تشغل أنت أو أحد أقاربك منصباً سياسيّاً أو حكوميّاً رفيعاً؟ *',
            style: TextStyle(fontWeight: FontWeight.w600, height: 1.6)),
        const SizedBox(height: 8),
        SegmentedButton<bool>(
          key: const Key('reg-pep-choice'),
          segments: const [
            ButtonSegment(value: false, label: Text('لا')),
            ButtonSegment(value: true, label: Text('نعم')),
          ],
          selected: _isPep == null ? <bool>{} : {_isPep!},
          emptySelectionAllowed: true,
          showSelectedIcon: false,
          onSelectionChanged: (sel) => setState(() => _isPep = sel.isEmpty ? null : sel.first),
        ),
        if (_isPep == true) _field(_pepPosition, 'المنصب — يُذكر صراحةً *'),
      ]);

  Widget _stepAddress() => _wrap([
        _sectionNote('أدخل عنوان سكنك بالتفصيل لتسهيل التحقّق.'),
        OutlinedButton.icon(
          onPressed: _locating ? null : _detectLocation,
          icon: _locating
              ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2))
              : const Icon(Icons.my_location),
          label: Text(_locating ? 'جارٍ تحديد موقعك…' : 'حدّد موقعي تلقائياً'),
        ),
        if (_locationNotice != null) ...[
          const SizedBox(height: 10),
          Container(
            padding: const EdgeInsets.all(12),
            decoration: BoxDecoration(
              color: (_inServiceArea ?? false) ? const Color(0xFFE8F5E9) : const Color(0xFFFFF3E0),
              borderRadius: BorderRadius.circular(10),
              border: Border.all(
                color: (_inServiceArea ?? false) ? const Color(0xFF0F9D58) : const Color(0xFFCFA300),
              ),
            ),
            child: Row(children: [
              Icon(
                (_inServiceArea ?? false) ? Icons.check_circle_outline : Icons.info_outline,
                size: 20,
                color: (_inServiceArea ?? false) ? const Color(0xFF0F9D58) : const Color(0xFFCFA300),
              ),
              const SizedBox(width: 8),
              Expanded(child: Text(_locationNotice!, style: const TextStyle(fontSize: 13, height: 1.5))),
            ]),
          ),
        ],
        const SizedBox(height: 16),
        GovernoratePicker(
          label: 'محافظة السكن *',
          value: _residenceGov,
          helper: 'حسب وثيقة العنوان — عليها تُحدَّد منطقة تشغيل حسابك',
          onChanged: (v) => setState(() => _residenceGov = v),
        ),
        _field(_addrDir, 'المديرية *'),
        _field(_addrArea, 'الحي / العزلة *'),
        _field(_addrStreet, 'الشارع'),
        _field(_addrLandmark, 'أقرب معلم بارز'),
        DropdownButtonFormField<String>(
          key: const Key('reg-housing-type'),
          initialValue: _housingType,
          decoration: const InputDecoration(labelText: 'نوع السكن', border: OutlineInputBorder()),
          items: const [
            DropdownMenuItem(value: 'owned', child: Text('ملك')),
            DropdownMenuItem(value: 'rented', child: Text('إيجار')),
            DropdownMenuItem(value: 'family', child: Text('سكن عائلة')),
            DropdownMenuItem(value: 'other', child: Text('أخرى')),
          ],
          onChanged: (v) => setState(() => _housingType = v ?? 'owned'),
        ),
      ]);

  Widget _stepDocuments() => _wrap([
        _sectionNote('صوّر كلَّ وثيقةٍ في خانتها. الثلاثُ الأولى مطلوبةٌ لاعتماد حسابك، وإثباتُ العنوان يرفع حدودَك لاحقاً.'),
        _docSlot('بطاقة الهوية — الوجه *', _docIdFront, (x) => setState(() => _docIdFront = x)),
        _docSlot('بطاقة الهوية — الظهر *', _docIdBack, (x) => setState(() => _docIdBack = x)),
        _docSlot('صورة شخصية حديثة *', _docSelfie, (x) => setState(() => _docSelfie = x)),
        _docSlot('إثبات العنوان (اختياري)', _docAddress, (x) => setState(() => _docAddress = x)),
      ]);

  Widget _docSlot(String label, XFile? file, void Function(XFile?) set) => Padding(
        padding: const EdgeInsets.only(bottom: 14),
        child: Row(children: [
          if (file != null)
            ClipRRect(
              borderRadius: BorderRadius.circular(8),
              child: Image.file(File(file.path), width: 64, height: 64, fit: BoxFit.cover),
            )
          else
            Container(
              width: 64, height: 64,
              decoration: BoxDecoration(
                color: const Color(0xFFF0F1F3),
                borderRadius: BorderRadius.circular(8),
              ),
              child: const Icon(Icons.image_outlined, color: Color(0xFF9AA4B2)),
            ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(label, style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 13)),
                const SizedBox(height: 4),
                Row(children: [
                  TextButton.icon(
                    onPressed: () async {
                      final x = await _pickOne();
                      if (x != null) set(x);
                    },
                    icon: const Icon(Icons.upload_file, size: 17),
                    label: Text(file == null ? 'اختر صورة' : 'استبدال'),
                  ),
                  if (file != null)
                    TextButton(
                      onPressed: () => set(null),
                      child: const Text('حذف', style: TextStyle(color: AmialColors.red)),
                    ),
                ]),
              ],
            ),
          ),
        ]),
      );

  Widget _stepKin() => _wrap([
        _sectionNote('بيانات شخص قريب يمكن التواصل معه عند الحاجة.'),
        _field(_kinName, 'اسم الشخص القريب *'),
        _field(_kinPhone, 'هاتف الشخص القريب *', type: TextInputType.phone),
        _field(_kinRelation, 'صلة القرابة (مثل: أخ، أب)'),
        const SizedBox(height: 20),
        const Text('شخصٌ ثانٍ (اختياريّ لكن يُنصح به)', style: TextStyle(fontWeight: FontWeight.w600)),
        const SizedBox(height: 8),
        _field(_kin2Name, 'اسم الشخص الثاني'),
        _field(_kin2Phone, 'هاتفه', type: TextInputType.phone),
        _field(_kin2Relation, 'صلة القرابة'),
      ]);

  Widget _stepSignature() => _wrap([
        _sectionNote('وقّع بإصبعك داخل المربّعات الثلاثة — تُعتمد توقيعاً إلكترونياً.'),
        const Text('التوقيع الأول', style: TextStyle(fontWeight: FontWeight.w600)),
        const SizedBox(height: 6),
        SignaturePadWidget(key: _sigKey1, height: 150),
        const SizedBox(height: 12),
        const Text('التوقيع الثاني', style: TextStyle(fontWeight: FontWeight.w600)),
        const SizedBox(height: 6),
        SignaturePadWidget(key: _sigKey2, height: 150),
        const SizedBox(height: 12),
        const Text('التوقيع الثالث', style: TextStyle(fontWeight: FontWeight.w600)),
        const SizedBox(height: 6),
        SignaturePadWidget(key: _sigKey3, height: 150),
      ]);

  Widget _stepDeclarations() => _wrap([
        _sectionNote('يرجى قراءة الإقرارات والموافقة عليها.'),
        CheckboxListTile(
          value: _agreeTerms,
          activeColor: AmialColors.primary,
          controlAffinity: ListTileControlAffinity.leading,
          title: const Text('أوافق على شروط الخدمة والأحكام.'),
          onChanged: (v) => setState(() => _agreeTerms = v ?? false),
        ),
        CheckboxListTile(
          value: _agreePolicy,
          activeColor: AmialColors.primary,
          controlAffinity: ListTileControlAffinity.leading,
          title: const Text('أوافق على سياسة الخصوصية.'),
          onChanged: (v) => setState(() => _agreePolicy = v ?? false),
        ),
        CheckboxListTile(
          value: _declareAccuracy,
          activeColor: AmialColors.primary,
          controlAffinity: ListTileControlAffinity.leading,
          title: const Text('أقرّ بأن جميع المعلومات والوثائق المقدّمة صحيحة، وأتحمّل المسؤولية القانونية.'),
          onChanged: (v) => setState(() => _declareAccuracy = v ?? false),
        ),
      ]);

  Widget _stepPin() => _wrap([
        _sectionNote('اختر كلمة المرور — أربعةُ أرقامٍ تدخل بها إلى حسابك وتؤكّد بها معاملاتك.'),
        _field(_pin, 'كلمة المرور (4 أرقام) *', type: TextInputType.number, maxLength: 4),
        _field(_pinConfirm, 'تأكيد كلمة المرور *', type: TextInputType.number, maxLength: 4),
      ]);

  Widget _stepOtp() => _wrap([
        _sectionNote(
          'أدخل رمز التحقق المكوّن من 6 أرقام المرسل إلى $_normalizedEmail. '
          'الرمز صالح 5 دقائق ويعمل مرة واحدة، ولن يطلبه منك فريق الدعم.',
        ),
        _field(_otp, 'رمز التحقق *', type: TextInputType.number, maxLength: 6),
        TextButton(
          onPressed: (_submitting || _otpResendSeconds > 0) ? null : _sendOtp,
          child: Text(
            _otpResendSeconds > 0
                ? 'إعادة الإرسال بعد $_otpResendSeconds ثانية'
                : 'إعادة إرسال الرمز إلى البريد',
            style: const TextStyle(color: AmialColors.primary),
          ),
        ),
      ]);

  Widget _stepSuccess() => SingleChildScrollView(
        padding: const EdgeInsets.all(24),
        child: Column(
          children: [
            const SizedBox(height: 12),
            Container(
              height: 110, width: 110,
              alignment: Alignment.center,
              decoration: BoxDecoration(
                color: Colors.white,
                borderRadius: BorderRadius.circular(28),
                boxShadow: [
                  BoxShadow(color: Colors.black.withValues(alpha: 0.06), blurRadius: 18),
                ],
              ),
              child: const Icon(Icons.verified_user_outlined, color: AmialColors.primary, size: 56),
            ),
            const SizedBox(height: 24),
            const Text('طلبك قيد المراجعة',
                style: TextStyle(fontSize: 20, fontWeight: FontWeight.bold, color: AmialColors.primary)),
            const SizedBox(height: 12),
            const Text(
              'تم التحقق من بريدك ورفع طلبك. نحن حالياً نتحقق من الوثائق لضمان أمان حسابك. '
              'تستغرق هذه العملية عادةً ما بين 24 إلى 48 ساعة عمل.',
              textAlign: TextAlign.center,
              style: TextStyle(color: Color(0xFF5F6B7C), height: 1.6),
            ),
            if (_merchantNumber != null || _agentNumber != null) ...[
              const SizedBox(height: 16),
              Container(
                width: double.infinity,
                padding: const EdgeInsets.all(14),
                decoration: BoxDecoration(
                  color: AmialColors.yellow.withValues(alpha: 0.25),
                  borderRadius: BorderRadius.circular(12),
                  border: Border.all(color: AmialColors.yellowDark),
                ),
                child: Column(children: [
                  Text(
                    _merchantNumber != null
                        ? 'رقم التاجر الخاص بك (احفظه — تدخل به للتطبيق):'
                        : 'رقم الوكيل الخاص بك (احفظه — تدخل به للتطبيق):',
                    style: const TextStyle(fontSize: 12, fontWeight: FontWeight.bold),
                  ),
                  const SizedBox(height: 6),
                  SelectableText(
                    _merchantNumber ?? _agentNumber ?? '',
                    style: const TextStyle(
                        fontSize: 22, fontWeight: FontWeight.bold,
                        color: AmialColors.primary, fontFamily: 'monospace'),
                  ),
                ]),
              ),
            ],
            const SizedBox(height: 24),
            Container(
              padding: const EdgeInsets.all(18),
              decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(16)),
              child: Column(children: [
                _reviewStep(
                  icon: Icons.check,
                  iconBg: AmialColors.primary,
                  iconColor: Colors.white,
                  title: 'تم رفع الوثائق والتحقق من البريد',
                  subtitle: 'اكتملت الخطوة',
                  done: true,
                  showLine: true,
                ),
                _reviewStep(
                  icon: Icons.hourglass_bottom_rounded,
                  iconBg: AmialColors.yellow,
                  iconColor: AmialColors.primary,
                  title: 'جاري مراجعة البيانات',
                  subtitle: 'بانتظار الموافقة',
                  done: false,
                  showLine: true,
                ),
                _reviewStep(
                  icon: Icons.account_balance_wallet_outlined,
                  iconBg: const Color(0xFFF0F1F3),
                  iconColor: AmialColors.textMuted,
                  title: 'تفعيل المحفظة بالكامل',
                  subtitle: 'الخطوة النهائية',
                  done: false,
                  dimmed: true,
                  showLine: false,
                ),
              ]),
            ),
            const SizedBox(height: 24),
            SizedBox(
              width: double.infinity,
              child: ElevatedButton(
                onPressed: () => Get.offAll(() => const UnifiedLoginScreen()),
                style: ElevatedButton.styleFrom(
                  backgroundColor: AmialColors.primary,
                  foregroundColor: Colors.white,
                  padding: const EdgeInsets.symmetric(vertical: 15),
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
                ),
                child: const Text('العودة لتسجيل الدخول'),
              ),
            ),
          ],
        ),
      );

  Widget _reviewStep({
    required IconData icon,
    required Color iconBg,
    required Color iconColor,
    required String title,
    required String subtitle,
    required bool done,
    required bool showLine,
    bool dimmed = false,
  }) {
    return IntrinsicHeight(
      child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Expanded(
          child: Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
            Text(title,
                style: TextStyle(
                    fontWeight: FontWeight.bold,
                    fontSize: 14,
                    color: dimmed ? AmialColors.textMuted : Colors.black87)),
            Text(subtitle, style: const TextStyle(fontSize: 11, color: AmialColors.textMuted)),
            const SizedBox(height: 18),
          ]),
        ),
        const SizedBox(width: 12),
        Column(children: [
          CircleAvatar(radius: 20, backgroundColor: iconBg, child: Icon(icon, size: 20, color: iconColor)),
          if (showLine)
            Expanded(child: Container(width: 2, color: const Color(0xFFE5E7EB))),
        ]),
      ]),
    );
  }
}
