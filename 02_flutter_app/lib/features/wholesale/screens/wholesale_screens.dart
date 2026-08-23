import 'package:flutter/material.dart';
import 'package:amial_pay/features/wholesale/screens/wholesale_pro_screens.dart';

/// AMIAL-WHOLESALE-UI-002
///
/// واجهات توافقٍ تحفظ أسماء الشاشات العامة القديمة كي لا تنكسر المسارات أو
/// CapabilityScreens، بينما التنفيذ البصري والتشغيلي أصبح في مساحة الجملة
/// الاحترافية الجديدة.
class WholesaleDashboardScreen extends StatelessWidget {
  const WholesaleDashboardScreen({super.key});

  @override
  Widget build(BuildContext context) => const WholesaleProDashboardScreen();
}

class WholesaleProductsScreen extends StatelessWidget {
  const WholesaleProductsScreen({super.key});

  @override
  Widget build(BuildContext context) => const WholesaleProProductsScreen();
}

class WholesaleCustomersScreen extends StatelessWidget {
  const WholesaleCustomersScreen({super.key});

  @override
  Widget build(BuildContext context) => const WholesaleProCustomersScreen();
}

class WholesaleInvoiceCreateScreen extends StatelessWidget {
  const WholesaleInvoiceCreateScreen({super.key});

  @override
  Widget build(BuildContext context) => const WholesaleProInvoiceCreateScreen();
}

class WholesaleInvoicesListScreen extends StatelessWidget {
  const WholesaleInvoicesListScreen({super.key});

  @override
  Widget build(BuildContext context) => const WholesaleProInvoicesScreen();
}

class WholesaleAgingReportScreen extends StatelessWidget {
  const WholesaleAgingReportScreen({super.key});

  @override
  Widget build(BuildContext context) => const WholesaleProAgingScreen();
}

class WholesaleCustomerStatementScreen extends StatelessWidget {
  const WholesaleCustomerStatementScreen({
    super.key,
    required this.customer,
  });

  final Map<String, dynamic> customer;

  @override
  Widget build(BuildContext context) =>
      WholesaleProCustomerStatementScreen(customer: customer);
}
