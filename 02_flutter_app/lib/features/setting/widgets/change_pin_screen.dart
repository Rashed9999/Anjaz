import 'package:modal_progress_hud_nsn/modal_progress_hud_nsn.dart';
import 'package:amyal_pay/common/widgets/custom_button_widget.dart';
import 'package:amyal_pay/features/setting/controllers/profile_screen_controller.dart';
import 'package:amyal_pay/util/dimensions.dart';
import 'package:amyal_pay/util/styles.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';
import 'package:amyal_pay/common/widgets/custom_loader_widget.dart';
import 'package:amyal_pay/common/widgets/custom_password_field_widget.dart';
import 'package:flutter/material.dart';
import 'package:get/get.dart';

class ChangePinScreen extends StatefulWidget {
  const ChangePinScreen({super.key});

  @override
  State<ChangePinScreen> createState() => _ChangePinScreenState();
}

class _ChangePinScreenState extends State<ChangePinScreen> {
  final TextEditingController _oldPasswordController = TextEditingController();
  final TextEditingController _newPasswordController = TextEditingController();
  final TextEditingController _confirmPasswordController = TextEditingController();
  final FocusNode _oldPinFocus = FocusNode();
  final FocusNode _newPinFocus = FocusNode();
  final FocusNode _confirmPinFocus = FocusNode();

  @override
  Widget build(BuildContext context) {
    return GetBuilder<ProfileController>(
      builder: (controller) {
        return ModalProgressHUD(
          inAsyncCall: controller.isLoading,
          progressIndicator: CustomLoaderWidget(color: Theme.of(context).textTheme.titleLarge!.color,),
          child: Scaffold(
              appBar: AppBar(
                title: Text('pin_change'.tr),
                backgroundColor: AmyalColors.primary,
                foregroundColor: Colors.white,
                elevation: 0,
              ),
              body: Padding(
                padding: const EdgeInsets.all(Dimensions.paddingSizeLarge),
                child: Column(children: [

                  Expanded(child: SingleChildScrollView(child: Column(
                    mainAxisAlignment: MainAxisAlignment.center,
                    crossAxisAlignment: CrossAxisAlignment.start,
                    mainAxisSize: MainAxisSize.min,
                    children: [

                      Row(mainAxisAlignment: MainAxisAlignment.center,children: [
                        Padding(
                          padding: const EdgeInsets.symmetric(vertical: Dimensions.paddingSizeSmall),
                          child: Text(
                            'set_your_4_digit'.tr,
                            textAlign: TextAlign.center,
                            style: rubikMedium.copyWith(
                              color: Theme.of(context).textTheme.bodyLarge?.color,
                              fontSize: Dimensions.fontSizeDefault,
                            ),
                          ),
                        ),
                      ]),
                      const SizedBox(height: Dimensions.paddingSizeLarge),

                      Text('old_password'.tr, style: rubikRegular.copyWith(fontSize: Dimensions.fontSizeDefault, color: Theme.of(context).textTheme.bodyLarge?.color)),
                      const SizedBox(height: Dimensions.paddingSizeSmall),

                      CustomPasswordFieldWidget(
                        controller: _oldPasswordController,
                        focusNode: _oldPinFocus,
                        isPassword: true,
                        isShowSuffixIcon: true,
                        isIcon: false,
                        hint: 'type_old_pin'.tr,
                        letterSpacing: 10.0,
                        nextFocus: _newPinFocus,
                        showMaxLength: false,
                        suffixIconColor: Theme.of(context).hintColor.withValues(alpha: 0.5),
                        enabledBorder: OutlineInputBorder(
                          borderRadius: BorderRadius.circular(Dimensions.radiusSizeSmall),
                          borderSide: BorderSide(color: Theme.of(context).hintColor.withValues(alpha: 0.1), width: 1),
                        ),
                        hintTextStyle: rubikRegular.copyWith(
                          color: Theme.of(context).hintColor.withValues(alpha: 0.5),
                          fontSize: Dimensions.fontSizeDefault,
                          letterSpacing: 1.5,
                        ),
                      ),
                      const SizedBox(height: Dimensions.paddingSizeExtraLarge),

                      Text('new_password'.tr, style: rubikRegular.copyWith(fontSize: Dimensions.fontSizeDefault, color: Theme.of(context).textTheme.bodyLarge?.color)),
                      const SizedBox(height: Dimensions.paddingSizeSmall),

                      CustomPasswordFieldWidget(
                        controller: _newPasswordController,
                        focusNode: _newPinFocus,
                        nextFocus: _confirmPinFocus,
                        hint: 'type_new_pin'.tr,
                        isShowSuffixIcon: true,
                        isPassword: true,
                        isIcon: false,
                        textAlign: TextAlign.start,
                        letterSpacing: 10.0,
                        showMaxLength: false,
                        suffixIconColor: Theme.of(context).hintColor.withValues(alpha: 0.5),
                        enabledBorder: OutlineInputBorder(
                          borderRadius: BorderRadius.circular(Dimensions.radiusSizeSmall),
                          borderSide: BorderSide(color: Theme.of(context).hintColor.withValues(alpha: 0.1), width: 1),
                        ),
                        hintTextStyle: rubikRegular.copyWith(
                          color: Theme.of(context).hintColor.withValues(alpha: 0.5),
                          fontSize: Dimensions.fontSizeDefault,
                          letterSpacing: 1.5,
                        ),
                      ),
                      const SizedBox(height: Dimensions.paddingSizeExtraLarge),

                      Text('confirm_password'.tr, style: rubikRegular.copyWith(fontSize: Dimensions.fontSizeDefault, color: Theme.of(context).textTheme.bodyLarge?.color)),
                      const SizedBox(height: Dimensions.paddingSizeSmall),

                      CustomPasswordFieldWidget(
                        controller: _confirmPasswordController,
                        focusNode: _confirmPinFocus,
                        textInputAction: TextInputAction.done,
                        hint: 'type_new_pin'.tr,
                        isShowSuffixIcon: true,
                        isPassword: true,
                        isIcon: false,
                        letterSpacing: 10.0,
                        textAlign: TextAlign.start,
                        showMaxLength: false,
                        suffixIconColor: Theme.of(context).hintColor.withValues(alpha: 0.5),
                        enabledBorder: OutlineInputBorder(
                          borderRadius: BorderRadius.circular(Dimensions.radiusSizeSmall),
                          borderSide: BorderSide(color: Theme.of(context).hintColor.withValues(alpha: 0.1), width: 1),
                        ),
                        hintTextStyle: rubikRegular.copyWith(
                          color: Theme.of(context).hintColor.withValues(alpha: 0.5),
                          fontSize: Dimensions.fontSizeDefault,
                          letterSpacing: 1.5,
                        ),
                      ),
                      const SizedBox(height: Dimensions.paddingSizeExtraOverLarge),
                    ],
                  ))),

                  SizedBox(
                    height: Dimensions.paddingSizeOverLarge,
                    child: CustomButtonWidget(
                      buttonText: 'change_pin_button'.tr,
                      buttonTextStyle: rubikMedium.copyWith(color: Theme.of(context).primaryColor),
                      borderRadius: Dimensions.radiusSizeExtraSmall,
                      color: Theme.of(context).colorScheme.secondary,
                      onTap: (){
                        controller.changePin(
                            oldPassword: _oldPasswordController.text,
                            confirmPassword: _confirmPasswordController.text,
                            newPassword: _newPasswordController.text,
                        );
                      },
                    ),
                  ),
                ]),
              ),
        ));
      },
    );
  }
}
