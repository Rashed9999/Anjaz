import 'package:get/get.dart';
import 'package:amial_pay/features/fuel_station/domain/repositories/fuel_vertical_repo.dart';

/// AMIAL-FUEL-VERTICAL-001 · المرحلة ٨ — متحكّمُ القطاع.
///
/// ══════════════════════════════════════════════════════════════════════
/// **والواجهةُ تُبنى من الصلاحيّات لا من نوع النشاط.**
///
/// فالكاشيرُ والمحاسبُ والمالكُ يفتحون التطبيقَ نفسَه ويرون شاشاتٍ مختلفة،
/// لا لأنّ في الشيفرة `if (role == 'cashier')` — بل لأنّ الخادم يردّ
/// لكلٍّ صلاحيّاتِه.
///
/// **والإخفاءُ ليس أماناً:** كلُّ فعلٍ يُفحص في الخادم ثانيةً. ما هنا هو
/// ألّا نعرض بابًا يُغلق في وجه من يفتحه.
class FuelVerticalController extends GetxController implements GetxService {
  final FuelVerticalRepo repo;
  FuelVerticalController({required this.repo});

  // ── الحالة ─────────────────────────────────────────────────────────
  final RxBool isLoading = false.obs;
  final RxBool isSubmitting = false.obs;
  final RxString lastError = ''.obs;

  /// **الحالاتُ الستّ** التي تفرضها `amial-flutter` — لا شاشةَ بحالتين.
  final RxBool permissionDenied = false.obs;
  final RxBool isOffline = false.obs;

  final RxSet<String> permissions = <String>{}.obs;
  final RxBool isOwner = false.obs;
  final RxMap<String, dynamic> catalogue = <String, dynamic>{}.obs;

  final Rx<Map<String, dynamic>?> ops = Rx<Map<String, dynamic>?>(null);
  final RxList<Map<String, dynamic>> tanks = <Map<String, dynamic>>[].obs;
  final RxList<Map<String, dynamic>> deliveries = <Map<String, dynamic>>[].obs;
  final RxList<Map<String, dynamic>> variances = <Map<String, dynamic>>[].obs;
  final RxString unattributedLiters = '0'.obs;
  final RxList<Map<String, dynamic>> pendingPrices = <Map<String, dynamic>>[].obs;
  final RxList<Map<String, dynamic>> roles = <Map<String, dynamic>>[].obs;
  final RxList<Map<String, dynamic>> cashMovements = <Map<String, dynamic>>[].obs;
  final RxMap<String, dynamic> cashSummary = <String, dynamic>{}.obs;

  // ── أدوات ──────────────────────────────────────────────────────────

  bool _ok(Response r) => r.statusCode == 200 && (r.body?['success'] == true);

  String _msg(Response r) {
    if (r.statusCode == 401 || r.statusCode == 403) {
      return 'انتهت الجلسة أو لا تملك الصلاحية';
    }
    if (r.statusCode == null || r.statusCode == 0) {
      return 'لا اتصال بالخادم — تحقّق من الشبكة';
    }
    final m = r.body is Map ? r.body['message'] : null;
    return (m is String && m.trim().isNotEmpty) ? m : 'تعذّر إتمام العملية';
  }

  /// **يفحص كلّ نداء**: الشبكةُ المقطوعة ليست رفضَ صلاحيّة، ورفضُ
  /// الصلاحيّة ليس عطلاً. وخلطُها يُرسل المستعملَ يصلح ما ليس معطوباً.
  void _classify(Response r) {
    isOffline.value = (r.statusCode == null || r.statusCode == 0);
    permissionDenied.value = (r.statusCode == 403);
  }

  /// أيملك الفعل؟ — **يُسأل قبل رسم كلّ زرّ**.
  bool can(String permission) =>
      isOwner.value || permissions.contains(permission);

  /// أيملك واحدةً من هذه؟ — لإظهار قسمٍ كامل.
  bool canAny(List<String> perms) => perms.any(can);

  // ── الصلاحيّات ─────────────────────────────────────────────────────

  Future<void> loadPermissions() async {
    try {
      isLoading.value = true;
      lastError.value = '';

      final r = await repo.myPermissions();
      _classify(r);

      if (_ok(r)) {
        final d = r.body['data'] ?? {};
        isOwner.value = d['is_owner'] == true;
        permissions
          ..clear()
          ..addAll(List<String>.from(d['permissions'] ?? const []));
        catalogue.value = Map<String, dynamic>.from(d['catalogue'] ?? {});
      } else {
        lastError.value = _msg(r);
      }
    } catch (_) {
      lastError.value = 'تعذّر قراءة الصلاحيات';
      isOffline.value = true;
    } finally {
      isLoading.value = false;
    }
  }

  // ── مركز العمليّات ─────────────────────────────────────────────────

  Future<void> loadOps() async {
    try {
      isLoading.value = true;
      lastError.value = '';

      final r = await repo.ops();
      _classify(r);

      if (_ok(r)) {
        ops.value = Map<String, dynamic>.from(r.body['data'] ?? {});
        tanks
          ..clear()
          ..addAll(List<Map<String, dynamic>>.from(
              (ops.value?['tanks'] ?? const []).map((e) => Map<String, dynamic>.from(e))));
      } else {
        lastError.value = _msg(r);
      }
    } catch (_) {
      lastError.value = 'تعذّر تحميل مركز العمليات';
      isOffline.value = true;
    } finally {
      isLoading.value = false;
    }
  }

  // ── الخزّانات ──────────────────────────────────────────────────────

  Future<void> loadTanks() async {
    try {
      isLoading.value = true;
      final r = await repo.tanks();
      _classify(r);

      if (_ok(r)) {
        tanks
          ..clear()
          ..addAll(List<Map<String, dynamic>>.from(
              ((r.body['data'] ?? {})['tanks'] ?? const [])
                  .map((e) => Map<String, dynamic>.from(e))));
      } else {
        lastError.value = _msg(r);
      }
    } catch (_) {
      lastError.value = 'تعذّر تحميل الخزانات';
    } finally {
      isLoading.value = false;
    }
  }

  Future<bool> addTank(Map<String, dynamic> data) =>
      _submit(() => repo.addTank(data), then: loadTanks);

  /// يُعيد فرقَ القياس عن الدفتر — **من يقيس يريد أن يعرف الآن**.
  Future<String?> recordDip(int tankId, Map<String, dynamic> data) async {
    try {
      isSubmitting.value = true;
      lastError.value = '';

      final r = await repo.recordDip(tankId, data);
      _classify(r);

      if (_ok(r)) {
        await loadTanks();
        return '${(r.body['data'] ?? {})['dip_vs_book'] ?? '0'}';
      }

      lastError.value = _msg(r);
      return null;
    } catch (_) {
      lastError.value = 'تعذّر تسجيل القياس';
      return null;
    } finally {
      isSubmitting.value = false;
    }
  }

  Future<bool> linkNozzleToTank(int nozzleId, int tankId) =>
      _submit(() => repo.linkNozzleToTank(nozzleId, tankId), then: loadOps);

  // ── التوريدات ──────────────────────────────────────────────────────

  Future<void> loadDeliveries() async {
    try {
      isLoading.value = true;
      final r = await repo.deliveries();
      _classify(r);

      if (_ok(r)) {
        deliveries
          ..clear()
          ..addAll(List<Map<String, dynamic>>.from(
              ((r.body['data'] ?? {})['deliveries'] ?? const [])
                  .map((e) => Map<String, dynamic>.from(e))));
      } else {
        lastError.value = _msg(r);
      }
    } catch (_) {
      lastError.value = 'تعذّر تحميل التوريدات';
    } finally {
      isLoading.value = false;
    }
  }

  Future<bool> receiveDelivery(Map<String, dynamic> data) =>
      _submit(() => repo.receiveDelivery(data), then: loadDeliveries);

  Future<bool> verifyDelivery(int id, String before, String after) =>
      _submit(() => repo.verifyDelivery(id, before, after), then: loadDeliveries);

  Future<bool> postDelivery(int id) =>
      _submit(() => repo.postDelivery(id), then: loadDeliveries);

  // ── المصالحة ───────────────────────────────────────────────────────

  Future<void> loadVariances() async {
    try {
      isLoading.value = true;
      final r = await repo.stockVariances();
      _classify(r);

      if (_ok(r)) {
        final d = r.body['data'] ?? {};
        variances
          ..clear()
          ..addAll(List<Map<String, dynamic>>.from(
              (d['variances'] ?? const []).map((e) => Map<String, dynamic>.from(e))));
        unattributedLiters.value = '${d['unattributed_liters_30d'] ?? '0'}';
      } else {
        lastError.value = _msg(r);
      }
    } catch (_) {
      lastError.value = 'تعذّر تحميل الفروقات';
    } finally {
      isLoading.value = false;
    }
  }

  Future<Map<String, dynamic>?> previewReconciliation(int tankId, String? actual) async {
    try {
      isSubmitting.value = true;
      final r = await repo.reconciliationPreview(tankId, actual: actual);
      _classify(r);

      if (_ok(r)) return Map<String, dynamic>.from(r.body['data'] ?? {});

      lastError.value = _msg(r);
      return null;
    } catch (_) {
      lastError.value = 'تعذّر حساب المصالحة';
      return null;
    } finally {
      isSubmitting.value = false;
    }
  }

  Future<bool> reconcile(int tankId, Map<String, dynamic> data) =>
      _submit(() => repo.reconcile(tankId, data), then: loadVariances);

  Future<bool> resolveVariance(int id, String note, String status) =>
      _submit(() => repo.resolveVariance(id, note, status), then: loadVariances);

  // ── الأسعار ────────────────────────────────────────────────────────

  Future<void> loadPendingPrices() async {
    try {
      isLoading.value = true;
      final r = await repo.pendingPrices();
      _classify(r);

      if (_ok(r)) {
        pendingPrices
          ..clear()
          ..addAll(List<Map<String, dynamic>>.from(
              ((r.body['data'] ?? {})['pending'] ?? const [])
                  .map((e) => Map<String, dynamic>.from(e))));
      } else {
        lastError.value = _msg(r);
      }
    } catch (_) {
      lastError.value = 'تعذّر تحميل الأسعار المعلّقة';
    } finally {
      isLoading.value = false;
    }
  }

  Future<bool> proposePrice(Map<String, dynamic> data) =>
      _submit(() => repo.proposePrice(data), then: loadPendingPrices);

  Future<bool> approvePrice(int id) =>
      _submit(() => repo.approvePrice(id), then: loadPendingPrices);

  // ── نقد الوردية ────────────────────────────────────────────────────

  Future<void> loadShiftCash(int shiftId) async {
    try {
      isLoading.value = true;
      final r = await repo.shiftCash(shiftId);
      _classify(r);

      if (_ok(r)) {
        final d = r.body['data'] ?? {};
        cashMovements
          ..clear()
          ..addAll(List<Map<String, dynamic>>.from(
              (d['movements'] ?? const []).map((e) => Map<String, dynamic>.from(e))));
        cashSummary.value = Map<String, dynamic>.from(d['summary'] ?? {});
      } else {
        lastError.value = _msg(r);
      }
    } catch (_) {
      lastError.value = 'تعذّر تحميل حركة النقد';
    } finally {
      isLoading.value = false;
    }
  }

  Future<bool> addCashMovement(int shiftId, Map<String, dynamic> data) =>
      _submit(() => repo.addCashMovement(shiftId, data),
          then: () => loadShiftCash(shiftId));

  // ── الأدوار ────────────────────────────────────────────────────────

  Future<void> loadRoles() async {
    try {
      isLoading.value = true;
      final r = await repo.roles();
      _classify(r);

      if (_ok(r)) {
        final d = r.body['data'] ?? {};
        roles
          ..clear()
          ..addAll(List<Map<String, dynamic>>.from(
              (d['roles'] ?? const []).map((e) => Map<String, dynamic>.from(e))));
        catalogue.value = Map<String, dynamic>.from(d['catalogue'] ?? {});
      } else {
        lastError.value = _msg(r);
      }
    } catch (_) {
      lastError.value = 'تعذّر تحميل الأدوار';
    } finally {
      isLoading.value = false;
    }
  }

  Future<bool> seedRoles() => _submit(repo.seedRoles, then: loadRoles);

  // ── مُرسِلٌ واحدٌ لكلّ فعل ───────────────────────────────────────────
  //
  // **حتّى لا يُنسى `isSubmitting` في زرّ.** زرٌّ بلا حالةِ تحميلٍ يُضغط
  // مرّتين، وفعلٌ ماليٌّ يُنفَّذ مرّتين.
  Future<bool> _submit(
    Future<Response> Function() call, {
    Future<void> Function()? then,
  }) async {
    try {
      isSubmitting.value = true;
      lastError.value = '';

      final r = await call();
      _classify(r);

      if (_ok(r)) {
        if (then != null) await then();
        return true;
      }

      lastError.value = _msg(r);
      return false;
    } catch (_) {
      lastError.value = 'تعذّر إتمام العملية — تحقّق من الشبكة';
      isOffline.value = true;
      return false;
    } finally {
      isSubmitting.value = false;
    }
  }
}
