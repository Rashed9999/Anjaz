import 'dart:convert';

import 'package:flutter/services.dart';
import 'package:amyal_pay/features/auth/controllers/auth_controller.dart';
import 'package:amyal_pay/features/transaction_money/controllers/bootom_slider_controller.dart';
import 'package:amyal_pay/features/setting/controllers/profile_screen_controller.dart';
import 'package:amyal_pay/features/transaction_money/controllers/transaction_controller.dart';
import 'package:amyal_pay/features/splash/controllers/splash_controller.dart';
import 'package:amyal_pay/common/models/contact_model.dart';
import 'package:amyal_pay/features/transaction_money/domain/models/withdraw_model.dart';
import 'package:amyal_pay/helper/custom_extension_helper.dart';
import 'package:amyal_pay/helper/price_converter_helper.dart';
import 'package:amyal_pay/helper/transaction_type.dart';
import 'package:amyal_pay/util/dimensions.dart';
import 'package:amyal_pay/util/styles.dart';
import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:pin_code_fields/pin_code_fields.dart';
import 'package:amyal_pay/common/widgets/custom_pin_code_field_widget.dart';
import 'package:amyal_pay/helper/custom_snackbar_helper.dart';
import 'package:amyal_pay/common/widgets/demo_otp_hint_widget.dart';
import 'package:amyal_pay/features/transaction_money/widgets/preview_amount_widget.dart';

import '../widgets/bottom_sheet_with_slider.dart';
import '../widgets/for_person_widget.dart';




class TransactionConfirmationScreen extends StatelessWidget {
  final double? inputBalance;
  final String? transactionType;
  final String? purpose;
  final ContactModel? contactModel;
  final Function? callBack;
  final WithdrawalMethod? withdrawMethod;


  TransactionConfirmationScreen({super.key,
    required this.inputBalance,
    required this.transactionType,
    this.purpose, this.contactModel,
    this.callBack,
    this.withdrawMethod,
  });
  final _pinCodeFieldController = TextEditingController();


  @override
  Widget build(BuildContext context) {
    final bottomSliderController = Get.find<BottomSliderController>();

    bottomSliderController.setIsPinCompleted(isCompleted: false, isNotify: false);

    return Scaffold(
      appBar: AppBar(
        title: Text(transactionType!.tr),
      ),

      body: SingleChildScrollView(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            if(transactionType != TransactionType.withdrawRequest)
              ForPersonWidget(contactModel: contactModel, transactionType: transactionType),

            PreviewAmountWidget(amountText:  inputBalance?.toStringAsFixed(2) ?? "", onTap: callBack),

            if(transactionType == TransactionType.withdrawRequest)
              PreviewAmountWidget(
                amountText: '${PriceConverterHelper.calculation(inputBalance, Get.find<SplashController>().configModel!.withdrawChargePercent, 'percent')?.toStringAsFixed(2)}',
                title: 'charge'.tr,
              ),

            if(transactionType == TransactionType.withdrawRequest)
              PreviewAmountWidget(
                amountText: PriceConverterHelper.balanceWithCharge(amount: inputBalance, charge: PriceConverterHelper.convertCharge(
                  amount: inputBalance, charge: Get.find<SplashController>().configModel!.withdrawChargePercent,
                )).toStringAsFixed(2),
                title: 'total_amount'.tr,
              ),

            if(transactionType != TransactionType.withdrawRequest)
              Container(
                height: Dimensions.dividerSizeMedium,
                color: Theme.of(context).dividerColor,
              ),

            if(transactionType == TransactionType.withdrawRequest)
              Padding(
                padding: const EdgeInsets.symmetric(
                  horizontal: Dimensions.paddingSizeDefault,
                  vertical: Dimensions.paddingSizeSmall,
                ),
                child: Container(
                  padding: const EdgeInsets.all(10),
                  decoration: BoxDecoration(
                      color: Theme.of(context).cardColor,
                      borderRadius: const BorderRadius.all(Radius.circular(10))
                  ),

                  child: Column(children: [

                    _MethodFieldWidget(
                      type: 'withdraw_method'.tr, value: withdrawMethod!.methodName!,
                    ),

                    const SizedBox(height: 10,),

                    Column(
                      children: withdrawMethod!.methodFields.map((method) =>
                          Padding(
                            padding: const EdgeInsets.symmetric(vertical: 10),
                            child: _MethodFieldWidget(
                              type: method.inputName!.replaceAll('_', ' ').capitalizeFirst!,
                              value: method.inputValue!,
                            ),
                          ),
                      ).toList(),),

                  ]),
                ),
              ),

            Padding(
              padding: const EdgeInsets.symmetric(horizontal: Dimensions.paddingSizeLarge),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Padding(
                    padding: const EdgeInsets.only(
                      top: Dimensions.paddingSizeExtraExtraLarge,
                      bottom:Dimensions.paddingSizeDefault,
                    ),

                    child: Text('4digit_pin'.tr, style: rubikMedium.copyWith(
                      fontSize: Dimensions.fontSizeLarge,
                    )),
                  ),

                  Row(mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    children: [
                      Expanded(
                        child: Container(
                          alignment: Alignment.center, height: 50,
                          decoration: BoxDecoration(
                            borderRadius: BorderRadius.circular(27.0),
                            color: context.customThemeColors.sliderColor,
                          ),
                          child:PinCodeTextField(
                            controller: _pinCodeFieldController,
                            length: 4, appContext:  context,
                            onChanged: (value) => bottomSliderController.updatePinCompleteStatus(value),
                            keyboardType: TextInputType.number,
                            inputFormatters: <TextInputFormatter>[FilteringTextInputFormatter.allow(RegExp(r'[0-9]'))],
                            obscureText: true, hintCharacter: '•',
                            hintStyle: rubikMedium.copyWith(color: Theme.of(context).hintColor.withValues(alpha: 0.2)),
                            mainAxisAlignment: MainAxisAlignment.spaceEvenly,
                            cursorColor: Theme.of(context).highlightColor,
                            pinTheme: PinTheme.defaults(
                              shape: PinCodeFieldShape.circle,
                              activeColor: context.customThemeColors.sliderColor,
                              activeFillColor: Colors.red,
                              selectedColor: context.customThemeColors.sliderColor,
                              borderWidth: 0,
                              inactiveColor: context.customThemeColors.sliderColor,
                            ),
                          ),

                        ),
                      ),
                      const SizedBox(width: Dimensions.paddingSizeDefault),

                      GestureDetector(
                          onTap: (){
                            final configModel = Get.find<SplashController>().configModel;
                            if(!Get.find<BottomSliderController>().isPinCompleted) {
                              showCustomSnackBarHelper('please_input_4_digit_pin'.tr);
                            }else {

                              Get.find<TransactionMoneyController>().pinVerify(
                                  pin: _pinCodeFieldController.text,
                              ).then((isCorrect){
                                if(isCorrect) {
                                  if(transactionType == TransactionType.withdrawRequest) {
                                    _placeWithdrawRequest();
                                  }
                                  else if(configModel!.twoFactor!
                                      && (Get.find<ProfileController>().userInfo?.twoFactor ?? false)
                                  ){
                                    Get.find<AuthController>().checkOtp().then((value) =>
                                    value.isOk ? Get.defaultDialog(
                                      barrierDismissible: false,
                                      title: 'otp_verification'.tr,
                                      content: Column(
                                        children: [
                                          CustomPinCodeFieldWidget(
                                            onCompleted: (pin) => Get.find<AuthController>().verifyOtp(pin).
                                            then((value) {
                                              if(value.isOk){
                                                showModalBottomSheet(
                                                  isScrollControlled: true,
                                                  context: Get.context!,
                                                  isDismissible: false,
                                                  enableDrag: false,
                                                  shape: const RoundedRectangleBorder(
                                                    borderRadius: BorderRadius.vertical(top: Radius.circular(Dimensions.radiusSizeLarge)),
                                                  ),

                                                  builder: (context) => BottomSheetWithSlider(
                                                    amount: inputBalance.toString(),
                                                    contactModel: contactModel,
                                                    pinCode: Get.find<BottomSliderController>().pin,
                                                    transactionType: transactionType,
                                                    purpose: purpose,
                                                  ),
                                                );
                                              }
                                            }),
                                          ),

                                          const DemoOtpHintWidget(),

                                          GetBuilder<AuthController>(
                                            builder: (verifyController) =>
                                            verifyController.isVerifying ?
                                            CircularProgressIndicator(
                                              color: Theme.of(context).textTheme.titleLarge!.color,
                                            ) : const SizedBox.shrink(),
                                          )
                                        ],
                                      ),
                                    ) : null);
                                  }else{
                                    showModalBottomSheet(
                                        isScrollControlled: true,
                                        context: Get.context!,
                                        isDismissible: false,
                                        enableDrag: false,
                                        shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(
                                          top: Radius.circular(Dimensions.radiusSizeLarge),
                                        )),
                                        builder: (context) {
                                          return BottomSheetWithSlider(
                                            amount: inputBalance.toString(),
                                            contactModel: contactModel,
                                            pinCode: Get.find<BottomSliderController>().pin,
                                            transactionType: transactionType,
                                            purpose: purpose,
                                          );
                                        }
                                    );
                                  }
                                }
                                _pinCodeFieldController.clear();

                              });
                            }
                          },

                        child: GetBuilder<AuthController>(
                          builder: (otpCheckController) {
                            return GetBuilder<TransactionMoneyController>(
                              builder: (pinVerify) {
                                return pinVerify.isLoading|| otpCheckController.isLoading ?
                                SizedBox(
                                  width: Dimensions.radiusSizeOverLarge,
                                  height:  Dimensions.radiusSizeOverLarge,
                                  child: Center(
                                    child: CircularProgressIndicator(color: Theme.of(context).primaryColor),
                                  ),
                                ) :
                                Container(
                                  width: Dimensions.radiusSizeOverLarge,
                                  height:  Dimensions.radiusSizeOverLarge,
                                  decoration: BoxDecoration(
                                    borderRadius: BorderRadius.circular(25),
                                    color: Theme.of(context).colorScheme.secondary,
                                  ),
                                  child: Icon(Icons.arrow_forward, color: Colors.black),
                                );
                              },
                            );
                          },
                        ),
                      ),

                    ],
                  )

                ],
              ),
            )


          ],
        ),
      ),
    );
  }

  void _placeWithdrawRequest() {
    Map<String, String> withdrawalMethodField = {};

    for (var method in withdrawMethod!.methodFields) {
      withdrawalMethodField.addAll({'${method.inputName}' : '${method.inputValue}'});
    }

    List<Map<String, String>> withdrawalMethodFieldList = [];
    withdrawalMethodFieldList.add(withdrawalMethodField);

    Map<String, String?> withdrawRequestBody = {};
    withdrawRequestBody = {
      'pin' : Get.find<BottomSliderController>().pin,
      'amount' : '$inputBalance',
      'withdrawal_method_id' : '${withdrawMethod!.id}',
      'withdrawal_method_fields' : jsonEncode(withdrawalMethodFieldList),
    };

    Get.find<TransactionMoneyController>().withDrawRequest(placeBody: withdrawRequestBody);
  }


}

class _MethodFieldWidget extends StatelessWidget {
  final String type;
  final String value;

  const _MethodFieldWidget({required this.type, required this.value});

  @override
  Widget build(BuildContext context) {
    return Row(
      mainAxisAlignment: MainAxisAlignment.spaceBetween,
      children: [
        Text(type, style: rubikLight.copyWith(fontSize: Dimensions.fontSizeDefault),),

        Text(value),
      ],
    );
  }
}






