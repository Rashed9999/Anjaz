import 'package:get/get.dart';
import 'package:amyal_pay/data/api/api_client.dart';
import 'package:amyal_pay/data/api/idempotency_key_generator.dart';
import 'package:amyal_pay/util/app_constants.dart';

/// AMIAL-DONATIONS-001 (v1.2)
class DonationsRepo extends GetxService {
  final ApiClient apiClient;
  DonationsRepo({required this.apiClient});

  Future<Response> categories() => apiClient.getData(AppConstants.amyalDonationsCategories);
  Future<Response> organizations() => apiClient.getData(AppConstants.amyalDonationsOrgs);

  Future<Response> campaigns({String? categoryCode, bool? featured}) {
    final params = <String>[];
    if (categoryCode != null) params.add('category=$categoryCode');
    if (featured == true) params.add('featured=1');
    final query = params.isEmpty ? '' : '?${params.join('&')}';
    return apiClient.getData('${AppConstants.amyalDonationsCampaigns}$query');
  }

  Future<Response> campaignShow(String ulid) =>
      apiClient.getData('${AppConstants.amyalDonationCampaignShow}$ulid');

  Future<Response> donate({
    required String campaignUlid,
    required String amount,
    bool isAnonymous = false,
    String? message,
  }) {
    return apiClient.postData(
      AppConstants.amyalDonationsDonate,
      {
        'campaign_ulid': campaignUlid,
        'amount': amount,
        'is_anonymous': isAnonymous,
        'message': ?message,
      },
      idempotencyKey: IdempotencyKeyGenerator.forFinancialAction('donate'),
    );
  }

  Future<Response> myDonations() => apiClient.getData(AppConstants.amyalDonationsMy);
}
