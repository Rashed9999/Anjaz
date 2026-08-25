import 'package:flutter/material.dart';
import 'package:amial_pay/features/wholesale/screens/wholesale_pro_screens.dart';
import 'package:amial_pay/features/wholesale/widgets/wholesale_entitlement_gate.dart';

/// AMIAL-WHOLESALE-UI-002 + AMIAL-WHOLESALE-SCOPE-001
///
/// واجهات التوافق تحفظ أسماء الشاشات العامة القديمة كي لا تنكسر المسارات أو
/// CapabilityScreens، لكن كل مدخل عام يمر أولاً من حارس الجملة والاستحقاق.
///
/// السبب: التوجيه الرئيسي يرسل wholesale إلى هذه المساحة بالفعل، لكن أي
/// deep-link أو CapabilityScreen يجب ألا يستطيع فتح شاشة الجملة لتاجر تجزئة،
/// كما يجب ألا يتجاوز باقة التاجر بمجرد معرفة اسم route.
class WholesaleDashboardScreen extends StatelessWidget {
  const WholesaleDashboardScreen({super.key});

  @override
  Widget build(BuildContext context) => const WholesaleEntitlementGate(
        surface: WholesaleSurface.dashboard,
        child: WholesaleProDashboardScreen(),
      );
}

class WholesaleProductsScreen extends StatelessWidget {
  const WholesaleProductsScreen({super.key});

  @override
  Widget build(BuildContext context) => const WholesaleEntitlementGate(
        surface: WholesaleSurface.products,
        child: WholesaleProProductsScreen(),
      );
}

class WholesaleCustomersScreen extends StatelessWidget {
  const WholesaleCustomersScreen({super.key});

  @override
  Widget build(BuildContext context) => const WholesaleEntitlementGate(
        surface: WholesaleSurface.customers,
        child: WholesaleProCustomersScreen(),
      );
}

class WholesaleInvoiceCreateScreen extends StatelessWidget {
  const WholesaleInvoiceCreateScreen({super.key});

  @override
  Widget build(BuildContext context) => const WholesaleEntitlementGate(
        surface: WholesaleSurface.invoices,
        child: WholesaleProInvoiceCreateScreen(),
      );
}

class WholesaleInvoicesListScreen extends StatelessWidget {
  const WholesaleInvoicesListScreen({super.key});

  @override
  Widget build(BuildContext context) => const WholesaleEntitlementGate(
        surface: WholesaleSurface.invoices,
        child: WholesaleProInvoicesScreen(),
      );
}

class WholesaleAgingReportScreen extends StatelessWidget {
  const WholesaleAgingReportScreen({super.key});

  @override
  Widget build(BuildContext context) => const WholesaleEntitlementGate(
        surface: WholesaleSurface.aging,
        child: WholesaleProAgingScreen(),
      );
}

class WholesaleCustomerStatementScreen extends StatelessWidget {
  const WholesaleCustomerStatementScreen({
    super.key,
    required this.customer,
  });

  final Map<String, dynamic> customer;

  @override
  Widget build(BuildContext context) => WholesaleEntitlementGate(
        surface: WholesaleSurface.customers,
        child: WholesaleProCustomerStatementScreen(customer: customer),
      );
}
