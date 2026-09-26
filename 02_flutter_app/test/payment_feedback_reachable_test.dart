import 'dart:io';

import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:amial_pay/helper/payment_feedback.dart';

/// AMIAL-PAY-SOUND-001 — **نتيجةُ الدفع تُسمَع، ولا تُسقِط الدفعةَ إن لم تُسمَع.**
///
/// ══════════════════════════════════════════════════════════════════════
/// **قاله صاحبُ المشروع:** «عند اتمام الدفع صوت تم الدفع او فشل الدفع..
/// هذه لا تعمل لدي».
///
/// وقِيس فلم تكن قد وُجدت قطّ: صفرُ مشغّلٍ للصوت في التطبيق كلّه، وصفرُ
/// ملفِّ صوتٍ في المشروع غير نغمة الإشعار. فبُنيت.
///
/// **وثلاثةُ أشياءَ تُحرَس، وكلُّ واحدٍ منها كافٍ لإعادة العطل:**
///   ① أن يبقى الملفّان الصوتيّان (قناةٌ تشير إلى موردٍ مفقودٍ صامتة).
///   ② أن تُنادى النغمةُ من ورقة النتيجة الموحّدة ومن شبّاك البيع.
///   ③ **ألّا تُسقِط النغمةُ عمليّةً ماليّةً حين تفشل** — وهذا أهمُّها.
void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  test('\u2460 \u0627\u0644\u0645\u0644\u0641\u0651\u0627\u0646 \u0627\u0644\u0635\u0648\u062a\u064a\u0651\u0627\u0646 \u0645\u0648\u062c\u0648\u062f\u0627\u0646 \u0648\u0645\u0633\u0645\u0648\u0639\u0627\u0646 \u0641\u0639\u0644\u0627\u064b', () {
    for (final name in ['pay_success', 'pay_failed']) {
      final wav = File('android/app/src/main/res/raw/$name.wav');

      expect(wav.existsSync(), isTrue,
          reason: '**\u0627\u0644\u0645\u0648\u0631\u062f \u00ab$name\u00bb \u0645\u0641\u0642\u0648\u062f** \u2014 \u0648`MediaPlayer.create` \u062a\u064f\u0639\u064a\u062f '
              'null \u0641\u062a\u0635\u0645\u062a \u0627\u0644\u0646\u062a\u064a\u062c\u0629\u064f \u0628\u0644\u0627 \u062e\u0637\u0623\u064d \u0641\u064a \u0623\u064a\u0651 \u0633\u062c\u0644\u0651.');

      final bytes = wav.readAsBytesSync();

      expect(String.fromCharCodes(bytes.sublist(0, 4)), 'RIFF',
          reason: '\u00ab$name\u00bb \u0644\u064a\u0633 \u0645\u0644\u0641\u0651 WAV \u0635\u0627\u0644\u062d\u0627\u064b \u2014 \u064a\u064f\u0631\u0641\u064e\u0636 \u0639\u0646\u062f \u0627\u0644\u062a\u0634\u063a\u064a\u0644 \u0635\u0627\u0645\u062a\u0627\u064b');

      // ══════════════════════════════════════════════════════════════
      // **ووجودُ الملفّ ليس وجودَ صوت.**
      //
      // رأسُ WAV سليمٌ فوق ٤٤ بايتاً من الصمت يمرّ على كلّ فحصِ حجم،
      // ويُشغَّل، **ولا يُسمَع**. فيُقاس المحتوى: أعلى عيّنةٍ في الملفّ.
      // (القاعدة السابعة: صفرٌ لا يعني «فُحص».)
      // ══════════════════════════════════════════════════════════════
      var peak = 0;
      for (var i = 44; i + 1 < bytes.length; i += 2) {
        var v = bytes[i] | (bytes[i + 1] << 8);
        if (v >= 0x8000) v -= 0x10000;
        if (v.abs() > peak) peak = v.abs();
      }

      expect(peak, greaterThan(3000),
          reason: '**\u00ab$name\u00bb \u0635\u0627\u0645\u062a\u064c \u0623\u0648 \u062e\u0627\u0641\u062a\u064c \u062c\u062f\u0651\u0627\u064b** (\u0630\u0631\u0648\u0629 $peak \u0645\u0646 32767) \u2014 '
              '\u0648\u064a\u064f\u0634\u063a\u0651\u0644 \u0641\u0644\u0627 \u064a\u064f\u0633\u0645\u064e\u0639 \u0641\u064a \u0636\u062c\u064a\u062c \u0627\u0644\u0645\u062d\u0644\u0651.');

      expect(peak, lessThan(32700),
          reason: '\u00ab$name\u00bb \u0645\u0642\u0635\u0648\u0635\u064c \u0639\u0646\u062f \u0627\u0644\u0630\u0631\u0648\u0629 \u2014 \u064a\u064f\u0633\u0645\u064e\u0639 \u0645\u0634\u0648\u0651\u0647\u0627\u064b');
    }
  });

  test('\u0648\u0645\u0648\u0644\u0651\u062f\u064f\u0647\u0645\u0627 \u0641\u064a \u0627\u0644\u0645\u0633\u062a\u0648\u062f\u0639 \u2014 \u0641\u0623\u0635\u0644\u064c \u0644\u0627 \u064a\u064f\u0639\u0627\u062f \u0625\u0646\u062a\u0627\u062c\u064f\u0647 \u064a\u062a\u062c\u0645\u0651\u062f', () {
    // نغمةٌ تُنزَّل من الشبكة تحمل رخصةً لا تُقرأ، وتُشغَّل ملايينَ
    // المرّات في منتجٍ ماليّ. ومولِّدُها في المستودع يجعل تعديلَها
    // (أطولَ · أهدأَ · بدرجةٍ أخرى) سطراً لا بحثاً عن ملفٍّ بديل.
    expect(File('tool/generate_result_tones.py').existsSync(), isTrue,
        reason: '\u0645\u0648\u0644\u0651\u062f\u064f \u0627\u0644\u0646\u063a\u0645\u062a\u064a\u0646 \u0645\u0641\u0642\u0648\u062f \u2014 \u0641\u0645\u0646 \u0623\u0631\u0627\u062f \u062a\u0639\u062f\u064a\u0644\u064e\u0647\u0645\u0627 '
            '\u0644\u0627 \u064a\u062c\u062f \u0625\u0644\u0651\u0627 \u0645\u0648\u062c\u0627\u062a\u064d \u0645\u064f\u0633\u062c\u0651\u0644\u0629 \u0644\u0627 \u064a\u064f\u0639\u0631\u064e\u0641 \u0645\u0646 \u0623\u064a\u0646 \u062c\u0627\u0621\u062a.');
  });

  test('وMainActivity تشغّلهما بالاسم — لا بحزمةٍ خارجيّة', () {
    final kt = File('android/app/src/main/kotlin/amialpay/com/MainActivity.kt')
        .readAsStringSync();

    expect(kt.contains('R.raw.pay_success'), isTrue,
        reason: 'نغمةُ النجاح غير موصولةٍ بموردها');
    expect(kt.contains('R.raw.pay_failed'), isTrue,
        reason: 'نغمةُ الفشل غير موصولةٍ بموردها');
    expect(kt.contains('amial_pay/feedback_sound'), isTrue,
        reason: 'قناةُ النغمة غير مسجَّلة — فتُنادى من دارت ولا يردّ أحد');

    // **ولا حزمةَ صوتٍ خارجيّة.** إضافتُها تُدخل وحدةَ Gradle لا
    // يُصرِّفها شيءٌ في هذه البيئة، وأوّلُ من يقرؤها Codemagic — وقد
    // سقط مرّتين على هذا بعينه.
    final pubspec = File('pubspec.yaml').readAsStringSync();
    for (final pkg in ['audioplayers', 'just_audio', 'audio_session']) {
      expect(pubspec.contains('$pkg:'), isFalse,
          reason: 'أُضيفت حزمةُ صوتٍ «$pkg» — والنغمةُ تُشغَّل من إطار '
              'أندرويد نفسِه بلا تبعيّة. وحزمةٌ لا يُصرِّفها أحدٌ هنا '
              'تُسقط بناءَ Codemagic بدل أن تُضيف صوتاً.');
    }
  });

  // ══════════════════════════════════════════════════════════════════
  // AMIAL-PAY-VOICE-001 — **النطقُ بالعربيّة، وحدودُه مقولةٌ لا مسكوتٌ عنها.**
  //
  // طلبه صاحبُ المشروع: «الا يمكنك جعل صوت يتكلم العربي».
  //
  // **ولا مُصرِّفَ Kotlin في هذه البيئة** — فما يُحرَس هنا هو العقدُ
  // المقروء: النصُّ العربيُّ المنطوق، وسؤالُ توفّر اللغة، وتحريرُ
  // المُركِّب. والتصريفُ يقع في Codemagic، ويُقال ذلك ولا يُخفى.
  // ══════════════════════════════════════════════════════════════════
  test('ينطق بالعربيّة نصّاً بعينه — لا برسالةٍ مترجَمةٍ في مكانٍ آخر', () {
    final kt = File('android/app/src/main/kotlin/amialpay/com/MainActivity.kt')
        .readAsStringSync();

    expect(kt.contains('"تم الدفع بنجاح"'), isTrue,
        reason: 'نصُّ النجاح المنطوقُ اختفى — فيُشغَّل الجرسُ ولا يُقال شيء');
    expect(kt.contains('"فشل الدفع"'), isTrue,
        reason: 'نصُّ الفشل المنطوقُ اختفى — **والفشلُ الصامتُ أخطر**: '
            'يمضي الكاشيرُ ظانّاً أنّ الدفعَ تمّ');

    expect(kt.contains('TextToSpeech'), isTrue,
        reason: 'مُركِّبُ الكلام غيرُ موصول');
  });

  test('ولا يَعِد بنطقٍ على جهازٍ بلا صوتٍ عربيّ', () {
    final kt = File('android/app/src/main/kotlin/amialpay/com/MainActivity.kt')
        .readAsStringSync();

    // **يُسأل النظامُ ولا يُخمَّن.** جهازٌ بلا صوتٍ عربيٍّ منزَّل يبتلع
    // النداءَ صامتاً — فتصمت النتيجةُ كلُّها لو استُبدل الجرسُ بالنطق.
    for (final code in ['LANG_MISSING_DATA', 'LANG_NOT_SUPPORTED']) {
      expect(kt.contains(code), isTrue,
          reason: 'لا يُفحص «$code» — فيُفترَض وجودُ الصوت العربيّ، '
              'ويصمت الجهازُ الذي لا يملكه بلا أن يقول لماذا.');
    }

    // والجرسُ يبقى مهما كان — فهو الأرضيّةُ التي لا تسقط.
    expect(kt.contains('R.raw.pay_success'), isTrue,
        reason: 'سقط الجرسُ واعتُمد النطقُ وحدَه — فجهازٌ بلا صوتٍ عربيّ '
            'يصير صامتاً تماماً، وهو ما كانت الشكوى منه.');
  });

  test('ويُحرَّر المُركِّبُ عند الإغلاق — وإلّا بقي خيطُه حيّاً', () {
    final kt = File('android/app/src/main/kotlin/amialpay/com/MainActivity.kt')
        .readAsStringSync();

    expect(kt.contains('tts?.shutdown()'), isTrue,
        reason: 'المُركِّبُ لا يُحرَّر في onDestroy — يبقى اتّصالُ الخدمة '
            'مفتوحاً بعد إغلاق الشاشة، ويُنذر النظامُ بتسريب.');
  });

  test('② النغمةُ منادَاةٌ من ورقة النتيجة الموحّدة ومن شبّاك البيع', () {
    final doors = {
      'lib/common/widgets/amial_result_sheet.dart':
          'ورقةُ النتيجة الموحّدة — وبها يمرّ الدفعُ والتسديدُ والدفعُ الآمن',
      'lib/features/merchant/screens/cashier_payment_screen.dart':
          'شبّاكُ بيع التاجر — ولا يمرّ بالورقة الموحّدة',
      'lib/features/merchant/screens/merchant_accept_payment_screen.dart':
          'طلبُ الدفع من التاجر',
    };

    doors.forEach((path, why) {
      final src = File(path).readAsStringSync();

      // **ويُقاس النداءُ لا الاستيراد.** حارسٌ يبحث عن اسم الصنف يمرّ
      // ولو بقي سطرُ `import` وحدَه بلا نداء — وهو العطلُ الذي وقع في
      // حارس لوحة الوقود.
      expect(src.contains('PaymentFeedback.success()'), isTrue,
          reason: 'لا نغمةَ نجاحٍ في «$path» ($why)');
      expect(src.contains('PaymentFeedback.failure()'), isTrue,
          reason: 'لا نغمةَ فشلٍ في «$path» ($why) — والفشلُ الصامتُ '
              'أخطرُ من النجاح الصامت: يمضي الكاشيرُ ظانّاً أنّ الدفعَ تمّ.');
    });
  });

  test('③ وفشلُ النغمة لا يُسقِط العمليّة — وهو أهمُّ ما يُحرَس', () async {
    // ══════════════════════════════════════════════════════════════
    // القناةُ غيرُ منفَّذةٍ على iOS ولا في بيئة الاختبار. **واستثناءٌ
    // يخرج من النغمة يُلوّن دفعةً ناجحةً بالفشل** — أي أنّ تحسيناً
    // صوتيّاً يكسر مساراً ماليّاً. فيُحاكى الرفضُ ويُتأكَّد أنّها تبتلعه.
    // ══════════════════════════════════════════════════════════════
    var called = 0;

    TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger
        .setMockMethodCallHandler(
      const MethodChannel('amial_pay/feedback_sound'),
      (call) async {
        called++;
        throw PlatformException(code: 'UNAVAILABLE', message: 'لا مشغّل');
      },
    );

    await expectLater(PaymentFeedback.success(), completes,
        reason: '**رمت النغمةُ استثناءً فأسقطت المسار** — فدفعةٌ نجحت '
            'تُعرَض فاشلةً، والسببُ صوت.');
    await expectLater(PaymentFeedback.failure(), completes);

    expect(called, 2,
        reason: 'لم تُنادَ القناةُ أصلاً — فالاختبارُ يفحص فراغاً');

    TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger
        .setMockMethodCallHandler(
            const MethodChannel('amial_pay/feedback_sound'), null);
  });
}
