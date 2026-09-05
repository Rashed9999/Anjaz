// AMIAL-I18N-004 — **جولةُ الترجمة: ما بلغته، وما يحرسها من الانتكاس.**
//
// ══════════════════════════════════════════════════════════════════════
// قال صاحبُ المشروع «أبدِ الآن» على جولةٍ مخصَّصةٍ للترجمة. وجرت،
// **وأخرجت ثلاثةَ أعطالٍ كلُّها من صنعِ الأداة التي كُتبت لتتجنّبها.**
//
// والأداةُ نفسُها كانت جواباً للقاعدة الخامسة (تعديلٌ نمطيٌّ جشع قطع
// دالّةَ سهمٍ في هذا المشروع سلفاً). **فصنعت أعطالاً من صنفٍ آخر:**
//
//   ① `const Text('نصّ'.tr)` — و`.tr` جالبٌ يُنفَّذ، و`const` تُحسب وقتَ
//      التصريف. **لا يُصرَّف إطلاقاً.** وأمسكه المحلّل.
//
//   ② `'شطرٌ أوّل '.tr 'وشطرٌ ثانٍ'.tr` — والجملةُ المقطوعةُ على سطرين
//      جملةٌ واحدةٌ تلصقها Dart. **لا تُصرَّف** — وأمسكه المحلّل.
//
//   ③ `'…${plan['adds_count']} قدرة:'` — وقع داخلَ الإقحام حرفان
//      مفردان، فاستُخرج `]} قدرة:` مفتاحاً. **وهذا يُصرَّف** — فلا
//      يمسكه محلّلٌ ولا مُصرِّف، ويعرض النصّ صحيحاً بالمصادفة، ويترك
//      مفتاحاً خرِباً في ملفّ اللغة ونصّاً لا يُترجَم أبداً.
//
// **والثالثُ أخطرُها، وهو وحدَه ما يحتاج حارساً**: الأوّلان يسقطان في
// البوّابة، والثالثُ يمرّ صامتاً. (وهو نمطُ المشروع كلِّه: العطلُ الذي
// يُصرَّف أخطرُ من الذي لا يُصرَّف.)

import 'dart:convert';
import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

/// نصٌّ عربيٌّ محفورٌ يصل العين — غيرُ مُقحَمٍ وغيرُ متبوعٍ بـ`.tr`.
final _hard = RegExp(r"'((?:[^'\\$]|\\.)*[؀-ۿ](?:[^'\\$]|\\.)*)'(?!\s*\.tr)");

/// ما ليس نصّاً يُقرأ: مسارُ أصلٍ، أو مفتاحٌ تقنيّ، أو إقحام.
const _notText = ['assets/', 'package:', 'http', 'Key(', 'key:', r'${'];

int _hardCodedIn(File f) {
  var n = 0;
  for (final l in f.readAsLinesSync()) {
    final s = l.trimLeft();
    if (s.startsWith('//') || s.startsWith('*') || s.startsWith('/*')) continue;
    if (_notText.any(l.contains)) continue;
    n += _hard.allMatches(l).length;
  }
  return n;
}

final _holes = RegExp(r'\$\{[^}]*\}|\$\w+');

/// متونُ الحروف النصّيّة المتبوعةِ بـ`.tr` في سطرٍ واحد.
///
/// **ولا يكفي تعبيرٌ نمطيّ هنا** — وقد جُرّب فسقط: في
///
///     '${'pay_with'.tr} ${AppConstants.appName}'.tr
///
/// داخلَ الحرف الخارجيّ **حرفٌ آخرُ مفرد**، فيرى المُطابِقُ المسطَّح
/// `'pay_with'.tr` وحدَه ويعدّه سليماً (وهو سليم)، **ويعمى عن الخارجيّ
/// وهو العطل**. فيُمشى على السطر حرفاً حرفاً بعدّ عمق `${`، ويُتخطّى ما
/// بداخله. (القاعدة الثانية: جُرّب الحارسُ بالعكس فمرّ، فأُعيد بناؤه.)
List<String> _translatedLiterals(String src) {
  final out = <String>[];
  var i = 0;

  while (i < src.length) {
    final q = src[i];
    if (q != "'" && q != '"') {
      i++;
      continue;
    }

    var j = i + 1;
    var depth = 0;

    while (j < src.length) {
      final c = src[j];

      if (c == r'\') {
        j += 2;
        continue;
      }
      if (depth == 0 && c == q) break;
      if (c == r'$' && j + 1 < src.length && src[j + 1] == '{') {
        depth++;
        j += 2;
        continue;
      }
      if (depth > 0 && c == '}') {
        depth--;
        j++;
        continue;
      }
      // حرفٌ نصّيٌّ متداخلٌ داخل الإقحام — يُقفَز فوقه كتلةً واحدة.
      if (depth > 0 && (c == "'" || c == '"')) {
        var k = j + 1;
        while (k < src.length && src[k] != c) {
          k += src[k] == r'\' ? 2 : 1;
        }
        j = k + 1;
        continue;
      }
      j++;
    }

    if (j < src.length) {
      final after = src.substring(j + 1);
      if (RegExp(r'^\s*\.tr\b').hasMatch(after)) {
        out.add(src.substring(i + 1, j));
      }
      i = j + 1;
    } else {
      i++;
    }
  }

  return out;
}

/// الشاشاتُ الخمسُ التي قِيست يومَ بدأت الجولة، وهي التي يقف عليها التاجر.
const _theFive = [
  'lib/features/access/screens/role_based_home_screens.dart',
  'lib/features/merchant/screens/merchant_services_hub_screen.dart',
  'lib/features/plans/screens/plans_catalog_screen.dart',
  'lib/features/merchant/screens/cashier_pos_screen.dart',
  'lib/features/pharmacy/screens/pharmacy_dashboard_screen.dart',
];

void main() {
  test('① الشاشاتُ الخمسُ لا يبقى فيها نصٌّ محفور', () {
    final left = <String>[];

    for (final p in _theFive) {
      final n = _hardCodedIn(File(p));
      if (n > 0) left.add('$p → $n');
    }

    expect(left, isEmpty,
        reason: 'عاد نصٌّ محفورٌ إلى شاشةٍ تُرجمت:\n  ${left.join('\n  ')}\n\n'
            'وهي الشاشاتُ التي قِيس عليها العطلُ المُبلَّغ — لوحةُ التجزئة '
            'كانت صفرَ نداءات `.tr` مقابل ٤١ نصّاً. '
            'استعمل `tool/i18n_extract.py` ولا تكتب النصَّ في الشيفرة.');
  });

  test('② ولا `.tr` على نصٍّ مُقحَم — فهو مفتاحٌ لا يُطابَق أبداً', () {
    // **العطلُ الذي يُصرَّف.** `'مرحباً $name'.tr` تُرجع النصَّ المركَّب
    // نفسَه لأنّ لا مفتاحَ يساويه، فتعرض عربيّةً صحيحةً ولا تُترجَم — ولا
    // خطأَ في أيّ سجلّ. والصحيحُ `trParams` أو فصلُ الثابت عن المتغيّر.
    //
    // **وحرفٌ كلُّه إقحامٌ واحدٌ ليس منها**: `'${x.type}'.tr` مفتاحٌ
    // ديناميكيّ — النتيجةُ هي الرمزُ نفسُه فتُطابَق مفتاحاً حقيقيّاً. وهو
    // استعمالٌ سليمٌ قائمٌ في المشروع، وحارسٌ يمسكه يُسقط سليماً.
    //
    // **فالحدُّ: نصٌّ ثابتٌ إلى جانب الإقحام** — عندها لا يساوي المركَّبُ
    // مفتاحاً أبداً. (وأوّلُ تشغيلٍ أمسك الحالتين معاً، فقِيستا واحدةً
    // واحدة: الأولى سليمة، والثانية `'${'pay_with'.tr} …'.tr` عطلٌ حقيقيّ.)
    final offenders = <String>[];

    for (final f in Directory('lib').listSync(recursive: true)) {
      if (f is! File || !f.path.endsWith('.dart')) continue;

      var line = 0;
      for (final l in f.readAsLinesSync()) {
        line++;
        if (l.trimLeft().startsWith('//')) continue;

        for (final body in _translatedLiterals(l)) {
          if (!body.contains(r'$')) continue;

          final holes = _holes.allMatches(body).length;
          if (holes == 0) continue;

          // **والشكلُ الوحيدُ السليم: إقحامٌ واحدٌ ولا شيءَ معه** — فتكون
          // النتيجةُ الرمزَ نفسَه فتُطابَق مفتاحاً.
          //
          // وما عداه لا يساوي مفتاحاً أبداً: إقحامان بينهما مسافةٌ يُنتجان
          // «Pay with أميال باي»، ولا مفتاحَ بهذا الاسم. **والمسافةُ وحدَها
          // تكفي** — وقد جُرّب الحارسُ بالعكس فمرّ لأنّي كنتُ أقصّها
          // بـ`trim` قبل الحكم.
          if (holes == 1 && body.replaceAll(_holes, '').isEmpty) continue;

          offenders.add('${f.path}:$line');
        }
      }
    }

    expect(offenders, isEmpty,
        reason: 'نصٌّ مُقحَمٌ عليه `.tr`:\n  ${offenders.join('\n  ')}\n\n'
            '`.tr` تُرجع المفتاحَ حين يغيب، والنصُّ المركَّبُ لا يساوي '
            'مفتاحاً أبداً — فيُعرَض صحيحاً بالمصادفة ولا يُترجَم، ويُكتب '
            'في ملفّ اللغة مفتاحٌ خرِب. استعمل `trParams`.');
  });

  test('③ والملفّان يتطابقان مفتاحاً بمفتاح، لا عدداً فحسب', () {
    // **العددُ وحدَه يمرّ على ملفّين مختلفين تماماً.** فيُقاس التطابق.
    final ar = (jsonDecode(File('assets/language/ar.json').readAsStringSync())
        as Map<String, dynamic>);
    final en = (jsonDecode(File('assets/language/en.json').readAsStringSync())
        as Map<String, dynamic>);

    final missingEn = ar.keys.where((k) => !en.containsKey(k)).toList();
    final missingAr = en.keys.where((k) => !ar.containsKey(k)).toList();

    expect(missingEn, isEmpty,
        reason: 'مفاتيحُ بلا إنجليزيّة (تُعرَض خاماً يومَ تعود اللغة):\n  '
            '${missingEn.take(20).join('\n  ')}');
    expect(missingAr, isEmpty,
        reason: 'مفاتيحُ بلا عربيّة:\n  ${missingAr.take(20).join('\n  ')}');

    // **ولا مفتاحَ فارغُ الترجمة** — وهو غيابٌ بثوب حضور (القاعدة السابعة).
    final blank = en.entries
        .where((e) => (e.value as String).trim().isEmpty)
        .map((e) => e.key)
        .toList();
    expect(blank, isEmpty,
        reason: 'مفاتيحُ إنجليزيّتُها فارغة:\n  ${blank.take(20).join('\n  ')}');
  });

  test('④ ولا اتّجاهَ مفروضٌ في الشيفرة — يُسأل من اللغة', () {
    // كان في ١٣ موضعاً، فالشاشةُ تبقى من اليمين **حتّى لو تُرجم نصُّها**.
    final offenders = <String>[];

    for (final f in Directory('lib').listSync(recursive: true)) {
      if (f is! File || !f.path.endsWith('.dart')) continue;
      if (f.path.endsWith('app_direction.dart')) continue;

      var line = 0;
      for (final l in f.readAsLinesSync()) {
        line++;
        if (l.trimLeft().startsWith('//') || l.trimLeft().startsWith('///')) {
          continue;
        }
        if (l.contains('textDirection: TextDirection.rtl')) {
          offenders.add('${f.path}:$line');
        }
      }
    }

    expect(offenders, isEmpty,
        reason: 'اتّجاهٌ مفروضٌ رقماً ثابتاً:\n  ${offenders.join('\n  ')}\n\n'
            'استعمل `appTextDirection()` — تقرأ `LocalizationController.isLtr` '
            'وتسقط على العربيّة حين لا مُتحكِّمَ في الشجرة.');
  });

  test('⑤ والعتبةُ تُقاس، فلا تبقى «يوماً ما»', () {
    // **الرقمُ هو الذي يقرّر متى تعود الإنجليزيّة** — لا الشعورُ بأنّ
    // «الشاشاتِ المهمّةَ تُرجمت». وهذا الاختبارُ يقول كم بقي في كلّ جولة.
    var total = 0;
    final worst = <String, int>{};

    for (final f in Directory('lib').listSync(recursive: true)) {
      if (f is! File || !f.path.endsWith('.dart')) continue;
      final n = _hardCodedIn(f);
      total += n;
      if (n > 0) worst[f.path] = n;
    }

    final top = worst.entries.toList()
      ..sort((a, b) => b.value.compareTo(a.value));

    // **حارسُ انتكاسٍ لا حارسُ هدف**: يمنع أن يعود المحفورُ يتضخّم بين
    // الجولات. والرقمُ مقيسٌ يومَ كُتب: ٤٥٣٤.
    expect(total, lessThanOrEqualTo(4534),
        reason: 'ازداد النصُّ المحفور — كُتبت نصوصٌ جديدةٌ في الشيفرة بدل '
            'المفاتيح. الأثقلُ:\n  '
            '${top.take(10).map((e) => '${e.value}  ${e.key}').join('\n  ')}');

    // **وحين يهبط دون ٥٠٠ تُعاد الإنجليزيّةُ ويسقط هذا السطر** — فيُقرأ
    // الحارسُ تذكيراً لا عقبة.
    if (total < 500) {
      fail('هبط المحفورُ إلى $total — دون العتبة. '
          'أعِد سطرَ الإنجليزيّة في `AppConstants.languages`، '
          'وحدِّث الحالةَ ③ في `no_english_reaches_the_eye_test`، '
          'واحذف هذا الشرط.');
    }
  });
}
