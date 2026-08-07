import 'package:cached_network_image/cached_network_image.dart' show CachedNetworkImage;
import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amial_pay/common/models/contact_model.dart';
import 'package:amial_pay/common/widgets/custom_asset_image_widget.dart';
import 'package:amial_pay/features/splash/controllers/splash_controller.dart';
import 'package:amial_pay/helper/transaction_type.dart';
import 'package:amial_pay/util/dimensions.dart';
import 'package:amial_pay/util/images.dart';
import 'package:amial_pay/util/styles.dart';

class ForPersonWidget extends StatelessWidget {
  final ContactModel? contactModel;
  final String? transactionType;
  const ForPersonWidget({super.key, required this.contactModel, required this.transactionType, });


  @override
  Widget build(BuildContext context) {
    final bool isFav = contactModel?.isFavourite ?? false;

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: Dimensions.paddingSizeLarge, vertical: Dimensions.paddingSizeDefault),
      color: Theme.of(context).cardColor,
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [

        Text(isFav ? 'favorite_person'.tr : 'for_person'.tr, style: rubikSemiBold.copyWith(
            fontSize: Dimensions.fontSizeLarge,
            color: Theme.of(context).hintColor.withValues(alpha: 0.6),
        )),
        const SizedBox(height: Dimensions.radiusSizeExtraSmall),

        _PreviewContactItemWidget(contactModel: contactModel, transactionType: transactionType),

      ]),
    );
  }
}


class _PreviewContactItemWidget extends StatelessWidget {
  final ContactModel? contactModel;
  final String? transactionType;
  const _PreviewContactItemWidget({required this.contactModel, required this.transactionType,});


  @override
  Widget build(BuildContext context) {
    final bool isFav = contactModel?.isFavourite ?? false;

    String phoneNumber = contactModel!.phoneNumber!;
    if(phoneNumber.contains('-')) {
      phoneNumber.replaceAll('-', '');
    }



    return Row(children: [
      Stack(children: [

        SizedBox(
          height: 40,
          width: 40,
          child: CachedNetworkImage(
            imageUrl: '${_getImageBaseUrl()}/${contactModel?.avatarImage}',
            imageBuilder: (context, imageProvider) => CircleAvatar(
              backgroundImage: imageProvider,
            ),
            placeholder: (context, url) => CircleAvatar(
              child: Icon(Icons.person, size: Dimensions.paddingSizeLarge),
            ),
            errorWidget: (context, url, error) => CircleAvatar(
              child: Text((contactModel?.name?.isNotEmpty ?? false) ? contactModel!.name![0].toUpperCase() : ''  , style: rubikMedium.copyWith(color: Colors.white)),
            ),
          ),
        ),

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
      const SizedBox(width: Dimensions.paddingSizeDefault),

      Expanded(child: Column(
        mainAxisAlignment: MainAxisAlignment.start, crossAxisAlignment: CrossAxisAlignment.start,
        children: [

          Text(contactModel?.name ?? '', style: rubikRegular.copyWith(fontSize: Dimensions.fontSizeLarge), overflow: TextOverflow.ellipsis),
          const SizedBox(width: Dimensions.paddingSizeExtraSmall),


          Text(phoneNumber, style: rubikLight.copyWith(
            fontSize: Dimensions.fontSizeLarge,
            color: Theme.of(context).hintColor.withValues(alpha: 0.6),
          )),
        ],
      )),
    ]);
  }

  String? _getImageBaseUrl() {
    return transactionType == TransactionType.cashOut
        ? Get.find<SplashController>().configModel?.baseUrls?.agentImageUrl
        : Get.find<SplashController>().configModel?.baseUrls?.customerImageUrl ?? '';
  }
}
