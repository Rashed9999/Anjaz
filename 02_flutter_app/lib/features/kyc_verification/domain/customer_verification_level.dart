import 'package:flutter/material.dart';

/// AMIAL-CUSTOMER-VERIFICATION-LABELS-001
///
/// الأرقام 0..3 تبقى مفاتيح تشغيل داخلية فقط. واجهة العميل تعرض اسماً
/// مفهوماً وشارة لونية موحدة، ولا تعرض كلمة Tier أو رقم المستوى.
class CustomerVerificationLevel {
  final int tier;
  final String label;
  final Color color;
  final Color foreground;

  const CustomerVerificationLevel._({
    required this.tier,
    required this.label,
    required this.color,
    required this.foreground,
  });

  static const unverified = CustomerVerificationLevel._(
    tier: 0,
    label: 'عميل غير موثق',
    color: Color(0xFF8B5E3C),
    foreground: Colors.white,
  );

  static const partial = CustomerVerificationLevel._(
    tier: 1,
    label: 'عميل موثق جزئيا',
    color: Color(0xFFF28C28),
    foreground: Colors.white,
  );

  static const identity = CustomerVerificationLevel._(
    tier: 2,
    label: 'عميل موثق بهوية',
    color: Color(0xFF16874C),
    foreground: Colors.white,
  );

  static const verified = CustomerVerificationLevel._(
    tier: 3,
    label: 'عميل موثق',
    color: Color(0xFFD4AF37),
    foreground: Color(0xFF4D3A00),
  );

  static CustomerVerificationLevel fromTier(int tier) => switch (tier) {
        <= 0 => unverified,
        1 => partial,
        2 => identity,
        _ => verified,
      };

  static String labelFor(int tier) => fromTier(tier).label;
}
