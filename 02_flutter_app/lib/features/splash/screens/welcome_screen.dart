import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/features/auth/controllers/auth_controller.dart';
import 'package:amyal_pay/features/auth/domain/models/user_short_data_model.dart';
import 'package:amyal_pay/helper/route_helper.dart';
import 'package:amyal_pay/util/app_constants.dart';
import 'package:amyal_pay/util/dimensions.dart';
import 'package:amyal_pay/util/styles.dart';
import 'package:amyal_pay/common/widgets/custom_logo_widget.dart';

class WelcomeScreen extends StatefulWidget {
  final String? phoneNumber;
  final String? countryCode;
  final String? password;

  const WelcomeScreen({
    super.key, this.phoneNumber, this.countryCode, this.password,
  });

  @override
  State<WelcomeScreen> createState() => _WelcomeScreenState();
}

class _WelcomeScreenState extends State<WelcomeScreen> {
  @override
  void initState() {
    Get.find<AuthController>().bioAthPinSetup(widget.password).then((_) {

      UserShortDataModel? userData = Get.find<AuthController>().getUserData();
      Get.offAllNamed(RouteHelper.getLoginRoute(
        countryCode: widget.countryCode,
        phoneNumber: widget.phoneNumber,
        userName: userData?.name ?? ''
      ));
    });
    super.initState();
  }
  @override
  Widget build(BuildContext context) {
    final Size size = MediaQuery.of(context).size;
    return Scaffold(
      backgroundColor: Theme.of(context).primaryColor,
      body: Container(
        height: size.height * 0.7,
        width: double.infinity,
        padding: const EdgeInsets.symmetric(horizontal: Dimensions.paddingSizeExtraExtraLarge),
        decoration: BoxDecoration(
          color: Theme.of(context).cardColor,
          borderRadius: const BorderRadius.only(
            bottomLeft:
                Radius.circular(Dimensions.radiusSizeExtraExtraLarge),
            bottomRight:
                Radius.circular(Dimensions.radiusSizeExtraExtraLarge),
          ),
        ),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            const CustomLogoWidget(
              height: 90,
              width: 90,
            ),
            const SizedBox(
              height: Dimensions.paddingSizeExtraLarge,
            ),
             Text(
                '${'welcome_to'.tr} ${AppConstants.appName} !',
                textAlign: TextAlign.center,
                style: rubikMedium.copyWith(
                  color: Theme.of(context).textTheme.titleLarge!.color,
                  fontSize: Dimensions.fontSizeOverOverLarge,
                ),
              ),
              const SizedBox(
              height: Dimensions.paddingSizeExtraSmall,
            ),
              const SizedBox(
              height: Dimensions.paddingSizeOverLarge,
            ),
             Text(
                'start_exploring_the_amazing_ways_to_take_your_lifestyle_upward'.tr,
                textAlign: TextAlign.center,
                style: rubikLight.copyWith(
                  color: Theme.of(context).textTheme.bodyLarge!.color,
                  fontSize: Dimensions.fontSizeExtraLarge,
                ),
              ),
               const SizedBox(
              height: Dimensions.paddingSizeOverLarge,
            ),
          ],
        ),
      ),
    );
  }
}
