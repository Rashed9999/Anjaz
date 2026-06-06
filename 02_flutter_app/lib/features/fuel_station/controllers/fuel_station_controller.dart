import 'package:get/get.dart';
import 'package:amyal_pay/features/fuel_station/domain/repositories/fuel_station_repo.dart';
import 'package:amyal_pay/features/plans/screens/my_usage_screen.dart';

/// AMIAL-FUEL-001 — متحكّم محطة الوقود.
class FuelStationController extends GetxController implements GetxService {
  final FuelStationRepo repo;
  FuelStationController({required this.repo});

  // الحالة العامة
  final Rx<Map<String, dynamic>?> station = Rx<Map<String, dynamic>?>(null);
  final RxList<Map<String, dynamic>> pumps = <Map<String, dynamic>>[].obs;
  final RxList<Map<String, dynamic>> products = <Map<String, dynamic>>[].obs;
  final RxList<Map<String, dynamic>> companies = <Map<String, dynamic>>[].obs;
  final RxList<Map<String, dynamic>> sales = <Map<String, dynamic>>[].obs;
  final Rx<Map<String, dynamic>?> dashboardData = Rx<Map<String, dynamic>?>(null);

  // النوبة المفتوحة حالياً (إن وُجدت)
  final Rx<Map<String, dynamic>?> currentShift = Rx<Map<String, dynamic>?>(null);
  final RxList<Map<String, dynamic>> shifts = <Map<String, dynamic>>[].obs;
  final Rx<Map<String, dynamic>?> shiftDetail = Rx<Map<String, dynamic>?>(null);

  // بطاقات شركة محدّدة
  final RxList<Map<String, dynamic>> cards = <Map<String, dynamic>>[].obs;

  // سجلات العجز/الفائض
  final RxList<Map<String, dynamic>> variances = <Map<String, dynamic>>[].obs;

  // آخر بيع تمّ تسجيله (لشاشة النجاح)
  final Rx<Map<String, dynamic>?> lastSale = Rx<Map<String, dynamic>?>(null);

  final RxBool isLoading = false.obs;
  final RxBool isSubmitting = false.obs;
  final RxString lastError = ''.obs;

  // ============ Station ============

  Future<void> loadStation() async {
    try {
      isLoading.value = true;
      final r = await repo.getStation();
      if (_ok(r)) station.value = Map<String, dynamic>.from((r.body['meta']?['station'] ?? {}) as Map);
    } catch (_) {} finally { isLoading.value = false; }
  }

  Future<bool> saveStation(Map<String, dynamic> data) async {
    try {
      isSubmitting.value = true;
      lastError.value = '';
      final r = await repo.upsertStation(data);
      if (_ok(r)) { await loadStation(); return true; }
      lastError.value = _msg(r) ?? 'فشل الحفظ';
      return false;
    } catch (_) {
      lastError.value = 'خطأ في الشبكة';
      return false;
    } finally { isSubmitting.value = false; }
  }

  // ============ Pumps ============

  Future<void> loadPumps() async {
    try {
      isLoading.value = true;
      final r = await repo.listPumps();
      if (_ok(r)) {
        final list = (r.body['meta']?['pumps'] ?? []) as List;
        pumps.assignAll(list.map((e) => Map<String, dynamic>.from(e as Map)).toList());
      }
    } catch (_) {} finally { isLoading.value = false; }
  }

  Future<bool> addPump(Map<String, dynamic> data) async {
    return _doAndReload(() => repo.addPump(data), loadPumps);
  }

  Future<bool> updatePump(int id, Map<String, dynamic> data) async {
    return _doAndReload(() => repo.updatePump(id, data), loadPumps);
  }

  // ============ Products ============

  Future<void> loadProducts() async {
    try {
      isLoading.value = true;
      final r = await repo.listProducts();
      if (_ok(r)) {
        final list = (r.body['meta']?['products'] ?? []) as List;
        products.assignAll(list.map((e) => Map<String, dynamic>.from(e as Map)).toList());
      }
    } catch (_) {} finally { isLoading.value = false; }
  }

  Future<bool> addProduct(Map<String, dynamic> data) async {
    return _doAndReload(() => repo.addProduct(data), loadProducts);
  }

  Future<bool> updateProductPrice(int id, String newPrice) async {
    return _doAndReload(() => repo.updateProductPrice(id, newPrice), loadProducts);
  }

  // ============ Sales (الجوهر) ============

  Future<bool> recordSale(Map<String, dynamic> data) async {
    try {
      isSubmitting.value = true;
      lastError.value = '';
      final r = await repo.recordSale(data);
      // CRITICAL-001-USAGE
      if (await UsageLimitDialog.handleIfLimitExceeded(r)) return false;
      if ((r.statusCode == 200 || r.statusCode == 201) &&
          r.body is Map && r.body['success'] == true) {
        lastSale.value = Map<String, dynamic>.from((r.body['meta']?['sale'] ?? {}) as Map);
        await loadDashboard(); // حدّث الإحصائيات
        return true;
      }
      lastError.value = _msg(r) ?? 'فشل تسجيل البيع';
      return false;
    } catch (_) {
      lastError.value = 'خطأ في الشبكة';
      return false;
    } finally { isSubmitting.value = false; }
  }

  Future<void> loadSales({Map<String, dynamic>? filters}) async {
    try {
      isLoading.value = true;
      final r = await repo.listSales(filters: filters);
      if (_ok(r)) {
        final list = (r.body['meta']?['sales'] ?? []) as List;
        sales.assignAll(list.map((e) => Map<String, dynamic>.from(e as Map)).toList());
      }
    } catch (_) {} finally { isLoading.value = false; }
  }

  Future<void> loadDashboard() async {
    try {
      final r = await repo.dashboard();
      if (_ok(r)) dashboardData.value = Map<String, dynamic>.from((r.body['meta'] ?? {}) as Map);
    } catch (_) {}
  }

  // ============ Companies ============

  Future<void> loadCompanies() async {
    try {
      isLoading.value = true;
      final r = await repo.listCompanies();
      if (_ok(r)) {
        final list = (r.body['meta']?['companies'] ?? []) as List;
        companies.assignAll(list.map((e) => Map<String, dynamic>.from(e as Map)).toList());
      }
    } catch (_) {} finally { isLoading.value = false; }
  }

  Future<bool> addCompany(Map<String, dynamic> data) async {
    return _doAndReload(() => repo.addCompany(data), loadCompanies);
  }

  Future<bool> recordCompanyPayment(int id, String amount, {String? note}) async {
    return _doAndReload(() => repo.recordCompanyPayment(id, amount, note: note), loadCompanies);
  }

  // ============ Shifts (النوبات) ============

  Future<void> loadCurrentShift() async {
    try {
      final r = await repo.currentShift();
      if (_ok(r)) {
        final shift = r.body['meta']?['shift'];
        currentShift.value = shift == null ? null : Map<String, dynamic>.from(shift as Map);
      }
    } catch (_) {}
  }

  Future<bool> openShift({String? openingCash, String? notes}) async {
    return _doAndReload(() => repo.openShift({
      if (openingCash != null) 'opening_cash': openingCash,
      if (notes != null && notes.isNotEmpty) 'notes': notes,
    }), loadCurrentShift);
  }

  Future<bool> closeShift({
    required int shiftId,
    required String actualCash,
    Map<int, String>? pumpClosings,
    String? varianceReason,
    String? closingNotes,
  }) async {
    final pumpClosingsMap = <String, dynamic>{};
    pumpClosings?.forEach((k, v) => pumpClosingsMap[k.toString()] = v);

    return _doAndReload(() => repo.closeShift(shiftId, {
      'actual_cash': actualCash,
      if (pumpClosingsMap.isNotEmpty) 'pump_closings': pumpClosingsMap,
      if (varianceReason != null && varianceReason.isNotEmpty) 'variance_reason': varianceReason,
      if (closingNotes != null && closingNotes.isNotEmpty) 'closing_notes': closingNotes,
    }), () async {
      currentShift.value = null;
      await loadShifts();
    });
  }

  Future<void> loadShifts() async {
    try {
      isLoading.value = true;
      final r = await repo.listShifts();
      if (_ok(r)) {
        final list = (r.body['meta']?['shifts'] ?? []) as List;
        shifts.assignAll(list.map((e) => Map<String, dynamic>.from(e as Map)).toList());
      }
    } catch (_) {} finally { isLoading.value = false; }
  }

  Future<void> loadShiftDetail(int id) async {
    try {
      isLoading.value = true;
      final r = await repo.showShift(id);
      if (_ok(r)) shiftDetail.value = Map<String, dynamic>.from((r.body['meta']?['shift'] ?? {}) as Map);
    } catch (_) {} finally { isLoading.value = false; }
  }

  // ============ Cards ============

  Future<void> loadCards(int companyId) async {
    try {
      isLoading.value = true;
      final r = await repo.listCards(companyId);
      if (_ok(r)) {
        final list = (r.body['meta']?['cards'] ?? []) as List;
        cards.assignAll(list.map((e) => Map<String, dynamic>.from(e as Map)).toList());
      }
    } catch (_) {} finally { isLoading.value = false; }
  }

  Future<bool> addCard(int companyId, Map<String, dynamic> data) async {
    return _doAndReload(() => repo.addCard(companyId, data), () => loadCards(companyId));
  }

  // ============ Variances ============

  Future<void> loadVariances({String? status}) async {
    try {
      isLoading.value = true;
      final r = await repo.listVariances(status: status);
      if (_ok(r)) {
        final list = (r.body['meta']?['variances'] ?? []) as List;
        variances.assignAll(list.map((e) => Map<String, dynamic>.from(e as Map)).toList());
      }
    } catch (_) {} finally { isLoading.value = false; }
  }

  String receiptUrl(String saleUlid) => repo.receiptUrl(saleUlid);

  // ============ Helpers ============

  Future<bool> _doAndReload(Future<Response> Function() action, Future<void> Function() reload) async {
    try {
      isSubmitting.value = true;
      lastError.value = '';
      final r = await action();
      if (_ok(r)) { await reload(); return true; }
      lastError.value = _msg(r) ?? 'فشلت العملية';
      return false;
    } catch (_) {
      lastError.value = 'خطأ في الشبكة';
      return false;
    } finally { isSubmitting.value = false; }
  }

  bool _ok(Response r) =>
      (r.statusCode == 200 || r.statusCode == 201) &&
      r.body is Map && r.body['success'] == true;

  String? _msg(Response r) {
    try { if (r.body is Map) return r.body['message']?.toString(); } catch (_) {}
    return null;
  }
}
