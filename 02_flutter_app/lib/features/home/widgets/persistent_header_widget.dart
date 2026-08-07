import 'package:get/get.dart';
import 'package:amial_pay/features/setting/controllers/theme_controller.dart';
import 'package:amial_pay/util/dimensions.dart';
import 'package:flutter/material.dart';
class PersistentHeaderWidget extends StatefulWidget {
  final VoidCallback? onTap;
  const PersistentHeaderWidget({super.key, this.onTap});

  @override
  State<PersistentHeaderWidget> createState() => _PersistentHeaderWidgetState();
}

class _PersistentHeaderWidgetState extends State<PersistentHeaderWidget> {
  @override
  Widget build(BuildContext context) {
    return InkWell(
      onTap: widget.onTap,
      child: Container(
        transform: Matrix4.translationValues(0.0, 10.0, 0.0),
        decoration: BoxDecoration(borderRadius: const BorderRadius.only(topLeft: Radius.circular(Dimensions.radiusSizeExtraLarge), topRight: Radius.circular(Dimensions.radiusSizeExtraLarge),),
          color: Theme.of(context).cardColor,
            boxShadow: [
              BoxShadow(color: Theme.of(context).textTheme.bodyLarge!.color!.withValues(alpha:0.2), blurRadius: Get.find<ThemeController>().darkTheme ?  0 : 20, offset: const Offset(0, 0)),
            ]
        ),
        child: Center(
          child: Container(height: 5, width: 32,
            margin: const EdgeInsets.only(top: Dimensions.paddingSizeDefault, bottom: Dimensions.paddingSizeSmall,),
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(36),
              color: Theme.of(context).hintColor.withValues(alpha: 0.4),
            ),
          ),
        ),
      ),
    );
  }
}


