/// AMIAL-DONATIONS-001 (v1.2) — Flutter models
library;

class AmyalCharityCategory {
  final int id;
  final String code;
  final String nameAr;
  final String? icon;
  final int sortOrder;

  AmyalCharityCategory({
    required this.id,
    required this.code,
    required this.nameAr,
    this.icon,
    required this.sortOrder,
  });

  factory AmyalCharityCategory.fromJson(Map<String, dynamic> j) => AmyalCharityCategory(
    id: j['id'] ?? 0,
    code: j['code'] ?? '',
    nameAr: j['name_ar'] ?? '',
    icon: j['icon'],
    sortOrder: j['sort_order'] ?? 0,
  );
}

class AmyalCharityOrganization {
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

  AmyalCharityOrganization({
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

  factory AmyalCharityOrganization.fromJson(Map<String, dynamic> j) =>
      AmyalCharityOrganization(
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

class AmyalCharityCampaign {
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
  final AmyalCharityOrganization? organization;
  final AmyalCharityCategory? category;
  final List<AmyalRecentDonation>? recentDonations;

  AmyalCharityCampaign({
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
    this.organization,
    this.category,
    this.recentDonations,
  });

  factory AmyalCharityCampaign.fromJson(Map<String, dynamic> j) {
    final imgs = j['gallery_images'];
    return AmyalCharityCampaign(
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
      organization: j['organization'] is Map
          ? AmyalCharityOrganization.fromJson(Map<String, dynamic>.from(j['organization']))
          : null,
      category: j['category'] is Map
          ? AmyalCharityCategory.fromJson(Map<String, dynamic>.from(j['category']))
          : null,
      recentDonations: (j['recent_donations'] as List?)
          ?.map((d) => AmyalRecentDonation.fromJson(Map<String, dynamic>.from(d)))
          .toList(),
    );
  }

  bool get isActive => status == 'active';

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

class AmyalRecentDonation {
  final String amount;
  final DateTime? donatedAt;
  final String donorName;
  final String? message;

  AmyalRecentDonation({
    required this.amount,
    this.donatedAt,
    required this.donorName,
    this.message,
  });

  factory AmyalRecentDonation.fromJson(Map<String, dynamic> j) => AmyalRecentDonation(
    amount: j['amount']?.toString() ?? '0',
    donatedAt: j['donated_at'] != null ? DateTime.tryParse(j['donated_at']) : null,
    donorName: j['donor_name'] ?? 'متبرع',
    message: j['message'],
  );
}

class AmyalDonation {
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
  final AmyalCharityCampaign? campaign;
  final AmyalCharityOrganization? organization;

  AmyalDonation({
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

  factory AmyalDonation.fromJson(Map<String, dynamic> j) => AmyalDonation(
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
        ? AmyalCharityCampaign.fromJson(Map<String, dynamic>.from(j['campaign']))
        : null,
    organization: j['organization'] is Map
        ? AmyalCharityOrganization.fromJson(Map<String, dynamic>.from(j['organization']))
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
