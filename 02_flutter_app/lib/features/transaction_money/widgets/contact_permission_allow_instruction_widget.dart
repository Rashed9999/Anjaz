import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amial_pay/util/dimensions.dart';
import 'package:amial_pay/util/styles.dart';

class ContactPermissionAllowInstructionWidget extends StatelessWidget {
  const ContactPermissionAllowInstructionWidget({
    super.key,
  });

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        const SizedBox(height: Dimensions.paddingSizeDefault),
        Text(
          'you_can_allow_from'.tr,
          style: rubikRegular.copyWith(fontSize: Dimensions.fontSizeDefault),
          textAlign: TextAlign.center,
        ),
        const SizedBox(height: Dimensions.paddingSizeExtraSmall),
        Wrap(
          alignment: WrapAlignment.center,
          crossAxisAlignment: WrapCrossAlignment.center,
          children: [
            Text(
              'app_setting'.tr,
              style: rubikMedium.copyWith(
                fontSize: Dimensions.fontSizeDefault,
                color: Theme.of(context).primaryColor,
              ),
            ),
            Icon(
              Icons.arrow_forward_ios,
              size: Dimensions.paddingSizeDefault,
              color: Theme.of(context).primaryColor,
            ),
            Text(
              'permissions'.tr,
              style: rubikMedium.copyWith(
                fontSize: Dimensions.fontSizeDefault,
                color: Theme.of(context).primaryColor,
              ),
            ),
            Icon(
              Icons.arrow_forward_ios,
              size: Dimensions.paddingSizeDefault,
              color: Theme.of(context).primaryColor,
            ),
            Text(
              'contacts'.tr,
              style: rubikMedium.copyWith(
                fontSize: Dimensions.fontSizeDefault,
                color: Theme.of(context).primaryColor,
              ),
            ),
            Icon(
              Icons.arrow_forward_ios,
              size: Dimensions.paddingSizeDefault,
              color: Theme.of(context).primaryColor,
            ),
            Text(
              'allow'.tr,
              style: rubikMedium.copyWith(
                fontSize: Dimensions.fontSizeDefault,
                color: Theme.of(context).primaryColor,
              ),
            ),
          ],
        ),
      ],
    );
  }
}