import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

/// صفحة الترقية يجب أن تبيع ما يخص قطاع التاجر وما هو جاهز فعلاً فقط.
void main() {
  final repo = File('lib/features/plans/domain/repositories/plans_repo.dart');
  final controller = File('lib/features/plans/controllers/plans_controller.dart');

  test('كتالوج الباقات يُطلب بحسب نوع النشاط من عقد الاستحقاقات', () {
    expect(repo.readAsStringSync(), contains('/plans/capabilities'));
    expect(repo.readAsStringSync(), contains('business_type'));
    expect(controller.readAsStringSync(), contains('access?.businessType.value'));
  });

  test('لا تظهر ميزة قريباً ضمن ميزات باقة قابلة للشراء', () {
    expect(controller.readAsStringSync(), contains("== 'available'"));
  });
}
