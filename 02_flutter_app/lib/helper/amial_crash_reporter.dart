import 'dart:async';

import 'package:firebase_crashlytics/firebase_crashlytics.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// AMIAL-CRASH-001 — تقارير الأعطال: كيف نعرف أن شيئاً انكسر.
///
/// **المشكلة التي يحلّها هذا الملفّ:**
/// لم يكن في التطبيق أيّ نظام تبليغ. فالسبيل الوحيد لمعرفة عطل هو أن يصطدم
/// به مالك المشروع نفسه ويصوّره. وهذا يعني أن كل عطل لا يصادفه هو شخصياً
/// يبقى حيّاً إلى الأبد، وأن سرعة الإصلاح مقيّدة بسرعة اصطدامه بالأعطال.
///
/// الآن يصل الأثر كاملاً مع رقم السطر، ومعه: كم مستخدماً أصابه، وعلى أي
/// إصدار، وفي أي شاشة كان.
///
/// **وهنا الحسّاس: التطبيق محفظة مالية.**
/// التقرير يُرفع إلى خوادم Google. ورسائل الاستثناءات ليست بريئة — رسالة
/// خطأ من الخادم قد تردّ رقم الهاتف، ومسار الطلب قد يحمل رقم الحساب،
/// وترويسة المصادقة تحمل الرمز كاملاً. فكل نصّ يُرفع يمرّ على [scrub] أوّلاً.
///
/// ولهذا لا يُستعمل رقم الهاتف معرّفاً للمستخدم بل معرّفه الداخلي: يكفي
/// لربط الأعطال بحساب واحد عند التحقيق، ولا يكشف صاحبه لمن يقرأ اللوحة.
class AmialCrashReporter {
  AmialCrashReporter._();

  static bool _ready = false;

  /// هل التقارير فعّالة الآن — يُقرأ في الاختبارات وفي شاشة التشخيص.
  static bool get isActive => _ready;

  /// يُستدعى من main بعد تهيئة Firebase وقبل runApp.
  ///
  /// يُمرَّر [enabled] = false في وضع التطوير: أعطال التطوير — وهي كثيرة
  /// ومقصودة — تُغرق اللوحة فتخفي أعطال المستخدمين الحقيقيين بينها.
  static Future<void> init({bool? enabled}) async {
    final on = enabled ?? !kDebugMode;
    try {
      await FirebaseCrashlytics.instance.setCrashlyticsCollectionEnabled(on);
      _ready = on;
    } catch (_) {
      // Firebase لم يُهيَّأ (منصّة غير مسجّلة، أو جهاز بلا خدمات Google).
      // التطبيق يعمل كاملاً بلا تقارير — لا يُسقَط لأجل التبليغ.
      _ready = false;
      return;
    }

    // أخطاء بناء الودجات: الشاشة الحمراء، وتجاوز التخطيط، والتأكيدات.
    final previous = FlutterError.onError;
    FlutterError.onError = (details) {
      previous?.call(details);
      _send(details.exception, details.stack, reason: details.library, fatal: true);
    };

    // كل ما يقع خارج شجرة الودجات: المستقبلات غير المُلتقَطة، وأخطاء
    // القنوات الأصلية. بدون هذا تختفي أعطال الشبكة والملفّات كلّها.
    PlatformDispatcher.instance.onError = (error, stack) {
      _send(error, stack, fatal: true);
      return true;
    };

    await _restoreIdentity();
  }

  /// AMIAL-CRASH-005 — يُعيد ربط الحساب عند كل إقلاع.
  ///
  /// **العطل الذي يمنعه:** كان الربط يقع في مسار تسجيل الدخول وحده. ومن
  /// يفتح التطبيق ومعه جلسة محفوظة لا يمرّ به أبداً — وهي حال كل مستخدم
  /// عائد، أي الحالة الغالبة. فكانت أعطالهم كلّها تصل بلا هوية ولا دور ولا
  /// منطقة، ولا شيء في اللوحة يشي بأن حقلاً ناقص.
  ///
  /// فالهوية تُحفظ عند الدخول وتُستعاد هنا، ولا تُنتظر شبكة ولا ملفّ شخصي.
  static Future<void> _restoreIdentity() async {
    try {
      final prefs = await SharedPreferences.getInstance();
      final account = prefs.getString(_kAccount);
      if (account == null) return;
      await identify(
        account: account,
        role: prefs.getString(_kRole),
        zone: prefs.getString(_kZone),
      );
    } catch (_) {}
  }

  static const _kAccount = 'amial_crash_account';
  static const _kRole = 'amial_crash_role';
  static const _kZone = 'amial_crash_zone';

  /// يربط الأعطال القادمة بحساب — بمعرّفه الداخلي لا برقم هاتفه.
  ///
  /// [role] و[zone] يجيبان أهمّ سؤالين عند ورود عطل: أيّ صنف حساب أصابه،
  /// وهل هو محصور بمنطقة (وهو ما يميّز عطل الإعداد من عطل الشيفرة).
  ///
  /// [account] هو معرّف الصفّ في قاعدة البيانات — لا رقم الحساب المعروض ولا
  /// رقم الهاتف. وهو أقلّ ما يكفي: تبحث به لوحة الإدارة عند التحقيق، ولا
  /// يقول لمن يقرأ لوحة الأعطال من هو صاحبه. وبدون أيّ معرّف يصير السؤال
  /// «كم مستخدماً أصابه؟» بلا جواب، فتضيع فائدة التقرير أصلاً.
  static Future<void> identify({String? account, String? role, String? zone}) async {
    // الحفظ قبل فحص [_ready] عمداً: التعطيل حالة راهنة قد تتبدّل، أمّا
    // الهوية فحقيقة عن هذه الجلسة. حفظُها دائماً يجعل أوّل إقلاع تُفعَّل فيه
    // التقارير يجدها جاهزة، ويجعل هذا المسار قابلاً للاختبار بلا Firebase.
    try {
      final prefs = await SharedPreferences.getInstance();
      if (account != null && account.isNotEmpty) {
        await prefs.setString(_kAccount, account);
      }
      if (role != null && role.isNotEmpty) await prefs.setString(_kRole, role);
      if (zone != null && zone.isNotEmpty) await prefs.setString(_kZone, zone);
    } catch (_) {}

    if (!_ready) return;
    try {
      final c = FirebaseCrashlytics.instance;
      await c.setUserIdentifier(
        account == null || account.isEmpty ? 'guest' : account,
      );
      if (role != null && role.isNotEmpty) await c.setCustomKey('role', role);
      if (zone != null && zone.isNotEmpty) await c.setCustomKey('zone', zone);
    } catch (_) {
      // التبليغ لا يُسقط التطبيق أبداً.
    }
  }

  /// الهوية المحفوظة — يقرؤها الاختبار، وهي ما يُستعاد عند الإقلاع.
  @visibleForTesting
  static Future<Map<String, String?>> storedIdentity() async {
    final prefs = await SharedPreferences.getInstance();
    return {
      'account': prefs.getString(_kAccount),
      'role': prefs.getString(_kRole),
      'zone': prefs.getString(_kZone),
    };
  }

  /// يُنادى عند الخروج: أعطال من يأتي بعده لا تُنسب إلى حسابه.
  ///
  /// الجهاز الواحد قد يتناوب عليه صرّافان أو موظّفا نقطة بيع. وإبقاء الهوية
  /// السابقة يجعل التحقيق يقود إلى الشخص الخطأ — وهو أسوأ من لا هوية.
  static Future<void> forgetIdentity() async {
    try {
      if (_ready) {
        await FirebaseCrashlytics.instance.setUserIdentifier('guest');
      }
      final prefs = await SharedPreferences.getInstance();
      await prefs.remove(_kAccount);
      await prefs.remove(_kRole);
      await prefs.remove(_kZone);
    } catch (_) {}
  }

  /// عطل غير قاتل: التُقط وعُولج، لكنّه يجب أن يُرى.
  ///
  /// هذا هو الاستعمال الأهمّ عملياً. الشيفرة مليئة بـ `catch (_) {}` تُبقي
  /// التطبيق واقفاً — وهو صحيح للمستخدم — لكنها تدفن السبب. بتسجيلها هنا
  /// يبقى التطبيق واقفاً ويصل السبب معاً.
  static Future<void> record(
    Object error,
    StackTrace? stack, {
    String? reason,
  }) async =>
      _send(error, stack, reason: reason, fatal: false);

  /// فُتات يُرفَق بالعطل التالي — أهمّه اسم الشاشة.
  ///
  /// أثر الاستدعاء وحده يقول أين وقع العطل ولا يقول كيف وصل المستخدم إليه.
  static void breadcrumb(String message) {
    if (!_ready) return;
    try {
      FirebaseCrashlytics.instance.log(scrub(message));
    } catch (_) {}
  }

  /// تقرير متعمَّد للتأكّد من وصول السلسلة كاملةً إلى لوحة Firebase.
  ///
  /// بدونه يبقى «التبليغ يعمل» ادّعاءً: الحزمة مركّبة والشيفرة تُنادى، وقد
  /// لا يصل شيء لسبب خارج الشيفرة كلّها — مشروع غير مطابق، أو حزمة غير
  /// مسجّلة، أو خرائط رموز لم تُرفع. الفحص الوحيد الصادق أن يُرسَل ويُرى.
  static Future<bool> sendTestReport() async {
    if (!_ready) return false;
    await record(
      Exception('تقرير اختبار متعمَّد — أُرسل من شاشة التشخيص'),
      StackTrace.current,
      reason: 'فحص سلسلة التبليغ',
    );
    return true;
  }

  /// انهيار متعمَّد — يفحص المسار القاتل وهو غير المسار أعلاه.
  ///
  /// الأعطال القاتلة يلتقطها مُعالج أصلي ويرفعها عند التشغيل التالي، لا
  /// المُرسِل الذي يرفع غير القاتلة. فنجاح أحدهما لا يثبت الآخر.
  static void forceCrash() => FirebaseCrashlytics.instance.crash();

  static Future<void> _send(
    Object error,
    StackTrace? stack, {
    String? reason,
    required bool fatal,
  }) async {
    if (!_ready) return;
    try {
      await FirebaseCrashlytics.instance.recordError(
        describe(error),
        stack,
        reason: reason == null ? null : scrub(reason),
        fatal: fatal,
      );
    } catch (_) {}
  }

  /// نصّ العطل كما يظهر في اللوحة: منقّى، ومسبوقاً بنوعه مرّة واحدة.
  ///
  /// النوع يبقى ظاهراً لأنه ما تُصنَّف عليه القضايا. لكن أكثر الاستثناءات
  /// تذكر اسمها في نصّها أصلاً، فإلحاقه دائماً يُنتج
  /// `_Exception: Exception: …` — تكرارٌ يلوّث كل تقرير.
  ///
  /// وتُنزع الشرطة السفلية من اسم النوع قبل المقارنة: `Exception('…')` في
  /// Dart نوعه `_Exception` ونصّه يبدأ بـ `Exception:`، فالمقارنة الحرفية
  /// لا تتطابق أبداً ويبقى التكرار.
  @visibleForTesting
  static String describe(Object error) {
    final text = scrub(error.toString());
    final type = error.runtimeType.toString().replaceFirst(RegExp(r'^_'), '');
    return text.startsWith(type) ? text : '$type: $text';
  }

  // ── التنقية ───────────────────────────────────────────────────────────

  /// أرقام الهواتف اليمنية: 7 ثم 0-8 ثم سبع خانات، مع بادئة الدولة أو بدونها.
  static final _phone = RegExp(r'(\+?967[\s\-]?)?7[0-8][\s\-]?\d{7}');

  /// رموز المصادقة — الترويسة كاملة، وأي سلسلة JWT.
  static final _bearer = RegExp(r'[Bb]earer\s+[\w\-\.]+');
  static final _jwt = RegExp(r'\beyJ[\w\-\.]{20,}');

  static final _email = RegExp(r'[\w\.\-\+]+@[\w\-]+\.[\w\.\-]+');

  /// ما بعد كلمة «رمز/pin/otp/code» مباشرةً — الرمز السري ورمز التحقّق.
  ///
  /// بلا `\b` عمداً: حدّ الكلمة في Dart معرَّف على [A-Za-z0-9_] وحدها، فلا
  /// يقع قطّ قبل حرف عربي. وكتابته هنا تجعل نصف القاعدة ميّتاً صامتاً —
  /// «كلمة المرور 9137» كانت تُرفع كما هي. (كشفه اختبار، لا قراءة.)
  static final _secret = RegExp(
    r'(pin|otp|code|password|passcode|رمز|كلمة\s*المرور)\W{0,3}\d+',
    caseSensitive: false,
  );

  /// أرقام الحسابات وأرقام الإيصالات ورموز التحقّق: ستّ خانات فأكثر.
  ///
  /// الحدّ عند ستّ خانات مقصود: أرقام الأسطر ورموز HTTP ومعرّفات الصفوف
  /// الصغيرة تبقى ظاهرة — وهي التي تُشخَّص بها الأعطال — بينما لا ينجو رقم
  /// حساب ولا رقم إيصال. وضياع مبلغ كبير في التنقية ثمن مقبول.
  static final _longDigits = RegExp(r'\d{6,}');

  /// يُزيل ما لا يجوز أن يغادر الجهاز، ويُبقي ما يُشخَّص به العطل.
  ///
  /// الترتيب مقصود: الأطول أوّلاً. لو سبقت قاعدةُ الأرقام الطويلة قاعدةَ
  /// الهاتف لالتهمت رقمَه فضاع تمييزه، ولو سبقت قاعدةُ الهاتف قاعدةَ JWT
  /// لمزّقت الرمز نصفين وبقي نصفه ظاهراً.
  @visibleForTesting
  static String scrub(String input) => input
      .replaceAll(_jwt, '[رمز]')
      .replaceAll(_bearer, 'Bearer [رمز]')
      .replaceAll(_email, '[بريد]')
      .replaceAllMapped(_secret, (m) => '${m.group(1)} [محجوب]')
      .replaceAll(_phone, '[هاتف]')
      .replaceAll(_longDigits, '[رقم]');
}

/// يسجّل اسم كل شاشة يدخلها المستخدم، فيصل مع العطل مسارُه إليه.
///
/// «انهار عند الدفع» و«انهار عند الدفع قادماً من مسح الرمز بعد فشل شبكة»
/// عطلان مختلفان في الجهد اللازم لإعادة إنتاجهما.
class AmialCrashRouteObserver extends NavigatorObserver {
  @override
  void didPush(Route<dynamic> route, Route<dynamic>? previousRoute) {
    super.didPush(route, previousRoute);
    final name = route.settings.name;
    if (name != null) AmialCrashReporter.breadcrumb('دخل: $name');
  }

  @override
  void didPop(Route<dynamic> route, Route<dynamic>? previousRoute) {
    super.didPop(route, previousRoute);
    final name = previousRoute?.settings.name;
    if (name != null) AmialCrashReporter.breadcrumb('رجع إلى: $name');
  }
}
