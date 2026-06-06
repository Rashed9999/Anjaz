import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/common/widgets/custom_button_widget.dart';
import 'package:amyal_pay/common/widgets/custom_showcase_widget.dart';
import 'package:amyal_pay/features/home/domain/models/global_key_manager_model.dart';
import 'package:amyal_pay/features/setting/controllers/profile_screen_controller.dart';
import 'package:amyal_pay/features/splash/controllers/splash_controller.dart';
import 'package:amyal_pay/helper/price_converter_helper.dart';
import 'package:amyal_pay/helper/transaction_type.dart';
import 'package:amyal_pay/util/dimensions.dart';
import 'package:amyal_pay/util/images.dart';
import 'package:amyal_pay/util/styles.dart';
import 'package:amyal_pay/features/home/widgets/banner_widget.dart';
import 'package:amyal_pay/features/home/widgets/option_card_widget.dart';
import 'package:amyal_pay/features/transaction_money/screens/transaction_balance_input_screen.dart';
import 'package:amyal_pay/features/transaction_money/screens/transaction_money_screen.dart';

class ThemeOneWidget extends StatelessWidget {
  final GlobalKeyManagerModel keyManager;
  final GlobalKey? lastKey;
  const ThemeOneWidget({super.key,required this.keyManager, this.lastKey});
  @override
  Widget build(BuildContext context) {
    return GetBuilder<SplashController>(
        builder: (splashController) {
          return Stack(children: [
            Container(
              height: 180.0,
              decoration: BoxDecoration(
                color: Theme.of(context).primaryColor,
                borderRadius: const BorderRadius.only(
                  bottomLeft: Radius.circular(Dimensions.paddingSizeLarge),
                  bottomRight: Radius.circular(Dimensions.paddingSizeLarge),
                ),
              ),
            ),

            Positioned(child: Column(children: [
              const SizedBox(height: Dimensions.paddingSizeSmall),

              Container(
                alignment: Alignment.center,
                width: double.maxFinite,
                padding: const EdgeInsets.all(Dimensions.paddingSizeSmall),
                margin: const  EdgeInsets.symmetric(
                  horizontal: Dimensions.paddingSizeLarge,
                  vertical: Dimensions.paddingSizeLarge,
                ),
                decoration: BoxDecoration(
                  borderRadius: BorderRadius.circular(Dimensions.paddingSizeSmall),
                  color: Theme.of(context).cardColor,
                ),
                child: Row(
                  mainAxisAlignment: MainAxisAlignment.start,
                  crossAxisAlignment: CrossAxisAlignment.center,
                  children: [

                    GetBuilder<ProfileController>( builder: (profileController) {
                      return CustomShowcaseWidget(
                        title: "here_you_get_your_available_balance".tr,
                        showcaseKey: keyManager.appbarBalanceKey,
                        subtitle: "",
                        isDone: lastKey == keyManager.appbarBalanceKey,
                        padding: EdgeInsets.zero,
                        child: AnimatedSwitcher(
                          duration: const Duration(milliseconds: 300),
                          switchInCurve: Curves.slowMiddle,
                          switchOutCurve: Curves.slowMiddle,
                          child: !profileController.showBalanceButtonTapped && profileController.isUserBalanceHide() ?
                          InkWell(
                            onTap: () => profileController.updateBalanceButtonTappedStatus(shouldUpdate: true),
                            child: Container(
                              decoration: BoxDecoration(
                                  borderRadius: BorderRadius.circular(Dimensions.radiusSizeExtraExtraLarge),
                                  color: Theme.of(context).hintColor.withValues(alpha: 0.07)
                              ),
                              padding: const EdgeInsets.symmetric(horizontal: Dimensions.paddingSizeExtraLarge, vertical: Dimensions.radiusSizeExtraSmall),
                              child: Row(mainAxisSize: MainAxisSize.min, children: [
                                const Icon(Icons.touch_app_outlined, size: 15, color: Colors.black),
                                const SizedBox(width: 3),

                                Text("tap_for_balance".tr, style: rubikRegular.copyWith(
                                  fontSize: Dimensions.radiusSizeSmall,
                                  color: Theme.of(context).textTheme.bodyLarge?.color,
                                )),
                              ]),
                            ),
                          ) :
                          Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisAlignment: MainAxisAlignment.center, children: [
                            Row(
                              children: [
                                Text(PriceConverterHelper.balanceWithSymbol(balance: '${(profileController.userInfo?.balance ?? 0) > 0 ? (profileController.userInfo?.balance ?? 0) : 0 }'), style: rubikMedium.copyWith(
                                  color: Theme.of(context).textTheme.bodyLarge?.color, fontSize: Dimensions.fontSizeExtraLarge,
                                )),

                                if((profileController.userInfo?.balance ?? 0) < 0) ...[
                                  SizedBox(width: Dimensions.paddingSizeSmall),


                                  Text('(${'due'.tr} -${PriceConverterHelper.convertPrice(profileController.userInfo?.balance?.abs() ?? 0)})', style: rubikRegular.copyWith(
                                      fontSize: Dimensions.fontSizeDefault,
                                      color: Theme.of(context).colorScheme.error.withValues(alpha: 0.8),
                                      height: 1.0,
                                  )),
                                  SizedBox(width: Dimensions.paddingSizeSmall),

                                  Tooltip(
                                    triggerMode: TooltipTriggerMode.tap,
                                    message: 'your_balance_will_adjust_after_added_balance'.tr,
                                    child: Icon(Icons.info, color: Theme.of(context).colorScheme.error.withValues(alpha: 0.8), size: Dimensions.paddingSizeDefault),
                                  ),

                                ],
                              ],
                            ),

                            Text('available_balance'.tr, style: rubikRegular.copyWith(
                                fontSize: Dimensions.fontSizeDefault,
                                color: Theme.of(context).textTheme.bodyLarge?.color?.withValues(alpha: 0.6),
                                height: 1.0
                            )),
                          ]),
                        ),
                      );
                    }),

                    const Spacer(),

                    if(splashController.configModel!.systemFeature!.addMoneyStatus!)
                      SizedBox(
                        width: 100,
                        child: CustomButtonWidget(
                          onTap: ()=> Get.to(const TransactionBalanceInputScreen(transactionType: 'add_money')),
                          color: Theme.of(context).colorScheme.secondary,
                          buttonText: 'add_money'.tr,
                          buttonTextStyle: rubikRegular.copyWith(fontSize: Dimensions.fontSizeSmall, color: Theme.of(context).textTheme.bodyLarge?.color),
                          prefixIcon: Icon(Icons.add_circle, color: Theme.of(context).textTheme.bodyLarge?.color, size: Dimensions.paddingSizeLarge),
                          borderRadius: Dimensions.paddingSizeExtraSmall,
                        ),
                      ),
                  ],
                ),
              ),

              /// Cards...
              SizedBox(
                height: 120.0,
                child: Padding(
                  padding: const EdgeInsets.symmetric(horizontal: Dimensions.fontSizeExtraSmall),
                  child: Row(children: [

                    if(splashController.configModel!.systemFeature!.sendMoneyStatus!) Expanded(child: OptionCardWidget(
                      showcaseKey: keyManager.sendMoneyKey,
                      showcaseTitle: 'from_here_you_can_send_your_money',
                      image: Images.sendMoneyLogo, text: 'send_money'.tr.replaceAll(' ', '\n'),
                      color: Theme.of(context).cardColor,
                      isDone: lastKey == keyManager.sendMoneyKey,
                      onTap: ()=> Get.to(()=> const TransactionMoneyScreen(
                        fromEdit: false,
                        transactionType: TransactionType.sendMoney,
                      )),
                    )),

                    if(splashController.configModel!.systemFeature!.cashOutStatus!)Expanded(child: OptionCardWidget(
                      showcaseTitle: "from_here_you_can_cash_out",
                      showcaseKey: keyManager.cashOutKey,
                      image: Images.cashOutLogo, text: 'cash_out'.tr.replaceAll(' ', '\n'),
                      isDone: lastKey == keyManager.cashOutKey,
                      color: Theme.of(context).cardColor,
                      onTap: ()=> Get.to(()=> const TransactionMoneyScreen(
                        fromEdit: false,
                        transactionType: TransactionType.cashOut,
                      )),
                    )),

                    if(splashController.configModel!.systemFeature!.sendMoneyRequestStatus!)Expanded(child: OptionCardWidget(
                      showcaseKey: keyManager.requestMoneyKey,
                      showcaseTitle: "from_here_you_can_request_money",
                      image: Images.requestMoney, text: 'request_money'.tr,
                      isDone: lastKey == keyManager.requestMoneyKey,
                      color: Theme.of(context).cardColor,
                      onTap: ()=> Get.to(()=> const TransactionMoneyScreen(
                        fromEdit: false,
                        transactionType: TransactionType.requestMoney,
                      )),
                    )),

                    if(splashController.configModel!.systemFeature!.sendMoneyRequestStatus!) Expanded(child: OptionCardWidget(
                      showcaseKey: keyManager.sendMoneyRequestKey,
                      isDone: lastKey == keyManager.sendMoneyRequestKey,
                      showcaseTitle: "from_here_you_can_check_send_requests",
                      image: Images.requestListImage2,
                      text: 'withdraw_request'.tr,
                      color: Theme.of(context).cardColor,
                      onTap: () => Get.to(()=> const TransactionBalanceInputScreen(
                        transactionType: TransactionType.withdrawRequest,
                      )),
                    ),
                    ),
                  ]),
                ),
              ),


              const BannerWidget(),

            ])),
          ]);
        }
    );
  }

}
