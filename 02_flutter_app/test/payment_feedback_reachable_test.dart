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

  test('① الملفّان الصوتيّان في مكانهما', () {
    for (final name in ['pay_success', 'pay_failed']) {
      final wav = File('android/app/src/main/res/raw/$name.wav');
      expect(wav.existsSync(), isTrue,
          reason: '**المورد «$name» مفقود** — و`MediaPlayer.create` تُعيد '
              'null فتصمت النتيجةُ بلا خطأٍ في أيّ سجلّ.');
      expect(wav.lengthSync(), greaterThan(1000),
          reason: 'ملفُّ «$name» فارغٌ أو مبتور — يُشغَّل ولا يُسمَع');
    }
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
