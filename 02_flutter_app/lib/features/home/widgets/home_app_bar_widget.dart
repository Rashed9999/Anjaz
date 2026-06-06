import 'package:flutter/material.dart';
import 'package:flutter_svg/flutter_svg.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/common/widgets/custom_ink_well_widget.dart';
import 'package:amyal_pay/common/widgets/custom_showcase_widget.dart';
import 'package:amyal_pay/features/home/controllers/home_controller.dart';
import 'package:amyal_pay/features/home/controllers/menu_controller.dart';
import 'package:amyal_pay/features/home/widgets/transaction_amount_item_widget.dart';
import 'package:amyal_pay/features/kyc_verification/screens/kyc_verify_screen.dart';
import 'package:amyal_pay/features/setting/controllers/profile_screen_controller.dart';
import 'package:amyal_pay/features/setting/domain/enums/kyc_verificaiton.dart';
import 'package:amyal_pay/features/setting/domain/models/profile_model.dart';
import 'package:amyal_pay/features/splash/controllers/splash_controller.dart';
import 'package:amyal_pay/helper/custom_extension_helper.dart';
import 'package:amyal_pay/helper/price_converter_helper.dart';
import 'package:amyal_pay/util/dimensions.dart';
import 'package:amyal_pay/util/images.dart';
import 'package:amyal_pay/common/widgets/custom_image_widget.dart';
import 'package:amyal_pay/helper/tween_helper.dart';
import 'package:amyal_pay/common/widgets/hero_dialogue_route_widget.dart';
import 'package:amyal_pay/features/home/widgets/user_name_widget.dart';
import 'package:amyal_pay/util/styles.dart';

import '../../../common/models/config_model.dart';


class HomeAppBarWidget extends StatelessWidget implements PreferredSizeWidget {
  final ScrollController scrollController;
  final GlobalKey availableBalanceKey;
  final GlobalKey withdrawKey;
  const HomeAppBarWidget({super.key, required this.scrollController, required this.availableBalanceKey, required this.withdrawKey});

  @override
  Widget build(BuildContext context) {

    double topPadding = MediaQuery.of(context).padding.top;

    return GetBuilder<ProfileController>( builder: (profileController) {

      int? themeIndex = Get.find<SplashController>().configModel?.themeIndex ?? 1;
      String ? gender = profileController.userInfo?.gender?.toLowerCase();

      final SystemFeature? systemFeature = Get.find<SplashController>().configModel?.systemFeature;

      final canSeeTotalCashOutAmount = (systemFeature?.cashOutStatus ?? false) ? true : (profileController.userInfo?.totalCashOutAmount ?? 0) > 0;
      final canSeeTotalSendMoneyAmount = (systemFeature?.sendMoneyStatus ?? false) ? true : (profileController.userInfo?.totalSendMoneyAmount ?? 0) > 0;
      final canSeeTotalWithdrawMoneyAmount = (systemFeature?.withdrawRequestStatus ?? false) ? true : (profileController.userInfo?.totalWithdrawMoneyAmount ?? 0) > 0;

      return GetBuilder<MenuItemController>(builder: (menuController){
        return Container(
          decoration: BoxDecoration(
            color: Theme.of(context).primaryColor,
            borderRadius: menuController.homePageScrollPosition > 180 ? const BorderRadius.only(
              bottomLeft: Radius.circular(Dimensions.radiusSizeLarge),
              bottomRight: Radius.circular(Dimensions.radiusSizeLarge),
            ) : null,
          ),

          child: Container(
            padding: EdgeInsets.only(
              top:  topPadding + Dimensions.paddingSizeDefault,
              left: Dimensions.paddingSizeLarge,
              right: Dimensions.paddingSizeLarge,
              bottom: Dimensions.paddingSizeSmall,
            ),

            decoration: BoxDecoration(
              color: Colors.white.withValues(alpha:0.1),
              borderRadius: const BorderRadius.only(
                bottomLeft: Radius.circular(Dimensions.radiusSizeLarge),
                bottomRight: Radius.circular(Dimensions.radiusSizeLarge),
              ),
            ),

            child: Column(mainAxisSize: MainAxisSize.min, children: [

              if(_isShowVerifyButton(profileController.userInfo)) Container(
                decoration: BoxDecoration(
                    borderRadius: BorderRadius.circular(Dimensions.radiusSizeVerySmall),
                    color: Theme.of(context).colorScheme.error.withValues(alpha: 0.85)
                ),
                padding: const EdgeInsets.only(bottom: 5.0, top: 5, left: 15, right: 5),
                margin: const EdgeInsets.only(bottom: Dimensions.paddingSizeDefault),
                child: Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    Flexible(
                      child: Text(
                        profileController.userInfo!.kycStatus == KycVerification.needApply ?
                        'kyc_verification_is_not'.tr : profileController.userInfo!.kycStatus == KycVerification.pending ?
                        'your_verification_request_is'.tr : 'your_verification_is_denied'.tr,
                        style: rubikRegular.copyWith(
                          color: Colors.white.withValues(alpha:0.85),
                        ),
                        maxLines: 2,
                      ),
                    ),
                    const SizedBox(width: Dimensions.paddingSizeDefault),


                    CustomInkWellWidget(
                      onTap: () => Get.to(()=> const KycVerifyScreen()),
                      child: Container(
                        padding: const EdgeInsets.symmetric(
                          horizontal: Dimensions.paddingSizeSmall,
                          vertical: Dimensions.paddingSizeExtraSmall - 1,
                        ),
                        decoration: BoxDecoration(
                          borderRadius: BorderRadius.circular(Dimensions.paddingSizeExtraSmall),
                          color: Theme.of(context).cardColor,
                        ),
                        child: Text(
                          profileController.userInfo!.kycStatus == KycVerification.needApply ?
                          'verify_now'.tr : profileController.userInfo!.kycStatus == KycVerification.pending ?
                          'edit'.tr : 're_apply'.tr,
                          style: rubikRegular.copyWith(
                            color: Theme.of(context).textTheme.bodyLarge?.color,
                            fontSize: Dimensions.fontSizeDefault,
                          ),
                        ),
                      ),
                    ),

                  ],
                ),
              ),

              Row(mainAxisAlignment: MainAxisAlignment.spaceBetween,  children: [
                Expanded(child: Row(children: [

                  GestureDetector(
                    onTap: () => Get.find<MenuItemController>().selectProfilePage(),
                    child: Container(
                      decoration: BoxDecoration(
                        borderRadius: BorderRadius.circular(10),
                      ),
                      height: 65,
                      width: 65,
                      child: ClipRRect(
                        borderRadius: BorderRadius.circular(10),
                        child: profileController.userInfo == null ? Container(
                          decoration: BoxDecoration(
                            borderRadius: BorderRadius.circular(10),
                          ),
                          child: ClipRRect(
                            borderRadius: BorderRadius.circular(10),
                            child: Image.asset(Images.avatar,fit: BoxFit.cover),
                          ),
                        ) : CustomImageWidget(
                          image: '${Get.find<SplashController>().configModel?.baseUrls?.customerImageUrl}/${profileController.userInfo?.image ?? ''}',
                          fit: BoxFit.cover, placeholder: gender == "male" ? Images.menPlaceHolder : gender == "female" ? Images.womenPlaceholder : Images.avatar,
                        ),

                      ),
                    ),
                  ),
                  const SizedBox(width: Dimensions.paddingSizeSmall),

                  Get.find<SplashController>().configModel!.themeIndex == 1
                      ? const UserNameWidget()
                      :  _BalanceWidget(profileController, availableBalanceKey),
                ])),

                GestureDetector(
                  onTap: () => Navigator.of(context).push(HeroDialogRouteWidget(builder:(_) =>const _QrPopupCardWidget())),
                  child: Hero(
                    tag: Get.find<HomeController>().heroShowQr,
                    createRectTween: (begin, end) => TweenHelper(begin: begin, end: end),

                    child: Container(
                      decoration: BoxDecoration(
                          borderRadius: BorderRadius.circular(Dimensions.paddingSizeExtraSmall),
                          color: Colors.white.withValues(alpha:0.1)
                      ),
                      padding: const EdgeInsets.fromLTRB(10, 10, 10, 5),
                      child: profileController.userInfo != null ? Column(
                        children: [
                          SizedBox(
                            height: themeIndex == 1 ? 20 : 35, width: themeIndex == 1 ? 20 : 35,
                            child: Image.asset(Images.qrCode, color: Colors.white, fit: BoxFit.fitHeight,),
                          ),
                          const SizedBox(height: Dimensions.paddingSizeSuperExtraSmall),

                          Text('your_qr'.tr, style: rubikLight.copyWith(
                              fontSize: Dimensions.fontSizeSmall, color: Colors.white
                          ))
                        ],
                      ) : const SizedBox(),
                    ),
                  ),
                ),
              ]),
              const SizedBox(height: Dimensions.paddingSizeDefault),

              if(themeIndex != 1)
                Row(children: [
                 if(canSeeTotalCashOutAmount) Expanded(child: TransactionAmountItemWidget(
                    label: 'cash_out'.tr,
                    amount: PriceConverterHelper.convertPrice(profileController.userInfo?.totalCashOutAmount ?? 0),
                  )),
                  const SizedBox(width: Dimensions.paddingSizeSmall),

                  if(canSeeTotalSendMoneyAmount) Expanded(child: TransactionAmountItemWidget(
                    label: 'send'.tr,
                    amount: PriceConverterHelper.convertPrice(profileController.userInfo?.totalSendMoneyAmount ?? 0),
                  )),
                  const SizedBox(width: Dimensions.paddingSizeSmall),

                 if(canSeeTotalWithdrawMoneyAmount) Expanded(child: TransactionAmountItemWidget(
                    label: 'withdraw'.tr,
                    amount: PriceConverterHelper.convertPrice(profileController.userInfo?.totalWithdrawMoneyAmount ?? 0),
                  )),
                ]),
            ]),
          ),
        );
      });
    });
  }

  @override
  Size get preferredSize => const Size(double.maxFinite, 220);

  bool _isShowVerifyButton(ProfileModel? userInfo)=> userInfo != null && userInfo.kycStatus != KycVerification.approve;
}


class _QrPopupCardWidget extends StatelessWidget {
  const _QrPopupCardWidget();

  @override
  Widget build(BuildContext context) {
    final Size size = MediaQuery.of(context).size;
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(32.0),
        child: Hero(
          tag: Get.find<HomeController>().heroShowQr,
          createRectTween: (begin, end) {
            return TweenHelper(begin: begin, end: end);
          },
          child: Material(
            color: Colors.white,
            elevation: 2,
            shape:
            RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
            child: Padding(
                padding: const EdgeInsets.all(20.0),
                child: GetBuilder<ProfileController>(
                  builder: (controller) {
                    return SizedBox(
                      child: SvgPicture.string(
                        controller.userInfo!.qrCode!,
                        fit: BoxFit.contain,
                        width: size.width * 0.8,
                        // height: size.width * 0.8,
                      ),
                    );
                  },
                )),
          ),
        ),
      ),
    );
  }
}


class _BalanceWidget extends StatelessWidget {
  final ProfileController profileController;
  final GlobalKey availableBalanceKey;
  const _BalanceWidget(this.profileController, this.availableBalanceKey);

  @override
  Widget build(BuildContext context) {
    return GetBuilder<ProfileController>( builder: (profileController) {
      return Expanded(
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisSize: MainAxisSize.min,
          children: [
            Text("${profileController.userInfo?.fName?.capitalizeFirst ?? ""} ${profileController.userInfo?.lName?.capitalizeFirst ?? ""}", style: rubikMedium.copyWith(
                fontSize: Dimensions.fontSizeLarge, color: Theme.of(context).colorScheme.onSecondary, height: 1.0
            ),maxLines: 1, overflow: TextOverflow.ellipsis, ),

            CustomShowcaseWidget(
              title: "here_you_get_your_available_balance".tr, subtitle: " ", showcaseKey: availableBalanceKey,
              padding: const EdgeInsets.only(
                top: 0, bottom: 9,
                left: 7,
                right: 7,
              ),
              child: AnimatedSwitcher(
                duration: const Duration(milliseconds: 300),
                switchInCurve: Curves.slowMiddle,
                switchOutCurve: Curves.slowMiddle,
                child: !profileController.showBalanceButtonTapped && profileController.isUserBalanceHide() ? InkWell(
                  onTap: () => profileController.updateBalanceButtonTappedStatus(shouldUpdate: true),
                  child: Container(
                    decoration: BoxDecoration(
                        borderRadius: BorderRadius.circular(Dimensions.radiusSizeExtraExtraLarge),
                        color: context.customThemeColors.screenShortBackgroundColor,
                    ),
                    padding: const EdgeInsets.symmetric(horizontal: Dimensions.paddingSizeDefault, vertical: Dimensions.paddingSizeExtraSmall),
                    margin: const EdgeInsets.only(top: Dimensions.paddingSizeSmall),
                    child: Row(mainAxisSize: MainAxisSize.min, children: [
                      const Icon(Icons.touch_app_outlined, size: 15, color: Colors.black),
                      const SizedBox(width: 3),

                      Text("tap_for_balance".tr, style: rubikRegular.copyWith(
                        fontSize: Dimensions.radiusSizeSmall,
                        color: Colors.black,
                      )),
                    ]),
                  ),
                ) : Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Row(
                    children: [
                      Text(
                        PriceConverterHelper.balanceWithSymbol(
                          balance: '${(profileController.userInfo?.balance ?? 0) > 0 ? profileController.userInfo?.balance : 0}',
                        ),
                        style: rubikMedium.copyWith(color: Colors.white, fontSize: Dimensions.fontSizeExtraLarge),
                      ),

                      if((profileController.userInfo?.balance ?? 0) < 0) ...[
                        SizedBox(width: Dimensions.paddingSizeSmall),

                        Text('(${'due'.tr} -${PriceConverterHelper.convertPrice(profileController.userInfo?.balance?.abs() ?? 0)})', style: rubikRegular.copyWith(
                          fontSize: Dimensions.fontSizeDefault,
                          color: Theme.of(context).colorScheme.error.withValues(alpha: 0.8),
                          height: 1.0,
                        )),
                        SizedBox(width: Dimensions.paddingSizeSmall),


                        Tooltip(
                          triggerMode: TooltipTriggerMode.tap,
                          message: 'your_balance_will_adjust_after_added_balance'.tr,
                          child: Icon(Icons.info, color: Theme.of(context).colorScheme.error.withValues(alpha: 0.8), size: Dimensions.paddingSizeDefault),
                        ),

                      ],
                    ],
                  ),

                  Text('available_balance'.tr, style: rubikRegular.copyWith(
                      fontSize: Dimensions.fontSizeDefault, color: Colors.white.withValues(alpha:0.8,), height: 1.0
                  )),
                ]),
              ),
            ),
          ],
        ),
      );
    });
  }
}

