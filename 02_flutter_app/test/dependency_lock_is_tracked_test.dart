import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

/// AMIAL-BUILD-LOCK-001 — **الشجرةُ التي تُبنى هي الشجرةُ التي فُحصت.**
///
/// ══════════════════════════════════════════════════════════════════════
/// **الثمن:** سقط بناءُ Codemagic على `flutter_html` بـ
/// `Method not found: 'matches'` — **والبوّابةُ المحلّيّةُ خضراءُ تماماً**،
/// لأنّ الحزمةَ التي تُصرَّف هنا ليست التي تُصرَّف هناك.
///
/// والسببُ سطرٌ واحدٌ في `.gitignore`: `pubspec.lock` كان مُهمَلاً. فيحلّ
/// Codemagic التبعيّاتِ **من جديد في كلّ بناء**، فيأخذ أحدثَ ما يطابق
/// القيود — لا ما فُحص. **فكسرٌ في حزمةٍ لا نملكها يصل إلى شاشة صاحب
/// المشروع بلا أن يمرّ بفحصٍ واحد.**
///
/// وهو أخو عطل حدّ Gradle المسجَّل في هذا المشروع: `flutter: stable`
/// يتحرّك والمشروعُ لا يتحرّك معه. **إلّا أنّ هذا أسوأ**: هناك رقمٌ
/// مثبَّتٌ يتخلّف، وهنا **لا رقمَ مثبَّتٌ أصلاً**.
///
/// **والقفلُ يُتتبَّع في التطبيقات لا في المكتبات** — وهذا تطبيق.
void main() {
  test('ملفُّ القفل موجودٌ في الشجرة', () {
    expect(File('pubspec.lock').existsSync(), isTrue,
        reason: 'لا `pubspec.lock` — فكلُّ بناءٍ يحلّ التبعيّاتِ بنفسه');
  });

  test('ولا يُهمَله git — وإلّا بُني ما لم يُفحَص', () {
    final ignore = File('.gitignore');

    expect(ignore.existsSync(), isTrue);

    // **ويُقرأ السطرُ لا الملفّ.** البحثُ عن النصّ في الملفّ كلِّه يجده
    // في التعليق الذي يشرح العطل — وهو نفسُ عطل «تعليقٌ يصف العطلَ
    // فيُخفيه» الذي وقع في هذا المشروع مرّتين.
    final ignored = ignore
        .readAsLinesSync()
        .map((l) => l.trim())
        .where((l) => l.isNotEmpty && !l.startsWith('#'))
        .any((l) => l == 'pubspec.lock' ||
            l == '/pubspec.lock' ||
            l == '**/pubspec.lock');

    expect(ignored, isFalse,
        reason: '**عاد `pubspec.lock` إلى `.gitignore`** — فتحلّ منصّةُ '
            'البناء التبعيّاتِ من جديد وتأخذ أحدثَ ما يطابق القيود. '
            'والشجرةُ التي تُبنى ليست التي مرّت البوّابة، فكسرٌ في حزمةٍ '
            'لا نملكها يصل إلى المستخدم بلا فحص.');
  });

  test('وكلُّ تبعيّةٍ مباشرةٍ محلولةٌ في القفل — فقفلٌ ناقصٌ يُحلّ جزئيّاً', () {
    final lock = File('pubspec.lock').readAsStringSync();
    final pubspec = File('pubspec.yaml').readAsStringSync();

    // التبعيّاتُ المباشرة: أسطرٌ بمسافتين تحت `dependencies:`
    final deps = <String>[];
    var inDeps = false;

    for (final raw in pubspec.split('\n')) {
      if (raw.startsWith('dependencies:')) { inDeps = true; continue; }
      if (raw.startsWith('dev_dependencies:') ||
          raw.startsWith('flutter:') ||
          raw.startsWith('dependency_overrides:')) { inDeps = false; }
      if (!inDeps) continue;

      final m = RegExp(r'^  ([a-z0-9_]+):').firstMatch(raw);
      if (m != null && m.group(1) != 'sdk') deps.add(m.group(1)!);
    }

    expect(deps.length, greaterThan(20),
        reason: 'لم تُقرأ التبعيّاتُ — الحارسُ يفحص فراغاً');

    final missing = deps.where((d) => !lock.contains('  $d:')).toList();

    expect(missing, isEmpty,
        reason: 'تبعيّاتٌ في pubspec.yaml بلا صفٍّ في القفل: '
            '${missing.join('، ')} — شغّل `flutter pub get` والتزم القفل.');
  });
}
