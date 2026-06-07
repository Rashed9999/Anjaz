import 'package:get/get.dart';
import 'package:amyal_pay/data/api/api_client.dart';

/// P1-BRANCHES — Repo الفروع.
class BranchesRepo extends GetxService {
  final ApiClient apiClient;
  BranchesRepo({required this.apiClient});

  static const _base = '/api/v1/amial/merchant/branches';

  Future<Response> list({bool activeOnly = false}) =>
      apiClient.getData('$_base${activeOnly ? "?active_only=1" : ""}');

  Future<Response> show(int id) => apiClient.getData('$_base/$id');
  Future<Response> create(Map<String, dynamic> data) => apiClient.postData(_base, data);
  Future<Response> update(int id, Map<String, dynamic> data) =>
      apiClient.putData('$_base/$id', data);
  Future<Response> destroy(int id) => apiClient.deleteData('$_base/$id');
  Future<Response> setDefault(int id) => apiClient.postData('$_base/$id/default', {});
  Future<Response> report(int id, {String? from, String? to}) {
    final params = <String, String>{};
    if (from != null) params['from'] = from;
    if (to != null) params['to'] = to;
    final qs = params.isEmpty ? '' : '?${params.entries.map((e) => '${e.key}=${e.value}').join('&')}';
    return apiClient.getData('$_base/$id/report$qs');
  }
}
