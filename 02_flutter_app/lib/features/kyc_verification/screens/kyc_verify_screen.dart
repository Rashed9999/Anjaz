import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amial_pay/features/kyc_verification/controllers/kyc_verify_controller.dart';
import 'package:amial_pay/features/kyc_verification/widgets/dotted_border_widget.dart';
import 'package:amial_pay/features/setting/controllers/profile_screen_controller.dart';
import 'package:amial_pay/util/styles.dart';
import 'package:amial_pay/common/widgets/custom_button_widget.dart';
import 'package:amial_pay/common/widgets/custom_drop_down_button_widget.dart';
import 'package:amial_pay/helper/custom_snackbar_helper.dart';
import 'package:amial_pay/common/widgets/custom_text_field_widget.dart';
import 'package:amial_pay/features/auth/widgets/governorate_picker.dart';
import '../../../util/dimensions.dart';

class KycVerifyScreen extends StatefulWidget {
  const KycVerifyScreen({super.key});

  @override
  State<KycVerifyScreen> createState() => _KycVerifyScreenState();
}

class _KycVerifyScreenState extends State<KycVerifyScreen> {
  final TextEditingController _identityNumberController = TextEditingController();
  final TextEditingController _addressController = TextEditingController();
  final TextEditingController _signatureController = TextEditingController();
  String? _residenceGovernorate;
  bool _declared = false;

  // AMIAL-KYC-PRIVACY-APP-001 — حق خصوصية للجميع، لا «وضع نساء».
  String _reviewMode = 'standard';

  @override
  void initState() {
    Get.find<KycVerifyController>().initialSelect();
    super.initState();
  }

  @override
  void dispose() {
    _identityNumberController.dispose();
    _addressController.dispose();
    _signatureController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text('kyc_verification'.tr)),
      body: Padding(
        padding: const EdgeInsets.symmetric(horizontal: Dimensions.fontSizeDefault, vertical: Dimensions.paddingSizeLarge),
        child: GetBuilder<KycVerifyController>(
          builder: (kycVerifyController) {
            return SingleChildScrollView(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                CustomDropDownButtonWidget(
                  value: kycVerifyController.dropDownSelectedValue,
                  itemList: kycVerifyController.dropList,
                  onChanged: (value)=> kycVerifyController.dropDownChange(value!),
                ),
                const SizedBox(height: Dimensions.fontSizeDefault),

                CustomTextFieldWidget(
                  controller: _identityNumberController,
                  isShowBorder: true,
                  maxLines: 1,
                  hintText: 'identity_number'.tr,
                ),
                const SizedBox(height: Dimensions.paddingSizeLarge),

                Text('طريقة مراجعة الهوية', style: rubikRegular.copyWith(fontWeight: FontWeight.w700)),
                const SizedBox(height: 6),
                const Text(
                  'الخصوصية متاحة لكل صاحب حساب. لا يغيّر اختيارك متطلبات إثبات الهوية؛ بل يحدد من يستطيع رؤية صورة الوجه وكيف تُراجع.',
                  style: TextStyle(fontSize: 12.5, height: 1.5, color: Color(0xFF5F6B7C)),
                ),
                const SizedBox(height: 10),
                _privacyOption(
                  value: 'standard',
                  title: 'مراجعة عادية',
                  subtitle: 'الوثائق مشفّرة، وكل مشاهدة من لوحة أميال تحمل علامة مائية قابلة للتتبّع.',
                  icon: Icons.verified_user_outlined,
                ),
                _privacyOption(
                  value: 'restricted_review',
                  title: 'خصوصية إضافية',
                  subtitle: 'صورة الوجه لا تُعرض إلا لمراجع يملك صلاحية بيومترية مستقلة، مع تسجيل كل مشاهدة.',
                  icon: Icons.privacy_tip_outlined,
                  emphasized: true,
                ),
                _privacyOption(
                  value: 'in_person',
                  title: 'طلب تحقق حضوري',
                  subtitle: 'طلب مسار مراجعة حضورية مقيدة. في النسخة الحالية تبقى المستندات الثلاثة مطلوبة حتى اعتماد البديل رقابياً.',
                  icon: Icons.person_pin_circle_outlined,
                ),
                Container(
                  margin: const EdgeInsets.only(top: 4, bottom: 14),
                  padding: const EdgeInsets.all(12),
                  decoration: BoxDecoration(
                    color: const Color(0xFFF4F7FB),
                    borderRadius: BorderRadius.circular(10),
                    border: Border.all(color: const Color(0xFFDDE5EF)),
                  ),
                  child: const Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Icon(Icons.face_retouching_natural, size: 20, color: Color(0xFF5F6B7C)),
                    SizedBox(width: 8),
                    Expanded(child: Text(
                      'التحقق الآلي الخاص (Liveness + Face Match) سيظهر هنا بعد ربط مزود بيومتري حقيقي ومعتمد. أميال لا يعرض نتائج أو درجات وهمية.',
                      style: TextStyle(fontSize: 12.5, height: 1.5, color: Color(0xFF5F6B7C)),
                    )),
                  ]),
                ),

                const Text(
                  'ارفع ٣ صور بالترتيب: وجه الهوية، ظهر الهوية، ثم صورة شخصية حديثة.',
                  style: TextStyle(fontSize: 13, height: 1.5),
                ),
                const SizedBox(height: Dimensions.paddingSizeDefault,),

                Container(height: 100,padding: const EdgeInsets.symmetric(horizontal: Dimensions.paddingSizeExtraSmall),
                  child: ListView.builder(
                    scrollDirection: Axis.horizontal,
                    shrinkWrap: true,
                    itemCount: kycVerifyController.identityImage.length + 1,
                    itemBuilder: (BuildContext context, index){
                      if(index + 1 == kycVerifyController.identityImage.length + 1) {
                        return const DottedBorderWidget(path: null);
                      }
                      return  kycVerifyController.identityImage.isNotEmpty ?
                      Row(
                        children: [
                          Stack(
                            children: [
                              DottedBorderWidget(path: kycVerifyController.identityImage[index].path),

                              Positioned(
                                bottom:0,right:0,
                                child: InkWell(
                                  onTap :() => kycVerifyController.removeImage(index),
                                  child: Container(
                                      decoration: BoxDecoration(
                                          color: Colors.red.withValues(alpha:0.2),
                                          borderRadius: const BorderRadius.all(Radius.circular(Dimensions.paddingSizeDefault))
                                      ),
                                      child: const Padding(
                                        padding: EdgeInsets.all(5.0),
                                        child: Icon(Icons.delete_outline,color: Colors.red,size: 16,),
                                      )),
                                ),
                              ),
                            ],
                          ),
                          const SizedBox(width: Dimensions.paddingSizeDefault),
                        ],
                      ):const SizedBox();

                    },),
                ),
                const SizedBox(height: Dimensions.fontSizeDefault),

                Text('العنوان (المدينة، الحي، الشارع)', style: rubikRegular),
                const SizedBox(height: Dimensions.paddingSizeExtraSmall),
                CustomTextFieldWidget(
                  controller: _addressController,
                  isShowBorder: true,
                  maxLines: 2,
                  hintText: 'مثال: عدن، المنصورة، شارع الأربعين',
                ),
                const SizedBox(height: Dimensions.fontSizeDefault),

                GovernoratePicker(
                  label: 'محافظة السكن',
                  value: _residenceGovernorate,
                  helper: 'نستخدمها لتحديد منطقة الحساب بعد مراجعة الهوية.',
                  onChanged: (value) => setState(() => _residenceGovernorate = value),
                ),

                Text('التوقيع الإلكتروني (اكتب اسمك الكامل)', style: rubikRegular),
                const SizedBox(height: Dimensions.paddingSizeExtraSmall),
                CustomTextFieldWidget(
                  controller: _signatureController,
                  isShowBorder: true,
                  maxLines: 1,
                  hintText: 'اسمك الكامل كتوقيع',
                ),
                const SizedBox(height: Dimensions.fontSizeDefault),

                Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Checkbox(
                      value: _declared,
                      activeColor: Theme.of(context).primaryColor,
                      onChanged: (v) => setState(() => _declared = v ?? false),
                    ),
                    const Expanded(
                      child: Padding(
                        padding: EdgeInsets.only(top: 12),
                        child: Text(
                          'أقرّ بأن جميع المعلومات والوثائق المقدّمة صحيحة، وأتحمّل المسؤولية القانونية عن صحّتها.',
                          style: TextStyle(fontSize: 12.5, height: 1.4),
                        ),
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: Dimensions.paddingSizeLarge),

                Center(child: kycVerifyController.isLoading ? const CircularProgressIndicator() :  SizedBox(
                  width: 200, height: 50,
                  child: CustomButtonWidget(buttonText: 'upload'.tr, onTap: (){
                    if(_identityNumberController.text.isEmpty) {
                      showCustomSnackBarHelper('identity_number_is_empty'.tr);
                    }else if(kycVerifyController.identityImage.length != 3) {
                      showCustomSnackBarHelper('يرجى رفع ثلاث صور بالترتيب: وجه الهوية وظهرها وصورة شخصية');
                    }else if(kycVerifyController.dropDownSelectedValue == kycVerifyController.dropList[0]) {
                      showCustomSnackBarHelper('select_identity_type'.tr);
                    }else if(_addressController.text.trim().isEmpty) {
                      showCustomSnackBarHelper('الرجاء إدخال العنوان');
                    }else if(_residenceGovernorate == null) {
                      showCustomSnackBarHelper('الرجاء اختيار محافظة السكن');
                    }else if(_signatureController.text.trim().isEmpty) {
                      showCustomSnackBarHelper('الرجاء كتابة التوقيع الإلكتروني');
                    }else if(!_declared) {
                      showCustomSnackBarHelper('الرجاء الإقرار بصحة المعلومات');
                    }else{
                      kycVerifyController.kycVerify(
                        _identityNumberController.text,
                        address: _addressController.text.trim(),
                        residenceGovernorate: _residenceGovernorate!,
                        signature: _signatureController.text.trim(),
                        declared: _declared,
                        reviewMode: _reviewMode,
                      ).then((value)
                      => Get.find<ProfileController>().getProfileData(isUpdate: true, reload: true));
                    }
                  }, color: Theme.of(context).primaryColor),
                  ),
                ),
              ]),
            );
          }
        ),
      ),
    );
  }

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
            color: selected ? primary : (emphasized ? const Color(0xFF9EB8D8) : const Color(0xFFE1E6EC)),
            width: selected ? 1.6 : 1,
          ),
        ),
        child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Icon(icon, color: selected ? primary : const Color(0xFF5F6B7C), size: 23),
          const SizedBox(width: 10),
          Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Row(children: [
              Expanded(child: Text(title, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 14))),
              if (emphasized)
                const Padding(
                  padding: EdgeInsets.only(right: 6),
                  child: Text('خصوصية أعلى', style: TextStyle(fontSize: 10.5, color: Color(0xFF365F91))),
                ),
            ]),
            const SizedBox(height: 4),
            Text(subtitle, style: const TextStyle(fontSize: 12, height: 1.45, color: Color(0xFF5F6B7C))),
          ])),
          Radio<String>(
            value: value,
            groupValue: _reviewMode,
            onChanged: (v) => setState(() => _reviewMode = v ?? 'standard'),
          ),
        ]),
      ),
    );
  }
}
