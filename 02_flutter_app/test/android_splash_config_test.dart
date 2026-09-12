import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

/// AMIAL-SPLASH-005 — إعداد شاشة الإقلاع على أندرويد.
///
/// **الخلل الذي يمنعه هذا الملفّ:**
/// أُصلحت شاشة الإقلاع مرّتين وبقيت على حالها. السبب أن الإصلاحين وقعا في
/// آليةٍ لا يستعملها النظام: منذ أندرويد 12 (API 31)، وحين يكون
/// targetSdk ≥ 31، يتجاهل النظام `android:windowBackground` ويستعمل واجهة
/// SplashScreen الجديدة. فـ launch_background.xml لا يُقرأ على أي هاتف
/// حديث مهما وُضع فيه.
///
/// وهذا الصنف لا يكشفه معاينة الصورة التي أنتجتُها — كانت الصورة سليمة
/// تماماً، والملفّ الذي يحويها لا يُفتح أصلاً. الفحص الصحيح أن يُسأل:
/// أيّ ملفّ يقرؤه النظام على النسخة التي نستهدفها؟
void main() {
  const resDir = 'android/app/src/main/res';

  group('أندرويد 12+ (values-v31)', () {
    test('ملفّ الأنماط موجود — بدونه يعرض النظام الأيقونة مقصوصة', () {
      expect(File('$resDir/values-v31/styles.xml').existsSync(), isTrue,
          reason: 'targetSdk ≥ 31 يجعل launch_background.xml غير مقروء');
    });

    test('يحدّد خلفية السبلاش وأيقونته صراحةً', () {
      final xml = File('$resDir/values-v31/styles.xml').readAsStringSync();

      expect(xml, contains('android:windowSplashScreenBackground'));
      expect(xml, contains('android:windowSplashScreenAnimatedIcon'));
      // بلا هذين يقع النظام على الافتراضي: أيقونة التطبيق التكيّفية،
      // وهامش الأمان فيها ثلث المساحة فتظهر صغيرة منزاحة.
      expect(xml, contains('@drawable/splash_icon'));
      expect(xml, contains('@color/amial_yellow'));
    });

    test('الوضع الليلي له نفس الإعداد — وإلّا عاد العطل ليلاً', () {
      final night = File('$resDir/values-night-v31/styles.xml');
      expect(night.existsSync(), isTrue);
      expect(night.readAsStringSync(), contains('windowSplashScreenAnimatedIcon'));
    });
  });

  group('أصول السبلاش', () {
    const densities = ['mdpi', 'hdpi', 'xhdpi', 'xxhdpi', 'xxxhdpi'];

    test('splash_icon موجود بكل الكثافات', () {
      for (final d in densities) {
        expect(File('$resDir/drawable-$d/splash_icon.png').existsSync(), isTrue,
            reason: 'ينقص drawable-$d/splash_icon.png');
      }
    });

    test('launch_logo موجود للأجهزة قبل أندرويد 12', () {
      // المسار القديم ما زال مستعملاً على API < 31، فلا يُهمَل.
      for (final d in densities) {
        expect(File('$resDir/drawable-$d/launch_logo.png').existsSync(), isTrue,
            reason: 'ينقص drawable-$d/launch_logo.png');
      }
    });

    // ══════════════════════════════════════════════════════════════════
    // **الأصلُ صورةٌ قبل أن يكون مربّعاً.**
    //
    // قِيس فوُجد ثلاثةٌ من الخمسة **ليست PNG إطلاقاً**: لا توقيعَ في
    // أوّلها، وبايتاتٌ عشوائيّةٌ محلَّها — كتبها `1f44499` («fix: unify
    // merchant financial views across verticals»)، وهو التزامٌ عن
    // شاشاتٍ ماليّةٍ لا عن أصولٍ رسوميّة. وكتب الغثاءَ نفسَه في
    // `routes/api/amial.php` معه.
    //
    // **وكان الحارسُ يقرأ البايتات ١٦..٢٣ من غيرِ صورةٍ فيُخرج
    // «ليست مربّعة»** — والرقمُ ٣٦٦٠٢٨٦٤٧٢×٢٨٠٨٧٦٥٢٨٨. فيُرسل من
    // يصدّقه ليقصّ صورةً سليمةً لا وجودَ لها. **وحارسٌ يصيب في المنع
    // ويخطئ في السبب يُنتج إصلاحاً في المكان الخطأ.** (القاعدة الثالثة.)
    // ══════════════════════════════════════════════════════════════════
    test('أيقونة السبلاش صورةٌ أصلاً، ثمّ مربّعة', () {
      const pngSignature = [0x89, 0x50, 0x4E, 0x47, 0x0D, 0x0A, 0x1A, 0x0A];

      for (final d in densities) {
        final bytes =
            File('$resDir/drawable-$d/splash_icon.png').readAsBytesSync();

        expect(bytes.length, greaterThan(pngSignature.length),
            reason: 'drawable-$d/splash_icon.png فارغٌ أو مبتور.');

        expect(bytes.sublist(0, 8), pngSignature,
            reason: '**drawable-$d/splash_icon.png ليس PNG** — لا توقيعَ '
                'في أوّله. والامتدادُ وحدَه لا يجعل الملفَّ صورةً: '
                'يُبنى التطبيقُ وتُعرض شاشةُ إقلاعٍ بلا شعار، ولا يقول '
                'ذلك مُصرِّفٌ ولا محلِّل.');

        // ترويسة PNG: العرض والارتفاع في البايتات 16..23 — **ولا تُقرأ
        // إلّا بعد ثبوت التوقيع**، وإلّا فُسِّر غثاءٌ على أنّه مقاس.
        final width = bytes.buffer.asByteData().getUint32(16);
        final height = bytes.buffer.asByteData().getUint32(20);

        // لوحة غير مربّعة تُقصّ بغير ما نتوقّع، فيبدو الشعار منزاحاً.
        expect(width, height,
            reason: 'drawable-$d/splash_icon.png ليست مربّعة '
                '($width×$height)');
      }
    });
  });

  test('المسار القديم يشير إلى الشعار لا إلى أيقونة التطبيق', () {
    for (final f in ['drawable', 'drawable-v21']) {
      final raw = File('$resDir/$f/launch_background.xml').readAsStringSync();

      // تُنزع التعليقات أوّلاً: الملفّ يشرح في تعليقه ما كان يعرضه سابقاً،
      // وفحص النصّ كاملاً يجعل الشرح نفسه سبب الفشل.
      final xml = raw.replaceAll(RegExp(r'<!--.*?-->', dotAll: true), '');

      expect(xml, contains('@drawable/launch_logo'));
      // وجه الأيقونة التكيّفية مصمَّم للقصّ في قناع، وعرضه خامّاً يُظهر
      // شعاراً بثلث حجمه خارج مركزه.
      expect(xml, isNot(contains('ic_launcher_foreground')));
    }
  });
}
