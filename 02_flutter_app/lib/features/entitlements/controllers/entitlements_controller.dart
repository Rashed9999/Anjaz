import 'package:get/get.dart';
import 'package:amial_pay/common/controllers/vertical_state_mixin.dart';
import 'package:amial_pay/features/entitlements/domain/repositories/entitlements_repo.dart';

/// AMIAL-ENTITLEMENTS-001 — **ملفُّ خدمات التاجر في التطبيق**.
///
/// ══════════════════════════════════════════════════════════════════════
/// **ولا قائمةَ خدماتٍ مكتوبةً في Dart.** كانت ٢٦ بوّابةً مكتوبةً بأسماء
/// ميزاتٍ نصّيّة — فقدرةٌ جديدةٌ في الخادم تحتاج نشرةَ تطبيقٍ لتُرى، وميزةٌ
/// تُلغى تبقى بطاقتُها معروضة.
///
/// **فصارت الشاشةُ تُرسم من الردّ**: `manifest.capabilities` فيها الاسمُ
/// العربيُّ والوصفُ والأيقونةُ والحالةُ وسعرُ الفتح.
class EntitlementsController extends GetxController
    with VerticalStateMixin
    implements GetxService {
  final EntitlementsRepo repo;
  EntitlementsController({required this.repo});

  static const stAvailable = 'available';
  static const stLockedByPlan = 'locked_by_plan';
  static const stLockedByRole = 'locked_by_role';
  static const stLimitReached = 'limit_reached';

  final Rx<Map<String, dynamic>?> manifest = Rx<Map<String, dynamic>?>(null);
  final RxList<Map<String, dynamic>> items = <Map<String, dynamic>>[].obs;
  final RxString groupFilter = ''.obs;

  Future<void> load() async {
    clearState();
    isLoading.value = true;
    try {
      final r = await repo.manifest();
      classify(r);
      if (!okOf(r)) {
        lastError.value = msgOf(r);
        return;
      }
      final d = Map<String, dynamic>.from((r.body?['data'] ?? {}) as Map);
      manifest.value = d;
      items.assignAll(((d['capabilities'] ?? []) as List)
          .map((e) => Map<String, dynamic>.from(e as Map))
          .toList());

      // الصلاحيّاتُ المتاحة تُشتقّ من الملفّ نفسِه — مصدرٌ واحد.
      permissions
        ..clear()
        ..addAll(items
            .where((c) => c['state'] == stAvailable)
            .map((c) => '${c['capability']['code']}'));
      isOwner.value = d['is_owner'] == true;
    } catch (_) {
      isOffline.value = true;
      lastError.value = 'لا اتصال بالخادم — تحقّق من الشبكة';
    } finally {
      isLoading.value = false;
    }
  }

  /// **أتُفتح هذه الشاشة؟** — يُسأل قبل التنقّل لا بعده.
  bool isAvailable(String code) => items
      .any((c) => c['capability']['code'] == code && c['state'] == stAvailable);

  Map<String, dynamic>? stateOf(String code) =>
      items.firstWhereOrNull((c) => c['capability']['code'] == code);

  List<String> get groups =>
      ((manifest.value?['groups'] ?? []) as List).map((e) => '$e').toList();

  List<Map<String, dynamic>> get visible => groupFilter.value.isEmpty
      ? items
      : items.where((c) => c['capability']['group'] == groupFilter.value).toList();

  int summary(String key) {
    final s = manifest.value?['summary'];
    if (s is! Map) return 0;
    final v = s[key];
    return v is int ? v : int.tryParse('$v') ?? 0;
  }

  String get planName => '${manifest.value?['plan']?['name'] ?? '—'}';
  String get planExpiry => '${manifest.value?['plan']?['expires_at'] ?? ''}';
}
