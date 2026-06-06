import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/features/home/controllers/home_controller.dart';
import 'package:amyal_pay/features/setting/controllers/profile_screen_controller.dart';
import 'package:amyal_pay/util/dimensions.dart';
import 'package:amyal_pay/util/styles.dart';
class UserNameWidget extends StatelessWidget {
  const UserNameWidget({super.key});

  @override
  Widget build(BuildContext context) {

    return Column(
      mainAxisSize: MainAxisSize.min,
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        GetBuilder<ProfileController>(builder: (controller)=> controller.userInfo != null
            ? Text(
          '${'hi'.tr} ${controller.userInfo?.fName ?? ''} ${controller.userInfo?.lName ?? ''}',
          textAlign: TextAlign.start, maxLines: 1,
          overflow: TextOverflow.ellipsis,
          style: rubikLight.copyWith(
            fontSize: Dimensions.fontSizeDefault,
            color: Colors.white.withValues(alpha:0.5),
          ),
        )
            : Text('hi_user'.tr,style: rubikLight.copyWith(
            fontSize: Dimensions.fontSizeDefault,
            color: Colors.white.withValues(alpha:0.5))),
        ),

        GetBuilder<HomeController>(builder: (controller){
          return Text(
              controller.greetingMessage(), style: rubikRegular.copyWith(
            fontSize: Dimensions.fontSizeSemiLarge, color: Colors.white,
          ));
        }),
      ],
    );
  }
}