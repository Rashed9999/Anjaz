import 'package:get/get.dart';
import 'package:amial_pay/data/api/api_client.dart';

/// AMIAL-ME-001 — بيانات المستخدم.
class MeRepo extends GetxService {
  final ApiClient apiClient;
  MeRepo({required this.apiClient});

  static const _base = '/api/v1/amial/me';

  Future<Response> show() => apiClient.getData(_base);
  Future<Response> accountNumber() => apiClient.getData('$_base/account-number');
}

class MeController extends GetxController implements GetxService {
  final MeRepo repo;
  MeController({required this.repo});

  final Rx<Map<String, dynamic>?> me = Rx<Map<String, dynamic>?>(null);
  final RxBool isLoading = false.obs;

  Future<void> load() async {
    try {
      isLoading.value = true;
      final r = await repo.show();
      if (r.statusCode == 200 && r.body is Map && r.body['success'] == true) {
        me.value = Map<String, dynamic>.from((r.body['meta'] ?? {}) as Map);
      }
    } catch (_) {} finally {
      isLoading.value = false;
    }
  }
}
