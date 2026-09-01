import 'package:flutter/material.dart';
import 'package:get/get.dart';

import 'package:amial_pay/features/access/controllers/access_controller.dart';
import 'package:amial_pay/features/merchant/screens/merchant_services_hub_screen.dart';
import 'package:amial_pay/features/merchant/screens/receipt_settings_screen.dart';
import 'package:amial_pay/features/setting/screens/support_screen.dart';
import 'package:amial_pay/theme/amial_colors.dart';

/// إعدادات المنشأة في تطبيق التاجر.
///
/// هذه ليست شاشة «حسابي» الشخصية: لا تعرض رقم محفظة الموظف ولا السحب
/// الشخصي أو كشف حساب العميل. إعدادات تشغيل المنشأة تُفتح من هنا فقط.
class MerchantAccountScreen extends StatelessWidget {
  const MerchantAccountScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final access = Get.find<AccessController>();
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(title: const Text('إعدادات المنشأة')),
      body: Obx(() => ListView(
            padding: const EdgeInsets.all(16),
            children: [
              Container(
                padding: const EdgeInsets.all(18),
                decoration: BoxDecoration(
                  gradient: const LinearGradient(
                    colors: [AmialColors.primary, AmialColors.primaryDark],
                  ),
                  borderRadius: BorderRadius.circular(16),
                ),
                child: Row(children: [
                  const Icon(Icons.storefront_rounded,
                      color: AmialColors.yellow, size: 34),
                  const SizedBox(width: 12),
                  Expanded(child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(access.businessName,
                          style: const TextStyle(
                              color: Colors.white,
                              fontSize: 18,
                              fontWeight: FontWeight.w800)),
                      Text(access.businessTypeLabel.value ?? 'منشأة تجارية',
                          style: const TextStyle(color: Colors.white70)),
                    ],
                  )),
                ]),
              ),
              const SizedBox(height: 20),
              _item(
                context,
                icon: Icons.apps_rounded,
                title: 'خدمات المنشأة',
                subtitle: 'المبيعات، العملاء، الموظفون، التقارير والاستحقاقات',
                onTap: () => Get.to(() => const MerchantServicesHubScreen()),
              ),
              _item(
                context,
                icon: Icons.receipt_long_outlined,
                title: 'إعدادات الفاتورة والطباعة',
                subtitle: 'اسم المنشأة، الترويسة، الشعار والطابعة',
                onTap: () => Get.to(() => const ReceiptSettingsScreen()),
              ),
              _item(
                context,
                icon: Icons.support_agent_outlined,
                title: 'الدعم والمساعدة',
                subtitle: 'تواصل مع دعم أميال باي',
                onTap: () => Get.to(() => const SupportScreen()),
              ),
            ],
          )),
    );
  }

  Widget _item(BuildContext context,
      {required IconData icon,
      required String title,
      required String subtitle,
      required VoidCallback onTap}) {
    return Card(
      elevation: 0,
      margin: const EdgeInsets.only(bottom: 10),
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(14),
        side: const BorderSide(color: AmialColors.border),
      ),
      child: ListTile(
        leading: Icon(icon, color: AmialColors.primary),
        title: Text(title, style: const TextStyle(fontWeight: FontWeight.w700)),
        subtitle: Text(subtitle),
        trailing: const Icon(Icons.chevron_left_rounded),
        onTap: onTap,
      ),
    );
  }
}
