import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/features/bill_pay/controllers/bill_pay_controller.dart';
import 'package:amyal_pay/features/bill_pay/domain/models/bill_pay_models.dart';
import 'package:amyal_pay/features/bill_pay/screens/bill_pay_form_screen.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';

/// AMIAL-BILL-PAY-001 (v0.9-D)
class BillPayProvidersScreen extends StatefulWidget {
  const BillPayProvidersScreen({super.key});

  @override
  State<BillPayProvidersScreen> createState() => _BillPayProvidersScreenState();
}

class _BillPayProvidersScreenState extends State<BillPayProvidersScreen> {
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      Get.find<BillPayController>().loadProviders();
    });
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmyalColors.background,
      appBar: AppBar(
        backgroundColor: AmyalColors.primary,
        foregroundColor: Colors.white,
        title: const Text('دفع الفواتير'),
        elevation: 0,
      ),
      body: Obx(() {
        final ctrl = Get.find<BillPayController>();
        if (ctrl.isLoading.value && ctrl.providers.isEmpty) {
          return const Center(
              child: CircularProgressIndicator(color: AmyalColors.primary));
        }
        if (ctrl.providers.isEmpty) {
          return ListView(
            children: [
              SizedBox(height: MediaQuery.of(context).size.height * 0.2),
              Icon(Icons.receipt_long, size: 80, color: AmyalColors.textMuted.withValues(alpha: 0.5)),
              const SizedBox(height: 16),
              const Center(child: Text('لا توجد خدمات متاحة حالياً')),
            ],
          );
        }

        return ListView(
          padding: const EdgeInsets.all(12),
          children: ctrl.providers.expand((p) sync* {
            yield Padding(
              padding: const EdgeInsets.symmetric(vertical: 8, horizontal: 4),
              child: Text(
                p.displayNameAr,
                style: const TextStyle(
                    fontSize: 14,
                    fontWeight: FontWeight.bold,
                    color: AmyalColors.textSecondary),
              ),
            );
            for (final svc in p.services.where((s) => s.isActive)) {
              yield _ServiceCard(provider: p, service: svc);
            }
          }).toList(),
        );
      }),
    );
  }
}

class _ServiceCard extends StatelessWidget {
  final AmyalBillProvider provider;
  final AmyalBillService service;
  const _ServiceCard({required this.provider, required this.service});

  @override
  Widget build(BuildContext context) {
    return Card(
      margin: const EdgeInsets.symmetric(vertical: 4),
      child: InkWell(
        borderRadius: BorderRadius.circular(8),
        onTap: () {
          Get.to(() => BillPayFormScreen(provider: provider, service: service));
        },
        child: Padding(
          padding: const EdgeInsets.all(12),
          child: Row(
            children: [
              CircleAvatar(
                backgroundColor: AmyalColors.yellow.withValues(alpha: 0.25),
                child: Icon(_iconForType(service.serviceType),
                    color: AmyalColors.primary, size: 20),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Text(
                  service.displayNameAr,
                  style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 14),
                ),
              ),
              const Icon(Icons.chevron_left, color: AmyalColors.textMuted),
            ],
          ),
        ),
      ),
    );
  }

  IconData _iconForType(String type) {
    switch (type) {
      case 'recharge': return Icons.smartphone;
      case 'postpaid_bill': return Icons.receipt_long;
      case 'internet': return Icons.wifi;
      default: return Icons.payment;
    }
  }
}
