/// العقد الواحد الذي يصف المنشأة في تطبيق التاجر.
///
/// هو طبقة تنظيم فوق الأنظمة القائمة؛ لا يعيد تنفيذ المنتجات أو الديون أو
/// الدفع. الباقات والقدرات التفصيلية تبقى في المصدر الخلفي للـ entitlements.
class MerchantContext {
  const MerchantContext({
    required this.businessName,
    required this.businessType,
    required this.businessTypeLabel,
    required this.plan,
    required this.actor,
    required this.isPosSession,
    required this.timezone,
    required this.clockFormat,
  });

  final String businessName;
  final String? businessType;
  final String? businessTypeLabel;
  final String plan;
  final String actor;
  final bool isPosSession;
  final String timezone;
  final String clockFormat;

  factory MerchantContext.fromJson(Map<dynamic, dynamic> json) {
    final business = json['business'] is Map ? json['business'] as Map : const {};
    final subscription = json['subscription'] is Map ? json['subscription'] as Map : const {};
    final actor = json['actor'] is Map ? json['actor'] as Map : const {};
    final device = json['device'] is Map ? json['device'] as Map : const {};
    final clock = json['clock'] is Map ? json['clock'] as Map : const {};
    return MerchantContext(
      businessName: '${business['name'] ?? 'تاجر أميال باي'}',
      businessType: business['business_type']?.toString(),
      businessTypeLabel: business['business_type_label']?.toString(),
      plan: '${subscription['plan'] ?? 'free'}',
      actor: '${actor['kind'] ?? 'customer'}',
      isPosSession: device['is_pos_session'] == true,
      timezone: '${clock['timezone'] ?? 'Asia/Riyadh'}',
      clockFormat: '${clock['format'] ?? 'HH:mm'}',
    );
  }
}
