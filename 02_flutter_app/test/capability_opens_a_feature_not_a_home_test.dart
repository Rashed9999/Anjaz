import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

/// AMIAL-CAP-HOME-001 — **القدرةُ تفتح ميزتَها، لا شاشةَ رئيسيّةٍ ثانية.**
///
/// ══════════════════════════════════════════════════════════════════════
/// **الثمن، بنصّ صاحب المشروع أمام ستّ صور:**
///
///     «كيف تدّعي أنّك أصلحتَ حسابات التجّار وشاشاتها… انظر ما هذه
///      العشوائية. لقد أضعتَ وقتي والدقائق المدفوعة».
///
/// وقِيس فكان محقّاً، **والعشوائيّةُ لها مصدرٌ واحدٌ بعينه**: خريطةُ
/// القدرات كانت تفتح **شاشاتِ رئيسيّةٍ كاملة** بدل شاشات الميزات.
///
///     quick_sale         → MerchantQuickSaleHomeScreen   ← رئيسيّة
///     pharmacy_pos       → PharmacyDashboardScreen       ← رئيسيّة
///     pharmacy_products  → PharmacyDashboardScreen       ← رئيسيّة
///     pharmacy_batches   → PharmacyDashboardScreen       ← رئيسيّة
///     pharmacy_alerts    → PharmacyDashboardScreen       ← رئيسيّة
///     pharmacy_customers → PharmacyCustomersScreen       ← الميزة نفسها
///     wholesale_invoices → WholesaleDashboardScreen      ← رئيسيّة
///
/// **فتاجرُ تجزئةٍ يضغط «البيع السريع» فيهبط في رئيسيّةٍ ثانية** ببطاقة
/// ترحيبٍ أخرى وأزرارٍ أخرى — رئيسيّتان لحسابٍ واحد. **وخمسةُ أزرارٍ
/// مختلفةِ الأسماء في الصيدليّة تُفضي إلى شاشةٍ واحدة**، وشاشاتُها
/// الحقيقيّةُ (`PharmacyProductsScreen` · `PharmacyCustomersScreen` ·
/// `PharmacyAlertsScreen` · `PharmacySaleScreen`) مبنيّةٌ في الملفّ ذاته
/// **ولا يفتحها شيء**.
///
/// ══════════════════════════════════════════════════════════════════════
/// **ولا يمسكه شيءٌ ممّا كان قائماً:** كلُّ سطرٍ منها صحيحٌ وحدَه —
/// الشاشةُ موجودة، والقدرةُ معلَنة، والزرُّ يُضغط ويفتح شاشة. **والخللُ
/// في أنّ ما يُفتَح غيرُ ما يقوله الزرّ** — وهو ما يُقرأ عشوائيّةً ولا
/// يُنتج خطأً في أيّ سجلّ.
///
/// (القاعدة التاسعة: «قياسُ ما بعد الضغطة أثرُها لا غيابُ الخطأ» —
/// والانتقالُ إلى الشاشة الخطأ لا يُنتج خطأً في أيّ مكان.)
void main() {
  final mapFile = File('lib/features/entitlements/capability_screens.dart');
  final dispatcher =
      File('lib/features/access/screens/home_dispatcher_screen.dart');

  test('الملفّان في مكانهما — وإلّا فحصنا العدم', () {
    expect(mapFile.existsSync(), isTrue);
    expect(dispatcher.existsSync(), isTrue);
  });

  /// الوجهاتُ العليا التي يوزّع إليها التطبيق بعد فصل المالك إلى الويب.
  ///
  /// لم يعد هناك `_merchantShell(...)` ولا أربع رئيسيّات تاجر داخل
  /// التطبيق. المساران المقصودان فقط: موظف POS إلى سطحه التشغيلي،
  /// والمالك/الوكيل/الإدارة إلى إشعار البوابة.
  Set<String> dispatcherRoleDestinations() {
    final src = dispatcher.readAsStringSync();
    final out = <String>{};

    for (final m in RegExp(
      r"return const (PosEmployeeHomeScreen|WebPortalNoticeScreen)\\b",
    ).allMatches(src)) {
      out.add(m.group(1)!);
    }

    return out;
  }

  /// القدرةُ ← اسمُ الشاشة التي تفتحها.
  Map<String, String> capabilityTargets() {
    final src = mapFile.readAsStringSync();
    final out = <String, String>{};

    for (final m in RegExp(
            r"'([a-z0-9_.]+)':\s*\(\)\s*=>\s*const ([A-Za-z0-9_]+)\(")
        .allMatches(src)) {
      out[m.group(1)!] = m.group(2)!;
    }

    return out;
  }

  test('المُرسِلُ يثبت فصل المالك عن موظف POS', () {
    final destinations = dispatcherRoleDestinations();

    expect(destinations, contains('PosEmployeeHomeScreen'),
        reason: 'موظف POS فقد سطحه التشغيلي بعد تسجيل الدخول');
    expect(destinations, contains('WebPortalNoticeScreen'),
        reason: 'مالك المنشأة لم يعد موجّهاً بوضوح إلى بوابة الويب');

    final src = dispatcher.readAsStringSync();
    expect(src.contains('_merchantShell('), isFalse,
        reason: 'عاد غلاف رئيسية التاجر القديمة إلى التطبيق بعد نقل المالك للويب');
  });

  test('وخريطة تشغيل POS تُقرأ فعلاً ولا يفحص الحارس فراغاً', () {
    final targets = capabilityTargets();

    expect(targets.length, greaterThanOrEqualTo(20),
        reason: 'لم تُقرأ خريطة تشغيل POS الحالية أو تقلّصت بلا مراجعة');
    expect(targets.keys, containsAll(<String>[
      'cashier',
      'debts',
      'shift_close',
      'fuel_pos',
      'pharmacy_pos',
      'wholesale_invoices',
      'restaurant_orders',
    ]));
  });

  test('لا قدرة تشغيل POS تعيد الموظف إلى سطح المالك أو بوابة الويب', () {
    final targets = capabilityTargets();

    // ══════════════════════════════════════════════════════════════
    // **واستثناءٌ واحدٌ مكتوبٌ بسببه، لا قائمةٌ مفتوحة.**
    //
    // `RestaurantScreen` قطاعٌ كلُّه شاشةٌ واحدةٌ بتبويبين (الطاولاتُ
    // والطلباتُ معاً، والمطبخُ تبويبُها الثاني) — والطلبُ يُفتَح على
    // طاولة، ففصلُهما يُنتج شاشتين لعملٍ واحد. وهو مكتوبٌ في الخريطة
    // نفسِها منذ قبلُ، ومعه `initialTab` لتمييز المطبخ.
    //
    // **والاستثناءُ مسمّىً واحداً واحداً**: أيُّ شاشةِ رئيسيّةٍ أخرى
    // تدخل الخريطةَ غداً تسقط هنا.
    // ══════════════════════════════════════════════════════════════
    const forbidden = {
      'WebPortalNoticeScreen',
      'MerchantDashboardScreen',
      'MerchantAdaptiveShell',
      'PharmacyDashboardScreen',
      'WholesaleDashboardScreen',
    };

    final leaks = <String>[];

    targets.forEach((capability, screen) {
      if (forbidden.contains(screen)) {
        leaks.add('  $capability → $screen');
      }
    });

    expect(leaks, isEmpty,
        reason: '**قدرات تشغيل POS خرجت من سطح الموظف إلى سطح مالك:**\n'
            '${leaks.join('\n')}\n\n'
            'موظف نقطة البيع يجب أن يبقى داخل رحلة التشغيل المحمولة؛ '
            'إدارة المنشأة انتقلت إلى الويب.');
  });

  test('ولا خمسُ قدراتٍ تُفضي إلى شاشةٍ واحدة', () {
    final targets = capabilityTargets();
    final byScreen = <String, List<String>>{};

    targets.forEach((cap, screen) =>
        byScreen.putIfAbsent(screen, () => []).add(cap));

    // ══════════════════════════════════════════════════════════════
    // **حدٌّ لا مَنعٌ.** بعضُ الاجتماع مشروع: شاشةُ تقاريرَ واحدةٌ
    // تخدم «أرباح» و«تقارير متقدّمة»، وقطاعُ المطعم شاشةٌ بتبويبات.
    //
    // **والخمسةُ ليست اجتماعاً بل ضياع**: كان في الصيدليّة خمسُ قدراتٍ
    // بأسماءَ مختلفةٍ تفتح اللوحةَ نفسَها، وشاشاتُها مبنيّةٌ لا تُفتح.
    // فالحدُّ ثلاثةٌ: ما فوقَه يُراجَع ويُسمّى.
    // ══════════════════════════════════════════════════════════════
    final crowded = <String>[];

    byScreen.forEach((screen, caps) {
      if (caps.length > 3) {
        crowded.add('  $screen ← ${caps.length} قدرات: ${caps.join('، ')}');
      }
    });

    expect(crowded, isEmpty,
        reason: '**شاشةٌ واحدةٌ خلف أكثرَ من ثلاث قدرات:**\n'
            '${crowded.join('\n')}\n\n'
            'فأزرارٌ مختلفةُ الأسماء تُفضي إلى موضعٍ واحد — **يعمل الزرُّ '
            'ويفتح غيرَ ما يقول**. ويُراجَع: إمّا لكلٍّ شاشتُها، وإمّا '
            'تُدمَج القدراتُ في الخادم.');
  });

  test('وشاشتان يفتحهما التاجرُ نفسُه لا تحملان عنواناً واحداً', () {
    // ══════════════════════════════════════════════════════════════
    // كانت `MyServicesScreen` و`MyCapabilitiesScreen` كلتاهما «خدماتي»
    // — ويفتحهما التاجرُ من درجه في الجلسة نفسِها، **والدرجُ يسمّي
    // الثانيةَ «مزايا الباقة»**: ثلاثةُ أسماءٍ لشيئين.
    //
    // ولا يُفحَص تكرارُ العناوين في التطبيق كلِّه: «المنتجات» في
    // الصيدليّة وفي الجملة عنوانان مشروعان — لا يراهما تاجرٌ واحد.
    // **والمقصودُ ما يجتمع على شاشةٍ واحدة.**
    // ══════════════════════════════════════════════════════════════
    String titleOf(String path) {
      final src = File(path).readAsStringSync();

      for (final pat in [
        RegExp(r"title:\s*const Text\('([^']+)'\)"),
        RegExp(r"AmialScreenHeader\(title:\s*'([^']+)'"),
      ]) {
        final m = pat.firstMatch(src);
        if (m != null) return m.group(1)!;
      }

      return '';
    }

    final services = titleOf('lib/features/me/screens/my_services_screen.dart');
    final capabilities =
        titleOf('lib/features/entitlements/screens/my_capabilities_screen.dart');

    expect(services, isNotEmpty, reason: 'لم يُقرأ عنوانُ شاشة الخدمات');
    expect(capabilities, isNotEmpty, reason: 'لم يُقرأ عنوانُ شاشة المزايا');

    expect(capabilities, isNot(equals(services)),
        reason: '**شاشتان مختلفتان بعنوان «$services»** — ويفتحهما التاجرُ '
            'من درجه في الجلسة نفسِها، فيقرأ اسماً واحداً لشيئين.');
  });
}
