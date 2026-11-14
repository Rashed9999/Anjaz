import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

/// AMIAL-QUICK-SALE-VERIFY-001 — التسجيل لا يكفي لاعتماد المنشأة.
///
/// القطاع السريع لا يحتاج سجلاً تجارياً، لكن يجب أن يستطيع مالكه الوصول
/// إلى رفع الهوية وصورة نقطة البيع. الحارس يفحص المدخل والتسجيل وسياسة
/// الوثائق معاً، لا وجود الشاشة وحدها.
void main() {
  final home = File('lib/features/access/screens/role_based_home_screens.dart');
  final screen = File(
      'lib/features/merchant_verification/screens/merchant_verification_screen.dart');
  final controller = File(
      'lib/features/merchant_verification/controllers/merchant_verification_controller.dart');

  test('quick sale has a working merchant-verification entry point', () {
    final source = home.readAsStringSync();

    expect(source, contains('MerchantVerificationScreen'));
    expect(source, contains('ملف اعتماد المتجر'));
  });

  test('verification screen self-registers its controller and follows server document policy', () {
    final screenSource = screen.readAsStringSync();
    final controllerSource = controller.readAsStringSync();

    expect(controllerSource, contains('ensureRegistered()'));
    expect(screenSource, contains('MerchantVerificationController.ensureRegistered()'));
    expect(screenSource, contains('_requiredDocKeys()'));
    expect(screenSource, contains("requiredDocs.contains('commercial_register')"));
    expect(screenSource,
        isNot(contains("['id_card_front', 'id_card_back', 'commercial_register', 'store_photo']")));
  });
}
