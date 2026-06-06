import 'package:get/get.dart';
import 'package:amyal_pay/data/api/api_client.dart';

/// CRITICAL-001-PLANS — Repo كتالوج الخطط + Usage.
class PlansRepo extends GetxService {
  final ApiClient apiClient;
  PlansRepo({required this.apiClient});

  /// GET /plans — كل الخطط + الخطّة الحالية.
  Future<Response> catalog() => apiClient.getData('/api/v1/amial/plans');

  /// GET /usage — snapshot الاستخدام (للتاجر).
  Future<Response> myUsage() => apiClient.getData('/api/v1/amial/usage');
}
