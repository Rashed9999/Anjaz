import 'package:get/get.dart';
import 'package:amial_pay/data/api/api_client.dart';
import 'package:amial_pay/util/app_constants.dart';

/// AMIAL-DONATIONS-001 (v1.2)
class DonationsRepo extends GetxService {
  final ApiClient apiClient;
  DonationsRepo({required this.apiClient});

  Future<Response> categories() => apiClient.getData(AppConstants.amialDonationsCategories);
  Future<Response> organizations() => apiClient.getData(AppConstants.amialDonationsOrgs);

  Future<Response> campaigns({String? categoryCode, bool? featured}) {
    final params = <String>[];
    if (categoryCode != null) params.add('category=$categoryCode');
    if (featured == true) params.add('featured=1');
    final query = params.isEmpty ? '' : '?${params.join('&')}';
    return apiClient.getData('${AppConstants.amialDonationsCampaigns}$query');
  }

  Future<Response> campaignShow(String ulid) =>
      apiClient.getData('${AppConstants.amialDonationCampaignShow}$ulid');

  Future<Response> donate({
    required String campaignUlid,
    required String amount,
    bool isAnonymous = false,
    String? message,
    // AMIAL-IDEMPOTENCY-002 — **يُستقبَل ولا يُولَّد هنا.**
    // كان يُولَّد في قائمة المُعامِلات، أي في كلّ نداء — فإعادةُ المحاولة
    // بعد انقطاعٍ تصل بمفتاحٍ جديدٍ فتُقرأ تبرّعاً ثانياً. والمستودعُ
    // `GetxService` مفردٌ لا يعرف متى تبدأ نيّةٌ ومتى تنتهي؛ يعرفها المتحكّم.
    required String idempotencyKey,
  }) {
    return apiClient.postData(
      AppConstants.amialDonationsDonate,
      {
        'campaign_ulid': campaignUlid,
        'amount': amount,
        'is_anonymous': isAnonymous,
        'message': ?message,
      },
      idempotencyKey: idempotencyKey,
    );
  }

  Future<Response> myDonations() => apiClient.getData(AppConstants.amialDonationsMy);
}
