import 'package:flutter/material.dart';
import 'package:amial_pay/theme/amial_colors.dart';
import 'package:amial_pay/util/app_constants.dart';

/// صفحة تعريف لا تعتمد على إعداد HTML اختياري قد يتركها فارغة.
class AboutAmialScreen extends StatelessWidget {
  const AboutAmialScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(title: const Text('معلومات عنا')),
      body: ListView(
        padding: const EdgeInsets.all(24),
        children: [
          Container(
            width: 72,
            height: 72,
            decoration: BoxDecoration(
              color: AmialColors.yellow,
              borderRadius: BorderRadius.circular(20),
            ),
            child: const Icon(Icons.account_balance_wallet_rounded,
                color: AmialColors.primary, size: 38),
          ),
          const SizedBox(height: 22),
          const Text(
            'أميال باي',
            style: TextStyle(fontSize: 24, fontWeight: FontWeight.w900),
          ),
          const SizedBox(height: 8),
          const Text(
            'محفظة وخدمات مالية تساعدك على استلام الأموال، متابعة عملياتك، والتعامل مع خدماتك من مكان واحد.',
            style: TextStyle(
              height: 1.7,
              color: AmialColors.textSecondary,
            ),
          ),
          const SizedBox(height: 24),
          _row(Icons.language_rounded, 'الموقع', AppConstants.productionDomain),
          const SizedBox(height: 12),
          _row(Icons.info_outline_rounded, 'إصدار التطبيق',
              AppConstants.appVersion.toString()),
        ],
      ),
    );
  }

  Widget _row(IconData icon, String title, String value) => Container(
        padding: const EdgeInsets.all(14),
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(14),
          border: Border.all(color: AmialColors.border),
        ),
        child: Row(children: [
          Icon(icon, color: AmialColors.primary),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(title, style: const TextStyle(fontWeight: FontWeight.w700)),
                const SizedBox(height: 2),
                Text(value, style: const TextStyle(color: AmialColors.textSecondary)),
              ],
            ),
          ),
        ]),
      );
}
