import 'package:dotted_border/dotted_border.dart';
import 'package:amial_pay/util/dimensions.dart';
import 'package:amial_pay/util/images.dart';
import 'package:amial_pay/util/styles.dart';
import 'package:flutter/material.dart';
import 'package:get/get_utils/src/extensions/internacionalization.dart';
import 'package:amial_pay/common/widgets/custom_ink_well_widget.dart';

class ScanButtonWidget extends StatelessWidget {
  final VoidCallback onTap;
  const ScanButtonWidget({super.key,required this.onTap});

  @override
  Widget build(BuildContext context) {
    return Container(
      alignment: Alignment.center,
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(Dimensions.radiusSizeOverLarge),
        color: Theme.of(context).colorScheme.secondary,
      ),
      child: CustomInkWellWidget(
        onTap: onTap,
        radius: Dimensions.radiusSizeOverLarge,
        child: DottedBorder(
          options: RoundedRectDottedBorderOptions(
            strokeWidth: 1.0,
            color: Theme.of(context).primaryColor,
            radius: const Radius.circular(Dimensions.radiusSizeOverLarge),
          ),
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: Dimensions.paddingSizeSmall, vertical: Dimensions.paddingSizeSmall),
            child: Row(
              mainAxisAlignment: MainAxisAlignment.spaceAround,
              children: [
                Image.asset(Images.qrCode, width: Dimensions.paddingSizeDefault),
                Padding(
                  padding: const EdgeInsets.symmetric(horizontal: Dimensions.paddingSizeSmall),
                  child: Text('scan_qr_code'.tr, style: rubikRegular.copyWith(fontSize: Dimensions.fontSizeLarge, color: Colors.black),),
                )
              ],
            ),
          )),
      ),
    );
  }
}