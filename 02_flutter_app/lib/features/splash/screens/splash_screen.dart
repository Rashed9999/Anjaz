import 'dart:async';
import 'package:flutter/foundation.dart';
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

     // فحص الاتصال/الـ VPN يعتمد على connectivity_plus، وهو موجّه للموبايل
     // (الهدف الفعلي). على غيره قد تغيب البنية (NetworkManager/D-Bus) فيرمي خطأً.
     // نقصره على الموبايل؛ والتوجيه يتمّ بكل الأحوال عبر _route() أدناه.
     // صلابة إضافية: نتعامل مع أخطاء الـ stream بأمان دون إسقاط الشاشة.
     if (GetPlatform.isMobile) {
       subscription = Connectivity().onConnectivityChanged.listen((List<ConnectivityResult> result) async {
        if(await ApiChecker.isVpnActive()) {
          showCustomSnackBarHelper('you are using vpn', isVpn: true, duration: const Duration(minutes: 10));
        }
        if(isFirstTime) {
          isFirstTime = false;
          await _route();
        }
      }, onError: (Object e) {
        if (kDebugMode) debugPrint('[Splash] connectivity stream error: $e');
      });
     } else {
       subscription = const Stream<List<ConnectivityResult>>.empty().listen((_) {});
     }

     _route();




  }


  @override
  void dispose() {
    subscription.cancel();
    super.dispose();
  }

  Future<void> _route() async {
    // AMIAL-FIX: التطبيق كان يعلق على شاشة البداية للأبد إذا كان الخادم غير
    // متاح (getConfigData بلا مهلة). الآن: نحاول جلب الإعدادات بمهلة قصيرة،
    // وسواء نجح أو فشل نُكمل التوجيه ليبقى التطبيق قابلاً للاستخدام.
    try {
      await Get.find<SplashController>()
          .getConfigData()
          .timeout(const Duration(seconds: 8));
    } catch (_) {
      // الخادم غير متاح أو بطيء — نُكمل بدل التعليق.
    }

    await Future.delayed(const Duration(seconds: 1));

    try {
      await Get.find<SplashController>().initSharedData();
    } catch (_) {/* تجاهل — بيانات محلية */}

    UserShortDataModel? userData = Get.find<AuthController>().getUserData();
    final config = Get.find<SplashController>().configModel;

    // AMIAL: كل المسارات تؤدّي لدخول أميال باي الموحّد — لا لشاشة PIN القديمة
    // (6cash). المستخدم العائد (userData موجود) يذهب مباشرةً للدخول الموحّد؛
    // الجديد يمرّ باختيار اللغة ثمّ الدخول الموحّد.
    if (userData != null && config?.companyName != null) {
      if (GetPlatform.isAndroid) {
        try { await FirebaseMessaging.instance.requestPermission(); } catch (_) {}
      }
      Get.offNamed(RouteHelper.getUnifiedLoginRoute());
    } else {
      Get.offNamed(RouteHelper.getChoseLanguageRoute());
    }
  }

  @override
  Widget build(BuildContext context) {
    // AMIAL-SPLASH: نفس أصفر الشعار في سبلاش النظام (#FECA1E) — كانت الخلفية
    // بيضاء افتراضياً فتظهر «شاشة بيضاء» بين سبلاش النظام وشاشة الدخول.
    return Scaffold(
      backgroundColor: const Color(0xFFFECA1E),
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
