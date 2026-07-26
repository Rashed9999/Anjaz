/// AMIAL-SAFE-PAYMENT-001 (v1.1) — Flutter models
library;

class AmyalSafePayment {
  final int id;
  final String paymentUlid;
  final int buyerUserId;
  final int sellerUserId;
  final String title;
  final String description;
  final String? deliveryTerms;
  final String amount;
  final String platformFee;
  final String heldAmount;
  final String refundedToBuyerAmount;
  final String releasedToSellerAmount;
  final String status;
  final bool isDisputed;
  final DateTime? sellerResponseDeadline;
  final DateTime? sellerAcceptedAt;
  final DateTime? deliveredAt;
  final DateTime? buyerConfirmedAt;
  final DateTime? releasedAt;
  final DateTime? createdAt;
  final AmyalUserMini? buyer;
  final AmyalUserMini? seller;
  final List<AmyalSafePaymentEvent>? events;

  AmyalSafePayment({
    required this.id,
    required this.paymentUlid,
    required this.buyerUserId,
    required this.sellerUserId,
    required this.title,
    required this.description,
    this.deliveryTerms,
    required this.amount,
    required this.platformFee,
    required this.heldAmount,
    required this.refundedToBuyerAmount,
    required this.releasedToSellerAmount,
    required this.status,
    required this.isDisputed,
    this.sellerResponseDeadline,
    this.sellerAcceptedAt,
    this.deliveredAt,
    this.buyerConfirmedAt,
    this.releasedAt,
    this.createdAt,
    this.buyer,
    this.seller,
    this.events,
  });

  factory AmyalSafePayment.fromJson(Map<String, dynamic> j) => AmyalSafePayment(
    id: j['id'] ?? 0,
    paymentUlid: j['payment_ulid'] ?? '',
    buyerUserId: j['buyer_user_id'] ?? 0,
    sellerUserId: j['seller_user_id'] ?? 0,
    title: j['title'] ?? '',
    description: j['description'] ?? '',
    deliveryTerms: j['delivery_terms'],
    amount: j['amount']?.toString() ?? '0',
    platformFee: j['platform_fee']?.toString() ?? '0',
    heldAmount: j['held_amount']?.toString() ?? '0',
    refundedToBuyerAmount: j['refunded_to_buyer_amount']?.toString() ?? '0',
    releasedToSellerAmount: j['released_to_seller_amount']?.toString() ?? '0',
    status: j['status'] ?? 'pending_seller_acceptance',
    isDisputed: j['is_disputed'] == true || j['is_disputed'] == 1,
    sellerResponseDeadline: _date(j['seller_response_deadline']),
    sellerAcceptedAt: _date(j['seller_accepted_at']),
    deliveredAt: _date(j['delivered_at']),
    buyerConfirmedAt: _date(j['buyer_confirmed_at']),
    releasedAt: _date(j['released_at']),
    createdAt: _date(j['created_at']),
    buyer: j['buyer'] is Map ? AmyalUserMini.fromJson(Map<String, dynamic>.from(j['buyer'])) : null,
    seller: j['seller'] is Map ? AmyalUserMini.fromJson(Map<String, dynamic>.from(j['seller'])) : null,
    events: (j['events'] as List?)
        ?.map((e) => AmyalSafePaymentEvent.fromJson(Map<String, dynamic>.from(e)))
        .toList(),
  );

  static DateTime? _date(dynamic v) => v != null ? DateTime.tryParse(v.toString()) : null;

  bool get isTerminal => [
    'released_to_seller', 'seller_rejected', 'refunded_to_buyer',
    'partially_refunded', 'cancelled', 'expired',
  ].contains(status);

  bool get isSuccess => status == 'released_to_seller';
  bool get isRefunded => ['refunded_to_buyer', 'cancelled', 'seller_rejected', 'expired'].contains(status);

  String get arabicStatusLabel {
    switch (status) {
      case 'pending_seller_acceptance': return 'بانتظار قبول البائع';
      case 'seller_rejected': return 'رفض البائع — تم الاسترداد';
      case 'funded': return 'مال محجوز — يستعد البائع';
      case 'in_delivery': return 'في طريق التسليم';
      case 'delivered': return 'تم التسليم — أكد الاستلام';
      case 'buyer_confirmed': return 'تم التأكيد';
      case 'released_to_seller': return 'تم الإفراج للبائع ✓';
      case 'disputed': return 'في نزاع — تراجع الإدارة';
      case 'refunded_to_buyer': return 'تم الاسترداد للمشتري';
      case 'partially_refunded': return 'استرداد جزئي';
      case 'cancelled': return 'تم الإلغاء';
      case 'expired': return 'انتهت الصلاحية — تم الاسترداد';
      default: return status;
    }
  }
}

class AmyalUserMini {
  final int id;
  final String? firstName;
  final String? lastName;
  final String? phone;

  AmyalUserMini({required this.id, this.firstName, this.lastName, this.phone});

  factory AmyalUserMini.fromJson(Map<String, dynamic> j) => AmyalUserMini(
    id: j['id'] ?? 0,
    firstName: j['f_name'],
    lastName: j['l_name'],
    phone: j['phone'],
  );

  String get displayName {
    final parts = [firstName, lastName].where((p) => p != null && p.isNotEmpty).toList();
    return parts.isNotEmpty ? parts.join(' ') : (phone ?? 'مستخدم');
  }
}

class AmyalSafePaymentEvent {
  final int id;
  final String eventType;
  final String? fromStatus;
  final String? toStatus;
  final String actorType;
  final int? actorUserId;
  final String? note;
  final DateTime? createdAt;

  AmyalSafePaymentEvent({
    required this.id,
    required this.eventType,
    this.fromStatus,
    this.toStatus,
    required this.actorType,
    this.actorUserId,
    this.note,
    this.createdAt,
  });

  factory AmyalSafePaymentEvent.fromJson(Map<String, dynamic> j) => AmyalSafePaymentEvent(
    id: j['id'] ?? 0,
    eventType: j['event_type'] ?? '',
    fromStatus: j['from_status'],
    toStatus: j['to_status'],
    actorType: j['actor_type'] ?? 'system',
    actorUserId: j['actor_user_id'],
    note: j['note'],
    createdAt: j['created_at'] != null ? DateTime.tryParse(j['created_at']) : null,
  );

  String get arabicEventLabel {
    switch (eventType) {
      case 'created': return 'إنشاء الطلب';
      case 'seller_accepted': return 'قبول البائع';
      case 'seller_rejected': return 'رفض البائع';
      case 'in_delivery_marked': return 'بدء التسليم';
      case 'delivered_marked': return 'تأكيد التسليم';
      case 'buyer_confirmed': return 'تأكيد المشتري';
      case 'released_to_seller': return 'إفراج المبلغ';
      case 'buyer_disputed': return 'فتح نزاع';
      case 'buyer_cancelled': return 'إلغاء من المشتري';
      case 'admin_resolved_release': return 'قرار: إفراج للبائع';
      case 'admin_resolved_refund': return 'قرار: استرداد للمشتري';
      case 'admin_resolved_partial': return 'قرار: استرداد جزئي';
      case 'expired': return 'انتهت الصلاحية';
      default: return eventType;
    }
  }
}

/// AMIAL-SAFEPAY-EVIDENCE-001 — دليل واحد مرفوع.
///
/// لا يحمل الملفّ نفسه بل وصفه: الملفّ يُجلب عند الطلب برابط محميّ، لأن
/// تحميل خمس صور لكل مرحلة مع كل فتح للشاشة يستهلك باقة المستخدم بلا داعٍ.
class AmyalEvidenceItem {
  final int id;
  final String role; // buyer | seller | admin
  final String stage;
  final String mime;
  final int sizeBytes;
  final String? originalName;
  final String fingerprint;
  final DateTime? uploadedAt;

  AmyalEvidenceItem({
    required this.id,
    required this.role,
    required this.stage,
    required this.mime,
    required this.sizeBytes,
    this.originalName,
    required this.fingerprint,
    this.uploadedAt,
  });

  factory AmyalEvidenceItem.fromJson(Map<String, dynamic> j) => AmyalEvidenceItem(
        id: j['id'] ?? 0,
        role: j['role']?.toString() ?? '',
        stage: j['stage']?.toString() ?? '',
        mime: j['mime']?.toString() ?? 'image/jpeg',
        sizeBytes: int.tryParse('${j['size_bytes'] ?? 0}') ?? 0,
        originalName: j['original_name']?.toString(),
        fingerprint: j['fingerprint']?.toString() ?? '',
        uploadedAt: j['uploaded_at'] != null
            ? DateTime.tryParse(j['uploaded_at'].toString())
            : null,
      );

  bool get isPdf => mime == 'application/pdf';

  String get roleLabel => switch (role) {
        'buyer' => 'المشتري',
        'seller' => 'البائع',
        'admin' => 'الإدارة',
        _ => role,
      };

  static String stageLabel(String stage) => switch (stage) {
        'created' => 'عند الإنشاء',
        'in_delivery' => 'عند الشحن',
        'delivered' => 'عند التسليم',
        'dispute' => 'مع النزاع',
        'admin_review' => 'مراجعة الإدارة',
        _ => stage,
      };
}

/// AMIAL-SAFEPAY-TRUST-001 — سجلّ الطرف المقابل.
class AmyalTrustSummary {
  final String role;
  final int completedDeals;
  final int disputedDeals;
  final int totalDeals;
  final double disputeRate;
  final String? memberSince;
  final String badge;

  AmyalTrustSummary({
    required this.role,
    required this.completedDeals,
    required this.disputedDeals,
    required this.totalDeals,
    required this.disputeRate,
    this.memberSince,
    required this.badge,
  });

  factory AmyalTrustSummary.fromJson(Map<String, dynamic> j) => AmyalTrustSummary(
        role: j['role']?.toString() ?? 'seller',
        completedDeals: int.tryParse('${j['completed_deals'] ?? 0}') ?? 0,
        disputedDeals: int.tryParse('${j['disputed_deals'] ?? 0}') ?? 0,
        totalDeals: int.tryParse('${j['total_deals'] ?? 0}') ?? 0,
        disputeRate: double.tryParse('${j['dispute_rate'] ?? 0}') ?? 0,
        memberSince: j['member_since']?.toString(),
        badge: j['badge']?.toString() ?? 'جديد',
      );

  bool get isNew => totalDeals == 0;
  bool get isRisky => badge == 'نزاعات متكرّرة';
  bool get isTrusted => badge == 'موثوق';
}

/// سبب نزاع منظّم — يأتي من الخادم لا مطبوعاً في التطبيق.
class AmyalDisputeReason {
  final String code;
  final String label;

  AmyalDisputeReason({required this.code, required this.label});

  factory AmyalDisputeReason.fromJson(Map<String, dynamic> j) => AmyalDisputeReason(
        code: j['code']?.toString() ?? 'other',
        label: j['label']?.toString() ?? '',
      );
}

class AmyalSafePaymentActions {
  final bool sellerAccept;
  final bool sellerReject;
  final bool sellerMarkInDelivery;
  final bool sellerMarkDelivered;
  final bool buyerConfirm;
  final bool buyerCancel;
  final bool buyerDispute;

  AmyalSafePaymentActions({
    required this.sellerAccept,
    required this.sellerReject,
    required this.sellerMarkInDelivery,
    required this.sellerMarkDelivered,
    required this.buyerConfirm,
    required this.buyerCancel,
    required this.buyerDispute,
  });

  factory AmyalSafePaymentActions.fromJson(Map<String, dynamic> j) => AmyalSafePaymentActions(
    sellerAccept: j['seller_accept'] == true,
    sellerReject: j['seller_reject'] == true,
    sellerMarkInDelivery: j['seller_mark_in_delivery'] == true,
    sellerMarkDelivered: j['seller_mark_delivered'] == true,
    buyerConfirm: j['buyer_confirm'] == true,
    buyerCancel: j['buyer_cancel'] == true,
    buyerDispute: j['buyer_dispute'] == true,
  );

  factory AmyalSafePaymentActions.empty() => AmyalSafePaymentActions(
    sellerAccept: false, sellerReject: false, sellerMarkInDelivery: false,
    sellerMarkDelivered: false, buyerConfirm: false, buyerCancel: false, buyerDispute: false,
  );
}
