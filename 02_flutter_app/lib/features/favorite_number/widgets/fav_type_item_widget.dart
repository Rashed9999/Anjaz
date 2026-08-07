import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amial_pay/features/favorite_number/controllers/fav_number_controller.dart';
import 'package:amial_pay/features/favorite_number/domain/enums/fav_type.dart';
import 'package:amial_pay/util/dimensions.dart';
import 'package:amial_pay/util/styles.dart';

class FavTypeItemWidget extends StatelessWidget {
  final FavType favOption;
  final FavNumberController favNumberController;

  const FavTypeItemWidget({
    super.key,
    required this.favOption,
    required this.favNumberController,
  });

  @override
  Widget build(BuildContext context) {
    final bool isSelected = favOption == favNumberController.selectedType;

    return GetBuilder<FavNumberController>(
      builder: (favNumberController) {
        return InkWell(
          splashColor: Colors.transparent,
          highlightColor: Colors.transparent,
          onTap: () {
            favNumberController.updateSelectedFavType(favOption);
          },
          child: Row(mainAxisSize: MainAxisSize.min,children: [

            Container(
              padding: const EdgeInsets.all(Dimensions.paddingSizeExtraSmall),
              decoration: BoxDecoration(
                  border: Border.all(width: 1, color: isSelected ? Theme.of(context).primaryColor : Theme.of(context).hintColor.withValues(alpha: 0.1)),
                  shape: BoxShape.circle
              ),
              child: Icon(
                Icons.circle,
                size: 10,
                color: isSelected ? Theme.of(context).primaryColor : Colors.transparent,
              ),
            ),
            const SizedBox(width: Dimensions.paddingSizeExtraSmall),

            Text(favOption.toValueString.tr, style: rubikRegular.copyWith(color: Theme.of(context).primaryColor), overflow: TextOverflow.ellipsis),
          ]),
        );
      }
    );
  }
}