import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:shimmer/shimmer.dart';
import 'package:amyal_pay/features/auth/controllers/auth_controller.dart';
import 'package:amyal_pay/features/setting/controllers/profile_screen_controller.dart';
import 'package:amyal_pay/util/dimensions.dart';
import 'package:amyal_pay/util/images.dart';
import 'package:amyal_pay/util/styles.dart';
import 'package:amyal_pay/common/widgets/custom_ink_well_widget.dart';

import 'confirm_pin_bottom_sheet.dart';

class StatusMenu extends StatelessWidget {
  final String? title;
  final Widget? leading;
  final bool isAuth;
  const StatusMenu({super.key,
   this.title, this.leading, this.isAuth = false,
  });

  @override
  Widget build(BuildContext context) {
    final profileController = Get.find<ProfileController>();
    final authController = Get.find<AuthController>();

    return CustomInkWellWidget(
      onTap: () => Get.defaultDialog(barrierDismissible: false, title: '4digit_pin'.tr, content: ConfirmPinBottomSheet(
        callBack: isAuth ? authController.setBiometric : profileController.twoFactorOnTap, isAuth: isAuth,
      ),),
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: Dimensions.paddingSizeSmall,horizontal: Dimensions.paddingSizeDefault),
        child: Row(children: [
          leading!,
          const SizedBox(width: Dimensions.paddingSizeDefault),
          Text(title!,style: rubikRegular.copyWith(fontSize: Dimensions.fontSizeLarge)),
          const Spacer(),
          GetBuilder<AuthController>(builder: (authController) {
              return GetBuilder<ProfileController>(builder: (profController) {

                bool? isOn = isAuth ? (authController.biometric && authController.bioList.isNotEmpty) : profController.userInfo!.twoFactor;
               return profController.isLoading ? Center(child: Text('off'.tr))
                   : Text(isOn! ? 'on'.tr : 'off'.tr);

            });
          })],
        ),
      ),

    );
  }
}


class TwoFactorShimmer extends StatelessWidget {
  const TwoFactorShimmer({super.key});

  @override
  Widget build(BuildContext context) {
    return Container(
      color: Theme.of(context).cardColor,
      child: Shimmer.fromColors(
        baseColor: Theme.of(context).hintColor.withValues(alpha: 0.1),
        highlightColor: Theme.of(context).hintColor.withValues(alpha: 0.2),
        child : Padding(
          padding: const EdgeInsets.symmetric(vertical: Dimensions.paddingSizeSmall,horizontal: Dimensions.paddingSizeDefault),
          child: Row(children: [
            Image.asset(Images.twoFactorAuthentication,width: 28.0),
            const SizedBox(width: Dimensions.paddingSizeDefault),

            Text('two_factor_authentication'.tr,style: rubikRegular.copyWith(fontSize: Dimensions.fontSizeLarge)),
            const Spacer(),

            GetBuilder<ProfileController>(builder: (profController)=> profController.isLoading ? Center(child: Text('off'.tr)) : Text(profController.userInfo!.twoFactor! ? 'on'.tr : 'off'.tr)),

          ]),
        ),
      ),
    );
  }
}
