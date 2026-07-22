import 'dart:async';
import 'package:flutter/foundation.dart';
import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:amyal_pay/features/auth/controllers/auth_controller.dart';
import 'package:amyal_pay/features/splash/controllers/splash_controller.dart';
import 'package:amyal_pay/data/api/api_checker.dart';
import 'package:amyal_pay/helper/route_helper.dart';
import 'package:amyal_pay/util/app_constants.dart';
import 'package:amyal_pay/util/images.dart';
import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:shared_preferences/shared_preferences.dart';
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
    // AMIAL-STARTUP-FLOW-001: بداية سريعة واحدة.
    // - لا تأخير صناعي (كانت ثانية كاملة فوق سبلاش النظام = «شاشتا شعار»).
    // - جلب الإعدادات بمهلة قصيرة، وسواء نجح أو فشل نُكمل (لا تعليق).
    try {
      await Get.find<SplashController>()
          .getConfigData()
          .timeout(const Duration(seconds: 5));
    } catch (_) {
      // الخادم غير متاح أو بطيء — نُكمل بدل التعليق.
    }

    try {
      await Get.find<SplashController>().initSharedData();
    } catch (_) {/* تجاهل — بيانات محلية */}

    if (!mounted) return;

    // AMIAL-STARTUP-FLOW-001: شاشة اللغة تظهر «أول تشغيل فقط» — علامتها وجود
    // language_code المحفوظ عند أيّ اختيار سابق. كانت تظهر لكل غير مسجّل دخول
    // (وكل مرة يفشل فيها جلب الإعداد) — وهذا خطأ تدفّق لا علاقة له بالحساب.
    final prefs = Get.find<SharedPreferences>();
    final languageChosen = prefs.containsKey(AppConstants.languageCode);

    if (!languageChosen) {
      Get.offNamed(RouteHelper.getChoseLanguageRoute());
      return;
    }

    if (GetPlatform.isAndroid &&
        Get.find<AuthController>().getUserData() != null) {
      try { await FirebaseMessaging.instance.requestPermission(); } catch (_) {}
    }
    Get.offNamed(RouteHelper.getUnifiedLoginRoute());
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
