import 'package:amyal_pay/common/widgets/custom_asset_image_widget.dart';
import 'package:amyal_pay/util/dimensions.dart';
import 'package:amyal_pay/util/styles.dart';
import 'package:flutter/material.dart';
class MenuItem extends StatelessWidget {
  const MenuItem({
    super.key,
    required this.image,
    required this.title,
    this.iconData,
  });
  final String? image;
  final String title;
  final IconData? iconData;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: Dimensions.paddingSizeSmall,horizontal: Dimensions.paddingSizeDefault),
      child: Row(children: [
        SizedBox(
          width: Dimensions.fontSizeOverOverLarge,
          height: Dimensions.fontSizeOverOverLarge,
          child: image != null ?
          CustomAssetImageWidget(image!,fit: BoxFit.contain) : Icon(iconData),
        ),
        const SizedBox(width: Dimensions.paddingSizeDefault),

        Text(title,style: rubikRegular.copyWith(fontSize: Dimensions.fontSizeLarge - 1)),
        const Spacer(),

        const Icon(Icons.arrow_forward_ios_rounded,size: Dimensions.radiusSizeDefault,),
      ],),
    );
  }
}