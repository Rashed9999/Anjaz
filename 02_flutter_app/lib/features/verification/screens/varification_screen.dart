import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amial_pay/common/widgets/custom_large_widget.dart';
import 'package:amial_pay/features/auth/controllers/auth_controller.dart';
import 'package:amial_pay/features/auth/controllers/create_account_controller.dart';
import 'package:amial_pay/features/verification/controllers/verification_controller.dart';
import 'package:amial_pay/util/dimensions.dart';
import 'package:amial_pay/common/widgets/custom_pin_code_field_widget.dart';
import 'package:amial_pay/common/widgets/demo_otp_hint_widget.dart';
import 'package:amial_pay/features/verification/widgets/information_view.dart';
import 'package:amial_pay/features/verification/widgets/timer_section.dart';

class VerificationScreen extends StatefulWidget {
  final String? phoneNumber;
  const VerificationScreen({super.key, this.phoneNumber});

  @override
  State<VerificationScreen> createState() => _VerificationScreenState();
}

class _VerificationScreenState extends State<VerificationScreen> {

  @override
  void initState() {
    Get.find<VerificationController>().startTimer();
    Get.find<VerificationController>().setOtp(null);
    super.initState();
  }

  @override
  Widget build(BuildContext context) {
    final bool isForgetPassword = widget.phoneNumber != null;
    return Scaffold(
      backgroundColor: Theme.of(context).cardColor,
      appBar: AppBar(
        title: Text('phone_verification'.tr),
        leading: IconButton(
          icon: const Icon(Icons.arrow_back),
          onPressed: () {
            Get.find<VerificationController>().cancelTimer();
            Get.back();
          },
        ),
      ),
      body: Column(
        children: [
          Expanded(child: SingleChildScrollView(
            child: Column(crossAxisAlignment: CrossAxisAlignment.center,
              children: [
                const SizedBox(height: Dimensions.paddingSizeExtraLarge),
                InformationView(phoneNumber: widget.phoneNumber),
                const SizedBox(height: Dimensions.paddingSizeOverLarge),

                GetBuilder<VerificationController>(builder: (verificationController){
                  return CustomPinCodeFieldWidget(
                    padding: Dimensions.paddingSizeOverLarge,
                    onCompleted: (pin){
                      verificationController.setOtp(pin);

                      String? phoneNumber = isForgetPassword ? widget.phoneNumber : Get.find<CreateAccountController>().phoneNumber!;

                      if(isForgetPassword){
                        Get.find<AuthController>().verificationForForgetPass(phoneNumber, pin);
                      }else{
                        Get.find<AuthController>().phoneVerify(phoneNumber!, pin);
                      }
                    },
                  );
                }),

                const DemoOtpHintWidget(),
                const SizedBox(height: Dimensions.paddingSizeExtraLarge,),


                GetBuilder<AuthController>(builder: (controller)=> GetBuilder<VerificationController>(
                  builder: (verificationController) {
                    return CustomLargeButtonWidget(
                      backgroundColor: verificationController.otp?.length == 4 ?  Theme.of(context).colorScheme.secondary : Theme.of(context).disabledColor,
                      text: 'verify'.tr,
                      textColor: verificationController.otp?.length != 4 ? Colors.white : null,
                      isLoading: controller.isLoading,
                      fontSize: Dimensions.fontSizeDefault,
                      onTap: verificationController.otp?.length == 4 ? () async {
                        String? phoneNumber = isForgetPassword ? widget.phoneNumber : Get.find<CreateAccountController>().phoneNumber!;
                        String otp =  Get.find<VerificationController>().otp ?? "";
                        if(isForgetPassword){
                          Get.find<AuthController>().verificationForForgetPass(phoneNumber, otp);
                        }else{
                          Get.find<AuthController>().phoneVerify(phoneNumber!, otp);
                        }
                      } : null,
                    );
                  }
                )),

                TimerSection(
                  isForgetPassword: isForgetPassword,
                  phoneNumber: widget.phoneNumber
                      ?? Get.find<CreateAccountController>().phoneNumber!,
                ),
              ],
            ),
          )),

        ],
      ),

    );
  }
}
