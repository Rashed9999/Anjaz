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
import '../../../util/dimensions.dart';

class KycVerifyScreen extends StatefulWidget {
  const KycVerifyScreen({super.key});

  @override
  State<KycVerifyScreen> createState() => _KycVerifyScreenState();
}

class _KycVerifyScreenState extends State<KycVerifyScreen> {
  final TextEditingController _identityNumberController = TextEditingController();
  // AMIAL-KYC: العنوان + التوقيع الإلكتروني + الإقرار
  final TextEditingController _addressController = TextEditingController();
  final TextEditingController _signatureController = TextEditingController();
  bool _declared = false;

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
      appBar: AppBar(
        title: Text('kyc_verification'.tr),
      ),
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
                  //fillColor: Theme.of(context).cardColor,
                  isShowBorder: true,
                  maxLines: 1,
                  hintText: 'identity_number'.tr,
                ),
                const SizedBox(height: Dimensions.fontSizeDefault),

                Text('upload_your_image'.tr, style: rubikRegular),
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

                // ── العنوان ──────────────────────────────────
                Text('العنوان (المدينة، الحي، الشارع)', style: rubikRegular),
                const SizedBox(height: Dimensions.paddingSizeExtraSmall),
                CustomTextFieldWidget(
                  controller: _addressController,
                  isShowBorder: true,
                  maxLines: 2,
                  hintText: 'مثال: عدن، المنصورة، شارع الأربعين',
                ),
                const SizedBox(height: Dimensions.fontSizeDefault),

                // ── التوقيع الإلكتروني ────────────────────────
                Text('التوقيع الإلكتروني (اكتب اسمك الكامل)', style: rubikRegular),
                const SizedBox(height: Dimensions.paddingSizeExtraSmall),
                CustomTextFieldWidget(
                  controller: _signatureController,
                  isShowBorder: true,
                  maxLines: 1,
                  hintText: 'اسمك الكامل كتوقيع',
                ),
                const SizedBox(height: Dimensions.fontSizeDefault),

                // ── الإقرار بصحة المعلومات ────────────────────
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
                    }else if(kycVerifyController.identityImage.isEmpty) {
                      showCustomSnackBarHelper('please_upload_identity_image'.tr);
                    }else if(kycVerifyController.dropDownSelectedValue == kycVerifyController.dropList[0]) {
                      showCustomSnackBarHelper('select_identity_type'.tr);
                    }else if(_addressController.text.trim().isEmpty) {
                      showCustomSnackBarHelper('الرجاء إدخال العنوان');
                    }else if(_signatureController.text.trim().isEmpty) {
                      showCustomSnackBarHelper('الرجاء كتابة التوقيع الإلكتروني');
                    }else if(!_declared) {
                      showCustomSnackBarHelper('الرجاء الإقرار بصحة المعلومات');
                    }else{
                      kycVerifyController.kycVerify(
                        _identityNumberController.text,
                        address: _addressController.text.trim(),
                        signature: _signatureController.text.trim(),
                        declared: _declared,
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
}
