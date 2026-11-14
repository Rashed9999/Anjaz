import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

/// لا يكفي أن يهبط المالك في بوابة الويب بعد الدخول؛ الروابط القديمة
/// والمسارات القادمة من سجل القدرات يجب أن تقوده إليها أيضاً.
void main() {
  final routes = File('lib/helper/route_helper.dart');

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
}
