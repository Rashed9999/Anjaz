import 'dart:io';
import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amial_pay/data/api/api_client.dart';
import 'package:amial_pay/theme/amial_colors.dart';
import 'package:amial_pay/features/setting/domain/models/profile_model.dart';
import 'package:amial_pay/features/auth/controllers/auth_controller.dart';
import 'package:amial_pay/features/auth/domain/reposotories/auth_repo.dart';
import 'package:amial_pay/features/setting/controllers/edit_profile_controller.dart';
import 'package:amial_pay/features/setting/controllers/profile_screen_controller.dart';
import 'package:amial_pay/features/camera_verification/controllers/camera_screen_controller.dart';
import 'package:amial_pay/helper/route_helper.dart';

/// AMIAL-PROFILE-EDIT-001 + AMIAL-EMAIL-IDENTITY-001
///
/// البريد الإلكتروني اعتماد استعادة أمني، لذلك لا يُعدّل مع الاسم/الصورة.
/// تغييره يتطلب كلمة المرور الحالية ثم OTP يصل إلى البريد الجديد، وبعد نجاح
/// التغيير تُلغى الجلسات ويُطلب تسجيل الدخول من جديد.
class EditProfileScreen extends StatefulWidget {
  const EditProfileScreen({super.key});

  @override
  State<EditProfileScreen> createState() => _EditProfileScreenState();
}

class _EditProfileScreenState extends State<EditProfileScreen> {
  final _formKey = GlobalKey<FormState>();
  final _firstName = TextEditingController();
  final _lastName = TextEditingController();
  final _email = TextEditingController();
  final _occupation = TextEditingController();
  final _api = Get.find<ApiClient>();
  bool _changingEmail = false;

  @override
  void initState() {
    super.initState();
    final info = Get.find<ProfileController>().userInfo;
    _firstName.text = info?.fName ?? '';
    _lastName.text = info?.lName ?? '';
    _email.text = info?.email ?? '';
    _occupation.text = info?.occupation ?? '';
    Get.find<EditProfileController>().setGender(info?.gender ?? 'Male', isUpdate: false);
  }

  @override
  void dispose() {
    _firstName.dispose();
    _lastName.dispose();
    _email.dispose();
    _occupation.dispose();
    super.dispose();
  }

  void _snack(String m, {bool ok = false}) => ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(content: Text(m), backgroundColor: ok ? AmialColors.success : AmialColors.red));

  String _responseMessage(Response response, String fallback) {
    final body = response.body;
    if (body is Map && body['message'] != null) return '${body['message']}';
    return fallback;
  }

  Map<String, dynamic> _responseMeta(Response response) {
    final body = response.body;
    if (body is! Map || body['meta'] is! Map) return <String, dynamic>{};
    return Map<String, dynamic>.from(body['meta'] as Map);
  }

  Future<void> _save(EditProfileController c) async {
    if (!_formKey.currentState!.validate()) return;
    final image = Get.find<CameraScreenController>().getImage;
    final body = ProfileModel(
      fName: _firstName.text.trim(),
      lName: _lastName.text.trim(),
      gender: c.gender,
      occupation: _occupation.text.trim(),
      // البريد لا يمر من update-profile؛ يبقى هنا للعرض فقط.
      email: _email.text.trim(),
    );
    final multipart = image != null ? [MultipartBody('image', image)] : <MultipartBody>[];
    await c.updateProfileData(body, multipart);
  }

  Future<void> _changeEmail() async {
    if (_changingEmail) return;

    final newEmail = TextEditingController();
    final password = TextEditingController();
    final firstKey = GlobalKey<FormState>();

    final proceed = await showDialog<bool>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        title: Text(_email.text.trim().isEmpty ? 'إضافة البريد الإلكتروني' : 'تغيير البريد الإلكتروني'),
        content: Form(
          key: firstKey,
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            const Text(
              'سيصل رمز تحقق إلى البريد الجديد. لا يمكن ربط بريد مستخدم بحساب آخر.',
              style: TextStyle(fontSize: 13),
            ),
            const SizedBox(height: 14),
            TextFormField(
              controller: newEmail,
              keyboardType: TextInputType.emailAddress,
              textDirection: TextDirection.ltr,
              decoration: const InputDecoration(
                labelText: 'البريد الجديد',
                border: OutlineInputBorder(),
              ),
              validator: (v) {
                final s = (v ?? '').trim();
                if (s.isEmpty) return 'البريد مطلوب';
                return RegExp(r'^[^@\s]+@[^@\s]+\.[^@\s]+$').hasMatch(s)
                    ? null
                    : 'بريد غير صحيح';
              },
            ),
            const SizedBox(height: 12),
            TextFormField(
              controller: password,
              obscureText: true,
              decoration: const InputDecoration(
                labelText: 'كلمة المرور الحالية',
                border: OutlineInputBorder(),
              ),
              validator: (v) => (v == null || v.isEmpty) ? 'كلمة المرور مطلوبة' : null,
            ),
          ]),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(dialogContext, false), child: const Text('إلغاء')),
          FilledButton(
            onPressed: () {
              if (firstKey.currentState?.validate() == true) {
                Navigator.pop(dialogContext, true);
              }
            },
            child: const Text('إرسال رمز التحقق'),
          ),
        ],
      ),
    );

    final newAddress = newEmail.text.trim().toLowerCase();
    final currentPassword = password.text;
    newEmail.dispose();
    password.dispose();
    if (proceed != true || !mounted) return;

    setState(() => _changingEmail = true);
    try {
      final requested = await _api.postData('/api/v1/auth/email-change/request', {
        'new_email': newAddress,
        'current_password': currentPassword,
      });
      if (!mounted) return;
      if (requested.statusCode != 200 || requested.body is! Map || requested.body['success'] != true) {
        _snack(_responseMessage(requested, 'تعذّر إرسال رمز التحقق'));
        return;
      }

      final meta = _responseMeta(requested);
      final challengeId = '${meta['challenge_id'] ?? ''}';
      if (challengeId.isEmpty) {
        _snack('تعذر إنشاء طلب تغيير البريد. حاول مرة أخرى.');
        return;
      }

      final otp = TextEditingController();
      final otpKey = GlobalKey<FormState>();
      final confirmed = await showDialog<bool>(
        context: context,
        barrierDismissible: false,
        builder: (dialogContext) => AlertDialog(
          title: const Text('تحقق من البريد الجديد'),
          content: Form(
            key: otpKey,
            child: Column(mainAxisSize: MainAxisSize.min, children: [
              Text('أدخل الرمز المكوّن من 6 أرقام الذي أرسلناه إلى $newAddress'),
              const SizedBox(height: 14),
              TextFormField(
                controller: otp,
                keyboardType: TextInputType.number,
                textAlign: TextAlign.center,
                maxLength: 6,
                decoration: const InputDecoration(
                  labelText: 'رمز التحقق',
                  counterText: '',
                  border: OutlineInputBorder(),
                ),
                validator: (v) => RegExp(r'^\d{6}$').hasMatch((v ?? '').trim())
                    ? null
                    : 'أدخل 6 أرقام',
              ),
              const SizedBox(height: 8),
              const Text(
                'الرمز صالح لفترة قصيرة ويعمل مرة واحدة. فريق أميال لن يطلبه منك.',
                style: TextStyle(fontSize: 12, color: Colors.black54),
              ),
            ]),
          ),
          actions: [
            TextButton(onPressed: () => Navigator.pop(dialogContext, false), child: const Text('إلغاء')),
            FilledButton(
              onPressed: () {
                if (otpKey.currentState?.validate() == true) {
                  Navigator.pop(dialogContext, true);
                }
              },
              child: const Text('تأكيد البريد'),
            ),
          ],
        ),
      );

      final code = otp.text.trim();
      otp.dispose();
      if (confirmed != true || !mounted) return;

      final changed = await _api.postData('/api/v1/auth/email-change/confirm', {
        'challenge_id': challengeId,
        'new_email': newAddress,
        'otp': code,
      });
      if (!mounted) return;
      if (changed.statusCode != 200 || changed.body is! Map || changed.body['success'] != true) {
        _snack(_responseMessage(changed, 'تعذر تأكيد البريد الجديد'));
        return;
      }

      // الخادم أبطل الجلسات لحظة تغيير اعتماد الاستعادة. نمسح النسخة المحلية
      // أيضاً حتى لا يبقى التطبيق في حالة "داخل ظاهرياً" بتوكن مرفوض.
      final repo = Get.find<AuthRepo>();
      repo.removeUserToken();
      repo.removeUserData();
      Get.offAllNamed(RouteHelper.getUnifiedLoginRoute());
      WidgetsBinding.instance.addPostFrameCallback((_) {
        final ctx = Get.context;
        if (ctx == null) return;
        ScaffoldMessenger.of(ctx).showSnackBar(const SnackBar(
          content: Text('تم تغيير البريد وتوثيقه. سجّل الدخول من جديد لحماية الحساب.'),
          backgroundColor: AmialColors.success,
        ));
      });
    } finally {
      if (mounted) setState(() => _changingEmail = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final phone = Get.find<ProfileController>().userInfo?.phone ?? '';
    return GetBuilder<EditProfileController>(builder: (c) {
      return Scaffold(
        backgroundColor: AmialColors.background,
        appBar: AppBar(title: const Text('تعديل بياناتي')),
        body: Form(
          key: _formKey,
          child: ListView(padding: const EdgeInsets.all(16), children: [
            Center(
              child: Stack(clipBehavior: Clip.none, children: [
                GetBuilder<CameraScreenController>(builder: (cam) {
                  final picked = cam.getImage;
                  return CircleAvatar(
                    radius: 56,
                    backgroundColor: AmialColors.primary.withValues(alpha: 0.1),
                    backgroundImage: picked != null ? FileImage(File(picked.path)) : null,
                    child: picked == null
                        ? const Icon(Icons.person, size: 56, color: AmialColors.primary)
                        : null,
                  );
                }),
                Positioned(
                  bottom: -4, right: -4,
                  child: InkWell(
                    onTap: () => Get.find<AuthController>().requestCameraPermission(fromEditProfile: true),
                    child: Container(
                      padding: const EdgeInsets.all(8),
                      decoration: const BoxDecoration(color: AmialColors.primary, shape: BoxShape.circle),
                      child: const Icon(Icons.camera_alt_outlined, size: 18, color: Colors.white),
                    ),
                  ),
                ),
              ]),
            ),
            const SizedBox(height: 20),

            if (phone.isNotEmpty) ...[
              _readOnly('رقم الجوال', phone),
              const SizedBox(height: 12),
            ],
            _field(_firstName, 'الاسم الأول *', required: true),
            const SizedBox(height: 12),
            _field(_lastName, 'الاسم الأخير *', required: true),
            const SizedBox(height: 12),

            _readOnly('البريد الإلكتروني المرتبط بالحساب',
                _email.text.trim().isEmpty ? 'غير مضاف' : _email.text.trim()),
            const SizedBox(height: 8),
            OutlinedButton.icon(
              onPressed: _changingEmail ? null : _changeEmail,
              icon: _changingEmail
                  ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2))
                  : const Icon(Icons.verified_user_outlined),
              label: Text(_email.text.trim().isEmpty ? 'إضافة البريد وتوثيقه' : 'تغيير البريد بطريقة آمنة'),
            ),
            const Padding(
              padding: EdgeInsets.only(top: 6),
              child: Text(
                'لا يمكن تعديل البريد مباشرة؛ يلزم التحقق من البريد الجديد برمز OTP.',
                style: TextStyle(fontSize: 12, color: Colors.black54),
              ),
            ),
            const SizedBox(height: 12),

            _field(_occupation, 'المهنة (اختياري)'),
            const SizedBox(height: 16),

            const Text('الجنس', style: TextStyle(fontSize: 13, fontWeight: FontWeight.w600)),
            const SizedBox(height: 8),
            Row(children: [
              _genderChip(c, 'Male', 'ذكر'),
              const SizedBox(width: 10),
              _genderChip(c, 'Female', 'أنثى'),
            ]),
            const SizedBox(height: 28),

            FilledButton.icon(
              onPressed: c.isLoading || _changingEmail ? null : () => _save(c),
              icon: c.isLoading
                  ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                  : const Icon(Icons.save),
              label: Text(c.isLoading ? 'جارٍ الحفظ…' : 'حفظ البيانات'),
              style: FilledButton.styleFrom(backgroundColor: AmialColors.primary, minimumSize: const Size.fromHeight(52)),
            ),
          ]),
        ),
      );
    });
  }

  Widget _field(TextEditingController ctrl, String label,
      {bool required = false, TextInputType? keyboard, String? Function(String?)? validator}) {
    return TextFormField(
      controller: ctrl,
      keyboardType: keyboard,
      decoration: InputDecoration(labelText: label, filled: true, fillColor: Colors.white, border: const OutlineInputBorder()),
      validator: validator ?? (required ? (v) => (v == null || v.trim().isEmpty) ? 'مطلوب' : null : null),
    );
  }

  Widget _readOnly(String label, String value) => TextFormField(
        initialValue: value,
        readOnly: true,
        textDirection: TextDirection.ltr,
        decoration: InputDecoration(
          labelText: label, filled: true, fillColor: const Color(0xFFF1F3F6),
          border: const OutlineInputBorder(), suffixIcon: const Icon(Icons.lock_outline, size: 18),
        ),
      );

  Widget _genderChip(EditProfileController c, String value, String label) {
    final selected = c.gender == value;
    return Expanded(
      child: InkWell(
        borderRadius: BorderRadius.circular(12),
        onTap: () => c.setGender(value),
        child: Container(
          padding: const EdgeInsets.symmetric(vertical: 12),
          decoration: BoxDecoration(
            color: selected ? AmialColors.primary : Colors.white,
            borderRadius: BorderRadius.circular(12),
            border: Border.all(color: selected ? AmialColors.primary : AmialColors.border),
          ),
          child: Text(label, textAlign: TextAlign.center,
              style: TextStyle(color: selected ? Colors.white : Colors.black87, fontWeight: FontWeight.w600)),
        ),
      ),
    );
  }
}
