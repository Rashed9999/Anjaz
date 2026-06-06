import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/features/setting/controllers/profile_screen_controller.dart';
import 'package:amyal_pay/features/splash/controllers/splash_controller.dart';
import 'package:amyal_pay/util/dimensions.dart';
import 'package:amyal_pay/util/styles.dart';

class TransactionAmountItemWidget extends StatelessWidget {
  final String? label, amount;
  const TransactionAmountItemWidget({super.key, this.label, this.amount});

  @override
  Widget build(BuildContext context) {
    return GetBuilder<ProfileController>( builder: (profileController) {
        return AnimatedSwitcher(
          duration: const Duration(milliseconds: 300),
          switchInCurve: Curves.slowMiddle,
          switchOutCurve: Curves.slowMiddle,
          child: !profileController.showBalanceButtonTapped && profileController.isUserBalanceHide() ?
          InkWell(
            onTap: () => profileController.updateBalanceButtonTappedStatus(shouldUpdate: true),
            child: Container(
              decoration: BoxDecoration(
                borderRadius: BorderRadius.circular(Dimensions.paddingSizeSmall),
                color: Theme.of(context).cardColor.withValues(alpha: 0.07),
              ),
              padding: const EdgeInsets.all(Dimensions.paddingSizeSmall),
              child: Row(mainAxisAlignment: MainAxisAlignment.start,children: [
                Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text(label ?? '', style: rubikRegular.copyWith(
                    fontSize: Dimensions.fontSizeSmall,
                    color: Theme.of(context).cardColor,
                  )),

                  Text('${Get.find<SplashController>().configModel?.currencySymbol} ****', style: rubikRegular.copyWith(
                    fontSize: Dimensions.fontSizeSmall,
                    color: Theme.of(context).cardColor,
                  )),

                ]),

                const Spacer(),
              ]),
            ),
          ) :
          Container(
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(Dimensions.paddingSizeSmall),
              color: Theme.of(context).cardColor.withValues(alpha: 0.07),
            ),
            padding: const EdgeInsets.all(Dimensions.paddingSizeSmall),
            child: Row(mainAxisAlignment: MainAxisAlignment.start,children: [
              Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(label ?? '', style: rubikRegular.copyWith(
                  fontSize: Dimensions.fontSizeSmall,
                  color: Theme.of(context).cardColor,
                )),

                Text(amount ?? '', style: rubikMedium.copyWith(
                  fontSize: Dimensions.fontSizeSmall,
                  color: Theme.of(context).cardColor,
                )),
              ]),
            ],
            ),
          ),
        );
      }
    );
  }
}
