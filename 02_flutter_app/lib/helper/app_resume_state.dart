import 'package:amial_pay/helper/route_helper.dart';
import 'package:flutter/widgets.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// AMIAL-RESUME-001 — استئناف آمن بعد أن يقتل أندرويد عملية Flutter في الخلفية.
///
/// أندرويد يحتفظ عادةً بمهمة التطبيق، لكنه قد يعيد إنشاء العملية عند نقص
/// الذاكرة. من دون هذه الطبقة يبدأ Flutter من `/splash` ثم ينسى مكان المستخدم.
/// نحفظ *وجهة آمنة فقط* ووقت انتقال التطبيق للخلفية، ثم نستأنفها خلال المهلة
/// القصيرة نفسها التي تسمح بها سياسة الجلسة.
///
/// لا نحفظ أي شاشة PIN أو تحويل أو رمز تحقق أو بيانات تُكتب في نموذج.
class AppResumeState extends NavigatorObserver with WidgetsBindingObserver {
  AppResumeState({required SharedPreferences sharedPreferences})
      : _preferences = sharedPreferences;

  static const Duration resumeWindow = Duration(seconds: 20);

  static const String _routeKey = 'amial_safe_resume_route';
  static const String _backgroundedAtKey = 'amial_backgrounded_at';

  final SharedPreferences _preferences;
  bool _attached = false;
  bool _backgroundedInCurrentProcess = false;

  void attach() {
    if (_attached) return;
    WidgetsBinding.instance.addObserver(this);
    _attached = true;
  }

  /// هل توجد عودة قصيرة وآمنة يمكن أن تُستأنف؟
  bool get hasEligibleDestination {
    final leftAt = _preferences.getInt(_backgroundedAtKey);
    final route = _canonicalSafeRoute(_preferences.getString(_routeKey));
    if (leftAt == null || route == null) return false;

    final elapsed = DateTime.now().millisecondsSinceEpoch - leftAt;
    if (elapsed < 0 || elapsed > resumeWindow.inMilliseconds) {
      clear();
      return false;
    }
    return true;
  }

  /// تستهلك وجهة الاستئناف مرة واحدة عند إنشاء عملية جديدة.
  String? takeEligibleDestination() {
    if (!hasEligibleDestination) return null;
    final route = _canonicalSafeRoute(_preferences.getString(_routeKey));
    _preferences.remove(_backgroundedAtKey);
    return route;
  }

  /// يستدعيها شريط التنقل لأن تبديل تبويبه لا يغيّر Route في Navigator.
  void rememberNavbar(String selectedPage) {
    const allowed = {'home', 'history', 'notification', 'profile'};
    final page = allowed.contains(selectedPage) ? selectedPage : 'home';
    _storeSafeRoute(RouteHelper.getNavBarRoute(selectedPage: page));
  }

  void clear() {
    _preferences.remove(_routeKey);
    _preferences.remove(_backgroundedAtKey);
  }

  @override
  void didPush(Route<dynamic> route, Route<dynamic>? previousRoute) {
    super.didPush(route, previousRoute);
    _storeSafeRoute(route.settings.name);
  }

  @override
  void didReplace({Route<dynamic>? newRoute, Route<dynamic>? oldRoute}) {
    super.didReplace(newRoute: newRoute, oldRoute: oldRoute);
    _storeSafeRoute(newRoute?.settings.name);
  }

  @override
  void didPop(Route<dynamic> route, Route<dynamic>? previousRoute) {
    super.didPop(route, previousRoute);
    _storeSafeRoute(previousRoute?.settings.name);
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    switch (state) {
      case AppLifecycleState.hidden:
      case AppLifecycleState.paused:
        _backgroundedInCurrentProcess = true;
        _preferences.setInt(
          _backgroundedAtKey,
          DateTime.now().millisecondsSinceEpoch,
        );
        break;
      case AppLifecycleState.resumed:
        // لا نمسح وقت عملية سابقة عند الإقلاع الجديد. نزيله فقط عند عودة
        // العملية ذاتها، إذ لا نحتاج عندها إلى استعادة Navigator من التخزين.
        if (_backgroundedInCurrentProcess) {
          _preferences.remove(_backgroundedAtKey);
          _backgroundedInCurrentProcess = false;
        }
        break;
      case AppLifecycleState.inactive:
      case AppLifecycleState.detached:
        break;
    }
  }

  void _storeSafeRoute(String? candidate) {
    final route = _canonicalSafeRoute(candidate);
    if (route != null) {
      _preferences.setString(_routeKey, route);
    }
  }

  String? _canonicalSafeRoute(String? candidate) {
    if (candidate == null || candidate.isEmpty) return null;

    final uri = Uri.tryParse(candidate);
    if (uri == null) return null;

    switch (uri.path) {
      case RouteHelper.navbar:
        final selected = uri.queryParameters['selectedPage'];
        const allowed = {'home', 'history', 'notification', 'profile'};
        return RouteHelper.getNavBarRoute(
          selectedPage: allowed.contains(selected) ? selected : 'home',
        );
      case RouteHelper.notification:
      case RouteHelper.profile:
      case RouteHelper.editProfileScreen:
      case RouteHelper.faq:
      case RouteHelper.support:
        // هذه شاشات عرض/إعدادات بلا نموذج مالي أو سر.
        return uri.path;
      default:
        return null;
    }
  }
}
