// AMIAL-NEGATIVE-STOCK-001 — **ما يُرسله الخادمُ يصل العين، والقفلُ يُقال.**
//
// ══════════════════════════════════════════════════════════════════════
// `RetailVerticalController::operationsCenter` يضع في الردّ حقلين لم يكن
// في التطبيق سطرٌ واحدٌ يقرأ اسمَ أيٍّ منهما — قِيس بالبحث فلم يُوجَد:
//
//   ① `negative_stock` — **الميزةُ تنتهي عند JSON.** حارسُ الخادم يثبت
//      أنّ الرقم يُحسب ويُرسَل، ولا يثبت أنّ أحداً يقرؤه. (القاعدة ١٢:
//      مبنيٌّ ولا يُوصَل إليه — أكثرُ أعطال المشروع تكراراً.)
//
//   ② `low_stock_locked` — والتعليقُ في المتحكّم يقول غرضَه صراحةً:
//      «فالشاشةُ تعرض ارفع الباقة مكانَ قائمةٍ فارغةٍ تكذب». ولمّا لم
//      يُقرأ، صار القفلُ يُعرَض «لا صنف تحت حدّه» — أي **«فحصنا فلم نجد»
//      على مخزونٍ لم يُنظَر فيه أصلاً**. (القاعدة السابعة.)
//
// وثلاثُ حالاتٍ لا اثنتان، والثالثةُ هي التي تُفقد التحذيرَ معناه.

import 'package:flutter_test/flutter_test.dart';
import 'package:get/get.dart';
import 'package:mocktail/mocktail.dart';

import 'package:amial_pay/features/retail/controllers/retail_vertical_controller.dart';
import 'package:amial_pay/features/retail/domain/repositories/retail_vertical_repo.dart';

class _MockRepo extends Mock implements RetailVerticalRepo {
  @override
  InternalFinalCallback<void> get onStart =>
      InternalFinalCallback<void>(callback: () {});

  @override
  InternalFinalCallback<void> get onDelete =>
      InternalFinalCallback<void>(callback: () {});
}

void main() {
  late RetailVerticalController c;

  setUp(() {
    c = RetailVerticalController(repo: _MockRepo());
    Get.put<RetailVerticalController>(c);
  });

  tearDown(Get.reset);

  test('① ما نزل تحت الصفر يُقرأ من الردّ', () {
    c.ops.value = {
      'negative_stock': [
        {'product': 'حليب', 'location': 'الرفّ', 'on_hand': '-3.000', 'shortfall': '3.000'},
        {'product': 'خبز', 'location': 'المخزن', 'on_hand': '-1.000', 'shortfall': '1.000'},
      ],
    };

    expect(c.negativeStock.length, 2,
        reason: 'الخادم يُرسل المخزون السالب والتطبيق لا يقرأ الاسم — '
            'فالميزةُ تنتهي عند JSON ولا تصل صاحبَ المتجر');
    expect(c.negativeStock.first['product'], 'حليب');
    expect(c.negativeStock.first['shortfall'], '3.000');
  });

  test('② وردٌّ بلا سالبٍ لا يخترع تحذيراً', () {
    c.ops.value = {'negative_stock': <dynamic>[], 'low_stock': <dynamic>[]};

    expect(c.negativeStock, isEmpty,
        reason: 'تحذيرٌ بلا سبب يُعوّد العين على تخطّيه يومَ يصدق');
  });

  test('③ والقفلُ يُقال ولا يُقرأ «فحصنا فلم نجد»', () {
    c.ops.value = {
      'low_stock': null,
      'low_stock_locked': {'state': 'plan', 'unlock': 'متاح في باقة الأعمال'},
    };

    final locked = c.lowStockLocked;

    expect(locked, isNotNull,
        reason: 'القفلُ لا يصل الشاشة — فتُعرَض قائمةٌ فارغة، ويقرؤها '
            'التاجرُ «مخزوني سليم» وهو لم يُفحَص');
    expect(locked!['unlock'], 'متاح في باقة الأعمال',
        reason: 'لا يُقال بكم يُفتح — فالقفلُ بلا باب');

    // **ومفتوحٌ فارغٌ ليس مقفولاً** — وإلّا صار كلُّ يومٍ سليمٍ «مقفولاً».
    c.ops.value = {'low_stock': <dynamic>[], 'low_stock_locked': null};
    expect(c.lowStockLocked, isNull);
  });
}
