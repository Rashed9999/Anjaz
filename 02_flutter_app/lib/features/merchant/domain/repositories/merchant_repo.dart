import 'package:get/get.dart';
import 'package:amyal_pay/data/api/api_client.dart';
import 'package:amyal_pay/data/api/idempotency_key_generator.dart';

/// AMIAL-MERCHANT-APP-001 (v1.6)
class MerchantRepo extends GetxService {
  final ApiClient apiClient;
  MerchantRepo({required this.apiClient});

  // التاجر يستخدم customer routes (لأن Cash6 يعتبره user مع type=3)
  static const String _customerBase = '/api/v1/customer';

  // ====== Profile ======
  Future<Response> getProfile() => apiClient.getData('$_customerBase/get-customer');
  Future<Response> dailyStats() => apiClient.getData('/api/v1/amial/merchant/daily-stats');
  Future<Response> updateProfile(Map<String, dynamic> data) =>
      apiClient.putData('$_customerBase/update-profile', data);
  Future<Response> logout() => apiClient.postData('$_customerBase/logout', {});

  // ====== Generate Payment Request (QR) ======
  Future<Response> requestPayment({
    required String amount,
    String? note,
    String? customerPhone,
  }) {
    return apiClient.postData(
      '$_customerBase/request-money',
      {
        'amount': amount,
        if (note != null) 'description': note,
        if (customerPhone != null) 'phone': customerPhone,
      },
      idempotencyKey: IdempotencyKeyGenerator.forFinancialAction('merchant_request_payment'),
    );
  }

  // ====== Transaction history (filtered to incoming) ======
  Future<Response> transactionHistory({int page = 1}) =>
      apiClient.getData('$_customerBase/transaction-history?page=$page');

  // ====== Refund (يستخدم AMIAL safe-payment إذا متاح أو send-money عكسي) ======
  Future<Response> processRefund({
    required String originalTransactionId,
    required String amount,
    String? reason,
  }) {
    return apiClient.postData(
      '/api/v1/amial/merchant/refund',
      {
        'original_transaction_id': originalTransactionId,
        'amount': amount,
        if (reason != null) 'reason': reason,
      },
      idempotencyKey: IdempotencyKeyGenerator.forFinancialAction('merchant_refund'),
    );
  }

  // ====== Ledger (المحاسبي) ======
  Future<Response> ledger({int page = 1}) =>
      apiClient.getData('/api/v1/amial/merchant/ledger?page=$page');

  // ====== Withdraw (التاجر يسحب إلى بنكه) ======
  Future<Response> withdraw({
    required String amount,
    required int withdrawalMethodId,
    Map<String, dynamic>? methodFields,
  }) {
    return apiClient.postData(
      '$_customerBase/withdraw',
      {
        'amount': amount,
        'withdrawal_method_id': withdrawalMethodId,
        if (methodFields != null) ...methodFields,
      },
      idempotencyKey: IdempotencyKeyGenerator.forFinancialAction('merchant_withdraw'),
    );
  }
}
