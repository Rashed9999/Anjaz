import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/common/widgets/custom_text_field_widget.dart';
import 'package:amyal_pay/common/widgets/text_field_title.dart';
import 'package:amyal_pay/util/dimensions.dart';
import 'package:amyal_pay/util/styles.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';

class PinFieldWidget extends StatefulWidget {
  final TextEditingController pinController;
  final TextEditingController confirmPinController;
  final bool isRegistration;
  final GlobalKey<FormState> formKey;

  const PinFieldWidget({super.key,
    required this.pinController,
    required this.confirmPinController,
    this.isRegistration = true, required this.formKey
  }) ;

  @override
  State<PinFieldWidget> createState() => _PinFieldWidgetState();
}

class _PinFieldWidgetState extends State<PinFieldWidget> {
  final FocusNode confirmFocus = FocusNode();
  String _pin = "";
  String _confirmPin = "";


  @override
  Widget build(BuildContext context) {
    return Form(
      key: widget.formKey,
      child: Container(
        padding: const EdgeInsets.symmetric(vertical: Dimensions.paddingSizeDefault, horizontal: Dimensions.paddingSizeLarge),
        width: double.infinity,
        child: SingleChildScrollView(child: Column( crossAxisAlignment: CrossAxisAlignment.start , children: [

          // AMIAL-PIN-UI-001: كانت أيقونة PNG بقفل أخضر — لون خارج هوية
          // البراند (أزرق/أصفر). استبدلناها بشارة مبنيّة بالكود بألوان البراند
          // فلا تتعارض الألوان ولا تعتمد على أصل صورة.
          Center(
            child: Container(
              width: 76, height: 76,
              decoration: BoxDecoration(
                color: AmyalColors.primary.withValues(alpha: 0.08),
                shape: BoxShape.circle,
              ),
              child: const Icon(Icons.lock_outline_rounded,
                  size: 36, color: AmyalColors.primary),
            ),
          ),

          const SizedBox(height: Dimensions.paddingSizeDefault),

          Center(
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: Dimensions.paddingSizeSmall),
              child: Text(
                widget.isRegistration ?  'set_your_pin'.tr : 'set_new_4_digit_pin'.tr,
                textAlign: TextAlign.center,
                style: rubikMedium.copyWith(
                  color: AmyalColors.primary,
                  fontSize: Dimensions.fontSizeExtraLarge,
                ),
              ),
            ),
          ),
          const SizedBox(height: Dimensions.paddingSizeExtraSmall),
          Center(
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: Dimensions.paddingSizeSmall),
              child: Text(
                "create_4_digit_pin_for_for_future_login".tr,
                textAlign: TextAlign.center,
                style: rubikLight.copyWith(
                  color: AmyalColors.textSecondary,
                  fontSize: Dimensions.fontSizeDefault,
                ),
              ),
            ),
          ),
          const SizedBox(height: Dimensions.paddingSizeExtraLarge),

          TextFieldTitle(
            title: "pin".tr,
            requiredMark: true,
            isPadding: false,
            maxLength: 4,
            currentLength : _pin.length,
            titleColor: Theme.of(context).primaryColor,
          ),
          const SizedBox(height: Dimensions.paddingSizeExtraSmall),

          CustomTextFieldWidget(
            isAutoFocus: true,
            controller: widget.pinController,
            nextFocus: confirmFocus,
            isPassword: true,
            isShowSuffixIcon: true,
            letterSpacing: 10,
            fontSize: Dimensions.fontSizeLarge,
            maxLength: 4,
            hintText: "ex:1234".tr,
            suffixIconColor: Theme.of(context).primaryColor,
            onValidate: (pin){
              _setPinValue();
              return pin !=null && pin.isEmpty ? "field_cannot_be_empty".tr : pin!.isNotEmpty && pin.length != 4  ? "pin_should_be_4_digit".tr : null;
            },
            onChanged: (value){
              _setPinValue();
            },
          ),
          const SizedBox(height: Dimensions.paddingSizeExtraSmall,),

          TextFieldTitle(
            title: "confirm_pin".tr,
            requiredMark: true,
            isPadding: false,
            maxLength: 4,
            currentLength : _confirmPin.length,
            titleColor: Theme.of(context).primaryColor,
          ),
          const SizedBox(height: Dimensions.paddingSizeExtraSmall,),

          CustomTextFieldWidget(
            controller: widget.confirmPinController,
            focusNode: confirmFocus,
            isShowSuffixIcon: true,
            isPassword: true,
            letterSpacing: 10,
            fontSize: Dimensions.fontSizeLarge,
            maxLength: 4,
            hintText: "ex:1234".tr,
            suffixIconColor: Theme.of(context).primaryColor,
            onValidate: (pin){
              return pin !=null && pin.isEmpty ? "field_cannot_be_empty".tr : pin!.isNotEmpty && pin.length != 4
                  ?  "pin_should_be_4_digit".tr : _pin != _confirmPin ? "pin_not_matched".tr : null;
            },
            onChanged: (value){
              _setPinValue();
            },
          ),

        ])),
      ),
    );
  }
  void _setPinValue () {
    if (!mounted) return; // AMIAL-FIX-006
    setState(() {
      _pin = widget.pinController.text;
      _confirmPin = widget.confirmPinController.text;
    });

  }
}
