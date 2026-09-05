import 'package:get/get.dart';

/// **حالةُ قطاعٍ عامّة** — AMIAL-RETAIL-VERTICAL-001 · المرحلة ١٠.
///
/// ══════════════════════════════════════════════════════════════════════
/// بُنيت هذه الحقولُ في جولة الوقود، **وليس فيها سطرٌ خاصٌّ بالوقود**:
/// تحميلٌ وإرسالٌ وخطأٌ ورفضُ صلاحيّةٍ وانقطاعُ شبكة، وسؤالُ «أيملك
/// الفعل؟». والتجزئةُ تحتاجها حرفاً بحرف، والجملةُ والصيدليّةُ بعدها.
///
/// **ونسخُها لكلّ قطاعٍ يعني تصنيفاً يتباعد**: يُصلَح خلطُ «بلا اتّصال»
/// بـ«خطأ» في أحدهما ويبقى في الآخر.
mixin VerticalStateMixin on GetxController {
  final RxBool isLoading = false.obs;
  final RxBool isSubmitting = false.obs;
  final RxString lastError = ''.obs;

  /// **أخطأ الفعلُ أم أخطأ التحميل؟** — AMIAL-VERTICAL-ACTION-ERROR-001.
  ///
  /// ══════════════════════════════════════════════════════════════════
  /// **الثمنُ الذي دُفع:** أرسل صاحبُ المشروع صورةَ شاشة الخزّانات وقد
  /// امتلأت كلُّها برسالة «تعذّر إتمام العملية · نوع الوقود غير موجود في
  /// هذه المحطة»، وقال: **«لا استطيع انشاء خزان او مضخة، ليس هناك طريقة
  /// للعمل»**.
  ///
  /// والسببُ أنّ `lastError` كانت وعاءً واحداً لخطأين مختلفين تماماً:
  ///
  ///   · **خطأُ تحميل** — لا بياناتٍ تُعرَض أصلاً، فحجبُ الشاشة صواب.
  ///   · **خطأُ فعل**   — القائمةُ محمَّلةٌ سليمة، وفشل إدخالٌ واحد.
  ///
  /// و`VerticalStateView` كانت تحجب الشاشةَ في الحالتين. **فالخطأُ في
  /// حقلٍ واحدٍ يمحو القائمةَ وزرَّ الإضافة معاً** — ولا طريقَ للتصحيح
  /// إلّا الخروجُ من الشاشة والعودةُ إليها، ولا شيءَ يقول ذلك.
  ///
  /// **والحقلُ الجديد رايةٌ لا رسالة**: `lastError` تبقى وعاءَ النصّ كما
  /// هي، فكلُّ شاشةٍ تقرأها اليومَ في نخبِها تبقى تعمل بلا تعديل. وهذه
  /// تقول **أيَّ خطأٍ هو**، فتُحجَب الشاشةُ للتحميل وحدَه.
  final RxBool errorIsAction = false.obs;

  /// **الحالاتُ الستّ** التي تفرضها `amial-flutter` — لا شاشةَ بحالتين.
  final RxBool permissionDenied = false.obs;
  final RxBool isOffline = false.obs;

  final RxSet<String> permissions = <String>{}.obs;
  final RxBool isOwner = false.obs;
  final RxMap<String, dynamic> catalogue = <String, dynamic>{}.obs;

  bool okOf(Response r) => r.statusCode == 200 && (r.body?['success'] == true);

  String msgOf(Response r) {
    if (r.statusCode == 401) {
      return 'انتهت الجلسة — سجّل الدخول من جديد';
    }
    if (r.statusCode == 403) {
      final m = r.body is Map ? r.body['message'] : null;
      return (m is String && m.trim().isNotEmpty)
          ? m
          : 'لا تملك الصلاحية اللازمة لهذه الخدمة';
    }
    if (r.statusCode == null || r.statusCode == 0) {
      return 'لا اتصال بالخادم — تحقّق من الشبكة';
    }
    final m = r.body is Map ? r.body['message'] : null;
    return (m is String && m.trim().isNotEmpty) ? m : 'تعذّر إتمام العملية';
  }

  /// **يفحص كلّ نداء**: الشبكةُ المقطوعة ليست رفضَ صلاحيّة، ورفضُ
  /// الصلاحيّة ليس عطلاً. وخلطُها يُرسل المستعملَ يصلح ما ليس معطوباً.
  void classify(Response r) {
    isOffline.value = (r.statusCode == null || r.statusCode == 0);
    permissionDenied.value = (r.statusCode == 403);
    // **رايةٌ تُخفَض مع كلّ ردّ** — فيرفعها `failAction` وحدَها بعدها.
    // ولولا ذلك لبقي فشلُ فعلٍ قديمٍ يمنع حجبَ الشاشة عند فشل تحميلٍ لاحق.
    errorIsAction.value = false;
  }

  /// أيملك الفعل؟ — **يُسأل قبل رسم كلّ زرّ**.
  bool can(String permission) =>
      isOwner.value || permissions.contains(permission);

  /// أيملك واحدةً من هذه؟ — لإظهار قسمٍ كامل.
  bool canAny(List<String> perms) => perms.any(can);

  void clearState() {
    lastError.value = '';
    errorIsAction.value = false;
    isOffline.value = false;
    permissionDenied.value = false;
  }

  /// **فشلَ فعلٌ، والشاشةُ تبقى** — لا تُحجَب ولا يُمحى زرُّ الإضافة.
  void failAction(String message) {
    lastError.value = message;
    errorIsAction.value = true;
  }

  /// **فشلَ تحميل** — لا بياناتٍ تُعرَض، فالحجبُ هو الصواب.
  void failLoad(String message) {
    lastError.value = message;
    errorIsAction.value = false;
  }
}
