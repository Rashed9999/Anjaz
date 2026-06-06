import 'package:flutter/material.dart';
import 'package:shimmer/shimmer.dart';
import 'package:amyal_pay/util/dimensions.dart';

class ProfileShimmerWidget extends StatelessWidget {
  const ProfileShimmerWidget({super.key});

  @override
  Widget build(BuildContext context) {
    final  size = MediaQuery.of(context).size;
    return Container(
      color: Theme.of(context).cardColor,
      child: Shimmer.fromColors(
        baseColor: Theme.of(context).hintColor.withValues(alpha: 0.1),
        highlightColor:  Theme.of(context).hintColor.withValues(alpha: 0.2),
        child : Container(

          padding: const EdgeInsets.symmetric(horizontal: Dimensions.paddingSizeSmall, vertical: Dimensions.paddingSizeDefault),
          child: ListTile(
            leading: const CircleAvatar(maxRadius: Dimensions.radiusProfileAvatar),

            title:  Column(
              mainAxisAlignment: MainAxisAlignment.start,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Container(color: Colors.red, height: Dimensions.paddingSizeSmall, width: size.width * 0.3),
                const SizedBox(height: Dimensions.paddingSizeExtraSmall),
                Container(color: Colors.red, height: Dimensions.paddingSizeExtraSmall, width: size.width * 0.5),
              ],
            ),
          ),
        ),
      ),
    );
  }
}






