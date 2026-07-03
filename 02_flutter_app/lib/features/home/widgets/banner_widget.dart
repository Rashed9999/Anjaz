import 'package:carousel_slider/carousel_slider.dart';
import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/features/home/controllers/banner_controller.dart';
import 'package:amyal_pay/features/splash/controllers/splash_controller.dart';
import 'package:amyal_pay/util/dimensions.dart';
import 'package:amyal_pay/util/images.dart';
import 'package:amyal_pay/common/widgets/custom_image_widget.dart';
import 'package:amyal_pay/common/screens/web_screen.dart';
import 'package:amyal_pay/features/home/widgets/banner_shimmer_widget.dart';
import 'package:smooth_page_indicator/smooth_page_indicator.dart';

class BannerWidget extends StatelessWidget {
  const BannerWidget({super.key});

  @override
  Widget build(BuildContext context) {
    final Size size = MediaQuery.of(context).size;
    return GetBuilder<SplashController>(
      builder: (splashController) {


        return (splashController.configModel?.systemFeature?.bannerStatus ?? false) ? GetBuilder<BannerController>(builder: (bannerController){
          return bannerController.bannerList == null  ? const Center(child: BannerShimmerWidget()) :
          bannerController.bannerList!.isNotEmpty ?  Container(
            margin: const EdgeInsets.symmetric(vertical: Dimensions.paddingSizeLarge),
            child: Column( children: [
              SizedBox(
                height: size.width / 3.5, width: size.width,
                child: CarouselSlider.builder(itemCount: bannerController.bannerList!.length,
                  itemBuilder: (context, index, realIndex) {
                    final image = bannerController.bannerList!.isNotEmpty ? bannerController.bannerList![index].image : '';
                    return InkWell(
                      onTap: (){
                        if(bannerController.bannerList!.isNotEmpty){
                          Get.to(WebScreen(selectedUrl: bannerController.bannerList![index].url!));
                        }
                      },

                      child: Padding(padding: const EdgeInsets.symmetric(horizontal: Dimensions.paddingSizeDefault),
                        child: Container(
                          width: MediaQuery.of(context).size.width,
                          decoration: BoxDecoration(borderRadius: BorderRadius.circular(Dimensions.radiusSizeDefault)),
                          child: ClipRRect(borderRadius: BorderRadius.circular(10),
                            child: CustomImageWidget(image: "${Get.find<SplashController>().configModel!.baseUrls!.bannerImageUrl}/$image", fit: BoxFit.cover, placeholder: Images.bannerPlaceHolder),
                          ),
                        ),
                      ),
                    );
                  },

                  options: CarouselOptions(autoPlay: true,
                    enlargeCenterPage: true, autoPlayInterval: const Duration(seconds: 4), viewportFraction: 1,
                    onPageChanged: (index, reason)=>bannerController.updateBannerActiveIndex(index),
                  ),
                ),
              ),
              const SizedBox(height: Dimensions.paddingSizeSmall),
              AnimatedSmoothIndicator(
                activeIndex: bannerController.bannerActiveIndex,
                count: Get.find<BannerController>().bannerList!.length,
                effect: CustomizableEffect(
                  dotDecoration: DotDecoration(
                    height: 7, width: 7, borderRadius: BorderRadius.circular(5),
                    color: Theme.of(context).primaryColor.withValues(alpha:0.2),
                  ),
                  activeDotDecoration:  DotDecoration(
                    height: 8, width: 8, borderRadius: const BorderRadius.all(Radius.circular(5)),
                    color: Theme.of(context).primaryColor,
                  ),
                ),
              ),
              ],
            ),
          ) : const SizedBox();
        }) : const SizedBox();
      }
    );
  }
}
