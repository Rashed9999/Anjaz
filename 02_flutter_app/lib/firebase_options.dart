import 'package:firebase_core/firebase_core.dart';
import 'package:flutter/foundation.dart';

/// AMIAL-FCM-001 — إعدادات Firebase الخاصّة بأميال باي.
///
/// كانت القيم مكتوبة داخل `main.dart` وتشير إلى مشروع القالب `gem-b5006`
/// (حزم `com.sixamtech.*`)، وحزمتنا `com.amyalpay.app` غير مسجّلة فيه أصلاً —
/// فلم يكن ممكناً وصول أي إشعار مهما فعلنا في الخادم.
///
/// القيم أدناه مأخوذة من `android/app/google-services.json` لمشروع `amial-pay`.
/// الملفان يجب أن يبقيا متطابقين؛ إن غيّرت أحدهما غيّر الآخر.
///
/// ليست أسراراً: مفتاح `apiKey` هنا مُضمَّن داخل كل نسخة APK وقابل للاستخراج،
/// وحمايته الحقيقية هي قواعد Firebase لا إخفاؤه. السرّ الوحيد هو ملف
/// service-account في لوحة الإدارة، ولا مكان له في هذا المستودع.
class AmialFirebaseOptions {
  const AmialFirebaseOptions._();

  static const FirebaseOptions android = FirebaseOptions(
    apiKey: 'AIzaSyCQ6dEoXKoc2ftsSlW9AbcQk9AIBCC3seM',
    appId: '1:800292015506:android:d7aa73a0f29678353c054b',
    messagingSenderId: '800292015506',
    projectId: 'amial-pay',
    storageBucket: 'amial-pay.firebasestorage.app',
  );

  /// يعيد الإعدادات المناسبة للمنصّة، أو `null` لمنصّة لم تُسجَّل بعد.
  ///
  /// iOS غير مسجَّل في مشروع `amial-pay` حتى الآن؛ نعيد `null` كي يستعمل
  /// `Firebase.initializeApp()` ملف `GoogleService-Info.plist` بدلاً من أن
  /// يقلع بإعدادات أندرويد الخاطئة.
  static FirebaseOptions? forCurrentPlatform() {
    switch (defaultTargetPlatform) {
      case TargetPlatform.android:
        return android;
      default:
        return null;
    }
  }
}
