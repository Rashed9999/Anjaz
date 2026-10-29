import 'package:flutter/material.dart';
import 'package:flutter_svg/flutter_svg.dart';
import 'package:get/get.dart';
import 'package:amial_pay/features/setting/controllers/profile_screen_controller.dart';
import 'package:amial_pay/features/splash/controllers/splash_controller.dart';
import 'package:amial_pay/util/dimensions.dart';
import 'package:amial_pay/util/images.dart';
import 'package:amial_pay/util/styles.dart';
import 'package:amial_pay/common/widgets/custom_image_widget.dart';
import 'package:amial_pay/features/setting/widgets/profile_qr_code_bottomsheet_widget.dart';

import 'profile_shimmer_widget.dart';

class UserInfoWidget extends StatelessWidget {
  const UserInfoWidget({super.key});

  @override
  Widget build(BuildContext context) {
    return GetBuilder<ProfileController>(
      builder: (profileController) =>
      profileController.isLoading ? const ProfileShimmerWidget() : Container(
        color: Theme.of(context).cardColor,
        padding: const EdgeInsets.only(
          left: Dimensions.paddingSizeLarge,
          right: Dimensions.paddingSizeLarge,
          top: Dimensions.paddingSizeLarge,
        ),
        child: Column(
          children: [
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                profileController.userInfo != null
                    ? Row(
                        children: [
                          Container(
                            width: Dimensions.radiusSizeOverLarge,
                            height: Dimensions.radiusSizeOverLarge,
                            decoration: BoxDecoration(
                              borderRadius: const BorderRadius.all(
                                Radius.circular(Dimensions.radiusProfileAvatar),
                              ),
                              border: Border.all(
                                width: 1,
                                color: Theme.of(context).highlightColor,
                              ),
                            ),
                            child: ClipRRect(
                              borderRadius: const BorderRadius.all(
                                Radius.circular(Dimensions.radiusProfileAvatar),
                              ),
                              child: CustomImageWidget(
                                fit: BoxFit.cover,
                                image: "${Get.find<SplashController>().configModel!.baseUrls!.customerImageUrl}/${profileController.userInfo!.image}",
                                placeholder: profileController.userInfo?.gender?.toLowerCase() == 'male'
                                    ? Images.menPlaceHolder
                                    : profileController.userInfo?.gender?.toLowerCase() == 'female'
                                        ? Images.womenPlaceholder
                                        : Images.avatar,
                              ),
                            ),
                          ),
                          const SizedBox(width: Dimensions.paddingSizeSmall),
                          Column(
                            mainAxisAlignment: MainAxisAlignment.start,
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              SizedBox(
                                width: MediaQuery.of(context).size.width * 0.5,
                                child: Text(
                                  '${profileController.userInfo!.fName} ${profileController.userInfo!.lName}',
                                  style: rubikMedium.copyWith(
                                    color: Theme.of(context).textTheme.bodyLarge!.color,
                                    fontSize: Dimensions.fontSizeLarge,
                                  ),
                                  textAlign: TextAlign.start,
                                  maxLines: 1,
                                  overflow: TextOverflow.ellipsis,
                                ),
                              ),
                              Text(
                                '${profileController.userInfo!.phone}',
                                style: rubikMedium.copyWith(
                                  color: Theme.of(context).textTheme.bodyLarge!.color!
                                      .withValues(alpha: Get.isDarkMode ? 0.8 : 0.5),
                                  fontSize: Dimensions.fontSizeLarge,
                                ),
                                textAlign: TextAlign.start,
                                maxLines: 1,
                                overflow: TextOverflow.ellipsis,
                                textDirection: TextDirection.ltr,
                              ),
                            ],
                          ),
                        ],
                      )
                    : const SizedBox(),
                InkWell(
                  onTap: () => showModalBottomSheet(
                    context: context,
                    isScrollControlled: true,
                    isDismissible: false,
                    shape: const RoundedRectangleBorder(
                      borderRadius: BorderRadius.vertical(
                        top: Radius.circular(Dimensions.radiusSizeLarge),
                      ),
                    ),
                    builder: (context) => const ProfileQRCodeBottomSheetWidget(),
                  ),
                  child: GetBuilder<ProfileController>(builder: (controller) {
                    final qr = controller.userInfo?.qrCode;
                    return Container(
                      decoration: BoxDecoration(
                        shape: BoxShape.circle,
                        color: Theme.of(context).colorScheme.secondary,
                      ),
                      padding: const EdgeInsets.all(10.0),
                      child: (qr != null && qr.isNotEmpty)
                          ? SvgPicture.string(qr, height: 24, width: 24)
                          : const Icon(Icons.qr_code_2, size: 24),
                    );
                  }),
                ),
              ],
            ),
            const SizedBox(height: Dimensions.paddingSizeExtraSmall),
          ],
        ),
      ),
    );
  }
}
