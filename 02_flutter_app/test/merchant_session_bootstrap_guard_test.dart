import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

/// AMIAL-AUTH-BOOTSTRAP-001
///
/// وجود رمز في secure storage لا يكفي: يجب أن يُحمّل في ApiClient قبل أول
/// شاشة API. من دون ذلك تُظهر الصيدلية وPOS «انتهت الجلسة» بعد إعادة تشغيل
/// التطبيق، رغم أن صاحب الحساب لم يسجّل خروجاً.
void main() {
  final di = File('lib/helper/get_di.dart');
  final authRepo = File('lib/features/auth/domain/reposotories/auth_repo.dart');
  final staff = File('lib/features/merchant/screens/merchant_staff_screen.dart');
  final shift = File('lib/features/merchant/screens/cashier_shift_screen.dart');

  test('رمز الجلسة يعاد إلى الذاكرة وترويسة API عند الإقلاع', () {
    final diSource = di.readAsStringSync();
    final repoSource = authRepo.readAsStringSync();

    expect(diSource, contains('await Get.find<AuthRepo>().primeTokenCache();'));
    expect(repoSource, contains('_cachedToken = token;'));
    expect(repoSource, contains('apiClient.updateHeader(token);'));
  });

  test('شاشات الأعمال لا تخفي فشل المصادقة أو الصلاحية كقائمة فارغة', () {
    final staffSource = staff.readAsStringSync();
    final shiftSource = shift.readAsStringSync();

    expect(staffSource, contains("r.statusCode == 401"));
    expect(staffSource, contains("r.statusCode == 403"));
    expect(shiftSource, contains("_error = _messageOf(r)"));
    expect(shiftSource, contains("r.statusCode == 401"));
  });
}