import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_currency_input_formatter/flutter_currency_input_formatter.dart';
import 'package:get/get.dart';
import 'package:amial_pay/features/language/controllers/localization_controller.dart';
import 'package:amial_pay/features/setting/controllers/profile_screen_controller.dart';
import 'package:amial_pay/features/splash/controllers/splash_controller.dart';
import 'package:amial_pay/features/transaction_money/controllers/transaction_controller.dart';
import 'package:amial_pay/features/transaction_money/domain/models/purpose_model.dart';
import 'package:amial_pay/helper/price_converter_helper.dart';
import 'package:amial_pay/helper/transaction_type.dart';
import 'package:amial_pay/util/app_constants.dart';
import 'package:amial_pay/util/dimensions.dart';
import 'package:amial_pay/util/images.dart';
import 'package:amial_pay/util/styles.dart';
import 'package:amial_pay/common/widgets/custom_image_widget.dart';
import 'package:amial_pay/features/transaction_money/widgets/input_field_shimmer_widget.dart';

class InputBoxWidget extends StatelessWidget {
  final TextEditingController inputAmountController;
  final FocusNode? focusNode;
  final String? transactionType;

  const InputBoxWidget({
    super.key,
    required this.inputAmountController, this.focusNode, this.transactionType,
  });

  @override
  Widget build(BuildContext context) {
    return GetBuilder<TransactionMoneyController>(
        builder: (transactionMoneyController) {
          return  transactionMoneyController.isLoading ?
          const InputFieldShimmerWidget() :  Column(children: [

            Stack(children: [

              Container(color: Theme.of(context).cardColor,
                child: Column(
                  children: [ Container( width: context.width * 0.6,
                    padding: const EdgeInsets.symmetric(vertical: Dimensions.paddingSizeLarge),
                    child: TextField(
                      inputFormatters: <TextInputFormatter>[
                        CurrencyInputFormatter(
                          decimalDigits: AppConstants.dynamicDecimalPoint,
                          symbolOnLeft: Get.find<SplashController>().configModel!.isCurrencyPositionOnLeft ?? false,
                          currencySymbol:'${Get.find<SplashController>().configModel!.currencySymbol}',
                        ),
                      ],
                      keyboardType: const TextInputType.numberWithOptions(decimal: true),
                      controller: inputAmountController,
                      focusNode: focusNode,
                      textAlignVertical: TextAlignVertical.center,
                      textAlign: TextAlign.center,
                      style: rubikMedium.copyWith(fontSize: 34, color: Theme.of(context).textTheme.titleLarge!.color),
                      decoration: InputDecoration(
                        isCollapsed : true,
                        hintText:PriceConverterHelper.balanceInputHint(),
                        border : InputBorder.none, focusedBorder: const UnderlineInputBorder(),
                        hintStyle: rubikMedium.copyWith(
                          fontSize: 34, color: Theme.of(context).textTheme.titleLarge!.color!.withValues(alpha:0.7),
                        ),

                      ),

                    ),
                  ),

                    Center( child: GetBuilder<ProfileController>(
                      builder: (profController)=> profController.isLoading ? Center(
                        child: CircularProgressIndicator(color: Theme.of(context).textTheme.titleLarge!.color),
                      ) :
                      Text(
                        '${'available_balance'.tr} ${PriceConverterHelper.availableBalance()}',
                        style: rubikRegular.copyWith(
                          fontSize: Dimensions.fontSizeLarge,
                          color: Theme.of(context).hintColor.withValues(alpha: 0.6),
                        ),
                      ),
                    ),),
                    const SizedBox(height: Dimensions.paddingSizeDefault,),


                  ],
                ),
              ),

              if(transactionType == TransactionType.sendMoney) Positioned(
                left: Get.find<LocalizationController>().isLtr ? Dimensions.paddingSizeLarge : null,
                bottom: Get.find<LocalizationController>().isLtr ? Dimensions.paddingSizeExtraLarge : null,
                right:  Get.find<LocalizationController>().isLtr ? null : Dimensions.paddingSizeLarge,
                child: CustomImageWidget(
                  image:'${Get.find<SplashController>().configModel!.baseUrls!.purposeImageUrl
                  }/${transactionMoneyController.purposeList!.isEmpty ? PurposeModel().logo :
                  transactionMoneyController.purposeList?[transactionMoneyController.selectedItem].logo}',
                  height: 50, width: 50, fit: BoxFit.cover,
                  placeholder: Images.sendMoneyLogo,
                ),
              ),

            ]),

            Container(
              height: Dimensions.dividerSizeSmall,
              color: Theme.of(context).dividerColor,
            ),



          ]);
        }
    );
  }
}





