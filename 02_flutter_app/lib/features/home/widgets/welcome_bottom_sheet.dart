import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amial_pay/features/auth/controllers/auth_controller.dart';
import 'package:amial_pay/features/auth/domain/models/user_short_data_model.dart';
import 'package:amial_pay/features/home/widgets/show_case/showcaseview.dart';
import 'package:amial_pay/features/setting/controllers/profile_screen_controller.dart';
import 'package:amial_pay/util/app_constants.dart';
import 'package:amial_pay/util/dimensions.dart';
import 'package:amial_pay/util/images.dart';
import 'package:amial_pay/util/styles.dart';

class WelcomeBottomSheet extends StatelessWidget {
  final List<GlobalKey<State<StatefulWidget>>> keys;
  final BuildContext showCaseContext;
  const WelcomeBottomSheet({super.key, required this.keys, required this.showCaseContext,});

  @override
  Widget build(BuildContext context) {
    return GetBuilder<ProfileController>(builder: (profileController){
      UserShortDataModel? userData  = Get.find<AuthController>().getUserData();

      return PopScope(
        canPop: false,
        onPopInvokedWithResult: (value, result){
          ShowCaseWidget.of(showCaseContext).startShowCase(keys);
          Get.find<AuthController>().setWelcomeBottomSheetStatus(false);
        },
        child: Container(
          decoration: BoxDecoration(
            color: Theme.of(context).cardColor,
            borderRadius : const BorderRadius.only(
              topLeft: Radius.circular(Dimensions.paddingSizeExtraLarge),
              topRight : Radius.circular(Dimensions.paddingSizeExtraLarge),
            ),
          ),
          child: Stack( children: [
            SingleChildScrollView(
              child: Column(mainAxisSize: MainAxisSize.min, children: [

                Container(height: 5, width: 40,
                  decoration: BoxDecoration(
                      color: Theme.of(Get.context!).hintColor.withValues(alpha:0.2),
                      borderRadius: BorderRadius.circular(Dimensions.radiusSizeSmall)
                  ),
                  margin: const EdgeInsets.symmetric(vertical: Dimensions.paddingSizeDefault ),
                ),
                const SizedBox(height: Dimensions.paddingSizeSmall),

                Image.asset(Images.logo, height: 100, fit: BoxFit.fitHeight,),
                const SizedBox(height: Dimensions.paddingSizeExtraLarge),

                Text("${"welcome_to".tr} ${AppConstants.appName}!"  , style: rubikSemiBold.copyWith(
                    fontSize: Dimensions.fontSizeExtraLarge
                )),
                const SizedBox(height: Dimensions.paddingSizeExtraSmall),
                Padding(padding: const EdgeInsets.symmetric(horizontal: Dimensions.paddingSizeLarge),
                  child: Text("${"hello".tr}, ${ profileController.userInfo?.fName?.capitalizeFirst ?? userData?.name ?? ""} ${profileController.userInfo?.lName?.capitalizeFirst ?? ""}!"  , style: rubikRegular.copyWith(
                    fontSize: Dimensions.fontSizeExtraLarge, color: const Color(0xff38af79),
                  ), maxLines: 1, overflow: TextOverflow.ellipsis,),
                ),

                const SizedBox(height: Dimensions.paddingSizeExtraLarge),

                Padding(padding: const EdgeInsets.symmetric(horizontal: Dimensions.paddingSizeDefault * 2,),
                  child: Text( "welcome_message_for_sign_up".tr, maxLines: 3,
                    style: rubikRegular.copyWith(color: Theme.of(context).textTheme.bodyMedium?.color?.withValues(alpha:0.7), fontSize: Dimensions.fontSizeLarge),
                    textAlign: TextAlign.center, overflow: TextOverflow.ellipsis,
                  ),
                ),
                const SizedBox(height: Dimensions.paddingSizeLarge * 1.3),


                const SizedBox(height: Dimensions.paddingSizeLarge),

              ]),
            ),

            Positioned(
              right: 15, top: 15,
              child: InkWell(
                onTap: ()=> Get.back(),
                child: Container(
                  decoration: BoxDecoration(
                    shape: BoxShape.circle,
                    color: Theme.of(context).hintColor.withValues(alpha:0.1)
                  ),
                  padding: const EdgeInsets.all(7),
                  child: const Icon(Icons.close, size: 20,),
                ),
              ),
            )
          ]),
        ),
      );
    });
  }
}
