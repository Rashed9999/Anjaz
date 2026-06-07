import 'package:get/get.dart';
import 'package:amyal_pay/data/api/api_client.dart';
import 'package:amyal_pay/data/api/idempotency_key_generator.dart';

/// AMIAL-AGENT-APP-001 (v1.6)
class AgentRepo extends GetxService {
  final ApiClient apiClient;
  AgentRepo({required this.apiClient});

  static const String _base = '/api/v1/agent';

  // ====== Profile ======
  Future<Response> getProfile() => apiClient.getData('$_base/get-agent');
  Future<Response> dailyStats() => apiClient.getData('/api/v1/amial/agent/daily-stats');

  // ====== AMIAL-AGENT-NETWORK-001 (v2.4): Float + Settlements ======
  Future<Response> floatDashboard() =>
      apiClient.getData('/api/v1/amial/agent/float-dashboard');

  Future<Response> requestTopup({
    required String amount,
    String paymentMethod = 'cash',
    String? paymentReference,
  }) {
    return apiClient.postData('/api/v1/amial/agent/topup-request', {
      'amount': amount,
      'payment_method': paymentMethod,
      'payment_reference': ?paymentReference,
    });
  }

  Future<Response> settlements() =>
      apiClient.getData('/api/v1/amial/agent/settlements');
  Future<Response> updateProfile(Map<String, dynamic> data) =>
      apiClient.putData('$_base/update-profile', data);
  Future<Response> logout() => apiClient.postData('$_base/logout', {});

  // ====== Cash In (Agent → Customer) ======
  Future<Response> cashIn({
    required String customerPhone,
    required String amount,
    required String pin,
    String? note,
  }) {
    return apiClient.postData(
      '$_base/send-money',
      {
        'phone': customerPhone,
        'amount': amount,
        'pin': pin,
        'note': ?note,
      },
      idempotencyKey: IdempotencyKeyGenerator.forFinancialAction('agent_cash_in'),
    );
  }

  // ====== Cash Out (Customer → Agent, then customer gets cash) ======
  Future<Response> requestCashOut({
    required String customerPhone,
    required String amount,
    String? note,
  }) {
    return apiClient.postData(
      '$_base/request-money',
      {
        'phone': customerPhone,
        'amount': amount,
        'note': ?note,
      },
      idempotencyKey: IdempotencyKeyGenerator.forFinancialAction('agent_cash_out_request'),
    );
  }

  // ====== Add Money (Agent buys credit from system) ======
  Future<Response> addMoney({
    required String amount,
    required int withdrawalMethodId,
    Map<String, dynamic>? methodFields,
  }) {
    return apiClient.postData(
      '$_base/add-money',
      {
        'amount': amount,
        'withdrawal_method_id': withdrawalMethodId,
        ...?methodFields,
      },
      idempotencyKey: IdempotencyKeyGenerator.forFinancialAction('agent_add_money'),
    );
  }

  // ====== Withdraw (Agent → Bank) ======
  Future<Response> withdraw({
    required String amount,
    required int withdrawalMethodId,
    Map<String, dynamic>? methodFields,
  }) {
    return apiClient.postData(
      '$_base/withdraw',
      {
        'amount': amount,
        'withdrawal_method_id': withdrawalMethodId,
        ...?methodFields,
      },
      idempotencyKey: IdempotencyKeyGenerator.forFinancialAction('agent_withdraw'),
    );
  }

  // ====== Transactions ======
  Future<Response> transactionHistory({String? type, int page = 1}) {
    final params = <String, String>{'page': '$page'};
    if (type != null) params['type'] = type;
    final query = params.entries.map((e) => '${e.key}=${e.value}').join('&');
    return apiClient.getData('$_base/transaction-history?$query');
  }

  Future<Response> withdrawalMethods() => apiClient.getData('$_base/withdrawal-methods');
  Future<Response> withdrawalRequests() => apiClient.getData('$_base/withdrawal-requests');

  // ====== Security ======
  Future<Response> verifyPin(String pin) =>
      apiClient.postData('$_base/verify-pin', {'pin': pin});

  Future<Response> changePin({required String oldPin, required String newPin}) =>
      apiClient.postData('$_base/change-pin', {
        'current_pin': oldPin,
        'new_pin': newPin,
        'confirm_pin': newPin,
      });

  // ====== Check customer (verify before cash-in) ======
  Future<Response> checkCustomer(String phone) =>
      apiClient.postData('/api/v1/check-customer', {'phone': phone});
}
