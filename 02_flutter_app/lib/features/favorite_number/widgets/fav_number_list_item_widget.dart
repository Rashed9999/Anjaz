import 'package:flutter/material.dart';
import 'package:flutter_slidable/flutter_slidable.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/common/widgets/show_custom_bottom_sheet.dart';
import 'package:amyal_pay/features/favorite_number/controllers/fav_number_controller.dart';
import 'package:amyal_pay/features/favorite_number/domain/models/favorite_list_model.dart';
import 'package:amyal_pay/features/favorite_number/screens/add_fav_number_screen.dart';
import 'package:amyal_pay/features/favorite_number/widgets/fav_num_delete_bottom_sheet_widget.dart';
import 'package:amyal_pay/util/dimensions.dart';
import 'package:amyal_pay/util/styles.dart';

class FavNumberListItemWidget extends StatelessWidget {
  final String? label;
  final String? transactionType;
  final List<FavouriteModel> favouriteList;
  const FavNumberListItemWidget({
    super.key, this.label, this.transactionType, required this.favouriteList,
  });

  @override
  Widget build(BuildContext context) {
    return favouriteList.isEmpty ? SizedBox() : Padding(
      padding: const EdgeInsets.symmetric(horizontal: Dimensions.paddingSizeLarge),
      child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start,children: [

        Text(label ?? '', style: rubikRegular.copyWith(color: Theme.of(context).hintColor, fontSize: Dimensions.fontSizeLarge)),
        const SizedBox(height: Dimensions.paddingSizeLarge),

        ListView.separated(
          physics: const NeverScrollableScrollPhysics(),
          shrinkWrap: true,
          padding: EdgeInsets.zero,
          itemBuilder: (context, index){
            return _FavNumberItemWidget(transactionType: transactionType, favouriteModel: favouriteList[index]);
          },
          separatorBuilder: (BuildContext context, int index)=> const SizedBox(height: Dimensions.paddingSizeDefault),
          itemCount: favouriteList.length,
        ),
      ]),
    );
  }
}

class _FavNumberItemWidget extends StatelessWidget {
  final String? transactionType;
  final FavouriteModel? favouriteModel;
  const _FavNumberItemWidget({
    required this.favouriteModel, this.transactionType,
  });

  @override
  Widget build(BuildContext context) {
    final bool isShowIcon = transactionType == null;

    return InkWell(
      onTap: isShowIcon ? null : (){
        Get.find<FavNumberController>().checkoutToTransaction(favouriteModel!, transactionType);
      },
      child: Slidable(
        key: const ValueKey(0),
        enabled: isShowIcon,
        endActionPane: ActionPane(
          motion: const ScrollMotion(),
          dragDismissible: false,
          extentRatio: 0.2,
          children: [
            CustomSlidableAction(
              onPressed: (value)=> showCustomBottomSheet(child: FavNumDeleteBottomSheetWidget(id: favouriteModel?.id)),
              backgroundColor: Theme.of(context).hintColor.withValues(alpha: 0.05),
              foregroundColor: Theme.of(context).colorScheme.error,
              borderRadius: const BorderRadius.only(topRight: Radius.circular(Dimensions.paddingSizeSmall), bottomRight: Radius.circular(Dimensions.paddingSizeSmall)),
              child: Icon(
                Icons.delete_forever_rounded,
                size: Dimensions.radiusSizeExtraLarge,
                color: Theme.of(context).colorScheme.error,
              ),
            ),
          ],
        ),
        child: Container(
          padding: const EdgeInsets.all(Dimensions.paddingSizeSmall).copyWith(left: 0),
          decoration: BoxDecoration(borderRadius: BorderRadius.circular(Dimensions.paddingSizeSmall)),
          child: Row(crossAxisAlignment: CrossAxisAlignment.center, children: [

            CircleAvatar(child:  Text(
              (favouriteModel?.name?.length ?? 0) > 0 ? favouriteModel!.name![0].toUpperCase() : '',
              style: rubikMedium.copyWith(color: Colors.white),
            )),

            const SizedBox(width: Dimensions.paddingSizeDefault),

            Expanded(child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                Text(favouriteModel?.name ?? '', style: rubikRegular.copyWith(
                  fontSize: Dimensions.fontSizeDefault,
                  color: Theme.of(context).textTheme.bodyLarge?.color,
                ), overflow: TextOverflow.ellipsis),
                const SizedBox(height: Dimensions.paddingSizeExtraSmall),
      
                Text(
                  favouriteModel?.phone ?? '',
                  maxLines: 1,
                  style: rubikLight.copyWith(
                    fontSize: Dimensions.fontSizeDefault,
                    color: Theme.of(context).hintColor.withValues(alpha: 0.5),
                  ),
                ),
              ],
            )),
            const SizedBox(width: Dimensions.paddingSizeDefault),
      
            if(isShowIcon)
            InkWell(
              onTap: ()=> Get.to(()=> AddFavNumberScreen(favNumber: favouriteModel)),
              child: const Icon(Icons.edit, color: Colors.blue),
            ),
          ]),
        ),
      ),
    );
  }
}