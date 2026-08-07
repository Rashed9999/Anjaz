import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amial_pay/features/auth/controllers/auth_controller.dart';
import 'package:amial_pay/features/home/widgets/show_case/models/tooltip_action_button.dart';
import 'package:amial_pay/features/home/widgets/show_case/showcaseview.dart';
import 'package:amial_pay/util/dimensions.dart';
import 'package:amial_pay/util/styles.dart';

class CustomShowcaseWidget extends StatelessWidget {
  final GlobalKey showcaseKey;
  final String title;
  final String subtitle;
  final Widget child;
  final EdgeInsets? padding;
  final BorderRadius ? radius;
  final bool isDone;
  const CustomShowcaseWidget({super.key, required this.title, required this.subtitle, required this.child, required this.showcaseKey, this.padding, this.radius, this.isDone = false});

  @override
  Widget build(BuildContext context) {


    return Showcase(
      tooltipActions: [
        TooltipActionButton(
          backgroundColor: Colors.transparent,
          type: TooltipDefaultActionType.skip,
          name: !isDone ? "skip_tour".tr : "",
          textStyle: rubikRegular.copyWith(fontSize: Dimensions.fontSizeLarge ),
          padding: const EdgeInsets.symmetric(horizontal: Dimensions.paddingSizeExtraSmall, vertical: 3),
          onTap: !isDone ? (){
            ShowCaseWidget.of(context).dismiss();
            Get.find<AuthController>().setTourWidgetStatus(false);
            if(GetPlatform.isAndroid){
              FirebaseMessaging.instance.requestPermission();
            }
          } : (){}
        ),

        TooltipActionButton(
          type: TooltipDefaultActionType.next,
          name: isDone ? 'done'.tr : "next".tr,
          textStyle: rubikMedium.copyWith(fontSize: Dimensions.fontSizeLarge - 2 , color: Colors.white),
          backgroundColor: Theme.of(context).primaryColor,
          padding: const EdgeInsets.symmetric(horizontal: Dimensions.paddingSizeExtraLarge, vertical: Dimensions.paddingSizeExtraSmall),
        ),
      ],
      tooltipBackgroundColor: Theme.of(context).cardColor,
      key: showcaseKey,
      disableBarrierInteraction: true,
      targetPadding: padding ?? const EdgeInsets.symmetric(vertical: 2, horizontal: 7),
      disableMovingAnimation: true,
      titlePadding: const EdgeInsets.only(left: Dimensions.paddingSizeExtraSmall, right: Dimensions.paddingSizeExtraSmall,),
      titleTextStyle: rubikRegular.copyWith(fontSize: Dimensions.fontSizeLarge , color: Theme.of(context).primaryColor),
      description: subtitle.tr,
      tooltipPadding: const EdgeInsets.all(15),
      targetBorderRadius: radius ?? BorderRadius.circular(Dimensions.radiusSizeExtraSmall),
      title: title.tr,
      child: child,
    );
  }
}
