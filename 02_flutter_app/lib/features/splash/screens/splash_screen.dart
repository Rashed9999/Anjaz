import 'dart:async';
import 'package:flutter/foundation.dart';
import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:amial_pay/features/splash/controllers/splash_controller.dart';
import 'package:amial_pay/data/api/api_checker.dart';
import 'package:amial_pay/helper/route_helper.dart';
import 'package:amial_pay/features/splash/widgets/brand_splash_animation.dart';
import 'package:amial_pay/util/app_constants.dart';
import 'package:amial_pay/theme/amial_colors.dart';
import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:amial_pay/helper/custom_snackbar_helper.dart';

class SplashScreen extends StatefulWidget {
  const SplashScreen({super.key});

  @override
  State<SplashScreen> createState() => _SplashScreenState();
}

class _SplashScreenState extends State<SplashScreen>
    with WidgetsBindingObserver {
  late StreamSubscription<List<ConnectivityResult>> subscription;

  // AMIAL-UI-001 كانت «خطوات إقلاع مرئية — تُظهر أن التطبيق يعمل أثناء
  // التهيئة». وحلّت محلَّها حركةُ الشعار (AMIAL-SPLASH-C-001): ١٫٨ ثانية
  // من الحركة تؤدّي الغرضَ نفسَه وأحسن.
  //
  // **وأُزيل عدّادُ الخطوات معها، ولم يُترك يُكتب ولا يُقرأ.** فحالةٌ تُحدَّث
  // ولا تُعرض تُوهم القارئَ أنّ لها أثراً، ويُبنى عليها لاحقاً ما لا يظهر.

  // AMIAL-SPLASH-C-001 — حركةُ الشعار (النمط C) بدل التكبير البسيط.
  //
  // ══════════════════════════════════════════════════════════════════
  // **وتوتّرٌ حقيقيٌّ حُسم هنا، لا أُخفيه:**
  //
  // مكتوبٌ في هذا الملفّ منذ AMIAL-STARTUP-FLOW-001: «لا تأخير صناعي —
  // كانت ثانيةٌ كاملة فوق سبلاش النظام = شاشتا شعار». والمواصفةُ الجديدة
  // تطلب حركةً مدّتها ١٫٨ ثانية.
  //
  // فالحلُّ ليس إلغاء أحدهما: **الانتقالُ يقع حين يجتمع الأمران** —
  // انتهاءُ الحركة **و** جهوزيّةُ البيانات. فإن سبقت البياناتُ الحركةَ
  // (وهو الغالب على شبكةٍ جيّدة) رأى المستعملُ الحركةَ كاملةً؛ وإن
  // تأخّرت البياناتُ غطّت الحركةُ الانتظارَ بدل شاشةٍ جامدة.
  //
  // **والسقفُ ١٫٨ ثانية لا أكثر**: الحركةُ لا تُعاد ولا تُمدَّد، فلا
  // يتحوّل «الانتظارُ المغطّى» إلى تأخيرٍ صناعيّ.
  bool _dataReady = false;
  bool _animationDone = false;

  @override
  void initState() {
    super.initState();

    bool isFirstTime = true;

    // فحص الاتصال/الـ VPN يعتمد على connectivity_plus، وهو موجّه للموبايل
    // (الهدف الفعلي). على غيره قد تغيب البنية (NetworkManager/D-Bus) فيرمي خطأً.
    // نقصره على الموبايل؛ والتوجيه يتمّ بكل الأحوال عبر _route() أدناه.
    // صلابة إضافية: نتعامل مع أخطاء الـ stream بأمان دون إسقاط الشاشة.
    if (GetPlatform.isMobile) {
      subscription = Connectivity().onConnectivityChanged.listen(
        (List<ConnectivityResult> result) async {
          if (await ApiChecker.isVpnActive()) {
            showCustomSnackBarHelper(
              'you are using vpn',
              isVpn: true,
              duration: const Duration(minutes: 10),
            );
          }
          if (isFirstTime) {
            isFirstTime = false;
            await _route();
          }
        },
        onError: (Object e) {
          if (kDebugMode) debugPrint('[Splash] connectivity stream error: $e');
        },
      );
    } else {
      subscription = const Stream<List<ConnectivityResult>>.empty().listen(
        (_) {},
      );
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
      // الإعدادات البعيدة
      await Get.find<SplashController>().getConfigData().timeout(
        const Duration(seconds: 5),
      );
    } catch (_) {
      // الخادم غير متاح أو بطيء — نُكمل بدل التعليق.
    }

    try {
      // البيانات المحلية (اللغة/الثيم/الجلسة)
      await Get.find<SplashController>().initSharedData();
    } catch (_) {
      /* تجاهل — بيانات محلية */
    }

    if (!mounted) return;

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
    } catch (_) {
      /* غير حرج */
    }

    _dataReady = true;
    _goIfReady();
  }

  /// **ولا يُنتقل إلّا مرّةً واحدة.**
  ///
  /// فالبياناتُ والحركةُ تنتهيان في أيّ ترتيب، وكلاهما ينادي هذه الدالّة.
  /// وبلا الحارس يُنادى `Get.offNamed` مرّتين فتُفتح شاشةُ الدخول فوق
  /// نفسها. (زرٌّ يعمل ويفعل الشيء مرّتين.)
  bool _navigated = false;

  void _goIfReady() {
    if (!mounted || _navigated) return;
    if (!_dataReady || !_animationDone) return;

    _navigated = true;
    Get.offNamed(RouteHelper.getUnifiedLoginRoute());
  }

  @override
  Widget build(BuildContext context) {
    // AMIAL-SPLASH: نفس أصفر الشعار في سبلاش النظام (#FECA1E) — كانت الخلفية
    // بيضاء افتراضياً فتظهر «شاشة بيضاء» بين سبلاش النظام وشاشة الدخول.
    return Scaffold(
      // الخلفيّةُ من توكِنز العلامة لا رقماً مكتوباً — فلونٌ يُنسخ
      // يعيش وحده ويفترق عن مصدره. (AMIAL-TOKENS-001)
      backgroundColor: AmialColors.yellow,
      body: SafeArea(
        // AMIAL-SPLASH-006 — العمود يجب أن يملأ العرض صراحةً.
        //
        // **العطل الذي بقي ثلاث جولات:** Scaffold يُخطّط جسمه بقيود
        // **فضفاضة** ثم يضعه عند x=0. والعمود ينكمش إلى عرض أعرض أبنائه.
        // فلمّا صار أعرضهم هو الشعار المحدود بـ 62% من الشاشة، انكمش العمود
        // كلّه إلى 62% وانزوى إلى الحافّة اليسرى — ومعه النصّان والمؤشّر.
        //
        // ولهذا كان «الانحراف» يصيب كل شيء بالقدر نفسه: قياس الفيديو أعطى
        // إزاحةً واحدة (-142 بكسل) لكل عنصر على حدة، ثابتةً طوال عمر الشاشة.
        // وهو ما ميّزه عن حركة انتقال، ودلّ على أن الجاني هو الحاوي لا الأبناء.
        //
        // والمحاولات السابقة أخطأت الطبقة: أُصلح مقاس الشعار مرّة، وشاشة
        // إقلاع النظام مرّة، وكلاهما سليم — الخلل في عرض الحاوي وحده.
        child: SizedBox.expand(
          child: Column(
            children: [
              const Spacer(flex: 3),

              // AMIAL-SPLASH-C-001 — المراحل الستّ من الصور الأربع
              // المفصولة من الشعار الأصليّ.
              BrandSplashAnimation(
                onCompleted: () {
                  if (!mounted) return;
                  setState(() => _animationDone = true);
                  _goIfReady();
                },
              ),

              const Spacer(flex: 2),

              // **والمؤشّرُ لا يظهر إلّا إن تأخّرت البيانات بعد الحركة.**
              //
              // فظهورُه أثناء الحركة يُفسد لحظةَ العلامة ويُشعر بالبطء —
              // وهو الدرسُ المكتوب في AMIAL-SPLASH-002. أمّا بعدها فغيابُه
              // يجعل الشاشةَ تبدو معلّقة، والمستعملُ لا يعرف أحيّةٌ هي.
              SizedBox(
                height: 22,
                child: (_animationDone && !_dataReady)
                    ? SizedBox(
                        width: 22,
                        height: 22,
                        child: CircularProgressIndicator(
                          strokeWidth: 2.2,
                          valueColor: AlwaysStoppedAnimation<Color>(
                            AmialColors.primary.withValues(alpha: 0.55),
                          ),
                        ),
                      )
                    : null,
              ),
              const SizedBox(height: 46),
            ],
          ),
        ),
      ),
    );
  }
}
