import 'package:flutter/foundation.dart';
import 'package:flutter/services.dart';

/// AMIAL-SEC-CAPTURE-001 — منع تصوير/تسجيل الشاشة على الشاشات الحسّاسة.
///
/// يقابل `FLAG_SECURE` على أندرويد (MainActivity.kt). على iOS لا يوجد مكافئ
/// مباشر، فالنداء يعود بلا أثر ولا يرمي — الشاشات تبقى تعمل طبيعياً.
///
/// الاستخدام في `State`:
/// ```dart
/// @override
/// void initState() { super.initState(); SecureScreen.enable(); }
/// @override
/// void dispose() { SecureScreen.disable(); super.dispose(); }
/// ```
///
/// **لا يُفعَّل على مستوى التطبيق كلّه** — يجب أن يبقى المستخدم قادراً على
/// تصوير إيصالاته ومشاركتها.
class SecureScreen {
  SecureScreen._();

  static const MethodChannel _ch = MethodChannel('amyal_pay/secure_screen');

  static Future<void> enable() => _invoke('enable');
  static Future<void> disable() => _invoke('disable');

  static Future<void> _invoke(String method) async {
    if (defaultTargetPlatform != TargetPlatform.android) return;
    try {
      await _ch.invokeMethod<bool>(method);
    } catch (_) {
      // المنصّة لا تدعمه — ليس خطأً يستحقّ إيقاف الشاشة.
    }
  }
}
