import 'package:amial_pay/common/widgets/amial_brand_logo.dart';
import 'package:amial_pay/features/auth/screens/activate_account_screen.dart';
import 'package:amial_pay/theme/amial_colors.dart';
import 'package:amial_pay/theme/amial_spacing.dart';
import 'package:amial_pay/util/app_constants.dart';
import 'package:amial_pay/util/app_direction.dart';
import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:url_launcher/url_launcher.dart';

/// AMIAL-OPENING-OWNED-BY-MUWATHAQ-001 — فتح الحساب في موثّق وحده، ثم التفعيل المباشر هنا.
class QuickRegistrationScreen extends StatelessWidget {
  const QuickRegistrationScreen({super.key});

  Future<void> _openMuwathaq(BuildContext context) async {
    var ok = false;
    try {
      ok = await launchUrl(
        Uri.parse(AppConstants.muwathaqOpenCustomerAccountUrl),
        mode: LaunchMode.externalApplication,
      );
    } catch (_) {
      ok = false;
    }
    if (!ok && context.mounted) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text('auth_open_browser_error'.tr),
          backgroundColor: AmialColors.danger,
        ),
      );
    }
  }

  Widget _stepRow(String n, String text) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 5),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            CircleAvatar(
              radius: 12,
              backgroundColor: AmialColors.yellow,
              child: Text(n, style: const TextStyle(color: AmialColors.primary, fontWeight: FontWeight.w900, fontSize: 11)),
            ),
            const SizedBox(width: 10),
            Expanded(
              child: Text(text, style: const TextStyle(color: AmialColors.textSecondary, fontSize: 13, height: 1.5)),
            ),
          ],
        ),
      );

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(title: Text('auth_open_title'.tr)),
      body: SafeArea(
        child: Directionality(
          textDirection: appTextDirection(),
          child: Center(
            child: SingleChildScrollView(
              padding: const EdgeInsets.all(AmialSpacing.lg),
              child: Container(
                padding: const EdgeInsets.all(AmialSpacing.lg),
                decoration: BoxDecoration(
                  color: AmialColors.cardSurface,
                  borderRadius: BorderRadius.circular(AmialSpacing.radiusXl),
                  border: Border.all(color: AmialColors.border),
                  boxShadow: AmialSpacing.cardShadow,
                ),
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    const Center(child: AmialBrandLogo(height: 76)),
                    const SizedBox(height: 16),
                    Text(
                      'auth_open_heading'.tr,
                      style: const TextStyle(fontSize: 21, fontWeight: FontWeight.w900, color: AmialColors.primary),
                      textAlign: TextAlign.center,
                    ),
                    const SizedBox(height: 12),
                    _stepRow('1', 'auth_open_step_1'.tr),
                    _stepRow('2', 'auth_open_step_2'.tr),
                    _stepRow('3', 'auth_open_step_3'.tr),
                    const SizedBox(height: 20),
                    FilledButton.icon(
                      onPressed: () => _openMuwathaq(context),
                      icon: const Icon(Icons.verified_user_outlined),
                      label: Text('auth_open_go_muwathaq'.tr),
                    ),
                    const SizedBox(height: 10),
                    OutlinedButton.icon(
                      onPressed: () => Get.to(() => const ActivateAccountScreen()),
                      icon: const Icon(Icons.key_outlined),
                      label: const Text('لديّ رمز تفعيل'),
                    ),
                  ],
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}
