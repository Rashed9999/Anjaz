import 'dart:async';

import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amial_pay/features/auth/controllers/auth_controller.dart';
import 'package:amial_pay/helper/route_helper.dart';
import 'package:amial_pay/theme/amial_colors.dart';

/// AMIAL-SESSION-GUARD-001 — إغلاق الجلسة عند مغادرة التطبيق.
///
/// ضابط أمني قياسي في تطبيقات المحافظ: إن أُطفئت الشاشة، أو ضُغط زرّ البيت،
/// أو نُقل التطبيق إلى نافذة/قائمة التطبيقات الأخيرة — تُغلق الجلسة ويعود
/// المستخدم إلى تسجيل الدخول مع إشعار صريح بالسبب.
///
/// **لماذا يهمّ:** الهاتف يُترك مفتوحاً على طاولة أو يُعار لأحدهم. جلسة
/// محفظة مفتوحة بلا حدّ زمني تعني أن أي شخص يلتقط الجهاز يجد حساباً مفتوحاً
/// برصيده وسجلّه وإمكانية التحويل منه.
///
/// **مهلة السماح (`graceSeconds`) — قرار هندسي لا تفصيل:**
/// الإغلاق الفوري عند أي مغادرة يكسر وظائف حقيقية في التطبيق، لأن هذه
/// العمليات كلّها تُخرج التطبيق إلى الخلفية:
///   • فتح الكاميرا لمسح رمز QR
///   • ورقة المشاركة عند مشاركة إيصال
///   • منتقي جهات الاتصال
///   • حوار أذونات النظام
/// فلو أغلقنا الجلسة لحظة المغادرة، لخرج المستخدم من حسابه كلّما حاول مسح
/// رمز أو مشاركة إيصال. لذلك نسمح بمهلة قصيرة تغطّي هذه العودات السريعة،
/// وما تجاوزها يُعدّ مغادرة حقيقية.
///
/// المهلة ثابت واحد أدناه — خفضها إلى صفر يُنتج السلوك الفوري تماماً.
class SessionGuard with WidgetsBindingObserver {
  SessionGuard._();
  static final SessionGuard instance = SessionGuard._();

  /// مهلة السماح قبل اعتبار المغادرة حقيقية.
  static const int graceSeconds = 20;

  /// مفتاح تعطيل مؤقّت — تستعمله التدفّقات التي تفتح نشاطاً خارجياً معروفاً
  /// ويُتوقّع أن تطول (مسح QR مثلاً). تُعاد التهيئة تلقائياً عند العودة.
  bool _suspended = false;

  DateTime? _leftAt;
  bool _attached = false;

  /// يبدأ المراقبة — يُستدعى مرّة واحدة عند إقلاع التطبيق.
  void attach() {
    if (_attached) return;
    WidgetsBinding.instance.addObserver(this);
    _attached = true;
  }

  /// يعطّل الحارس مؤقّتاً حول عملية تفتح تطبيقاً خارجياً.
  ///
  /// ```dart
  /// await SessionGuard.instance.shield(() => Get.to(() => const CameraScreen()));
  /// ```
  Future<T> shield<T>(Future<T> Function() action) async {
    _suspended = true;
    try {
      return await action();
    } finally {
      _leftAt = null;
      _suspended = false;
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    switch (state) {
      case AppLifecycleState.paused:
      case AppLifecycleState.hidden:
        // غادر التطبيق الواجهة: إطفاء شاشة، زرّ بيت، أو نافذة التطبيقات.
        _leftAt ??= DateTime.now();
        break;

      case AppLifecycleState.resumed:
        _evaluate();
        break;

      case AppLifecycleState.inactive:
      case AppLifecycleState.detached:
        // `inactive` تحدث لحظياً عند سحب شريط الإشعارات أو ظهور حوار نظام —
        // ليست مغادرة، فلا نبدأ العدّ عندها.
        break;
    }
  }

  Future<void> _evaluate() async {
    final left = _leftAt;
    _leftAt = null;

    if (left == null || _suspended) return;
    if (!_isLoggedIn()) return;

    final away = DateTime.now().difference(left).inSeconds;
    if (away < graceSeconds) return;

    await _closeSession();
  }

  bool _isLoggedIn() {
    try {
      return Get.find<AuthController>().isLoggedIn();
    } catch (_) {
      return false;
    }
  }

  /// يُغلق الجلسة ويعود لتسجيل الدخول مع إشعار السبب.
  Future<void> _closeSession() async {
    try {
      // مسح بيانات البصمة المحفوظة ليس مطلوباً هنا: الجلسة أُغلقت لغياب
      // المستخدم لا لخروجه، وهو يريد الدخول السريع عند عودته.
      final auth = Get.find<AuthController>();
      auth.updateToken(isLogOut: true);
      auth.logout();
    } catch (_) {/* الأولوية للخروج من الشاشة حتى لو فشل إبطال الرمز */}

    Get.offAllNamed(RouteHelper.getUnifiedLoginRoute());

    // الإشعار بعد التنقّل كي يظهر فوق شاشة الدخول لا الشاشة المُغادَرة.
    WidgetsBinding.instance.addPostFrameCallback((_) {
      final ctx = Get.context;
      if (ctx == null) return;
      ScaffoldMessenger.of(ctx).showSnackBar(
        const SnackBar(
          content: Text(
            'تم إغلاق الجلسة تلقائياً لحمايتك — سجّل الدخول للمتابعة',
            textAlign: TextAlign.center,
          ),
          backgroundColor: AmialColors.primary,
          duration: Duration(seconds: 4),
          behavior: SnackBarBehavior.floating,
        ),
      );
    });
  }

  /// يُستدعى من `UnifiedAuthController` بعد دخول ناجح لتصفير الحالة.
  void reset() {
    _leftAt = null;
    _suspended = false;
  }
}
