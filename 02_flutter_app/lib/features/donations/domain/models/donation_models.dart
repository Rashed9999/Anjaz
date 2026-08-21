/// AMIAL-DONATIONS-001 (v1.2) — Flutter models
library;

class AmialCharityCategory {
  final int id;
  final String code;
  final String nameAr;
  final String? icon;
  final int sortOrder;

  AmialCharityCategory({
    required this.id,
    required this.code,
    required this.nameAr,
    this.icon,
    required this.sortOrder,
  });

  factory AmialCharityCategory.fromJson(Map<String, dynamic> j) => AmialCharityCategory(
    id: j['id'] ?? 0,
    code: j['code'] ?? '',
    nameAr: j['name_ar'] ?? '',
    icon: j['icon'],
    sortOrder: j['sort_order'] ?? 0,
  );
}

class AmialCharityOrganization {
  final int id;
  final String orgUlid;
  final String nameAr;
  final String? descriptionAr;
  final String? logoUrl;
  final String? coverImageUrl;
  final String totalCollected;
  final int totalCampaigns;
  final int totalDonors;
  final String verificationStatus;

  AmialCharityOrganization({
    required this.id,
    required this.orgUlid,
    required this.nameAr,
    this.descriptionAr,
    this.logoUrl,
    this.coverImageUrl,
    required this.totalCollected,
    required this.totalCampaigns,
    required this.totalDonors,
    required this.verificationStatus,
  });

  factory AmialCharityOrganization.fromJson(Map<String, dynamic> j) =>
      AmialCharityOrganization(
        id: j['id'] ?? 0,
        orgUlid: j['org_ulid'] ?? '',
        nameAr: j['name_ar'] ?? '',
        descriptionAr: j['description_ar'],
        logoUrl: j['logo_url'],
        coverImageUrl: j['cover_image_url'],
        totalCollected: j['total_collected']?.toString() ?? '0',
        totalCampaigns: j['total_campaigns'] ?? 0,
        totalDonors: j['total_donors'] ?? 0,
        verificationStatus: j['verification_status'] ?? 'pending_verification',
      );

  bool get isVerified => verificationStatus == 'verified';
}

class AmialCharityCampaign {
  final int id;
  final String campaignUlid;
  final int orgId;
  final int categoryId;
  final String titleAr;
  final String descriptionAr;
  final String? storyMd;
  final String? locationAr;
  final String targetAmount;
  final String currentAmount;
  final double progressPercentage;
  final int? beneficiaryCount;
  final String? beneficiaryDescriptionAr;
  final String? coverImageUrl;
  final List<String>? galleryImages;
  final DateTime? deadlineAt;
  final String status;
  final int donorCount;
  final bool isFeatured;

  /// AMIAL-CHARITY-META-001 — «عاجل».
  ///
  /// وهي حكمٌ إداريّ لا تصنيف: حملةُ إغاثةٍ قد لا تكون عاجلة، وحملةُ
  /// علاجٍ قد تكون. فتُقرأ من الخادم ولا تُستنتج من `category`.
  final bool isUrgent;

  final DateTime? startAt;
  final AmialCharityOrganization? organization;
  final AmialCharityCategory? category;
  final List<AmialRecentDonation>? recentDonations;

  AmialCharityCampaign({
    required this.id,
    required this.campaignUlid,
    required this.orgId,
    required this.categoryId,
    required this.titleAr,
    required this.descriptionAr,
    this.storyMd,
    this.locationAr,
    required this.targetAmount,
    required this.currentAmount,
    required this.progressPercentage,
    this.beneficiaryCount,
    this.beneficiaryDescriptionAr,
    this.coverImageUrl,
    this.galleryImages,
    this.deadlineAt,
    required this.status,
    required this.donorCount,
    required this.isFeatured,
    this.isUrgent = false,
    this.startAt,
    this.organization,
    this.category,
    this.recentDonations,
  });

  factory AmialCharityCampaign.fromJson(Map<String, dynamic> j) {
    final imgs = j['gallery_images'];
    return AmialCharityCampaign(
      id: j['id'] ?? 0,
      campaignUlid: j['campaign_ulid'] ?? '',
      orgId: j['org_id'] ?? 0,
      categoryId: j['category_id'] ?? 0,
      titleAr: j['title_ar'] ?? '',
      descriptionAr: j['description_ar'] ?? '',
      storyMd: j['story_md'],
      locationAr: j['location_ar'],
      targetAmount: j['target_amount']?.toString() ?? '0',
      currentAmount: j['current_amount']?.toString() ?? '0',
      progressPercentage: (j['progress_percentage'] ?? 0).toDouble(),
      beneficiaryCount: j['beneficiary_count'],
      beneficiaryDescriptionAr: j['beneficiary_description_ar'],
      coverImageUrl: j['cover_image_url'],
      galleryImages: imgs is List ? List<String>.from(imgs) : null,
      deadlineAt: j['deadline_at'] != null ? DateTime.tryParse(j['deadline_at']) : null,
      status: j['status'] ?? '',
      donorCount: j['donor_count'] ?? 0,
      isFeatured: j['is_featured'] == true || j['is_featured'] == 1,
      // الخادمُ يردّ 1/0 من MySQL و true/false من الـcast — فيُقبل الوجهان.
      isUrgent: j['is_urgent'] == true || j['is_urgent'] == 1,
      startAt: j['start_at'] != null ? DateTime.tryParse(j['start_at']) : null,
      organization: j['organization'] is Map
          ? AmialCharityOrganization.fromJson(Map<String, dynamic>.from(j['organization']))
          : null,
      category: j['category'] is Map
          ? AmialCharityCategory.fromJson(Map<String, dynamic>.from(j['category']))
          : null,
      recentDonations: (j['recent_donations'] as List?)
          ?.map((d) => AmialRecentDonation.fromJson(Map<String, dynamic>.from(d)))
          .toList(),
    );
  }

  bool get isActive => status == 'active';

  bool get isAcceptingNow {
    final now = DateTime.now();
    return isActive &&
        (startAt == null || !startAt!.isAfter(now)) &&
        (deadlineAt == null || deadlineAt!.isAfter(now));
  }

  int? get daysRemaining {
    if (deadlineAt == null) return null;
    final diff = deadlineAt!.difference(DateTime.now()).inDays;
    return diff > 0 ? diff : 0;
  }

  String get remainingAmount {
    final target = double.tryParse(targetAmount) ?? 0;
    final current = double.tryParse(currentAmount) ?? 0;
    final remain = target - current;
    return remain > 0 ? remain.toStringAsFixed(2) : '0.00';
  }
}

class AmialRecentDonation {
  final String amount;
  final DateTime? donatedAt;
  final String donorName;
  final String? message;

  AmialRecentDonation({
    required this.amount,
    this.donatedAt,
    required this.donorName,
    this.message,
  });

  factory AmialRecentDonation.fromJson(Map<String, dynamic> j) => AmialRecentDonation(
    amount: j['amount']?.toString() ?? '0',
    donatedAt: j['donated_at'] != null ? DateTime.tryParse(j['donated_at']) : null,
    donorName: j['donor_name'] ?? 'متبرع',
    message: j['message'],
  );
}

class AmialDonation {
  final int id;
  final String donationUlid;
  final int campaignId;
  final int orgId;
  final bool isAnonymous;
  final String amount;
  final String platformFee;
  final String netToCharity;
  final String? donorMessage;
  final String status;
  final DateTime? donatedAt;
  final AmialCharityCampaign? campaign;
  final AmialCharityOrganization? organization;

  AmialDonation({
    required this.id,
    required this.donationUlid,
    required this.campaignId,
    required this.orgId,
    required this.isAnonymous,
    required this.amount,
    required this.platformFee,
    required this.netToCharity,
    this.donorMessage,
    required this.status,
    this.donatedAt,
    this.campaign,
    this.organization,
  });

  factory AmialDonation.fromJson(Map<String, dynamic> j) => AmialDonation(
    id: j['id'] ?? 0,
    donationUlid: j['donation_ulid'] ?? '',
    campaignId: j['campaign_id'] ?? 0,
    orgId: j['org_id'] ?? 0,
    isAnonymous: j['is_anonymous'] == true || j['is_anonymous'] == 1,
    amount: j['amount']?.toString() ?? '0',
    platformFee: j['platform_fee']?.toString() ?? '0',
    netToCharity: j['net_to_charity']?.toString() ?? '0',
    donorMessage: j['donor_message'],
    status: j['status'] ?? 'completed',
    donatedAt: j['donated_at'] != null ? DateTime.tryParse(j['donated_at']) : null,
    campaign: j['campaign'] is Map
        ? AmialCharityCampaign.fromJson(Map<String, dynamic>.from(j['campaign']))
        : null,
    organization: j['organization'] is Map
        ? AmialCharityOrganization.fromJson(Map<String, dynamic>.from(j['organization']))
        : null,
  );

  String get arabicStatusLabel {
    switch (status) {
      case 'completed': return 'مكتمل';
      case 'settled': return 'تم تحويله للمنظمة';
      case 'refunded': return 'مُسترَد';
      default: return status;
    }
  }
}
