import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

/// AMIAL-POS-HOME-001 — **الكاشيرُ يفتح شاشةَ كاشير، لا لوحةَ المالك.**
///
/// ══════════════════════════════════════════════════════════════════════
/// **ما قِيس قبل هذا الحارس**، ولم يشتكِ منه أحدٌ لأنّه لا يُنتج خطأً:
///
/// `RoleRouter` يرسل `'pos'` إلى `HomeDispatcherScreen`. وكلُّ فرعٍ في
/// المُرسِل يسأل `_access.isMerchant`، وهي `role.value == 'merchant'`
/// **ودورُ الكاشير `'pos'`** — فيسقط من الفروع الستّة كلِّها ومن
/// `isAgent` و`isAdmin` إلى آخر سطر:
///
///     return widget.userHomeFallback;      // ← MerchantDashboardScreen
///
/// **فيهبط في لوحة المالك، وخارجَ `_merchantShell`**: بلا درجٍ وبلا
/// مراكزِ قدراتٍ وبلا زرِّ عودة. ويقرأ فيها رصيدَ صاحبه، ويرى «سحب
/// رصيدي» و«موظفو نقاط البيع» و«إعدادات المتجر».
///
/// **ولا يمسكه شيءٌ ممّا كان قائماً:** الشاشةُ تُبنى، والأزرارُ تُضغط،
/// والخادمُ يردّ ٤٠٣ حين تصل. لا استثناءَ ولا سطرَ في أيّ سجلّ.
/// (القاعدة التاسعة: قياسُ ما بعد الضغطة أثرُها لا غيابُ الخطأ.)
///
/// ══════════════════════════════════════════════════════════════════════
/// **ويُفحص أربعةُ أشياءَ لا واحد**، لأنّ ثلاثةً منها كافيةٌ وحدَها
/// لإعادة العطل: الفرعُ موجود · **وقبلَ فروع القطاعات** · والشاشةُ
/// موجودةٌ وتفتح بيعاً · ولا تحمل بابَ مالكٍ واحداً.
void main() {
  final dispatcher =
      File('lib/features/access/screens/home_dispatcher_screen.dart');
  final posHome =
      File('lib/features/merchant/screens/pos_employee_home_screen.dart');
  final access =
      File('lib/features/access/controllers/access_controller.dart');
  final dashboard =
      File('lib/features/merchant/screens/merchant_dashboard_screen.dart');

  test('الملفّاتُ في مواضعها — وإلّا فحصنا العدم', () {
    for (final f in [dispatcher, posHome, access, dashboard]) {
      expect(f.existsSync(), isTrue, reason: 'مفقود: ${f.path}');
    }
  });

  test('الفاعلُ يُقرأ من الخادم ولا يُخمَّن من الدور', () {
    final src = access.readAsStringSync();

    expect(src.contains("access['actor']"), isTrue,
        reason: '**`actor` لا يُقرأ.** والخادمُ يصرّح به منذ '
            '`AMIAL-ACTOR-001` — مالكٌ · موظّفُ نقطة بيع · موظّفُ أدوار · '
            'عميل. وبلا قراءته لا سبيلَ إلى التفريق إلّا بالدور، '
            'و`\'pos\'` ليس في `ALL_ROLES` أصلاً.');

    expect(src.contains('bool get isPosStaff'), isTrue,
        reason: 'لا مُميِّزَ لموظّف نقطة البيع في متحكّم الوصول');

    expect(src.contains('bool get isMerchantOwner'), isTrue,
        reason: '**لا مُميِّزَ للمالك** — والملكيّةُ ليست ميزةً تُفحص '
            'بـ`has()`: الكاشيرُ يرث ميزاتِ صاحبه، فيمرّ من حاجزها.');

    // **وتُصفَّر عند الخروج** — وإلّا بقي «كاشير» فيدخل المالكُ على
    // جهازه فيجد شاشةَ موظّفه.
    final reset = src.substring(src.indexOf('void reset()'));
    expect(reset.contains("actor.value = 'customer'"), isTrue,
        reason: 'الفاعلُ لا يُصفَّر عند تسجيل الخروج');
  });

  test('المُرسِلُ يفرّق الكاشيرَ — وقبل فروع القطاعات', () {
    final src = dispatcher.readAsStringSync();

    final posBranch = src.indexOf('isPosStaff');

    expect(posBranch, greaterThan(-1),
        reason: '**لا فرعَ لموظّف نقطة البيع في المُرسِل** — فيسقط من '
            'كلّ فروع `isMerchant` إلى `userHomeFallback`، وهي لوحةُ '
            'المالك.');

    expect(src.contains('PosEmployeeHomeScreen'), isTrue,
        reason: 'الفرعُ موجودٌ ولا يفتح شاشةَ كاشير');

    // ══════════════════════════════════════════════════════════════
    // **والترتيبُ جزءٌ من الإصلاح لا زينةٌ فيه.**
    //
    // الكاشيرُ يرث صنفَ نشاط صاحبه (`business_type`)، فلو وقع فرعُه
    // بعد فروع القطاعات لالتقطه أوّلُها **وفتح له لوحةَ إدارة القطاع**
    // — `FuelOwnerConsoleScreen` مثلاً — لا شاشةَ بيعه.
    //
    // فيُقاس الموضعُ لا الوجود.
    // ══════════════════════════════════════════════════════════════
    final firstSectorBranch = RegExp(r'_access\.isMerchant\s*&&')
        .firstMatch(src)
        ?.start;

    expect(firstSectorBranch, isNotNull,
        reason: 'لم تُقرأ فروعُ القطاعات — الحارسُ يفحص مجموعةً فارغة');

    expect(posBranch, lessThan(firstSectorBranch!),
        reason: '**فرعُ الكاشير بعد فروع القطاعات** — فيرث صنفَ صاحبه '
            'فيلتقطه أوّلُها ويفتح له **لوحةَ إدارة** القطاع بدل شاشة '
            'بيعه. والوجودُ وحدَه لا يكفي: الموضعُ هو الإصلاح.');
  });

  test('شاشةُ الكاشير تفتح بيعاً، ولا تحمل بابَ مالك', () {
    final src = posHome.readAsStringSync();

    // ── تفتح بيعاً فعلاً ──
    expect(src.contains('CashierPosScreen'), isTrue,
        reason: 'شاشةُ الكاشير لا تفتح الكاشير — والبيعُ هو عملُه كلُّه');

    // وبحسب صنف نشاط صاحبه: كاشيرُ محطّةٍ لا يُفتح له كاشيرُ بقالة.
    expect(src.contains('FuelSaleScreen'), isTrue,
        reason: 'كاشيرُ محطّة الوقود يُفتح له كاشيرُ بقالة — ومسارُ '
            'البيع هناك `FuelSaleScreen` وحدَه');

    // ── ولا بابَ مالكٍ واحد ──
    //
    // **وتُسمّى الأبوابُ واحداً واحداً** لا بقائمةٍ مفتوحة: بابٌ يُضاف
    // غداً يسقط هنا باسمه فيُراجَع، ولا يمرّ في زحام.
    const ownerDoors = {
      'WithdrawRequestScreen': 'سحب رصيد المتجر',
      'MerchantStaffScreen': 'إدارة الموظفين',
      'MerchantServicesHubScreen': 'إعدادات المتجر',
      'MerchantWalletScreen': 'محفظة المتجر',
      'PlansCatalogScreen': 'الترقية — والكاشيرُ لا يشتري باقةَ صاحبه',
      'MerchantAdaptiveShell': 'درجُ المالك',
    };

    final leaks = <String>[];
    ownerDoors.forEach((screen, why) {
      if (src.contains(screen)) leaks.add('  $screen — $why');
    });

    expect(leaks, isEmpty,
        reason: '**أبوابُ مالكٍ في شاشة الكاشير:**\n${leaks.join('\n')}\n\n'
            'وضغطُها يُردّ من الخادم بـ٤٠٣ «متاح للتجّار فقط». '
            '**وزرٌّ يُرسَم ثمّ يُصفع أسوأُ من غيابه**: الغيابُ يُسأل '
            'عنه، والرفضُ يُقرأ عطلاً في التطبيق.');
  });

  test('وأبوابُ المالك في لوحته محروسةٌ بالملكيّة لا بالميزة', () {
    final src = dashboard.readAsStringSync();

    // القالبُ المطلوب: `ownerOnly: true` يسبق كلَّ واحدٍ من هذه الشاشات.
    const mustBeOwnerGated = [
      'MerchantWalletScreen',
      'MerchantStaffScreen',
      'MerchantServicesHubScreen',
    ];

    final ungated = <String>[];

    for (final screen in mustBeOwnerGated) {
      // **يُبحَث عن النداء لا عن الاسم** — وسطرُ الاستيراد في رأس
      // الملفّ يحمل الاسمَ نفسَه ولا حاجزَ قبله، فالبحثُ عنه يجعل
      // الحارسَ يسقط أبداً ولو كان الزرُّ محروساً. (وقد سقط أوّلَ
      // تشغيلٍ لهذا السبب، فصُحّح.)
      final at = src.indexOf('const $screen()');
      expect(at, greaterThan(-1), reason: 'اختفى $screen من اللوحة');

      // **يُفتَّش عن الحاجز في الكتلة السابقة للنداء وحدَها** — ووجودُه
      // في مكانٍ آخر من الملفّ لا يحرس هذا الزرّ.
      final window = src.substring((at - 420).clamp(0, at), at);
      if (!window.contains('ownerOnly: true')) ungated.add('  $screen');
    }

    expect(ungated, isEmpty,
        reason: '**أبوابُ مالكٍ بلا حدِّ ملكيّة:**\n${ungated.join('\n')}\n\n'
            '**وحاجزُ الميزة وحدَه لا يكفي هنا**: `restrictToPosPermissions` '
            'تُبقي للكاشير ما مُنح من صلاحيّات صاحبه، فمن أُعطي '
            '`employees` (مديرُ عمليّاتٍ مثلاً) **يملك الميزةَ ولا يملك '
            'المتجر** — فيمرّ الحاجزُ ويردّ الخادمُ ٤٠٣.');
  });
}
