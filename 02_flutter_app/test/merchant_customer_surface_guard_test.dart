import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

/// AMIAL-MERCHANT-CUSTOMER-SURFACE-001
///
/// حارس حدود المنتج: شاشة العميل لا تُستعمل مركزَ خدمات للتاجر. هذا اختبار
/// مصدر متعمد لأن المشكلة تظهر من navigation قبل وجود شبكة أو بيانات تجريبية.
void main() {
  final customerServices = File('lib/features/me/screens/my_services_screen.dart');
  final customerProfile = File('lib/features/setting/screens/profile_screen.dart');
  final merchantDrawer = File('lib/features/merchant/screens/merchant_adaptive_shell.dart');
  final merchantHome = File('lib/features/access/screens/role_based_home_screens.dart');
  final merchantSettings = File('lib/features/merchant/screens/merchant_account_screen.dart');
  final posHome = File('lib/features/merchant/screens/merchant_pos_home_screen.dart');

  test('مصادر حدود العميل والتاجر موجودة', () {
    for (final file in [customerServices, customerProfile, merchantDrawer, merchantHome, merchantSettings, posHome]) {
      expect(file.existsSync(), isTrue, reason: 'ملف مفقود: ${file.path}');
    }
  });

  test('route قديم إلى خدمات العميل لا يحمّل بيانات العميل للتاجر', () {
    final src = customerServices.readAsStringSync();
    expect(src, contains('_merchantAtEntry = Get.find<AccessController>().isMerchantSession'));
    expect(src, contains('if (_merchantAtEntry) return;'));
    expect(src, contains('if (_merchantAtEntry || access.isMerchantSession)'));
    expect(src, contains('return const MerchantServicesHubScreen();'));
  });

  test('التاجر لا يفتح حساب العميل من قائمة أو لوحة تشغيله', () {
    final profile = customerProfile.readAsStringSync();
    final drawer = merchantDrawer.readAsStringSync();
    final home = merchantHome.readAsStringSync();

    expect(profile, contains('return const MerchantAccountScreen();'));
    expect(drawer, contains("label: 'إعدادات المنشأة'"));
    expect(drawer, isNot(contains('const ProfileScreen()')));
    expect(home, isNot(contains('const MyServicesScreen()')));
    expect(home, contains('const MerchantServicesHubScreen()'));
  });

  test('موظف POS يفتح مساحة بيع محدودة لا لوحة مالك أو حساب عميل', () {
    final dispatcher = File('lib/features/access/screens/home_dispatcher_screen.dart').readAsStringSync();
    final pos = posHome.readAsStringSync();

    expect(dispatcher, contains('if (_access.isPos)'));
    expect(dispatcher, contains('return const MerchantPosHomeScreen();'));
    expect(pos, contains('if (access.isFuel) return const FuelSaleScreen();'));
    expect(pos, contains('if (access.isPharmacy) return const PharmacySaleScreen();'));
    expect(pos, contains('return const CashierPosScreen();'));
  });
}
