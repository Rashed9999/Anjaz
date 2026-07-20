import 'dart:io';
import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/data/api/api_client.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';
import 'package:amyal_pay/features/setting/domain/models/profile_model.dart';
import 'package:amyal_pay/features/auth/controllers/auth_controller.dart';
import 'package:amyal_pay/features/setting/controllers/edit_profile_controller.dart';
import 'package:amyal_pay/features/setting/controllers/profile_screen_controller.dart';
import 'package:amyal_pay/features/camera_verification/controllers/camera_screen_controller.dart';

/// AMIAL-PROFILE-EDIT-001 — «تعديل بياناتي» (أُعيد تصميمها بأسلوب أميال باي).
///
/// النسخة الأصلية كانت شاشة 6cash قديمة تعتمد على روابط إعداد قد لا تُملأ في
/// أميال باي (تُظهر شاشة رمادية/فارغة عند null). هذه نسخة متينة: بلا كسر عند
/// نقص البيانات، تعيد استخدام EditProfileController (منطق الحفظ نفسه).
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

  @override
  void initState() {
    super.initState();
    final info = Get.find<ProfileController>().userInfo; // قد يكون null — نتعامل بأمان
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
      SnackBar(content: Text(m), backgroundColor: ok ? const Color(0xFF2E7D32) : AmyalColors.red));

  Future<void> _save(EditProfileController c) async {
    if (!_formKey.currentState!.validate()) return;
    final image = Get.find<CameraScreenController>().getImage;
    final body = ProfileModel(
      fName: _firstName.text.trim(),
      lName: _lastName.text.trim(),
      gender: c.gender,
      occupation: _occupation.text.trim(),
      email: _email.text.trim(),
    );
    final multipart = image != null ? [MultipartBody('image', image)] : <MultipartBody>[];
    final ok = await c.updateProfileData(body, multipart);
    if (!mounted) return;
    if (ok) {
      Get.find<ProfileController>().getProfileData(reload: true);
      _snack('تم حفظ البيانات', ok: true);
      Get.back();
    } else {
      _snack('تعذّر حفظ البيانات — تحقّق من الاتصال');
    }
  }

  @override
  Widget build(BuildContext context) {
    final phone = Get.find<ProfileController>().userInfo?.phone ?? '';
    return GetBuilder<EditProfileController>(builder: (c) {
      return Scaffold(
        backgroundColor: AmyalColors.background,
        appBar: AppBar(
          title: const Text('تعديل بياناتي'),
          backgroundColor: AmyalColors.primary,
          foregroundColor: Colors.white,
        ),
        body: Form(
          key: _formKey,
          child: ListView(padding: const EdgeInsets.all(16), children: [
            // الصورة الرمزية + زر الكاميرا
            Center(
              child: Stack(clipBehavior: Clip.none, children: [
                GetBuilder<CameraScreenController>(builder: (cam) {
                  final picked = cam.getImage;
                  return CircleAvatar(
                    radius: 56,
                    backgroundColor: AmyalColors.primary.withValues(alpha: 0.1),
                    backgroundImage: picked != null ? FileImage(File(picked.path)) : null,
                    child: picked == null
                        ? const Icon(Icons.person, size: 56, color: AmyalColors.primary)
                        : null,
                  );
                }),
                Positioned(
                  bottom: -4, right: -4,
                  child: InkWell(
                    onTap: () => Get.find<AuthController>().requestCameraPermission(fromEditProfile: true),
                    child: Container(
                      padding: const EdgeInsets.all(8),
                      decoration: const BoxDecoration(color: AmyalColors.primary, shape: BoxShape.circle),
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
            _field(_email, 'البريد الإلكتروني (اختياري)', keyboard: TextInputType.emailAddress, validator: (v) {
              final s = (v ?? '').trim();
              if (s.isEmpty) return null;
              return RegExp(r'^[^@\s]+@[^@\s]+\.[^@\s]+$').hasMatch(s) ? null : 'بريد غير صحيح';
            }),
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
              onPressed: c.isLoading ? null : () => _save(c),
              icon: c.isLoading
                  ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                  : const Icon(Icons.save),
              label: Text(c.isLoading ? 'جارٍ الحفظ…' : 'حفظ البيانات'),
              style: FilledButton.styleFrom(backgroundColor: AmyalColors.primary, minimumSize: const Size.fromHeight(52)),
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
            color: selected ? AmyalColors.primary : Colors.white,
            borderRadius: BorderRadius.circular(12),
            border: Border.all(color: selected ? AmyalColors.primary : AmyalColors.border),
          ),
          child: Text(label, textAlign: TextAlign.center,
              style: TextStyle(color: selected ? Colors.white : Colors.black87, fontWeight: FontWeight.w600)),
        ),
      ),
    );
  }
}
