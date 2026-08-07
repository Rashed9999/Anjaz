import 'package:get/get.dart';
import 'package:amial_pay/data/api/api_client.dart';
import 'package:amial_pay/data/api/idempotency_key_generator.dart';
import 'package:amial_pay/util/app_constants.dart';

/// AMIAL-FUND-FAMILY-001 (v0.9-D)
class FundsRepo extends GetxService {
  final ApiClient apiClient;
  FundsRepo({required this.apiClient});

  Future<Response> list() async => apiClient.getData(AppConstants.amialFundsList);

  Future<Response> create({
    required String name,
    String? description,
    bool requireOwnerApproval = true,
    String? targetAmount, // AMIAL-FUND-002
  }) async {
    return apiClient.postData(
      AppConstants.amialFundsCreate,
      {
        'name': name,
        'description': ?description,
        'require_owner_approval_for_disbursement': requireOwnerApproval,
        if (targetAmount != null && targetAmount.isNotEmpty) 'target_amount': targetAmount,
      },
      idempotencyKey: IdempotencyKeyGenerator.forFinancialAction('fund_create'),
    );
  }

  Future<Response> show(String ulid) async {
    return apiClient.getData('${AppConstants.amialFundShow}$ulid');
  }

  Future<Response> invite({
    required String fundUlid,
    required String phone,
    String role = 'member',
  }) async {
    return apiClient.postData(
      '${AppConstants.amialFundInvite}$fundUlid/invite',
      {'phone': phone, 'role': role},
      idempotencyKey: IdempotencyKeyGenerator.forFinancialAction('fund_invite'),
    );
  }

  Future<Response> contribute({
    required String fundUlid,
    required String amount,
    String? note,
  }) async {
    return apiClient.postData(
      '${AppConstants.amialFundContribute}$fundUlid/contribute',
      {'amount': amount, 'note': ?note},
      idempotencyKey: IdempotencyKeyGenerator.forFinancialAction('fund_contribute'),
    );
  }

  Future<Response> proposeDisbursement({
    required String fundUlid,
    required int beneficiaryUserId,
    required String amount,
    String? note,
  }) async {
    return apiClient.postData(
      '${AppConstants.amialFundPropose}$fundUlid/propose-disbursement',
      {
        'beneficiary_user_id': beneficiaryUserId,
        'amount': amount,
        'note': ?note,
      },
      idempotencyKey: IdempotencyKeyGenerator.forFinancialAction('fund_disburse'),
    );
  }

  Future<Response> approveDisbursement(String txUlid) async {
    return apiClient.postData(
      '${AppConstants.amialFundApproveDisb}$txUlid/approve',
      {},
      idempotencyKey: IdempotencyKeyGenerator.forFinancialAction('fund_approve'),
    );
  }

  Future<Response> rejectDisbursement(String txUlid, String reason) async {
    return apiClient.postData(
      '${AppConstants.amialFundRejectDisb}$txUlid/reject',
      {'reason': reason},
    );
  }

  Future<Response> acceptInvitation(int membershipId) async {
    return apiClient.postData(
      '${AppConstants.amialFundAcceptInvite}$membershipId/accept',
      {},
    );
  }

  Future<Response> transactions(String fundUlid) async {
    return apiClient.getData('${AppConstants.amialFundTransactions}$fundUlid/transactions');
  }
}
