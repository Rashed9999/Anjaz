// AMIAL-BRAND-LOCKUP-001 — **شعارٌ واحدٌ يُسلَّم، لا أربعُ طبقاتٍ تُركَّب.**
//
// ══════════════════════════════════════════════════════════════════════
// أرسل صاحبُ المشروع الشعارَ النهائيَّ وقال: «غيّره إلى هذا في أغلب أماكن
// التطبيق، **ما عدا شعارات فتح بداية التطبيق**. اختر الأحجام المناسبة».
//
// **وثلاثةُ أشياءَ قِيست قبل أوّل تعديل:**
//
//   ① النسخةُ الكاملةُ كانت تُركَّب من أربعة ملفّاتٍ بمقاساتٍ وفواصلَ
//      مكتوبةٍ يدويّاً — أي شعارٌ **قريبٌ** من الأصل لا الأصلَ نفسَه،
//      وأيُّ فرقٍ يتراكم عبر أربع طبقاتٍ ولا شيءَ يكشفه.
//   ② الملفُّ المُرسَل ١٤٤٨×١٠٨٦ ومحتواه ١١٤٧×٦٩٤ — **هامشٌ شفّافٌ
//      يبتلع ٣٦٪ من كلّ صندوقٍ يوضع فيه**، فيُرسَم الشعارُ صغيراً بلا
//      سبب ولا خطأَ في أيّ سجلّ. فقُصّ قبل التركيب.
//   ③ سبعةُ مواضعَ تضع الشعارَ في **مربّع** — لأنّ القديمَ كان طوليّاً.
//      والجديدُ عرضُه ١٫٦ ضعفَ ارتفاعِه، فمربّعٌ يترك ثلثَيه فراغاً.
//
// **والحالةُ الرابعةُ هي الأهمّ**: شاشةُ الافتتاح تحرّك الطبقاتِ كلاًّ على
// حدة، فطبقةٌ واحدةٌ مسطَّحةٌ تُلغي الحركةَ نفسَها — وهو ما استثناه
// صاحبُ المشروع صراحةً.

import 'dart:io';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:amial_pay/common/widgets/amial_brand_logo.dart';

String _read(String p) {
  final f = File(p);
  expect(f.existsSync(), isTrue, reason: 'غيرُ موجود: $p');
  return f.readAsStringSync();
}

void main() {
  const widget = 'lib/common/widgets/amial_brand_logo.dart';
  const splash = 'lib/features/splash/widgets/brand_splash_animation.dart';

  test('① الشعارُ ملفٌّ واحدٌ لا أربعُ طبقاتٍ تُركَّب', () {
    final code = _read(widget)
        .split('\n')
        .where((l) => !l.trimLeft().startsWith('//') && !l.trimLeft().startsWith('///'))
        .join('\n');

    expect(code.contains('logo_lockup.png'), isTrue,
        reason: 'الشعارُ النهائيُّ غيرُ مستعمَل');

    for (final layer in ['logo_wordmark.png', 'logo_swoosh.png',
      'logo_latin.png', 'logo_tagline.png']) {
      expect(code.contains(layer), isFalse,
          reason: 'ما زال يُركَّب من $layer — فالمعروضُ قريبٌ من الشعار '
              'لا الشعارُ نفسُه، وفرقُ التباعد يتراكم عبر أربع طبقات');
    }
  });

  test('② والملفُّ موجودٌ ومقصوصُ الهامش', () async {
    final f = File('assets/brand/logo_lockup.png');
    expect(f.existsSync(), isTrue, reason: 'أصلُ الشعار غيرُ مضاف');

    // **النسبةُ تُقاس من الملفّ نفسِه** — ورقمٌ مكتوبٌ في الشيفرة يشيخ
    // يومَ يُستبدَل الأصل.
    final bytes = await f.readAsBytes();
    final w = bytes.buffer.asByteData().getUint32(16);
    final h = bytes.buffer.asByteData().getUint32(20);

    expect((w / h - AmialBrandLogo.lockupAspect).abs() < 0.01, isTrue,
        reason: 'نسبةُ الملفّ ${(w / h).toStringAsFixed(3)} تخالف '
            '`lockupAspect` — فمن مرّر ارتفاعاً وحدَه يحصل على عرضٍ خطأ');
  });

  test('③ ومن مرّر ضلعاً واحداً يحصل على الآخر بالنسبة', () {
    // **بلا هذا يُحشَر الشعارُ في مربّعٍ فيُرسَم بثلثه.**
    const byHeight = AmialBrandLogo(height: 100);
    expect(byHeight.height, 100);
    expect(byHeight.width, isNull,
        reason: 'المقاسُ يُكمَّل في `build` لا في المُنشئ');
  });

  testWidgets('③ب والعرضُ المحسوبُ يظهر في الشجرة فعلاً', (tester) async {
    await tester.pumpWidget(const MaterialApp(
      home: Scaffold(body: Center(child: AmialBrandLogo(height: 100))),
    ));

    final box = tester.getSize(find.byType(AmialBrandLogo));

    expect(box.height, 100);
    expect((box.width - 100 * AmialBrandLogo.lockupAspect).abs() < 0.5, isTrue,
        reason: 'مُرّر ارتفاعٌ وحدَه فخرج عرضٌ لا يطابق النسبة — '
            'فالشعارُ يُقصّ أو يُترك حوله فراغ');
  });

  test('④ وشاشةُ الافتتاح تبقى على طبقاتها — وهو ما استُثني صراحةً', () {
    final code = _read(splash);

    // حركةُ الافتتاح تُظهر كلَّ طبقةٍ في وقتها؛ وملفٌّ مسطَّحٌ يُلغيها.
    for (final layer in ['logo_wordmark.png', 'logo_swoosh.png',
      'logo_latin.png', 'logo_tagline.png']) {
      expect(code.contains(layer), isTrue,
          reason: 'ذهبت طبقةُ $layer من شاشة الافتتاح — والحركةُ تُظهر كلَّ '
              'طبقةٍ في وقتها، وصاحبُ المشروع استثنى هذه الشاشةَ نصّاً');
    }

    expect(code.contains('logo_lockup.png'), isFalse,
        reason: 'دخل الشعارُ المسطَّحُ شاشةَ الافتتاح — فتُلغى الحركة');
  });

  test('⑤ ولا موضعَ يقرأ ملفَّ الشعار خاماً خارج الودجت', () {
    // **مصدرٌ واحدٌ لا سبيلان** — وملفٌّ يُقرأ خاماً لا يتبع النسبةَ ولا
    // يتغيّر يومَ يُستبدَل الشعار.
    final offenders = <String>[];

    for (final f in Directory('lib').listSync(recursive: true)) {
      if (f is! File || !f.path.endsWith('.dart')) continue;
      if (f.path.endsWith('amial_brand_logo.dart')) continue;
      if (f.path.endsWith('brand_splash_animation.dart')) continue;

      final code = f.readAsStringSync();
      if (code.contains('Image.asset(Images.logo')
          || code.contains('logo_lockup.png')) {
        offenders.add(f.path);
      }
    }

    expect(offenders, isEmpty,
        reason: 'تقرأ ملفَّ الشعار خاماً: ${offenders.join('، ')} — '
            'استعمل `AmialBrandLogo` فتتبع النسبةَ والاستبدال');
  });
}
