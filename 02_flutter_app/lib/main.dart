import 'dart:async';

import 'package:camera/camera.dart';
import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter_local_notifications/flutter_local_notifications.dart';
import 'package:flutter_smart_dialog/flutter_smart_dialog.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/common/models/notification_body.dart';
import 'package:amyal_pay/features/language/controllers/localization_controller.dart';
import 'package:amyal_pay/features/setting/controllers/theme_controller.dart';
import 'package:amyal_pay/helper/notification_helper.dart';
import 'package:amyal_pay/helper/route_helper.dart';
import 'package:amyal_pay/theme/dark_theme.dart';
import 'package:amyal_pay/theme/light_theme.dart';
import 'package:amyal_pay/util/app_constants.dart';
import 'package:amyal_pay/util/messages.dart';

import 'helper/get_di.dart' as di;

final FlutterLocalNotificationsPlugin flutterLocalNotificationsPlugin = FlutterLocalNotificationsPlugin();
 late List<CameraDescription> cameras;

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();

  try{
    await Firebase.initializeApp(options: FirebaseOptions(
      apiKey: "AIzaSyA40XT2LSjEI_V9LCfp8YDE2qN_P9Fcduw", ///current_key here
      appId: "1:384321080318:android:f1fd798581de62ff2c0eaf", ///mobilesdk_app_id here
      messagingSenderId: "384321080318", ///project_number here
      projectId: "gem-b5006", ///project_id her
    ));

  }catch(e) {
    await Firebase.initializeApp();
  }

  cameras = await availableCameras();

  Map<String, Map<String, String>> languages = await di.init();

  int? orderID;
  NotificationBody? body;
  try {
    final RemoteMessage? remoteMessage = await FirebaseMessaging.instance.getInitialMessage();
    if (remoteMessage != null) {
      body = NotificationHelper.convertNotification(remoteMessage.data);
    }
    await NotificationHelper.initialize(flutterLocalNotificationsPlugin);
    FirebaseMessaging.onBackgroundMessage(myBackgroundMessageHandler);
  }catch(e) {
    if (kDebugMode) {
      // debug print removed — AMIAL-FIX-003
    }
  }

  runApp(MyApp(languages: languages, orderID: orderID));

}

class MyApp extends StatelessWidget {
  final Map<String, Map<String, String>>? languages;
  final int? orderID;
  const MyApp({super.key, required this.languages, required this.orderID});

  @override
  Widget build(BuildContext context) {
    return GetBuilder<ThemeController>(builder: (themeController) {
      return GetBuilder<LocalizationController>(builder: (localizeController) {
        return MediaQuery(
          data: MediaQuery.of(context).copyWith(textScaler: const TextScaler.linear(0.95)),
          child: SafeArea(top: false, child: GetMaterialApp(
            navigatorObservers: [FlutterSmartDialog.observer],
            builder: FlutterSmartDialog.init(),
            title: AppConstants.appName,
            debugShowCheckedModeBanner: false,
            navigatorKey: Get.key,
            theme: themeController.darkTheme ? dark : light,
            locale: localizeController.locale,
            translations: Messages(languages: languages),
            fallbackLocale: Locale(AppConstants.languages[0].languageCode!, AppConstants.languages[0].countryCode),
            initialRoute: RouteHelper.getSplashRoute(),
            getPages: RouteHelper.routes,
            defaultTransition: Transition.topLevel,
            transitionDuration: const Duration(milliseconds: 500),

          )),
        );
      },
      );
    },
    );
  }
}
