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
    expect(src, contains("groups: ['الجملة']"));
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
