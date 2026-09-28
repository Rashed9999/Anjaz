import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

/// AMIAL-MERCHANT-CUSTOMER-SURFACE-001
///
/// حارس حدود المنتج: شاشة العميل لا تُستعمل مركزَ خدمات للتاجر. هذا اختبار
/// مصدر متعمد لأن المشكلة تظهر من navigation قبل وجود شبكة أو بيانات تجريبية.
void main() {
  final customerServices = File('lib/features/me/screens/my_services_screen.dart');
  final customerProfile = File('lib/features/setting/screens/profile_screen.dart');
  final portalNotice = File('lib/features/access/screens/web_portal_notice_screen.dart');
  final posHome = File('lib/features/merchant/screens/pos_employee_home_screen.dart');

  test('مصادر حدود العميل والتاجر موجودة', () {
    for (final file in [customerServices, customerProfile, portalNotice, posHome]) {
      expect(file.existsSync(), isTrue, reason: 'ملف مفقود: ${file.path}');
    }
  });

  test('route قديم إلى خدمات العميل لا يحمّل بيانات العميل للتاجر', () {
    final src = customerServices.readAsStringSync();
    expect(src, contains('_merchantAtEntry = Get.find<AccessController>().isMerchantSession'));
    expect(src, contains('if (_merchantAtEntry) return;'));
    expect(src, contains('if (_merchantAtEntry || access.isMerchantSession)'));
    expect(src, contains("return const WebPortalNoticeScreen(role: 'merchant');"));
    expect(src, isNot(contains('MerchantServicesHubScreen'));
  });

  test('التاجر لا يفتح حساب العميل أو لوحة منشأة قديمة من التطبيق', () {
    final profile = customerProfile.readAsStringSync();

    expect(profile, contains("return const WebPortalNoticeScreen(role: 'merchant');"));
    expect(profile, isNot(contains('MerchantAccountScreen'));
  });

  /// ══════════════════════════════════════════════════════════════════
  /// **القصدُ باقٍ، والوجهةُ صُحّحت.**
  ///
  /// كان هذا الفحصُ يشترط بالحرف:
  ///
  ///     expect(dispatcher, contains('return const MerchantPosHomeScreen();'));
  ///
  /// **وكتبه الالتزامُ نفسُه الذي غيّر الوجهة** (`797638b` — «fix:
  /// separate customer and merchant surfaces»): استبدل في المُرسِل
  /// `PosEmployeeHomeScreen` — ٤٤٣ سطراً بناها `a3e14e1` قبله بثلاثة
  /// أيّام — بشاشةٍ من ستّةٍ وعشرين سطراً تمرّر إلى البيع مباشرةً، **ثمّ
  /// كتب حارساً يثبّت الاستبدال**. فصار الحارسُ يحرس الانحدار.
  ///
  /// **وما فقده الكاشيرُ مقيس**: ثمانيةُ أبوابٍ لا تُبلَغ من شاشة البيع
  /// — **إقفالُ الورديّة** وتقريرُها والمرتجعاتُ والإيصالاتُ والأصنافُ
  /// وعملاءُ الآجل والإشعاراتُ وملفُّه. **وكاشيرٌ لا يُقفل ورديّتَه**
  /// عاطلٌ في آخر ما يفعله في يومه.
  ///
  /// **والقصدُ المكتوبُ في عنوان الفحص صحيحٌ ويبقى**: «مساحةُ بيعٍ
  /// محدودة، لا لوحةَ مالكٍ ولا حسابَ عميل» — و`PosEmployeeHomeScreen`
  /// تفي به، وتُثبته `pos_employee_has_a_cashier_screen_test` بتسمية
  /// أبواب المالك واحداً واحداً. **فيُصحَّح المشترطُ ولا يُنزَع الشرط.**
  /// ══════════════════════════════════════════════════════════════════
  test('موظف POS يفتح مساحة بيع محدودة لا لوحة مالك أو حساب عميل', () {
    final dispatcher =
        File('lib/features/access/screens/home_dispatcher_screen.dart')
            .readAsStringSync();
    final pos =
        File('lib/features/merchant/screens/pos_employee_home_screen.dart')
            .readAsStringSync();

    expect(dispatcher, contains('_access.isPosStaff'),
        reason: 'المُرسِلُ لا يفرّق موظّفَ نقطة البيع — فيسقط إلى '
            '`userHomeFallback`، وهي لوحةُ المالك.');

    expect(dispatcher, contains('return const PosEmployeeHomeScreen();'),
        reason: 'وجهةُ الكاشير ليست شاشتَه — وشاشةٌ تمرّر إلى البيع '
            'مباشرةً تحرمه إقفالَ ورديّته.');

    // وسيرُ البيع يتبع صنفَ نشاط صاحبه — والكاشيرُ يرثه.
    expect(pos, contains('FuelSaleScreen'));
    expect(pos, contains('PharmacySaleScreen'));
    expect(pos, contains('CashierPosScreen'));

    // ولا بابَ مالكٍ في شاشته.
    for (final door in const ['MerchantWalletScreen', 'WithdrawRequestScreen']) {
      expect(pos, isNot(contains(door)),
          reason: '**بابُ مالكٍ في شاشة الكاشير: $door** — يُضغط فيردّه '
              'الخادمُ بـ٤٠٣، ويُقرأ عطلاً في التطبيق.');
    }
  });
}
