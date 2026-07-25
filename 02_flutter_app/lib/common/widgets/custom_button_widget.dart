import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/util/dimensions.dart';
import 'package:amyal_pay/common/widgets/custom_ink_well_widget.dart';

class CustomButtonWidget extends StatelessWidget {
  final Function? onTap;
  final String? buttonText;
  final Color? color;
  final TextStyle? buttonTextStyle;
  final double? borderRadius;
  final Widget? prefixIcon;
  final Widget? suffixIcon;
  final bool isBorder;
  final Color? borderColor;
  final bool isLoading;

  const CustomButtonWidget({
    super.key,
    this.onTap,
    required this.buttonText,
    this.color,
    this.buttonTextStyle,
    this.borderRadius,
    this.prefixIcon,
    this.suffixIcon,
    this.isBorder = false,
    this.borderColor,
    this.isLoading = false,

  });

  @override
  Widget build(BuildContext context) {
    return Container(
      decoration: BoxDecoration(
        color: onTap == null ? Theme.of(context).hintColor.withValues(alpha: 0.08) : color ?? Theme.of(context).colorScheme.secondary,
        // AMIAL-DS-003: نصف قطر 14 وارتفاع 52 — قياسا الزرّ الموحّد في
        // أميال. كان 12 مع حشوة 10 فيخرج زرّ قصير بمظهر القالب.
        borderRadius: BorderRadius.circular(borderRadius ?? 14),
        border: isBorder ? Border.all(width: 1, color: borderColor ?? Theme.of(context).primaryColor) : null,
      ),
      child: CustomInkWellWidget(
        onTap: isLoading ? null : onTap as void Function()?,
        radius: borderRadius ?? 14,
        child: Padding(
          padding: const EdgeInsets.symmetric(vertical: 15),
          child: isLoading ? Center(child: Row(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              const SizedBox(
                height: 15, width: 15,
                child: CircularProgressIndicator(
                  valueColor: AlwaysStoppedAnimation<Color>(Colors.black),
                  strokeWidth: 2,
                ),
              ),
              const SizedBox(width: Dimensions.paddingSizeSmall),

              Text('loading'.tr, style: buttonTextStyle ?? const TextStyle(color: Colors.white)),
            ],
          )) : Row(mainAxisAlignment: MainAxisAlignment.center,children: [

            if(prefixIcon != null) ...[
              prefixIcon!,
              const SizedBox(width: Dimensions.paddingSizeExtraSmall)
            ],

            // AMIAL-BTN-OVERFLOW-001: كان Text مباشراً داخل Row بلا Flexible،
            // فلا يجد عرضاً محدوداً يُطبّق عليه ellipsis — يفيض ويُقصّ من
            // الحافة بدل أن ينتهي بنقاط. ظهر ذلك في حوار الخروج حيث صار
            // «مسح البيانات وتسجيل الخروج» ← «لبيانات وتسجيل الخروج».
            Flexible(
              child: Text(buttonText ?? '',
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  textAlign: TextAlign.center,
                  style: buttonTextStyle ?? const TextStyle(color: Colors.white)),
            ),

            if(suffixIcon != null) ...[
              suffixIcon!,
              const SizedBox(width: Dimensions.paddingSizeExtraSmall)
            ],
          ]),
        ),
      ),
    );
  }
}
