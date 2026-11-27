import 'package:flutter/services.dart';
import 'package:get/get.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// AMIAL-PAY-SOUND-001 + AMIAL-PAY-VOICE-002 — **نتيجةُ العملية والإشعارات المالية تُسمَع وتُنطَق بالعربيّة.**
class PaymentFeedback {
  PaymentFeedback._();

  static const MethodChannel _channel =
      MethodChannel('amial_pay/feedback_sound');

  static const String voicePrefsKey = 'amial_voice_notifications_enabled';

  /// يُطفأ في الاختبارات ومن يريد شاشةً صامتة.
  static bool enabled = true;

  static DateTime? _lastActionFeedbackAt;

  /// هل النطق الصوتي للإشعارات المالية الواردة مفعّل؟ (مفعّل افتراضياً).
  static bool isVoiceNotificationsEnabled() {
    try {
      if (!Get.isRegistered<SharedPreferences>()) return true;
      return Get.find<SharedPreferences>().getBool(voicePrefsKey) ?? true;
    } catch (_) {
      return true;
    }
  }

  static Future<void> setVoiceNotificationsEnabled(bool value) async {
    try {
      if (!Get.isRegistered<SharedPreferences>()) return;
      await Get.find<SharedPreferences>().setBool(voicePrefsKey, value);
    } catch (_) {}
  }

  /// **تمّت العملية** — جرسٌ صاعد، ثمّ نطقٌ عربيّ («تمّ الدفع بنجاح» أو نصّ مخصّص)، واهتزاز.
  static Future<void> success({String? speech}) {
    _lastActionFeedbackAt = DateTime.now();
    return _fire(
      'success',
      HapticFeedback.mediumImpact,
      speech: isVoiceNotificationsEnabled() ? speech : null,
      allowDefaultSpeech: isVoiceNotificationsEnabled(),
    );
  }

  /// **فشلت العملية** — جرسٌ هابط، ثمّ نطقٌ عربيّ («فشل الدفع» أو نصّ مخصّص)، واهتزازٌ أطول.
  static Future<void> failure({String? speech}) {
    _lastActionFeedbackAt = DateTime.now();
    return _fire(
      'failure',
      HapticFeedback.vibrate,
      speech: isVoiceNotificationsEnabled() ? speech : null,
      allowDefaultSpeech: isVoiceNotificationsEnabled(),
    );
  }

  /// **إشعار مالي وارد** — نغمة الإشعار + نطق عربي للحدث عند التفعيل، مع منع التداخل
  /// إذا وقع بعد أقل من 4 ثوانٍ من نطق نتيجة عملية نفّذها المستخدم للتوّ على جهازه.
  static Future<void> notify({String? speech}) async {
    if (!enabled) return;
    final last = _lastActionFeedbackAt;
    if (last != null &&
        DateTime.now().difference(last) < const Duration(seconds: 4)) {
      return;
    }

    final cleanSpeech =
        isVoiceNotificationsEnabled() ? speech?.trim() : null;
    await _fire(
      'notify',
      HapticFeedback.lightImpact,
      speech: (cleanSpeech != null && cleanSpeech.isNotEmpty)
          ? cleanSpeech
          : null,
    );
  }

  /// AMIAL-PAY-VOICE-001 — هل ينطق هذا الجهازُ العربيّة أصلاً؟
  static Future<bool> canSpeakArabic() async {
    try {
      return await _channel.invokeMethod<bool>('canSpeakArabic') ?? false;
    } catch (_) {
      return false;
    }
  }

  static Future<void> _fire(
    String method,
    Future<void> Function() haptic, {
    String? speech,
    bool allowDefaultSpeech = true,
  }) async {
    if (!enabled) return;

    // ══════════════════════════════════════════════════════════════
    // **ولا تُسقِط عمليّةً ماليّةً من أجل نغمة.**
    // ══════════════════════════════════════════════════════════════
    try {
      if (speech != null && speech.trim().isNotEmpty) {
        await _channel.invokeMethod<void>(method, {'speech': speech.trim()});
      } else if (!allowDefaultSpeech &&
          (method == 'success' || method == 'failure')) {
        await _channel.invokeMethod<void>('notify');
      } else {
        await _channel.invokeMethod<void>(method);
      }
    } catch (_) {
      // مقصود: النغمةُ تحسينٌ لا شرطُ صحّة.
    }

    try {
      await haptic();
    } catch (_) {
      // مقصود.
    }
  }
}
