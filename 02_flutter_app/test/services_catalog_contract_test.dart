import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

/// AMIAL-SERVICES-CATALOG-002 — أكواد «خدماتي» عقدٌ مع الخادم لا أسماء عرض.
///
/// **صنف العطل الذي يحرسه هذا الملفّ:**
/// كل بطاقة في مركز الخدمات تُقرَّر مفتوحةً أو مقفلةً بـ `access.has(code)`،
/// والقائمة تأتي من `AccessPresets` في الخادم. فكودٌ لا يعرفه الخادم يجعل
/// `has` تُرجع false **دائماً**: تظهر الخدمة مقفلةً ومعها دعوةٌ لترقية باقة،
/// فيرقّي التاجر ولا تُفتح. وهذا أسوأ من انهيار: لا استثناء ولا سجلّ ولا
/// شاشة رمادية — واجهةٌ تعمل بثقة وهي كاذبة، ولا يُكتشف إلّا بشكوى.
///
/// وقد وقع قريبٌ منه فعلاً: بطاقة «المخزون» كانت تفتح شاشة الكاشير.
///
/// الفحص نصّيّ عبر المستودعين لأن العقد نفسه نصّيّ: سلسلة حروف في Dart
/// يجب أن تطابق ثابتاً في PHP، ولا يوجد مُصرِّف يربط بينهما.
void main() {
  final hub = File(
      'lib/features/merchant/screens/merchant_services_hub_screen.dart');
  final constants =
      File('../01_backend/app/Support/Access/AccessConstants.php');

  /// مفاتيح الميزات التي يعرفها الخادم — قيم ثوابت `F_*`.
  Set<String> serverFeatures() {
    final src = constants.readAsStringSync();
    return RegExp(r"const F_\w+ = '([a-z0-9_]+)'")
        .allMatches(src)
        .map((m) => m.group(1)!)
        .toSet();
  }

  /// نصّ كل تعريف `_Svc` مفهرساً بكوده.
  ///
  /// يُقسَّم النصّ على `_Svc('` بدل مطابقة نهاية كل تعريف: التعريف يحوي
  /// أقواساً متداخلة (`() => const XScreen()`), وأي نمطٍ يبحث عن `),` يقف
  /// عند أوّلها فيقصّ نصف التعريف — وقد أخفى عنّي فعلاً وسيطاً موجوداً.
  Map<String, String> catalogEntries() {
    final src = hub.readAsStringSync();
    final out = <String, String>{};
    for (final chunk in src.split("_Svc('").skip(1)) {
      final code = chunk.substring(0, chunk.indexOf("'"));
      // آخر تعريف يمتدّ إلى نهاية الملفّ فيبتلع تعريف الصنف `_Svc` نفسه —
      // وفيه كلمة onlyFor، فيمرّ الفحص على آخر خدمة دائماً. يُقصّ عند نهاية
      // القائمة.
      final end = chunk.indexOf('\n  ];');
      out[code] = end == -1 ? chunk : chunk.substring(0, end);
    }
    return out;
  }

  List<String> catalogCodes() => catalogEntries().keys.toList();

  test('الملفّان في مكانهما — وإلّا فحصنا العدم', () {
    // حارسٌ لا يجد مصدره يمرّ دائماً. ولو نُقل أيّ من الملفّين وجب أن نعلم
    // هنا لا أن يصمت الفحص.
    expect(hub.existsSync(), isTrue, reason: 'شاشة الخدمات غير موجودة: ${hub.path}');
    expect(constants.existsSync(), isTrue,
        reason: 'ثوابت الصلاحيات غير موجودة: ${constants.path} — '
            'المستودع أحاديّ ويضمّ الخلفية والتطبيق معاً');

    expect(serverFeatures(), contains('inventory'),
        reason: 'استخراج ثوابت PHP فشل — تغيّرت صياغتها والفحص صار أعمى');
    expect(catalogCodes().length, greaterThanOrEqualTo(20),
        reason: 'استخراج أكواد الكتالوج فشل — تغيّرت صياغة _Svc');
  });

  test('كل كود خدمة يعرفه الخادم', () {
    final known = serverFeatures();
    final unknown = catalogCodes().where((c) => !known.contains(c)).toList();

    expect(unknown, isEmpty,
        reason: 'أكواد لا يعرفها الخادم: ${unknown.join('، ')}\n'
            'ستُرجع access.has لها false دائماً، فتظهر الخدمة مقفلة لمن '
            'يملكها ومعها دعوةُ ترقيةٍ لا تفتح شيئاً. أضِف الثابت في '
            'AccessConstants ووزّعه في AccessPresets، أو صحّح الكود هنا.');
  });

  test('كل خدمة لها شاشة، إلّا ما وُثّق أنه بلا شاشة', () {
    // بطاقةٌ بلا `builder` تفتح ورقةً تقول «تعمل تلقائياً داخل الكاشير».
    // وهي عبارة صحيحة لخدمةٍ خلفية، وكذبةٌ لخدمةٍ شاشتها مبنيّة ومهملة —
    // وهذا ما كان يحدث لـ«البيع دون اتصال»: الشاشة موجودة والبطاقة تقول
    // لا حاجة لها.
    final src = hub.readAsStringSync();
    final withoutScreen = RegExp(r"_Svc\('([a-z0-9_]+)'[\s\S]{0,400}?,\s*null\)")
        .allMatches(src)
        .map((m) => m.group(1)!)
        .toSet();

    // لا استثناءات اليوم. أيّ إضافة هنا تحتاج سطراً يشرح لماذا لا شاشة لها.
    const documentedHeadless = <String>{};

    expect(withoutScreen.difference(documentedHeadless), isEmpty,
        reason: 'خدمات بلا شاشة وبلا توثيق: ${withoutScreen.join('، ')}\n'
            'إمّا أن تُربط بشاشتها، وإمّا تُضاف إلى documentedHeadless '
            'مع سبب — كي لا يمرّ الإهمال بصمت.');
  });

  test('الخدمات المقصورة على أنواع نشاط تُعلن ذلك بـ onlyFor', () {
    // الميزة التي يمنحها الخادم حسب business_type لا حسب الباقة لا تفتحها
    // أغلى باقة. فإن لم تُعلَن، وضعتها الشاشة في «متاح بترقية الباقة» —
    // ووعدت صاحبَ الباقة المؤسسية بترقيةٍ فوق الأعلى.
    //
    // المرجع: AccessPresets::businessTypeFeatures — ما ورد هناك ولم يرد في
    // أيّ planFeatures يجب أن يحمل onlyFor.
    final presets =
        File('../01_backend/app/Support/Access/AccessPresets.php');
    expect(presets.existsSync(), isTrue, reason: 'AccessPresets غير موجود');

    final src = presets.readAsStringSync();
    final bizBlock = src.substring(
        src.indexOf('businessTypeFeatures'), src.indexOf('planFeatures'));
    final planBlock = src.substring(src.indexOf('planFeatures'));

    // ما يمنحه الدور نفسه ممنوحٌ لكل تاجر مهما كان نشاطه أو باقته، فلا
    // يُطلب له onlyFor. أغفلتُ هذا أوّل مرّة فاتّهم الفحصُ «الإيصالات»
    // و«تقرير اليوم» ظلماً — وهما في roleBase.
    final merchantBase = src.substring(
        src.indexOf('A::ROLE_MERCHANT => ['),
        src.indexOf('A::ROLE_ADMIN => ['));
    final common = RegExp(r'\$common = \[([^\]]*)\]').firstMatch(src)!.group(1)!;

    Set<String> consts(String block) => RegExp(r'A::(F_\w+)')
        .allMatches(block)
        .map((m) => m.group(1)!)
        .toSet();

    final alwaysGranted = consts(planBlock)
        .union(consts(merchantBase))
        .union(consts(common));
    final bizOnly = consts(bizBlock).difference(alwaysGranted);
    expect(bizOnly, isNotEmpty,
        reason: 'لم يُستخرج شيء — تغيّرت صياغة AccessPresets');

    // ترجمة الثوابت إلى قيمها.
    final values = <String, String>{};
    for (final m in RegExp(r"const (F_\w+) = '([a-z0-9_]+)'")
        .allMatches(constants.readAsStringSync())) {
      values[m.group(1)!] = m.group(2)!;
    }
    final bizOnlyCodes = bizOnly.map((c) => values[c]).whereType<String>().toSet();

    final entries = catalogEntries();
    final missing = entries.entries
        .where((e) => bizOnlyCodes.contains(e.key))
        .where((e) => !e.value.contains('onlyFor'))
        .map((e) => e.key)
        .toList();

    expect(missing, isEmpty,
        reason: 'خدمات يمنحها نوع النشاط لا الباقة وتفتقد onlyFor: '
            '${missing.join('، ')}\n'
            'بدونها تُعرض على من لا يملكها بوعد «ترقّ باقتك» — وهي ترقيةٌ '
            'لن تفتحها مهما دفع.');
  });
}
