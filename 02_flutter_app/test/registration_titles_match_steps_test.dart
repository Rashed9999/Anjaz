import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

/// AMIAL-REG-TITLES-001 — **عنوانُ كلّ شاشةٍ هو عنوانُها هي.**
///
/// ══════════════════════════════════════════════════════════════════════
/// **ما وقع:** أُدرجت خطوةُ «العمل ومصدر الدخل» في الموضع ٣ من
/// `PageView`، **ولم تُدرَج في قائمة العناوين**. فانزاح كلُّ عنوانٍ بعدها
/// واحداً: شاشةُ الوثائق تُعنوَن «شخص قريب»، وشاشةُ التوقيع «الإقرارات»،
/// **وشاشةُ كلمة المرور تُعنوَن «رمز التحقق»**.
///
/// ولهذا قال من سجّل: «لا توجد خانةٌ لاختيار كلمة المرور» — وهي موجودةٌ،
/// لكنّ الشاشةَ تقول إنّها شاشةُ رمز التحقّق. **حقلٌ لا يجده صاحبُه
/// كالحقل الغائب.**
///
/// **ولا يمسكه مُصرِّفٌ ولا مُحلِّل**: القائمةُ أقصرُ من الصفحات فحسب،
/// و`titles[_step]` لا يتجاوز حدَّه لأنّ شاشةَ النجاح لا تُعنوَن.
/// و`_validateStep` كان سليماً — فلا خطأَ في أيّ سجلّ، ولا سقط اختبار.
/// **عطلٌ في المعنى وحدَه، لا يراه إلّا إنسانٌ يقرأ الشاشة.**
///
/// فالحارسُ يقرأ المصدرَ ويطابق **ترتيبَ الصفحات بترتيب العناوين**، لا
/// عددَهما فقط: من نقل صفحةً غداً بلا نقلِ عنوانها يسقط هنا.
void main() {
  final src = File(
    'lib/features/auth/screens/amial_registration_wizard_screen.dart',
  ).readAsStringSync();

  /// أسماءُ بواني الصفحات بترتيبها داخل `PageView(... children: [...])`.
  List<String> pagesInOrder() {
    final at = src.indexOf('child: PageView(');
    expect(at, isNot(-1), reason: 'لم يعد المعالجُ يستعمل PageView — راجع الحارس');
    final open = src.indexOf('children: [', at);
    final close = src.indexOf('],', open);
    final block = src.substring(open, close);

    return RegExp(r'_step(\w+)\(\)')
        .allMatches(block)
        .map((m) => m.group(1)!)
        .toList();
  }

  /// العناوينُ بترتيبها في قائمة `titles`.
  List<String> titlesInOrder() {
    final at = src.indexOf('const titles = [');
    expect(at, isNot(-1), reason: 'لم تعد قائمةُ العناوين باسم titles');
    final close = src.indexOf('];', at);
    final block = src.substring(at, close);

    return RegExp(r"'([^']+)'")
        .allMatches(block)
        .map((m) => m.group(1)!)
        .toList();
  }

  test('لكلّ شاشةِ إدخالٍ عنوانٌ واحد — وشاشةُ النجاح بلا عنوان', () {
    final pages = pagesInOrder();
    final titles = titlesInOrder();

    expect(pages.length, greaterThan(1), reason: 'لم تُقرأ الصفحاتُ أصلاً');
    expect(pages.last, 'Success',
        reason: 'آخرُ صفحةٍ ليست شاشةَ النجاح — تغيّر الترتيبُ فراجع الحارس');

    expect(titles.length, pages.length - 1,
        reason: '**عددُ العناوين لا يطابق عددَ شاشات الإدخال** '
            '(${titles.length} عنواناً مقابل ${pages.length - 1} شاشة). '
            'أُدرجت شاشةٌ ولم يُدرَج عنوانُها، فانزاح كلُّ ما بعدها.');
  });

  test('وشاشةُ كلمة المرور تُعنوَن «كلمة المرور» لا «رمز التحقق»', () {
    final pages = pagesInOrder();
    final titles = titlesInOrder();

    final pinAt = pages.indexOf('Pin');
    expect(pinAt, isNot(-1), reason: 'لا شاشةَ كلمةِ مرورٍ في المعالج');

    expect(titles[pinAt], 'كلمة المرور',
        reason: '**شاشةُ كلمة المرور معنونةٌ «${titles[pinAt]}»** — '
            'فمن سجّل ظنّ أنّ لا خانةَ لكلمة المرور، ثمّ سُئل عنها '
            'عند الدخول. واللفظُ الواحدُ للشيء الواحد.');
  });

  test('وخطوةُ العمل ومصدر الدخل لها عنوانُها', () {
    final pages = pagesInOrder();
    final titles = titlesInOrder();

    final workAt = pages.indexOf('Work');
    expect(workAt, isNot(-1), reason: 'لا خطوةَ عملٍ في المعالج');

    expect(titles[workAt], 'العمل ومصدر الدخل',
        reason: '**الخطوةُ التي أحدثت الانزياحَ أصلاً** — عنوانُها '
            '«${titles[workAt]}» لا يصفها.');
  });

  test('وعددُ الخطوات المعروضُ يُشتقّ ولا يُكتب رقماً', () {
    // رقمٌ مكتوبٌ بخطّ اليد هو ما جعل الشريطَ يقول «من 9» وهي عشر.
    expect(src.contains(r'من ${titles.length}'), isTrue,
        reason: '**عددُ الخطوات مكتوبٌ رقماً** — يشيخ مع أوّل خطوةٍ تُدرَج.');
    expect(src.contains('List.generate(titles.length'), isTrue,
        reason: '**أجزاءُ شريط التقدّم مكتوبةٌ رقماً** — تُخفي خطوةً كاملة.');
  });
}
