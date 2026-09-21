import 'package:amial_pay/common/widgets/amial_form.dart';
import 'package:amial_pay/features/kyc_verification/widgets/customer_verification_panel.dart';
import 'package:amial_pay/theme/amial_colors.dart';
import 'package:flutter/material.dart';

/// AMIAL-KYC-CENTER-001
///
/// الباب الوحيد لتوثيق العميل الفرد داخل التطبيق.
/// يجمع في شاشة مستقلة:
/// - حالة التوثيق الحالية.
/// - الحدود المالية والاستخدام.
/// - المستويات والمتطلبات والمزايا.
/// - رفع المستوى.
/// - النماذج/المستندات المؤرشفة.
///
/// إبقاء هذه التفاصيل داخل «حسابي» كان يجعل الصفحة طويلة جداً ويخلط
/// إعدادات الحساب اليومية مع رحلة KYC. لذلك أصبحت «حسابي» تعرض مدخلاً
/// واحداً فقط، وكل التفاصيل تعيش هنا.
class CustomerVerificationCenterScreen extends StatelessWidget {
  const CustomerVerificationCenterScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmialColors.background,
      body: SafeArea(
        child: Column(
          children: [
            const AmialScreenHeader(title: 'التوثيق'),
            Expanded(
              child: ListView(
                padding: const EdgeInsets.fromLTRB(16, 8, 16, 24),
                children: const [
                  CustomerVerificationPanel(),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}
