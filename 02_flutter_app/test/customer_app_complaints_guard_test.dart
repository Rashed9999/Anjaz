// AMIAL-CUSTOMER-COMPLAINTS-001 — **الطبقةُ التي كانت غائبةً حين وصلت
// الشكوى.**
//
// ══════════════════════════════════════════════════════════════════════
// أرسل صاحبُ المشروع ستَّ شكاوى من تطبيق العميل، وقال: «لماذا لم يكتشف
// ساهر الأخطاء؟». والجوابُ مقيسٌ لا معتذَر: **ساهر لا يقرأ Dart** — هو
// مجموعةُ جامعاتٍ ثابتةٍ لشيفرة PHP وحدَها.
//
// والأرقام:
//
//   شاشاتٌ عامّةٌ في التطبيق         ٢٠١
//   مذكورةٌ في أيّ اختبارِ دارت        ٨
//   ملفّاتُ اختبارِ الخادم           ٣٩٦
//   ملفّاتُ اختبارِ دارت              ٢٠  ·  تبني واجهةً فعلاً: ٥
//
// فأربعٌ من شكاواه الستّ **سلوكيّةٌ في الواجهة**، ولا يمسك واحدةً منها
// اختبارُ خادمٍ مهما كثر. وهذا الملفُّ أوّلُ حجرٍ في تلك الطبقة: يحرس
// أربعاً منها بحيث لا تعود صامتة.
//
// ══════════════════════════════════════════════════════════════════════
// **والقياسُ على المعنى لا الصياغة**، وتُنزَع التعليقاتُ قبل كلّ بحث —
// فقد مرّ حارسٌ في هذه الجلسة لأنّ الكلمةَ وردت في تعليقٍ عربيٍّ يصف
// الميزةَ لا في شيفرةٍ تُنفّذها. (وهو عطلٌ مكتوبٌ في `CLAUDE.md` وقعتُ
// فيه بعد قراءته.)

import 'dart:io';
import 'dart:math' as math;

import 'package:flutter_test/flutter_test.dart';

void main() {
  // ══════════════════════════════════════════════════════════════════
  //  ⑤ «الألوان الكتابة في الدفع الامن غير واضحة»
  // ══════════════════════════════════════════════════════════════════

  group('الشكوى ⑤ — التباين في الدفع الآمن', () {
    test('كلُّ زوجِ نصٍّ وخلفيّةٍ في مؤشّر الخطوات يجتاز 4.5:1', () {
      // القيمُ من `AmialColors` ومن الشاشة نفسِها — تُقرأ لا تُكتب هنا.
      final palette = _tokens();

      final surface = 'F0F1F3'; // خلفيّةُ الخطوة القادمة، في الشاشة
      final onSurface = palette['textSecondary']!;

      final ratio = _contrast(onSurface, surface);

      expect(ratio >= 4.5, isTrue,
          reason: 'لونُ نصِّ الخطوة القادمة على #$surface = '
              '${ratio.toStringAsFixed(2)}:1 — والحدُّ 4.5:1. '
              'وكان `textMuted` فأعطى 2.62:1، وهي الشكوى بعينها.');
    });

    test('**ولا يعود `textMuted` إلى مؤشّر الخطوات**', () {
      final src = _strip(_read(
        'lib/features/safe_payment/screens/safe_payment_detail_screen.dart'));

      // ══════════════════════════════════════════════════════════════
      // **ويُقتصَر على الودجت نفسِها لا إلى آخر الملفّ.**
      //
      // `textMuted` **مشروعةٌ** في مواضعَ أخرى من هذه الشاشة — على
      // خلفيّاتٍ بيضاءَ تجتاز الحدَّ بسهولة. فالممنوعُ استعمالُها على
      // خلفيّة #F0F1F3 وحدَها، لا اللونُ نفسُه.
      //
      // وشريحةٌ تمتدّ إلى آخر الملفّ تُسقط الحارسَ على استعمالٍ سليم —
      // **وحارسٌ يمنع صواباً يُعطَّل، ثمّ لا يحرس شيئاً.**
      // ══════════════════════════════════════════════════════════════
      final from = src.indexOf('class _StagesStrip');
      final indicator = src.substring(
        from, src.indexOf('for (var i = 0; i < 3; i++)') + 2200);

      expect(indicator.contains('AmialColors.textMuted'), isFalse,
          reason: 'عاد اللونُ الباهتُ إلى مؤشّر خطوات الدفع الآمن — '
              'وقِيس أنّه 2.62:1 على خلفيّته، أي نصٌّ لا يُقرأ');
    });

    test('توكِنُ التباين موجودٌ في اللوحة — فلا يُخترَع لونٌ جديد', () {
      final palette = _tokens();
      expect(palette.containsKey('textSecondary'), isTrue,
          reason: 'لا توكِنَ لنصٍّ ثانويٍّ مقروء — ومن لم يجد ما يحتاجه '
              'اخترع، فصار في المشروع ستّةُ أخضرَ تعني «نجح»');
    });
  });

  // ══════════════════════════════════════════════════════════════════
  //  ⑥ «ابعاد التطبيق في الاعلى و الاسفل غير منسقة — مقتطفات سوداء»
  // ══════════════════════════════════════════════════════════════════

  group('الشكوى ⑥ — الشريطان الأسودان', () {
    test('التطبيق يضبط شريطَي النظام مرّةً على الأقلّ', () {
      final main = _strip(_read('lib/main.dart'));

      expect(main.contains('setSystemUIOverlayStyle'), isTrue,
          reason: '**لا شيءَ في ٥١١ ملفَّ دارت ينادي `SystemChrome`** — '
              'فالشريطان يبقيان على لون الثيم الأصل `Theme.Black`، '
              'أي شريطان أسودان فوق التطبيق وتحته');

      // **وسطوعُ الأيقونات يُقال صراحةً** — وإلّا اختفت على نصف
      // الأجهزة: أبيضُ على أبيض، أو أسودُ على أزرقَ داكن.
      expect(main.contains('statusBarIconBrightness'), isTrue,
          reason: 'لم يُحدَّد سطوعُ أيقونات شريط الحالة');
      expect(main.contains('systemNavigationBarIconBrightness'), isTrue,
          reason: 'لم يُحدَّد سطوعُ أيقونات شريط التنقّل');
    });

    test('وثيما أندرويد يلوّنانهما قبل أوّل إطار', () {
      // لحظةُ الإقلاع تسبق أوّلَ إطارٍ من فلاتر؛ فبلا ضبطٍ في الثيم
      // يرى المستخدمُ الشريطين أسودين ثمّ يتغيّران — وميضٌ يُقرأ عطلاً.
      for (final path in const [
        'android/app/src/main/res/values/styles.xml',
        'android/app/src/main/res/values-night/styles.xml',
      ]) {
        final xml = _read(path);
        expect(xml.contains('android:statusBarColor'), isTrue,
            reason: '$path لا يلوّن شريطَ الحالة');
        expect(xml.contains('android:navigationBarColor'), isTrue,
            reason: '$path لا يلوّن شريطَ التنقّل');
      }
    });
  });

  // ══════════════════════════════════════════════════════════════════
  //  ④ «المفترض تحميل السند لا كلّ خيارات الطباعة — تلك للتاجر والوكيل»
  // ══════════════════════════════════════════════════════════════════

  group('الشكوى ④ — سندُ العميل لا يعرض خيارات التاجر', () {
    test('الطباعةُ الحراريّةُ خلف «فاتورةُ تاجر» لا تُعرض لكلّ سند', () {
      final src = _strip(_read(
        'lib/features/receipts/screens/receipt_detail_screen.dart'));

      // النوعُ يُقرأ من المستند لا من دور المستخدم — فموظّفُ التاجر
      // قد يفتح سنداً شخصيّاً، والعميلُ لا يفتح فاتورةَ متجر أبداً.
      expect(src.contains('isMerchantInvoice'), isTrue,
          reason: 'لا تمييزَ بين سندِ عمليّةٍ وفاتورةِ تاجر — فتُعرض '
              'خياراتُ الطابعة الحراريّة لكلّ من يفتح سنداً');

      for (final option in const ['طباعة مباشرة', 'PDF حراري 80', 'PDF حراري 58']) {
        expect(src.contains(option), isTrue,
            reason: 'اختفى خيارُ «$option» كلّيّاً — والتاجرُ يحتاجه');
      }

      // ══════════════════════════════════════════════════════════════
      // **ويُسأل: أهي خلف الشرط؟ لا: أيوجد الشرطُ في الملفّ؟**
      //
      // كان هذا يسأل `hasMatch('if (isInvoice)')` — أي «هل تردُ العبارةُ
      // في مكانٍ ما». وجُرّب بالعكس فمرّ: أُزيل الشرطُ عن «طباعة مباشرة»
      // وبقي على غيرها، **فوجد الحارسُ العبارةَ ومرّ والعطلُ قائم**.
      //
      // فالسؤالُ الصحيح: أيسبق الشرطُ كلَّ خيارِ طباعةٍ مباشرةً؟
      // ══════════════════════════════════════════════════════════════
      for (final gated in const ['طباعة مباشرة', 'PDF حراري 80', 'PDF حراري 58']) {
        final at = src.indexOf(gated);
        final before = src.substring(0, at);

        // **أقربُ شرطٍ سابقٍ لا نافذةٌ بعددِ محارف.** نافذةٌ ثابتةٌ
        // أسقطت الخيارَ الثالثَ وهو **داخل** الشرط: فالحدُّ اعتباطيٌّ
        // يقيس المسافةَ لا البنية.
        final lastIf = before.lastIndexOf(RegExp(r'if\s*\('));
        final gate = lastIf < 0 ? '' : before.substring(lastIf, lastIf + 32);

        expect(RegExp(r'if\s*\(isInvoice\)').hasMatch(gate), isTrue,
            reason: 'خيارُ «$gated» ليس خلف شرطِ «فاتورةُ تاجر» — '
                'فيراه العميلُ في سنده، وهو ما اشتُكي منه حرفيّاً: '
                '«المفترض يكون تحميل السند و ليس كل هذه الخيارات '
                'للطباعة هذه تخص التاجر و الوكيل»');
      }
    });
  });

  // ══════════════════════════════════════════════════════════════════
  //  ② «لا استطيع تحميل كشف بي دي اف»
  // ══════════════════════════════════════════════════════════════════

  group('الشكوى ② — تحميل الـPDF', () {
    test('لا يُكتب في مجلّد التنزيلات العامّ — محظورٌ منذ أندرويد 10', () {
      final src = _strip(_read('lib/helper/pdf_downloader_helper.dart'));

      expect(src.contains('/storage/emulated/0/Download'), isFalse,
          reason: 'الكتابةُ هناك تُلقي `FileSystemException` على أندرويد '
              '10 فما فوق (‏scoped storage) — فيُقال «فشل معالجة PDF» '
              'والسببُ إذنٌ لا عطلُ ملفّ');

      expect(src.contains('getExternalStorageDirectory'), isTrue,
          reason: 'لا يُستعمل مجلّدُ التطبيق الخارجيّ — وهو الوحيدُ الذي '
              'لا يحتاج إذناً على كلّ الإصدارات');
    });

    test('والبيانُ يُعلن رؤيةَ قارئ PDF — وإلّا حُفظ الملفُّ ولم يُفتح', () {
      final xml = _read('android/app/src/main/AndroidManifest.xml');

      final queries = xml.substring(
        xml.indexOf('<queries>'), xml.indexOf('</queries>'));

      expect(queries.contains('application/pdf'), isTrue,
          reason: '**منذ أندرويد 11 لا يرى التطبيقُ قارئاً لم يُعلنه هنا**، '
              'فيعود `resolveActivity` فارغاً: يُنزَّل الملفُّ بنجاح ثمّ '
              'لا يُفتح، ولا خطأَ في أيّ سجلّ');
    });

    test('وفشلُ الفتح يُقال للمستخدم ولا يُبتلع', () {
      final src = _strip(_read('lib/helper/pdf_downloader_helper.dart'));

      expect(src.contains('showCustomSnackBarHelper'), isTrue,
          reason: 'يُضغط «تحميل» فلا يُرى ولا يُسمع شيء — والملفُّ محفوظٌ '
              'على الجهاز. وصمتٌ بعد ضغطةٍ يُقرأ عطلاً في التطبيق كلِّه');
    });
  });
}

// ══════════════════════════════════════════════════════════════════════
//  أدوات
// ══════════════════════════════════════════════════════════════════════

String _read(String path) => File(path).readAsStringSync();

/// يُزيل التعليقاتِ قبل البحث — **فالكلمةُ في تعليقٍ يصف الميزةَ ليست
/// شيفرةً تُنفّذها**، وقد مرّ حارسٌ في هذا المشروع على عطلٍ قائمٍ لهذا
/// السبب بعينه.
String _strip(String src) => src
    .replaceAll(RegExp(r'/\*.*?\*/', dotAll: true), '')
    .split('\n')
    .map((l) {
      final i = l.indexOf('//');
      return i < 0 ? l : l.substring(0, i);
    })
    .join('\n');

/// توكِناتُ اللون تُقرأ من `AmialColors` — **ولا تُكتب هنا نسخةٌ ثانية**،
/// فنسختان لحقيقةٍ واحدةٍ تفترقان أوّلَ ما يتغيّر أحدُهما.
Map<String, String> _tokens() {
  final src = _read('lib/theme/amial_colors.dart');
  final out = <String, String>{};
  for (final m in RegExp(r'static const Color (\w+) = Color\(0xFF([0-9A-Fa-f]{6})\)')
      .allMatches(src)) {
    out[m.group(1)!] = m.group(2)!.toUpperCase();
  }
  return out;
}

double _luminance(String hex) {
  double channel(int v) {
    final c = v / 255;
    return c <= 0.03928 ? c / 12.92 : math.pow((c + 0.055) / 1.055, 2.4).toDouble();
  }

  final r = channel(int.parse(hex.substring(0, 2), radix: 16));
  final g = channel(int.parse(hex.substring(2, 4), radix: 16));
  final b = channel(int.parse(hex.substring(4, 6), radix: 16));
  return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

double _contrast(String a, String b) {
  final la = _luminance(a), lb = _luminance(b);
  final hi = la > lb ? la : lb, lo = la > lb ? lb : la;
  return (hi + 0.05) / (lo + 0.05);
}
