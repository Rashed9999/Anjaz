import 'package:flutter/material.dart';
import 'package:amial_pay/data/api/api_client.dart';
import 'package:get/get.dart';
import 'package:amial_pay/features/kyc_verification/controllers/kyc_verify_controller.dart';
import 'package:amial_pay/features/kyc_verification/screens/customer_verification_review_screen.dart';
import 'package:amial_pay/features/kyc_verification/widgets/dotted_border_widget.dart';
import 'package:amial_pay/features/setting/controllers/profile_screen_controller.dart';
import 'package:amial_pay/util/styles.dart';
import 'package:amial_pay/common/widgets/custom_button_widget.dart';
import 'package:amial_pay/common/widgets/custom_drop_down_button_widget.dart';
import 'package:amial_pay/helper/custom_snackbar_helper.dart';
import 'package:amial_pay/common/widgets/custom_text_field_widget.dart';
import '../../../util/dimensions.dart';

/// AMIAL-PROGRESSIVE-KYC-UPGRADE-APP-002
///
/// هذه الشاشة تخص حالة «عميل موثق بهوية»: إثبات الهوية القانونية.
/// إثبات الإقامة له مساره المستقل، وإثبات صاحب الهوية الأقوى
/// (Liveness/Face Match أو مسار حضوري/مقيد) يخص حالة «عميل موثق».
/// يرفع حدوده من المحفظة الأساسية.
class KycVerifyScreen extends StatefulWidget {
  const KycVerifyScreen({super.key});

  @override
  State<KycVerifyScreen> createState() => _KycVerifyScreenState();
}

class _KycVerifyScreenState extends State<KycVerifyScreen> {
  final TextEditingController _identityNumberController = TextEditingController();
  final TextEditingController _dateOfBirthController = TextEditingController();
  final TextEditingController _idPlaceOfIssueController = TextEditingController();
  final TextEditingController _issueDateController = TextEditingController();
  final TextEditingController _expiryDateController = TextEditingController();

  // حق خصوصية للجميع، لا مسار مبني على جنس المستخدم.
  String _reviewMode = 'standard';

  @override
  void initState() {
    super.initState();
    Get.find<KycVerifyController>().initialSelect();
  }

  @override
  void dispose() {
    _identityNumberController.dispose();
    _dateOfBirthController.dispose();
    _idPlaceOfIssueController.dispose();
    _issueDateController.dispose();
    _expiryDateController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: const Color(0xFFF7F8FB),
      appBar: AppBar(
        title: const Text('ترقية توثيق الهوية'),
        elevation: 0,
      ),
      body: GetBuilder<KycVerifyController>(
        builder: (controller) => SingleChildScrollView(
          padding: const EdgeInsets.all(Dimensions.paddingSizeDefault),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              _tierHeader(context),
              const SizedBox(height: 14),
              _card([
                _sectionTitle(
                  Icons.badge_outlined,
                  'بيانات الهوية',
                  'أدخل بيانات الوثيقة كما هي مكتوبة عليها: الرقم، الميلاد، مكان الإصدار، وتواريخ الإصدار والانتهاء.',
                ),
                CustomDropDownButtonWidget(
                  value: controller.dropDownSelectedValue,
                  itemList: controller.dropList,
                  onChanged: (value) => controller.dropDownChange(value!),
                ),
                const SizedBox(height: Dimensions.fontSizeDefault),
                CustomTextFieldWidget(
                  controller: _identityNumberController,
                  isShowBorder: true,
                  maxLines: 1,
                  hintText: 'identity_number'.tr,
                ),
                const SizedBox(height: 12),
                _dateField(
                  controller: _dateOfBirthController,
                  label: 'تاريخ الميلاد',
                  firstDate: DateTime(1900),
                  lastDate: DateTime.now(),
                ),
                const SizedBox(height: 12),
                CustomTextFieldWidget(
                  controller: _idPlaceOfIssueController,
                  isShowBorder: true,
                  maxLines: 1,
                  hintText: 'مكان إصدار الهوية',
                ),
                const SizedBox(height: 12),
                _dateField(
                  controller: _issueDateController,
                  label: 'تاريخ إصدار الهوية',
                  firstDate: DateTime(1950),
                  lastDate: DateTime.now(),
                ),
                const SizedBox(height: 12),
                _dateField(
                  controller: _expiryDateController,
                  label: 'تاريخ انتهاء الهوية',
                  firstDate: DateTime.now().add(const Duration(days: 1)),
                  lastDate: DateTime.now().add(const Duration(days: 365 * 20)),
                ),
              ]),
              const SizedBox(height: 14),
              _card([
                _sectionTitle(
                  Icons.document_scanner_outlined,
                  'صورتا الوثيقة',
                  'ارفع وجه الهوية وظهرها. السيلفي غير مطلوب في هذه المرحلة ويظهر فقط عند طلب حالة عميل موثق.',
                ),
                _privacyNotice(),
                const SizedBox(height: 12),
                _documentStrip(controller),
                const SizedBox(height: 8),
                Row(
                  children: [
                    _legendDot('1', 'وجه الهوية'),
                    const SizedBox(width: 10),
                    _legendDot('2', 'ظهر الهوية'),
                  ],
                ),
              ]),
              const SizedBox(height: 14),
              _card([
                _sectionTitle(
                  Icons.privacy_tip_outlined,
                  'خصوصية المراجعة',
                  'اختر من يستطيع مراجعة وثائقك. هذا لا يغيّر متطلبات إثبات الهوية ولا يضعفها.',
                ),
                _privacyOption(
                  value: 'standard',
                  title: 'مراجعة محمية',
                  subtitle:
                      'الوثائق مشفّرة، وكل مشاهدة من لوحة أميال تُعرض بنسخة تحمل علامة مائية وTrace ID مرتبطين بالموظف.',
                  icon: Icons.verified_user_outlined,
                ),
                _privacyOption(
                  value: 'restricted_review',
                  title: 'خصوصية إضافية',
                  subtitle:
                      'تنتقل القضية إلى طابور مقيد ولا يستطيع رؤية الوثائق إلا مراجع يملك صلاحية KYC المقيدة.',
                  icon: Icons.lock_person_outlined,
                  emphasized: true,
                ),
                const SizedBox(height: 4),
                Container(
                  padding: const EdgeInsets.all(12),
                  decoration: BoxDecoration(
                    color: const Color(0xFFF4F7FB),
                    borderRadius: BorderRadius.circular(12),
                    border: Border.all(color: const Color(0xFFDDE5EF)),
                  ),
                  child: const Row(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Icon(
                        Icons.face_retouching_natural,
                        size: 20,
                        color: Color(0xFF5F6B7C),
                      ),
                      SizedBox(width: 8),
                      Expanded(
                        child: Text(
                          'Liveness + Face Match والتحقق الحضوري ليست مطلوبة لحالة عميل موثق بهوية. تظهر كخيارات إثبات أقوى عند طلب حالة عميل موثق بعد ربط مزود بيومتري حقيقي ومعتمد.',
                          style: TextStyle(
                            fontSize: 12.5,
                            height: 1.55,
                            color: Color(0xFF5F6B7C),
                          ),
                        ),
                      ),
                    ],
                  ),
                ),
              ]),
              const SizedBox(height: 14),
              _separationCard(),
              const SizedBox(height: 20),
              controller.isLoading
                  ? const Center(child: CircularProgressIndicator())
                  : CustomButtonWidget(
                      buttonText: 'إرسال الهوية للمراجعة',
                      color: Theme.of(context).primaryColor,
                      onTap: () => _submit(controller),
                    ),
              const SizedBox(height: 24),
            ],
          ),
        ),
      ),
    );
  }

  Widget _dateField({
    required TextEditingController controller,
    required String label,
    required DateTime firstDate,
    required DateTime lastDate,
  }) {
    return TextField(
      controller: controller,
      readOnly: true,
      decoration: InputDecoration(
        labelText: label,
        border: const OutlineInputBorder(),
        suffixIcon: const Icon(Icons.calendar_month_outlined),
      ),
      onTap: () async {
        final current = DateTime.tryParse(controller.text);
        var initial = current ?? lastDate;
        if (initial.isBefore(firstDate)) initial = firstDate;
        if (initial.isAfter(lastDate)) initial = lastDate;

        final selected = await showDatePicker(
          context: context,
          initialDate: initial,
          firstDate: firstDate,
          lastDate: lastDate,
        );
        if (selected != null && mounted) {
          controller.text =
              '${selected.year.toString().padLeft(4, '0')}-'
              '${selected.month.toString().padLeft(2, '0')}-'
              '${selected.day.toString().padLeft(2, '0')}';
          setState(() {});
        }
      },
    );
  }

  Widget _tierHeader(BuildContext context) {
    final primary = Theme.of(context).primaryColor;
    return Container(
      padding: const EdgeInsets.all(18),
      decoration: BoxDecoration(
        color: primary,
        borderRadius: BorderRadius.circular(20),
      ),
      child: const Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Icon(Icons.shield_outlined, color: Colors.white),
              SizedBox(width: 8),
              Text(
                'عميل موثق بهوية',
                style: TextStyle(
                  color: Colors.white,
                  fontSize: 18,
                  fontWeight: FontWeight.w800,
                ),
              ),
            ],
          ),
          SizedBox(height: 10),
          Text(
            'هذه ترقية للحساب القائم وليست إعادة تسجيل. بعد اعتماد الوثيقة ترتفع حدودك وفق سياسة أميال الحالية.',
            style: TextStyle(color: Colors.white, height: 1.55, fontSize: 13),
          ),
        ],
      ),
    );
  }

  Widget _card(List<Widget> children) => Container(
        padding: const EdgeInsets.all(16),
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(18),
          border: Border.all(color: const Color(0xFFE5E9EF)),
          boxShadow: const [
            BoxShadow(
              color: Color(0x0A000000),
              blurRadius: 18,
              offset: Offset(0, 6),
            ),
          ],
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: children,
        ),
      );

  Widget _sectionTitle(IconData icon, String title, String subtitle) => Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Icon(icon, size: 22, color: const Color(0xFF2F5E92)),
              const SizedBox(width: 8),
              Expanded(
                child: Text(
                  title,
                  style: rubikRegular.copyWith(
                    fontWeight: FontWeight.w800,
                    fontSize: 16,
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 6),
          Text(
            subtitle,
            style: const TextStyle(
              fontSize: 12.5,
              height: 1.5,
              color: Color(0xFF5F6B7C),
            ),
          ),
          const SizedBox(height: 14),
        ],
      );

  Widget _privacyNotice() => Container(
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(
          color: const Color(0xFFEFF8F2),
          borderRadius: BorderRadius.circular(12),
          border: Border.all(color: const Color(0xFFCDE8D6)),
        ),
        child: const Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Icon(Icons.no_photography_outlined, color: Color(0xFF177848), size: 20),
            SizedBox(width: 8),
            Expanded(
              child: Text(
                'لا نطلب سيلفي في حالة عميل موثق بهوية. صور الوثيقة تُخزّن مشفّرة ولا تُنسخ إلى مخزن الهوية القديم.',
                style: TextStyle(fontSize: 12.5, height: 1.5, color: Color(0xFF315D43)),
              ),
            ),
          ],
        ),
      );

  Widget _documentStrip(KycVerifyController controller) {
    final canAdd = controller.identityImage.length < 2;
    return SizedBox(
      height: 108,
      child: ListView.separated(
        scrollDirection: Axis.horizontal,
        itemCount: controller.identityImage.length + (canAdd ? 1 : 0),
        separatorBuilder: (_, __) => const SizedBox(width: 12),
        itemBuilder: (context, index) {
          if (index >= controller.identityImage.length) {
            return const DottedBorderWidget(path: null);
          }

          return Stack(
            children: [
              DottedBorderWidget(path: controller.identityImage[index].path),
              PositionedDirectional(
                bottom: 2,
                end: 2,
                child: InkWell(
                  onTap: () => controller.removeImage(index),
                  borderRadius: BorderRadius.circular(18),
                  child: Container(
                    padding: const EdgeInsets.all(6),
                    decoration: BoxDecoration(
                      color: Colors.red.withValues(alpha: 0.88),
                      shape: BoxShape.circle,
                    ),
                    child: const Icon(Icons.delete_outline, color: Colors.white, size: 16),
                  ),
                ),
              ),
              PositionedDirectional(
                top: 4,
                start: 4,
                child: Container(
                  width: 24,
                  height: 24,
                  alignment: Alignment.center,
                  decoration: const BoxDecoration(
                    color: Colors.black87,
                    shape: BoxShape.circle,
                  ),
                  child: Text(
                    '${index + 1}',
                    style: const TextStyle(
                      color: Colors.white,
                      fontWeight: FontWeight.w800,
                      fontSize: 11,
                    ),
                  ),
                ),
              ),
            ],
          );
        },
      ),
    );
  }

  Widget _legendDot(String number, String label) => Expanded(
        child: Container(
          padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 9),
          decoration: BoxDecoration(
            color: const Color(0xFFF7F8FB),
            borderRadius: BorderRadius.circular(10),
          ),
          child: Row(
            children: [
              Container(
                width: 22,
                height: 22,
                alignment: Alignment.center,
                decoration: const BoxDecoration(
                  color: Color(0xFF2F5E92),
                  shape: BoxShape.circle,
                ),
                child: Text(
                  number,
                  style: const TextStyle(
                    color: Colors.white,
                    fontWeight: FontWeight.w700,
                    fontSize: 11,
                  ),
                ),
              ),
              const SizedBox(width: 7),
              Expanded(
                child: Text(label, style: const TextStyle(fontSize: 12.5)),
              ),
            ],
          ),
        ),
      );

  Widget _privacyOption({
    required String value,
    required String title,
    required String subtitle,
    required IconData icon,
    bool emphasized = false,
  }) {
    final selected = _reviewMode == value;
    final primary = Theme.of(context).primaryColor;
    return InkWell(
      onTap: () => setState(() => _reviewMode = value),
      borderRadius: BorderRadius.circular(12),
      child: Container(
        margin: const EdgeInsets.only(bottom: 9),
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(
          color: selected ? primary.withValues(alpha: 0.06) : Colors.white,
          borderRadius: BorderRadius.circular(12),
          border: Border.all(
            color: selected
                ? primary
                : (emphasized
                    ? const Color(0xFF9EB8D8)
                    : const Color(0xFFE1E6EC)),
            width: selected ? 1.6 : 1,
          ),
        ),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Icon(icon, color: selected ? primary : const Color(0xFF5F6B7C), size: 23),
            const SizedBox(width: 10),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      Expanded(
                        child: Text(
                          title,
                          style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 14),
                        ),
                      ),
                      if (emphasized)
                        const Text(
                          'وصول مقيد',
                          style: TextStyle(fontSize: 10.5, color: Color(0xFF365F91)),
                        ),
                    ],
                  ),
                  const SizedBox(height: 4),
                  Text(
                    subtitle,
                    style: const TextStyle(
                      fontSize: 12,
                      height: 1.45,
                      color: Color(0xFF5F6B7C),
                    ),
                  ),
                ],
              ),
            ),
            Radio<String>(
              value: value,
              groupValue: _reviewMode,
              onChanged: (v) => setState(() => _reviewMode = v ?? 'standard'),
            ),
          ],
        ),
      ),
    );
  }

  Widget _separationCard() => Container(
        padding: const EdgeInsets.all(14),
        decoration: BoxDecoration(
          color: const Color(0xFFFFFBF1),
          borderRadius: BorderRadius.circular(16),
          border: Border.all(color: const Color(0xFFF0E1B7)),
        ),
        child: const Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              'ما الذي لا نطلبه هنا؟',
              style: TextStyle(fontWeight: FontWeight.w800, fontSize: 14),
            ),
            SizedBox(height: 7),
            Text(
              '• إثبات السكن: اكتمل في حالة التوثيق السابقة ولا نعيد طلبه هنا.\n'
              '• السيلفي: ليس مطلوباً لحالة عميل موثق بهوية؛ يظهر عند طلب حالة عميل موثق.\n'
              '• الدخل وPEP والملف التنظيمي الكامل: تُستكمل عند طلب حالة عميل موثق.',
              style: TextStyle(fontSize: 12.5, height: 1.65, color: Color(0xFF655A3D)),
            ),
          ],
        ),
      );

  Future<void> _submit(KycVerifyController controller) async {
    if (_identityNumberController.text.trim().length < 5) {
      showCustomSnackBarHelper('أدخل رقم هوية صحيحاً');
      return;
    }
    if (controller.dropDownSelectedValue == controller.dropList.first) {
      showCustomSnackBarHelper('select_identity_type'.tr);
      return;
    }
    if (_dateOfBirthController.text.isEmpty ||
        _idPlaceOfIssueController.text.trim().length < 2 ||
        _issueDateController.text.isEmpty ||
        _expiryDateController.text.isEmpty) {
      showCustomSnackBarHelper(
        'أكمل تاريخ الميلاد ومكان الإصدار وتاريخ الإصدار والانتهاء',
      );
      return;
    }
    if (controller.identityImage.length != 2) {
      showCustomSnackBarHelper('ارفع صورتين بالترتيب: وجه الهوية ثم ظهرها');
      return;
    }

    final profile = Get.find<ProfileController>().userInfo;
    Map<String, dynamic> residence = <String, dynamic>{};
    try {
      final response = await Get.find<ApiClient>()
          .getData('/api/v1/amial/me/kyc/residence');
      if (response.statusCode == 200 &&
          response.body is Map &&
          response.body['data'] is Map) {
        residence = Map<String, dynamic>.from(response.body['data'] as Map);
      }
    } catch (_) {
      // المراجعة تستمر بالبيانات المتاحة؛ لا نمنع رفع الهوية بسبب فشل عرض إضافي.
    }

    final type = controller.dropDownSelectedValue;
    final typeLabel = switch (type) {
      'nid' => 'بطاقة هوية',
      'passport' => 'جواز سفر',
      'driving_licence' => 'رخصة قيادة',
      _ => type,
    };

    final confirmed = await Get.to<bool>(
      () => CustomerVerificationReviewScreen(
        targetTier: 2,
        rows: [
          VerificationReviewRow(
            'الاسم الكامل',
            '${profile?.fName ?? ''} ${profile?.lName ?? ''}'.trim(),
          ),
          VerificationReviewRow('رقم الهاتف', profile?.phone ?? ''),
          VerificationReviewRow('البريد الإلكتروني', profile?.email ?? ''),
          VerificationReviewRow('رقم الحساب', profile?.accountNumber ?? ''),
          VerificationReviewRow(
            'محافظة الميلاد',
            residence['birth_governorate_name']?.toString() ?? '',
          ),
          VerificationReviewRow(
            'محافظة السكن',
            residence['verified_governorate_name']?.toString() ??
                residence['declared_governorate_name']?.toString() ??
                '',
          ),
          VerificationReviewRow(
            'المديرية',
            residence['residence_district']?.toString() ?? '',
          ),
          VerificationReviewRow(
            'الحي / المنطقة',
            residence['residence_area']?.toString() ?? '',
          ),
          VerificationReviewRow('نوع الهوية', typeLabel),
          VerificationReviewRow(
            'رقم الهوية',
            _identityNumberController.text.trim(),
          ),
          VerificationReviewRow(
            'تاريخ الميلاد',
            _dateOfBirthController.text,
          ),
          VerificationReviewRow(
            'مكان إصدار الهوية',
            _idPlaceOfIssueController.text.trim(),
          ),
          VerificationReviewRow(
            'تاريخ إصدار الهوية',
            _issueDateController.text,
          ),
          VerificationReviewRow(
            'تاريخ انتهاء الهوية',
            _expiryDateController.text,
          ),
          VerificationReviewRow(
            'خصوصية المراجعة',
            _reviewMode == 'restricted_review'
                ? 'خصوصية إضافية'
                : 'مراجعة محمية',
          ),
        ],
        documents: [
          VerificationReviewDocument(
            label: 'وجه الهوية',
            path: controller.identityImage[0].path,
          ),
          VerificationReviewDocument(
            label: 'ظهر الهوية',
            path: controller.identityImage[1].path,
          ),
        ],
        onConfirm: () => controller.kycVerify(
          _identityNumberController.text.trim(),
          dateOfBirth: _dateOfBirthController.text,
          idPlaceOfIssue: _idPlaceOfIssueController.text.trim(),
          issueDate: _issueDateController.text,
          expiryDate: _expiryDateController.text,
          reviewMode: _reviewMode,
        ),
      ),
    );

    if (confirmed == true) {
      try {
        await Get.find<ProfileController>()
            .getProfileData(isUpdate: true, reload: true);
      } catch (_) {
        // تحديث الواجهة فقط؛ الأرشفة والرفع تمّا في الخادم بالفعل.
      }
      Get.back(result: true);
    }
  }

}
