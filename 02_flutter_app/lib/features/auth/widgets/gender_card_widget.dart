import 'package:flutter/material.dart';
import 'package:amyal_pay/common/widgets/custom_asset_image_widget.dart';
import 'package:amyal_pay/util/dimensions.dart';
import 'package:amyal_pay/util/styles.dart';

class GenderCardWidget extends StatelessWidget {
  final String? text;
  final Color? color;
  final Function? onTap;
  final String icon;
  const GenderCardWidget({super.key, required this.icon, this.text, this.color,this.onTap});

  @override
  Widget build(BuildContext context) {
    return InkWell(
      splashColor: Colors.transparent,
      highlightColor: Colors.transparent,
      onTap: onTap as void Function()?,
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: Dimensions.paddingSizeDefault, vertical: Dimensions.paddingSizeSmall),
        decoration: BoxDecoration(
          color: color,
          borderRadius: BorderRadius.circular(Dimensions.radiusSizeExtraSmall),
          border: Border.all(width: 1, color: Theme.of(context).hintColor.withValues(alpha: 0.1))
        ),
        child: Row(mainAxisSize: MainAxisSize.min, mainAxisAlignment: MainAxisAlignment.start, children: [

          CustomAssetImageWidget(icon, height: Dimensions.paddingSizeLarge, width: Dimensions.paddingSizeLarge),
          const SizedBox(width: Dimensions.radiusSizeExtraSmall),

          Text(text!,
            style: rubikRegular.copyWith(
              color: Theme.of(context).textTheme.bodyLarge!.color,
              fontSize: Dimensions.fontSizeDefault,
            ),
          ),

        ]),
      ),
    );
  }
}
