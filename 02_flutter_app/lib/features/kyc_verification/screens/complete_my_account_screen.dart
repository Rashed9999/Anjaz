import 'dart:io';

import 'package:amial_pay/features/auth/widgets/governorate_picker.dart';
import 'package:amial_pay/features/kyc_verification/controllers/verification_center_controller.dart';
import 'package:amial_pay/features/kyc_verification/domain/customer_verification_level.dart';
import 'package:amial_pay/features/kyc_verification/screens/kyc_verify_screen.dart';
import 'package:amial_pay/features/kyc_verification/screens/customer_verification_review_screen.dart';
import 'package:amial_pay/features/kyc_verification/widgets/yemen_residence_picker.dart';
import 'package:amial_pay/features/setting/controllers/profile_screen_controller.dart';
import 'package:amial_pay/helper/custom_snackbar_helper.dart';
import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:image_picker/image_picker.dart';

/// صفحة واحدة تكمل فقط ما ينقص العميل للمستوى الذي اختاره.
/// لا تُستخدم للتاجر أو الوكيل أو موظف POS أو موظف الإدارة.
class CompleteMyAccountScreen extends StatefulWidget {
  final int targetTier;

  const CompleteMyAccountScreen({super.key, required this.targetTier});

  @override
  State<CompleteMyAccountScreen> createState() => _CompleteMyAccountScreenState();
}

class _CompleteMyAccountScreenState extends State<CompleteMyAccountScreen> {
  final _otp = TextEditingController();
  final _nameEn = TextEditingController();
  final _fatherName = TextEditingController();
  final _grandfatherName = TextEditingController();
  final _district = TextEditingController();
  final _area = TextEditingController();
  final _landmark = TextEditingController();
  final _pepPosition = TextEditingController();
  final _picker = ImagePicker();

  bool _otpRequested = false;
  String? _birthGovernorate;
  YemenResidenceSelection _residenceLocation = const YemenResidenceSelection();
  String? _evidenceType;
  XFile? _residenceEvidence;
  String? _incomeSource;
  String? _accountPurpose;
  bool? _isPep;
  String _ownershipMode = 'restricted_review';
  XFile? _selfie;

  VerificationCenterController get _controller =>
      Get.find<VerificationCenterController>();

  @override
  void initState() {
    super.initState();
    Future.microtask(() async {
      // مصدر الصلاحية والحالة هو مركز التوثيق في الخادم. نحمّله أولاً
      // حتى لا يفشل مسار الترقية بسبب أن ملف Profile الثانوي لم يُحمّل.
      await _controller.load();
      await _controller.loadResidenceOptions();

      // نحتاج Profile فقط لعرض الاسم/الهاتف في بطاقة المراجعة؛ فشله لا
      // يجوز أن يغلق رحلة التوثيق نفسها.
      final profileController = Get.find<ProfileController>();
      if (profileController.userInfo == null) {
        try {
          await profileController.getProfileData();
        } catch (_) {
          // تبقى رحلة KYC عاملة، والبيانات الناقصة ستأتي من مركز التوثيق.
        }
      }
    });
  }

  @override
  void dispose() {
    _otp.dispose();
    _nameEn.dispose();
    _fatherName.dispose();
    _grandfatherName.dispose();
    _district.dispose();
    _area.dispose();
    _landmark.dispose();
    _pepPosition.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: const Color(0xFFF6F8FB),
      appBar: AppBar(title: const Text('إكمال حسابي')),
      body: GetBuilder<VerificationCenterController>(
        builder: (controller) {
          if (controller.isLoading && controller.data == null) {
            return const Center(child: CircularProgressIndicator());
          }
          if (controller.data == null) {
            return Center(
              child: FilledButton(
                onPressed: () => controller.load(),
                child: const Text('إعادة المحاولة'),
              ),
            );
          }

          return RefreshIndicator(
            onRefresh: () => controller.load(),
            child: ListView(
              padding: const EdgeInsets.all(16),
              children: [
                _header(controller),
                const SizedBox(height: 14),
                if (widget.targetTier >= 1 && !controller.phoneVerified) ...[
                  _phoneStep(controller),
                  const SizedBox(height: 12),
                ],
                if (widget.targetTier >= 1 && controller.residenceStatus != 'verified') ...[
                  _residenceStep(controller),
                  const SizedBox(height: 12),
                ],
                if (widget.targetTier >= 2 && controller.currentTier < 1) ...[
                  _lockedStep(
                    'توثيق الهوية',
                    'أكمل إثبات الهاتف واعتماد السكن أولاً. بعد الوصول إلى حالة عميل موثق جزئيا تُفتح خطوة الهوية.',
                  ),
                  const SizedBox(height: 12),
                ] else if (widget.targetTier >= 2 && controller.currentTier < 2) ...[
                  _identityStep(controller),
                  const SizedBox(height: 12),
                ],
                if (widget.targetTier >= 3) ...[
                  if (controller.currentTier < 2)
                    _lockedStep(
                      'التوثيق الكامل',
                      'أكمل توثيق الهوية أولاً. لن نطلب منك متطلبات عميل موثق قبل إنهاء المرحلة السابقة.',
                    )
                  else ...[
                    if (controller.tier3ProfileMissingCodes.isNotEmpty) ...[
                      _fullProfileStep(controller),
                      const SizedBox(height: 12),
                    ],
                    if (controller.tier3Ownership['ready'] != true)
                      _ownershipStep(controller),
                  ],
                ],
                if (_allRequestedStepsComplete(controller)) ...[
                  const SizedBox(height: 4),
                  _completedCard(controller),
                ],
                const SizedBox(height: 28),
              ],
            ),
          );
        },
      ),
    );
  }

  Widget _header(VerificationCenterController controller) {
    final level = controller.levels.firstWhereOrNull(
      (e) => '${e['tier']}' == '${widget.targetTier}',
    );
    return Container(
      padding: const EdgeInsets.all(17),
      decoration: BoxDecoration(
        color: const Color(0xFF173E73),
        borderRadius: BorderRadius.circular(18),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Container(
                width: 11,
                height: 11,
                decoration: BoxDecoration(
                  color: CustomerVerificationLevel
                      .fromTier(widget.targetTier)
                      .color,
                  shape: BoxShape.circle,
                ),
              ),
              const SizedBox(width: 8),
              Expanded(
                child: Text(
                  CustomerVerificationLevel
                      .fromTier(widget.targetTier)
                      .label,
                  style: const TextStyle(
                    color: Colors.white,
                    fontSize: 18,
                    fontWeight: FontWeight.w800,
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 7),
          Text(
            '${level?['description'] ?? 'أكمل البيانات المطلوبة لرفع مستوى حسابك.'}',
            style: const TextStyle(color: Colors.white70, height: 1.5, fontSize: 12.5),
          ),
          const SizedBox(height: 10),
          const Row(
            children: [
              Icon(Icons.filter_alt_outlined, color: Colors.white70, size: 18),
              SizedBox(width: 6),
              Expanded(
                child: Text(
                  'نعرض هنا ما ينقص حسابك فقط؛ الخطوات المكتملة لا تُطلب مرة أخرى.',
                  style: TextStyle(color: Colors.white70, fontSize: 11.5),
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }

  Widget _phoneStep(VerificationCenterController controller) => _stepCard(
        number: 1,
        icon: Icons.phone_android_outlined,
        title: 'إثبات ملكية رقم الهاتف',
        subtitle: 'سنرسل الرمز إلى رقم الهاتف المسجل على حسابك، ولا يمكن تغييره من هذه الخطوة.',
        children: [
          if (!_otpRequested)
            FilledButton.icon(
              onPressed: controller.isActionLoading
                  ? null
                  : () async {
                      if (await controller.requestPhoneOtp() && mounted) {
                        setState(() => _otpRequested = true);
                      }
                    },
              icon: const Icon(Icons.sms_outlined),
              label: const Text('إرسال رمز التحقق'),
            )
          else ...[
            TextField(
              controller: _otp,
              keyboardType: TextInputType.number,
              maxLength: 6,
              textDirection: TextDirection.ltr,
              decoration: const InputDecoration(
                labelText: 'رمز التحقق',
                hintText: '000000',
                border: OutlineInputBorder(),
                counterText: '',
              ),
            ),
            const SizedBox(height: 9),
            Row(
              children: [
                Expanded(
                  child: FilledButton(
                    onPressed: controller.isActionLoading
                        ? null
                        : () {
                            if (_otp.text.trim().length != 6) {
                              showCustomSnackBarHelper('أدخل رمز التحقق المكون من 6 أرقام');
                              return;
                            }
                            controller.verifyPhoneOtp(_otp.text.trim());
                          },
                    child: const Text('تأكيد الرقم'),
                  ),
                ),
                const SizedBox(width: 8),
                TextButton(
                  onPressed: controller.isActionLoading
                      ? null
                      : () => controller.requestPhoneOtp(),
                  child: const Text('إعادة الإرسال'),
                ),
              ],
            ),
          ],
        ],
      );

  Widget _residenceStep(VerificationCenterController controller) {
    final status = controller.residenceStatus;
    if (status == 'pending') {
      return _statusStep(
        2,
        Icons.home_work_outlined,
        'إثبات السكن قيد المراجعة',
        'وصل دليلك إلى فريق المراجعة. لن نطلب منك رفع نسخة أخرى ما لم يطلب المراجع دليلاً إضافياً.',
      );
    }

    final options = controller.residenceOptions;
    return _stepCard(
      number: 2,
      icon: Icons.home_work_outlined,
      title: status == 'needs_more_evidence'
          ? 'إرسال دليل سكن أقوى'
          : 'بيانات الميلاد والسكن الحالي',
      subtitle:
          'محافظة الميلاد بيان تعريفي. محافظة السكن تحدد توفر الخدمات بعد التوثيق، لكنها لا تمنع التسجيل أو اعتماد الحساب.',
      children: [
        GovernoratePicker(
          label: 'محافظة الميلاد',
          value: _birthGovernorate,
          helper: 'لا علاقة لها بنطاق التشغيل.',
          onChanged: (value) => setState(() => _birthGovernorate = value),
        ),
        const SizedBox(height: 10),
        YemenResidencePicker(
          onChanged: (value) => setState(() => _residenceLocation = value),
        ),
        const SizedBox(height: 10),
        _field(_area, 'اسم الحي / المنطقة *'),
        const SizedBox(height: 10),
        _field(_landmark, 'أقرب معلم — اختياري'),
        const SizedBox(height: 10),
        DropdownButtonFormField<String>(
          value: _evidenceType,
          decoration: const InputDecoration(
            labelText: 'نوع دليل السكن',
            border: OutlineInputBorder(),
          ),
          items: options
              .map(
                (item) => DropdownMenuItem<String>(
                  value: '${item['code']}',
                  child: Text('${item['label']}'),
                ),
              )
              .toList(),
          onChanged: (value) => setState(() => _evidenceType = value),
        ),
        if (_evidenceType != null) ...[
          const SizedBox(height: 7),
          Builder(builder: (_) {
            final selected =
                options.firstWhereOrNull((e) => '${e['code']}' == _evidenceType);
            return Text(
              '${selected?['description'] ?? ''}',
              style: const TextStyle(
                fontSize: 11.5,
                height: 1.4,
                color: Color(0xFF667386),
              ),
            );
          }),
        ],
        const SizedBox(height: 11),
        OutlinedButton.icon(
          onPressed: () async {
            final file = await _picker.pickImage(
              source: ImageSource.gallery,
              imageQuality: 88,
            );
            if (file != null && mounted) {
              setState(() => _residenceEvidence = file);
            }
          },
          icon: Icon(
            _residenceEvidence == null
                ? Icons.upload_file
                : Icons.check_circle_outline,
          ),
          label: Text(
            _residenceEvidence == null
                ? 'اختيار صورة واضحة لدليل السكن'
                : 'تم اختيار المستند',
          ),
        ),
        const SizedBox(height: 8),
        FilledButton(
          onPressed: controller.isActionLoading
              ? null
              : () async {
                  if (_birthGovernorate == null ||
                      !_residenceLocation.hasRequired ||
                      _area.text.trim().length < 2 ||
                      _evidenceType == null ||
                      _residenceEvidence == null) {
                    showCustomSnackBarHelper(
                      'أكمل محافظة الميلاد ومحافظة السكن والمديرية واسم الحي ونوع الدليل والمستند',
                    );
                    return;
                  }

                  final selected = options.firstWhereOrNull(
                    (item) => '${item['code']}' == _evidenceType,
                  );
                  final profile = Get.find<ProfileController>().userInfo;

                  await Get.to<bool>(
                    () => CustomerVerificationReviewScreen(
                      targetTier: 1,
                      rows: [
                        VerificationReviewRow(
                          'الاسم الكامل',
                          '${profile?.fName ?? ''} ${profile?.lName ?? ''}'.trim(),
                        ),
                        VerificationReviewRow('رقم الهاتف', profile?.phone ?? ''),
                        VerificationReviewRow('البريد الإلكتروني'.tr, profile?.email ?? ''),
                        VerificationReviewRow('رقم الحساب', profile?.accountNumber ?? ''),
                        VerificationReviewRow(
                          'محافظة الميلاد',
                          GovernoratePicker.nameOf(_birthGovernorate) ??
                              _birthGovernorate!,
                        ),
                        VerificationReviewRow(
                          'محافظة السكن',
                          GovernoratePicker.nameOf(
                                _residenceLocation.governorateCode,
                              ) ??
                              _residenceLocation.governorateCode!,
                        ),
                        VerificationReviewRow(
                          'المديرية',
                          _residenceLocation.districtName ?? '—',
                        ),
                        VerificationReviewRow(
                          'الحي / المنطقة',
                          _area.text.trim(),
                        ),
                        VerificationReviewRow(
                          'أقرب معلم',
                          _landmark.text.trim(),
                        ),
                        VerificationReviewRow(
                          'نوع إثبات السكن',
                          '${selected?['label'] ?? _evidenceType}',
                        ),
                      ],
                      documents: [
                        VerificationReviewDocument(
                          label: 'إثبات محل السكن',
                          path: _residenceEvidence!.path,
                        ),
                      ],
                      onConfirm: () => controller.submitResidence(
                        birthGovernorate: _birthGovernorate!,
                        governorate: _residenceLocation.governorateCode!,
                        districtId: _residenceLocation.districtId!,
                        area: _area.text.trim(),
                        landmark: _landmark.text.trim(),
                        evidenceType: _evidenceType!,
                        evidence: File(_residenceEvidence!.path),
                      ),
                    ),
                  );
                },
          child: const Text('مراجعة وإرسال إثبات السكن'),
        ),
      ],
    );
  }

  Widget _identityStep(VerificationCenterController controller) {
    final status = controller.identityStatus;
    if (status == 'pending_review' || status == 'ready_for_account_review') {
      return _statusStep(
        3,
        Icons.badge_outlined,
        'الهوية قيد المراجعة',
        'تم إرسال وثيقة الهوية. لا نطلب رفعها مرة أخرى أثناء انتظار قرار المراجع.',
      );
    }

    return _stepCard(
      number: 3,
      icon: Icons.badge_outlined,
      title: status == 'rejected' ? 'تصحيح توثيق الهوية' : 'توثيق الهوية القانونية',
      subtitle: 'حالة عميل موثق بهوية تحتاج رقم الهوية ووجه الوثيقة وظهرها فقط. لا سيلفي في هذه المرحلة.',
      children: [
        FilledButton.icon(
          onPressed: () async {
            await Get.to(() => const KycVerifyScreen());
            await controller.load(silent: true);
          },
          icon: const Icon(Icons.document_scanner_outlined),
          label: Text(status == 'rejected' ? 'إعادة رفع الهوية' : 'إكمال بيانات الهوية'),
        ),
      ],
    );
  }

  Widget _fullProfileStep(VerificationCenterController controller) {
    final missing = controller.tier3ProfileMissingCodes.toSet();
    return _stepCard(
      number: 4,
      icon: Icons.assignment_ind_outlined,
      title: 'بيانات اعرف عميلك',
      subtitle: 'نعرض الحقول الناقصة فقط. البيانات الموجودة في حسابك لا نطلبها مرة أخرى.',
      children: [
        if (missing.contains('name_en')) _field(_nameEn, 'الاسم بالإنجليزية'),
        if (missing.contains('father_name')) _field(_fatherName, 'اسم الأب'),
        if (missing.contains('grandfather_name')) _field(_grandfatherName, 'اسم الجد'),
        if (missing.contains('residence_district')) _field(_district, 'المديرية'),
        if (missing.contains('income_source'))
          _optionDropdown(
            label: 'مصدر الدخل',
            value: _incomeSource,
            options: controller.incomeSourceOptions,
            onChanged: (v) => setState(() => _incomeSource = v),
          ),
        if (missing.contains('account_purpose'))
          _optionDropdown(
            label: 'الغرض من فتح الحساب',
            value: _accountPurpose,
            options: controller.accountPurposeOptions,
            onChanged: (v) => setState(() => _accountPurpose = v),
          ),
        if (missing.contains('is_pep')) ...[
          const Text('هل أنت شخص ذو صفة سياسية بارزة (PEP)؟', style: TextStyle(fontWeight: FontWeight.w700)),
          const SizedBox(height: 6),
          SegmentedButton<bool>(
            segments: const [
              ButtonSegment(value: false, label: Text('لا')),
              ButtonSegment(value: true, label: Text('نعم')),
            ],
            selected: _isPep == null ? <bool>{} : {_isPep!},
            emptySelectionAllowed: true,
            onSelectionChanged: (value) => setState(() {
              _isPep = value.isEmpty ? null : value.first;
            }),
          ),
          const SizedBox(height: 10),
        ],
        if (missing.contains('pep_position') || _isPep == true)
          _field(_pepPosition, 'المنصب/الصفة السياسية'),
        FilledButton(
          onPressed: controller.isActionLoading ? null : () => _submitProfile(controller, missing),
          child: const Text('حفظ البيانات الناقصة'),
        ),
      ],
    );
  }

  Widget _ownershipStep(VerificationCenterController controller) {
    final blockers = controller.tier3Ownership['blockers'];
    final blockerList =
        blockers is List ? blockers.map((e) => e.toString()).toList() : <String>[];

    return _stepCard(
      number: 5,
      icon: Icons.privacy_tip_outlined,
      title: 'إثبات صاحب الهوية',
      subtitle:
          'حالة عميل موثق تحتاج صورة سيلفي حديثة من الكاميرا بعد اكتمال السكن والهوية. لا نطلب إعادة رفع المستندات المعتمدة.',
      children: [
        if (blockerList.isNotEmpty) ...[
          Container(
            padding: const EdgeInsets.all(10),
            decoration: BoxDecoration(
              color: const Color(0xFFFFF7E8),
              borderRadius: BorderRadius.circular(10),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: blockerList
                  .map(
                    (e) => Padding(
                      padding: const EdgeInsets.only(bottom: 3),
                      child: Text(
                        '• $e',
                        style: const TextStyle(fontSize: 11.5),
                      ),
                    ),
                  )
                  .toList(),
            ),
          ),
          const SizedBox(height: 10),
        ],
        _ownershipOption(
          'restricted_review',
          'خصوصية إضافية',
          'السيلفي يراه فقط مراجع KYC المقيد، مع Trace ID وعلامة مائية.',
        ),
        _ownershipOption(
          'standard',
          'مراجعة محمية',
          'السيلفي يراجعه فريق KYC المخول مع التتبع والعلامة المائية.',
        ),
        const SizedBox(height: 8),
        OutlinedButton.icon(
          onPressed: () async {
            final image = await _picker.pickImage(
              source: ImageSource.camera,
              imageQuality: 90,
            );
            if (image != null && mounted) {
              setState(() => _selfie = image);
            }
          },
          icon: Icon(
            _selfie == null
                ? Icons.photo_camera_outlined
                : Icons.check_circle_outline,
          ),
          label: Text(
            _selfie == null
                ? 'التقاط صورة سيلفي حديثة'
                : 'تم التقاط السيلفي',
          ),
        ),
        const SizedBox(height: 8),
        FilledButton(
          onPressed: controller.isActionLoading
              ? null
              : () async {
                  if (_selfie == null) {
                    showCustomSnackBarHelper('التقط صورة سيلفي حديثة أولاً');
                    return;
                  }

                  final profile = Get.find<ProfileController>().userInfo;
                  await Get.to<bool>(
                    () => CustomerVerificationReviewScreen(
                      targetTier: 3,
                      rows: [
                        VerificationReviewRow(
                          'الاسم الكامل',
                          '${profile?.fName ?? ''} ${profile?.lName ?? ''}'.trim(),
                        ),
                        VerificationReviewRow(
                          'رقم الهاتف',
                          profile?.phone ?? '',
                        ),
                        VerificationReviewRow(
                          'البريد الإلكتروني',
                          profile?.email ?? '',
                        ),
                        VerificationReviewRow(
                          'رقم الحساب',
                          profile?.accountNumber ?? '',
                        ),
                        const VerificationReviewRow(
                          'توثيق السكن',
                          'مكتمل ومعتمد',
                        ),
                        const VerificationReviewRow(
                          'توثيق الهوية',
                          'مكتمل ومعتمد من الجهتين',
                        ),
                        VerificationReviewRow(
                          'مراجعة السيلفي',
                          _ownershipMode == 'restricted_review'
                              ? 'خصوصية إضافية'
                              : 'مراجعة محمية',
                        ),
                      ],
                      documents: [
                        VerificationReviewDocument(
                          label: 'صورة السيلفي الحديثة',
                          path: _selfie!.path,
                        ),
                      ],
                      onConfirm: () => controller.submitOwnershipSelfie(
                        mode: _ownershipMode,
                        selfie: File(_selfie!.path),
                      ),
                    ),
                  );
                },
          child: const Text('مراجعة وإرسال طلب التوثيق الكامل'),
        ),
      ],
    );
  }

  Widget _ownershipOption(
    String value,
    String title,
    String subtitle, {
    bool enabled = true,
  }) {
    return Opacity(
      opacity: enabled ? 1 : 0.55,
      child: RadioListTile<String>(
        contentPadding: EdgeInsets.zero,
        value: value,
        groupValue: _ownershipMode,
        onChanged: enabled ? (v) => setState(() => _ownershipMode = v ?? _ownershipMode) : null,
        title: Text(title, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 13.5)),
        subtitle: Text(subtitle, style: const TextStyle(fontSize: 11.2, height: 1.4)),
      ),
    );
  }

  Future<void> _submitProfile(
    VerificationCenterController controller,
    Set<String> missing,
  ) async {
    final fields = <String, dynamic>{};

    void addText(String code, TextEditingController input) {
      if (!missing.contains(code)) return;
      final value = input.text.trim();
      if (value.isNotEmpty) fields[code] = value;
    }

    addText('name_en', _nameEn);
    addText('father_name', _fatherName);
    addText('grandfather_name', _grandfatherName);
    addText('residence_district', _district);
    addText('pep_position', _pepPosition);

    if (missing.contains('income_source') && _incomeSource != null) {
      fields['income_source'] = _incomeSource;
    }
    if (missing.contains('account_purpose') && _accountPurpose != null) {
      fields['account_purpose'] = _accountPurpose;
    }
    if (missing.contains('is_pep') && _isPep != null) {
      fields['is_pep'] = _isPep;
      if (_isPep == true && _pepPosition.text.trim().isNotEmpty) {
        fields['pep_position'] = _pepPosition.text.trim();
      }
    }

    if (_isPep == true && _pepPosition.text.trim().isEmpty) {
      showCustomSnackBarHelper('اكتب المنصب أو الصفة السياسية قبل الحفظ');
      return;
    }

    final expected = missing.where((code) => code != 'pep_position' || _isPep != false).length;
    if (fields.length < expected) {
      showCustomSnackBarHelper('أكمل الحقول الظاهرة قبل الحفظ');
      return;
    }

    await controller.updateFullProfile(fields);
  }

  Widget _optionDropdown({
    required String label,
    required String? value,
    required List<Map<String, dynamic>> options,
    required ValueChanged<String?> onChanged,
  }) => Padding(
        padding: const EdgeInsets.only(bottom: 10),
        child: DropdownButtonFormField<String>(
          value: value,
          decoration: InputDecoration(labelText: label, border: const OutlineInputBorder()),
          items: options
              .map((e) => DropdownMenuItem<String>(value: '${e['code']}', child: Text('${e['label']}')))
              .toList(),
          onChanged: onChanged,
        ),
      );

  Widget _field(TextEditingController controller, String label) => Padding(
        padding: const EdgeInsets.only(bottom: 10),
        child: TextField(
          controller: controller,
          decoration: InputDecoration(labelText: label, border: const OutlineInputBorder()),
        ),
      );

  Widget _stepCard({
    required int number,
    required IconData icon,
    required String title,
    required String subtitle,
    required List<Widget> children,
  }) => Container(
        padding: const EdgeInsets.all(15),
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(17),
          border: Border.all(color: const Color(0xFFE0E5EC)),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Container(
                  width: 34,
                  height: 34,
                  alignment: Alignment.center,
                  decoration: const BoxDecoration(color: Color(0xFFE9F0F9), shape: BoxShape.circle),
                  child: Text('$number', style: const TextStyle(fontWeight: FontWeight.w800)),
                ),
                const SizedBox(width: 9),
                Icon(icon, color: const Color(0xFF315F95), size: 22),
                const SizedBox(width: 8),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(title, style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15)),
                      const SizedBox(height: 3),
                      Text(subtitle, style: const TextStyle(fontSize: 11.8, height: 1.45, color: Color(0xFF657184))),
                    ],
                  ),
                ),
              ],
            ),
            const SizedBox(height: 14),
            ...children,
          ],
        ),
      );

  Widget _statusStep(int number, IconData icon, String title, String subtitle) =>
      _stepCard(
        number: number,
        icon: icon,
        title: title,
        subtitle: subtitle,
        children: const [
          LinearProgressIndicator(minHeight: 5),
        ],
      );

  Widget _lockedStep(String title, String subtitle) => _stepCard(
        number: 4,
        icon: Icons.lock_outline,
        title: title,
        subtitle: subtitle,
        children: const [],
      );

  bool _allRequestedStepsComplete(VerificationCenterController c) {
    if (widget.targetTier == 1) return c.currentTier >= 1;
    if (widget.targetTier == 2) return c.currentTier >= 2;
    return c.currentTier >= 3;
  }

  Widget _completedCard(VerificationCenterController controller) => Container(
        padding: const EdgeInsets.all(15),
        decoration: BoxDecoration(
          color: const Color(0xFFEAF7EF),
          borderRadius: BorderRadius.circular(15),
          border: Border.all(color: const Color(0xFFC7E6D2)),
        ),
        child: const Row(
          children: [
            Icon(Icons.verified_outlined, color: Color(0xFF177848)),
            SizedBox(width: 9),
            Expanded(
              child: Text(
                'متطلبات حالة التوثيق المطلوبة مكتملة. إذا كانت هناك مراجعة بشرية معلقة فستتغير الحدود بعد اعتمادها.',
                style: TextStyle(fontSize: 12.5, height: 1.4),
              ),
            ),
          ],
        ),
      );
}
