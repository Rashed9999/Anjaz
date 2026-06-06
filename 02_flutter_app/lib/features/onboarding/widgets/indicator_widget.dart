import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/features/onboarding/controllers/on_boarding_controller.dart';
import 'package:smooth_page_indicator/smooth_page_indicator.dart';

class IndicatorWidget extends StatelessWidget {
  const IndicatorWidget({
    super.key,
  });

  @override
  Widget build(BuildContext context) {
    return GetBuilder<OnBoardingController>(
      
      builder: (onBoardController) {
      return AnimatedSmoothIndicator(
        activeIndex: onBoardController.page,
        count: onBoardController.onboardList.length,
        curve: Curves.easeOutSine,
        effect: CustomizableEffect(

          dotDecoration: DotDecoration(
            height: 5,
            width: 5,
            borderRadius: BorderRadius.circular(5),
            color: Theme.of(context).textTheme.titleLarge!.color!.withValues(alpha:0.2),
          ),
          activeDotDecoration: DotDecoration(
            height: 15,
            width: 15,
            borderRadius: const BorderRadius.all(Radius.circular(10)),
            color: Theme.of(context).primaryColorLight,
          ),
        ),
      );
    });
  }
}
