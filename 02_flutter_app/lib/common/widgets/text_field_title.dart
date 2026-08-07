import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amial_pay/util/dimensions.dart';
import 'package:amial_pay/util/styles.dart';

class TextFieldTitle extends StatelessWidget {
  final String title;
  final String? subtitle;
  final bool requiredMark;
  final double? fontSize;
  final bool isPadding;
  final int ? currentLength;
  final int ? maxLength;
  final Color? titleColor;
  const TextFieldTitle({super.key,  required this.title, this.subtitle, this.requiredMark = false, this.fontSize, this.isPadding = true, this.currentLength, this.maxLength, this.titleColor});
  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: EdgeInsets.only(bottom: isPadding ? Dimensions.paddingSizeSmall : 0,top: Dimensions.paddingSizeDefault ),
      child: Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [

        RichText(text: TextSpan(children: <TextSpan>[

          TextSpan(text: title,
            style: rubikRegular.copyWith(
              color: titleColor ?? Theme.of(Get.context!).textTheme.bodyLarge!.color!,
              fontSize: fontSize,
            ),
          ),

          TextSpan(text: subtitle ?? "",
            style: rubikRegular.copyWith(
              color: Theme.of(Get.context!).textTheme.bodyLarge!.color!.withValues(alpha:0.2),
              fontSize: fontSize,
            ),
          ),

          TextSpan(text: requiredMark?' *':"",
            style: rubikRegular.copyWith(
                color: Theme.of(Get.context!).textTheme.bodyLarge!.color!,
                fontSize: fontSize
            ),
          ),

        ])),

        if(currentLength !=null && maxLength !=null) Text("$currentLength/$maxLength", style: rubikLight.copyWith(
          fontSize: Dimensions.fontSizeDefault - 2,
        )),

      ]),
    );
  }
}
