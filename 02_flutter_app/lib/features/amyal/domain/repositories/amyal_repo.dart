import 'package:get/get.dart';
import 'package:amyal_pay/data/api/api_client.dart';
import 'package:amyal_pay/data/api/idempotency_key_generator.dart';
import 'package:amyal_pay/util/app_constants.dart';

/// AMYAL-LEGAL/ZONE/RECOVERY-001 (v0.7-D)
///
/// Repository موحد لكل Amyal endpoints الجديدة.
/// يفصل العميل HTTP عن الـ Controllers (clean architecture).
class AmyalRepo extends GetxService {
  final ApiClient apiClient;
  AmyalRepo({required this.apiClient});

  // ============ Zone Policy ============

  /// GET /api/v1/amial/policy/session
  Future<Response> getSessionPolicy() async {
    return await apiClient.getData(AppConstants.amyalPolicySession);
  }

  // ============ Legal Terms ============

  /// GET /api/v1/amial/legal/status
  Future<Response> getLegalStatus() async {
    return await apiClient.getData(AppConstants.amyalLegalStatus);
  }

  /// GET /api/v1/amial/legal/current
  Future<Response> getCurrentTerms() async {
    return await apiClient.getData(AppConstants.amyalLegalCurrent);
  }

  /// POST /api/v1/amial/legal/accept
  Future<Response> acceptTerms({
    required String version,
    String locale = 'ar',
    String? deviceId,
  }) async {
    return await apiClient.postData(
      AppConstants.amyalLegalAccept,
      {
        'version': version,
        'locale': locale,
        if (deviceId != null) 'device_id': deviceId,
      },
      idempotencyKey: IdempotencyKeyGenerator.forFinancialAction('terms_accept'),
    );
  }

  // ============ Account Recovery ============

  /// POST /api/v1/amial/recovery/initiate-self
  Future<Response> initiateSelfRecovery({required String newPhone}) async {
    return await apiClient.postData(
      AppConstants.amyalRecoveryInitiateSelf,
      {'new_phone': newPhone},
      idempotencyKey:
          IdempotencyKeyGenerator.forFinancialAction('recovery_initiate_self'),
    );
  }

  /// POST /api/v1/amial/recovery/initiate-lost
  Future<Response> initiateLostRecovery({
    required String newPhone,
    required List<String> identificationDocuments,
    String? userNotes,
  }) async {
    return await apiClient.postData(
      AppConstants.amyalRecoveryInitiateLost,
      {
        'new_phone': newPhone,
        'identification_documents': identificationDocuments,
        if (userNotes != null) 'user_notes': userNotes,
      },
      idempotencyKey:
          IdempotencyKeyGenerator.forFinancialAction('recovery_initiate_lost'),
    );
  }

  /// POST /api/v1/amial/recovery/{ulid}/verify-otp
  Future<Response> verifyRecoveryOtp({
    required String ulid,
    required String otpOld,
    required String otpNew,
  }) async {
    return await apiClient.postData(
      '${AppConstants.amyalRecoveryVerifyOtp}$ulid/verify-otp',
      {'otp_old': otpOld, 'otp_new': otpNew},
      // OTP verify ليست عملية مالية، لكن idempotency يحميها من double-submit
      idempotencyKey:
          IdempotencyKeyGenerator.forFinancialAction('recovery_verify_otp'),
    );
  }

  /// POST /api/v1/amial/recovery/{ulid}/complete
  Future<Response> completeRecovery({
    required String ulid,
    required String pin,
  }) async {
    return await apiClient.postData(
      '${AppConstants.amyalRecoveryComplete}$ulid/complete',
      {'pin': pin},
      idempotencyKey:
          IdempotencyKeyGenerator.forFinancialAction('recovery_complete'),
    );
  }

  /// GET /api/v1/amial/recovery/{ulid}
  Future<Response> getRecoveryStatus(String ulid) async {
    return await apiClient.getData('${AppConstants.amyalRecoveryShow}$ulid');
  }
}
