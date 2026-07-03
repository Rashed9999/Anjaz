import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/features/splash/controllers/splash_controller.dart';
import 'package:amyal_pay/features/home/controllers/websitelink_controller.dart';
import 'package:amyal_pay/util/app_constants.dart';
import 'package:amyal_pay/util/dimensions.dart';
import 'package:amyal_pay/util/images.dart';
import 'package:amyal_pay/util/styles.dart';
import 'package:amyal_pay/common/widgets/custom_image_widget.dart';
import 'package:amyal_pay/common/widgets/custom_ink_well_widget.dart';
import 'package:amyal_pay/common/screens/web_screen.dart';
import 'package:amyal_pay/features/home/widgets/web_site_shimmer_widget.dart';

class LinkedWebsiteWidget extends StatelessWidget {
  const LinkedWebsiteWidget({super.key});

  @override
  Widget build(BuildContext context) {
    return GetBuilder<SplashController>(
      builder: (splashController) {
        return splashController.configModel!.systemFeature!.linkedWebSiteStatus! ?
        GetBuilder<WebsiteLinkController>(builder: (websiteLinkController){
          return websiteLinkController.isLoading ?
          const WebSiteShimmer() : websiteLinkController.websiteList!.isEmpty ?
          const SizedBox() :  Container(
            decoration: BoxDecoration(
              color: Theme.of(context).cardColor,
              boxShadow: [BoxShadow(
                color: Theme.of(context).shadowColor.withValues(alpha:0.01),
                blurRadius: 20, offset: const Offset(0, 3),
              )],
            ),
            child: Stack( children: [

              Positioned.fill(child: Image.asset(Images.linkedWebsiteBg, fit: BoxFit.fitWidth)),
              Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                const SizedBox(height: Dimensions.paddingSizeExtraLarge),

                Row(mainAxisAlignment: MainAxisAlignment.spaceBetween , children: [
                  Expanded(
                    child: Column( crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Padding(
                        padding: const EdgeInsets.symmetric(horizontal: Dimensions.paddingSizeLarge),
                        child: Text(
                          '${'pay_with'.tr} ${AppConstants.appName}'.tr, style: rubikMedium.copyWith(
                          fontSize: Dimensions.fontSizeSemiLarge,
                          color: Theme.of(context).textTheme.titleLarge!.color,
                        ),),
                      ),
                    
                    
                      Padding(
                        padding: const EdgeInsets.symmetric(horizontal: Dimensions.paddingSizeLarge),
                        child: Text('${'you_can_pay_your_bills_using'.tr} ${AppConstants.appName} ${'from_this_sites'.tr}', style: rubikRegular.copyWith(
                          color: Theme.of(context).hintColor, fontSize: Dimensions.fontSizeDefault,
                        )),
                      ),
                    ]),
                  ),
                  Padding(
                    padding: const EdgeInsets.symmetric(horizontal: Dimensions.paddingSizeLarge),
                    child: Image.asset(Images.payIcon, height : 45, fit: BoxFit.fitHeight,),
                  )
                ]),

                Container(
                  height: 110,
                  width: double.infinity,
                  margin: const EdgeInsets.only(top: Dimensions.paddingSizeDefault),

                  child: ListView.builder(
                    itemCount: websiteLinkController.websiteList!.length,
                    scrollDirection: Axis.horizontal,
                    physics: const ClampingScrollPhysics(),
                    itemBuilder: (context, index) {
                      return CustomInkWellWidget(
                        onTap: () => Get.to(WebScreen(selectedUrl: websiteLinkController.websiteList![index].url!)),
                        radius: Dimensions.radiusSizeExtraSmall,
                        highlightColor: Theme.of(context).primaryColor.withValues(alpha:0.1),
                        child: Container(width: 90,
                          padding: const EdgeInsets.symmetric(vertical: Dimensions.paddingSizeSmall),
                          child: Column(
                            children: [
                              Container(
                                decoration: BoxDecoration(
                                  borderRadius: BorderRadius.circular(Dimensions.radiusSizeSmall),
                                  border: Border.all(color: Theme.of(context).hintColor.withValues(alpha:0.4), width: 0.5)
                                ),
                                padding: const EdgeInsets.all(Dimensions.paddingSizeExtraSmall),
                                child: SizedBox(width: Dimensions.paddingSizeOverLarge, height: Dimensions.paddingSizeOverLarge,
                                  child: CustomImageWidget(
                                    image: "${Get.find<SplashController>().configModel?.baseUrls?.linkedWebsiteImageUrl
                                    }/${websiteLinkController.websiteList?[index].image ?? ""}",
                                    placeholder: Images.webLinkPlaceHolder, fit: BoxFit.cover,
                                  ),
                                ),
                              ),
                              const SizedBox(height: 7),
                              Text(
                                textAlign: TextAlign.center, maxLines: 2, overflow: TextOverflow.ellipsis,
                                websiteLinkController.websiteList?[index].name ?? "",
                                style: rubikRegular.copyWith(
                                  height: 1.0,
                                  color: Theme.of(context).hintColor, fontSize: Dimensions.fontSizeDefault,
                                ),
                              ),
                            ],
                          ),
                        ),
                      );
                    },
                  ),
                ),
                const SizedBox(height: Dimensions.paddingSizeSmall),
              ]),
            ]),
          );
        }
        ) : const SizedBox();
      }
    );
  }
}
