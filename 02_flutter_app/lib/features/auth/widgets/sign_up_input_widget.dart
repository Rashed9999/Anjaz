import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amial_pay/common/widgets/custom_text_field_widget.dart';
import 'package:amial_pay/common/widgets/text_field_title.dart';
import 'package:amial_pay/features/setting/controllers/profile_screen_controller.dart';
import 'package:amial_pay/helper/email_checker_helper.dart';
import 'package:amial_pay/util/dimensions.dart';

class SignUpInputWidget extends StatefulWidget {
  final TextEditingController? occupationController, fNameController,lNameController,emailController;

   const SignUpInputWidget({
     super.key,
     this.occupationController,
     this.fNameController,
     this.lNameController,
     this.emailController,
   });

  @override
  State<SignUpInputWidget> createState() => _SignUpInputWidgetState();
}

class _SignUpInputWidgetState extends State<SignUpInputWidget> {
  final FocusNode occupationFocus = FocusNode();
  final FocusNode firstNameFocus = FocusNode();
  final FocusNode lastNameFocus = FocusNode();
  final FocusNode emailNameFocus = FocusNode();

  @override
  void initState() {
    super.initState();
    if(widget.fNameController!.text.isEmpty){
      firstNameFocus.requestFocus();
    }
  }

  @override
  Widget build(BuildContext context) {
    return Container(
    padding: const EdgeInsets.symmetric(horizontal: Dimensions.paddingSizeLarge),
    child: GetBuilder<ProfileController>(
      builder: (controller) {
        return Column(children: [

          TextFieldTitle(title: "first_name".tr, requiredMark: true,),
          CustomTextFieldWidget(
            hintText: 'enter_first_name'.tr,
            isShowBorder: true,
            controller: widget.fNameController,
            focusNode: firstNameFocus,
            nextFocus: lastNameFocus,
            inputType: TextInputType.name,
            capitalization: TextCapitalization.words,
            isAutoFocus: true,
            onValidate: (name){
              return name != null && name.isEmpty ? "field_cannot_be_empty".tr : null;
            },
          ),


          TextFieldTitle(title: "last_name".tr, requiredMark: true,),
          CustomTextFieldWidget(
            hintText: 'enter_last_name'.tr,
            isShowBorder: true,
            controller: widget.lNameController,
            focusNode: lastNameFocus,
            nextFocus: emailNameFocus,
            inputType: TextInputType.name,
            capitalization: TextCapitalization.words,
            onValidate: (name){
              return name != null && name.isEmpty ? "field_cannot_be_empty".tr : null;
            },
          ),

          TextFieldTitle(title: "email_address".tr),
          CustomTextFieldWidget(
            hintText: 'email_address_hint'.tr,
            isShowBorder: true,
            controller: widget.emailController,
            focusNode: emailNameFocus,
            nextFocus: occupationFocus,
            inputType: TextInputType.emailAddress,
            onValidate: (email){
              return email != null && email.isNotEmpty && EmailCheckerHelper.isNotValid(email) ? "please_provide_valid_email".tr : null;
            },
          ),

          TextFieldTitle(title: "occupation".tr),
          CustomTextFieldWidget(
            hintText: 'ex:teacher'.tr,
            isShowBorder: true,
            controller: widget.occupationController,
            focusNode: occupationFocus,
            nextFocus: firstNameFocus,
            inputType: TextInputType.name,
            capitalization: TextCapitalization.words,
          ),
          const SizedBox(height: Dimensions.paddingSizeLarge),


        ]);
      }
    ));
  }
}
