import 'package:flutter/material.dart';
import 'package:amial_pay/features/auth/screens/quick_registration_screen.dart';

/// AMIAL-PROGRESSIVE-KYC-APP-002
///
/// نقطة التوافق التي كانت تفتح معالج KYC الكامل من شاشة الدخول.
/// إنشاء العميل الجديد صار يبدأ بمحفظة أساسية سريعة، بينما نحتفظ بالمعالج
/// الكامل القديم في legacy كمرجع انتقال إلى شاشة ترقية KYC داخل الحساب.
class AmialRegistrationWizardScreen extends StatelessWidget {
  const AmialRegistrationWizardScreen({super.key});

  @override
  Widget build(BuildContext context) => const QuickRegistrationScreen();
}
