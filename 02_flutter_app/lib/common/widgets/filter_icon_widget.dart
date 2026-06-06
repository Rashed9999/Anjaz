import 'package:flutter/material.dart';
import 'package:amyal_pay/util/dimensions.dart';

class FilterIconWidget extends StatelessWidget {
  final bool isFiltered;
  final double? iconSize;
  final Function()? onTap;

  const FilterIconWidget({
    super.key,
    this.isFiltered = false,
    this.onTap,
    this.iconSize = 20,
  });

  @override
  Widget build(BuildContext context) {
    return InkWell(onTap: onTap, child: Stack(children: [

      Container(
        alignment: Alignment.center,
        decoration: BoxDecoration(
          border: Border.all(color: Theme.of(context).primaryColor, width: 1.5),
          borderRadius: BorderRadius.circular(Dimensions.radiusSizeSmall),
        ),
          child: Icon(Icons.filter_alt_rounded, size: iconSize, color: Colors.white),
      ),

      if(isFiltered) Positioned(right: 4, top: 2, child: Container(
        width: Dimensions.paddingSizeSmall, height: Dimensions.paddingSizeSmall,
        decoration: BoxDecoration(
          color: Theme.of(context).colorScheme.error,
          border: Border.all(color: Theme.of(context).primaryColor, width: 2),
          shape: BoxShape.circle,
        )),
      ),
    ]));
  }
}
