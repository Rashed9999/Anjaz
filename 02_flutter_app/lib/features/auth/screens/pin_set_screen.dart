import 'dart:io';
import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';
import 'package:amyal_pay/common/widgets/custom_large_widget.dart';
import 'package:amyal_pay/features/auth/controllers/auth_controller.dart';
import 'package:amyal_pay/features/camera_verification/controllers/camera_screen_controller.dart';
import 'package:amyal_pay/features/auth/controllers/create_account_controller.dart';
import 'package:amyal_pay/features/setting/controllers/edit_profile_controller.dart';
import 'package:amyal_pay/features/verification/controllers/verification_controller.dart';
import 'package:amyal_pay/data/api/api_client.dart';
import 'package:amyal_pay/common/models/signup_body_model.dart';
import 'package:amyal_pay/helper/phone_cheker_helper.dart';
import 'package:amyal_pay/util/dimensions.dart';
import 'package:amyal_pay/features/auth/widgets/pin_field_widget.dart';

class PinSetScreen extends StatefulWidget {
  final SignUpBodyModel signUpBody;
  const PinSetScreen({super.key, required this.signUpBody});

  @override
  State<PinSetScreen> createState() => _PinSetScreenState();
}

class _PinSetScreenState extends State<PinSetScreen> {
  final TextEditingController passController = TextEditingController();
  final TextEditingController confirmPassController = TextEditingController();
  final GlobalKey<FormState> formKey = GlobalKey<FormState>();

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text("setup_pin".tr), backgroundColor: AmyalColors.primary, foregroundColor: Colors.white, elevation: 0),
      body: SafeArea(child: Column(children: [

        Expanded(child: SingleChildScrollView(
          child: PinFieldWidget(
            pinController: passController,
            confirmPinController: confirmPassController,
            formKey: formKey,
          ),
        )),

        GetBuilder<AuthController>(
          builder: (authController) {
            return Padding(
              padding: const EdgeInsets.symmetric(
                vertical: Dimensions.paddingSizeLarge,
                horizontal: Dimensions.paddingSizeSmall,
              ),
              child: CustomLargeButtonWidget(
                backgroundColor: Theme.of(context).colorScheme.secondary,
                text: 'save'.tr,
                fontSize: Dimensions.fontSizeDefault,
                isLoading: authController.isLoading,
                onTap: () {
                  String password =  passController.text.trim();

                  if(formKey.currentState!.validate()){
                    String gender =  Get.find<EditProfileController>().gender ?? "Male";
                    String countryCode = PhoneNumberHelper.getCountryCode(Get.find<CreateAccountController>().phoneNumber)!;
                    String phoneNumber = Get.find<CreateAccountController>().phoneNumber!.replaceAll(countryCode, '');
                    File? image =  Get.find<CameraScreenController>().getImage;
                    String? otp =  Get.find<VerificationController>().otp;

                    SignUpBodyModel signUpBody = SignUpBodyModel(
                        fName: widget.signUpBody.fName,
                        lName: widget.signUpBody.lName,
                        gender: gender,
                        occupation: widget.signUpBody.occupation,
                        email: widget.signUpBody.email,
                        phone: phoneNumber,
                        otp: otp,
                        password: password,
                        dialCountryCode: countryCode
                    );

                    MultipartBody multipartBody = MultipartBody('image',image );
                    Get.find<AuthController>().registration(signUpBody,[multipartBody]);
                  }
                }
              ) ,
            );
          }
        ),


      ])),
    );
  }
}
