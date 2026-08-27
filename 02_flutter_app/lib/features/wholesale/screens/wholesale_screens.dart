import 'package:flutter/material.dart';
import 'package:amial_pay/features/wholesale/screens/wholesale_policy_screens.dart';

/// AMIAL-WHOLESALE-UI-002 + AMIAL-WHOLESALE-ACCESS-001
///
/// هذه هي المداخل العامة الوحيدة لمساحة الجملة. كل شاشة تقرأ snapshot
/// action-level من الخادم قبل عرض أزرار الإنشاء/التعديل/التحصيل/الإبطال.
///
/// لا تُستعمل WholesalePro* مباشرةً من routing العام؛ هي مكوّنات تشغيل
/// داخلية تفتحها طبقة السياسة بعد نجاح الباقة + صلاحية الموظف.
class WholesaleDashboardScreen extends StatelessWidget {
  const WholesaleDashboardScreen({super.key});

  @override
  Widget build(BuildContext context) => const WholesalePolicyDashboardScreen();
}

class WholesaleProductsScreen extends StatelessWidget {
  const WholesaleProductsScreen({super.key});

  @override
  Widget build(BuildContext context) => const WholesalePolicyProductsScreen();
}

class WholesaleCustomersScreen extends StatelessWidget {
  const WholesaleCustomersScreen({super.key});

  @override
  Widget build(BuildContext context) => const WholesalePolicyCustomersScreen();
}

class WholesaleInvoiceCreateScreen extends StatelessWidget {
  const WholesaleInvoiceCreateScreen({super.key});

  @override
  Widget build(BuildContext context) => const WholesalePolicyInvoiceCreateScreen();
}

class WholesaleInvoicesListScreen extends StatelessWidget {
  const WholesaleInvoicesListScreen({super.key});

  @override
  Widget build(BuildContext context) => const WholesalePolicyInvoicesScreen();
}

class WholesaleAgingReportScreen extends StatelessWidget {
  const WholesaleAgingReportScreen({super.key});

  @override
  Widget build(BuildContext context) => const WholesalePolicyAgingScreen();
}

class WholesaleCustomerStatementScreen extends StatelessWidget {
  const WholesaleCustomerStatementScreen({
    super.key,
    required this.customer,
  });

  final Map<String, dynamic> customer;

  @override
  Widget build(BuildContext context) =>
      WholesalePolicyCustomerStatementScreen(customer: customer);
}
