import 'dart:io';

import 'package:amial_pay/data/api/api_client.dart';
import 'package:get/get_connect/http/src/response/response.dart';

/// AMIAL-KYC-COMPLETE-ACCOUNT-APP-001 — كل نداءات توثيق العميل في مكان واحد.
class VerificationCenterRepo {
  final ApiClient apiClient;

  VerificationCenterRepo({required this.apiClient});

  Future<Response> status() =>
      apiClient.getData('/api/v1/amial/me/verification-status');

  Future<Response> completion() =>
      apiClient.getData('/api/v1/amial/me/kyc/completion');

  Future<Response> requestPhoneOtp() =>
      apiClient.postData('/api/v1/customer/check-otp', <String, dynamic>{});

  Future<Response> verifyPhoneOtp(String otp) =>
      apiClient.postData('/api/v1/customer/verify-otp', {'otp': otp});

  Future<Response> residence() =>
      apiClient.getData('/api/v1/amial/me/kyc/residence');

  Future<Response> submitResidence({
    required String birthGovernorate,
    required String governorate,
    required String district,
    String? area,
    String? landmark,
    required String evidenceType,
    required File evidence,
    String? evidenceDate,
  }) {
    final fields = <String, String>{
      'birth_governorate': birthGovernorate,
      'residence_governorate': governorate,
      'residence_district': district,
      if (area != null && area.trim().isNotEmpty) 'residence_area': area.trim(),
      if (landmark != null && landmark.trim().isNotEmpty)
        'residence_landmark': landmark.trim(),
      'evidence_type': evidenceType,
      if (evidenceDate != null && evidenceDate.isNotEmpty)
        'evidence_date': evidenceDate,
    };
    return apiClient.postMultipartData(
      '/api/v1/amial/me/kyc/residence',
      fields,
      [MultipartBody('evidence', evidence)],
    );
  }

  Future<Response> updateFullProfile(Map<String, dynamic> fields) =>
      apiClient.postData('/api/v1/amial/me/kyc/profile', fields);

  Future<Response> updatePrivacy(String mode) =>
      apiClient.postData('/api/v1/amial/me/kyc/privacy', {'review_mode': mode});

  Future<Response> submitOwnershipSelfie({
    required String mode,
    required File selfie,
  }) =>
      apiClient.postMultipartData(
        '/api/v1/amial/me/kyc/ownership/selfie',
        {'review_mode': mode},
        [MultipartBody('selfie', selfie)],
      );

  Future<Response> startBiometric() =>
      apiClient.postData('/api/v1/amial/me/kyc/privacy/biometric/start', {});
}
