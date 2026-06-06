import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/features/agent/screens/agent_dashboard_screen.dart';
import 'package:amyal_pay/features/donations/screens/donations_home_screen.dart';
import 'package:amyal_pay/features/merchant/screens/merchant_dashboard_screen.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';

/// AMIAL-UNIFIED-AUTH-001 (v1.6)
///
/// RoleRouter — يوجّه المستخدم للشاشة المناسبة حسب دوره بعد تسجيل الدخول.
///
/// استخدام بعد نجاح login:
///   RoleRouter.navigateToHome(role);
class RoleRouter {
  /// توجيه للشاشة الرئيسية حسب الدور.
  static void navigateToHome(String role) {
    switch (role) {
      case 'customer':
        // الشاشة الرئيسية للعميل — استبدلها بـ CustomerHomeScreen الموجودة في Cash6
        Get.offAll(() => const _CustomerHomePlaceholder());
        break;
      case 'merchant':
      case 'pos':
        Get.offAll(() => const MerchantDashboardScreen());
        break;
      case 'agent':
        Get.offAll(() => const AgentDashboardScreen());
        break;
      default:
        Get.offAll(() => const _CustomerHomePlaceholder());
    }
  }

  /// واجهة الشاشة الرئيسية حسب الدور (بدون navigation - للـ embedding).
  static Widget homeForRole(String role) {
    return switch (role) {
      'merchant' || 'pos' => const MerchantDashboardScreen(),
      'agent' => const AgentDashboardScreen(),
      _ => const _CustomerHomePlaceholder(),
    };
  }
}

/// Placeholder — استبدله بـ Customer home الموجودة في Cash6 الأصلي.
class _CustomerHomePlaceholder extends StatelessWidget {
  const _CustomerHomePlaceholder();

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmyalColors.background,
      appBar: AppBar(
        backgroundColor: AmyalColors.primary,
        foregroundColor: Colors.white,
        title: const Text('أميال باي'),
      ),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          Container(
            padding: const EdgeInsets.all(20),
            decoration: BoxDecoration(
              gradient: const LinearGradient(
                colors: [AmyalColors.primary, Color(0xFF1A56C2)],
              ),
              borderRadius: BorderRadius.circular(12),
            ),
            child: const Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text('مرحباً بك',
                    style: TextStyle(color: Colors.white70, fontSize: 12)),
                SizedBox(height: 4),
                Text('الرصيد المتاح',
                    style: TextStyle(color: Colors.white70, fontSize: 13)),
                SizedBox(height: 4),
                Text('0.00 ر.س',
                    style: TextStyle(
                        color: Colors.white,
                        fontSize: 28,
                        fontWeight: FontWeight.bold)),
              ],
            ),
          ),
          const SizedBox(height: 24),
          GridView.count(
            crossAxisCount: 3,
            shrinkWrap: true,
            physics: const NeverScrollableScrollPhysics(),
            mainAxisSpacing: 12,
            crossAxisSpacing: 12,
            children: [
              _GridItem(icon: Icons.send, label: 'تحويل', onTap: () {}),
              _GridItem(icon: Icons.qr_code_scanner, label: 'دفع QR', onTap: () {}),
              _GridItem(icon: Icons.receipt, label: 'فواتير', onTap: () {}),
              _GridItem(
                icon: Icons.volunteer_activism,
                label: 'تبرعات',
                onTap: () => Get.to(() => const DonationsHomeScreen()),
              ),
              _GridItem(icon: Icons.group, label: 'صندوق عائلي', onTap: () {}),
              _GridItem(icon: Icons.shield, label: 'دفع آمن', onTap: () {}),
            ],
          ),
          const SizedBox(height: 16),
          const Center(
            child: Text(
              'استبدل هذه الشاشة بـ CustomerHomeScreen الأصلية من Cash6',
              style: TextStyle(fontSize: 11, color: AmyalColors.textMuted),
              textAlign: TextAlign.center,
            ),
          ),
        ],
      ),
    );
  }
}

class _GridItem extends StatelessWidget {
  final IconData icon;
  final String label;
  final VoidCallback onTap;
  const _GridItem({required this.icon, required this.label, required this.onTap});

  @override
  Widget build(BuildContext context) {
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(12),
      child: Container(
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(12),
          border: Border.all(color: AmyalColors.border),
        ),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            CircleAvatar(
              radius: 22,
              backgroundColor: AmyalColors.yellow.withOpacity(0.2),
              child: Icon(icon, color: AmyalColors.primary, size: 22),
            ),
            const SizedBox(height: 6),
            Text(label, style: const TextStyle(fontSize: 11)),
          ],
        ),
      ),
    );
  }
}
