import 'package:amyal_pay/common/widgets/custom_asset_image_widget.dart';
import 'package:amyal_pay/common/widgets/custom_showcase_widget.dart';
import 'package:amyal_pay/util/dimensions.dart';
import 'package:amyal_pay/util/styles.dart';
import 'package:flutter/material.dart';
import 'package:amyal_pay/common/widgets/custom_ink_well_widget.dart';
class OptionCardWidget extends StatelessWidget {
  final String? image;
  final String? text;
  final VoidCallback? onTap;
  final Color? color;
  final GlobalKey  showcaseKey;
  final String? showcaseTitle;
  final bool isDone;
   const OptionCardWidget({super.key, this.image, this.text, this.onTap, this.color, required  this.showcaseKey,   this.showcaseTitle, required this.isDone}) ;

  @override
  Widget build(BuildContext context) {
     return Container(
       margin: const EdgeInsets.symmetric(horizontal: Dimensions.paddingSizeSmall),
       decoration: BoxDecoration(
         color: color,
         borderRadius: BorderRadius.circular(Dimensions.paddingSizeSmall),
         border: Border.all(width: 1, color: Theme.of(context).cardColor),
         boxShadow: [BoxShadow(
             color: Colors.black.withValues(alpha: 0.1),
             blurRadius: 16,
             offset: const Offset(0, 6)),
         ],
       ),
       child: CustomShowcaseWidget(
         showcaseKey: showcaseKey,
         title: showcaseTitle ?? "",
         subtitle: "",
         padding: EdgeInsets.zero,
         radius: BorderRadius.circular(Dimensions.radiusSizeLarge),
         isDone: isDone,
         child: CustomInkWellWidget(
           onTap: onTap,
           radius: Dimensions.radiusSizeDefault,
           child: Column(mainAxisAlignment: MainAxisAlignment.start, children: [
             const SizedBox(height: Dimensions.paddingSizeDefault),

             CustomAssetImageWidget(image!, height: 40, width: 40, fit: BoxFit.contain),
             const SizedBox(height: Dimensions.paddingSizeSmall),

             Text(text ?? '', textAlign: TextAlign.center, maxLines: 2, style: rubikRegular.copyWith(
               fontSize: Dimensions.fontSizeDefault,
               color: Theme.of(context).textTheme.bodyLarge!.color,
             ))
           ]),
         ),
       ),
     );
  }
}