
import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/util/dimensions.dart';
import 'package:amyal_pay/util/styles.dart';

class CustomLargeButtonWidget extends StatelessWidget {
  final String? text;
  final Function? onTap;
  final Color? backgroundColor;
  final Color? textColor;
  final double bottomPadding;
  final bool isLoading;
  final double? fontSize;
  final EdgeInsetsGeometry? padding;

  const CustomLargeButtonWidget({super.key,
    this.backgroundColor,
    this.onTap,
    this.text,
    this.bottomPadding = Dimensions.paddingSizeExtraOverLarge,
    this.fontSize,
    this.padding,
    this.isLoading = false,
    this.textColor,
  });

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding:  padding ?? const EdgeInsets.only(
        left: Dimensions.paddingSizeExtraExtraLarge,
        right: Dimensions.paddingSizeExtraExtraLarge,
      ),
      child: TextButton(
        onPressed: isLoading ? null : onTap as void Function()?,
        style: TextButton.styleFrom(
          minimumSize: Size(Get.width, 45),
          padding: const EdgeInsets.symmetric(
              vertical: Dimensions.paddingSizeDefault),
          backgroundColor: backgroundColor,
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(Dimensions.radiusSizeSmall),
          ),
        ),
        child:  Row(mainAxisAlignment: MainAxisAlignment.center, children: [

          isLoading ? Padding( padding: const EdgeInsets.symmetric(horizontal: Dimensions.fontSizeSmall),
            child: SizedBox(height: Dimensions.fontSizeLarge, width: Dimensions.fontSizeLarge,
              child: CircularProgressIndicator( strokeWidth : 2 , color: Theme.of(context).colorScheme.primary ),),
          ) : const SizedBox(),


          Text(isLoading ? "loading".tr :text ?? "",
            style: rubikSemiBold.copyWith(
              color: textColor ?? Theme.of(context).textTheme.bodyLarge!.color,
              fontSize: fontSize ?? Dimensions.fontSizeExtraLarge,
            ),
          ),

        ]),
      ),
    );
  }
}
