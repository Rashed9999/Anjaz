import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

/// AMIAL-KEYPAD-LTR-002 — كل لوحة أرقام في التطبيق تفرض اتجاهها.
///
/// **العطل الذي وقع مرّتين:**
/// لوحة الرمز السرّي كانت تعرض «٣ ٢ ١» فأُصلحت بفرض LTR. ثم ظهر في تسجيل
/// شاشة من محطة وقود أن **لوحة الكاشير** تعرضها كذلك — وهي تطبيقٌ ثانٍ
/// مستقلّ، لم يُسأل عنه حين أُصلح الأوّل.
///
/// وهذا نمطُ خطئي المتكرّر: أُصلح النسخة ولا أسأل عن الصنف. فالحارس هنا
/// ليس على لوحة بعينها، بل على **القاعدة**: أي ملفّ يبني صفوف أرقام يدوياً
/// يجب أن يفرض الاتجاه، وإلّا عكسه المحيط العربي.
///
/// وهو فحصٌ نصّيّ لا سلوكيّ — يجد اللوحات التي لم تُكتب بعد. والسلوك مفحوص
/// بقياس المواضع في splash/widget_smoke لِما هو مكتوب.
void main() {
  /// ملفّات تبني صفّ أرقام يدوياً — لا تستعمل AmialNumpad المشتركة.
  List<File> handRolledKeypads() {
    final out = <File>[];
    for (final entity in Directory('lib').listSync(recursive: true)) {
      if (entity is! File || !entity.path.endsWith('.dart')) continue;

      final src = entity.readAsStringSync();
      final buildsDigitRow = RegExp(r"\['1',\s*'2',\s*'3'\]").hasMatch(src) ||
          RegExp(r"for \(var col = 1; col <= 3").hasMatch(src);

      if (buildsDigitRow) out.add(entity);
    }
    return out;
  }

  test('كل لوحة أرقام مكتوبة يدوياً تفرض الاتجاه صراحةً', () {
    final offenders = <String>[];

    for (final file in handRolledKeypads()) {
      if (!file.readAsStringSync().contains('TextDirection.ltr')) {
        offenders.add(file.path);
      }
    }

    expect(offenders, isEmpty,
        reason: 'لوحات أرقام بلا اتجاه صريح: ${offenders.join('، ')}\n'
            'الواجهة العربية تعكس كل Row فتظهر «٣ ٢ ١» بدل «١ ٢ ٣». '
            'المرجع لوحة الاتصال التي في يد المستخدم كل يوم، لا اتجاه القراءة. '
            'لُفَّها بـ Directionality(textDirection: TextDirection.ltr).');
  });

  test('الفحص يجد اللوحات فعلاً — وإلّا مرّ على العدم', () {
    // حارسٌ لا يجد ما يحرسه يمرّ دائماً ولا يحرس شيئاً. وقد وقع لي في هذه
    // الجولة فحصٌ نجح لأن نمطه لم يطابق سطراً واحداً.
    final found = handRolledKeypads();

    expect(found, isNotEmpty,
        reason: 'لم يُعثر على أي لوحة أرقام — تغيّرت صياغتها والفحص صار أعمى');
    expect(found.length, greaterThanOrEqualTo(2),
        reason: 'المعروف لوحتان: AmialNumpad ولوحة كاشير الوقود');
  });
}
