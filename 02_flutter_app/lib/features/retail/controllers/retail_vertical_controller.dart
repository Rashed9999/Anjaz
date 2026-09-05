import 'package:get/get.dart';
import 'package:amial_pay/common/controllers/vertical_state_mixin.dart';
import 'package:amial_pay/features/retail/domain/repositories/retail_vertical_repo.dart';

/// AMIAL-RETAIL-VERTICAL-001 · المرحلة ١٠ — متحكّمُ قطاع التجزئة.
///
/// ══════════════════════════════════════════════════════════════════════
/// **والواجهةُ تُبنى من الصلاحيّات لا من نوع النشاط.**
///
/// فالكاشيرُ ومديرُ المخزون والمالكُ يفتحون التطبيقَ نفسَه ويرون شاشاتٍ
/// مختلفة، لا لأنّ في الشيفرة `if (role == 'cashier')` — بل لأنّ الخادم
/// يردّ لكلٍّ صلاحيّاتِه. **وشرطٌ في الشيفرة على اسم الدور يجمد**: يضيف
/// المالكُ دوراً سادساً فلا يراه التطبيق.
///
/// **والإخفاءُ ليس أماناً:** كلُّ فعلٍ يُفحص في الخادم ثانيةً.
class RetailVerticalController extends GetxController
    with VerticalStateMixin
    implements GetxService {
  final RetailVerticalRepo repo;
  RetailVerticalController({required this.repo});

  // ── رموزُ الصلاحيّات — **مصدرُها الخادم، وهذه أسماؤها هنا** ────────
  static const pProductView = 'retail.product.view';
  static const pProductManage = 'retail.product.manage';
  static const pCatalogManage = 'retail.catalog.manage';
  static const pPriceView = 'retail.price.view';
  static const pPricePropose = 'retail.price.propose';
  static const pPriceApprove = 'retail.price.approve';
  static const pStockView = 'retail.stock.view';
  static const pLocationManage = 'retail.location.manage';
  static const pTransferRequest = 'retail.transfer.request';
  static const pTransferApprove = 'retail.transfer.approve';
  static const pTransferShip = 'retail.transfer.ship';
  static const pTransferReceive = 'retail.transfer.receive';
  static const pCountStart = 'retail.count.start';
  static const pCountEnter = 'retail.count.enter';
  static const pCountApprove = 'retail.count.approve';
  static const pWasteRecord = 'retail.waste.record';
  static const pWasteApprove = 'retail.waste.approve';
  static const pReturnCreate = 'retail.return.create';
  static const pReturnApprove = 'retail.return.approve';
  static const pRoleView = 'role.view';
  static const pRoleManage = 'role.manage';

  // ── البيانات ───────────────────────────────────────────────────────
  final Rx<Map<String, dynamic>?> ops = Rx<Map<String, dynamic>?>(null);
  final RxList<Map<String, dynamic>> categories = <Map<String, dynamic>>[].obs;
  final RxList<Map<String, dynamic>> brands = <Map<String, dynamic>>[].obs;
  final RxList<Map<String, dynamic>> units = <Map<String, dynamic>>[].obs;
  final RxList<Map<String, dynamic>> locations = <Map<String, dynamic>>[].obs;
  final RxList<Map<String, dynamic>> transfers = <Map<String, dynamic>>[].obs;
  final RxList<Map<String, dynamic>> counts = <Map<String, dynamic>>[].obs;
  final RxList<Map<String, dynamic>> wastes = <Map<String, dynamic>>[].obs;
  final RxMap<String, dynamic> wasteReport = <String, dynamic>{}.obs;
  final RxList<Map<String, dynamic>> pendingPrices = <Map<String, dynamic>>[].obs;
  final RxList<Map<String, dynamic>> roles = <Map<String, dynamic>>[].obs;

  final Rx<Map<String, dynamic>?> currentTransfer = Rx<Map<String, dynamic>?>(null);
  final Rx<Map<String, dynamic>?> currentCount = Rx<Map<String, dynamic>?>(null);

  // ── أدوات ──────────────────────────────────────────────────────────

  List<Map<String, dynamic>> _list(Response r, String key) {
    final raw = (r.body?['data']?[key] ?? []) as List;
    return raw.map((e) => Map<String, dynamic>.from(e as Map)).toList();
  }

  Future<T?> _run<T>(Future<Response> Function() call, T? Function(Response) map,
      {bool submitting = false}) async {
    clearState();
    if (submitting) {
      isSubmitting.value = true;
    } else {
      isLoading.value = true;
    }
    try {
      final r = await call();
      classify(r);
      if (!okOf(r)) {
        lastError.value = msgOf(r);
        return null;
      }
      return map(r);
    } catch (_) {
      // **لا يُبتلع العطل**: شاشةٌ فارغةٌ بلا سبب تُقرأ «لا بيانات».
      isOffline.value = true;
      lastError.value = 'لا اتصال بالخادم — تحقّق من الشبكة';
      return null;
    } finally {
      isSubmitting.value = false;
      isLoading.value = false;
    }
  }

  // ── التحميل ────────────────────────────────────────────────────────

  /// **تُحمَّل الصلاحيّاتُ أوّلاً** — ثمّ تُرسم القائمة منها.
  Future<void> loadPermissions() async {
    await _run(repo.myPermissions, (r) {
      final d = r.body?['data'] ?? {};
      isOwner.value = d['is_owner'] == true;
      permissions
        ..clear()
        ..addAll(((d['permissions'] ?? []) as List).map((e) => '$e'));
      catalogue.value = Map<String, dynamic>.from((d['catalogue'] ?? {}) as Map);
      return true;
    });
  }

  Future<void> loadOps() async {
    await _run(repo.ops, (r) {
      ops.value = Map<String, dynamic>.from((r.body?['data'] ?? {}) as Map);
      return true;
    });
  }

  Future<void> loadCatalog() async {
    await _run(repo.categories, (r) {
      categories.assignAll(_list(r, 'tree'));
      return true;
    });
    await _run(repo.brands, (r) {
      brands.assignAll(_list(r, 'brands'));
      return true;
    });
    await _run(repo.units, (r) {
      units.assignAll(_list(r, 'units'));
      return true;
    });
  }

  Future<void> loadLocations() async {
    await _run(repo.locations, (r) {
      locations.assignAll(_list(r, 'locations'));
      return true;
    });
  }

  Future<void> loadTransfers() async {
    await _run(repo.transfers, (r) {
      transfers.assignAll(_list(r, 'transfers'));
      return true;
    });
  }

  Future<void> loadTransfer(int id) async {
    await _run(() => repo.showTransfer(id), (r) {
      final d = Map<String, dynamic>.from((r.body?['data'] ?? {}) as Map);
      currentTransfer.value = d;
      return true;
    });
  }

  Future<void> loadCounts() async {
    await _run(repo.counts, (r) {
      counts.assignAll(_list(r, 'counts'));
      return true;
    });
  }

  Future<void> loadCountSheet(int id) async {
    await _run(() => repo.countSheet(id), (r) {
      currentCount.value = Map<String, dynamic>.from((r.body?['data'] ?? {}) as Map);
      return true;
    });
  }

  Future<void> loadWastes({int days = 30}) async {
    await _run(() => repo.wastes(days: days), (r) {
      final d = r.body?['data'] ?? {};
      wasteReport.value = Map<String, dynamic>.from((d['report'] ?? {}) as Map);
      wastes.assignAll(((d['items'] ?? []) as List)
          .map((e) => Map<String, dynamic>.from(e as Map))
          .toList());
      return true;
    });
  }

  Future<void> loadPendingPrices() async {
    await _run(repo.pendingPrices, (r) {
      pendingPrices.assignAll(_list(r, 'pending'));
      return true;
    });
  }

  Future<void> loadRoles() async {
    await _run(repo.roles, (r) {
      roles.assignAll(_list(r, 'roles'));
      return true;
    });
  }

  // ── الأفعال — **كلٌّ منها يعيد نجاحَه ليُعرض للمستعمل** ────────────

  Future<bool> addCategory(Map<String, dynamic> d) async =>
      await _run(() => repo.addCategory(d), (_) => true, submitting: true) ?? false;

  Future<bool> addBrand(Map<String, dynamic> d) async =>
      await _run(() => repo.addBrand(d), (_) => true, submitting: true) ?? false;

  Future<bool> addUnit(Map<String, dynamic> d) async =>
      await _run(() => repo.addUnit(d), (_) => true, submitting: true) ?? false;

  Future<bool> addLocation(Map<String, dynamic> d) async =>
      await _run(() => repo.addLocation(d), (_) => true, submitting: true) ?? false;

  /// AMIAL-VARIANTS-REACH-001 — توليدُ متغيّرات صنفٍ من محاوره.
  ///
  /// **المستودعُ كان يحمل النداءَ منذ بُني القطاع ولا شيءَ يناديه** — لا
  /// متحكّمَ ولا شاشة. والخادمُ كاملٌ: ضربٌ ديكارتيّ للمحاور، وسقفُ ٢٠٠
  /// متغيّرٍ في المرّة، وإعادةُ التوليد لا تكرّر ما وُلد.
  Future<bool> generateVariants(int productId, Map<String, List<String>> axes) async =>
      await _run(() => repo.generateVariants(productId, axes),
          (_) => true, submitting: true) ?? false;

  // ── AMIAL-VARIANT-EDITOR-001 · AMIAL-PRODUCT-ATTRIBUTES-001 ────────

  /// آخرُ رسالةِ نجاحٍ من الخادم — **تحمل «١٠ وحداتٍ تنتظر التوزيع»**،
  /// وهي رسالةٌ لا تُبنى في التطبيق فلا تُخترَع.
  final lastMessage = ''.obs;

  Future<Map<String, dynamic>?> loadVariants(int productId) async =>
      await _run(() => repo.productVariants(productId),
          (r) => Map<String, dynamic>.from((r.body?['data'] ?? {}) as Map));

  Future<bool> saveVariant(int variantId, Map<String, dynamic> d) async =>
      await _run(() => repo.updateVariant(variantId, d),
          (_) => true, submitting: true) ?? false;

  Future<bool> generateVariantsFromLibrary(
          int productId, List<Map<String, dynamic>> selection) async =>
      await _run(() => repo.generateVariantsFromLibrary(productId, selection),
          (r) {
            lastMessage.value = '${r.body?['message'] ?? ''}';
            return true;
          }, submitting: true) ?? false;

  Future<List<Map<String, dynamic>>?> loadAttributes() async =>
      await _run(() => repo.attributes(), (r) =>
          ((((r.body?['data'] ?? {}) as Map)['attributes'] ?? []) as List)
              .map((e) => Map<String, dynamic>.from(e as Map)).toList());

  Future<bool> addAttribute(String name, List<String> terms) async =>
      await _run(() => repo.addAttribute(name, terms), (_) => true,
          submitting: true) ?? false;

  Future<bool> addAttributeTerms(int attributeId, List<String> terms) async =>
      await _run(() => repo.addAttributeTerms(attributeId, terms), (_) => true,
          submitting: true) ?? false;

  Future<bool> deleteAttributeTerm(int termId) async =>
      await _run(() => repo.deleteAttributeTerm(termId), (_) => true,
          submitting: true) ?? false;

  Future<bool> deleteAttribute(int attributeId) async =>
      await _run(() => repo.deleteAttribute(attributeId), (_) => true,
          submitting: true) ?? false;

  Future<bool> requestTransfer(Map<String, dynamic> d) async =>
      await _run(() => repo.requestTransfer(d), (_) => true, submitting: true) ?? false;

  Future<bool> approveTransfer(int id) async =>
      await _run(() => repo.approveTransfer(id), (_) => true, submitting: true) ?? false;

  Future<bool> shipTransfer(int id, Map<String, dynamic> shipped) async =>
      await _run(() => repo.shipTransfer(id, shipped), (_) => true, submitting: true) ?? false;

  Future<bool> receiveTransfer(int id, Map<String, dynamic> received) async =>
      await _run(() => repo.receiveTransfer(id, received), (_) => true, submitting: true) ?? false;

  Future<bool> openCount(Map<String, dynamic> d) async =>
      await _run(() => repo.openCount(d), (_) => true, submitting: true) ?? false;

  Future<bool> enterCount(int id, Map<String, dynamic> d) async =>
      await _run(() => repo.enterCount(id, d), (_) => true, submitting: true) ?? false;

  Future<bool> submitCount(int id) async =>
      await _run(() => repo.submitCount(id), (_) => true, submitting: true) ?? false;

  Future<bool> approveCount(int id) async =>
      await _run(() => repo.approveCount(id), (_) => true, submitting: true) ?? false;

  Future<bool> recordWaste(Map<String, dynamic> d) async =>
      await _run(() => repo.recordWaste(d), (_) => true, submitting: true) ?? false;

  Future<bool> approveWaste(int id) async =>
      await _run(() => repo.approveWaste(id), (_) => true, submitting: true) ?? false;

  Future<bool> rejectWaste(int id, String reason) async =>
      await _run(() => repo.rejectWaste(id, reason), (_) => true, submitting: true) ?? false;

  Future<bool> approvePrice(int id) async =>
      await _run(() => repo.approvePrice(id), (_) => true, submitting: true) ?? false;

  Future<bool> proposePrice(Map<String, dynamic> d) async =>
      await _run(() => repo.proposePrice(d), (_) => true, submitting: true) ?? false;

  Future<bool> seedRoles() async =>
      await _run(repo.seedRoles, (_) => true, submitting: true) ?? false;

  // ── قراءاتٌ مشتقّة من `ops` ────────────────────────────────────────

  int pendingOf(String key) {
    final p = ops.value?['pending'];
    if (p is! Map) return 0;
    final v = p[key];
    return v is int ? v : int.tryParse('$v') ?? 0;
  }

  List<Map<String, dynamic>> get lowStock =>
      ((ops.value?['low_stock'] ?? []) as List)
          .map((e) => Map<String, dynamic>.from(e as Map))
          .toList();

  List<Map<String, dynamic>> get inTransit =>
      ((ops.value?['in_transit'] ?? []) as List)
          .map((e) => Map<String, dynamic>.from(e as Map))
          .toList();

  // ══════════════════════════════════════════════════════════════════
  // AMIAL-NEGATIVE-STOCK-001 — **الخادمُ يُرسلها منذ بُنيت، ولا قارئَ لها.**
  //
  // `RetailVerticalController::operationsCenter` يضع `negative_stock` في
  // الردّ، ولم يكن في التطبيق سطرٌ واحدٌ يقرأ الاسم. **فالميزةُ تنتهي عند
  // JSON** — وهو نمطُ العطل الأكثر تكراراً في المشروع: مبنيٌّ ولا يُوصَل
  // إليه. (القاعدة الثانية عشرة.)
  // ══════════════════════════════════════════════════════════════════
  List<Map<String, dynamic>> get negativeStock =>
      ((ops.value?['negative_stock'] ?? []) as List)
          .map((e) => Map<String, dynamic>.from(e as Map))
          .toList();

  /// **ولا يُقرأ القفلُ صفراً.**
  ///
  /// حين تُقفل «تنبيهات النفاد» بالباقة يُرسل الخادمُ `low_stock` **معدومةً**
  /// و`low_stock_locked` تصف القفل — والتعليقُ في المتحكّم يقول الغرضَ
  /// صراحةً: «فالشاشةُ تعرض ارفع الباقة مكانَ قائمةٍ فارغةٍ تكذب».
  /// ولم يكن في التطبيق قارئٌ لها، **فقُرئ القفلُ «فحصنا فلم نجد»** —
  /// والتاجرُ يظنّ مخزونَه سليماً وهو لم يُفحص. (القاعدة السابعة.)
  Map<String, dynamic>? get lowStockLocked {
    final v = ops.value?['low_stock_locked'];

    return v is Map ? Map<String, dynamic>.from(v) : null;
  }
}
