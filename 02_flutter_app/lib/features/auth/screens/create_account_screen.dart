import 'package:country_code_picker/country_code_picker.dart';
import 'package:amyal_pay/common/widgets/custom_pop_scope_widget.dart';
import 'package:amyal_pay/common/widgets/custom_text_field_widget.dart';
import 'package:amyal_pay/common/widgets/text_field_title.dart';
import 'package:amyal_pay/features/auth/controllers/auth_controller.dart';
import 'package:amyal_pay/features/auth/controllers/create_account_controller.dart';
import 'package:amyal_pay/helper/phone_cheker_helper.dart';
import 'package:amyal_pay/util/app_constants.dart';
import 'package:amyal_pay/util/dimensions.dart';
import 'package:amyal_pay/util/styles.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';
import 'package:amyal_pay/common/widgets/custom_logo_widget.dart';
import 'package:amyal_pay/common/widgets/custom_large_widget.dart';
import 'package:flutter/material.dart';
import 'package:get/get.dart';

class CreateAccountScreen extends StatefulWidget {
  const CreateAccountScreen({super.key});


  @override
  State<CreateAccountScreen> createState() => _CreateAccountScreenState();
}

class _CreateAccountScreenState extends State<CreateAccountScreen> {
  final TextEditingController numberFieldController = TextEditingController();
  final GlobalKey<FormState> formKey = GlobalKey<FormState>();


  @override
  void initState() {
    super.initState();
  }

  @override
  Widget build(BuildContext context) {
    return CustomPopScopeWidget(
      child: Scaffold(
        backgroundColor: Theme.of(context).cardColor,
        appBar: AppBar(
          title: Text('create_account'.tr),
          backgroundColor: AmyalColors.primary,
          foregroundColor: Colors.white,
          elevation: 0,
          automaticallyImplyLeading: false,
        ),
        body: Form(
          key: formKey,
          child: Column(crossAxisAlignment: CrossAxisAlignment.center, children: [

            Expanded(child: SingleChildScrollView(child: Column(children: [

                const SizedBox(height: Dimensions.radiusSizeOverLarge),

                const CustomLogoWidget(height: 70.0, width: 70.0),
                const SizedBox(height: Dimensions.paddingSizeExtraLarge),

                Text('enter_mobile_number'.tr,
                  style : rubikSemiBold.copyWith(
                    color: Theme.of(context).textTheme.bodyMedium?.color,
                    fontSize: Dimensions.fontSizeExtraLarge,
                  ),
                ),
                const SizedBox(height: Dimensions.paddingSizeSmall),

                Padding(padding: const EdgeInsets.symmetric(horizontal: Dimensions.paddingSizeExtraLarge),
                  child: Text('${'create'.tr} ${AppConstants.appName} ${'account_with_your'.tr}', style: rubikRegular.copyWith(
                      color: Theme.of(context).hintColor.withValues(alpha: 0.6),
                      fontSize: Dimensions.fontSizeLarge),
                      textAlign: TextAlign.center
                  ),
                ),
                const SizedBox(height: Dimensions.paddingSizeExtraLarge,),

                Padding(
                  padding: const EdgeInsets.symmetric(horizontal: Dimensions.paddingSizeLarge),
                  child: TextFieldTitle(title: "phone_number".tr),
                ),

                Padding(
                  padding: const EdgeInsets.symmetric(horizontal: Dimensions.paddingSizeLarge),
                  child: GetBuilder<CreateAccountController>(
                    id: 'countryCode',
                    builder: (createAccountController) {
                      return CustomTextFieldWidget(
                        countryDialCode: createAccountController.countryCode,
                        onCountryChanged: (CountryCode countryCode) => createAccountController.setCountryCode(countryCode.dialCode!),
                        hintText: "type_your_number".tr,
                        controller:  numberFieldController,
                        isShowBorder: true,
                        inputType: TextInputType.phone,
                        isAutoFocus: true,
                        onValidate: (phone){
                         return PhoneNumberHelper.onValidatePhone(
                            countryCode: createAccountController.countryCode,
                            controller: numberFieldController,
                          );
                        },
                      );
                     },
                    ),
                ),

              ]))),
            const SizedBox(height: Dimensions.paddingSizeDefault),

            SafeArea(
              child: GetBuilder<AuthController>(builder: (controller)=> CustomLargeButtonWidget(
                padding: const EdgeInsets.all(Dimensions.paddingSizeLarge),
                backgroundColor: Theme.of(context).colorScheme.secondary,
                text: 'next'.tr,
                fontSize: Dimensions.fontSizeLarge,
                isLoading: controller.isLoading,
                onTap: () async {
                  final String phone = PhoneNumberHelper.getValidatePhoneNumberWithPhoneParser(
                    countryCode: Get.find<CreateAccountController>().countryCode,
                    phoneNumber: numberFieldController.text,
                  );

                  if(formKey.currentState!.validate()){
                    Get.find<CreateAccountController>().sendOtpResponse(number: phone);
                  }
                },
              )),
            ),
            const SizedBox(height: Dimensions.paddingSizeDefault),
          ]),
        ),
      ),
    );
  }
}
