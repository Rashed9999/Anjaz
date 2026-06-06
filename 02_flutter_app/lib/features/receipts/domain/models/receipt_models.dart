/// AMIAL-RECEIPTS-001 (v0.9-D Flutter)
class AmyalReceipt {
  final int id;
  final String receiptNumber;
  final String verificationCode;
  final String receiptType;
  final int userId;
  final int? counterpartyUserId;
  final String referenceTransactionId;
  final String? referenceType;
  final int? referenceId;
  final String amount;
  final String fee;
  final String netAmount;
  final String direction; // debit | credit
  final String status;    // pending_pdf | pdf_generated | pdf_failed | voided
  final String? pdfStoragePath;
  final Map<String, dynamic>? metadata;
  final String zoneCode;
  final DateTime? issuedAt;
  final DateTime? pdfGeneratedAt;

  AmyalReceipt({
    required this.id,
    required this.receiptNumber,
    required this.verificationCode,
    required this.receiptType,
    required this.userId,
    this.counterpartyUserId,
    required this.referenceTransactionId,
    this.referenceType,
    this.referenceId,
    required this.amount,
    required this.fee,
    required this.netAmount,
    required this.direction,
    required this.status,
    this.pdfStoragePath,
    this.metadata,
    required this.zoneCode,
    this.issuedAt,
    this.pdfGeneratedAt,
  });

  factory AmyalReceipt.fromJson(Map<String, dynamic> json) {
    return AmyalReceipt(
      id: json['id'] ?? 0,
      receiptNumber: json['receipt_number'] ?? '',
      verificationCode: json['verification_code'] ?? '',
      receiptType: json['receipt_type'] ?? '',
      userId: json['user_id'] ?? 0,
      counterpartyUserId: json['counterparty_user_id'],
      referenceTransactionId: json['reference_transaction_id'] ?? '',
      referenceType: json['reference_type'],
      referenceId: json['reference_id'],
      amount: json['amount']?.toString() ?? '0',
      fee: json['fee']?.toString() ?? '0',
      netAmount: json['net_amount']?.toString() ?? '0',
      direction: json['direction'] ?? 'debit',
      status: json['status'] ?? 'pending_pdf',
      pdfStoragePath: json['pdf_storage_path'],
      metadata: json['metadata'] is Map ? Map<String, dynamic>.from(json['metadata']) : null,
      zoneCode: json['zone_code'] ?? 'SOUTH',
      issuedAt: json['issued_at'] != null ? DateTime.tryParse(json['issued_at']) : null,
      pdfGeneratedAt: json['pdf_generated_at'] != null ? DateTime.tryParse(json['pdf_generated_at']) : null,
    );
  }

  bool get isReady => status == 'pdf_generated';
  bool get isPending => status == 'pending_pdf';
  bool get isFailed => status == 'pdf_failed';

  String get arabicTypeLabel {
    switch (receiptType) {
      case 'send_money': return 'تحويل أموال';
      case 'cash_in': return 'إيداع نقدي';
      case 'cash_out': return 'سحب نقدي';
      case 'add_money': return 'إضافة رصيد';
      case 'withdraw': return 'طلب سحب';
      case 'pay_merchant': return 'دفع لتاجر';
      case 'pos_payment': return 'دفع نقطة بيع';
      case 'qr_payment': return 'دفع QR';
      case 'refund': return 'استرجاع';
      case 'safe_payment_funded': return 'تجميد دفع آمن';
      case 'safe_payment_released': return 'إفراج دفع آمن';
      case 'safe_payment_refunded': return 'إعادة دفع آمن';
      case 'split_bill_payment': return 'تقسيم فاتورة';
      case 'family_fund_contribute': return 'مساهمة عائلية';
      case 'family_fund_disburse': return 'صرف عائلي';
      case 'bank_settlement': return 'تسوية بنكية';
      case 'fee_charge': return 'رسوم خدمة';
      default: return receiptType;
    }
  }
}
