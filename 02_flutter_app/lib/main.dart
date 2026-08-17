import 'dart:async';

import 'package:amial_pay/data/api/api_client.dart';
import 'package:camera/camera.dart';
import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter_local_notifications/flutter_local_notifications.dart';
import 'package:flutter_smart_dialog/flutter_smart_dialog.dart';
import 'package:get/get.dart';
import 'package:amial_pay/common/models/notification_body.dart';
import 'package:amial_pay/features/language/controllers/localization_controller.dart';
import 'package:amial_pay/features/setting/controllers/theme_controller.dart';
import 'package:amial_pay/helper/amial_crash_reporter.dart';
import 'package:amial_pay/helper/notification_helper.dart';
import 'package:amial_pay/helper/route_helper.dart';
import 'package:amial_pay/theme/dark_theme.dart';
import 'package:amial_pay/theme/light_theme.dart';
import 'package:amial_pay/util/app_constants.dart';
import 'package:amial_pay/util/messages.dart';
import 'package:amial_pay/firebase_options.dart';

import 'helper/get_di.dart' as di;
import 'package:amial_pay/features/auth/controllers/session_guard.dart';

final FlutterLocalNotificationsPlugin flutterLocalNotificationsPlugin = FlutterLocalNotificationsPlugin();
 late List<CameraDescription> cameras;

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();

  // AMIAL-FCM-001: كانت هنا إعدادات مشروع القالب gem-b5006 مكتوبةً يدوياً،
  // وهي لمشروع لا تملكه ولا حزمتنا مسجّلة فيه. صارت تُقرأ من AmialFirebaseOptions
  // المطابق لـ android/app/google-services.json.
  try{
    final options = AmialFirebaseOptions.forCurrentPlatform();
    await Firebase.initializeApp(options: options);
  }catch(e) {
    // منصّة غير مسجّلة أو تهيئة مكرّرة — نرجع للملف الأصلي في المشروع الأصلي.
    try {
      await Firebase.initializeApp();
    } catch (_) {
      // بلا Firebase يبقى التطبيق يعمل كاملاً عدا الإشعارات.
    }
  }

  // AMIAL-CRASH-001: أوّل ما يُهيَّأ بعد Firebase — كل ما بعده صار مرصوداً.
  await AmialCrashReporter.init();

  cameras = await availableCameras();

  Map<String, Map<String, String>> languages = await di.init();

  // ══════════════════════════════════════════════════════════════════
  // AMIAL-POS-DEVICES-009 — **هويّةُ مقعد الجهاز تُلصق قبل أوّل طلب.**
  //
  // فالخادمُ يربط رمزَ الجلسة بمقعدٍ مسجَّل عند الدخول، ويقارن الترويسةَ
  // في كلّ طلبٍ بعده. **ولو أُلصقت بعد الدخول لدخل الموظّفُ بلا مقعد**
  // ثمّ تعذّر ربطُه، فيعمل في «الوضع الصامت» أبداً ولا يُغلق شيء.
  //
  // وتُلصق قبل `runApp` لا في شاشةٍ بعينها: الدخولُ له أكثرُ من باب.
  try {
    await Get.find<ApiClient>().attachPosDeviceHeader();
  } catch (_) {
    // **لا يُوقَف الإقلاعُ لأجلها** — غيابُ الترويسة يُعالَج في الخادم
    // (‏وضعٌ صامتٌ أو منعٌ صريح)، وتعطيلُ التطبيق كلِّه أسوأُ من كليهما.
  }

  int? orderID;
  NotificationBody? body;
  try {
    final RemoteMessage? remoteMessage = await FirebaseMessaging.instance.getInitialMessage();
    if (remoteMessage != null) {
      body = NotificationHelper.convertNotification(remoteMessage.data);
    }
    await NotificationHelper.initialize(flutterLocalNotificationsPlugin);
    FirebaseMessaging.onBackgroundMessage(myBackgroundMessageHandler);
  }catch(e, s) {
    // AMIAL-CRASH-001: كان يُبتلع صامتاً. وفشلُ التهيئة هنا يعني أن كل
    // إشعارات هذا الجهاز صامتة — عطل جسيم لا أثر له في الواجهة إطلاقاً.
    AmialCrashReporter.record(e, s, reason: 'تهيئة الإشعارات');
  }

  // AMIAL-SESSION-GUARD-001: مراقبة دورة حياة التطبيق — إغلاق الجلسة عند
  // إطفاء الشاشة أو ضغط زرّ البيت أو نقل التطبيق إلى نافذة.
  SessionGuard.instance.attach();

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
        // AMIAL-SPLASH-003: أُزيل SafeArea الذي كان يلفّ GetMaterialApp كلّه.
        //
        // لفّ التطبيق كاملاً بـ SafeArea خطأ بنيوي: الحشوات تُطبَّق مرّة واحدة
        // على الجذر فتُقلَّص نافذة التطبيق كلّها — كل الشاشات، لا الشاشة التي
        // تحتاجها. وهو الودجت الوحيد في هذه الشجرة القادر على تضييق عرض
        // التطبيق، وقد ظهر أثره في شاشة البداية: الشعار مقصوص من اليسار
        // والمحتوى كلّه متمركز حول 61% من عرض الشاشة لا حول منتصفها.
        //
        // الصحيح أن تستعمل كل شاشة SafeArea لنفسها — وشاشة البداية تفعل.
        return MediaQuery(
          data: MediaQuery.of(context).copyWith(textScaler: const TextScaler.linear(0.95)),
          child: GetMaterialApp(
            // AMIAL-CRASH-001: مسار الشاشات يُرفق بالعطل — «انهار عند الدفع»
            // و«انهار عند الدفع قادماً من المسح» عطلان مختلفان في التشخيص.
            navigatorObservers: [FlutterSmartDialog.observer, AmialCrashRouteObserver()],
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

          ),
        );
      },
      );
    },
    );
  }
}
