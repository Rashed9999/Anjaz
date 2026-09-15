// AMIAL-I18N-004 — حارس الترجمة للشاشات الحيّة.
//
// ملفات `screens/legacy/` مراجع انتقال غير قابلة للوصول من رحلة المستخدم؛
// لا تدخل ميزانية النص الظاهر، وإلا صار الاحتفاظ بمرجع تاريخي انتكاسةً
// وهمية في واجهة لا يستطيع العميل فتحها.

import 'dart:convert';
import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

final _hard = RegExp(r"'((?:[^'\\$]|\\.)*[؀-ۿ](?:[^'\\$]|\\.)*)'(?!\s*\.tr)");
const _notText = ['assets/', 'package:', 'http', 'Key(', 'key:', r'${'];
final _holes = RegExp(r'\$\{[^}]*\}|\$\w+');

bool _isLegacy(File f) =>
    f.path.replaceAll('\\', '/').contains('/screens/legacy/');

int _hardCodedIn(File f) {
  if (_isLegacy(f)) return 0;

  var n = 0;
  for (final l in f.readAsLinesSync()) {
    final s = l.trimLeft();
    if (s.startsWith('//') || s.startsWith('*') || s.startsWith('/*')) continue;
    if (_notText.any(l.contains)) continue;
    n += _hard.allMatches(l).length;
  }
  return n;
}

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

const _theFive = [
  'lib/features/access/screens/role_based_home_screens.dart',
  'lib/features/merchant/screens/merchant_services_hub_screen.dart',
  'lib/features/plans/screens/plans_catalog_screen.dart',
  'lib/features/merchant/screens/cashier_pos_screen.dart',
  'lib/features/pharmacy/screens/pharmacy_dashboard_screen.dart',
];

void main() {
  test('① الشاشات الخمس المترجمة لا يعود إليها نص محفور', () {
    final left = <String>[];
    for (final p in _theFive) {
      final n = _hardCodedIn(File(p));
      if (n > 0) left.add('$p → $n');
    }
    expect(
      left,
      isEmpty,
      reason: 'عاد نص محفور إلى شاشة مترجمة:\n  ${left.join('\n  ')}',
    );
  });

  test('② لا `.tr` على نص مركب بإقحام', () {
    final offenders = <String>[];

    for (final f in Directory('lib').listSync(recursive: true)) {
      if (f is! File || !f.path.endsWith('.dart') || _isLegacy(f)) continue;

      var line = 0;
      for (final l in f.readAsLinesSync()) {
        line++;
        if (l.trimLeft().startsWith('//')) continue;

        for (final body in _translatedLiterals(l)) {
          if (!body.contains(r'$')) continue;
          final holes = _holes.allMatches(body).length;
          if (holes == 0) continue;
          if (holes == 1 && body.replaceAll(_holes, '').isEmpty) continue;
          offenders.add('${f.path}:$line');
        }
      }
    }

    expect(
      offenders,
      isEmpty,
      reason: 'نص مركب عليه `.tr`:\n  ${offenders.join('\n  ')}\nاستعمل trParams.',
    );
  });

  test('③ ملفا العربية والإنجليزية يتطابقان مفتاحاً بمفتاح', () {
    final ar = jsonDecode(File('assets/language/ar.json').readAsStringSync())
        as Map<String, dynamic>;
    final en = jsonDecode(File('assets/language/en.json').readAsStringSync())
        as Map<String, dynamic>;

    final missingEn = ar.keys.where((k) => !en.containsKey(k)).toList();
    final missingAr = en.keys.where((k) => !ar.containsKey(k)).toList();
    final blank = en.entries
        .where((e) => (e.value as String).trim().isEmpty)
        .map((e) => e.key)
        .toList();

    expect(missingEn, isEmpty,
        reason: 'مفاتيح بلا إنجليزية:\n  ${missingEn.take(20).join('\n  ')}');
    expect(missingAr, isEmpty,
        reason: 'مفاتيح بلا عربية:\n  ${missingAr.take(20).join('\n  ')}');
    expect(blank, isEmpty,
        reason: 'ترجمات إنجليزية فارغة:\n  ${blank.take(20).join('\n  ')}');
  });

  test('④ لا اتجاه RTL مفروض داخل الشاشة', () {
    final offenders = <String>[];

    for (final f in Directory('lib').listSync(recursive: true)) {
      if (f is! File || !f.path.endsWith('.dart') || _isLegacy(f)) continue;
      if (f.path.endsWith('app_direction.dart')) continue;

      var line = 0;
      for (final l in f.readAsLinesSync()) {
        line++;
        final s = l.trimLeft();
        if (s.startsWith('//') || s.startsWith('///')) continue;
        if (l.contains('textDirection: TextDirection.rtl')) {
          offenders.add('${f.path}:$line');
        }
      }
    }

    expect(
      offenders,
      isEmpty,
      reason: 'اتجاه مفروض في الشيفرة:\n  ${offenders.join('\n  ')}',
    );
  });

  test('⑤ ميزانية النص المحفور تقيس الواجهات الحيّة فقط', () {
    var total = 0;
    final worst = <String, int>{};

    for (final f in Directory('lib').listSync(recursive: true)) {
      if (f is! File || !f.path.endsWith('.dart') || _isLegacy(f)) continue;
      final n = _hardCodedIn(f);
      total += n;
      if (n > 0) worst[f.path] = n;
    }

    final top = worst.entries.toList()
      ..sort((a, b) => b.value.compareTo(a.value));

    // لا نرفع الحد بسبب شاشة قديمة نُقلت إلى legacy؛ أي زيادة في الشاشات
    // القابلة للوصول تبقى انتكاسة حقيقية ويجب نقلها إلى ملفات اللغة.
    expect(
      total,
      lessThanOrEqualTo(4536),
      reason: 'ازداد النص المحفور في الواجهات الحية. الأثقل:\n  '
          '${top.take(10).map((e) => '${e.value}  ${e.key}').join('\n  ')}',
    );

    if (total < 500) {
      fail('هبط النص المحفور إلى $total — حدّث سياسة إعادة الإنجليزية والحارس.');
    }
  });
}
