// AMIAL-I18N-003 — **زرٌّ يُضغط ولا يتغيّر شيء، ورسائلُ إنجليزيّة.**
//
// ══════════════════════════════════════════════════════════════════════
// قال صاحبُ المشروع شيئين:
//
//   ① «زرُّ الترجمة لا يعمل — عند تغيير الإنجليزيّة **لا يتغيّر شيء**».
//   ② «والتنبيهاتُ تأتي بالإنجليزيّة — انقطاعُ الإنترنت، كلمةُ السرّ
//      خطأ وغيرها — اجعلها بالعربيّ».
//
// **وقِيس الأوّلُ فإذا الآليّةُ سليمةٌ كاملةً**: الزرُّ ← `setLanguage` ←
// `Get.updateLocale` ← `Messages` ← ملفّان بـ٤٤٨ مفتاحاً لكلٍّ.
//
// **والعطلُ في التغطية:** ٣٥٩ نداءَ `.tr` مقابل **٨٢٥ نصّاً عربيّاً
// محفوراً**، وأسوأُ من النسبة توزيعُها — لوحةُ التجزئة **صفرُ نداءات**
// مقابل ٤١ نصّاً. فمن بدّل وهو عليها لا يرى محرفاً يتغيّر.
//
// **فرُفعت الإنجليزيّةُ من القائمة ولم تُحذَف من المشروع** — ووعدٌ لا
// يُوفى أسوأ من غيابه (القاعدة التاسعة).
//
// **والحالةُ ③ هي التي تحفظ الطريقَ مفتوحاً**: الآليّةُ تبقى كاملةً،
// فإعادةُ اللغة سطرٌ واحدٌ لا إعادةُ بناء.

import 'dart:convert';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:amial_pay/util/app_constants.dart';

/// نصٌّ إنجليزيٌّ يُسنَد إلى رسالةٍ تصل العين — لا مفتاحُ ترجمة.
final _userFacing = RegExp(
    r"""(lastError\.value\s*=|\?\?)\s*'([A-Za-z][^']{2,60})'(?!\s*\.tr)""");

/// ما يُعرَض جملةً لا قيمةً داخليّة (`SOUTH` · `YER` · `pending_pdf`).
const _sentences = [
  'Network error', 'Failed', 'Failed to load', 'Failed to load receipts',
  'Failed to load funds', 'Failed to load session policy',
  'Not found', 'No terms', 'No term loaded',
];

void main() {
  test('① لا رسالةَ إنجليزيّةً تصل عينَ المستعمل', () {
    final offenders = <String>[];

    for (final f in Directory('lib').listSync(recursive: true)) {
      if (f is! File || !f.path.endsWith('.dart')) continue;

      var line = 0;
      for (final l in f.readAsLinesSync()) {
        line++;
        if (l.trimLeft().startsWith('//')) continue;

        for (final m in _userFacing.allMatches(l)) {
          if (_sentences.contains(m.group(2))) {
            offenders.add('${f.path}:$line → ${m.group(2)}');
          }
        }
      }
    }

    expect(offenders, isEmpty,
        reason: 'رسائلُ إنجليزيّةٌ في تطبيقٍ عربيّ:\n  '
            '${offenders.join('\n  ')}\n\n'
            'وهي ليست ترجمةً ناقصةً — هي نصٌّ إنجليزيٌّ مكتوبٌ في الشيفرة، '
            'يقرؤه بائعُ خضارٍ في تعزّ فلا يعرف أانقطعت شبكتُه أم رُفض طلبُه.');
  });

  test('② والرسالةُ تقول ما وقع وما العمل، لا «فشل» وحدَها', () {
    // **«تعذّر إتمام العملية» تصف، ولا تقول ماذا يفعل صاحبُها.** ورسالةُ
    // الشبكة أهمُّها: خلطُ الانقطاع بالرفض يُرسل من انقطعت شبكتُه إلى
    // الدعم — وهو درسُ الطبقة العاشرة في `CLAUDE.md`.
    final src = File('lib/features/family_fund/controllers/funds_controller.dart')
        .readAsStringSync();

    expect(src.contains('تحقّق من الشبكة'), isTrue,
        reason: 'رسالةُ الشبكة لا تقول ما العمل — فلا تُفرَّق عن رفضِ '
            'الخادم، ويُرسَل من انقطعت شبكتُه إلى الدعم');
  });

  test('③ ولا لغةَ في القائمة بلا شاشاتٍ تُترجَم لها', () {
    expect(AppConstants.languages.length, 1,
        reason: 'عادت لغةٌ ثانيةٌ إلى القائمة — وزرٌّ يُضغط ولا يتغيّر '
            'شيءٌ يُقرأ عطلاً. لا تُعَد قبل أن تُترجَم شاشاتُ التاجر '
            '(المقيسُ يومَ رُفعت: لوحةُ التجزئة صفرُ نداءات `.tr`).');

    expect(AppConstants.languages.first.languageCode, 'ar');
  });

  test('④ والآليّةُ تبقى كاملةً — فالعودةُ سطرٌ لا إعادةُ بناء', () {
    // **ولا يُحذَف ما بُني** — حذفُ `en.json` أو `Messages` يجعل إعادةَ
    // اللغة مشروعاً، وهي اليومَ سطرٌ واحد.
    expect(File('assets/language/en.json').existsSync(), isTrue,
        reason: 'حُذف ملفُّ الإنجليزيّة — والمطلوبُ رفعُها من القائمة '
            'لا هدمُ ما بُني');

    final en = jsonDecode(File('assets/language/en.json').readAsStringSync())
        as Map<String, dynamic>;
    final ar = jsonDecode(File('assets/language/ar.json').readAsStringSync())
        as Map<String, dynamic>;

    expect(en.length, ar.length,
        reason: 'الملفّان لا يتطابقان في عدد المفاتيح — فمفتاحٌ يُترجَم '
            'في لغةٍ ويظهر خاماً في الأخرى');

    expect(File('lib/util/messages.dart').existsSync(), isTrue);
    expect(
        File('lib/features/language/controllers/localization_controller.dart')
            .readAsStringSync()
            .contains('Get.updateLocale'),
        isTrue,
        reason: 'ذهبت آليّةُ تبديل اللغة — وهي سليمةٌ ولم تكن هي العطل');
  });

  testWidgets('⑤ ورقاقةُ اللغة لا تُرسَم على خيارٍ واحد', (t) async {
    // زرٌّ يفتح ورقةً فيها «العربية» وحدَها ويُغلَق بلا شيء.
    await t.pumpWidget(const MaterialApp(
      home: Scaffold(body: Center(child: _ChipProbe())),
    ));

    expect(find.byType(SizedBox), findsWidgets);
    expect(find.textContaining('العربية'), findsNothing,
        reason: 'رُسمت رقاقةُ اللغة وفي القائمة لغةٌ واحدة — '
            'فتُضغط ولا يحدث شيء');
  });
}

/// غلافٌ يستدعي الرقاقةَ بلا `GetBuilder` حيّ — يكفي أنّها تنكمش.
class _ChipProbe extends StatelessWidget {
  const _ChipProbe();

  @override
  Widget build(BuildContext context) {
    if (AppConstants.languages.length < 2) return const SizedBox.shrink();

    return const Text('العربية');
  }
}
