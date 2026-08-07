/// AMIAL-LEGAL-001 + AMIAL-ZONE-001 + AMIAL-RECOVERY-001 (v0.7-D)
///
/// Domain models لـ Amial API endpoints.
library;

class AmialSessionPolicy {
  final String accountZone;
  final String? requestZone;
  final String allowedOperationalZone;
  final bool canTransact;
  final bool readOnlyMode;
  final List<String> readActions;
  final List<String> financialActions;
  final String? bannerMessage;
  final String policyVersion;

  AmialSessionPolicy({
    required this.accountZone,
    this.requestZone,
    required this.allowedOperationalZone,
    required this.canTransact,
    required this.readOnlyMode,
    required this.readActions,
    required this.financialActions,
    this.bannerMessage,
    required this.policyVersion,
  });

  factory AmialSessionPolicy.fromJson(Map<String, dynamic> meta) {
    final actions = meta['available_actions'] as Map<String, dynamic>? ?? {};
    return AmialSessionPolicy(
      accountZone: meta['account_zone'] ?? 'UNKNOWN',
      requestZone: meta['request_zone'],
      allowedOperationalZone: meta['allowed_operational_zone'] ?? 'SOUTH',
      canTransact: meta['can_transact'] ?? false,
      readOnlyMode: meta['read_only_mode'] ?? false,
      readActions: List<String>.from(actions['read'] ?? []),
      financialActions: List<String>.from(actions['financial'] ?? []),
      bannerMessage: meta['banner_message'],
      policyVersion: meta['policy_version'] ?? '1.0',
    );
  }
}

class AmialLegalTerm {
  final int id;
  final String version;
  final String locale;
  final String title;
  final String content;
  final String? changelog;
  final String? effectiveAt;

  AmialLegalTerm({
    required this.id,
    required this.version,
    required this.locale,
    required this.title,
    required this.content,
    this.changelog,
    this.effectiveAt,
  });

  factory AmialLegalTerm.fromJson(Map<String, dynamic> meta) {
    return AmialLegalTerm(
      id: meta['id'] ?? 0,
      version: meta['version'] ?? '0.0',
      locale: meta['locale'] ?? 'ar',
      title: meta['title'] ?? '',
      content: meta['content'] ?? '',
      changelog: meta['changelog'],
      effectiveAt: meta['effective_at'],
    );
  }
}

class AmialLegalStatus {
  final bool needsAcceptance;
  final String? currentVersion;
  final String? title;

  AmialLegalStatus({
    required this.needsAcceptance,
    this.currentVersion,
    this.title,
  });

  factory AmialLegalStatus.fromJson(Map<String, dynamic> meta) {
    return AmialLegalStatus(
      needsAcceptance: meta['needs_acceptance'] ?? false,
      currentVersion: meta['current_version'],
      title: meta['title'],
    );
  }
}

class AmialRecoveryRequest {
  final String requestUlid;
  final String requestType;
  final String status;
  final int? riskScore;
  final String? expiresAt;
  final String? reviewedAt;
  final String? adminNotesExcerpt;

  AmialRecoveryRequest({
    required this.requestUlid,
    required this.requestType,
    required this.status,
    this.riskScore,
    this.expiresAt,
    this.reviewedAt,
    this.adminNotesExcerpt,
  });

  factory AmialRecoveryRequest.fromJson(Map<String, dynamic> meta) {
    return AmialRecoveryRequest(
      requestUlid: meta['request_ulid'] ?? '',
      requestType: meta['request_type'] ?? '',
      status: meta['status'] ?? 'pending_otp',
      riskScore: meta['risk_score'],
      expiresAt: meta['expires_at'],
      reviewedAt: meta['reviewed_at'],
      adminNotesExcerpt: meta['admin_notes_excerpt'],
    );
  }

  bool get isPendingOtp => status == 'pending_otp';
  bool get isPendingReview => status == 'pending_review';
  bool get isApproved => status == 'approved';
  bool get isRejected => status == 'rejected';
}
