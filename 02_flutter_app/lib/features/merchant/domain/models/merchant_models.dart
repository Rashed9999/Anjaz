/// AMIAL-MERCHANT-APP-001 (v1.6) — Merchant models
library;

class AmialMerchant {
  final int id;
  final int userId;
  final String? merchantNumber;
  final String? storeName;
  final String? logoUrl;
  final String? address;
  final String? phone;
  final String? phoneMasked;
  final String balance;
  final bool verified;
  final String? verificationStatus;

  AmialMerchant({
    required this.id,
    required this.userId,
    this.merchantNumber,
    this.storeName,
    this.logoUrl,
    this.address,
    this.phone,
    this.phoneMasked,
    required this.balance,
    required this.verified,
    this.verificationStatus,
  });

  factory AmialMerchant.fromJson(Map<String, dynamic> j) {
    final merchant = j['merchant'] is Map ? Map<String, dynamic>.from(j['merchant']) : <String, dynamic>{};
    return AmialMerchant(
      id: merchant['id'] ?? 0,
      userId: j['id'] ?? merchant['user_id'] ?? 0,
      merchantNumber: (merchant['merchant_number'] ?? j['merchant_number'])?.toString(),
      storeName: (merchant['store_name'] ?? j['store_name'])?.toString(),
      logoUrl: (merchant['logo'] ?? j['logo'])?.toString(),
      address: (merchant['address'] ?? j['address'])?.toString(),
      phone: j['phone']?.toString(),
      phoneMasked: j['phone_masked']?.toString(),
      balance: (j['current_balance'] ?? '0').toString(),
      verified: (j['merchant_verified'] ?? merchant['verified'] ?? 0) == 1,
      verificationStatus: (j['verification_status'] ?? merchant['verification_status'])?.toString(),
    );
  }

  String get displayName => storeName?.isNotEmpty == true ? storeName! : (merchantNumber ?? 'تاجر');
}

class AmialMerchantTransaction {
  final int id;
  final String transactionId;
  final String type; // received_payment, refund, qr_payment, pos_payment
  final String amount;
  final String? fee;
  final String? customerName;
  final String? customerPhoneMasked;
  final String? posUserName;
  final String? posNumber;
  final String status;
  final DateTime createdAt;

  AmialMerchantTransaction({
    required this.id,
    required this.transactionId,
    required this.type,
    required this.amount,
    this.fee,
    this.customerName,
    this.customerPhoneMasked,
    this.posUserName,
    this.posNumber,
    required this.status,
    required this.createdAt,
  });

  factory AmialMerchantTransaction.fromJson(Map<String, dynamic> j) {
    return AmialMerchantTransaction(
      id: j['id'] ?? 0,
      transactionId: (j['transaction_id'] ?? j['ulid'] ?? '').toString(),
      type: (j['type'] ?? j['transaction_type'] ?? 'unknown').toString(),
      amount: (j['amount'] ?? '0').toString(),
      fee: j['fee']?.toString(),
      customerName: j['customer_name']?.toString(),
      customerPhoneMasked: (j['customer_phone_masked'] ?? j['sender_phone'])?.toString(),
      posUserName: j['pos_user_name']?.toString(),
      posNumber: j['pos_number']?.toString(),
      status: (j['status'] ?? 'unknown').toString(),
      createdAt: j['created_at'] != null
          ? (DateTime.tryParse(j['created_at'].toString()) ?? DateTime.now())
          : DateTime.now(),
    );
  }

  String get typeLabel {
    switch (type) {
      case 'pay_merchant':
      case 'pos_payment':
      case 'received_payment':
        return 'استلام دفعة';
      case 'qr_payment':
        return 'دفعة QR';
      case 'refund_merchant':
      case 'refund':
        return 'استرجاع';
      case 'safe_payment_released':
        return 'إفراج دفع آمن';
      default:
        return type;
    }
  }

  bool get isIncoming => !type.contains('refund');
}

class AmialMerchantDashboardStats {
  final String todaySales;
  final String todayRefunds;
  final String todayNet;
  final int todayTransactionsCount;

  /// ══════════════════════════════════════════════════════════════════
  /// AMIAL-POS-SCOPE-001 — **`null` يعني «لا يُعرَض لك»، لا «صفر».**
  ///
  /// كان `String balance` بافتراضٍ `'0'`. والخادمُ صار يحذف الحقلَ عن
  /// موظّف نقطة البيع — **فكان الافتراضُ يكتب «الرصيد المتاح: 0 ر.ي»
  /// على متجرٍ فيه مئتا ألف**. وكذبةٌ بصفرٍ أسوأ من امتناع: الكاشيرُ
  /// يقرؤها متجراً خاوياً، والمالكُ إن رآها ظنّ مالَه ذهب.
  ///
  /// (القاعدة السابعة: «غير معروف» ليس صفراً — يُقال الغيابُ صراحةً.)
  /// ══════════════════════════════════════════════════════════════════
  final String? balance;

  /// `owner_only` حين يحجب الخادمُ الرصيد — يُقرأ ليُقال السببُ للمستعمل.
  final String? balanceScope;

  final String pendingSettlement;

  AmialMerchantDashboardStats({
    required this.todaySales,
    required this.todayRefunds,
    required this.todayNet,
    required this.todayTransactionsCount,
    required this.balance,
    required this.pendingSettlement,
    this.balanceScope,
  });

  /// هل يُعرَض الرصيدُ لهذا الحساب؟
  bool get hasBalance => balance != null;

  factory AmialMerchantDashboardStats.empty() => AmialMerchantDashboardStats(
        todaySales: '0', todayRefunds: '0', todayNet: '0',
        todayTransactionsCount: 0, balance: null, pendingSettlement: '0',
      );

  factory AmialMerchantDashboardStats.fromJson(Map<String, dynamic> j) {
    final raw = j['current_balance'] ?? j['balance'];

    return AmialMerchantDashboardStats(
      todaySales: (j['today_sales'] ?? '0').toString(),
      todayRefunds: (j['today_refunds'] ?? '0').toString(),
      todayNet: (j['today_net'] ?? j['today_sales'] ?? '0').toString(),
      todayTransactionsCount: j['today_count'] ?? 0,
      balance: raw?.toString(),
      balanceScope: j['balance_scope']?.toString(),
      pendingSettlement: (j['pending_settlement'] ?? '0').toString(),
    );
  }
}
