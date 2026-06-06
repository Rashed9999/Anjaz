import 'dart:async';
import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:amyal_pay/features/auth/controllers/auth_controller.dart';
import 'package:amyal_pay/features/splash/controllers/splash_controller.dart';
import 'package:amyal_pay/data/api/api_checker.dart';
import 'package:amyal_pay/features/auth/domain/models/user_short_data_model.dart';
import 'package:amyal_pay/helper/route_helper.dart';
import 'package:amyal_pay/util/images.dart';
import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/helper/custom_snackbar_helper.dart';

class SplashScreen extends StatefulWidget {
  const SplashScreen({super.key});

  @override
  State<SplashScreen> createState() => _SplashScreenState();
}

class _SplashScreenState extends State<SplashScreen> with WidgetsBindingObserver {
  late StreamSubscription<List<ConnectivityResult>> subscription;

  @override
  void initState() {
    super.initState();

    bool isFirstTime = true;

     subscription = Connectivity().onConnectivityChanged.listen((List<ConnectivityResult> result) async {
      if(await ApiChecker.isVpnActive()) {
        showCustomSnackBarHelper('you are using vpn', isVpn: true, duration: const Duration(minutes: 10));
      }
      if(isFirstTime) {
        isFirstTime = false;
        await _route();
      }
    });

     _route();




  }


  @override
  void dispose() {
    super.dispose();
  }

  Future<void> _route() async {
    Get.find<SplashController>().getConfigData().then((value) {
      if(value.isOk) {
        Timer(const Duration(seconds: 1), () async {
          Get.find<SplashController>().initSharedData().then((value) async {
            UserShortDataModel? userData = Get.find<AuthController>().getUserData();


            if(userData != null && (Get.find<SplashController>().configModel!.companyName != null)){
              if(GetPlatform.isAndroid){
                await  FirebaseMessaging.instance.requestPermission();

              }
              Get.offNamed(RouteHelper.getLoginRoute(
                countryCode: userData.countryCode, phoneNumber: userData.phone,
                userName: userData.name ?? ''
              ));
            }else{
              Get.offNamed(RouteHelper.getChoseLanguageRoute());
            }
          });

        });
      }
    });
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Image.asset(Images.logo, height: 175),
          ],
        ),
      ),
    );
  }
}
