
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:get/get.dart';
import 'package:pin_code_fields/pin_code_fields.dart';
import 'package:amyal_pay/features/auth/controllers/auth_controller.dart';
import 'package:amyal_pay/features/transaction_money/controllers/bootom_slider_controller.dart';
import 'package:amyal_pay/helper/custom_extension_helper.dart';
import 'package:amyal_pay/util/dimensions.dart';
import 'package:amyal_pay/util/styles.dart';
import 'package:amyal_pay/helper/custom_snackbar_helper.dart';
import 'package:amyal_pay/features/transaction_money/widgets/next_button_widget.dart';

class ConfirmPinBottomSheet extends StatefulWidget {
  final Function? callBack;
  final bool isAuth;
  const ConfirmPinBottomSheet({super.key, this.callBack, this.isAuth = false});

  @override
  State<ConfirmPinBottomSheet> createState() => _ConfirmPinBottomSheetState();
}

class _ConfirmPinBottomSheetState extends State<ConfirmPinBottomSheet> {
  final TextEditingController _textEditingController = TextEditingController();

  @override
  void initState() {
    super.initState();
    Get.find<BottomSliderController>().setIsPinCompleted(isCompleted: false, isNotify: false);
  }


  @override
  Widget build(BuildContext context) {

    return Row(
      mainAxisAlignment: MainAxisAlignment.spaceBetween,
      children: [
        Expanded(
          child: Padding(
            padding: const EdgeInsets.only(right: Dimensions.paddingSizeSmall),
            child: Container(
              alignment: Alignment.center,
              height: 50.0,
              decoration: BoxDecoration(
                  borderRadius: BorderRadius.circular(27.0),
                  color: context.customThemeColors.sliderColor
              ),

              child:PinCodeTextField(
                controller: _textEditingController,
                length: 4, appContext:  context, onChanged: (value){
                Get.find<BottomSliderController>().updatePinCompleteStatus(value);
              },

                keyboardType: TextInputType.number, inputFormatters: <TextInputFormatter>[FilteringTextInputFormatter.allow(RegExp(r'[0-9]'))],
                obscureText: true, hintCharacter: '•',
                hintStyle: rubikMedium.copyWith(color: Theme.of(context).hintColor.withValues(alpha: 0.2)),
                mainAxisAlignment: MainAxisAlignment.spaceEvenly,

                cursorColor: context.customThemeColors.sliderColor,

                pinTheme: PinTheme.defaults(
                  shape: PinCodeFieldShape.circle,
                  activeColor: context.customThemeColors.sliderColor,
                  activeFillColor: Colors.red,selectedColor: context.customThemeColors.sliderColor,
                  borderWidth: 0,

                  inactiveColor: context.customThemeColors.sliderColor,
                  //fieldHeight: 20.0
                ),
              ),
            ),
          ),
        ),

        InkWell(
            onTap: () {
              if(Get.find<BottomSliderController>().isPinCompleted) {
                if(widget.isAuth) {
                  widget.callBack!(!Get.find<AuthController>().biometric);
                }else{
                  widget.callBack!();
                }

              }else {
                Get.find<BottomSliderController>().updatePinCompleteStatus('');
                Get.back(closeOverlays: true);
                showCustomSnackBarHelper('please_input_4_digit_pin'.tr);
              }
            },

            child: GetBuilder<BottomSliderController>(
                builder: (controller) {
                  return controller.isLoading? Center(child: CircularProgressIndicator(color: Theme.of(context).textTheme.titleLarge!.color,)): NextButtonWidget(isSubmittable: controller.isPinCompleted);
                }
            )
        )
      ],
    );
  }
}
