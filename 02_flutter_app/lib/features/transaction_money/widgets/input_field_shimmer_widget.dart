import 'package:flutter/material.dart';
import 'package:get/get_utils/src/extensions/internacionalization.dart';
import 'package:shimmer/shimmer.dart';
import 'package:amyal_pay/helper/price_converter_helper.dart';
import 'package:amyal_pay/util/dimensions.dart';
import 'package:amyal_pay/util/images.dart';
import 'package:amyal_pay/util/styles.dart';

class InputFieldShimmerWidget extends StatelessWidget {
  const InputFieldShimmerWidget({super.key});

  @override
  Widget build(BuildContext context) {
    return Shimmer.fromColors(
      baseColor: Colors.grey, highlightColor: Colors.grey[200]!,
      child: Column(children: [
        Stack(children: [
          Column(children: [
            Padding(
              padding: const EdgeInsets.only(top: Dimensions.paddingSizeExtraLarge, bottom: Dimensions.paddingSizeLarge),
              child: TextField(
                enabled: false,
                textAlignVertical: TextAlignVertical.center,
                textAlign: TextAlign.center, style: rubikMedium.copyWith(fontSize: 34, color: Theme.of(context).primaryColor),
                decoration: InputDecoration(border: InputBorder.none, hintText:PriceConverterHelper.balanceInputHint(), hintStyle: rubikMedium.copyWith(fontSize: 34, color: Theme.of(context).primaryColor) ),
              ),
            ),

            Center(child: Text('${'available_balance'.tr} ${PriceConverterHelper.availableBalance()}', style: rubikRegular.copyWith(fontSize: Dimensions.fontSizeLarge, color: Theme.of(context).hintColor.withValues(alpha: 0.4)))),
            const SizedBox(height: Dimensions.paddingSizeDefault),
          ]),

          Positioned(left: Dimensions.paddingSizeLarge, bottom: Dimensions.paddingSizeExtraLarge, child: Image.asset(Images.logo,width: 50.0)),

          Positioned(right: 10, bottom: 5, child: Image.asset(Images.inputStackDesing, width: 150.0,filterQuality: FilterQuality.high)),
        ]),

        const SizedBox(height: Dimensions.dividerSizeMedium),

      ]),
    );
  }
}
