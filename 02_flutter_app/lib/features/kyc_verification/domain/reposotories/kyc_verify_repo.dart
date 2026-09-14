import 'package:get/get_connect/http/src/response/response.dart';

import '../../../../util/app_constants.dart';
import '../../../../data/api/api_client.dart';

class KycVerifyRepo {
  final ApiClient apiClient;
  KycVerifyRepo({required this.apiClient});

  Future<Response> kycVerifyApi(Map<String,String> field, List<MultipartBody>? multipartBody) async {
    return await apiClient.postMultipartData(AppConstants.updateKycInformation, field, multipartBody);
  }

  /// AMIAL-KYC-PRIVACY-APP-001 — اختيار الخصوصية قرار مستقل عن رفع الصورة.
  /// لا نضعه داخل حقول legacy كي لا يضيع بصمت في endpoint قديم لا يعرفه.
  Future<Response> updatePrivacyMode(String mode) async {
    return await apiClient.postData(
      '/api/v1/amial/me/kyc/privacy',
      {'review_mode': mode},
    );
  }
}
