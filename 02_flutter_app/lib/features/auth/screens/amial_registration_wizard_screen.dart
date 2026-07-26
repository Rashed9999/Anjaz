import 'dart:io';
import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:image_picker/image_picker.dart';
import 'package:geolocator/geolocator.dart';
import 'package:amyal_pay/data/api/api_client.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';
import 'package:amyal_pay/features/auth/widgets/signature_pad_widget.dart';
import 'package:amyal_pay/features/auth/screens/unified_login_screen.dart';
import 'package:amyal_pay/common/widgets/amial_button.dart';

/// AMIAL-REG-WIZARD-001
///
/// معالج تسجيل عميل بنمط البنوك — 10 خطوات: شخصية → هوية → عنوان → وثائق →
/// شخص قريب → توقيع مرسوم → إقرارات → PIN → OTP → «بانتظار موافقة الإدارة».
/// يرسل كل شيء في نداء تسجيل واحد (multipart) مع التوقيع (base64) والوثائق.
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

  static const int _lastInputStep = 8; // خطوة OTP (يليها النجاح)
  static const int _successStep = 9;

  // ── الحقول ──────────────────────────────────────────────
  // الاسم الرباعي
  final _name1 = TextEditingController(); // الأول
  final _name2 = TextEditingController(); // الأب
  final _name3 = TextEditingController(); // الجد
  final _name4 = TextEditingController(); // العائلة
  final _dob = TextEditingController();    // تاريخ الميلاد (yyyy-MM-dd)
  final _email = TextEditingController();
  final _occupation = TextEditingController();
  String _gender = 'male';
  // AMIAL-REG-ROLES: نوع الحساب + حقول التاجر + أرقام الدخول من الخادم
  String _accountType = 'customer';
  String _businessType = 'retail';
  final _storeName = TextEditingController();
  String? _agentNumber;
  String? _merchantNumber;
  final _phone = TextEditingController();
  final String _dialCode = '+967';

  final _idNumber = TextEditingController();
  String _idType = 'nid';
  final _idIssue = TextEditingController();  // تاريخ الإصدار
  final _idExpiry = TextEditingController(); // تاريخ الانتهاء

  // العنوان بالتفصيل
  final _addrGov = TextEditingController();      // المحافظة
  final _addrDir = TextEditingController();      // المديرية
  final _addrArea = TextEditingController();     // الحي/العزلة
  final _addrStreet = TextEditingController();   // الشارع
  final _addrLandmark = TextEditingController(); // أقرب معلم

  final List<XFile> _idImages = [];

  final _kinName = TextEditingController();
  final _kinPhone = TextEditingController();
  final _kinRelation = TextEditingController();

  // ثلاث خانات توقيع
  final GlobalKey<SignaturePadState> _sigKey1 = GlobalKey<SignaturePadState>();
  final GlobalKey<SignaturePadState> _sigKey2 = GlobalKey<SignaturePadState>();
  final GlobalKey<SignaturePadState> _sigKey3 = GlobalKey<SignaturePadState>();

  bool _agreeTerms = false;
  bool _agreePolicy = false;
  bool _declareAccuracy = false;

  final _pin = TextEditingController();
  final _pinConfirm = TextEditingController();
  final _otp = TextEditingController();

  @override
  void dispose() {
    _page.dispose();
    for (final c in [
      _name1, _name2, _name3, _name4, _dob, _email, _occupation, _phone,
      _idNumber, _idIssue, _idExpiry,
      _addrGov, _addrDir, _addrArea, _addrStreet, _addrLandmark,
      _kinName, _kinPhone, _kinRelation, _pin, _pinConfirm, _otp, _storeName,
    ]) {
      c.dispose();
    }
    super.dispose();
  }

  void _snack(String msg) {
    if (!mounted) return;
    ScaffoldMessenger.of(context)
        .showSnackBar(SnackBar(content: Text(msg), backgroundColor: AmyalColors.red));
  }

  // منتقي تاريخ بسيط (بلا حزمة) — يكتب النتيجة yyyy-MM-dd في الحقل
  Future<void> _pickDate(TextEditingController c, {bool future = false}) async {
    final now = DateTime(2026, 7, 12);
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

  // ── التحقّق لكل خطوة ─────────────────────────────────────
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
        if (_addrGov.text.trim().isEmpty || _addrDir.text.trim().isEmpty ||
            _addrArea.text.trim().isEmpty) {
          _snack('أدخل المحافظة والمديرية والحي على الأقل');
          return false;
        }
        return true;
      case 3:
        if (_idImages.isEmpty) {
          _snack('أرفق صورة وثيقة الهوية');
          return false;
        }
        return true;
      case 4:
        if (_kinName.text.trim().isEmpty || _kinPhone.text.trim().isEmpty) {
          _snack('أدخل بيانات الشخص القريب');
          return false;
        }
        return true;
      case 5:
        if ((_sigKey1.currentState?.isEmpty ?? true) ||
            (_sigKey2.currentState?.isEmpty ?? true) ||
            (_sigKey3.currentState?.isEmpty ?? true)) {
          _snack('الرجاء التوقيع في المربّعات الثلاثة');
          return false;
        }
        return true;
      case 6:
        if (!_agreeTerms || !_agreePolicy || !_declareAccuracy) {
          _snack('الرجاء الموافقة على جميع الإقرارات');
          return false;
        }
        return true;
      case 7:
        if (_pin.text.length != 4) {
          _snack('رمز PIN يجب أن يكون 4 أرقام');
          return false;
        }
        if (_pin.text != _pinConfirm.text) {
          _snack('رمز PIN غير متطابق');
          return false;
        }
        return true;
      case 8:
        if (_otp.text.trim().isEmpty) {
          _snack('أدخل رمز التحقق المرسل إلى هاتفك');
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

    // بعد خطوة PIN → أرسل OTP قبل الانتقال لخطوة OTP
    if (_step == 7 && !_otpSent) {
      await _sendOtp();
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

  // AMIAL-GEO-ZONE-001 — حالة تحديد الموقع
  bool _locating = false;
  String? _locationNotice;
  bool? _inServiceArea;

  /// يطلب الموقع، ويسأل الخادم عن المحافظة، ويعبّئ حقل المحافظة.
  ///
  /// كل مسارات الفشل تنتهي برسالة مفهومة وإبقاء الإدخال اليدوي عاملاً —
  /// لا يجوز أن يمنع تعذّرُ الموقع إتمامَ التسجيل.
  Future<void> _detectLocation() async {
    setState(() {
      _locating = true;
      _locationNotice = null;
      _inServiceArea = null;
    });

    try {
      if (!await Geolocator.isLocationServiceEnabled()) {
        setState(() => _locationNotice =
            'خدمة الموقع (GPS) مُطفأة في جهازك. شغّلها ثم أعد المحاولة، '
            'أو أدخل عنوانك يدوياً.');
        return;
      }

      var permission = await Geolocator.checkPermission();
      if (permission == LocationPermission.denied) {
        permission = await Geolocator.requestPermission();
      }
      if (permission == LocationPermission.deniedForever) {
        setState(() => _locationNotice =
            'إذن الموقع مرفوض نهائياً. يمكنك السماح به من إعدادات التطبيق، '
            'أو إدخال عنوانك يدوياً.');
        return;
      }
      if (permission == LocationPermission.denied) {
        setState(() => _locationNotice =
            'لم تسمح بالوصول إلى الموقع. أدخل عنوانك يدوياً.');
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

      final governorate = data['governorate'];
      setState(() {
        _inServiceArea = data['in_service_area'] == true;
        _locationNotice = (data['notice'] as String?) ??
            'تم تحديد موقعك.';
        // نعبّئ المحافظة ولا نُقفل الحقل: التحديد تقريبيّ بأقرب مركز،
        // فيجب أن يبقى تصحيحه ممكناً.
        if (governorate is String && governorate.isNotEmpty) {
          _addrGov.text = governorate;
        }
      });
    } catch (e) {
      setState(() => _locationNotice =
          'تعذّر تحديد موقعك الآن. أدخل عنوانك يدوياً.');
    } finally {
      if (mounted) setState(() => _locating = false);
    }
  }

  Future<void> _sendOtp() async {
    try {
      final api = Get.find<ApiClient>();
      final r = await api.postData('/api/v1/customer/auth/check-phone', {
        'phone': '$_dialCode${_phone.text.trim()}',
      });
      _otpSent = true;
      // AMIAL-DEMO-OTP: في وضع التجربة (بلا بوابة SMS) يعيد الخادم الرمز
      // مباشرة — نعبّئه تلقائياً كي لا يقف التسجيل عند رمز لا يصل.
      final demoOtp = (r.body is Map) ? r.body['demo_otp'] : null;
      if (demoOtp != null && '$demoOtp'.isNotEmpty) {
        setState(() => _otp.text = '$demoOtp');
        _snack('وضع التجربة: عُبّئ رمز التحقق تلقائياً');
      } else {
        _snack('تم إرسال رمز التحقق إلى هاتفك');
      }
    } catch (_) {/* قد يكون التحقّق بالهاتف معطّلاً */}
  }

  Future<void> _pickIdImages() async {
    try {
      final imgs = await ImagePicker().pickMultiImage();
      if (imgs.isNotEmpty) setState(() => _idImages.addAll(imgs));
    } catch (_) {
      _snack('تعذّر اختيار الصور');
    }
  }

  Future<void> _submit() async {
    setState(() => _submitting = true);
    try {
      final signature = await _sigKey1.currentState?.exportBase64Png();
      // الاسم الرباعي: الأول = f_name، والبقية تُجمع في l_name
      final lastName = [_name2.text, _name3.text, _name4.text]
          .map((s) => s.trim())
          .where((s) => s.isNotEmpty)
          .join(' ');
      // العنوان المفصّل يُجمع في نصّ واحد
      final address = [
        _addrGov.text, _addrDir.text, _addrArea.text,
        _addrStreet.text, _addrLandmark.text,
      ].map((s) => s.trim()).where((s) => s.isNotEmpty).join('، ');
      final fields = <String, String>{
        'f_name': _name1.text.trim(),
        'l_name': lastName,
        'gender': _gender,
        'occupation': _occupation.text.trim(),
        'dial_country_code': _dialCode,
        'phone': _phone.text.trim(),
        'email': _email.text.trim(),
        'password': _pin.text,
        'otp': _otp.text.trim(),
        'date_of_birth': _dob.text.trim(),
        'identification_number': _idNumber.text.trim(),
        'identification_type': _idType,
        'identification_issue_date': _idIssue.text.trim(),
        'identification_expiry_date': _idExpiry.text.trim(),
        'address': address,
        'kin_name': _kinName.text.trim(),
        'kin_phone': _kinPhone.text.trim(),
        'kin_relation': _kinRelation.text.trim(),
        'declaration_accepted': '1',
        // AMIAL-REG-ROLES: نوع الحساب وحقول التاجر
        'account_type': _accountType,
        if (_accountType == 'merchant') 'store_name': _storeName.text.trim(),
        if (_accountType == 'merchant') 'business_type': _businessType,
        if (signature != null) 'signature': signature,
      };
      final parts = _idImages
          .map((x) => MultipartBody('identification_image[]', File(x.path)))
          .toList();

      final api = Get.find<ApiClient>();
      final r = await api.postMultipartData(
          '/api/v1/customer/auth/register', fields, parts);

      final ok = r.statusCode == 200 &&
          (r.body is Map) &&
          ('${r.body['message'] ?? ''}').toLowerCase().contains('success');
      if (ok) {
        setState(() {
          // أرقام الدخول للتاجر/الوكيل — تُعرض في شاشة النجاح ليحفظها المستخدم
          _agentNumber = (r.body is Map) ? r.body['agent_number']?.toString() : null;
          _merchantNumber = (r.body is Map) ? r.body['merchant_number']?.toString() : null;
          _step = _successStep;
          _submitting = false;
        });
        _page.animateToPage(_successStep,
            duration: const Duration(milliseconds: 250), curve: Curves.easeInOut);
        return;
      }
      String msg = 'تعذّر إكمال التسجيل';
      try {
        if (r.body is Map && r.body['errors'] is List && (r.body['errors'] as List).isNotEmpty) {
          msg = '${(r.body['errors'] as List).first['message'] ?? msg}';
        } else if (r.body is Map && r.body['message'] != null) {
          msg = '${r.body['message']}';
        }
      } catch (_) {}
      _snack(msg);
    } catch (e) {
      _snack('خطأ في الاتصال');
    } finally {
      if (mounted) setState(() => _submitting = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final titles = [
      'المعلومات الشخصية', 'معلومات الهوية', 'العنوان', 'وثائق الهوية',
      'شخص قريب', 'التوقيع الإلكتروني', 'الإقرارات', 'رمز PIN', 'رمز التحقق', 'تم',
    ];
    // AMIAL-REG-UI-002: لغة المراجع الاحترافية — بلا شريط عنوان ملوّن ثقيل.
    // رجوع خفيف + شريط تقدّم مقسّم بعدد الخطوات + عنوان كبير أسفله.
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
                        color: AmyalColors.background,
                        borderRadius: BorderRadius.circular(12),
                      ),
                      child: const Icon(Icons.arrow_forward,
                          size: 19, color: Color(0xFF1A2433)),
                    ),
                  ),
                  const Spacer(),
                  Text('الخطوة ${_step + 1} من 9',
                      style: const TextStyle(
                          fontSize: 12,
                          fontWeight: FontWeight.w600,
                          color: AmyalColors.textMuted)),
                ],
              ),
            ),
            // شريط تقدّم مقسّم إلى أجزاء بعدد الخطوات
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
              child: Row(
                children: List.generate(9, (i) {
                  final done = i <= _step;
                  return Expanded(
                    child: Container(
                      height: 4,
                      margin: EdgeInsets.only(left: i == 8 ? 0 : 4),
                      decoration: BoxDecoration(
                        color: done ? AmyalColors.primary : const Color(0xFFE6E9EF),
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

  /// AMIAL-REG-UI-002: شريط سفلي ثابت بحدّ علويّ خفيف — الزرّ الأساسي يأخذ
  /// العرض الأكبر (كما في المراجع)، وموحّد عبر AmialButton.
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

  // ── واجهات الخطوات ──────────────────────────────────────
  Widget _wrap(List<Widget> children) => SingleChildScrollView(
        padding: const EdgeInsets.all(20),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: children),
      );

  Widget _field(TextEditingController c, String label,
      {TextInputType? type, int maxLines = 1, int? maxLength}) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 14),
      child: TextField(
        controller: c,
        keyboardType: type,
        maxLines: maxLines,
        maxLength: maxLength,
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

  // حقل تاريخ للقراءة فقط يفتح المنتقي عند الضغط
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
        // AMIAL-REG-ROLES: نوع الحساب — الحساب يُنشأ حقيقياً بالدور المختار
        // ويصل للوحة «التحقق» في الإدارة لاعتماده.
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
            value: _businessType,
            decoration: const InputDecoration(labelText: 'نوع النشاط', border: OutlineInputBorder()),
            items: const [
              DropdownMenuItem(value: 'retail', child: Text('بقالة / سوبرماركت')),
              DropdownMenuItem(value: 'quick_sale', child: Text('بيع سريع (بسطة/خضار/أسماك)')),
              DropdownMenuItem(value: 'fuel', child: Text('محطة وقود')),
              DropdownMenuItem(value: 'pharmacy', child: Text('صيدلية')),
              DropdownMenuItem(value: 'wholesale', child: Text('جملة')),
              DropdownMenuItem(value: 'restaurant', child: Text('مطعم')),
            ],
            onChanged: (v) => setState(() => _businessType = v ?? 'retail'),
          ),
        ],
        const SizedBox(height: 14),
        _field(_name1, 'الاسم الأول *'),
        _field(_name2, 'اسم الأب *'),
        _field(_name3, 'اسم الجد *'),
        _field(_name4, 'اسم العائلة *'),
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
        _field(_email, 'البريد الإلكتروني (اختياري)', type: TextInputType.emailAddress),
        Row(children: [
          const SizedBox(width: 70, child: Text('967+', textAlign: TextAlign.center)),
          Expanded(child: _field(_phone, 'رقم الجوال *', type: TextInputType.phone)),
        ]),
      ]);

  Widget _stepIdentity() => _wrap([
        _sectionNote('اختر نوع وثيقة الهوية وأدخل رقمها.'),
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

  Widget _stepAddress() => _wrap([
        _sectionNote('أدخل عنوان سكنك بالتفصيل لتسهيل التحقّق.'),

        // AMIAL-GEO-ZONE-001: تحديد المحافظة من موقع الجهاز.
        // اختياري تماماً — رفض الإذن يترك الإدخال اليدوي كما هو.
        OutlinedButton.icon(
          onPressed: _locating ? null : _detectLocation,
          icon: _locating
              ? const SizedBox(
                  width: 16, height: 16,
                  child: CircularProgressIndicator(strokeWidth: 2))
              : const Icon(Icons.my_location),
          label: Text(_locating ? 'جارٍ تحديد موقعك…' : 'حدّد موقعي تلقائياً'),
        ),
        if (_locationNotice != null) ...[
          const SizedBox(height: 10),
          Container(
            padding: const EdgeInsets.all(12),
            decoration: BoxDecoration(
              color: (_inServiceArea ?? false)
                  ? const Color(0xFFE8F5E9)
                  : const Color(0xFFFFF3E0),
              borderRadius: BorderRadius.circular(10),
              border: Border.all(
                color: (_inServiceArea ?? false)
                    ? const Color(0xFF0F9D58)
                    : const Color(0xFFCFA300),
              ),
            ),
            child: Row(children: [
              Icon(
                (_inServiceArea ?? false)
                    ? Icons.check_circle_outline
                    : Icons.info_outline,
                size: 20,
                color: (_inServiceArea ?? false)
                    ? const Color(0xFF0F9D58)
                    : const Color(0xFFCFA300),
              ),
              const SizedBox(width: 8),
              Expanded(
                child: Text(_locationNotice!,
                    style: const TextStyle(fontSize: 13, height: 1.5)),
              ),
            ]),
          ),
        ],
        const SizedBox(height: 16),

        _field(_addrGov, 'المحافظة *'),
        _field(_addrDir, 'المديرية *'),
        _field(_addrArea, 'الحي / العزلة *'),
        _field(_addrStreet, 'الشارع'),
        _field(_addrLandmark, 'أقرب معلم بارز'),
      ]);

  Widget _stepDocuments() => _wrap([
        _sectionNote('أرفق صوراً واضحة (يمكن اختيار عدّة صور):\n'
            '١) الهوية — الوجه   ٢) الهوية — الظهر\n'
            '٣) إثبات العنوان   ٤) صورة شخصية حديثة'),
        OutlinedButton.icon(
          onPressed: _pickIdImages,
          icon: const Icon(Icons.upload_file),
          label: const Text('إرفاق صور الوثائق'),
        ),
        const SizedBox(height: 12),
        Wrap(
          spacing: 8, runSpacing: 8,
          children: List.generate(_idImages.length, (i) {
            return Stack(children: [
              ClipRRect(
                borderRadius: BorderRadius.circular(8),
                child: Image.file(File(_idImages[i].path),
                    width: 90, height: 90, fit: BoxFit.cover),
              ),
              Positioned(
                top: 0, left: 0,
                child: InkWell(
                  onTap: () => setState(() => _idImages.removeAt(i)),
                  child: Container(
                    color: Colors.red.withValues(alpha: 0.85),
                    child: const Icon(Icons.close, color: Colors.white, size: 18),
                  ),
                ),
              ),
            ]);
          }),
        ),
      ]);

  Widget _stepKin() => _wrap([
        _sectionNote('بيانات شخص قريب يمكن التواصل معه عند الحاجة.'),
        _field(_kinName, 'اسم الشخص القريب *'),
        _field(_kinPhone, 'هاتف الشخص القريب *', type: TextInputType.phone),
        _field(_kinRelation, 'صلة القرابة (مثل: أخ، أب)'),
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
          activeColor: AmyalColors.primary,
          controlAffinity: ListTileControlAffinity.leading,
          title: const Text('أوافق على شروط الخدمة والأحكام.'),
          onChanged: (v) => setState(() => _agreeTerms = v ?? false),
        ),
        CheckboxListTile(
          value: _agreePolicy,
          activeColor: AmyalColors.primary,
          controlAffinity: ListTileControlAffinity.leading,
          title: const Text('أوافق على سياسة الخصوصية.'),
          onChanged: (v) => setState(() => _agreePolicy = v ?? false),
        ),
        CheckboxListTile(
          value: _declareAccuracy,
          activeColor: AmyalColors.primary,
          controlAffinity: ListTileControlAffinity.leading,
          title: const Text(
              'أقرّ بأن جميع المعلومات والوثائق المقدّمة صحيحة، وأتحمّل المسؤولية القانونية.'),
          onChanged: (v) => setState(() => _declareAccuracy = v ?? false),
        ),
      ]);

  Widget _stepPin() => _wrap([
        _sectionNote('أنشئ رمز PIN من 4 أرقام لتأمين حسابك (يُستخدم للدخول والمعاملات).'),
        _field(_pin, 'رمز PIN (4 أرقام) *',
            type: TextInputType.number, maxLength: 4),
        _field(_pinConfirm, 'تأكيد رمز PIN *',
            type: TextInputType.number, maxLength: 4),
      ]);

  Widget _stepOtp() => _wrap([
        _sectionNote('أدخل رمز التحقق المرسل إلى هاتفك ${_dialCode}${_phone.text}.'),
        _field(_otp, 'رمز التحقق *', type: TextInputType.number, maxLength: 6),
        TextButton(
          onPressed: _submitting ? null : _sendOtp,
          child: const Text('إعادة إرسال الرمز',
              style: TextStyle(color: AmyalColors.primary)),
        ),
      ]);

  // AMIAL-DESIGN: «طلبك قيد المراجعة» — درع + مسار 3 خطوات (رُفعت الوثائق →
  // جاري المراجعة → تفعيل المحفظة) + العودة للدخول.
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
                  BoxShadow(
                      color: Colors.black.withValues(alpha: 0.06),
                      blurRadius: 18),
                ],
              ),
              child: const Icon(Icons.verified_user_outlined,
                  color: AmyalColors.primary, size: 56),
            ),
            const SizedBox(height: 24),
            const Text('طلبك قيد المراجعة',
                style: TextStyle(
                    fontSize: 20,
                    fontWeight: FontWeight.bold,
                    color: AmyalColors.primary)),
            const SizedBox(height: 12),
            const Text(
              'نحن حالياً نتحقق من الوثائق التي قمت برفعها لضمان أمان حسابك. '
              'تستغرق هذه العملية عادةً ما بين 24 إلى 48 ساعة عمل.',
              textAlign: TextAlign.center,
              style: TextStyle(color: Color(0xFF5F6B7C), height: 1.6),
            ),
            // AMIAL-REG-ROLES: رقم دخول التاجر/الوكيل — يحتاجه عند تسجيل الدخول
            if (_merchantNumber != null || _agentNumber != null) ...[
              const SizedBox(height: 16),
              Container(
                width: double.infinity,
                padding: const EdgeInsets.all(14),
                decoration: BoxDecoration(
                  color: AmyalColors.yellow.withValues(alpha: 0.25),
                  borderRadius: BorderRadius.circular(12),
                  border: Border.all(color: AmyalColors.yellowDark),
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
                        color: AmyalColors.primary, fontFamily: 'monospace'),
                  ),
                ]),
              ),
            ],
            const SizedBox(height: 24),

            // ====== مسار المراجعة ======
            Container(
              padding: const EdgeInsets.all(18),
              decoration: BoxDecoration(
                color: Colors.white,
                borderRadius: BorderRadius.circular(16),
              ),
              child: Column(children: [
                _reviewStep(
                  icon: Icons.check,
                  iconBg: AmyalColors.primary,
                  iconColor: Colors.white,
                  title: 'تم رفع الوثائق بنجاح',
                  subtitle: 'اكتملت الخطوة',
                  done: true,
                  showLine: true,
                ),
                _reviewStep(
                  icon: Icons.hourglass_bottom_rounded,
                  iconBg: AmyalColors.yellow,
                  iconColor: AmyalColors.primary,
                  title: 'جاري مراجعة البيانات',
                  subtitle: 'بانتظار الموافقة',
                  done: false,
                  showLine: true,
                ),
                _reviewStep(
                  icon: Icons.account_balance_wallet_outlined,
                  iconBg: const Color(0xFFF0F1F3),
                  iconColor: AmyalColors.textMuted,
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
                  backgroundColor: AmyalColors.primary,
                  foregroundColor: Colors.white,
                  padding: const EdgeInsets.symmetric(vertical: 15),
                  shape: RoundedRectangleBorder(
                      borderRadius: BorderRadius.circular(14)),
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
                    color: dimmed ? AmyalColors.textMuted : Colors.black87)),
            Text(subtitle,
                style: const TextStyle(
                    fontSize: 11, color: AmyalColors.textMuted)),
            const SizedBox(height: 18),
          ]),
        ),
        const SizedBox(width: 12),
        Column(children: [
          CircleAvatar(
            radius: 20,
            backgroundColor: iconBg,
            child: Icon(icon, size: 20, color: iconColor),
          ),
          if (showLine)
            Expanded(
              child: Container(width: 2, color: const Color(0xFFE5E7EB)),
            ),
        ]),
      ]),
    );
  }
}
