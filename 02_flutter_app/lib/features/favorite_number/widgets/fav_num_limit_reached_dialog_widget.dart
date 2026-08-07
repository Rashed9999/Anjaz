import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amial_pay/common/widgets/custom_asset_image_widget.dart';
import 'package:amial_pay/common/widgets/custom_button_widget.dart';
import 'package:amial_pay/features/favorite_number/controllers/fav_number_controller.dart';
import 'package:amial_pay/util/dimensions.dart';
import 'package:amial_pay/util/images.dart';
import 'package:amial_pay/util/styles.dart';

class FavNumLimitReachedDialogWidget extends StatelessWidget {
  const FavNumLimitReachedDialogWidget({super.key});

  @override
  Widget build(BuildContext context) {
    final double widthSize = MediaQuery.sizeOf(context).width;


    return Dialog(
      insetPadding: const EdgeInsets.symmetric(horizontal: Dimensions.paddingSizeLarge),
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(Dimensions.paddingSizeLarge)),
      child: Padding(
        padding: const EdgeInsets.all(Dimensions.paddingSizeLarge).copyWith(top: Dimensions.paddingSizeSmall),
        child: Column(mainAxisSize: MainAxisSize.min, children: [

          Align(
            alignment: Alignment.centerRight,
            child: InkWell(
              splashColor: Colors.transparent,
              highlightColor: Colors.transparent,
              onTap: ()=> Navigator.pop(context),
              child: Container(
                transform: Matrix4.translationValues(10, 0, 0),
                decoration: BoxDecoration(
                  color: Theme.of(context).hintColor.withValues(alpha: 0.1),
                  shape: BoxShape.circle,
                ),
                padding: const EdgeInsets.all(Dimensions.paddingSizeSmall),
                child: Icon(Icons.clear, color: Theme.of(context).textTheme.bodyLarge?.color, size: Dimensions.paddingSizeDefault),
              ),
            ),
          ),
          const SizedBox(height: Dimensions.paddingSizeLarge),
          
          const CustomAssetImageWidget(Images.starIcon, height: 80, width: 80),
          const SizedBox(height: Dimensions.paddingSizeLarge),

          Text('reached_the_maximum_limit'.tr, style: rubikSemiBold.copyWith(
            fontSize: Dimensions.fontSizeSemiLarge,
            color: Theme.of(context).primaryColor,
          ), overflow: TextOverflow.ellipsis),
          const SizedBox(height: Dimensions.paddingSizeExtraSmall),

          Text(
            '${'you_have_already_selected'.tr} ${Get.find<FavNumberController>().favouriteListModel?.total} ${'favorite_numbers'.tr} ',
            maxLines: 2,
            overflow: TextOverflow.ellipsis,
            style: rubikRegular.copyWith(
              fontSize: Dimensions.radiusSizeSmall,
              color: Theme.of(context).hintColor,
            ),
          ),
          const SizedBox(height: Dimensions.radiusSizeExtraExtraLarge),

          SizedBox(
            width: widthSize * 0.25,
            height: Dimensions.paddingSizeOverLarge,
            child: CustomButtonWidget(
              onTap: ()=> Navigator.pop(context),
              buttonText: 'okay'.tr,
              color: Theme.of(context).colorScheme.secondary,
              buttonTextStyle: rubikSemiBold.copyWith(color: Theme.of(context).textTheme.bodyLarge?.color),
              borderRadius: Dimensions.radiusSizeExtraSmall,
            ),
          ),
        ]),
      ),
    );
  }
}
