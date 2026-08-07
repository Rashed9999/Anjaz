/// AMIAL-BILL-PAY-001 (v0.9-D Flutter)
library;

class AmialBillProvider {
  final int id;
  final String code;
  final String name;
  final String displayNameAr;
  final bool isActive;
  final List<AmialBillService> services;

  AmialBillProvider({
    required this.id,
    required this.code,
    required this.name,
    required this.displayNameAr,
    required this.isActive,
    required this.services,
  });

  factory AmialBillProvider.fromJson(Map<String, dynamic> j) {
    final servicesList = j['services'] as List? ?? [];
    return AmialBillProvider(
      id: j['id'] ?? 0,
      code: j['code'] ?? '',
      name: j['name'] ?? '',
      displayNameAr: j['display_name_ar'] ?? j['name'] ?? '',
      isActive: j['is_active'] == true || j['is_active'] == 1,
      services: servicesList
          .map((s) => AmialBillService.fromJson(Map<String, dynamic>.from(s)))
          .toList(),
    );
  }
}

class AmialBillService {
  final int id;
  final int providerId;
  final String code;
  final String displayNameAr;
  final String serviceType;
  final bool isActive;
  final bool requiresAccountNumber;

  AmialBillService({
    required this.id,
    required this.providerId,
    required this.code,
    required this.displayNameAr,
    required this.serviceType,
    required this.isActive,
    required this.requiresAccountNumber,
  });

  factory AmialBillService.fromJson(Map<String, dynamic> j) => AmialBillService(
    id: j['id'] ?? 0,
    providerId: j['provider_id'] ?? 0,
    code: j['code'] ?? '',
    displayNameAr: j['display_name_ar'] ?? j['name'] ?? '',
    serviceType: j['service_type'] ?? '',
    isActive: j['is_active'] == true || j['is_active'] == 1,
    requiresAccountNumber: j['requires_account_number'] == true || j['requires_account_number'] == 1,
  );
}

class AmialBillProduct {
  final int id;
  final int serviceId;
  final String productCode;
  final String name;
  final String amountType; // fixed | variable
  final String? fixedAmount;
  final String? minAmount;
  final String? maxAmount;
  final String feeAmount;

  AmialBillProduct({
    required this.id,
    required this.serviceId,
    required this.productCode,
    required this.name,
    required this.amountType,
    this.fixedAmount,
    this.minAmount,
    this.maxAmount,
    required this.feeAmount,
  });

  factory AmialBillProduct.fromJson(Map<String, dynamic> j) => AmialBillProduct(
    id: j['id'] ?? 0,
    serviceId: j['service_id'] ?? 0,
    productCode: j['product_code'] ?? '',
    name: j['name'] ?? '',
    amountType: j['amount_type'] ?? 'fixed',
    fixedAmount: j['fixed_amount']?.toString(),
    minAmount: j['min_amount']?.toString(),
    maxAmount: j['max_amount']?.toString(),
    feeAmount: j['fee_amount']?.toString() ?? '0',
  );

  bool get isFixed => amountType == 'fixed';
  bool get isVariable => amountType == 'variable';
}

class AmialBillOrder {
  final int id;
  final String orderUlid;
  final int userId;
  final String subscriberAccount;
  final String amount;
  final String fee;
  final String totalDebited;
  final String status; // pending | processing | success | failed | pending_provider_confirmation | reversed
  final String? providerReference;
  final String? providerMessage;
  final DateTime? completedAt;
  final DateTime? createdAt;

  AmialBillOrder({
    required this.id,
    required this.orderUlid,
    required this.userId,
    required this.subscriberAccount,
    required this.amount,
    required this.fee,
    required this.totalDebited,
    required this.status,
    this.providerReference,
    this.providerMessage,
    this.completedAt,
    this.createdAt,
  });

  factory AmialBillOrder.fromJson(Map<String, dynamic> j) => AmialBillOrder(
    id: j['id'] ?? 0,
    orderUlid: j['order_ulid'] ?? '',
    userId: j['user_id'] ?? 0,
    subscriberAccount: j['subscriber_account'] ?? '',
    amount: j['amount']?.toString() ?? '0',
    fee: j['fee']?.toString() ?? '0',
    totalDebited: j['total_debited']?.toString() ?? '0',
    status: j['status'] ?? 'pending',
    providerReference: j['provider_reference'],
    providerMessage: j['provider_message'],
    completedAt: j['completed_at'] != null ? DateTime.tryParse(j['completed_at']) : null,
    createdAt: j['created_at'] != null ? DateTime.tryParse(j['created_at']) : null,
  );

  bool get isPending => ['pending', 'processing', 'pending_provider_confirmation'].contains(status);
  bool get isSuccess => status == 'success';
  bool get isFailed => status == 'failed' || status == 'reversed';
}
