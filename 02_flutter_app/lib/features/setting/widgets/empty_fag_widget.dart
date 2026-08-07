import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amial_pay/util/dimensions.dart';
import 'package:amial_pay/util/images.dart';
import 'package:amial_pay/util/styles.dart';

class EmptyFagWidget extends StatelessWidget {
  final bool isCategoryWise;
  const EmptyFagWidget({super.key,  this.isCategoryWise = true});

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: Dimensions.paddingSizeDefault),
      child: Center(
        child: Container(
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(Dimensions.radiusSizeSmall),
            color: Theme.of(context).cardColor
          ),
          margin: EdgeInsets.only(top: Dimensions.paddingSizeDefault, bottom: Get.height * 0.1),
          padding: const EdgeInsets.symmetric(horizontal: Dimensions.paddingSizeDefault),
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            crossAxisAlignment: CrossAxisAlignment.center,
            children: [
              Image.asset( Images.emptyFaq, width: 60, height: 60),
              const SizedBox(height: Dimensions.paddingSizeExtraLarge),
              Text('empty_faq_title'.tr, textAlign: TextAlign.center,
                style: rubikRegular.copyWith(fontSize: Dimensions.fontSizeLarge),
              ),
              const SizedBox(height: Dimensions.paddingSizeSmall),
              Text('empty_faq_subtitle'.tr, textAlign: TextAlign.center,
                style: rubikLight.copyWith(fontSize: Dimensions.fontSizeDefault),
              ),
              SizedBox(height: Get.height * 0.1,)
            ],
          ),
        ),
      ),
    );
  }
}
