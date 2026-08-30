import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

/// AMIAL-MERCHANT-NAV-002
///
/// القائمة لا يجوز أن تعود إلى خمسة عناصر عامة ثم تُلصق بها «خدمات نشاطي».
/// هذا الحارس يقف عند مصدر الـ manifest كي لا تختفي أقسام الصيدلية أو الجملة
/// أو المطعم عند تعديل شكل الـ drawer لاحقاً.
void main() {
  final drawer = File(
    'lib/features/merchant/screens/merchant_adaptive_shell.dart',
  );

  test('مصدر قائمة التاجر موجود', () {
    expect(drawer.existsSync(), isTrue,
        reason: 'لم نعثر على مصدر القائمة: ${drawer.path}');
  });

  test('كل نوع نشاط يملك أقسام تشغيله ولا يعرض مركزاً عاماً باسم نشاطي', () {
    final src = drawer.readAsStringSync();

    expect(src, contains('List<_MerchantDrawerSection> _sectionsForBusiness'));
    expect(src, isNot(contains("label: 'خدمات نشاطي'")));

    // السطور التالية أسماء تشغيل لا زخرفة. كل واحدة تشير إلى مجموعات
    // الاستحقاقات التي يقيّمها الخادم عند فتحها.
    expect(src, contains("case 'pharmacy':"));
    expect(src, contains("groups: ['الصيدلية']"));
    expect(src, contains("case 'wholesale':"));

    // ══════════════════════════════════════════════════════════════
    // AMIAL-WHOLESALE-GUIDE-001 — **الجملةُ صارت تُعرَّف بالرمز لا
    // بالمجموعة، والضمانةُ هي هي.**
    //
    // كان هنا `groups: ['الجملة']`. ودليلُ تشغيل الجملة يفصل ما تجمعه
    // تلك المجموعةُ الواحدة قسمين — «فواتير الجملة والتحصيل» و«التسعير»
    // — فصار القسمان يُعرَّفان برموزهما.
    //
    // **ولا يُلَيَّن الشرط:** بدل اسم المجموعة تُشترط رموزُها الثلاثة
    // بأعيانها، وتغطيتُها الكاملةُ محروسةٌ في الخادم أيضاً
    // (`MerchantDrawerVerticalGroupsGuardTest`). فسقوطُ أيٍّ منها يُسقط
    // هذا الفحص، تماماً كما كان يُسقطه غيابُ المجموعة.
    // ══════════════════════════════════════════════════════════════
    for (final code in const [
      'wholesale_invoices',
      'wholesale_collections',
      'wholesale_multi_pricing',
    ]) {
      expect(src, contains("'$code'"),
          reason: 'سقط «$code» من قائمة الجملة — وهو من مجموعة «الجملة» '
              'التي يخصّها القطاع، فلا بابَ إليه من درجه.');
    }

    expect(src, contains("title: 'التسعير'"),
        reason: '**قسمُ «التسعير» غائبٌ عن قائمة الجملة.** ودليلُ تشغيلها '
            'يجعله قسماً قائماً بذاته: «قوائم أسعار وشرائح كمية وأسعار '
            'شركات» — وهو ما يميّز الجملةَ عن التجزئة.');
    expect(src, contains("case 'restaurant':"));
    expect(src, contains("groups: ['المطاعم']"));
    expect(src, contains("case 'quick_sale':"));
    expect(src, contains("title: 'البيع السريع'"));
    expect(src, contains("case 'retail':"));
    expect(src, contains("title: 'الأصناف والباركود'"));
  });

  test('الوقود يبقى بقائمته التشغيلية الخاصة', () {
    final src = drawer.readAsStringSync();
    expect(src, contains("access.businessType.value == 'fuel'"));
    expect(src, contains("label: 'بيع الوقود'"));
    expect(src, contains('FuelOpsCenterScreen'));
  });
}
