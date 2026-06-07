/// AMIAL-AGENT-APP-001 (v1.6) — Agent models
library;

class AmyalAgent {
  final int id;
  final String? agentNumber;
  final String? fName;
  final String? lName;
  final String? phone;
  final String? phoneMasked;
  final String? email;
  final String balance;
  final String? imageUrl;
  final bool kycVerified;

  AmyalAgent({
    required this.id,
    this.agentNumber,
    this.fName,
    this.lName,
    this.phone,
    this.phoneMasked,
    this.email,
    required this.balance,
    this.imageUrl,
    required this.kycVerified,
  });

  factory AmyalAgent.fromJson(Map<String, dynamic> j) {
    return AmyalAgent(
      id: j['id'] ?? 0,
      agentNumber: j['agent_number']?.toString(),
      fName: j['f_name']?.toString(),
      lName: j['l_name']?.toString(),
      phone: j['phone']?.toString(),
      phoneMasked: j['phone_masked']?.toString(),
      email: j['email']?.toString(),
      balance: (j['current_balance'] ?? j['balance'] ?? '0').toString(),
      imageUrl: j['image']?.toString(),
      kycVerified: (j['is_kyc_verified'] ?? j['kyc_verified'] ?? 0) == 1,
    );
  }

  String get displayName {
    final parts = [fName, lName].where((p) => p != null && p.isNotEmpty).toList();
    return parts.isNotEmpty ? parts.join(' ') : (agentNumber ?? 'Agent');
  }
}

class AmyalAgentTransaction {
  final int id;
  final String transactionId;
  final String type; // cash_in, cash_out, withdraw, add_money
  final String amount;
  final String? fee;
  final String? counterpartyName;
  final String? counterpartyPhoneMasked;
  final String status; // success, pending, failed
  final DateTime createdAt;

  AmyalAgentTransaction({
    required this.id,
    required this.transactionId,
    required this.type,
    required this.amount,
    this.fee,
    this.counterpartyName,
    this.counterpartyPhoneMasked,
    required this.status,
    required this.createdAt,
  });

  factory AmyalAgentTransaction.fromJson(Map<String, dynamic> j) {
    return AmyalAgentTransaction(
      id: j['id'] ?? 0,
      transactionId: (j['transaction_id'] ?? j['ulid'] ?? '').toString(),
      type: (j['type'] ?? j['transaction_type'] ?? 'unknown').toString(),
      amount: (j['amount'] ?? '0').toString(),
      fee: j['fee']?.toString(),
      counterpartyName: j['counterparty_name']?.toString(),
      counterpartyPhoneMasked: (j['counterparty_phone_masked'] ?? j['receiver_phone'])?.toString(),
      status: (j['status'] ?? 'unknown').toString(),
      createdAt: j['created_at'] != null
          ? (DateTime.tryParse(j['created_at'].toString()) ?? DateTime.now())
          : DateTime.now(),
    );
  }

  String get typeLabel {
    switch (type) {
      case 'cash_in':
      case 'send_money':
        return 'إيداع للعميل';
      case 'cash_out':
      case 'request_money':
        return 'سحب من العميل';
      case 'withdraw':
        return 'سحب بنكي';
      case 'add_money':
        return 'إضافة رصيد';
      default:
        return type;
    }
  }

  String get statusLabel {
    return switch (status) {
      'success' => 'ناجحة',
      'pending' => 'معلقة',
      'failed' => 'فاشلة',
      _ => status,
    };
  }
}

class AmyalAgentDashboardStats {
  final String todayCashIn;
  final String todayCashOut;
  final String todayCommission;
  final int todayTransactionsCount;
  final String balance;

  AmyalAgentDashboardStats({
    required this.todayCashIn,
    required this.todayCashOut,
    required this.todayCommission,
    required this.todayTransactionsCount,
    required this.balance,
  });

  factory AmyalAgentDashboardStats.empty() => AmyalAgentDashboardStats(
        todayCashIn: '0',
        todayCashOut: '0',
        todayCommission: '0',
        todayTransactionsCount: 0,
        balance: '0',
      );

  factory AmyalAgentDashboardStats.fromJson(Map<String, dynamic> j) {
    return AmyalAgentDashboardStats(
      todayCashIn: (j['today_cash_in'] ?? '0').toString(),
      todayCashOut: (j['today_cash_out'] ?? '0').toString(),
      todayCommission: (j['today_commission'] ?? j['today_earned'] ?? '0').toString(),
      todayTransactionsCount: j['today_count'] ?? j['today_transactions'] ?? 0,
      balance: (j['current_balance'] ?? j['balance'] ?? '0').toString(),
    );
  }
}
