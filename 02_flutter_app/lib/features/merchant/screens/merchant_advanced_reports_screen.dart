import 'package:flutter/material.dart';
import 'package:get/get.dart';

import 'package:amial_pay/features/merchant/screens/financial_truth_report_screen.dart';
import 'package:amial_pay/features/merchant/screens/merchant_excel_export_screen.dart';
import 'package:amial_pay/features/merchant/screens/profit_report_screen.dart';
import 'package:amial_pay/theme/amial_colors.dart';

/// نقطة دخول التقارير المدفوعة. ليست نسخةً من تقرير اليوم: كل بطاقة تدخل
/// إلى تقرير مستقل متصل بمصدر بياناته أو إلى تصدير فعلي من الخادم.
class MerchantAdvancedReportsScreen extends StatelessWidget {
  const MerchantAdvancedReportsScreen({super.key});

  @override
  Widget build(BuildContext context) => Scaffold(
        backgroundColor: AmialColors.background,
        appBar: AppBar(title: const Text('التقارير المتقدمة')),
        body: ListView(
          padding: const EdgeInsets.all(16),
          children: [
            const Text('حلّل التشغيل والربحية ثم صدّر دفتر المتجر.',
                style: TextStyle(color: AmialColors.textSecondary)),
            const SizedBox(height: 16),
            _reportCard(
              context,
              icon: Icons.account_balance_wallet_outlined,
              color: AmialColors.primary,
              title: 'التقرير المالي التشغيلي',
              subtitle: 'المبيعات والنقد والمحفظة والذمم لفترة 1 أو 7 أو 30 يوماً',
              onTap: () => Get.to(() => const FinancialTruthReportScreen()),
            ),
            _reportCard(
              context,
              icon: Icons.trending_up_rounded,
              color: AmialColors.success,
              title: 'تحليل الربحية',
              subtitle: 'الإيراد والتكلفة والهامش وأعلى المنتجات ربحاً',
              onTap: () => Get.to(() => const ProfitReportScreen()),
            ),
            _reportCard(
              context,
              icon: Icons.file_download_outlined,
              color: const Color(0xFF1D6F42),
              title: 'تصدير دفتر المتجر',
              subtitle: 'ملف Excel حقيقي من بيانات الخادم للتحليل والمحاسبة',
              onTap: () => Get.to(() => const MerchantExcelExportScreen()),
            ),
          ],
        ),
      );

  Widget _reportCard(
    BuildContext context, {
    required IconData icon,
    required Color color,
    required String title,
    required String subtitle,
    required VoidCallback onTap,
  }) => Container(
        margin: const EdgeInsets.only(bottom: 12),
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(16),
        ),
        child: ListTile(
          onTap: onTap,
          contentPadding: const EdgeInsets.all(14),
          leading: Container(
            width: 46,
            height: 46,
            decoration: BoxDecoration(
              color: color.withValues(alpha: 0.12),
              borderRadius: BorderRadius.circular(12),
            ),
            child: Icon(icon, color: color),
          ),
          title: Text(title, style: const TextStyle(fontWeight: FontWeight.bold)),
          subtitle: Padding(
            padding: const EdgeInsets.only(top: 4),
            child: Text(subtitle, style: const TextStyle(fontSize: 12)),
          ),
          trailing: const Icon(Icons.chevron_left),
        ),
      );
}
