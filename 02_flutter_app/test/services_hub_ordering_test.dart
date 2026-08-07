import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:get/get.dart';
import 'package:mocktail/mocktail.dart';

import 'package:amial_pay/features/access/controllers/access_controller.dart';
import 'package:amial_pay/features/access/domain/repositories/access_repo.dart';
import 'package:amial_pay/features/merchant/screens/merchant_services_hub_screen.dart';

class _FakeAccessRepo extends Mock implements AccessRepo {}

/// AMIAL-SERVICES-ORDER-001 — الترتيب يُقاس، لا يُفترض.
///
/// **ما اشتكى منه صاحب محطة الوقود:** «لماذا تجعل الشاشة قائمة طويلة؟
/// المفترض خدماتي — التي أشترك فيها في الأعلى والباقي مغلق في الأسفل.»
///
/// وفحصُ الوجود لا يلتقط هذا: كل البطاقات موجودة في الحالتين، والمقفلة
/// كانت موجودة قبل الإصلاح وبعده. الفرق **موضعٌ** على الشاشة، فيُقاس
/// الموضع — كما تعلّمنا في لوحة الأرقام حين مرّ فحص الوجود على «٣ ٢ ١».
void main() {
  /// يبني الشاشة لتاجرٍ بنشاطٍ وباقةٍ ومجموعة ميزات محدّدة.
  Future<void> pumpHub(
    WidgetTester tester, {
    required String businessType,
    required Set<String> features,
    String planLabel = 'المؤسسات',
  }) async {
    // القائمة كسولة: ما تحت الطيّة لا يُبنى أصلاً، فيقول الفحص «القسم غائب»
    // عن قسمٍ موجودٍ لكنه أسفل الشاشة. تُطال النافذة لتُبنى الأقسام كلّها،
    // فالمقيس هنا الترتيب لا ما يسعه هاتف.
    //
    // والعرض يبقى ضيّقاً بقصد: الشبكة عمودان بنسبة ضلع ثابتة، فتوسيع العرض
    // يُضخّم كل بطاقة ويدفع الأقسام الأخيرة خارج المبنيّ — وهو ما أوقعني
    // أوّل مرّة حين وسّعت البعدين معاً.
    tester.view.physicalSize = const Size(400, 9000);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    Get.reset();
    final access = Get.put(AccessController(repo: _FakeAccessRepo()));
    access.role.value = 'merchant';
    access.businessType.value = businessType;
    access.businessTypeLabel.value = 'محطة وقود';
    access.subscriptionPlanLabel.value = planLabel;
    access.features.addAll(features);
    access.isLoaded.value = true;

    await tester.pumpWidget(MaterialApp(
      locale: const Locale('ar'),
      home: const Directionality(
        textDirection: TextDirection.rtl,
        child: MerchantServicesHubScreen(),
      ),
    ));
    await tester.pump();
  }

  /// موضع عنوان قسمٍ على المحور الرأسي داخل القائمة الممرَّرة.
  double sectionY(WidgetTester tester, String prefix) {
    final f = find.textContaining(prefix);
    expect(f, findsOneWidget, reason: 'القسم «$prefix» غائب');
    return tester.getTopLeft(f).dy;
  }

  tearDown(Get.reset);

  testWidgets('المفتوح يسبق المقفل على الشاشة', (tester) async {
    await pumpHub(tester,
        businessType: 'fuel',
        features: {'inventory', 'promotions', 'debts', 'refunds'});

    expect(sectionY(tester, 'خدماتك ('),
        lessThan(sectionY(tester, 'متاح بترقية الباقة (')),
        reason: 'المقفل ظهر فوق المفتوح — وهذا ما اشتكى منه المستخدم');
  });

  testWidgets('باقةٌ تفتح كل شيء لا تعرض دعوة ترقية', (tester) async {
    // AMIAL-SERVICES-SCOPE-001 — «مع العلم الباقة مؤسسية».
    //
    // صاحب الباقة الأعلى كان يرى «متاح بترقية الباقة»، فيبحث عن ترقيةٍ
    // فوق الأعلى. القسم لا يُعرض له أصلاً.
    await pumpHub(tester, businessType: 'fuel', features: _enterpriseFuel);

    expect(find.textContaining('متاح بترقية الباقة'), findsNothing,
        reason: 'عُرضت دعوة ترقية لمن هو على الباقة الأعلى');
    expect(find.textContaining('باقتك تفتح كل الخدمات'), findsOneWidget);
  });

  testWidgets('خدمة نشاطٍ آخر تُفصل عن المقفل بالباقة', (tester) async {
    // «تقسيم الفاتورة» يمنحها الخادم لنشاط التجزئة لا للباقة. عرضها تحت
    // «متاح بترقية الباقة» وعدٌ لا يُوفى: لا باقة تفتحها لمحطة وقود.
    await pumpHub(tester, businessType: 'fuel', features: _enterpriseFuel);

    expect(find.textContaining('لأنواع نشاط أخرى'), findsOneWidget);
    expect(find.text('تقسيم الفاتورة'), findsOneWidget);

    expect(sectionY(tester, 'خدماتك ('),
        lessThan(sectionY(tester, 'لأنواع نشاط أخرى')),
        reason: 'ما لا يخصّ نشاطه يجب أن يكون آخر ما يراه');
  });

  testWidgets('نفس الخدمة تُعرض لصاحب نشاطها ضمن خدماته', (tester) async {
    // عكسُ الحالة السابقة: لو صنّفنا «تقسيم الفاتورة» بالخطأ لاختفت عن
    // التجزئة أيضاً — وهي أصحاب الميزة. الحدّ يُختبر من جهتيه.
    await pumpHub(tester,
        businessType: 'retail',
        features: {..._enterpriseFuel, 'split_bill'});

    expect(find.textContaining('لأنواع نشاط أخرى'), findsNothing);
    expect(find.text('تقسيم الفاتورة'), findsOneWidget);
  });

  testWidgets('التاجر الجديد على المجاني يرى ما يملكه أولاً', (tester) async {
    // الطرف الآخر: أغلب الكتالوج مقفل. يجب أن يبقى القليل المفتوح فوقه.
    await pumpHub(tester,
        businessType: 'fuel',
        features: {'debts', 'refunds', 'wallet', 'receipts', 'daily_reports'},
        planLabel: 'المجانية');

    expect(sectionY(tester, 'خدماتك ('),
        lessThan(sectionY(tester, 'متاح بترقية الباقة (')));
    expect(find.textContaining('باقتك تفتح كل الخدمات'), findsNothing);
  });
}

/// ميزات محطة وقود على الباقة المؤسسية — كما يبنيها `AccessPresets`.
const _enterpriseFuel = {
  'wallet', 'notifications', 'profile', 'transfer', 'receive', 'qr_pay',
  'merchant_verification', 'receipts', 'daily_reports',
  'fuel_pos', 'fuel_pumps', 'fuel_products', 'fuel_companies', 'fuel_shifts',
  'quick_sale', 'debts', 'refunds', 'products', 'inventory', 'barcode',
  'inventory_audit', 'low_stock_alerts', 'promotions', 'offline_pos',
  'gift_cards', 'shift_close', 'expenses', 'customers', 'suppliers',
  'purchases', 'profit_reports', 'excel_export', 'advanced_reports',
  'employees', 'employee_permissions', 'multi_pos', 'fuel_cards',
  'fuel_variance', 'loyalty', 'branches', 'branch_reports', 'multi_currency',
  'installments', 'audit_log', 'advanced_backup', 'rbac', 'api_access',
  'corporate_accounts', 'corporate_credit_limits',
  'operations_manager', 'financial_manager',
};
