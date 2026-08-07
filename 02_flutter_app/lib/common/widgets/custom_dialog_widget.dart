import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amial_pay/features/auth/controllers/auth_controller.dart';
import 'package:amial_pay/helper/custom_extension_helper.dart';
import 'package:amial_pay/util/dimensions.dart';
import 'package:amial_pay/util/styles.dart';
import 'package:amial_pay/theme/amial_colors.dart';

import 'custom_button_widget.dart';

class CustomDialogWidget extends StatelessWidget {
  final bool isFailed;
  final double rotateAngle;
  final IconData icon;
  final String title;
  final String description;
  final Widget? descriptionWidget;
  final Function? onTapTrue;
  final String? onTapTrueText;
  final Function? onTapFalse;
  final String? onTapFalseText;
  final bool bigTitle;
  final bool isSingleButton;

  /// AMIAL-DIALOG-001: تلوين الأزرار حسب *نيّة* الإجراء لا حسب موضعه.
  /// افتراضياً: الأيسر أحمر (رفض) والأيمن أخضر (قبول) — لكن في نوافذ مثل
  /// «تسجيل الخروج» كلا الخيارين هدّام، فالأخضر مضلِّل. مرِّر لوناً صريحاً.
  final Color? trueButtonColor;
  final Color? falseButtonColor;

  const CustomDialogWidget({super.key,
    this.isFailed = false,
    this.rotateAngle = 0,
    required this.icon,
    required this.title,
    required this.description,
    this.onTapFalse,
    this.onTapTrue,
    this.onTapTrueText,
    this.onTapFalseText,
    this.bigTitle = false,
    this.isSingleButton = false,
    this.descriptionWidget,
    this.trueButtonColor,
    this.falseButtonColor,
  });

  @override
  Widget build(BuildContext context) {
    return Dialog(
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
      child: Padding(
        padding: const EdgeInsets.all(Dimensions.paddingSizeLarge),
        child: Stack(clipBehavior: Clip.none, children: [

          Positioned(
            left: 0, right: 0, top: -55,
            child: Container(
              height: 80,
              width: 80,
              alignment: Alignment.center,
              decoration: BoxDecoration(color: isFailed ? Theme.of(context).colorScheme.error.withValues(alpha:0.7) : Theme.of(context).primaryColor, shape: BoxShape.circle),
              child: Transform.rotate(angle: rotateAngle, child: Icon(icon, size: 40, color: Colors.white)),
            ),
          ),

          Padding(
            padding: const EdgeInsets.only(top: 40),
            child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.center, children: [
              bigTitle ? FittedBox(child: Text(title, style: rubikRegular.copyWith(fontSize: Dimensions.fontSizeLarge), maxLines: 1, overflow: TextOverflow.ellipsis, textAlign: TextAlign.start))
                  : Text(title, style: rubikRegular.copyWith(fontSize: bigTitle ? Dimensions.paddingSizeSmall :  Dimensions.fontSizeLarge), maxLines: 1, overflow: TextOverflow.ellipsis, textAlign: TextAlign.start),
              const SizedBox(height: Dimensions.paddingSizeSmall),

              Text(description, textAlign: TextAlign.center, style: rubikRegular),

              ?descriptionWidget,


              const SizedBox(height: Dimensions.paddingSizeLarge),

              Visibility(
                visible: isSingleButton,
                child:  Padding(
                  padding: const EdgeInsets.symmetric(horizontal: Dimensions.paddingSizeLarge),
                  child: CustomButtonWidget(buttonText: 'ok'.tr , onTap: () => Navigator.pop(context), color: AmialColors.primary),
                ),
              ),

             onTapTrue != null && onTapFalse != null ?  GetBuilder<AuthController>(
               builder: (authController) {
                 return Padding(
                    padding: const EdgeInsets.symmetric(horizontal: 0),
                    child: Row(
                      children: [

                        Expanded(child: CustomButtonWidget(
                          buttonText: onTapFalseText ?? 'no'.tr,
                          color: falseButtonColor ??
                              Theme.of(context).colorScheme.error.withValues(alpha: 0.7),
                          onTap: onTapFalse,
                        )),
                        const SizedBox(width: 10,),

                        Expanded(child: CustomButtonWidget(
                          buttonText: onTapTrueText ?? 'yes'.tr,
                          onTap: onTapTrue,
                          color: trueButtonColor ?? context.customThemeColors.accept,
                        )),
                      ],
                    ),
                  );
               }
             ) : const SizedBox(),
            ]),
          ),

        ]),
      ),
    );
  }
}
