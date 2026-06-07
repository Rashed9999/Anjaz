import 'package:flutter/material.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';

/// AMIAL-VERIFIED-BADGE-001 — شارة موثَّق موحّدة.
///
/// تستخدم في:
///   - شاشة تفاصيل التاجر (شاشات 21، 30)
///   - شاشة استلام دفعة (شاشة 32، 34)
///   - شاشة الكاشير عند QR
///   - الإيصالات
///   - بطاقة الملف الشخصي
///
/// مستويات (tier):
///   - 'verified' = توثيق أساسي (KYC مكتمل) → علامة صح + لون أزرق
///   - 'premium'  = توثيق محسّن (مستندات قانونية + سجل ناجح) → أصفر ذهبي
///   - 'gold'     = موثّق ذهبي (TOP merchants) → ذهبي + بريق
///
/// الاستخدام:
///   VerifiedBadge(tier: 'verified', size: VerifiedBadgeSize.small)
///   VerifiedBadge.text(tier: 'premium')  ← مع نص "موثّق"
class VerifiedBadge extends StatelessWidget {
  final String? tier; // null = لا شارة
  final VerifiedBadgeSize size;
  final bool withText;

  const VerifiedBadge({
    super.key,
    required this.tier,
    this.size = VerifiedBadgeSize.medium,
    this.withText = false,
  });

  /// نسخة مختصرة مع نص "موثّق"
  factory VerifiedBadge.text({
    Key? key,
    required String? tier,
    VerifiedBadgeSize size = VerifiedBadgeSize.medium,
  }) =>
      VerifiedBadge(key: key, tier: tier, size: size, withText: true);

  @override
  Widget build(BuildContext context) {
    if (tier == null || tier!.isEmpty || tier == 'unverified') {
      return const SizedBox.shrink();
    }

    final config = _configFor(tier!);
    final iconSize = _iconSize();
    final fontSize = _fontSize();

    if (!withText) {
      return Container(
        width: iconSize + 4,
        height: iconSize + 4,
        decoration: BoxDecoration(
          color: config.background,
          shape: BoxShape.circle,
          boxShadow: tier == 'gold'
              ? [BoxShadow(color: config.background.withValues(alpha: 0.4), blurRadius: 6, spreadRadius: 1)]
              : null,
        ),
        child: Icon(config.icon, color: config.iconColor, size: iconSize),
      );
    }

    return Container(
      padding: EdgeInsets.symmetric(horizontal: _hPad(), vertical: _vPad()),
      decoration: BoxDecoration(
        color: config.background,
        borderRadius: BorderRadius.circular(12),
        boxShadow: tier == 'gold'
            ? [BoxShadow(color: config.background.withValues(alpha: 0.4), blurRadius: 4)]
            : null,
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(config.icon, color: config.iconColor, size: iconSize),
          SizedBox(width: _hPad() / 2),
          Text(
            config.label,
            style: TextStyle(
              color: config.textColor,
              fontSize: fontSize,
              fontWeight: FontWeight.bold,
            ),
          ),
        ],
      ),
    );
  }

  _BadgeConfig _configFor(String t) {
    switch (t) {
      case 'gold':
        return _BadgeConfig(
          icon: Icons.workspace_premium,
          background: AmyalColors.yellow,
          iconColor: const Color(0xFF5C4400),
          textColor: const Color(0xFF5C4400),
          label: 'موثّق ذهبي',
        );
      case 'premium':
        return _BadgeConfig(
          icon: Icons.verified,
          background: AmyalColors.yellowDark,
          iconColor: Colors.white,
          textColor: Colors.white,
          label: 'موثّق محسّن',
        );
      case 'verified':
      default:
        return _BadgeConfig(
          icon: Icons.verified,
          background: AmyalColors.primary,
          iconColor: Colors.white,
          textColor: Colors.white,
          label: 'موثّق',
        );
    }
  }

  double _iconSize() => switch (size) {
        VerifiedBadgeSize.small => 12.0,
        VerifiedBadgeSize.medium => 16.0,
        VerifiedBadgeSize.large => 22.0,
      };

  double _fontSize() => switch (size) {
        VerifiedBadgeSize.small => 10.0,
        VerifiedBadgeSize.medium => 12.0,
        VerifiedBadgeSize.large => 14.0,
      };

  double _hPad() => switch (size) {
        VerifiedBadgeSize.small => 6.0,
        VerifiedBadgeSize.medium => 8.0,
        VerifiedBadgeSize.large => 12.0,
      };

  double _vPad() => switch (size) {
        VerifiedBadgeSize.small => 2.0,
        VerifiedBadgeSize.medium => 4.0,
        VerifiedBadgeSize.large => 6.0,
      };
}

enum VerifiedBadgeSize { small, medium, large }

class _BadgeConfig {
  final IconData icon;
  final Color background;
  final Color iconColor;
  final Color textColor;
  final String label;

  _BadgeConfig({
    required this.icon,
    required this.background,
    required this.iconColor,
    required this.textColor,
    required this.label,
  });
}
