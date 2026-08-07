import 'package:amial_pay/common/widgets/custom_large_widget.dart';
import 'package:amial_pay/features/auth/widgets/pin_field_widget.dart';
import 'package:amial_pay/features/forget_pin/controllers/forget_pin_controller.dart';
import 'package:amial_pay/util/dimensions.dart';
import 'package:flutter/material.dart';
import 'package:get/get.dart';


class ResetPinScreen extends StatefulWidget {
  final String? phoneNumber;
  final String? otp;
  const ResetPinScreen({super.key, this.phoneNumber, this.otp});

  @override
  State<ResetPinScreen>  createState() => _ResetPinScreenState();
}

class _ResetPinScreenState extends State<ResetPinScreen> {
  final TextEditingController newPassController = TextEditingController();
  final TextEditingController confirmPassController = TextEditingController();
  final GlobalKey<FormState> formKey = GlobalKey<FormState>();


  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Text("reset_setup_pin".tr),
      ),
      body: SingleChildScrollView(
        child: Column(children: [
        
          PinFieldWidget(
            pinController: newPassController,
            confirmPinController: confirmPassController,
            formKey: formKey,
          ),
        
          GetBuilder<ForgetPinController>(builder: (forgetPinController)=> Padding(
            padding: const EdgeInsets.only(bottom: 20,right: 10),
            child: CustomLargeButtonWidget(
              fontSize: Dimensions.fontSizeDefault,
              isLoading: forgetPinController.isLoading,
              text: 'reset_pin'.tr,
              onTap: () {
                if(formKey.currentState!.validate()) {
                  forgetPinController.resetPin(
                    newPassController.text,
                    confirmPassController.text,
                    widget.phoneNumber,
                    widget.otp,
                  );
                }
              },
              backgroundColor: Theme.of(context).colorScheme.secondary,
            ),
          )),
        
        ]),
      ),
    );
  }
}
