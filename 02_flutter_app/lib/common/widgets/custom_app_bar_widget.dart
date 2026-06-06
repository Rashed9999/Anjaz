import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/features/language/controllers/localization_controller.dart';
import 'package:amyal_pay/helper/route_helper.dart';
import 'package:amyal_pay/util/app_constants.dart';
import 'package:amyal_pay/util/dimensions.dart';
import 'package:amyal_pay/util/styles.dart';
import 'package:amyal_pay/common/widgets/custom_ink_well_widget.dart';
import 'package:amyal_pay/common/widgets/rounded_button_widget.dart';

class CustomAppbarWidget extends StatelessWidget implements PreferredSizeWidget {
  final String title;
  final Function? onTap;
  final bool isSkip;
  final Function? function;
  final bool onlyTitle;
  final bool showLanguage;
  final bool isBackButtonExist;
  const CustomAppbarWidget({super.key,
    required this.title,
    this.onTap,
    this.isSkip = false,
    this.function,
    this.onlyTitle = false,
    this.showLanguage = true,
    this.isBackButtonExist = true
  });
  @override
  Widget build(BuildContext context) {

    final String languageText = AppConstants.languages[Get.find<LocalizationController>().selectedIndex].languageName!;
    final width = MediaQuery.of(context).size.width;

    return onlyTitle ? Container(
      color: Theme.of(context).primaryColor,
      padding: const EdgeInsets.symmetric(horizontal: Dimensions.paddingSizeLarge),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisAlignment: MainAxisAlignment.end,
        children: [
          Text(title, style: rubikSemiBold.copyWith(
            fontSize: Dimensions.fontSizeExtraLarge,color: Colors.white,),
          ),
          const SizedBox(height: Dimensions.paddingSizeDefault,)

        ],
      ),

    ) : Container(
      color: Theme.of(context).primaryColor,
      child: SafeArea(
        child: Container(
          color: Theme.of(context).primaryColor,
          padding: const EdgeInsets.all(Dimensions.paddingSizeLarge),
          child: Center(
            child: Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              crossAxisAlignment: CrossAxisAlignment.center,
              children: [

                if(isBackButtonExist)...[
                  CustomInkWellWidget(
                    onTap: onTap == null ? () {
                      Get.back();
                    } : onTap as void Function()?,
                    radius: Dimensions.radiusSizeExtraSmall,
                    child: Container(
                      alignment: Alignment.center,
                      height: 40,
                      width: 35,
                      decoration: BoxDecoration(
                        border: Border.all(color: Theme.of(context).cardColor, width: 1),
                        borderRadius: BorderRadius.circular(Dimensions.radiusSizeExtraSmall),
                      ),
                      child: Icon(Icons.arrow_back_ios_new, size: Dimensions.paddingSizeDefault, color: Theme.of(context).cardColor),
                    ),
                  ),
                  const SizedBox(width: Dimensions.paddingSizeDefault),
                ],


                Text(title, style: rubikMedium.copyWith(
                    fontSize:  width < 380 ? Dimensions.fontSizeLarge :  Dimensions.fontSizeExtraLarge,
                    color: Colors.white),
                ),

                isSkip ? const Spacer() : const SizedBox(),

                isSkip ? SizedBox(
                  child: RoundedButtonWidget(
                    buttonText: 'skip'.tr,
                    onTap: function,
                    isSkip: true,
                  ),
                ) : showLanguage ? RoundedButtonWidget(
                  buttonText: languageText,
                  isSkip: true,
                  onTap: AppConstants.languages.length > 1 ? (){
                    Get.toNamed(RouteHelper.getChoseLanguageRoute());
                  } : null,
                ): const SizedBox(),
              ],
            ),
          ),
        ),
      ),
    );
  }

  @override
  Size get preferredSize => const Size(double.maxFinite, 70);
}


