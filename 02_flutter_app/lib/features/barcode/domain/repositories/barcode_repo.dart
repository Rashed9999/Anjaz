import 'package:get/get.dart';
import 'package:amial_pay/data/api/api_client.dart';

/// AMIAL-BARCODE-001 — Repo للبحث السريع بالباركود.
class BarcodeRepo extends GetxService {
  final ApiClient apiClient;
  BarcodeRepo({required this.apiClient});

  /// يبحث عن منتج بالباركود.
  /// context: 'auto' | 'wholesale' | 'pharmacy'
  Future<Response> lookup(String barcode, {String context = 'auto'}) =>
      apiClient.getData('/api/v1/amial/barcode/lookup',
          query: {'barcode': barcode, 'context': context});
}
