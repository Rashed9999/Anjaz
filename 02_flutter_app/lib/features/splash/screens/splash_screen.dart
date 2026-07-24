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
  static const List<String> _steps = <String>[
    'التحقق من الاتصال',
    'التحقق من النسخة',
    'تحميل البيانات',
    'جاهز',
  ];
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
            // ===== حالة الإقلاع =====
            _StartupStatus(stepDone: _stepDone, steps: _steps),
            const SizedBox(height: 40),
          ],
        ),
      ),
    );
  }
}

/// AMYAL-UI-001: مؤشّر إقلاع احترافي — شريط تقدّم + رسالة الخطوة الحالية.
class _StartupStatus extends StatelessWidget {
  final ValueNotifier<int> stepDone;
  final List<String> steps;
  const _StartupStatus({required this.stepDone, required this.steps});

  @override
  Widget build(BuildContext context) {
    return ValueListenableBuilder<int>(
      valueListenable: stepDone,
      builder: (context, done, _) {
        final total = steps.length;
        final clamped = done.clamp(0, total);
        final progress = clamped / total;
        final isReady = clamped >= total;
        // الرسالة الحالية: آخر خطوة اكتملت (أو أوّلها في البداية).
        final idx = (clamped == 0 ? 0 : clamped - 1).clamp(0, total - 1);
        final label = isReady ? 'جاهز ✓' : 'جارٍ ${steps[idx]}...';

        return Column(
          children: [
            SizedBox(
              width: 200,
              child: ClipRRect(
                borderRadius: BorderRadius.circular(6),
                child: TweenAnimationBuilder<double>(
                  tween: Tween<double>(begin: 0, end: progress),
                  duration: const Duration(milliseconds: 400),
                  builder: (context, value, __) => LinearProgressIndicator(
                    value: value == 0 ? null : value,
                    minHeight: 6,
                    backgroundColor: AmyalColors.primary.withValues(alpha: 0.15),
                    valueColor:
                        const AlwaysStoppedAnimation<Color>(AmyalColors.primary),
                  ),
                ),
              ),
            ),
            const SizedBox(height: 14),
            AnimatedSwitcher(
              duration: const Duration(milliseconds: 300),
              child: Text(
                label,
                key: ValueKey<String>(label),
                style: TextStyle(
                  color: AmyalColors.primary.withValues(alpha: 0.85),
                  fontSize: 13,
                  fontWeight: FontWeight.w600,
                ),
              ),
            ),
          ],
        );
      },
    );
  }
}
