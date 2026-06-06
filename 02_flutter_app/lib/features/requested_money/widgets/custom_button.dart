import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/features/setting/controllers/theme_controller.dart';
import 'package:amyal_pay/helper/custom_extension_helper.dart';
import 'package:amyal_pay/util/dimensions.dart';
import 'package:amyal_pay/util/styles.dart';


class CustomButton extends StatelessWidget {
  final Function? onTap;
  final String btnTxt;
  final Color? backgroundColor;
  const CustomButton({super.key, this.onTap, required this.btnTxt, this.backgroundColor});

  @override
  Widget build(BuildContext context) {
    return TextButton(
      onPressed: onTap as void Function()?,
      style: TextButton.styleFrom(padding: const EdgeInsets.all(0),
        backgroundColor: onTap == null ? Theme.of(context).hintColor : backgroundColor ?? Theme.of(context).primaryColor,
      ),
      child: Container(
        height: Dimensions.paddingSizeOverLarge,
        alignment: Alignment.center,
        decoration: BoxDecoration(
            color: context.customThemeColors.accept,
            boxShadow: [
              BoxShadow(color: Colors.grey.withValues(alpha:0.2), spreadRadius: 1, blurRadius: 7, offset: const Offset(0, 1)), // changes position of shadow
            ],
            gradient: (Get.find<ThemeController>().darkTheme || onTap == null) ? null : LinearGradient(colors: [
              Theme.of(context).primaryColor,
              Theme.of(context).primaryColor,
              Theme.of(context).primaryColor,
            ]),
            borderRadius: BorderRadius.circular(10)),
        child: Text(btnTxt,
            style: rubikMedium.copyWith(fontSize: Dimensions.fontSizeDefault, color: Colors.white
            )),
      ),
    );
  }
}
