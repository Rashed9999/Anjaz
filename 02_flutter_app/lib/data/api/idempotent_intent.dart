import 'package:get/get.dart';
import 'package:amial_pay/data/api/api_client.dart';
import 'package:amial_pay/data/api/idempotency_key_generator.dart';

/// AMIAL-IDEMPOTENCY-002 — **نيّةُ المستخدم مفتاحٌ واحد، مهما أُعيدت.**
///
/// ══════════════════════════════════════════════════════════════════════
/// **الثمن — قِيس في تدقيق الجاهزيّة:**
///
///     safe_payment_repo.dart:41  ·  donations_repo.dart:39
///     idempotencyKey: IdempotencyKeyGenerator.forFinancialAction('donate')
///
/// المفتاحُ يُولَّد **داخل قائمة المُعامِلات**، أي في كلّ نداء. فمن ضغط
/// «تبرَّع» وانقطع الاتّصالُ **بعد** وصول الطلب وقبل وصول الردّ، يرى
/// فشلاً فيضغط ثانيةً — فيصل مفتاحٌ مختلفٌ ويقرأه الخادمُ عمليّةً جديدة.
/// **خصمان.**
///
/// وانقطاعُ الشبكة في اليمن ليس حالةً نادرة.
///
/// ══════════════════════════════════════════════════════════════════════
/// **ولمَ مُعينٌ مشترك:** النمطُ الصحيح كان مكتوباً في
/// `transaction_controller.dart` وحدَه، ونُسخ خطأً في موضعين. ومن كتب
/// شاشةً ماليّةً ثالثةً سينسخ الأقربَ إليه. **فيُنتزَع النمطُ من الذاكرة
/// ويُوضع في صنف.**
///
/// **ولمَ في المتحكّم لا المستودع:** المستودعُ `GetxService` مفردٌ يعيش
/// طولَ التطبيق؛ مفتاحٌ محفوظٌ فيه يبقى بين تبرّعين مختلفين، فيُقرأ
/// الثاني إعادةً للأوّل ويُرفض. **والنيّةُ تُولد في المتحكّم وتموت معه.**
mixin IdempotentIntent {
  final Map<String, String> _keys = {};

  /// مفتاحُ العمليّة — يُولَّد مرّةً ويبقى حتّى يُحسَم أمرُها.
  ///
  /// و`scope` يفصل النيّاتِ المتزامنة: تبرّعان لحملتين مختلفتين نيّتان،
  /// فيُمرَّر معرّفُ الحملة معه.
  String keyFor(String action, {String? scope}) {
    final id = scope == null ? action : '$action:$scope';

    return _keys[id] ??= IdempotencyKeyGenerator.forFinancialAction(action);
  }

  /// نحفظ المفتاح عند تعذّر تأكيد النتيجة، بما في ذلك انقطاع الشبكة أو استمرار الطلب.
  void settleKey(String action, Response response, {String? scope}) {
    if (ApiClient.hasDefinitiveOutcome(response)) {
      _keys.remove(scope == null ? action : '$action:$scope');
    }
  }

  /// للاختبار: أَمحفوظٌ مفتاحٌ لهذه النيّة الآن؟
  bool hasKey(String action, {String? scope}) =>
      _keys.containsKey(scope == null ? action : '$action:$scope');
}
