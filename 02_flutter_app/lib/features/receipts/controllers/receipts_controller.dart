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

      final r = await repo.list(type: type, page: currentPage.value);
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
        lastError.value = _msg(r) ?? 'Failed to load receipts';
      }
    } catch (e) {
      if (kDebugMode) debugPrint('loadReceipts error: $e');
      lastError.value = 'Network error';
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
        lastError.value = _msg(r) ?? 'Failed to load';
      }
    } catch (e) {
      lastError.value = 'Network error';
    } finally {
      isLoading.value = false;
    }
  }

  String getDownloadUrl(int id) => repo.downloadUrl(id);

  String? _msg(Response r) {
    try {
      if (r.body is Map) return r.body['message'] as String?;
    } catch (_) {}
    return null;
  }
}
