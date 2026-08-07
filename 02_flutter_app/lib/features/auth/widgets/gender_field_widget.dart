import 'package:amial_pay/features/setting/controllers/edit_profile_controller.dart';
import 'package:amial_pay/util/dimensions.dart';
import 'package:amial_pay/util/images.dart';
import 'package:flutter/material.dart';
import 'package:get/get.dart';

import 'gender_card_widget.dart';
class GenderFieldWidget extends StatelessWidget {
  final bool fromEditProfile;
  const GenderFieldWidget({
    super.key,
    this.fromEditProfile = false,
  });

  @override
  Widget build(BuildContext context) {
    return GetBuilder<EditProfileController>(builder: (controller){
      return Container(
        width: double.infinity,
        padding:  EdgeInsets.only(
          top: Dimensions.paddingSizeExtraLarge,
          left: fromEditProfile ? Dimensions.paddingSizeSmall : Dimensions.paddingSizeDefault,
          right: fromEditProfile ? Dimensions.paddingSizeSmall : Dimensions.paddingSizeDefault,
          bottom: Dimensions.paddingSizeDefault,
        ),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          const SizedBox(height: Dimensions.paddingSizeDefault),

          Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [

              GenderCardWidget(
                icon: Images.male,
                text: 'male'.tr,
                color: controller.gender?.toLowerCase() == 'male'
                    ? Theme.of(context).colorScheme.secondary
                    : null,
                onTap: (){
                  controller.setGender('Male');
                },
              ),

              GenderCardWidget(
                icon: Images.female,
                text: 'female'.tr,
                color: controller.gender?.toLowerCase() == 'female'
                    ? Theme.of(context).colorScheme.secondary
                    : null,
                onTap: (){
                  controller.setGender('Female');
                },
              ),

              GenderCardWidget(
                icon: Images.other,
                text: 'other'.tr,
                color: controller.gender?.toLowerCase() == 'other'
                    ? Theme.of(context).colorScheme.secondary
                    : null,
                onTap: (){
                  controller.setGender('Other');
                },
              ),
            ])
        ]),
      );
    });
  }
}