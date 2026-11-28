import 'dart:io';

import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:amial_pay/helper/notification_helper.dart';
import 'package:amial_pay/helper/payment_feedback.dart';

/// AMIAL-NOTIFICATIONS-E2E-GUARD-001
///
/// يحرس الطبقات الثلاث للإشعارات في تطبيق Flutter والطبقتين الأصليتين (Android/iOS):
///   1. إعلان TTS_SERVICE في AndroidManifest.xml (إلزامي لعمل النطق العربي في Android 11–15).
///   2. تكامل قناة الصوت والنطق العربي في MainActivity.kt وAppDelegate.swift.
///   3. رفع رمز FCM الموحّد وعزله في مسارات الدخول والإقلاع والخروج.
///   4. عدم طمس الإشعارات بمعرّف 0 وتحديث مركز الإشعارات والمنع الذكي لتداخل الصوت.
void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  group('① النطق الصوتي العربي — Android 11+ وiOS', () {
    test('AndroidManifest يعلن TTS_SERVICE داخل <queries> ليعمل TextToSpeech', () {
      final manifest = File('android/app/src/main/AndroidManifest.xml')
          .readAsStringSync()
          .replaceAll(RegExp(r'<!--.*?-->', dotAll: true), '');

      final queriesStart = manifest.indexOf('<queries>');
      final queriesEnd = manifest.indexOf('</queries>');

      expect(queriesStart, greaterThanOrEqualTo(0));
      expect(queriesEnd, greaterThan(queriesStart));

      final queriesBlock = manifest.substring(queriesStart, queriesEnd);
      expect(
        queriesBlock.contains('android.intent.action.TTS_SERVICE'),
        isTrue,
        reason: 'غياب TTS_SERVICE من <queries> يحجب محرّك النطق عن التطبيق '
            'في Android 11–15 فتفشل تهيئة TextToSpeech دائماً بصمت.',
      );
    });

    test('MainActivity وAppDelegate يدعمان نغمة الإشعار والنطق العربي المخصّص', () {
      final kt = File('android/app/src/main/kotlin/amialpay/com/MainActivity.kt')
          .readAsStringSync();
      expect(kt.contains('setAudioAttributes'), isTrue);
      expect(kt.contains('pendingSpeech'), isTrue);
      expect(kt.contains('"notify"'), isTrue);
      expect(kt.contains('R.raw.notification'), isTrue);

      final swift = File('ios/Runner/AppDelegate.swift').readAsStringSync();
      expect(swift.contains('amial_pay/feedback_sound'), isTrue);
      expect(swift.contains('AVSpeechSynthesizer'), isTrue);
      expect(swift.contains('ar-SA'), isTrue);
    });
  });

  group('② تسجيل FCM الموحّد والإشعارات الخلفية والداخلية', () {
    test('AuthRepo وRoleRouter وNavBarScreen يربطون رمز FCM الموحّد وتجديده', () {
      final authRepo = File('lib/features/auth/domain/reposotories/auth_repo.dart')
          .readAsStringSync();
      expect(authRepo.contains('AppConstants.unifiedFcmTokenUri'), isTrue);
      expect(authRepo.contains('onTokenRefresh'), isTrue);

      final roleRouter =
          File('lib/features/auth/screens/role_router.dart').readAsStringSync();
      expect(roleRouter.contains('updateToken()'), isTrue);
      expect(roleRouter.contains('consumePendingInitialNotification()'), isTrue);

      final navBar =
          File('lib/features/home/screens/nav_bar_screen.dart').readAsStringSync();
      expect(navBar.contains('NotificationsCenterController'), isTrue);
      expect(navBar.contains('unreadCount'), isTrue);
    });

    test('NotificationHelper يمرّر خيارات Firebase في الخلفية ولا يستعمل المعرّف الثابت 0', () {
      final helper =
          File('lib/helper/notification_helper.dart').readAsStringSync();
      expect(helper.contains('AmialFirebaseOptions.forCurrentPlatform()'), isTrue);
      expect(helper.contains('NotificationsCenterController'), isTrue);
      expect(helper.contains('fln.show(0,'), isFalse,
          reason: 'استخدام المعرّف الثابت 0 يمسح كل إشعار سابق عند وصول إشعار جديد.');
    });
  });

  group('③ سلوك PaymentFeedback وNotificationHelper وقت التشغيل', () {
    test('nextNotificationId يولّد معرّفات فريدة ويحترم notification_id القادم من الخادم', () {
      expect(
        NotificationHelper.nextNotificationId({'notification_id': '4821'}),
        4821,
      );
      final a = NotificationHelper.nextNotificationId();
      final b = NotificationHelper.nextNotificationId();
      expect(a, isNot(equals(b)));
      expect(a, greaterThan(0));
    });

    test('buildSpokenSummary ينظّف معرّفات ULID الطويلة من النص المنطوق', () {
      final spoken = NotificationHelper.buildSpokenSummary(
        'استلمت حوالة مالية',
        'وصلك مبلغ 5000 ر.ي مرجع 01J9Z8K7M6N5P4Q3R2S1T0VXYZ',
      );
      expect(spoken.contains('01J9Z8K7M6N5P4Q3R2S1T0VXYZ'), isFalse);
      expect(spoken.contains('استلمت حوالة مالية'), isTrue);
      expect(spoken.contains('5000'), isTrue);
    });

    test('PaymentFeedback.notify يرسل النص العربي ويمنع التداخل الفوري بعد نغمة عملية محلية', () async {
      final calls = <MethodCall>[];
      TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger
          .setMockMethodCallHandler(
        const MethodChannel('amial_pay/feedback_sound'),
        (call) async {
          calls.add(call);
          return true;
        },
      );

      addTearDown(() {
        TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger
            .setMockMethodCallHandler(
                const MethodChannel('amial_pay/feedback_sound'), null);
      });

      // إشعار وارد مستقل يُنطق فوراً
      await PaymentFeedback.notify(speech: 'استلمت حوالة مالية');
      expect(calls.length, 1);
      expect(calls.first.method, 'notify');
      expect((calls.first.arguments as Map)['speech'], 'استلمت حوالة مالية');

      // بعد عملية محلية ناجحة، يُكتم صدى الإشعار الذاتي الفوري حتى لا يقطع «تم الدفع بنجاح»
      await PaymentFeedback.success();
      expect(calls.length, 2);
      expect(calls[1].method, 'success');

      await PaymentFeedback.notify(speech: 'تم إرسال الحوالة');
      expect(calls.length, 2,
          reason: 'تداخل إشعار الصدى الذاتي مع نطق نجاح العملية المحلية!');
    });
  });
}
