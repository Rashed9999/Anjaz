import 'package:flutter/material.dart';
import 'package:amial_pay/features/auth/screens/quick_registration_screen.dart';

/// AMIAL-PROGRESSIVE-KYC-APP-002
///
/// نقطة التوافق التي كانت تفتح معالج KYC الكامل من شاشة الدخول؛ تفتح الآن شاشة التسجيل التي تحيل إلى موثّق.
/// إنشاء العميل الجديد صار في موثّق وحده، والمعالج القديم أُزيل.
class AmialRegistrationWizardScreen extends StatelessWidget {
  const AmialRegistrationWizardScreen({super.key});

  @override
  Widget build(BuildContext context) => const QuickRegistrationScreen();
}
