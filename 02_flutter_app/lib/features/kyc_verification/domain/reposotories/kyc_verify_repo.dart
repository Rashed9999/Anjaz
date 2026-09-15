import 'package:get/get_connect/http/src/response/response.dart';

import '../../../../data/api/api_client.dart';

class KycVerifyRepo {
  final ApiClient apiClient;
  KycVerifyRepo({required this.apiClient});

  /// AMIAL-PROGRESSIVE-KYC-UPGRADE-APP-001
  /// Tier 2 لا يمر من endpoint 6cash القديم الذي يشترط ثلاث صور ويكرر
  /// identification_image[]. المسار الحديث يستقبل رقم الهوية + وجه/ظهر فقط.
  Future<Response> kycVerifyApi(
    Map<String, String> field,
    List<MultipartBody>? multipartBody,
  ) async {
    return apiClient.postMultipartData(
      '/api/v1/amial/me/kyc/identity',
      field,
      multipartBody,
    );
  }

  /// AMIAL-KYC-PRIVACY-APP-001 — اختيار الخصوصية قرار مستقل عن رفع الوثيقة.
  /// نثبته قبل الرفع حتى لا تصل وثيقة لمسار عادي بعد اختيار مراجعة مقيدة.
  Future<Response> updatePrivacyMode(String mode) async {
    return apiClient.postData(
      '/api/v1/amial/me/kyc/privacy',
      {'review_mode': mode},
    );
  }
}
