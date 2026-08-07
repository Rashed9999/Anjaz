import 'package:get/get.dart';
import 'package:amial_pay/data/api/api_client.dart';
import 'package:amial_pay/util/app_constants.dart';

/// AMIAL-RECEIPTS-001 (v0.9-D)
class ReceiptsRepo extends GetxService {
  final ApiClient apiClient;
  ReceiptsRepo({required this.apiClient});

  Future<Response> list({String? type, int page = 1}) async {
    final params = <String, String>{'page': page.toString()};
    if (type != null) params['type'] = type;
    final query = params.entries.map((e) => '${e.key}=${e.value}').join('&');
    return apiClient.getData('${AppConstants.amialReceiptsList}?$query');
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
