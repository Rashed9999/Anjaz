// AMIAL-SHIFT-DOOR-001 — **الحدُّ قائمٌ ولا بابَ في اللوحة.**
//
// ══════════════════════════════════════════════════════════════════════
// أرسل صاحبُ المشروع صورتَي لوحة تجزئةٍ وصيدليّة وقال: «يطلبون فتحَ
// ورديّةٍ عند العمل على الكاشير — هذا جيّد. **المشكلة لا يوجد نظامُ
// الورديّات هنا**، بينما الوقودُ موجود».
//
// **وقِيس، فكان ثلاثةَ أشياءَ لا شيئاً واحداً:**
//
//   ① **لوحةُ الصيدليّة بلا بلاطةِ ورديّةٍ إطلاقاً** — والخادمُ يردّ ٤٠٩
//      على كلّ بيعة (`merchant/pharmacy/sales` تحت `amial.shift`).
//   ② **ولوحةُ التجزئة فيها بلاطةٌ اسمُها «إقفال الوردية»** — والفعلُ
//      الذي يسبق البيعَ هو **الفتح**. فمن أراد أن يبدأ لم يبحث عن
//      «إقفال»، ووجدها بين العروضِ والولاءِ وبطاقاتِ الهدايا.
//   ③ **ولا تقول البلاطةُ حالةً** — أمفتوحةٌ أم لا، ومنذ متى، وباسم من.
//
// **والحالةُ الرابعةُ تحفظ ما لا يُكسَر**: شاشةُ الوقود لها ورديّاتُها
// (`fuel_shifts`)، وبلاطةُ درج الكاشير هناك تطلب ورديّةً ثانيةً لا معنى
// لها — فيُفحَص أنّها **ليست** هناك.

import 'dart:io';
import 'package:flutter_test/flutter_test.dart';

/// يُجرَّد النصُّ من التعليقات — **فتعليقٌ يصف العطلَ كان يُخفيه** مرّةً في
/// هذا المشروع، وهو مكتوبٌ في `CLAUDE.md` قاعدةً ثانية.
String _code(String path) {
  final f = File(path);
  expect(f.existsSync(), isTrue, reason: 'غيرُ موجود: $path');

  return f
      .readAsStringSync()
      .split('\n')
      .where((l) => !l.trimLeft().startsWith('//'))
      .join('\n');
}

void main() {
  const retail = 'lib/features/access/screens/role_based_home_screens.dart';
  const pharmacy = 'lib/features/pharmacy/screens/pharmacy_dashboard_screen.dart';
  const fuel = 'lib/features/fuel_station/screens/fuel_owner_console_screen.dart';
  const tile = 'lib/features/merchant/widgets/shift_status_tile.dart';

  test('① لوحةُ الصيدليّة فيها بابُ الورديّة', () {
    final code = _code(pharmacy);

    expect(code.contains('ShiftStatusTile'), isTrue,
        reason: 'لوحةُ الصيدليّة بلا ورديّةٍ إطلاقاً، والخادمُ يردّ ٤٠٩ '
            'على كلّ بيعة — فالمستعملُ أمام حدٍّ لا يعرف بابَه');

    expect(
        code.contains("import 'package:amial_pay/features/merchant/widgets/"
            "shift_status_tile.dart'"),
        isTrue,
        reason: 'ذُكرت البلاطةُ بلا استيراد — فلا تُصرَّف أصلاً');
  });

  test('② ولوحةُ التجزئة، والبابُ تحت الكاشير لا بعيداً عنه', () {
    final code = _code(retail);

    expect(code.contains('ShiftStatusTile'), isTrue,
        reason: 'لا بلاطةَ ورديّةٍ في لوحة التجزئة');

    // **موضعُها هو نصفُ الفائدة**: بلاطةٌ بين العروضِ والولاءِ لا يراها
    // من يريد أن يبدأ بيعَه.
    final cashier = code.indexOf('CashierPosScreen()');
    final door = code.indexOf('ShiftStatusTile');

    expect(cashier, greaterThan(-1));
    expect(door, greaterThan(cashier),
        reason: 'البلاطةُ قبل الكاشير — والترتيبُ يقول أيُّهما الفعلُ الأوّل');
    expect(door - cashier, lessThan(900),
        reason: 'البلاطةُ بعيدةٌ عن الكاشير — وهذا بعينه ما جعلها تختفي '
            'بين العروضِ والولاءِ وبطاقاتِ الهدايا');
  });

  test('②ب ولا يبقى اسمٌ يصف نصفَ الفعل', () {
    final code = _code(retail);

    expect(code.contains('إقفال الوردية'), isFalse,
        reason: 'بقيت البلاطةُ القديمة — واسمٌ يقول «إقفال» يُخفي أنّ '
            'الفتحَ هو ما يسبق البيع. وبلاطتان للشيء نفسِه تجعلان القارئَ '
            'يظنّهما فعلين ويبحث عن الفرق');
  });

  test('③ والبلاطةُ تقول الحالةَ ولا تخمّنها', () {
    final code = _code(tile);

    expect(code.contains('/api/v1/amial/cashier/shift'), isTrue,
        reason: 'لا تقرأ الحالةَ من الخادم — فتقول ما لا تعرف');

    // **«تعذّرت القراءة» ليست «لا ورديّة»** — القاعدة السابعة. ولولا
    // التفريقُ لأُرسل صاحبُها يفتح ورديّةً ثانيةً فوق مفتوحة.
    expect(code.contains('_unreadable'), isTrue,
        reason: 'يُخلَط تعذُّرُ القراءة بغياب الورديّة');

    expect(code.contains('opened_by_name'), isTrue,
        reason: 'لا تقول باسم من فُتحت — والفاتورةُ تُطبَع باسمه '
            'والفرقُ يُنسَب إليه');
  });

  test('④ ولوحةُ الوقود تبقى بلا بلاطةِ درج الكاشير', () {
    // **للوقود ورديّاتُه** (`fuel_shifts`)، و`computeCash` يستثنيه لئلّا
    // يُعدّ بيعُه مرّتين. فبلاطةُ الكاشير هنا تطلب ورديّةً ثانيةً لا
    // معنى لها — وحاجزٌ يشلّ عملاً سليماً أسوأ من ثغرة.
    expect(_code(fuel).contains('ShiftStatusTile'), isFalse,
        reason: 'دخلت بلاطةُ درج الكاشير لوحةَ المحطّة — فصار عاملُها '
            'مطالَباً بورديّتين');
  });
}
