
import 'package:get/get.dart';
import 'package:amyal_pay/common/widgets/custom_asset_image_widget.dart';
import 'package:amyal_pay/features/favorite_number/domain/enums/fav_type.dart';
import 'package:amyal_pay/features/transaction_money/controllers/contact_controller.dart';
import 'package:amyal_pay/util/dimensions.dart';
import 'package:amyal_pay/util/images.dart';
import 'package:amyal_pay/util/styles.dart';
import 'package:flutter/material.dart';

class ContactItemWidget extends StatelessWidget {
  final int index;
  const ContactItemWidget({super.key, required this.index});


  @override
  Widget build(BuildContext context) {
    final ContactController contactController = Get.find<ContactController>();
    final bool isFav = contactController.filteredContacts[index].favouriteModel != null;

    return Container(
      padding: const EdgeInsets.symmetric(vertical: Dimensions.paddingSizeSmall),
      child: Row(children: [
        Padding(
          padding: const EdgeInsets.symmetric( horizontal: Dimensions.paddingSizeDefault),
          child: Stack(children: [
            contactController.filteredContacts[index].contact!.thumbnail != null ?
            CircleAvatar(backgroundImage: MemoryImage(contactController.filteredContacts[index].contact!.thumbnail!)) :
            contactController.filteredContacts[index].contact!.displayName == '' ? const CircleAvatar() :
            CircleAvatar(child:  Text(
              contactController.filteredContacts[index].contact!.displayName[0].toUpperCase(),
              style: rubikMedium.copyWith(color: Colors.white),
            )),

            if(isFav)
              Positioned(
                right: 0,
                child: Container(
                  transform: Matrix4.translationValues(5, -0, 0),
                  child: const CustomAssetImageWidget(
                    Images.starIcon,
                    height: Dimensions.paddingSizeDefault,
                    width: Dimensions.paddingSizeDefault,
                    fit: BoxFit.contain,
                  ),
                ),
              ),
          ]),
        ),

        Expanded(child: Column(
            mainAxisAlignment: MainAxisAlignment.start, crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(mainAxisSize: MainAxisSize.min,children: [

                Flexible(child: Text(
                  contactController.filteredContacts[index].contact!.displayName,
                  style: rubikRegular.copyWith(fontSize: Dimensions.fontSizeLarge, color: Theme.of(context).primaryColor),
                  overflow: TextOverflow.ellipsis,
                )),
                const SizedBox(width: Dimensions.paddingSizeExtraSmall),

               if(isFav) Container(
                  padding: const EdgeInsets.symmetric(horizontal: Dimensions.paddingSizeSmall, vertical: 2),
                  decoration: BoxDecoration(
                    /// todo - change the color according to he status
                    color: Theme.of(context).secondaryHeaderColor.withValues(alpha: 0.9),
                    borderRadius: BorderRadius.circular(Dimensions.paddingSizeLarge),
                  ),
                  child: Text(contactController.filteredContacts[index].favouriteModel?.type?.toValueString.tr ?? '', style: rubikRegular.copyWith(fontSize: Dimensions.fontSizeSmall)),
                ),

              ]),

              contactController.filteredContacts[index].contact!.phones.isEmpty ? const SizedBox() :
              Text(contactController.filteredContacts[index].contact!.phones.first.number,
                style: rubikLight.copyWith(fontSize: Dimensions.fontSizeLarge, color: Theme.of(context).hintColor.withValues(alpha: 0.4)),
              ),
            ],
          )),

      ]),
    );
  }
}



