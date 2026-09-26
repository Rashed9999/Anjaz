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
import 'package:amial_pay/features/setting/widgets/email_identity_dialog.dart';

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
    setState(() => _changingEmail = true);
    try {
      final changed = await showDialog<bool>(
        context: context,
        barrierDismissible: false,
        builder: (_) => EmailIdentityDialog(initialEmail: _email.text.trim()),
      );
      if (changed != true || !mounted) return;
      final repo = Get.find<AuthRepo>();
      await repo.removeUserToken();
      repo.removeUserData();
      if (!mounted) return;
      Get.offAllNamed(RouteHelper.getUnifiedLoginRoute());
      WidgetsBinding.instance.addPostFrameCallback((_) {
        final ctx = Get.context;
        if (ctx == null) return;
        ScaffoldMessenger.of(ctx).showSnackBar(SnackBar(
          content: Text('email_identity_success'.tr),
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
        appBar: AppBar(title: Text('email_profile_title'.tr)),
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
              _readOnly('email_profile_phone'.tr, phone),
              const SizedBox(height: 12),
            ],
            _field(_firstName, 'email_profile_first_name'.tr, required: true),
            const SizedBox(height: 12),
            _field(_lastName, 'email_profile_last_name'.tr, required: true),
            const SizedBox(height: 12),

            _readOnly('email_identity_linked'.tr,
                _email.text.trim().isEmpty ? 'email_identity_missing'.tr : _email.text.trim()),
            const SizedBox(height: 8),
            OutlinedButton.icon(
              onPressed: _changingEmail ? null : _changeEmail,
              icon: _changingEmail
                  ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2))
                  : const Icon(Icons.verified_user_outlined),
              label: Text(_email.text.trim().isEmpty ? 'email_identity_add'.tr : 'email_identity_manage'.tr),
            ),
            Padding(
              padding: EdgeInsets.only(top: 6),
              child: Text(
                'email_identity_edit_help'.tr,
                style: TextStyle(fontSize: 12, color: Colors.black54),
              ),
            ),
            const SizedBox(height: 12),

            _field(_occupation, 'email_profile_occupation'.tr),
            const SizedBox(height: 16),

            Text('email_profile_gender'.tr, style: TextStyle(fontSize: 13, fontWeight: FontWeight.w600)),
            const SizedBox(height: 8),
            Row(children: [
              _genderChip(c, 'Male', 'email_profile_male'.tr),
              const SizedBox(width: 10),
              _genderChip(c, 'Female', 'email_profile_female'.tr),
            ]),
            const SizedBox(height: 28),

            FilledButton.icon(
              onPressed: c.isLoading || _changingEmail ? null : () => _save(c),
              icon: c.isLoading
                  ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                  : const Icon(Icons.save),
              label: Text(c.isLoading ? 'email_profile_saving'.tr : 'email_profile_save'.tr),
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
      validator: validator ?? (required ? (v) => (v == null || v.trim().isEmpty) ? 'email_profile_required'.tr : null : null),
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
