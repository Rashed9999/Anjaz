import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

/// صفحة الترقية يجب أن تبيع ما يخص قطاع التاجر وما هو جاهز فعلاً فقط.
void main() {
  final repo = File('lib/features/plans/domain/repositories/plans_repo.dart');
  final controller = File('lib/features/plans/controllers/plans_controller.dart');
  final map = File('lib/features/entitlements/capability_screens.dart');

  test('كتالوج الباقات يُطلب بحسب نوع النشاط من عقد الاستحقاقات', () {
    expect(repo.readAsStringSync(), contains('/plans/capabilities'));
    expect(repo.readAsStringSync(), contains('business_type'));
    expect(controller.readAsStringSync(), contains('access?.businessType.value'));
  });

  test('لا تظهر ميزة قريباً ضمن ميزات باقة قابلة للشراء', () {
    expect(controller.readAsStringSync(), contains("== 'available'"));
  });

  test('الأعمال تفتح شاشات التقارير وعميل الصيدلية المتخصصة', () {
    final src = map.readAsStringSync();
    expect(src, contains("'advanced_reports': () => const MerchantAdvancedReportsScreen()"));
    expect(src, contains("'profit_reports': () => const ProfitReportScreen()"));
    expect(src, contains("'pharmacy_customers': () => const PharmacyCustomersScreen()"));
  });

  /// ══════════════════════════════════════════════════════════════════
  /// **قدرةٌ هي فعلٌ داخل شاشةٍ لا تُسنَد إلى شاشة — وتبقى موصولة.**
  ///
  /// كان هذا الملفُّ يشترط سطرين:
  ///
  ///     'pharmacy_substitutions':     () => const PharmacyProductsScreen()
  ///     'pharmacy_batch_disposition': () => const PharmacyProductsScreen()
  ///
  /// **وهو يشترط العطلَ نفسَه الذي أُصلح**: «البدائل» و«إخراج الدفعة
  /// المنتهية» و«الأصناف» ثلاثةُ أسماءٍ تُفضي إلى الشاشة الواحدة —
  /// يُضغط الزرُّ فيفتح **غيرَ ما يقول اسمُه**، ولا خطأَ في أيّ سجلّ.
  /// وهو بعينه ما يحرسه `capability_opens_a_feature_not_a_home_test`.
  /// **فحارسانِ تعارضا، وكان أحدُهما يطلب ردَّ العطل.**
  ///
  /// **والحذفُ وحدَه لا يكفي** — قدرةٌ تُباع في صفحة الباقات ولا مدخلَ
  /// لها هي «مبنيٌّ ولا يُوصَل إليه»، وهو نمطُ العطل الأكثرُ تكراراً
  /// هنا. فيُشترط الطرفان معاً: **لا وجهةَ في الخريطة**، **ومَوضعُ
  /// عملٍ يحرسها بـ`has()`**. (القاعدتان التاسعة والثانية عشرة.)
  /// ══════════════════════════════════════════════════════════════════
  test('وفعلُ الصنف يُحرَس حيث يُستعمَل — لا يُسنَد إلى شاشةٍ تكرّر أختَها', () {
    final src = map.readAsStringSync();
    final pharmacy =
        File('lib/features/pharmacy/screens/pharmacy_dashboard_screen.dart')
            .readAsStringSync();

    for (final code in const [
      'pharmacy_substitutions',
      'pharmacy_batch_disposition',
    ]) {
      expect(src, isNot(contains("'$code': () =>")),
          reason: '**«$code» أُسنِد إلى شاشة.** وهو فعلٌ داخل شاشة '
              'الأصناف لا وجهةٌ مستقلّة — فإسنادُه يرسم زرّاً باسمه '
              'يفتح شاشةَ الأصناف نفسَها، فيعمل الزرُّ ويفتح غيرَ ما '
              'يقول.');

      expect(pharmacy, contains("has('$code')"),
          reason: '**«$code» بلا وجهةٍ وبلا حارسٍ في موضع عمله** — '
              'فهي قدرةٌ تُباع في صفحة الباقات ولا يصل إليها أحد. '
              'ونزعُها من الخريطة صحيحٌ **بشرط** أن تبقى موصولةً حيث '
              'تُستعمَل.');
    }
  });
}
