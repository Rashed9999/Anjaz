// AMIAL-FUEL-SETTINGS-DOOR-001 — **مبنيّةٌ ولا يُوصَل إليها.**
//
// ══════════════════════════════════════════════════════════════════════
// قال صاحبُ المشروع: **«لا استطيع انشاء خزان او مضخة، ليس هناك طريقة
// للعمل»**. وقِيس، فكان الطريقُ مسدوداً من ثلاث جهاتٍ **ولا خطأَ في أيّ
// سجلّ** — كلُّ نقطةِ نهايةٍ تعمل، وكلُّ شاشةٍ مبنيّة:
//
//   ① `FuelSettingsScreen` — وفيها المضخّاتُ وأنواعُ الوقود — لها بابٌ
//      واحدٌ في المشروع كلِّه: `merchant_adaptive_shell`، **وهي ليست لوحةَ
//      المحطّة**. فصاحبُ المحطّة يفتح `FuelOwnerConsoleScreen` كلَّ يومٍ
//      ولا يجد فيها مضخّةً ولا نوعاً.
//   ② وشاشةُ الأسعار تقول «أضف نوع وقود **من الإعدادات**» — إرشادٌ إلى
//      شاشةٍ لا بابَ لها.
//   ③ ونافذةُ «خزان جديد» تطلب **«معرّف نوع الوقود»** رقماً يُكتب باليد
//      «من شاشة الأسعار» — وشاشةُ الأسعار لا تعرض معرّفاً ولا تُنشئ نوعاً.
//
// **وهذا حارسُ نصٍّ لا حارسُ تشغيل** — يقرأ الملفّات كما تُقرأ، لأنّ
// العطلَ نفسَه كان في الوصل لا في المنطق. (القاعدة الثانية عشرة: المسارُ
// المسجَّل ليس ظهوراً.)

import 'dart:io';
import 'package:flutter_test/flutter_test.dart';

String _read(String path) {
  final f = File(path);
  expect(f.existsSync(), isTrue, reason: 'الملفُّ غيرُ موجود: $path');
  return f.readAsStringSync();
}

/// يُجرَّد النصُّ من التعليقات — **فتعليقٌ يصف العطلَ كان يُخفيه** مرّةً
/// في هذا المشروع، وهو مكتوبٌ في `CLAUDE.md` قاعدةً ثانية.
String _codeOnly(String src) {
  final out = StringBuffer();
  for (final line in src.split('\n')) {
    final t = line.trimLeft();
    if (t.startsWith('//')) continue;
    out.writeln(line);
  }
  return out.toString();
}

void main() {
  const console = 'lib/features/fuel_station/screens/fuel_owner_console_screen.dart';
  const tanks = 'lib/features/fuel_station/screens/fuel_tanks_screen.dart';

  test('لوحةُ المحطّة فيها بابٌ إلى إعدادات المحطّة', () {
    final code = _codeOnly(_read(console));

    expect(code.contains('FuelSettingsScreen'), isTrue,
        reason: 'لوحةُ المحطّة بلا بابٍ إلى الإعدادات — فلا مضخّةَ تُضاف '
            'ولا نوعَ وقودٍ يُعرَّف، والشاشةُ مبنيّةٌ كاملةً في مكانٍ آخر');

    expect(code.contains("import 'package:amial_pay/features/fuel_station/"
        "screens/fuel_settings_screen.dart'"), isTrue,
        reason: 'ذُكر الاسمُ بلا استيراد — فلا يُصرَّف أصلاً');
  });

  test('نافذةُ الخزّان تختار النوعَ ولا تطلب معرّفاً يُكتب باليد', () {
    final code = _codeOnly(_read(tanks));

    expect(code.contains('معرّف نوع الوقود'), isFalse,
        reason: 'ما زال يُطلَب رقمٌ من قاعدة البيانات يُحفظ عن ظهر قلب — '
            'ولا شاشةَ تعرضه');

    expect(code.contains('DropdownButtonFormField<int>'), isTrue,
        reason: 'لا قائمةَ اختيارٍ للنوع — والمستعملُ يخمّن رقماً');

    expect(code.contains('station.products'), isTrue,
        reason: 'القائمةُ لا تُبنى من أنواع المحطّة نفسِها');
  });

  test('وغيابُ الأنواع يُقال ومعه بابُه، لا يُترك رفضاً من الخادم', () {
    final code = _codeOnly(_read(tanks));

    // **الرفضُ كان يأتي من الخادم بعد الضغط**: «نوع الوقود غير موجود في
    // هذه المحطة» — صحيحٌ وعديمُ الفائدة، لا يقول ما العمل.
    expect(code.contains('أضِف نوعَ الوقود') || code.contains('لا أنواعَ وقودٍ'),
        isTrue,
        reason: 'لا يُقال لصاحب المحطّة إنّ عليه تعريفَ نوعٍ أوّلاً');

    expect(code.contains("Key('fuel-tank-goto-products')"), isTrue,
        reason: 'قيل له «أضِف نوعاً» ولم يُعطَ بابَه — وإرشادٌ بلا بابٍ '
            'هو العطلُ الأوّل نفسُه');
  });
}
