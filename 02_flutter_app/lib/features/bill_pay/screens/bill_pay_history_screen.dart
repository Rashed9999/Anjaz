import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amial_pay/features/bill_pay/controllers/bill_pay_controller.dart';
import 'package:amial_pay/features/bill_pay/domain/models/bill_pay_models.dart';
import 'package:amial_pay/helper/amial_money.dart';
import 'package:amial_pay/theme/amial_colors.dart';

/// AMIAL-BILL-PAY-HISTORY-001
///
/// سجل العميل لعمليات السداد. الحالة هنا من أمر السداد على الخادم؛
/// لا نُحوّل HTTP 200 إلى «نجاح» ما لم تكن الحالة success صراحةً.
class BillPayHistoryScreen extends StatefulWidget {
  const BillPayHistoryScreen({super.key});

  @override
  State<BillPayHistoryScreen> createState() => _BillPayHistoryScreenState();
}

class _BillPayHistoryScreenState extends State<BillPayHistoryScreen> {
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback(
      (_) => Get.find<BillPayController>().loadOrders(),
    );
  }

  String _maskedAccount(String raw) {
    final value = raw.trim();
    if (value.length <= 5) return '•••••';
    return '${value.substring(0, 3)}•••${value.substring(value.length - 2)}';
  }

  String _date(DateTime? value) {
    if (value == null) return '—';
    final local = value.toLocal();
    String two(int x) => x.toString().padLeft(2, '0');
    return '${local.year}-${two(local.month)}-${two(local.day)} '
        '${two(local.hour)}:${two(local.minute)}';
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(title: const Text('عمليات السداد')),
      body: Obx(() {
        final ctrl = Get.find<BillPayController>();

        if (ctrl.isLoading.value && ctrl.orders.isEmpty) {
          return const Center(
            child: CircularProgressIndicator(color: AmialColors.primary),
          );
        }

        if (ctrl.orders.isEmpty && ctrl.lastError.value.isNotEmpty) {
          return _StateView(
            icon: Icons.cloud_off_outlined,
            title: 'تعذّر تحميل عمليات السداد',
            subtitle: ctrl.lastError.value,
            actionLabel: 'إعادة المحاولة',
            onAction: ctrl.loadOrders,
          );
        }

        if (ctrl.orders.isEmpty) {
          return _StateView(
            icon: Icons.receipt_long_outlined,
            title: 'لا توجد عمليات سداد بعد',
            subtitle: 'ستظهر هنا عمليات الشحن وسداد الفواتير بعد تنفيذها.',
            actionLabel: 'تحديث',
            onAction: ctrl.loadOrders,
          );
        }

        return RefreshIndicator(
          onRefresh: ctrl.loadOrders,
          child: ListView.separated(
            physics: const AlwaysScrollableScrollPhysics(),
            padding: const EdgeInsets.all(12),
            itemCount: ctrl.orders.length + 1,
            separatorBuilder: (_, __) => const SizedBox(height: 10),
            itemBuilder: (context, index) {
              if (index == 0) {
                final hasPending = ctrl.orders.any((o) => o.isPending);
                if (!hasPending) return const SizedBox.shrink();
                return Container(
                  padding: const EdgeInsets.all(12),
                  decoration: BoxDecoration(
                    color: AmialColors.warning.withValues(alpha: 0.10),
                    borderRadius: BorderRadius.circular(12),
                    border: Border.all(
                      color: AmialColors.warning.withValues(alpha: 0.35),
                    ),
                  ),
                  child: const Row(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Icon(Icons.schedule_rounded, color: AmialColors.warning),
                      SizedBox(width: 10),
                      Expanded(
                        child: Text(
                          'العملية «قيد التأكيد» ليست فشلاً ولا نجاحاً بعد. '
                          'المبلغ يبقى محجوزاً مؤقتاً، ولا تُعِد الدفع. '
                          'أميال يتحقق من نفس مرجع العملية تلقائياً.',
                          style: TextStyle(height: 1.5),
                        ),
                      ),
                    ],
                  ),
                );
              }

              final order = ctrl.orders[index - 1];
              return _OrderCard(
                order: order,
                maskedAccount: _maskedAccount(order.subscriberAccount),
                date: _date(order.createdAt),
              );
            },
          ),
        );
      }),
    );
  }
}

class _OrderCard extends StatelessWidget {
  final AmialBillOrder order;
  final String maskedAccount;
  final String date;

  const _OrderCard({
    required this.order,
    required this.maskedAccount,
    required this.date,
  });

  ({String label, Color color, IconData icon}) get _status {
    switch (order.status) {
      case 'success':
        return (
          label: 'تم السداد',
          color: AmialColors.success,
          icon: Icons.check_circle_outline_rounded,
        );
      case 'failed':
        return (
          label: 'فشل وأعيد المبلغ',
          color: AmialColors.danger,
          icon: Icons.replay_circle_filled_outlined,
        );
      case 'reversed':
        return (
          label: 'مسترجعة',
          color: AmialColors.danger,
          icon: Icons.undo_rounded,
        );
      case 'pending':
      case 'processing':
      case 'pending_provider_confirmation':
        return (
          label: 'قيد التأكيد',
          color: AmialColors.warning,
          icon: Icons.schedule_rounded,
        );
      default:
        return (
          label: 'قيد المراجعة',
          color: AmialColors.textSecondary,
          icon: Icons.info_outline_rounded,
        );
    }
  }

  @override
  Widget build(BuildContext context) {
    final state = _status;
    final providerRef = order.providerReference?.trim();

    return Card(
      margin: EdgeInsets.zero,
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              children: [
                Icon(state.icon, color: state.color),
                const SizedBox(width: 8),
                Expanded(
                  child: Text(
                    state.label,
                    style: TextStyle(
                      color: state.color,
                      fontWeight: FontWeight.w800,
                    ),
                  ),
                ),
                Text(
                  '${AmialMoney.fmt(order.amount)} ر.ي',
                  style: const TextStyle(
                    fontWeight: FontWeight.w900,
                    fontSize: 16,
                  ),
                ),
              ],
            ),
            const Divider(height: 24),
            _line('الحساب', maskedAccount),
            _line('الرسوم', '${AmialMoney.fmt(order.fee)} ر.ي'),
            _line('الإجمالي', '${AmialMoney.fmt(order.totalDebited)} ر.ي'),
            _line('التاريخ', date),
            _line('مرجع أميال', order.orderUlid),
            if (providerRef != null && providerRef.isNotEmpty)
              _line('مرجع المزود', providerRef),
            if (order.providerMessage?.trim().isNotEmpty == true) ...[
              const SizedBox(height: 8),
              Text(
                order.providerMessage!.trim(),
                style: const TextStyle(
                  color: AmialColors.textSecondary,
                  fontSize: 12,
                  height: 1.5,
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }

  Widget _line(String label, String value) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 5),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(
            width: 92,
            child: Text(
              label,
              style: const TextStyle(
                color: AmialColors.textSecondary,
                fontSize: 12,
              ),
            ),
          ),
          Expanded(
            child: Text(
              value,
              textDirection: TextDirection.ltr,
              textAlign: TextAlign.end,
              style: const TextStyle(fontSize: 12.5, fontWeight: FontWeight.w600),
            ),
          ),
        ],
      ),
    );
  }
}

class _StateView extends StatelessWidget {
  final IconData icon;
  final String title;
  final String subtitle;
  final String actionLabel;
  final Future<void> Function() onAction;

  const _StateView({
    required this.icon,
    required this.title,
    required this.subtitle,
    required this.actionLabel,
    required this.onAction,
  });

  @override
  Widget build(BuildContext context) {
    return ListView(
      physics: const AlwaysScrollableScrollPhysics(),
      padding: const EdgeInsets.all(28),
      children: [
        const SizedBox(height: 80),
        Icon(icon, size: 64, color: AmialColors.textMuted),
        const SizedBox(height: 16),
        Text(
          title,
          textAlign: TextAlign.center,
          style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 17),
        ),
        const SizedBox(height: 8),
        Text(
          subtitle,
          textAlign: TextAlign.center,
          style: const TextStyle(color: AmialColors.textSecondary, height: 1.5),
        ),
        const SizedBox(height: 18),
        Center(
          child: OutlinedButton.icon(
            onPressed: onAction,
            icon: const Icon(Icons.refresh_rounded),
            label: Text(actionLabel),
          ),
        ),
      ],
    );
  }
}
