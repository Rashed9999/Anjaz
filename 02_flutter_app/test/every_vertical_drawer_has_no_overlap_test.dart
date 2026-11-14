import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

/// AMIAL-VERTICAL-DRAWER-001 — **لا قسمانِ في درجٍ واحدٍ يقودان إلى الشيء
/// نفسِه، والستّةُ تُقاس لا واحد.**
///
/// ══════════════════════════════════════════════════════════════════════
/// **السؤالُ كما وصل، وهو عادل:**
///
///     «لماذا قبل الإصلاحات تدّعي لا يوجد مشاكل هذه الصيدلية؟ وربما انتقل
///      كودكس إلى قطاع تاجرٍ آخر — فماذا تقول عن القطاعات الأخرى؟ هل هي
///      سليمةٌ وخاليةٌ من التداخل والمشاكل، أم تفضّل أن يكتشفها كودكس؟»
///
/// **والجوابُ الصادق: كنتُ أقرأ ولا أقيس، وقراءتي أخطأت مرّتين.**
///
/// عدَدتُ أقسامَ الدرج بنصٍّ يقطع عند `case` التالية **ولا يعرف
/// `default:`**، فسال قسمانِ من الفرع العامّ إلى حساب التجزئة فقلتُ
/// «**وجدتُ تداخلاً في التجزئة**: ثلاثةُ أقسامٍ للأصناف والمخزون» —
/// **ولا تداخلَ فيها**. وقلتُ «الوقودُ لا فرعَ له، يقع على القالب
/// المشترك» — **وله فرعُه**، لكنّه في `_activityItems` طبقةً أعلى، فلا
/// يبلغ `_sectionsForBusiness` أصلاً.
///
/// **وكلا الخطأين من صنفٍ واحد: ادّعاءٌ من قراءةٍ لا من قياس.** فصار
/// القياسُ حارساً يجري في كلّ بوّابة، لا جملةً في تقرير. (القاعدة
/// الثالثة: يُقاس ثمّ يُقال.)
///
/// ══════════════════════════════════════════════════════════════════════
/// **وما يحرسه ليس التسمية بل التداخل نفسُه:** قسمانِ في درجِ قطاعٍ
/// واحدٍ يطلبان المجموعةَ نفسَها **يفتحان القائمةَ نفسَها بعنوانين**.
/// ولا خطأَ في أيّ سجلّ، ولا يقوله مُصرِّفٌ ولا محلِّل — يُضغط البابان
/// فتصل الشاشةُ نفسُها، فيُقال «التطبيقُ يعيد نفسَه». وهو ما وصل عن
/// الصيدليّة بالضبط.
void main() {
  final file = File(
    'lib/features/merchant/screens/merchant_adaptive_shell.dart',
  );

  late String src;

  setUpAll(() {
    expect(file.existsSync(), isTrue, reason: 'مصدرُ الدرج مفقود: ${file.path}');
    src = file.readAsStringSync();
  });

  /// جسمُ دالّةٍ بأقواسها المتوازنة — لا بأوّل `}` يصادَف.
  String bodyOf(String signature) {
    final start = src.indexOf(signature);
    expect(start, isNot(-1), reason: 'لم تُوجد الدالّة: $signature');

    var i = src.indexOf('{', start);
    var depth = 0;
    final open = i;

    for (; i < src.length; i++) {
      if (src[i] == '{') depth++;
      if (src[i] == '}') {
        depth--;
        if (depth == 0) return src.substring(open, i + 1);
      }
    }

    fail('قوسٌ غيرُ مغلقٍ في: $signature');
  }

  /// **الفصلُ على `case` و`default` معاً** — وإغفالُ الثانية هو بعينه ما
  /// أنتج ادّعاءَ «تداخلِ التجزئة» الذي لا وجودَ له.
  Map<String, String> caseBlocks(String body) {
    final marks = RegExp(r"""case '([a-z_]+)':|(default):""")
        .allMatches(body)
        .toList();

    final out = <String, String>{};

    for (var i = 0; i < marks.length; i++) {
      final name = marks[i].group(1) ?? marks[i].group(2)!;
      final end = i + 1 < marks.length ? marks[i + 1].start : body.length;
      out[name] = body.substring(marks[i].end, end);
    }

    return out;
  }

  /// قائمةٌ حرفيّةٌ داخل `groups:` أو `codes:`.
  List<String> listArg(String section, String name) {
    final m = RegExp('$name:\\s*\\[([^\\]]*)\\]').firstMatch(section);
    if (m == null) return const [];

    return RegExp(r"'([^']+)'")
        .allMatches(m.group(1)!)
        .map((e) => e.group(1)!)
        .toList();
  }

  /// أقسامُ فرعٍ واحد: العنوانُ ومطلبُه — والمشتركةُ تُحلّ من تعريفها.
  List<({String title, List<String> claims})> sectionsIn(
    String block,
    String body,
  ) {
    final out = <({String title, List<String> claims})>[];

    // ① الأقسامُ المصرَّحةُ في مكانها.
    for (final m
        in RegExp(r'_MerchantDrawerSection\(').allMatches(block)) {
      var i = block.indexOf('(', m.start);
      var depth = 0;
      final open = i;

      for (; i < block.length; i++) {
        if (block[i] == '(') depth++;
        if (block[i] == ')') {
          depth--;
          if (depth == 0) break;
        }
      }

      final s = block.substring(open, i + 1);
      final t = RegExp(r"title:\s*'([^']+)'").firstMatch(s);
      if (t == null) continue;

      out.add((
        title: t.group(1)!,
        claims: [
          ...listArg(s, 'groups').map((g) => 'مجموعة «$g»'),
          ...listArg(s, 'codes').map((c) => 'رمز «$c»'),
        ],
      ));
    }

    // ② والمشتركةُ تُذكر باسمها وحدَه — `sale` · `people` · `reports`.
    //    فتُقرأ من تعريفها، وإلّا عُدّ الفرعُ أقصرَ ممّا هو ومرّ التداخل.
    for (final name in const ['sale', 'people', 'reports']) {
      if (!RegExp('(^|[\\s\\[])$name,').hasMatch(block)) continue;

      final d = RegExp(
        'const $name = _MerchantDrawerSection\\(',
      ).firstMatch(body);
      if (d == null) continue;

      var i = body.indexOf('(', d.start);
      var depth = 0;
      final open = i;

      for (; i < body.length; i++) {
        if (body[i] == '(') depth++;
        if (body[i] == ')') {
          depth--;
          if (depth == 0) break;
        }
      }

      final s = body.substring(open, i + 1);

      out.add((
        title: RegExp(r"title:\s*'([^']+)'").firstMatch(s)!.group(1)!,
        claims: [
          ...listArg(s, 'groups').map((g) => 'مجموعة «$g»'),
          ...listArg(s, 'codes').map((c) => 'رمز «$c»'),
        ],
      ));
    }

    return out;
  }

  // ═══════════════════════════════════════════════════════════════════

  /// **① لا قسمانِ في قطاعٍ واحدٍ يطلبان الشيءَ نفسَه.**
  test('لا بابانِ في درجِ قطاعٍ واحدٍ يفتحان القائمةَ نفسَها', () {
    final body = bodyOf('List<_MerchantDrawerSection> _sectionsForBusiness()');
    final blocks = caseBlocks(body);

    expect(blocks, isNotEmpty, reason: 'لم يُقرأ فرعٌ واحد — والقياسُ فارغ.');

    final clashes = <String>[];

    blocks.forEach((vertical, block) {
      final seen = <String, String>{};

      for (final s in sectionsIn(block, body)) {
        for (final claim in s.claims) {
          final first = seen[claim];

          if (first != null) {
            clashes.add('  «$vertical»: $claim يطلبه «$first» و«${s.title}»');
          } else {
            seen[claim] = s.title;
          }
        }
      }
    });

    expect(clashes, isEmpty,
        reason: '**أقسامٌ متداخلةٌ في درجٍ واحد:**\n${clashes.join('\n')}\n\n'
            'والقسمانِ يطلبان المجموعةَ نفسَها ⇒ **يفتحان القائمةَ '
            'نفسَها بعنوانين**. ولا خطأَ في أيّ سجلّ: يُضغط البابان '
            'فتصل الشاشةُ نفسُها.');
  });

  /// **② وكلُّ قسمٍ يطلب شيئاً — لا قسمَ بلا مطلب.**
  ///
  /// فقسمٌ بلا مجموعةٍ ولا رمزٍ يفتح قائمةً فارغةً أبداً: بابٌ مبنيٌّ
  /// يقود إلى «لا خدمات متاحة لهذا القسم». (القاعدة الثانية عشرة.)
  test('ولا قسمَ يفتح فراغاً', () {
    final body = bodyOf('List<_MerchantDrawerSection> _sectionsForBusiness()');
    final empty = <String>[];

    caseBlocks(body).forEach((vertical, block) {
      for (final s in sectionsIn(block, body)) {
        if (s.claims.isEmpty) empty.add('  «$vertical» ← «${s.title}»');
      }
    });

    expect(empty, isEmpty,
        reason: '**أقسامٌ بلا مجموعةٍ ولا رمز:**\n${empty.join('\n')}\n\n'
            'وهي تفتح «لا خدمات متاحة لهذا القسم» أبداً — بابٌ مبنيٌّ '
            'ولا يُوصَل منه إلى شيء.');
  });

  /// **③ والوقودُ يُقاس حيث هو — لا حيث ظننتُه.**
  ///
  /// ══════════════════════════════════════════════════════════════════
  /// قلتُ «الوقودُ لا فرعَ له فيقع على القالب المشترك» **وهو خطأ**:
  /// فرعُه في `_activityItems` طبقةً أعلى، فلا يبلغ
  /// `_sectionsForBusiness` أصلاً. **فيُثبَّت موضعُه بحارس** لئلّا
  /// يُنقَل يوماً فيسقط في القالب العامّ صامتاً — وحينها يرى صاحبُ
  /// المحطّة «المنتجات والمخزون» مكانَ مضخّاته.
  /// ══════════════════════════════════════════════════════════════════
  test('والوقودُ له فرعُه، وكلُّ بابٍ فيه إلى شاشةٍ غيرِ أختِها', () {
    final body = bodyOf('List<Widget> _activityItems(BuildContext context)');

    expect(body, contains("access.businessType.value == 'fuel'"),
        reason: '**سقط فرعُ الوقود من `_activityItems`** — فيقع على '
            'القالب العامّ، ويرى صاحبُ المحطّة «المنتجات والمخزون» '
            'مكانَ مضخّاته وخزّاناته.');

    final fuel = body.substring(
      body.indexOf("access.businessType.value == 'fuel'"),
      body.indexOf('return _sectionsForBusiness()'),
    );

    final doors = RegExp(r"label: '([^']+)'").allMatches(fuel).length;
    final screens = RegExp(r'const (Fuel\w+Screen)\(\)')
        .allMatches(fuel)
        .map((m) => m.group(1)!)
        .toList();

    expect(doors, greaterThanOrEqualTo(8),
        reason: 'تقلّصت أبوابُ المحطّة إلى $doors — ومحطّةُ الوقود '
            'أعرضُ من ذلك: مضخّاتٌ وخزّاناتٌ وورديّاتٌ وأسعارٌ وشركات.');

    expect(screens.length, doors,
        reason: '**بابٌ في درج المحطّة لا شاشةَ له**: $doors باباً '
            'و${screens.length} شاشة.');

    expect(screens.toSet().length, screens.length,
        reason: '**بابانِ في درج المحطّة يفتحان الشاشةَ نفسَها:** '
            '${screens.where((s) => screens.where((x) => x == s).length > 1).toSet().join('، ')}\n'
            'وهو عينُ ما يُشتكى منه: «التطبيقُ يعيد نفسَه».');
  });

  /// **④ والقطاعاتُ الستّةُ كلُّها مقيسة — لا أربعةٌ منها.**
  ///
  /// فقطاعٌ خارج القياس يُقرأ سليماً وهو غيرُ مفحوص، **والصمتُ ليس
  /// سلامة**. (القاعدة السابعة: «غير معروف» ليس صفراً.)
  test('والستّةُ كلُّها داخلَ القياس', () {
    final body = bodyOf('List<_MerchantDrawerSection> _sectionsForBusiness()');
    final covered = caseBlocks(body).keys.toSet();

    // الوقودُ مخدومٌ في `_activityItems`، والباقي هنا أو في `default`.
    final missing = const ['pharmacy', 'wholesale', 'restaurant', 'quick_sale',
            'retail']
        .where((v) => !covered.contains(v))
        .toList();

    expect(missing, isEmpty,
        reason: '**قطاعاتٌ بلا فرعٍ خاصٍّ في الدرج: ${missing.join('، ')}** — '
            'فتقع على القالب العامّ، ويرى صاحبُها أقساماً ليست حرفتَه.');

    expect(covered.contains('default'), isTrue,
        reason: 'سقط الفرعُ العامّ — ونشاطٌ لا يطابق أيَّ `case` يُخرج '
            'درجاً فارغاً.');
  });
}
