import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/features/auth/controllers/auth_controller.dart';
import 'package:amyal_pay/features/auth/domain/models/user_short_data_model.dart';
import 'package:amyal_pay/helper/route_helper.dart';
import 'package:amyal_pay/util/app_constants.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';
import 'package:amyal_pay/common/widgets/custom_logo_widget.dart';

/// AMIAL-UNIFY-UI-001 — «مرحباً» بهوية أميال (كانت شاشة 6cash بأنماط قديمة).
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
    super.initState();
    Get.find<AuthController>().bioAthPinSetup(widget.password).then((_) {
      UserShortDataModel? userData = Get.find<AuthController>().getUserData();
      Get.offAllNamed(RouteHelper.getUnifiedLoginRoute());
    });
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmyalColors.primary,
      body: SafeArea(
        child: Center(
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              Container(
                padding: const EdgeInsets.all(24),
                decoration: BoxDecoration(
                  color: Colors.white.withValues(alpha: 0.12),
                  shape: BoxShape.circle,
                ),
                child: const CustomLogoWidget(height: 90, width: 90),
              ),
              const SizedBox(height: 28),
              Text(
                '${'welcome_to'.tr} ${AppConstants.appName}',
                textAlign: TextAlign.center,
                style: const TextStyle(
                  color: Colors.white,
                  fontSize: 24,
                  fontWeight: FontWeight.bold,
                ),
              ),
              const SizedBox(height: 12),
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: 40),
                child: Text(
                  'start_exploring_the_amazing_ways_to_take_your_lifestyle_upward'.tr,
                  textAlign: TextAlign.center,
                  style: const TextStyle(color: Colors.white70, fontSize: 14, height: 1.5),
                ),
              ),
              const SizedBox(height: 40),
              const SizedBox(
                width: 26, height: 26,
                child: CircularProgressIndicator(color: AmyalColors.yellow, strokeWidth: 2.5),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
