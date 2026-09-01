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
  }

  /// أيملك الفعل؟ — **يُسأل قبل رسم كلّ زرّ**.
  bool can(String permission) =>
      isOwner.value || permissions.contains(permission);

  /// أيملك واحدةً من هذه؟ — لإظهار قسمٍ كامل.
  bool canAny(List<String> perms) => perms.any(can);

  void clearState() {
    lastError.value = '';
    isOffline.value = false;
    permissionDenied.value = false;
  }
}
