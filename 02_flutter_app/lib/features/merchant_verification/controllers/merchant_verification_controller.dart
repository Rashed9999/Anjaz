import 'dart:io';
import 'package:get/get.dart';
import 'package:amyal_pay/data/api/api_client.dart';

/// AMIAL-MERCHANT-VERIFY-001 — Repo توثيق التاجر.
class MerchantVerificationRepo extends GetxService {
  final ApiClient apiClient;
  MerchantVerificationRepo({required this.apiClient});

  static const _base = '/api/v1/amial/merchant/verification';

  Future<Response> status() => apiClient.getData(_base);

  /// تقديم الطلب — multipart مع الملفات.
  /// [files] مفاتيح: id_card_front, id_card_back, commercial_register,
  ///                  store_photo, address_proof, profession_license, optional_document
  Future<Response> submit({
    required Map<String, String> data,
    Map<String, File>? files,
  }) async {
    final multipart = <MultipartBody>[];
    if (files != null) {
      files.forEach((key, file) {
        multipart.add(MultipartBody(key, file));
      });
    }
    return apiClient.postMultipartData(_base, data, multipart);
  }
}

class MerchantVerificationController extends GetxController implements GetxService {
  final MerchantVerificationRepo repo;
  MerchantVerificationController({required this.repo});

  /// الحالة الحالية: profile_status, tier, current_request, required_docs, optional_docs
  final Rx<Map<String, dynamic>?> status = Rx<Map<String, dynamic>?>(null);
  final RxBool isLoading = false.obs;
  final RxBool isSubmitting = false.obs;
  final RxString lastError = ''.obs;

  Future<void> load() async {
    try {
      isLoading.value = true;
      final r = await repo.status();
      if (_ok(r)) {
        status.value = Map<String, dynamic>.from((r.body['meta'] ?? {}) as Map);
      }
    } catch (_) {} finally {
      isLoading.value = false;
    }
  }

  Future<bool> submit({required Map<String, String> data, Map<String, File>? files}) async {
    try {
      isSubmitting.value = true;
      lastError.value = '';
      final r = await repo.submit(data: data, files: files);
      if (_ok(r)) {
        await load();
        return true;
      }
      lastError.value = _msg(r) ?? 'فشل تقديم الطلب';
      return false;
    } catch (_) {
      lastError.value = 'خطأ في الشبكة';
      return false;
    } finally {
      isSubmitting.value = false;
    }
  }

  bool _ok(Response r) =>
      (r.statusCode == 200 || r.statusCode == 201) &&
      r.body is Map && r.body['success'] == true;
  String? _msg(Response r) {
    try { if (r.body is Map) return r.body['message']?.toString(); } catch (_) {}
    return null;
  }
}
