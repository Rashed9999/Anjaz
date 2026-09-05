import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

/// AMIAL-SCAN-DEADEND-001 · AMIAL-QUICKSALE-PRIMARY-001
///
/// ══════════════════════════════════════════════════════════════════════
/// لاحظ صاحبُ المشروع أنّ **الكاشيرَ والبيعَ السريع والباركود يؤدّون
/// مهمّةً واحدة**، وقِيس فكان محقّاً — والتكرارُ كان معطوباً من طرفين:
///
/// ① **«مسح باركود» يمسح ثمّ يرمي النتيجة.** كان يعرض إشعاراً
///    «✓ تم مسح ١٠ صنف بإجمالي…» **ولا يضيفها لسلّةٍ ولا يفتح كاشيراً**.
///    فالمسحُ ليس مهمّةً قائمةً بذاتها بل **مدخلٌ مختصرٌ إلى البيع**.
///    (والمرجعُ الصحيحُ مبنيٌّ: شاشةُ بيع الصيدليّة تمسح فتضيف للسلّة.)
///
/// ② **والبيعُ السريع يفتح على شبكةِ منتجاتٍ فارغة.** وهو قطاعُ الأسماك
///    والخضار والبسطات — **لا كتالوجَ فيه**؛ يُدخَل المبلغُ ويُدفَع.
///    والبنيةُ مبنيّةٌ (`_manualAmount` · `freeAmount: true`) لكنّها كانت
///    خلف أيقونةٍ بلا اسمٍ في الشريط العلويّ.
///
/// **ولا يمسك أيَّهما مُصرِّفٌ ولا مُحلِّل**: الشاشتان تُبنَيان بلا شكوى،
/// والزرُّ يُضغَط ويستجيب. لا يظهر إلّا لمن يبيع فعلاً.
///
/// فيُقرأ المصدرُ ويُطابَق أثرُ كلٍّ منهما — وهو أرخصُ وأثبتُ من بناء
/// شاشةٍ كاملةٍ بماسحٍ وهميّ.
void main() {
  String read(String rel) => File(rel).readAsStringSync();

  group('AMIAL-SCAN-DEADEND-001 — المسحُ يقود إلى بيع', () {
    late String home;

    setUpAll(() {
      home = read('lib/features/access/screens/role_based_home_screens.dart');
    });

    test('الأصنافُ الممسوحةُ تُضاف إلى سلّة الكاشير', () {
      final at = home.indexOf('ContinuousScannerScreen');
      expect(at, isNot(-1), reason: 'لم يعد زرُّ المسح موجوداً — راجع الحارس');

      // نافذةُ المعالج بعد فتح الماسح.
      final handler = home.substring(at, at + 900);

      expect(handler.contains('addToCart'), isTrue,
          reason: '**المسحُ لا يضيف شيئاً إلى السلّة** — يمسح البائعُ '
              'أصنافَه ثمّ لا يحدث شيء. (كان يعرض إشعاراً ويرمي النتيجة.)');
    });

    test('ويُفتَح الكاشيرُ بعده لإتمام البيع', () {
      final at = home.indexOf('ContinuousScannerScreen');
      final handler = home.substring(at, at + 900);

      expect(handler.contains('CashierPosScreen'), isTrue,
          reason: '**المسحُ لا يقود إلى الكاشير** — فالسلّةُ تمتلئ في '
              'مكانٍ لا يراه البائع، وهو تكرارٌ بلا وجهة.');
    });

    test('ولا يكتفي بإشعارٍ ثمّ يصمت', () {
      final at = home.indexOf('ContinuousScannerScreen');
      final handler = home.substring(at, at + 900);

      // الصياغةُ القديمة: إشعارٌ فيه «تم مسح» ولا شيء بعده.
      final onlyToast = handler.contains('تم مسح') &&
          !handler.contains('addToCart');

      expect(onlyToast, isFalse,
          reason: '**عاد الزرُّ إشعاراً بلا أثر** — وهو العطلُ الأصليّ.');
    });
  });

  group('AMIAL-QUICKSALE-PRIMARY-001 — البيعُ السريع يبدأ بالمبلغ', () {
    late String pos;

    setUpAll(() {
      pos = read('lib/features/merchant/screens/cashier_pos_screen.dart');
    });

    test('الكاشيرُ يعرف قطاعَ البيع السريع', () {
      expect(pos.contains('isQuickSale'), isTrue,
          reason: '**الكاشيرُ لا يميّز البيعَ السريع** — فيعرض على بائع '
              'السمك شبكةَ منتجاتٍ فارغةً أبداً.');
    });

    test('ويقدّم «أدخل المبلغ» فعلاً أوّلَ لا أيقونةً مخفيّة', () {
      expect(pos.contains('أدخل المبلغ'), isTrue,
          reason: '**لا فعلَ ظاهرٌ لإدخال المبلغ** — وهو كلُّ ما يفعله '
              'هذا القطاع. كان خلف أيقونةٍ بلا اسمٍ في الشريط العلويّ.');

      // ويصل إلى المسار المبنيّ أصلاً — لا نسخةً ثانية.
      expect(pos.contains('_manualAmount'), isTrue,
          reason: 'الزرُّ لا ينادي مسارَ المبلغ الحرّ المبنيّ.');
      expect(pos.contains('freeAmount: true'), isTrue,
          reason: '**المبلغُ الحرُّ لا يُعلَن للخادم** — فتُسجَّل بيعةٌ '
              'بلا أصناف كأنّها بيعةُ كتالوج.');
    });

    test('ولا تُقفَل الشبكةُ على من أضاف صنفاً', () {
      // **الثمنُ يُقاس أيضاً**: القطاعُ يقرّر ما يتقدّم لا ما يُمنَع.
      final at = pos.indexOf('quickSale');
      expect(at, isNot(-1));
      final near = pos.substring(at, at + 1400);

      expect(near.contains('if (quickSale)'), isTrue,
          reason: 'التقديمُ ليس مشروطاً بالقطاع');
      expect(near.contains('else') && near.contains('return const SizedBox'),
          isFalse,
          reason: '**الشبكةُ تُخفى عمّن أضاف صنفاً** — القطاعُ يقدّم ولا يمنع.');
    });
  });
}
