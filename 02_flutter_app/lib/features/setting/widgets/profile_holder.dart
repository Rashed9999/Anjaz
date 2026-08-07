import 'package:amial_pay/util/dimensions.dart';
import 'package:amial_pay/util/styles.dart';
import 'package:flutter/material.dart';

class ProfileHeader extends StatelessWidget {
  const ProfileHeader({
    super.key,
    required this.title,
  });
  final String title;

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        Container(
          color: Theme.of(context).canvasColor,
          width: double.infinity,
          child: Padding(
            padding: const EdgeInsets.only(left: Dimensions.paddingSizeDefault, top: 30.0, bottom: 8.0,right: Dimensions.paddingSizeDefault),
            child: Text(title,style: rubikMedium.copyWith(fontSize: Dimensions.fontSizeLarge, color: Theme.of(context).hintColor.withValues(alpha: 0.4))),
          ),
        ),
        Container(
          height: Dimensions.dividerSizeSmall,
          color: Theme.of(context).dividerColor,
        ),

      ],
    );
  }
}