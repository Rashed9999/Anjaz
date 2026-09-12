import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

/// AMIAL-WHOLESALE-GUIDE-001 — **قائمةُ الجملة كما يصفها دليلُها.**
///
/// ══════════════════════════════════════════════════════════════════════
/// المرجعُ مستندُ صاحب المشروع «دليل تاجر الجملة»، القسم ٥ «القائمة
/// الجانبية - تاجر الجملة». وفيه سبعةُ أقسامٍ بأسمائها:
///
///     الرئيسية · فواتير الجملة والتحصيل · العملاء والديون ·
///     الأصناف ومخزون الجملة · التسعير · التقارير والمالية ·
///     الفريق والأجهزة
///
/// **وقِيس ما كان: خمسة.** لا قسمَ للتسعير، و«العملاء والديون» مدموجٌ
/// مع «الفريق والأجهزة» في «العملاء والفريق»، ومعها قسمُ «البيع
/// والتحصيل» العامّ — **وبيعُ الجملة فاتورةٌ لا سلّةُ كاشير**.
///
/// والدليلُ يقول بنصّه: «لا تظهر له قائمة محطة الوقود أو مطعم. القائمة
/// تقوده إلى وظيفة جملة فعلية»، و«عناصر مشتركة فقط: مزايا الباقة،
/// الترقية، الإعدادات، الدعم وتسجيل الخروج. **أما عناصر التشغيل فهي
/// قائمة جملة متخصصة**».
///
/// ══════════════════════════════════════════════════════════════════════
/// **ولا يُقاس الوجودُ وحدَه بل الرمزُ خلفه**: قسمٌ يُعرَّف برموز قدرات،
/// ورمزٌ مخطوءٌ فيه **يُفرغ القسمَ صامتاً** — يُفتح فيُقال «لا خدمات
/// متاحة لهذا القسم»، ويُقرأ قفلَ باقةٍ لا خطأً إملائيّاً.
/// (القاعدة التاسعة: قياسُ ما بعد الضغطة أثرُها لا غيابُ الخطأ.)
void main() {
  final shell =
      File('lib/features/merchant/screens/merchant_adaptive_shell.dart');
  final hub =
      File('lib/features/merchant/screens/merchant_capability_hub_screen.dart');
  final registry =
      File('../01_backend/app/Support/Access/CapabilityRegistry.php');

  test('الملفّاتُ في مواضعها — وإلّا فحصنا العدم', () {
    for (final f in [shell, hub, registry]) {
      expect(f.existsSync(), isTrue, reason: 'مفقود: ${f.path}');
    }
  });

  /// كتلةُ `case 'wholesale':` وحدَها — فلا يُقرأ قسمُ قطاعٍ آخر.
  String wholesaleBlock() {
    final src = shell.readAsStringSync();
    final start = src.indexOf("case 'wholesale':");
    expect(start, greaterThan(-1),
        reason: 'لا فرعَ للجملة في `_sectionsForBusiness` — والدليلُ '
            'يشترط قائمةً متخصّصة، لا قالباً مشتركاً.');

    final end = src.indexOf("case '", start + 10);
    return src.substring(start, end > start ? end : src.length);
  }

  test('سبعةُ أقسامٍ بأسماء الدليل — لا خمسة', () {
    final block = wholesaleBlock();

    // «الرئيسية» بندٌ ثابتٌ في رأس الدرج لكلّ التجّار، فلا يُكتب هنا.
    const required = [
      'فواتير الجملة والتحصيل',
      'العملاء والديون',
      'الأصناف ومخزون الجملة',
      'التسعير',
      'التقارير والمالية',
      'الفريق والأجهزة',
    ];

    final missing =
        required.where((t) => !block.contains("title: '$t'")).toList();

    expect(missing, isEmpty,
        reason: '**أقسامٌ يشترطها دليلُ الجملة وليست في قائمته:**\n'
            '  ${missing.join('\n  ')}\n\n'
            'والقسمُ الغائبُ يُدفن محتواه: «التسعير» كان داخل «إعدادات '
            'الجملة»، وهو ما يميّز الجملةَ عن التجزئة — سعرُ عميلٍ وسعرُ '
            'كمّيّةٍ وسعرُ شركة.');
  });

  test('ولا قسمَ بيعٍ عامّ — بيعُ الجملة فاتورةٌ لا سلّةُ كاشير', () {
    final block = wholesaleBlock();

    expect(block.contains('sale,'), isFalse,
        reason: '**قسمُ «البيع والتحصيل» العامّ عاد إلى قائمة الجملة.** '
            'وهو مجموعةُ «البيع» المشتركة: كاشيرٌ وبيعٌ سريعٌ وتقسيمُ '
            'فاتورة. ودليلُ الجملة يجعل البيعَ **فاتورةً**: «فاتورة '
            'جديدة، QR، نقد، آجل، طباعة».');
  });

  test('وكلُّ رمزٍ في الأقسام موجودٌ في سجلّ القدرات', () {
    // ══════════════════════════════════════════════════════════════
    // **رمزٌ مخطوءٌ يُفرغ القسمَ ولا يُنتج خطأً.** الشاشةُ تُصفّي
    // `items` بالرمز، فما لا يطابق يُسقط بصمت، ويقرأ التاجرُ «لا خدمات
    // متاحة لهذا القسم» — فيظنّها باقتَه وهي غلطةُ حرف.
    // ══════════════════════════════════════════════════════════════
    final block = wholesaleBlock();
    final reg = registry.readAsStringSync();

    final codes = RegExp(r"^\s+'([a-z0-9_.]+)',\s*$", multiLine: true)
        .allMatches(block)
        .map((m) => m.group(1)!)
        .toSet();

    expect(codes.length, greaterThan(8),
        reason: 'لم تُقرأ رموزُ الأقسام — تغيّرت الصياغةُ والحارسُ يفحص '
            'فراغاً. (القاعدة السابعة: صفرٌ لا يعني «فُحص».)');

    // الرمزُ يُعلَن في السجلّ إمّا `C::make('code')` أو `C::make(A::F_CODE)`.
    final declared = <String>{
      ...RegExp(r"C::make\('([a-z0-9_.]+)'\)")
          .allMatches(reg)
          .map((m) => m.group(1)!),
      ...RegExp(r"const F_[A-Z_]+ = '([a-z0-9_]+)'")
          .allMatches(File('../01_backend/app/Support/Access/AccessConstants.php')
              .readAsStringSync())
          .map((m) => m.group(1)!),
    };

    final ghosts = codes.where((c) => !declared.contains(c)).toList()..sort();

    expect(ghosts, isEmpty,
        reason: '**رموزٌ في قائمة الجملة لا وجودَ لها في سجلّ القدرات:**\n'
            '  ${ghosts.join('، ')}\n\n'
            'فيُفتح القسمُ فارغاً ويُقال «لا خدمات متاحة لهذا القسم» — '
            '**ولا خطأَ في أيّ سجلّ**، ويُقرأ قفلَ باقةٍ لا غلطةَ حرف.');
  });

  test('وقدرةُ التسعير لها شاشةٌ تُفتح — لا اسمٌ بلا باب', () {
    final map = File('lib/features/entitlements/capability_screens.dart')
        .readAsStringSync();

    expect(map.contains("'wholesale_multi_pricing':"), isTrue,
        reason: '**«تسعير متعدد المستويات» بلا مدخلٍ في خريطة القدرات.** '
            'فتُعرَض في «مزايا باقتي» ويُضغط اسمُها ولا يُفتح شيء — '
            'وهي مبيعةٌ في باقة الأعمال.');

    final reg = registry.readAsStringSync();
    final at = reg.indexOf('F_WHOLESALE_MULTI_PRICING');
    expect(at, greaterThan(-1), reason: 'اختفت القدرةُ من السجلّ');

    final decl = reg.substring(at, at + 600);
    expect(decl.contains("->screen("), isTrue,
        reason: 'القدرةُ بلا `screen()` في السجلّ — و«قدراتي» تقرأ منه '
            'وجهةَ الضغطة.');
  });

  test('والهيكلُ يمرّر الرموزَ فعلاً — وإلّا كان الوصفُ زينة', () {
    final shellSrc = shell.readAsStringSync();
    final hubSrc = hub.readAsStringSync();

    expect(shellSrc.contains('codes: section.codes'), isTrue,
        reason: '**الأقسامُ تُعرِّف رموزَها والهيكلُ لا يمرّرها** — '
            'فيسقط التضييقُ ويُعرَض القسمُ بمجموعته كاملةً، والوصفُ في '
            'الشيفرة يقول غيرَ ما يقع.');

    expect(hubSrc.contains('codes.contains'), isTrue,
        reason: 'الشاشةُ تستقبل الرموزَ ولا تُصفّي بها');
  });
}
