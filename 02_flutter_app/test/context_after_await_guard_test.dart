import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

/// AMIAL-CTX-AFTER-AWAIT-001 — **سياقٌ يُستعمَل بعد انتظارٍ بلا حارسه.**
///
/// ══════════════════════════════════════════════════════════════════════
/// **العطل، وقد قِيس على شيفرةٍ حقيقيّةٍ لا فُرض:**
///
///     final ok = await c.recordCollection(...);   ← تحصيلُ مال
///     if (!mounted) return;                       ← حالةُ الودجة
///     if (ok) Navigator.pop(ctx);                 ← سياقُ **حوار**
///
/// و`mounted` حالةُ الودجة، و`ctx` سياقُ حوارٍ أو ورقةٍ سفليّة **قد
/// يُغلَق أثناء انتظار الشبكة**. فمن ضغط ثمّ سحب الورقةَ لأسفل — وهو ما
/// يقع كثيراً على شبكةٍ يمنيّةٍ بطيئة — يُنتج `Navigator.pop` على سياقٍ
/// ميّت: **يرمي، أو يُغلق الصفحةَ التي تحته**.
///
/// **ولا خطأَ في أيّ سجلٍّ عندنا.** المالُ حُصِّل، والشاشةُ التي عاد إليها
/// المستعملُ ليست التي كان فيها.
///
/// ══════════════════════════════════════════════════════════════════════
/// **ولمَ لا يكفي المحلِّل:** `use_build_context_synchronously` درجتُها
/// `info`، ومرشّحاتُ البوّابة مطابقةٌ لِما في `codemagic.yaml` حرفاً —
/// وهو لا يسقط على `info`. فثلاثةَ عشرَ موضعاً مرّت في دفعةٍ واحدة.
///
/// **والحارسُ يمنع النمطَ لا الكلمات:** أيُّ `Navigator.pop(x)` يقع بعد
/// `await` في الدالّة نفسِها ولا يُحرَس بـ`x.mounted`.
///
/// ══════════════════════════════════════════════════════════════════════
/// **ولا يبتلع حارسٌ حارساً.** الإصلاحُ الصحيح ليس خروجاً مبكِّراً واحداً:
/// لو خُرج عند موت سياق الحوار لضاعت رسالةُ «تم التحصيل» على تحصيلٍ وقع،
/// **وعميلٌ دفع ولم يرَ تأكيداً يدفع ثانية**. فالإغلاقُ بحارسه والرسالةُ
/// بحارسها.
void main() {
  /// الشاشاتُ التي تُحرَس — وتُوسَّع القائمةُ لا تُترك مفتوحة، فمرشِّحٌ
  /// على `lib/` كلِّه يمسك أنماطاً سليمةً في شيفرةٍ لا تنتظر شبكة.
  const watched = <String>[
    'lib/features/wholesale/screens',
    'lib/features/merchant/screens',
  ];

  test('كلُّ Navigator.pop بسياقٍ فرعيٍّ بعد await يُحرَس بسياقه', () {
    final offenders = <String>[];

    for (final dir in watched) {
      final d = Directory(dir);
      if (!d.existsSync()) continue;

      for (final f in d.listSync(recursive: true).whereType<File>()) {
        if (f.path.endsWith('.dart')) offenders.addAll(_offendersIn(f));
      }
    }

    expect(
      offenders,
      isEmpty,
      reason: 'سياقٌ فرعيٌّ يُغلَق بعد انتظارٍ بلا فحصِ `mounted` الخاصِّ به.\n'
          'وقد يكون أُغلق أثناء الانتظار، فيرمي أو يُغلق الصفحةَ تحته:\n'
          '  ${offenders.join('\n  ')}',
    );
  });

  test('الماسحُ يمسك العطلَ ويترك الزرَّ المتزامن', () {
    // ══════════════════════════════════════════════════════════════════
    // **ومُطابِقٌ عمي يخرج أخضرَ على صفر، ومُطابِقٌ جشعٌ يشلّ السليم.**
    // فيُجرَّب على الاثنين معاً في ملفٍّ واحد.
    // ══════════════════════════════════════════════════════════════════
    final tmp = File('${Directory.systemTemp.path}/ctx_probe.dart')
      ..writeAsStringSync('''
class X {
  void build() {
    IconButton(onPressed: () => Navigator.pop(sheetContext));
    FilledButton(onPressed: () async {
      final ok = await service.save();
      if (!mounted) return;
      if (ok) Navigator.pop(sheetContext);
    });
  }
}
''');

    final found = _offendersIn(tmp);
    tmp.deleteSync();

    expect(found.length, 1,
        reason: 'الماسحُ إمّا فوّت العطلَ وإمّا أنذر على الزرّ المتزامن: $found');
    expect(found.first, contains(':7'),
        reason: 'أُنذر على السطر الخطأ — والزرُّ المتزامن في الثالث');
  });
}

/// **يمسح الملفَّ بعمق الأقواس، لا بنافذةٍ من أسطر.**
///
/// أوّلُ كتابةٍ لهذا الحارس رجعت أربعين سطراً وبحثت عن `await`. **فأنذر
/// كاذباً على أزرارِ إغلاقٍ متزامنة** — `onPressed: () => Navigator.pop(x)`
/// بلا انتظارٍ في معالجها، والانتظارُ في بانٍ آخرَ قريب.
///
/// وحاجزٌ يشلّ عملاً سليماً يُطفَأ عند أوّل شكوى. فصار يتتبّع الكتلةَ
/// غيرَ المتزامنة نفسَها: يفتح عند `async {` ويُغلق بعمق الأقواس، ولا
/// يعتدّ إلّا بانتظارٍ داخلها.
List<String> _offendersIn(File f) {
  // ══════════════════════════════════════════════════════════════════
  // **والتعليقاتُ تُنزَع قبل المسح.**
  //
  // أوّلُ تشغيلٍ بعد الإصلاح أنذر على السطر ٣١٧ — **وهو تعليقٌ يشرح
  // العطلَ نفسَه** ويذكر `Navigator.pop(ctx)` في نصّه. وهو الدرسُ
  // المسجَّل في `CLAUDE.md` مقلوباً: هناك أخفى التعليقُ العطلَ، وهنا
  // اخترعه.
  //
  // ويُنزَع بإفراغ محتوى السطر لا بحذفه — فأرقامُ الأسطر تبقى صادقة.
  // ══════════════════════════════════════════════════════════════════
  final lines = f
      .readAsStringSync()
      .split('\n')
      .map((l) => l.replaceFirst(RegExp(r'\s*//.*$'), ''))
      .toList();
  final out = <String>[];
  var depth = 0;

  // **مكدّسُ الإغلاقات لا الكتلِ غيرِ المتزامنة وحدَها.**
  //
  // كُتب أوّلَ مرّةٍ يتتبّع `async {` فقط — **فأنذر على أزرارِ حوارٍ
  // متزامنة**: البانيِ الخارجيُّ ينتظر `showDialog`، وزرُّ «إلغاء» بداخله
  // `() => Navigator.pop(ctx)` لا انتظارَ فيه. **والعبرةُ بالإغلاق
  // الأعمق لا الأشمل.**
  //
  // فيُدفَع لكلّ إغلاقٍ: عمقُه، أَغيرُ متزامنٍ هو، وهل رأى انتظاراً.
  // ولا يُبلَّغ إلّا إذا كان **الأعمقُ** غيرَ متزامنٍ وقد انتظر.
  final stack = <List<dynamic>>[];  // [depth, isAsync, sawAwait]

  for (var i = 0; i < lines.length; i++) {
    final line = lines[i];

    final opensCallback = RegExp(r'(\)|\w)\s*(async\s*)?\{\s*$').hasMatch(line) ||
        RegExp(r'\basync\s*\{').hasMatch(line);

    if (opensCallback) {
      stack.add([depth, RegExp(r'\basync\s*\{').hasMatch(line), false]);
    }

    if (stack.isNotEmpty && line.contains('await ')) {
      stack.last[2] = true;
    }

    final m = RegExp(r'Navigator\.pop\(\s*([A-Za-z_][A-Za-z0-9_]*)\s*[,)]')
        .firstMatch(line);

    if (m != null) {
      final v = m.group(1)!;

      // **وإغلاقُ سهمٍ لا انتظارَ قبله في التعبير نفسِه.**
      final isArrow = line.contains('=>');

      final inner = stack.isEmpty ? null : stack.last;
      final risky = inner != null &&
          inner[1] == true &&
          inner[2] == true &&
          !isArrow;

      // `context` قد يكون سياقَ الحالة، و`mounted` يحرسه.
      if (v != 'context' && risky) {
        final from = inner[0] as int;
        var start = i;
        var d = depth;
        while (start > 0 && d >= from) {
          start--;
          d -= '}'.allMatches(lines[start]).length;
          d += '{'.allMatches(lines[start]).length;
        }

        if (!lines.sublist(start, i + 1).join('\n').contains('$v.mounted')) {
          out.add('${f.path}:${i + 1}  →  Navigator.pop($v)');
        }
      }
    }

    depth += '{'.allMatches(line).length;
    depth -= '}'.allMatches(line).length;

    while (stack.isNotEmpty && depth <= (stack.last[0] as int)) {
      stack.removeLast();
    }
  }

  return out;
}
