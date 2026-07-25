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
import 'package:amyal_pay/theme/amyal_colors.dart';
import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:amyal_pay/helper/custom_snackbar_helper.dart';

class SplashScreen extends StatefulWidget {
  const SplashScreen({super.key});

  @override
  State<SplashScreen> createState() => _SplashScreenState();
}

class _SplashScreenState extends State<SplashScreen>
    with WidgetsBindingObserver, SingleTickerProviderStateMixin {
  late StreamSubscription<List<ConnectivityResult>> subscription;

  // AMYAL-UI-001: خطوات إقلاع مرئية — تُظهر أن التطبيق «يعمل» أثناء التهيئة.
  final ValueNotifier<int> _stepDone = ValueNotifier<int>(0);

  late final AnimationController _logoCtrl;
  late final Animation<double> _logoScale;

  @override
  void initState() {
    super.initState();

    _logoCtrl = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 700),
    );
    _logoScale = CurvedAnimation(parent: _logoCtrl, curve: Curves.easeOutBack);
    _logoCtrl.forward();

    bool isFirstTime = true;

    // فحص الاتصال/الـ VPN يعتمد على connectivity_plus، وهو موجّه للموبايل
    // (الهدف الفعلي). على غيره قد تغيب البنية (NetworkManager/D-Bus) فيرمي خطأً.
    // نقصره على الموبايل؛ والتوجيه يتمّ بكل الأحوال عبر _route() أدناه.
    // صلابة إضافية: نتعامل مع أخطاء الـ stream بأمان دون إسقاط الشاشة.
    if (GetPlatform.isMobile) {
      subscription = Connectivity().onConnectivityChanged.listen(
          (List<ConnectivityResult> result) async {
        if (await ApiChecker.isVpnActive()) {
          showCustomSnackBarHelper('you are using vpn',
              isVpn: true, duration: const Duration(minutes: 10));
        }
        if (isFirstTime) {
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
    _logoCtrl.dispose();
    _stepDone.dispose();
    super.dispose();
  }

  Future<void> _route() async {
    // AMIAL-STARTUP-FLOW-001: بداية سريعة واحدة.
    // - لا تأخير صناعي (كانت ثانية كاملة فوق سبلاش النظام = «شاشتا شعار»).
    // - جلب الإعدادات بمهلة قصيرة، وسواء نجح أو فشل نُكمل (لا تعليق).

    // خطوة 1: الاتصال (وصلنا هنا فالتطبيق حيّ)
    _mark(1);

    try {
      // خطوة 2: النسخة/الإعدادات البعيدة
      await Get.find<SplashController>()
          .getConfigData()
          .timeout(const Duration(seconds: 5));
    } catch (_) {
      // الخادم غير متاح أو بطيء — نُكمل بدل التعليق.
    }
    _mark(2);

    try {
      // خطوة 3: البيانات المحلية (اللغة/الثيم/الجلسة)
      await Get.find<SplashController>().initSharedData();
    } catch (_) {/* تجاهل — بيانات محلية */}
    _mark(3);

    if (!mounted) return;

    // خطوة 4: جاهز
    _mark(4);

    // AMIAL-STARTUP-FLOW-002: لا شاشة لغة عند الإقلاع إطلاقاً.
    // العربية هي لغة التطبيق الأساسية والإنجليزية ثانوية اختيارية — فسؤال
    // المستخدم اليمني عن لغته قبل أن يرى التطبيق سؤال بلا معنى، ويُقحم شاشة
    // كاملة بين شاشة البدء وتسجيل الدخول. من أراد الإنجليزية يبدّلها من
    // القائمة المنسدلة في شاشة الدخول أو في الإعدادات.
    try {
      final prefs = Get.find<SharedPreferences>();
      if (!prefs.containsKey(AppConstants.languageCode)) {
        await prefs.setString(AppConstants.languageCode, 'ar');
        await prefs.setString(AppConstants.customerCountryCode, 'SA');
      }
    } catch (_) {/* غير حرج */}

    if (GetPlatform.isAndroid &&
        Get.find<AuthController>().getUserData() != null) {
      try {
        await FirebaseMessaging.instance.requestPermission();
      } catch (_) {}
    }
    Get.offNamed(RouteHelper.getUnifiedLoginRoute());
  }

  void _mark(int done) {
    if (!mounted) return;
    if (done > _stepDone.value) _stepDone.value = done;
  }

  @override
  Widget build(BuildContext context) {
    // AMIAL-SPLASH: نفس أصفر الشعار في سبلاش النظام (#FECA1E) — كانت الخلفية
    // بيضاء افتراضياً فتظهر «شاشة بيضاء» بين سبلاش النظام وشاشة الدخول.
    return Scaffold(
      backgroundColor: const Color(0xFFFECA1E),
      body: SafeArea(
        child: Column(
          children: [
            const Spacer(flex: 3),
            // ===== الشعار + الاسم + الشعار النصّي =====
            ScaleTransition(
              scale: _logoScale,
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Image.asset(Images.logo, height: 150),
                  const SizedBox(height: 18),
                  const Text(
                    'أميال باي',
                    style: TextStyle(
                      color: AmyalColors.primary,
                      fontSize: 30,
                      fontWeight: FontWeight.bold,
                      letterSpacing: 0.5,
                    ),
                  ),
                  const SizedBox(height: 6),
                  Text(
                    'دفع سريع وآمن',
                    style: TextStyle(
                      color: AmyalColors.primary.withValues(alpha: 0.75),
                      fontSize: 14,
                      fontWeight: FontWeight.w500,
                    ),
                  ),
                ],
              ),
            ),
            const Spacer(flex: 2),
            // AMIAL-SPLASH-002: كان هنا شريط تقدّم مع نصّ «جارٍ تحميل...»
            // يظهر عدّة ثوانٍ — وهو يُشعر المستخدم بالبطء بدل أن يُخفيه،
            // ولا يفعله أي تطبيق مصرفي. مؤشّر دائري صغير خافت يكفي.
            SizedBox(
              width: 22,
              height: 22,
              child: CircularProgressIndicator(
                strokeWidth: 2.2,
                valueColor: AlwaysStoppedAnimation<Color>(
                    AmyalColors.primary.withValues(alpha: 0.55)),
              ),
            ),
            const SizedBox(height: 46),
          ],
        ),
      ),
    );
  }
}
