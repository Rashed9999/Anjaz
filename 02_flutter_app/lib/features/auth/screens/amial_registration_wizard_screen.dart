import 'dart:io';
import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:image_picker/image_picker.dart';
import 'package:amyal_pay/data/api/api_client.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';
import 'package:amyal_pay/features/auth/widgets/signature_pad_widget.dart';
import 'package:amyal_pay/features/auth/screens/unified_login_screen.dart';

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
  final _fName = TextEditingController();
  final _lName = TextEditingController();
  final _email = TextEditingController();
  final _occupation = TextEditingController();
  String _gender = 'male';
  final _phone = TextEditingController();
  final String _dialCode = '+967';

  final _idNumber = TextEditingController();
  String _idType = 'nid';

  final _address = TextEditingController();

  final List<XFile> _idImages = [];

  final _kinName = TextEditingController();
  final _kinPhone = TextEditingController();
  final _kinRelation = TextEditingController();

  final GlobalKey<SignaturePadState> _sigKey = GlobalKey<SignaturePadState>();

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
      _fName, _lName, _email, _occupation, _phone, _idNumber, _address,
      _kinName, _kinPhone, _kinRelation, _pin, _pinConfirm, _otp,
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

  // ── التحقّق لكل خطوة ─────────────────────────────────────
  bool _validateStep(int s) {
    switch (s) {
      case 0:
        if (_fName.text.trim().isEmpty || _lName.text.trim().isEmpty) {
          _snack('أدخل الاسم الأول واسم العائلة');
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
        if (_address.text.trim().isEmpty) {
          _snack('أدخل العنوان');
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
        if (_sigKey.currentState?.isEmpty ?? true) {
          _snack('الرجاء التوقيع في المربّع');
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

  Future<void> _sendOtp() async {
    try {
      final api = Get.find<ApiClient>();
      await api.postData('/api/v1/customer/auth/check-phone', {
        'phone': '$_dialCode${_phone.text.trim()}',
      });
      _otpSent = true;
      _snack('تم إرسال رمز التحقق إلى هاتفك');
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
      final signature = await _sigKey.currentState?.exportBase64Png();
      final fields = <String, String>{
        'f_name': _fName.text.trim(),
        'l_name': _lName.text.trim(),
        'gender': _gender,
        'occupation': _occupation.text.trim(),
        'dial_country_code': _dialCode,
        'phone': _phone.text.trim(),
        'email': _email.text.trim(),
        'password': _pin.text,
        'otp': _otp.text.trim(),
        'identification_number': _idNumber.text.trim(),
        'identification_type': _idType,
        'address': _address.text.trim(),
        'kin_name': _kinName.text.trim(),
        'kin_phone': _kinPhone.text.trim(),
        'kin_relation': _kinRelation.text.trim(),
        'declaration_accepted': '1',
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
    return Scaffold(
      backgroundColor: const Color(0xFFF5F4EF),
      appBar: AppBar(
        backgroundColor: AmyalColors.primary,
        foregroundColor: Colors.white,
        title: Text('إنشاء حساب — ${titles[_step]}'),
        leading: IconButton(icon: const Icon(Icons.arrow_forward), onPressed: _back),
        automaticallyImplyLeading: false,
      ),
      body: Column(
        children: [
          if (_step < _successStep)
            LinearProgressIndicator(
              value: (_step + 1) / 9,
              backgroundColor: Colors.grey.shade200,
              color: AmyalColors.primary,
            ),
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
    );
  }

  Widget _bottomBar() {
    return SafeArea(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Row(
          children: [
            Expanded(
              child: OutlinedButton(
                onPressed: _submitting ? null : _back,
                style: OutlinedButton.styleFrom(
                  padding: const EdgeInsets.symmetric(vertical: 14),
                  side: const BorderSide(color: AmyalColors.primary),
                ),
                child: Text(_step == 0 ? 'إلغاء' : 'السابق',
                    style: const TextStyle(color: AmyalColors.primary)),
              ),
            ),
            const SizedBox(width: 12),
            Expanded(
              flex: 2,
              child: ElevatedButton(
                onPressed: _submitting ? null : _next,
                style: ElevatedButton.styleFrom(
                  backgroundColor: AmyalColors.primary,
                  foregroundColor: Colors.white,
                  padding: const EdgeInsets.symmetric(vertical: 14),
                ),
                child: _submitting
                    ? const SizedBox(
                        height: 20, width: 20,
                        child: CircularProgressIndicator(
                            color: Colors.white, strokeWidth: 2))
                    : Text(_step == _lastInputStep ? 'إنشاء الحساب' : 'التالي'),
              ),
            ),
          ],
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
        child: Text(t, style: const TextStyle(color: Color(0xFF5F6B62), fontSize: 13)),
      );

  Widget _stepPersonal() => _wrap([
        _sectionNote('أدخل معلوماتك الشخصية كما في وثيقة الهوية.'),
        _field(_fName, 'الاسم الأول *'),
        _field(_lName, 'اسم العائلة *'),
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
      ]);

  Widget _stepAddress() => _wrap([
        _sectionNote('أدخل عنوان سكنك بالتفصيل.'),
        _field(_address, 'المدينة، الحي، الشارع *', maxLines: 3),
      ]);

  Widget _stepDocuments() => _wrap([
        _sectionNote('أرفق صوراً واضحة لوثيقة الهوية (الوجه والظهر) وإثبات العنوان.'),
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
        _sectionNote('وقّع بإصبعك داخل المربّع أدناه — يُعتمد توقيعاً إلكترونياً.'),
        SignaturePadWidget(key: _sigKey, height: 180),
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

  Widget _stepSuccess() => Center(
        child: Padding(
          padding: const EdgeInsets.all(28),
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              Container(
                height: 96, width: 96,
                decoration: const BoxDecoration(
                    color: AmyalColors.primary, shape: BoxShape.circle),
                child: const Icon(Icons.check_rounded, color: Colors.white, size: 56),
              ),
              const SizedBox(height: 24),
              const Text('تم إنشاء حسابك بنجاح',
                  style: TextStyle(fontSize: 20, fontWeight: FontWeight.bold)),
              const SizedBox(height: 12),
              const Text(
                'حسابك الآن قيد مراجعة الإدارة. سنُعلمك فور الموافقة، وبعدها يمكنك الدخول واستخدام أميال باي.',
                textAlign: TextAlign.center,
                style: TextStyle(color: Color(0xFF5F6B62), height: 1.5),
              ),
              const SizedBox(height: 28),
              SizedBox(
                width: double.infinity,
                child: ElevatedButton(
                  onPressed: () => Get.offAll(() => const UnifiedLoginScreen()),
                  style: ElevatedButton.styleFrom(
                    backgroundColor: AmyalColors.primary,
                    foregroundColor: Colors.white,
                    padding: const EdgeInsets.symmetric(vertical: 14),
                  ),
                  child: const Text('العودة لتسجيل الدخول'),
                ),
              ),
            ],
          ),
        ),
      );
}
