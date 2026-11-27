import 'dart:io';

import 'package:amial_pay/helper/route_helper.dart';

import 'package:flutter_test/flutter_test.dart';

/// لا يكفي أن يهبط المالك في بوابة الويب بعد الدخول؛ الروابط القديمة
/// والمسارات القادمة من سجل القدرات يجب أن تقوده إليها أيضاً.
void main() {
  final capabilities =
      File('lib/features/entitlements/capability_screens.dart');

  test('روابط التاجر ونقطة البيع غير مسجلة في تطبيق العميل', () {
    final names = RouteHelper.routes.map((route) => route.name).toSet();
    for (final path in [
      RouteHelper.cashier, RouteHelper.quickSale, RouteHelper.posDevices,
      RouteHelper.operationsCenter, RouteHelper.staff, RouteHelper.refunds,
      RouteHelper.retail, RouteHelper.fuel, RouteHelper.pharmacy,
      RouteHelper.wholesale, RouteHelper.apiKeys, RouteHelper.corporate,
    ]) {
      expect(names, isNot(contains(path)), reason: path);
    }
    expect(names, containsAll([RouteHelper.sendMoney, RouteHelper.support]));
  });

  test('خريطة القدرات تبقي POS محدوداً وتحيل قدرات المالك للويب', () {
    final source = capabilities.readAsStringSync();

    for (final code in const [
      'quick_sale', 'refunds', 'products', 'shift_close', 'fuel_pos',
      'pharmacy_pos',
    ]) {
      expect(source, contains("'$code': () =>"),
          reason: 'فُقدت عملية POS أساسية: $code');
    }
    for (final code in const [
      'employees', 'multi_pos', 'branches', 'profit_reports', 'expenses',
      'audit_log', 'api_access', 'retail.catalog',
    ]) {
      expect(source, contains("'$code'"),
          reason: 'اختفى رمز قدرة المالك من العقد: $code');
    }
    expect(source, contains('WebPortalNoticeScreen(role: \'merchant\')'));
  });
}
