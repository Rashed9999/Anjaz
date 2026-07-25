import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/features/splash/controllers/splash_controller.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';
import 'package:url_launcher/url_launcher.dart';

/// AMIAL-UNIFY-UI-001 — «الدعم» بهوية أميال (كانت شاشة 6cash بأنماط قديمة).
class SupportScreen extends StatelessWidget {
  const SupportScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final cfg = Get.find<SplashController>().configModel;
    final phone = cfg?.companyPhone;
    final email = cfg?.companyEmail;

    return Scaffold(
      backgroundColor: AmyalColors.background,
      appBar: AppBar(
        title: Text('24_support'.tr),
      ),
      body: ListView(
        padding: const EdgeInsets.all(20),
        children: [
          const SizedBox(height: 12),
          Center(
            child: Container(
              width: 96,
              height: 96,
              decoration: BoxDecoration(
                color: AmyalColors.primary.withValues(alpha: 0.08),
                shape: BoxShape.circle,
              ),
              child: const Icon(Icons.headset_mic_rounded,
                  size: 46, color: AmyalColors.primary),
            ),
          ),
          const SizedBox(height: 18),
          Text('need_any_help'.tr,
              textAlign: TextAlign.center,
              style: const TextStyle(
                  fontSize: 20,
                  fontWeight: FontWeight.bold,
                  color: Color(0xFF1A2433))),
          const SizedBox(height: 6),
          Text('feel_free_to_contact'.tr,
              textAlign: TextAlign.center,
              style: const TextStyle(
                  fontSize: 14, color: AmyalColors.textSecondary)),
          const SizedBox(height: 28),
          if (phone != null && phone.isNotEmpty)
            _contactCard(
              icon: Icons.phone_rounded,
              label: 'make_call'.tr,
              value: phone,
              filled: true,
              onTap: () => launchUrl(Uri.parse('tel://$phone')),
            ),
          if (email != null && email.isNotEmpty) ...[
            const SizedBox(height: 12),
            _contactCard(
              icon: Icons.email_rounded,
              label: 'send_email'.tr,
              value: email,
              filled: false,
              onTap: () => launchUrl(Uri(scheme: 'mailto', path: email)),
            ),
          ],
          if ((phone == null || phone.isEmpty) && (email == null || email.isEmpty))
            Container(
              margin: const EdgeInsets.only(top: 12),
              padding: const EdgeInsets.all(16),
              decoration: BoxDecoration(
                color: Colors.white,
                borderRadius: BorderRadius.circular(14),
              ),
              child: Text('feel_free_to_contact'.tr,
                  textAlign: TextAlign.center,
                  style: const TextStyle(color: AmyalColors.textSecondary)),
            ),
        ],
      ),
    );
  }

  Widget _contactCard({
    required IconData icon,
    required String label,
    required String value,
    required bool filled,
    required VoidCallback onTap,
  }) {
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(16),
      child: Container(
        padding: const EdgeInsets.all(16),
        decoration: BoxDecoration(
          color: filled ? AmyalColors.primary : Colors.white,
          borderRadius: BorderRadius.circular(16),
          border: Border.all(
              color: filled ? AmyalColors.primary : AmyalColors.border),
          boxShadow: [
            BoxShadow(
                color: Colors.black.withValues(alpha: 0.04),
                blurRadius: 8,
                offset: const Offset(0, 3)),
          ],
        ),
        child: Row(children: [
          Container(
            width: 44,
            height: 44,
            decoration: BoxDecoration(
              color: filled
                  ? Colors.white.withValues(alpha: 0.18)
                  : AmyalColors.primary.withValues(alpha: 0.08),
              borderRadius: BorderRadius.circular(12),
            ),
            child: Icon(icon,
                color: filled ? Colors.white : AmyalColors.primary),
          ),
          const SizedBox(width: 14),
          Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(label,
                  style: TextStyle(
                      fontWeight: FontWeight.bold,
                      fontSize: 15,
                      color: filled ? Colors.white : const Color(0xFF1A2433))),
              Text(value,
                  textDirection: TextDirection.ltr,
                  style: TextStyle(
                      fontSize: 12,
                      color: filled ? Colors.white70 : AmyalColors.textSecondary)),
            ]),
          ),
          Icon(Icons.chevron_left_rounded,
              color: filled ? Colors.white70 : AmyalColors.textMuted),
        ]),
      ),
    );
  }
}
