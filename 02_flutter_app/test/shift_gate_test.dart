// AMIAL-SHIFT-GATE-001 — **الشبّاكُ لا يُفتح بلا ورديّة، ولا يُحبَس بلا سبب.**
//
// ══════════════════════════════════════════════════════════════════════
// حارسُ الخادم (`NoTillWithoutAShiftGuardTest`) يثبت أنّ البيعَ يُردّ ٤٠٩
// بلا ورديّة. **وهذا يثبت أنّ الكاشير لا يصل تلك الضغطةَ أصلاً**: بدونه
// يملأ السلّةَ ويحسب الباقي والزبونُ واقف، ثمّ يُردّ عند الضغط الأخير.
//
// **وثلاثُ حالاتٍ لا واحدة** — والثالثةُ هي التي تُسقِط متجراً كاملاً إن
// أُخطئت:
//
//   ① لا ورديّة   ⇒ يُحبَس ويُعرَض بابُ الفتح.
//   ② ورديّةٌ قائمةٌ ⇒ يمرّ، ويُقرأ اسمُ فاتحها على الشريط.
//   ③ **تعذّرت القراءة ⇒ يمرّ ولا يُحبَس.** الشبكةُ في اليمن تتقطّع،
//      وحبسُ الشبّاك على خادمٍ لم يردّ يوقف البيعَ كلَّه — **بينما
//      الخادمُ نفسُه سيرفض إن لزم**. (القاعدة السابعة: «تعذّرت القراءة»
//      ليست «لا ورديّة».)

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:get/get.dart';
import 'package:mocktail/mocktail.dart';

import 'package:amial_pay/data/api/api_client.dart';
import 'package:amial_pay/features/merchant/widgets/shift_gate.dart';

/// **ونداءاتُ دورة حياة GetX تُعطى قيماً حقيقيّة** — `ApiClient` خدمةٌ
/// (‏`GetxService`)، ومحاكاةٌ تُرجع `null` لـ`onStart` تُسقط الاختبارَ قبل
/// أن يُبنى إطارٌ واحد. (والنمطُ نفسُه في `account_statement_screen_widget_test`.)
class _MockApi extends Mock implements ApiClient {
  @override
  InternalFinalCallback<void> get onStart =>
      InternalFinalCallback<void>(callback: () {});

  @override
  InternalFinalCallback<void> get onDelete =>
      InternalFinalCallback<void>(callback: () {});
}

Response _ok(Map<String, dynamic> meta) => Response(
      statusCode: 200,
      body: {'success': true, 'code': 'OK', 'message': '', 'errors': {}, 'meta': meta},
    );

Future<void> _pump(WidgetTester t, Response Function() reply) async {
  final api = _MockApi();
  when(() => api.getData(any(),
          query: any(named: 'query'), headers: any(named: 'headers')))
      .thenAnswer((_) async => reply());
  Get.put<ApiClient>(api);

  await t.pumpWidget(GetMaterialApp(
    home: Directionality(
      textDirection: TextDirection.rtl,
      child: ShiftGate(
        child: Scaffold(body: Center(child: Text('شاشة الكاشير'))),
      ),
    ),
  ));
  await t.pumpAndSettle(const Duration(seconds: 1));
}

void main() {
  tearDown(Get.reset);

  testWidgets('① بلا وردية: يُحبس الشباك ويُعرض باب الفتح', (t) async {
    await _pump(t, () => _ok({'shift': null, 'required': true}));

    expect(find.text('شاشة الكاشير'), findsNothing,
        reason: 'الشباك انفتح بلا وردية — والكاشير سيُردّ عند الضغط الأخير');
    expect(find.text('لا يُفتح الشبّاك بلا وردية'), findsOneWidget);
    expect(find.text('بدء الوردية'), findsOneWidget,
        reason: 'الرفض لا يقول كيف يُصلَح — فيُقرأ عطلاً');
  });

  testWidgets('② بوردية مفتوحة: يمرّ، واسم فاتحها يُقرأ', (t) async {
    await _pump(t, () => _ok({
          'shift': {
            'id': 7,
            'opening_float': '1000.0000',
            'opened_by_name': 'أحمد صالح',
            'opened_by_role': 'pos',
            'status': 'open',
          },
          'required': true,
        }));

    expect(find.text('شاشة الكاشير'), findsOneWidget);
    expect(find.text('وردية أحمد صالح'), findsOneWidget,
        reason: 'الكاشير لا يعرف باسم من ستُطبَع الفاتورة');
  });

  testWidgets('③ تعذّرت القراءة: يمرّ ولا يُحبس متجرٌ كامل', (t) async {
    await _pump(t, () => const Response(statusCode: 503, body: null));

    expect(find.text('شاشة الكاشير'), findsOneWidget,
        reason: 'انقطاعُ الشبكة أوقف البيع كلَّه — والخادمُ هو الحدُّ لا الشاشة');
    expect(find.text('لا يُفتح الشبّاك بلا وردية'), findsNothing);
  });

  testWidgets('④ وتاجرٌ أطفأ الإلزام لا يُحبس', (t) async {
    await _pump(t, () => _ok({'shift': null, 'required': false}));

    expect(find.text('شاشة الكاشير'), findsOneWidget,
        reason: 'الشاشةُ أشدُّ من الخادم — ومصدرُ حقيقةٍ ثانٍ يفترق عن الأوّل');
  });
}
