// AMIAL-STATEMENT-UI-001 — **«لا يظهر الكشف في التطبيق، فقط عند التحميل».**
//
// ══════════════════════════════════════════════════════════════════════
// شكوى صاحب المشروع الثالثة. والخادمُ أخضرُ عليها: `AccountStatementTest`
// سبعةُ اختباراتٍ تمرّ، والحركاتُ تُردّ صحيحةً بمدينها ودائنها.
//
// **فالثغرةُ بين الردّ الصحيح والشاشة** — ولا شيءَ كان يقف هناك. هذه
// الشاشةُ لها **أربعُ حالات** (تحميلٌ · عطلٌ · فراغٌ · صفوف)، ولم تكن
// واحدةٌ منها تُبنى في أيّ اختبار.
//
// ══════════════════════════════════════════════════════════════════════
// **وحالةُ الفراغ أخطرُها.** كشفٌ فارغٌ بلا سطرٍ يشرح يُقرأ **عطلاً**:
// يظنّ صاحبُ الحساب أنّ حركاتِه ضاعت، فيتّصل بالدعم. والفرقُ بين
// «لا حركةَ في هذه الفترة» و«تعذّر الجلب» هو الفرقُ بين مستخدمٍ يغيّر
// التاريخَ ومستخدمٍ يفتح شكوى. **(القاعدةُ السابعة: «غير معروف» ليس
// صفراً.)**

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:get/get.dart';
import 'package:mocktail/mocktail.dart';

import 'package:amial_pay/data/api/api_client.dart';
import 'package:amial_pay/features/reports/screens/amial_account_statement_screen.dart';
import 'package:amial_pay/helper/amial_money.dart';

/// **و`ApiClient` خدمةُ GetX** — فحاويةُ الحقن تنادي دورةَ حياتها عند
/// التسجيل. ومحاكاةٌ تُرجع `null` لتلك النداءات تُسقط الاختبارَ قبل أن
/// يُبنى إطارٌ واحد، فتُعطى قيماً حقيقيّة.
class _MockApi extends Mock implements ApiClient {
  @override
  InternalFinalCallback<void> get onStart =>
      InternalFinalCallback<void>(callback: () {});

  @override
  InternalFinalCallback<void> get onDelete =>
      InternalFinalCallback<void>(callback: () {});
}

void main() {
  late _MockApi api;

  setUp(() {
    api = _MockApi();
    // **ولا `permanent`** — يُبقي أوّلَ محاكاةٍ حيّةً بعد `Get.reset`،
    // فتقرأ كلُّ الاختبارات بيانات الأوّل. وهي نتيجةٌ تتغيّر بترتيب
    // التشغيل: تمرّ منفردةً وتسقط في الجولة، فتُقرأ «عطلاً متقطّعاً».
    Get.put<ApiClient>(api);
  });

  tearDown(Get.reset);

  void answer(Response r) {
    when(() => api.getData(any(),
            query: any(named: 'query'),
            headers: any(named: 'headers')))
        .thenAnswer((_) async => r);
  }

  Response ok(Map<String, dynamic> meta) =>
      Response(statusCode: 200, body: {'success': true, 'meta': meta});

  Future<void> pump(WidgetTester t) async {
    await t.pumpWidget(const MaterialApp(
      home: Directionality(
        textDirection: TextDirection.rtl,
        child: AmialAccountStatementScreen(),
      ),
    ));
    await t.pumpAndSettle();
  }

  String rendered(WidgetTester t) => t
      .widgetList<Text>(find.byType(Text))
      .map((w) => w.data ?? '')
      .join('\n');

  // ══════════════════════════════════════════════════════════════════
  //  ① الحالةُ التي اشتُكي منها: حركاتٌ تُردّ — أتظهر؟
  // ══════════════════════════════════════════════════════════════════

  testWidgets('**الحركاتُ تُرسَم في الشاشة لا في الملفّ وحدَه**', (t) async {
    answer(ok({
      'items': [
        {
          'date': '2026-08-01',
          'statement': 'تحويل من ٧٧٧١٢٣٤٥٦',
          'debit': '0',
          'credit': '15000',
          'balance': '15000',
        },
        {
          'date': '2026-08-03',
          'statement': 'دفع فاتورة كهرباء',
          'debit': '4000',
          'credit': '0',
          'balance': '11000',
        },
      ],
      'opening_balance': '0',
      'closing_balance': '11000',
      'total_debit': '4000',
      'total_credit': '15000',
      'truncated': false,
    }));

    await pump(t);

    final text = rendered(t);

    // **وهذه هي الشكوى بعينها**: الردُّ صحيحٌ والشاشةُ فارغة.
    expect(text.contains('تحويل من ٧٧٧١٢٣٤٥٦'), isTrue,
        reason: '**الكشفُ لا يظهر في التطبيق** — والخادمُ ردَّ الحركةَ '
            'صحيحةً. وهي الشكوى الثالثة حرفيّاً.');

    expect(text.contains('دفع فاتورة كهرباء'), isTrue,
        reason: 'رُسم أوّلُ سطرٍ ولم تُرسم البقيّة');

    // ══════════════════════════════════════════════════════════════
    // **والمتوقَّعُ يُبنى بالمنسِّق نفسِه لا يُكتب أرقاماً.**
    //
    // `AmialMoney.yer` تُدخل فواصلَ الآلاف وتُلحق العملة، فتوقُّعُ
    // «15000» خامّاً يسقط على عرضٍ **صحيح**. وحارسٌ يمنع صواباً
    // يُعطَّل ثمّ لا يحرس شيئاً.
    // ══════════════════════════════════════════════════════════════
    for (final n in const ['15000', '4000', '11000']) {
      final shown = AmialMoney.yer(n);
      expect(text.contains(shown), isTrue,
          reason: 'المبلغ «$shown» غائبٌ عن الشاشة — والكشفُ يُطلب لإثبات '
              'الدخل أو تسوية خلاف');
    }
  });

  // ══════════════════════════════════════════════════════════════════
  //  ② فراغٌ يُقال، لا فراغٌ يُترك
  // ══════════════════════════════════════════════════════════════════

  testWidgets('فترةٌ بلا حركةٍ تقول ذلك — ولا تُترك بيضاء', (t) async {
    answer(ok({
      'items': <dynamic>[],
      'opening_balance': '0',
      'closing_balance': '0',
      'total_debit': '0',
      'total_credit': '0',
    }));

    await pump(t);

    final text = rendered(t).trim();

    expect(text.isNotEmpty, isTrue,
        reason: '**شاشةٌ بيضاءُ تماماً** — والمستخدمُ لا يعرف أهي فترةٌ '
            'بلا حركةٍ أم عطلٌ في التطبيق');

    // **ولا تُقال «عطل» على فراغٍ سليم** — إنذارٌ كاذبٌ يُعوّد القارئَ
    // على التجاهل يومَ يصدق.
    expect(text.contains('تعذّر'), isFalse,
        reason: 'فترةٌ بلا حركةٍ عُرضت كعطل — فيفتح صاحبُها شكوى بلا سبب');
  });

  // ══════════════════════════════════════════════════════════════════
  //  ③ والعطلُ يُقال عطلاً — ولا يُخلَط بالفراغ
  // ══════════════════════════════════════════════════════════════════

  testWidgets('**فشلُ الجلب لا يُعرَض كشفاً فارغاً**', (t) async {
    answer(Response(statusCode: 500, body: {'message': 'خطأ'}));

    await pump(t);

    final text = rendered(t);

    // ══════════════════════════════════════════════════════════════
    // **وهذا هو الفرقُ الذي يحمي المستخدم.** كشفٌ فارغٌ على عطلِ
    // خادمٍ يقول له «لا حركاتِ لك» — وهو كذبٌ يجعله يظنّ مالَه ذهب.
    // و«غيرُ معروف» ليس صفراً.
    // ══════════════════════════════════════════════════════════════
    expect(text.contains('تعذّر'), isTrue,
        reason: '**سقط الجلبُ ولم يُقَل** — فيُقرأ الفراغُ «لا حركاتِ لك»، '
            'وهو أسوأ من رسالة خطأ');
  });

  // ══════════════════════════════════════════════════════════════════
  //  ④ والقطعُ يُصرَّح به — وإلّا قُرئ الجزءُ كلّاً
  // ══════════════════════════════════════════════════════════════════

  testWidgets('كشفٌ مقطوعٌ يقول إنّه مقطوع', (t) async {
    answer(ok({
      'items': [
        {
          'date': '2026-08-01',
          'statement': 'حركة',
          'debit': '0',
          'credit': '1',
          'balance': '1',
        },
      ],
      'opening_balance': '0',
      'closing_balance': '1',
      'total_debit': '0',
      'total_credit': '1',
      'truncated': true,
    }));

    await pump(t);

    expect(rendered(t).contains('أكثر مما يُعرض'), isTrue,
        reason: '**عُرض جزءٌ من الحركات بلا تصريح** — فيُبنى على كشفٍ '
            'ناقصٍ حسابٌ أو خلافٌ، ولا شيءَ يقول إنّه ناقص');
  });

  // ══════════════════════════════════════════════════════════════════
  //  ⑤ وزرُّ التصدير موصولٌ — لا معروضاً وحدَه
  // ══════════════════════════════════════════════════════════════════

  testWidgets('زرُّ تصدير الـPDF له معالجٌ فعلاً', (t) async {
    answer(ok({
      'items': <dynamic>[],
      'opening_balance': '0',
      'closing_balance': '0',
      'total_debit': '0',
      'total_credit': '0',
    }));

    await pump(t);

    // **زرٌّ لم يُضغط ليس مبنيّاً** (القاعدةُ التاسعة). ومعالجٌ `null`
    // يُنتج ضغطةً صامتة: لا خطأ، ولا طلب، ولا شيء.
    final live = t
        .widgetList<InkWell>(find.byType(InkWell))
        .where((w) => w.onTap != null)
        .length;

    expect(live >= 2, isTrue,
        reason: 'أقلُّ من زرّين حيَّين في شاشة الكشف — والمتوقَّع مدى '
            'التاريخ والتصدير على الأقلّ');
  });
}
