import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/common/widgets/custom_ink_well_widget.dart';
import 'package:amyal_pay/common/widgets/custom_showcase_widget.dart';
import 'package:amyal_pay/features/home/domain/models/global_key_manager_model.dart';
import 'package:amyal_pay/features/splash/controllers/splash_controller.dart';
import 'package:amyal_pay/helper/transaction_type.dart';
import 'package:amyal_pay/util/dimensions.dart';
import 'package:amyal_pay/util/images.dart';
import 'package:amyal_pay/features/home/widgets/banner_widget.dart';
import 'package:amyal_pay/features/transaction_money/screens/transaction_balance_input_screen.dart';
import 'package:amyal_pay/features/transaction_money/screens/transaction_money_screen.dart';
import 'package:amyal_pay/util/styles.dart';

class ThemeThreeWidget extends StatelessWidget {
 final GlobalKeyManagerModel keyManager;
 final GlobalKey? lastKey;
  const ThemeThreeWidget({super.key, required this.keyManager, required this.lastKey});

  @override
  Widget build(BuildContext context) {
    return GetBuilder<SplashController>( builder: (splashController) {

      return Column(children: [
        Container(
          decoration: BoxDecoration(
            color: Theme.of(context).primaryColor,
            borderRadius: const BorderRadius.only(
              bottomLeft: Radius.circular(Dimensions.radiusSizeLarge),
              bottomRight: Radius.circular(Dimensions.radiusSizeLarge)
            )
          ),
          width: double.infinity,
          padding : const EdgeInsets.only(
            top: Dimensions.paddingSizeExtraLarge, bottom: Dimensions.paddingSizeExtraLarge,
            left: Dimensions.paddingSizeSmall,
          ),
          child: Builder(
            builder: (context) {
              return CustomShowcaseWidget(
                showcaseKey: keyManager.scrollableKey,
                title: "slide_this_section_to_show_more_options",
                subtitle: "",
                isDone: true,
                padding: const EdgeInsets.all(Dimensions.paddingSizeExtraSmall),
                child: SingleChildScrollView(
                  physics: const ClampingScrollPhysics(),
                  scrollDirection: Axis.horizontal,
                  child: Row(children: [
                    splashController.configModel!.systemFeature!.sendMoneyStatus! ?
                    _CardWidget(
                      image: Images.sendMoneyLogo3,
                      showcaseTitle: 'from_here_you_can_send_your_money',
                      showcaseKey: keyManager.sendMoneyKey,
                      text: 'send_money'.tr.replaceAll(" ", "\n"),
                      isDone: lastKey  == keyManager.sendMoneyKey,
                      onTap: ()=> Get.to(()=> const TransactionMoneyScreen(fromEdit: false,transactionType: 'send_money')),
                    ) : const SizedBox(),

                    if(splashController.configModel!.systemFeature!.cashOutStatus!)
                      _CardWidget(
                        showcaseTitle: "from_here_you_can_cash_out",
                        showcaseKey: keyManager.cashOutKey,
                        image: Images.cashOutLogo3,
                        isDone: lastKey == keyManager.cashOutKey,
                        text: 'cash_out'.tr.replaceAll(" ", "\n"),
                        onTap: ()=> Get.to(()=> const TransactionMoneyScreen(fromEdit: false,transactionType: 'cash_out')),
                      ),

                    if(splashController.configModel!.systemFeature!.addMoneyStatus!) _CardWidget(
                      showcaseKey: keyManager.addMoneyKey,
                      isDone: lastKey == keyManager.addMoneyKey,
                      showcaseTitle: "from_here_you_can_add_money_to_your_account",
                      image: Images.addMoneyLogo3,
                      text: 'add_money'.tr.replaceAll(" ", "\n"),
                      onTap: () => Get.to(const TransactionBalanceInputScreen(transactionType: 'add_money')),
                    ),

                    if(splashController.configModel!.systemFeature!.sendMoneyRequestStatus!) _CardWidget(
                      image: Images.requestMoney3,
                      showcaseKey: keyManager.requestMoneyKey,
                      showcaseTitle: "from_here_you_can_request_money",
                      isDone: lastKey == keyManager.requestMoneyKey,
                      text: 'request_money'.tr.replaceAll(" ", "\n"),
                      onTap: ()=> Get.to(()=> const TransactionMoneyScreen(fromEdit: false,transactionType: 'request_money')),
                    ),

                    if(splashController.configModel!.systemFeature!.withdrawRequestStatus!) _CardWidget(
                      showcaseKey: keyManager.withdrawKey,
                      showcaseTitle: "from_here_you_can_withdraw_request",
                      image: Images.withdrawMoneyLogo3,
                      text: 'withdraw_request'.tr,
                      isDone: true,
                      onTap: ()=> Get.to(()=> const TransactionBalanceInputScreen(
                        transactionType: TransactionType.withdrawRequest,
                      )),
                    ),

                  ]),
                ),
              );
            }
          ),
        ),

        /// Banner..
        const BannerWidget(),
      ]);
    });

  }

}

class _CardWidget extends StatelessWidget {
  final String? image;
  final String? text;
  final VoidCallback? onTap;
  final GlobalKey showcaseKey;
  final String showcaseTitle;
  final bool isDone;
  const _CardWidget({this.image, this.text, this.onTap, required this.showcaseKey, required this.showcaseTitle, this.isDone = false}) ;

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      height: 120, width: Dimensions.themeThreeTransactionCardWidth,
      child: CustomInkWellWidget(onTap: onTap,
        child: CustomShowcaseWidget(
          isDone: isDone,
          title: showcaseTitle,
          subtitle: '',
          showcaseKey: showcaseKey,
          padding: const EdgeInsets.symmetric( horizontal : Dimensions.paddingSizeExtraSmall, vertical: Dimensions.paddingSizeSmall),
          child: Container(
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(Dimensions.radiusSizeExtraSmall),
              color: Colors.white.withValues(alpha:0.1),
            ),
            margin: const EdgeInsets.symmetric(horizontal: Dimensions.paddingSizeExtraSmall),
            padding: const EdgeInsets.symmetric(horizontal:  Dimensions.paddingSizeSmall, vertical: Dimensions.paddingSizeDefault),
            child: Column(mainAxisAlignment: MainAxisAlignment.center, mainAxisSize: MainAxisSize.min, children: [
              Image.asset(image!, fit: BoxFit.contain, height: 35, width: 35,),

              const SizedBox(height: 7),
              Padding(padding: const EdgeInsets.symmetric(horizontal: 10),
                child: Text(text!, textAlign: TextAlign.center, maxLines: 2,
                  style: rubikRegular.copyWith(fontSize: Dimensions.fontSizeDefault, color: Colors.white,
                  ),
                ),
              )
            ]),
          ),
        ),
      ),
    );
  }
}
