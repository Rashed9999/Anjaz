
import 'package:flutter/material.dart';
import 'package:amial_pay/util/dimensions.dart';
import 'package:amial_pay/util/styles.dart';

class CustomSmallButtonWidget extends StatelessWidget {
  final String? text;
  final Function onTap;
  final Color? backgroundColor;
  final Color? textColor;
  final double textSize;
  final TextStyle? textStyle;
  const CustomSmallButtonWidget({super.key,
    this.backgroundColor,
    required this.onTap,
    this.text,
    this.textColor,
    this.textSize = Dimensions.fontSizeLarge,
    this.textStyle
  });

  @override
  Widget build(BuildContext context) {
    return TextButton(
      onPressed: onTap as void Function()?,
      style: TextButton.styleFrom(
        padding: const EdgeInsets.symmetric(
            vertical: Dimensions.paddingSizeDefault),
        backgroundColor: backgroundColor,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(Dimensions.radiusSizeSmall),
        ),
      ),
      child:  Text(
        text!,
        style: textStyle ?? rubikRegular.copyWith(
          color: textColor ?? Theme.of(context).textTheme.bodyMedium?.color,
          fontSize: textSize,
        ),
      ),
    );
  }
  //Dimensions.FONT_SIZE_EXTRA_LARGE
}
