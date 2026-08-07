/// AMIAL-FUND-FAMILY-001 (v0.9-D Flutter)
library;

class AmialFund {
  final int id;
  final String fundUlid;
  final String name;
  final String? description;
  final int ownerUserId;
  final String balance;
  final String heldBalance;
  final String zoneCode;
  final String status;
  final bool requireOwnerApprovalForDisbursement;
  final String? targetAmount; // AMIAL-FUND-002: المبلغ المستهدف

  AmialFund({
    required this.id,
    required this.fundUlid,
    required this.name,
    this.description,
    required this.ownerUserId,
    required this.balance,
    required this.heldBalance,
    required this.zoneCode,
    required this.status,
    required this.requireOwnerApprovalForDisbursement,
    this.targetAmount,
  });

  factory AmialFund.fromJson(Map<String, dynamic> j) => AmialFund(
    id: j['id'] ?? 0,
    fundUlid: j['fund_ulid'] ?? '',
    name: j['name'] ?? '',
    description: j['description'],
    ownerUserId: j['owner_user_id'] ?? 0,
    balance: j['balance']?.toString() ?? '0',
    heldBalance: j['held_balance']?.toString() ?? '0',
    zoneCode: j['zone_code'] ?? 'SOUTH',
    status: j['status'] ?? 'active',
    requireOwnerApprovalForDisbursement: j['require_owner_approval_for_disbursement'] == true || j['require_owner_approval_for_disbursement'] == 1,
    targetAmount: j['target_amount']?.toString(),
  );

  bool get isActive => status == 'active';
}

class AmialFundMembership {
  final int membershipId;
  final String role;        // owner, admin, member, viewer
  final String status;      // active, invited, declined, removed
  final String totalContributed;
  final String totalDisbursed;
  final AmialFund? fund;

  AmialFundMembership({
    required this.membershipId,
    required this.role,
    required this.status,
    required this.totalContributed,
    required this.totalDisbursed,
    this.fund,
  });

  factory AmialFundMembership.fromJson(Map<String, dynamic> j) => AmialFundMembership(
    membershipId: j['membership_id'] ?? 0,
    role: j['role'] ?? 'member',
    status: j['status'] ?? 'invited',
    totalContributed: j['total_contributed']?.toString() ?? '0',
    totalDisbursed: j['total_disbursed']?.toString() ?? '0',
    fund: j['fund'] is Map ? AmialFund.fromJson(Map<String, dynamic>.from(j['fund'])) : null,
  );

  bool get isOwner => role == 'owner';
  bool get isActive => status == 'active';
  bool get isInvited => status == 'invited';
  bool get canContribute => isActive && ['owner', 'admin', 'member'].contains(role);
  bool get canApproveDisbursement => isActive && role == 'owner';
}

class AmialFundTransaction {
  final int id;
  final String txUlid;
  final int fundId;
  final int userId;
  final String txType;
  final String amount;
  final String balanceBefore;
  final String balanceAfter;
  final int? beneficiaryUserId;
  final String? note;
  final String status;
  final DateTime? createdAt;
  // AMIAL-FUND-UI: أسماء المنفّذ والمستفيد (يرجعها الخادم في show)
  final String? actorName;
  final String? beneficiaryName;

  AmialFundTransaction({
    required this.id,
    required this.txUlid,
    required this.fundId,
    required this.userId,
    required this.txType,
    required this.amount,
    required this.balanceBefore,
    required this.balanceAfter,
    this.beneficiaryUserId,
    this.note,
    required this.status,
    this.createdAt,
    this.actorName,
    this.beneficiaryName,
  });

  factory AmialFundTransaction.fromJson(Map<String, dynamic> j) => AmialFundTransaction(
    id: j['id'] ?? 0,
    txUlid: j['tx_ulid'] ?? '',
    fundId: j['fund_id'] ?? 0,
    userId: j['user_id'] ?? 0,
    txType: j['tx_type'] ?? '',
    amount: j['amount']?.toString() ?? '0',
    balanceBefore: j['balance_before']?.toString() ?? '0',
    balanceAfter: j['balance_after']?.toString() ?? '0',
    beneficiaryUserId: j['beneficiary_user_id'],
    note: j['note'],
    status: j['status'] ?? 'completed',
    createdAt: j['created_at'] != null ? DateTime.tryParse(j['created_at']) : null,
    actorName: j['user'] is Map
        ? ('${j['user']['f_name'] ?? ''} ${j['user']['l_name'] ?? ''}').trim()
        : null,
    beneficiaryName: j['beneficiary'] is Map
        ? ('${j['beneficiary']['f_name'] ?? ''} ${j['beneficiary']['l_name'] ?? ''}').trim()
        : null,
  );

  bool get isPending => status == 'pending_approval';
  bool get isCompleted => status == 'completed';
  bool get isRejected => status == 'rejected';
}
