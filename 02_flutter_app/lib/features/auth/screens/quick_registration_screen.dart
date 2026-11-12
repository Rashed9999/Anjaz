import 'package:amial_pay/common/widgets/amial_brand_logo.dart';
import 'package:amial_pay/features/auth/screens/activate_account_screen.dart';
import 'package:amial_pay/util/app_constants.dart';
import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:url_launcher/url_launcher.dart';

/// AMIAL-OPENING-OWNED-BY-MUWATHAQ-001
///
/// فتح الحساب يتم في موثّق وحده: هو من يستلم البيانات والأدلة ويقرّر، وبعد
/// الاعتماد يهيّئ أميال باي المحفظة ويرسل بريد خطوات الدخول.
///
/// **هذه الشاشة لا تجمع بياناتٍ ولا تنشئ مستخدماً.** كان هنا معالجٌ من ألف وسبعمئة
/// سطر (بريد ثم هاتف ثم سكن ثم هوية) يُنادي مساراتٍ صارت ترُدّ 410؛ بقي بعد
/// `return` لا يُنفَّذ، وحارسٌ يحرس مراحلَه الميتة فيُبقيه حيّاً على الورق.
/// أُزيل كله: ما لا يُنفَّذ لا يُصان.
class QuickRegistrationScreen extends StatelessWidget {
  const QuickRegistrationScreen({super.key});

  Future<void> _openMuwathaq(BuildContext context) async {
    final ok = await launchUrl(
      Uri.parse(AppConstants.muwathaqOpenCustomerAccountUrl),
      mode: LaunchMode.externalApplication,
    );
    if (!ok && context.mounted) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('تعذر فتح بوابة موثّق.')),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('فتح حساب')),
      body: Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              const AmialBrandLogo(height: 88),
              const SizedBox(height: 20),
              const Text(
                'فتح الحساب يتم عبر موثّق',
                style: TextStyle(fontSize: 22, fontWeight: FontWeight.bold),
                textAlign: TextAlign.center,
              ),
              const SizedBox(height: 10),
              const Text(
                'موثّق يستلم بيانات الطلب وأدلته. بعد الاعتماد يصلك بريد برمز تفعيل؛ أدخله هنا مع بريدك واختر كلمة مرور ورمز PIN فيُفتح حسابك مباشرةً.',
                textAlign: TextAlign.center,
              ),
              const SizedBox(height: 24),
              FilledButton.icon(
                onPressed: () => _openMuwathaq(context),
                icon: const Icon(Icons.verified_user_outlined),
                label: const Text('الانتقال إلى موثّق'),
              ),
              const SizedBox(height: 12),
              OutlinedButton.icon(
                onPressed: () => Get.to(() => const ActivateAccountScreen()),
                icon: const Icon(Icons.key_outlined),
                label: const Text('لديّ رمز تفعيل'),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
