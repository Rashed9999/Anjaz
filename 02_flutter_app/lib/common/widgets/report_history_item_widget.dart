import 'package:flutter/material.dart';
import 'package:amial_pay/util/dimensions.dart';
import 'package:amial_pay/util/styles.dart';

class ReportHistoryItemWidget extends StatelessWidget {
  final String? label;
  final String? info;
  final Widget? infoWidget;
  final Color? backgroundColor;
  const ReportHistoryItemWidget({
    super.key, this.label, this.info, this.backgroundColor, this.infoWidget,
  });

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.maxFinite,
      padding: const EdgeInsets.all(Dimensions.paddingSizeSmall),
      decoration: BoxDecoration(
        color: backgroundColor ?? Theme.of(context).hintColor.withValues(alpha: 0.04),
        borderRadius: BorderRadius.circular(Dimensions.paddingSizeExtraSmall),
      ),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start,children: [

        Text(label ?? '', style: rubikSemiBold.copyWith(
          fontSize: Dimensions.fontSizeDefault,
          color: Theme.of(context).primaryColor,
        ), overflow: TextOverflow.ellipsis),
        const SizedBox(height: Dimensions.paddingSizeExtraSmall),

        infoWidget != null ? infoWidget! : Text(
          info ?? '',
          style: rubikRegular.copyWith(
            fontSize: Dimensions.fontSizeDefault,
            color: Theme.of(context).hintColor,
          ),
        ),
      ]),
    );
  }
}