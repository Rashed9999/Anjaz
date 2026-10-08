import 'package:flutter/foundation.dart';
import 'package:get/get.dart';
import 'package:amial_pay/features/receipts/domain/models/receipt_models.dart';
import 'package:amial_pay/features/receipts/domain/repositories/receipts_repo.dart';

/// AMIAL-RECEIPTS-001 (v0.9-D)
class ReceiptsController extends GetxController implements GetxService {
  final ReceiptsRepo repo;
  ReceiptsController({required this.repo});

  final RxList<AmialReceipt> receipts = <AmialReceipt>[].obs;
  final Rx<AmialReceipt?> selectedReceipt = Rx<AmialReceipt?>(null);
  final RxBool isLoading = false.obs;
  final RxBool isLoadingMore = false.obs;
  final RxString lastError = ''.obs;
  final RxInt currentPage = 1.obs;
  final RxBool hasMore = true.obs;

  // ── الفلاتر — AMIAL-RECEIPTS-FILTER-001 ──────────────────────────────
  //
  // **الثمن الذي دُفع:** شاشةٌ بلا بحثٍ ولا فلتر. ومن له مئةُ عمليّةٍ في
  // الشهر يبحث عن تحويلٍ لشخصٍ بعينه بالتمرير — ولا يجده.
  final RxString query = ''.obs;
  final RxString direction = ''.obs;   // '' | debit | credit
  final RxString typeFilter = ''.obs;
  final RxString fromDate = ''.obs;
  final RxString toDate = ''.obs;
  final RxString minAmount = ''.obs;
  final RxString maxAmount = ''.obs;

  /// **كم فلتراً مفعّل** — يُعرض على الزرّ فلا يُنسى فلترٌ يُخفي النتائج.
  int get activeFilterCount => [
        direction.value, typeFilter.value,
        fromDate.value, toDate.value,
        minAmount.value, maxAmount.value,
      ].where((v) => v.isNotEmpty).length;

  bool get hasAnyFilter => activeFilterCount > 0 || query.value.trim().isNotEmpty;

  void clearFilters() {
    query.value = '';
    direction.value = '';
    typeFilter.value = '';
    fromDate.value = '';
    toDate.value = '';
    minAmount.value = '';
    maxAmount.value = '';
  }

  Future<void> loadReceipts({String? type, bool refresh = false}) async {
    if (refresh) {
      currentPage.value = 1;
      hasMore.value = true;
      receipts.clear();
    }
    if (!hasMore.value) return;

    try {
      if (currentPage.value == 1) {
        isLoading.value = true;
      } else {
        isLoadingMore.value = true;
      }

      final r = await repo.list(
        type: type ?? (typeFilter.value.isEmpty ? null : typeFilter.value),
        page: currentPage.value,
        q: query.value,
        direction: direction.value,
        from: fromDate.value,
        to: toDate.value,
        minAmount: minAmount.value,
        maxAmount: maxAmount.value,
      );
      if (r.statusCode == 200 && r.body is Map) {
        final meta = (r.body['meta'] ?? {}) as Map;
        final items = meta['items'] as List? ?? [];
        final newReceipts = items
            .map((j) => AmialReceipt.fromJson(Map<String, dynamic>.from(j)))
            .toList();

        receipts.addAll(newReceipts);

        final pag = meta['pagination'] as Map? ?? {};
        final total = pag['total'] ?? 0;
        hasMore.value = receipts.length < total;
        currentPage.value++;
        lastError.value = '';
      } else {
        lastError.value = _msg(r) ?? 'تعذّر تحميل الإيصالات';
      }
    } catch (e) {
      if (kDebugMode) debugPrint('loadReceipts error: $e');
      lastError.value = 'لا اتصال بالخادم — تحقّق من الشبكة وأعد المحاولة';
    } finally {
      isLoading.value = false;
      isLoadingMore.value = false;
    }
  }

  Future<void> selectReceipt(int id) async {
    try {
      isLoading.value = true;
      final r = await repo.show(id);
      if (r.statusCode == 200 && r.body is Map) {
        final meta = Map<String, dynamic>.from(r.body['meta'] ?? {});
        selectedReceipt.value = AmialReceipt.fromJson(meta);
        lastError.value = '';
      } else {
        lastError.value = _msg(r) ?? 'تعذّر التحميل';
      }
    } catch (e) {
      lastError.value = 'لا اتصال بالخادم — تحقّق من الشبكة وأعد المحاولة';
    } finally {
      isLoading.value = false;
    }
  }

  String getDownloadUrl(int id) => repo.downloadUrl(id);

  String getDocumentUrl(int id, String route) => repo.documentUrl(id, route);

  String getPublicVerificationUrl(String verificationCode) =>
      repo.publicVerificationUrl(verificationCode);

  Future<void> recordPrint({required int id, required String format, required String printerName}) async {
    try {
      await repo.recordPrint(id, format: format, printerName: printerName);
    } catch (_) {
      // التدقيق اللاحق لا يجعل طباعةً نجحت تبدو فاشلة للمستخدم.
    }
  }

  String? _msg(Response r) {
    try {
      if (r.body is Map) return r.body['message'] as String?;
    } catch (_) {}
    return null;
  }
}
