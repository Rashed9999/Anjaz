/// AMIAL-RECEIPTS-001 (v0.9-D Flutter)
class AmialReceipt {
  final int id;
  final String receiptNumber;
  /// AMIAL-TXN-NO-001: رقم العملية الرسمي (15 خانة) من الخادم — لا يُلفَّق محلياً.
  final String? transactionNo;
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
  final String status;    // pending_pdf | pdf_generated | pdf_failed | voided (حالة الـPDF)
  final String opStatus;  // completed | cancelled | under_review | pending (حالة العملية)
  final String? pdfStoragePath;
  final Map<String, dynamic>? metadata;
  final String zoneCode;
  final DateTime? issuedAt;
  final DateTime? pdfGeneratedAt;
  final ReceiptDocumentDescriptor? document;

  AmialReceipt({
    required this.id,
    required this.receiptNumber,
    this.transactionNo,
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
    this.opStatus = 'completed',
    this.pdfStoragePath,
    this.metadata,
    required this.zoneCode,
    this.issuedAt,
    this.pdfGeneratedAt,
    this.document,
  });

  factory AmialReceipt.fromJson(Map<String, dynamic> json) {
    return AmialReceipt(
      id: json['id'] ?? 0,
      receiptNumber: json['receipt_number'] ?? '',
      transactionNo: json['transaction_no']?.toString(),
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
      opStatus: json['op_status']?.toString() ?? 'completed',
      pdfStoragePath: json['pdf_storage_path'],
      metadata: json['metadata'] is Map ? Map<String, dynamic>.from(json['metadata']) : null,
      zoneCode: json['zone_code'] ?? 'SOUTH',
      issuedAt: json['issued_at'] != null ? DateTime.tryParse(json['issued_at']) : null,
      pdfGeneratedAt: json['pdf_generated_at'] != null ? DateTime.tryParse(json['pdf_generated_at']) : null,
      document: json['document'] is Map
          ? ReceiptDocumentDescriptor.fromJson(Map<String, dynamic>.from(json['document']))
          : null,
    );
  }

  bool get isReady => status == 'pdf_generated';
  bool get isPending => status == 'pending_pdf';
  bool get isFailed => status == 'pdf_failed';
  bool get isVoided => status == 'voided';

  /// AMIAL-FIX(RECEIPT-STATUS): حالة «العملية» المعروضة للمستخدم.
  /// status في القاعدة يخصّ توليد ملف PDF (pending_pdf/pdf_generated/pdf_failed)
  /// لا العملية نفسها — وبعد تحويل التوليد إلى «عند الطلب» بقيت الإيصالات
  /// pending_pdf فظهر «جارٍ التحضير» على كل العمليات (و«فشل» لعمليات ناجحة!).
  /// الإيصال لا يصدر إلا لعملية منفَّذة فعلاً، فالحالة الصادقة «مكتملة»،
  /// بصياغة تناسب نوع كل عملية، و«ملغى» إن أُبطل الإيصال.
  String get operationStatusLabel {
    if (isVoided) return 'ملغى';
    switch (receiptType) {
      case 'send_money': return 'تم التحويل';
      case 'cash_in': return 'تم الإيداع';
      case 'cash_out':
      case 'withdraw': return 'تم السحب';
      case 'add_money': return 'تمت الإضافة';
      case 'pay_merchant':
      case 'pos_payment':
      case 'qr_payment':
      case 'split_bill_payment': return 'تم الدفع';
      case 'refund': return 'تم الاسترجاع';
      case 'safe_payment_funded': return 'مجمّد بأمان';
      case 'safe_payment_released': return 'تم الإفراج';
      case 'safe_payment_refunded': return 'أُعيد المبلغ';
      case 'family_fund_contribute': return 'تمت المساهمة';
      case 'family_fund_disburse': return 'تم الصرف';
      case 'bank_settlement': return 'تمت التسوية';
      default: return 'مكتملة';
    }
  }

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

/// وصف المستند الذي يقرّره الخادم من حقيقة العملية، لا من تخمين التطبيق.
class ReceiptDocumentDescriptor {
  final String kind; // wallet_voucher | merchant_invoice
  final String title;
  final String? vertical;
  final String? verticalLabel;
  final String documentNumber;
  final bool hasLineItems;
  final Map<String, dynamic> thermalPrint;
  final List<ReceiptDocumentFormat> formats;

  const ReceiptDocumentDescriptor({
    required this.kind,
    required this.title,
    this.vertical,
    this.verticalLabel,
    required this.documentNumber,
    required this.hasLineItems,
    required this.thermalPrint,
    required this.formats,
  });

  factory ReceiptDocumentDescriptor.fromJson(Map<String, dynamic> json) {
    final rawFormats = json['formats'] is List ? json['formats'] as List : const [];
    return ReceiptDocumentDescriptor(
      kind: json['kind']?.toString() ?? 'wallet_voucher',
      title: json['title']?.toString() ?? 'سند عملية',
      vertical: json['vertical']?.toString(),
      verticalLabel: json['vertical_label']?.toString(),
      documentNumber: json['document_number']?.toString() ?? '',
      hasLineItems: json['has_line_items'] == true,
      thermalPrint: json['thermal_print'] is Map
          ? Map<String, dynamic>.from(json['thermal_print'])
          : const <String, dynamic>{},
      formats: rawFormats
          .whereType<Map>()
          .map((e) => ReceiptDocumentFormat.fromJson(Map<String, dynamic>.from(e)))
          .toList(growable: false),
    );
  }

  bool get isMerchantInvoice => kind == 'merchant_invoice';
}

class ReceiptDocumentFormat {
  final String code;
  final String label;
  final String route;

  const ReceiptDocumentFormat({required this.code, required this.label, required this.route});

  factory ReceiptDocumentFormat.fromJson(Map<String, dynamic> json) => ReceiptDocumentFormat(
        code: json['code']?.toString() ?? '',
        label: json['label']?.toString() ?? '',
        route: json['route']?.toString() ?? '',
      );
}
