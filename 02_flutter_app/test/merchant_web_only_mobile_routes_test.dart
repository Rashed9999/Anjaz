import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

/// لا يكفي أن يهبط المالك في بوابة الويب بعد الدخول؛ الروابط القديمة
/// والمسارات القادمة من سجل القدرات يجب أن تقوده إليها أيضاً.
void main() {
  final routes = File('lib/helper/route_helper.dart');
  final capabilities =
      File('lib/features/entitlements/capability_screens.dart');

  test('كل مسار إدارة مالك في التطبيق يحيل إلى بوابة المنشأة', () {
    final source = routes.readAsStringSync();

    expect(source, contains("WebPortalNoticeScreen(role: 'merchant')"));
    for (final route in const [
      'posDevices', 'operationsCenter', 'staff', 'branches', 'expenses',
      'auditLog', 'backup', 'fuel', 'pharmacy', 'wholesale', 'apiKeys',
      'corporate',
    ]) {
      expect(source, contains('GetPage(name: $route, page: _merchantPortal)'),
          reason: 'المسار $route ما زال يفتح شاشة مالك داخل التطبيق.');
    }
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
