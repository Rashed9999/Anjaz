import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:get/get.dart';
import 'package:amial_pay/common/widgets/custom_pop_scope_widget.dart';
import 'package:amial_pay/features/language/controllers/localization_controller.dart';
import 'package:amial_pay/features/onboarding/controllers/on_boarding_controller.dart';
import 'package:amial_pay/helper/route_helper.dart';
import 'package:amial_pay/util/dimensions.dart';
import 'package:amial_pay/util/styles.dart';
import 'package:amial_pay/common/widgets/custom_small_button_widget.dart';
import 'package:amial_pay/features/onboarding/widgets/indicator_widget.dart';


class OnBoardingScreen extends StatefulWidget {
  const OnBoardingScreen({super.key});

  @override
  State<OnBoardingScreen> createState() => _OnBoardingScreenState();
}

class _OnBoardingScreenState extends State<OnBoardingScreen> {
  final PageController _pageController = PageController();


  @override
  void initState() {
    super.initState();

    Get.find<OnBoardingController>().updatePageIndex(0, isUpdate: false);
    Get.find<OnBoardingController>().getOnboardList();
  }


  @override
  Widget build(BuildContext context) {

    final OnBoardingController onBoardingController = Get.find<OnBoardingController>();
    final Size size = MediaQuery.of(context).size;

    return CustomPopScopeWidget(
      child: Scaffold(
        backgroundColor: Theme.of(context).cardColor,
        body: GetBuilder<LocalizationController>(
          builder: (localizationController) {
            return Column(children: [
      
              Expanded(child: Column(mainAxisSize: MainAxisSize.min, children: [
      
                Expanded(flex: 15, child: NotificationListener(
                  onNotification: (ScrollNotification notification) {
                    if (notification is UserScrollNotification) {
                      if(onBoardingController.page == onBoardingController.onboardList.length - 1 &&
                          notification.direction == ScrollDirection.reverse){
                        Get.offAllNamed(RouteHelper.getRegistrationRoute());
                      }
                    }
                    return false;
                  },
                  child: PageView.builder(
                    controller: _pageController,
                    itemCount: onBoardingController.onboardList.length,
                    scrollDirection: Axis.horizontal,
                    onPageChanged: (page) {
                      Get.find<OnBoardingController>().updatePageIndex(page);
      
                    },
                    itemBuilder: (context, index) {
                      return Column(children: [
      
                        SizedBox(height: size.width, width: size.width, child: Stack(children: [
      
                          SizedBox(width: double.infinity,
                            child: Image.asset(
                              onBoardingController.onboardList[index].backgroundImage,
                              fit: BoxFit.fitWidth,
                            ),
                          ),
      
                          Align(alignment: Alignment.bottomCenter, child: SizedBox(
                            height: MediaQuery.of(context).size.width * 0.6,
                            child: Image.asset(onBoardingController.onboardList[index].image, fit: BoxFit.fitHeight),
                          )),
      
                        ])),
                        SizedBox(height: size.height * 0.1),
      
                        Padding(padding: const EdgeInsets.symmetric(horizontal: Dimensions.paddingSizeDefault),
                          child: Column(children: [
      
                            Text(onBoardingController.onboardList[index].title, style: rubikSemiBold.copyWith(color: Theme.of(context).textTheme.bodyLarge!.color, fontSize: Dimensions.fontSizeExtraLarge,), textAlign: TextAlign.center,),
                            const SizedBox(height: Dimensions.paddingSizeDefault,),
      
                            Padding(
                              padding: const EdgeInsets.symmetric(horizontal: Dimensions.radiusSizeSmall),
                              child: Text(onBoardingController.onboardList[index].subtitle,
                                maxLines: 5,
                                overflow: TextOverflow.ellipsis,
                                style: rubikMedium.copyWith(
                                  color: Theme.of(context).textTheme.bodyMedium?.color?.withValues(alpha: 0.7),
                                  fontSize: Dimensions.fontSizeLarge,
                                ),
                                textAlign: TextAlign.center,
                              ),
                            ),
                          ]),
                        ),
      
                      ]);
                    },
                  ),
                )),
      
      
              ])),

              const IndicatorWidget(),
              SizedBox(height: size.height * 0.07),
      
              Row(children: [
      
                Expanded(
                  child: Container(
                    padding: const EdgeInsets.only(left: Dimensions.paddingSizeDefault, right: Dimensions.paddingSizeDefault, bottom: Dimensions.paddingSizeExtraExtraLarge),
                    child: CustomSmallButtonWidget(
                      onTap: () => Get.offAllNamed(RouteHelper.getRegistrationRoute()),
                      backgroundColor: Theme.of(context).colorScheme.secondary,
                      text: 'login_registration'.tr,
                      textStyle: rubikSemiBold.copyWith(
                        color: Theme.of(context).textTheme.bodyLarge!.color,
                        fontSize: Dimensions.fontSizeLarge
                      ),
                    ),
                  ),
                ),
              ])
      
            ]);
          }
        ),
      ),
    );
  }
}
