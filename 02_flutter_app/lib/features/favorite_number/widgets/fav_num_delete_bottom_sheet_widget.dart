import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/common/widgets/custom_button_widget.dart';
import 'package:amyal_pay/features/favorite_number/controllers/fav_number_controller.dart';
import 'package:amyal_pay/helper/custom_snackbar_helper.dart';
import 'package:amyal_pay/util/dimensions.dart';
import 'package:amyal_pay/util/styles.dart';

class FavNumDeleteBottomSheetWidget extends StatelessWidget {
  final int? id;
  const FavNumDeleteBottomSheetWidget({super.key, required this.id});

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.all(Dimensions.paddingSizeSmall),
      child: Column(mainAxisSize: MainAxisSize.min, children: [

        Align(
          alignment: Alignment.centerRight,
          child: InkWell(
            splashColor: Colors.transparent,
            highlightColor: Colors.transparent,
            onTap: ()=> Navigator.pop(context),
            child: Container(
              decoration: BoxDecoration(
                color: Theme.of(context).hintColor.withValues(alpha: 0.1),
                shape: BoxShape.circle,
              ),
              padding: const EdgeInsets.all(Dimensions.paddingSizeSmall),
              child: Icon(Icons.clear, color: Theme.of(context).textTheme.bodyLarge?.color, size: Dimensions.paddingSizeDefault),
            ),
          ),
        ),

        Padding(
          padding: const EdgeInsets.symmetric(horizontal: Dimensions.paddingSizeDefault, vertical: Dimensions.paddingSizeLarge),
          child: Column(children: [

            Icon(Icons.delete_forever_rounded, size: Dimensions.paddingSizeOverLarge, color: Theme.of(context).colorScheme.error),
            const SizedBox(height: Dimensions.paddingSizeLarge),

            Padding(
              padding: const EdgeInsets.symmetric(horizontal: Dimensions.paddingSizeExtraLarge),
              child: Text('are_you_sure_you_want_to_delete_this_number'.tr, style: rubikMedium.copyWith(
                fontSize: Dimensions.fontSizeLarge,
                color: Theme.of(context).primaryColor,
              ), textAlign: TextAlign.center),
            ),
            const SizedBox(height: Dimensions.radiusSizeExtraLarge),

            Row(mainAxisAlignment: MainAxisAlignment.spaceBetween,children: [

              Expanded(child: SizedBox(
                height: Dimensions.paddingSizeOverLarge,
                child: CustomButtonWidget(
                  onTap: ()=> Navigator.pop(context),
                  buttonText: 'no'.tr,
                  color: Theme.of(context).hintColor.withValues(alpha: 0.08),
                  buttonTextStyle: rubikSemiBold.copyWith(color: Theme.of(context).primaryColor),
                  borderRadius: Dimensions.radiusSizeExtraSmall,
                ),
              )),
              const SizedBox(width: Dimensions.paddingSizeSmall),

              Expanded(child: SizedBox(
                height: Dimensions.paddingSizeOverLarge,
                child: GetBuilder<FavNumberController>(
                  builder: (favNumberController) {
                    return CustomButtonWidget(
                      isLoading: favNumberController.isLoading,
                      onTap: () async {
                        final bool isSuccess = await favNumberController.deleteFavoriteNumber(id);

                        if(isSuccess) {
                          showCustomSnackBarHelper('favorite_number_deleted_successfully'.tr, isError: false);
                        }
                      },
                      buttonText: 'yes_delete'.tr,
                      color: Theme.of(context).colorScheme.error,
                      buttonTextStyle: rubikSemiBold.copyWith(color: Theme.of(context).cardColor),
                      borderRadius: Dimensions.radiusSizeExtraSmall,
                    );
                  }
                ),
              )),
            ]),
          ]),
        ),
      ]),
    );
  }
}
