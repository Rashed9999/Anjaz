import 'package:get/get.dart';
import 'package:amial_pay/data/api/api_client.dart';
import 'package:amial_pay/util/app_constants.dart';

/// AMIAL-RECEIPTS-001 (v0.9-D)
class ReceiptsRepo extends GetxService {
  final ApiClient apiClient;
  ReceiptsRepo({required this.apiClient});

  /// AMIAL-RECEIPTS-FILTER-001 — بحثٌ وفلاتر.
  ///
  /// **والقيمُ تُرمَّز**: بحثٌ بمسافةٍ أو `+` في رقم هاتف كان يكسر السلسلة
  /// فيصل إلى الخادم ناقصاً — ولا خطأ، فقط «لا نتائج» على رقمٍ موجود.
  Future<Response> list({
    String? type,
    int page = 1,
    String? q,
    String? direction,
    String? from,
    String? to,
    String? minAmount,
    String? maxAmount,
  }) async {
    return apiClient.getData(AppConstants.amialReceiptsList, query: {
      'page': page.toString(),
      if (type != null && type.isNotEmpty) 'type': type,
      if (q != null && q.trim().isNotEmpty) 'q': q.trim(),
      if (direction != null && direction.isNotEmpty) 'direction': direction,
      if (from != null && from.isNotEmpty) 'from': from,
      if (to != null && to.isNotEmpty) 'to': to,
      if (minAmount != null && minAmount.isNotEmpty) 'min_amount': minAmount,
      if (maxAmount != null && maxAmount.isNotEmpty) 'max_amount': maxAmount,
    });
  }

  Future<Response> show(int id) async {
    return apiClient.getData('${AppConstants.amialReceiptShow}$id');
  }

  /// نُرجع الـ URL للتحميل المباشر (الـ Flutter يستخدم url_launcher / file save).
  String downloadUrl(int id) {
    return '${apiClient.appBaseUrl}${AppConstants.amialReceiptDownload}$id/download';
  }

  Future<Response> verifyPublic(String code) async {
    return apiClient.getData('${AppConstants.amialReceiptVerifyPublic}$code');
  }
}
