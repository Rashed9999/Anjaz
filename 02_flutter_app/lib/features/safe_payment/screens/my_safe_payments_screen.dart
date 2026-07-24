import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/features/safe_payment/controllers/safe_payment_controller.dart';
import 'package:amyal_pay/features/safe_payment/domain/models/safe_payment_models.dart';
import 'package:amyal_pay/features/safe_payment/screens/create_safe_payment_screen.dart';
import 'package:amyal_pay/features/safe_payment/screens/safe_payment_detail_screen.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';
import 'package:amyal_pay/helper/amial_money.dart';

/// AMIAL-SAFE-PAYMENT-001 (v1.1)
class MySafePaymentsScreen extends StatefulWidget {
  const MySafePaymentsScreen({super.key});

  @override
  State<MySafePaymentsScreen> createState() => _MySafePaymentsScreenState();
}

class _MySafePaymentsScreenState extends State<MySafePaymentsScreen>
    with SingleTickerProviderStateMixin {
  late TabController _tabController;
  final List<String> _tabRoles = ['all', 'buyer', 'seller'];

  @override
  void initState() {
    super.initState();
    _tabController = TabController(length: 3, vsync: this);
    _tabController.addListener(() {
      if (!_tabController.indexIsChanging) _refresh();
    });
    WidgetsBinding.instance.addPostFrameCallback((_) => _refresh());
  }

  Future<void> _refresh() async {
    final role = _tabRoles[_tabController.index];
    await Get.find<SafePaymentController>().loadList(role: role);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmyalColors.background,
      appBar: AppBar(
        backgroundColor: AmyalColors.primary,
        foregroundColor: Colors.white,
        title: const Text('الدفع الآمن'),
        bottom: TabBar(
          controller: _tabController,
          indicatorColor: AmyalColors.yellow,
          labelColor: Colors.white,
          unselectedLabelColor: Colors.white70,
          tabs: const [
            Tab(text: 'الكل'),
            Tab(text: 'كمشتري'),
            Tab(text: 'كبائع'),
          ],
        ),
      ),
      floatingActionButton: FloatingActionButton.extended(
        backgroundColor: AmyalColors.primary,
        foregroundColor: Colors.white,
        onPressed: () async {
          final ok = await Get.to<bool>(() => const CreateSafePaymentScreen());
          if (ok == true && mounted) _refresh();
        },
        icon: const Icon(Icons.add_shopping_cart),
        label: const Text('طلب دفع آمن'),
      ),
      body: RefreshIndicator(
        onRefresh: _refresh,
        color: AmyalColors.primary,
        child: Obx(() {
          final ctrl = Get.find<SafePaymentController>();
          if (ctrl.isLoading.value && ctrl.payments.isEmpty) {
            return const Center(
                child: CircularProgressIndicator(color: AmyalColors.primary));
          }
          if (ctrl.payments.isEmpty) {
            return ListView(
              children: [
                SizedBox(height: MediaQuery.of(context).size.height * 0.15),
                Icon(Icons.shield_outlined,
                    size: 80, color: AmyalColors.textMuted.withValues(alpha: 0.5)),
                const SizedBox(height: 16),
                const Center(
                  child: Text('لا توجد عمليات دفع آمن',
                      style: TextStyle(fontSize: 14)),
                ),
                const SizedBox(height: 8),
                const Center(
                  child: Padding(
                    padding: EdgeInsets.symmetric(horizontal: 32),
                    child: Text(
                      'الدفع الآمن يحمي مالك في عمليات الشراء — الأموال محجوزة حتى تأكيد الاستلام',
                      textAlign: TextAlign.center,
                      style: TextStyle(color: AmyalColors.textSecondary, fontSize: 12),
                    ),
                  ),
                ),
              ],
            );
          }
          return ListView.separated(
            padding: const EdgeInsets.only(top: 8, bottom: 80),
            itemCount: ctrl.payments.length,
            separatorBuilder: (_, _) =>
                const Divider(height: 1, color: AmyalColors.border),
            itemBuilder: (context, i) => _PaymentTile(payment: ctrl.payments[i]),
          );
        }),
      ),
    );
  }

  @override
  void dispose() {
    _tabController.dispose();
    super.dispose();
  }
}

class _PaymentTile extends StatelessWidget {
  final AmyalSafePayment payment;
  const _PaymentTile({required this.payment});

  @override
  Widget build(BuildContext context) {
    final statusInfo = _statusVisuals(payment.status, payment.isDisputed);
    return ListTile(
      tileColor: Colors.white,
      contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 6),
      leading: CircleAvatar(
        backgroundColor: statusInfo.color.withValues(alpha: 0.15),
        child: Icon(statusInfo.icon, color: statusInfo.color, size: 20),
      ),
      title: Text(
        payment.title,
        style: const TextStyle(fontWeight: FontWeight.w600),
        maxLines: 1,
        overflow: TextOverflow.ellipsis,
      ),
      subtitle: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            payment.arabicStatusLabel,
            style: TextStyle(fontSize: 12, color: statusInfo.color, fontWeight: FontWeight.w500),
          ),
          if (payment.createdAt != null)
            Text(
              _fmtRelative(payment.createdAt!),
              style: const TextStyle(fontSize: 10, color: AmyalColors.textMuted),
            ),
        ],
      ),
      trailing: Column(
        mainAxisAlignment: MainAxisAlignment.center,
        crossAxisAlignment: CrossAxisAlignment.end,
        children: [
          Text(
            '${AmialMoney.fmt(payment.amount)} ر.ي',
            style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14),
          ),
          if (payment.isDisputed)
            const Padding(
              padding: EdgeInsets.only(top: 2),
              child: Icon(Icons.warning, color: Colors.orange, size: 14),
            ),
        ],
      ),
      onTap: () {
        Get.to(() => SafePaymentDetailScreen(ulid: payment.paymentUlid));
      },
    );
  }

  _StatusVisual _statusVisuals(String status, bool disputed) {
    if (disputed) return _StatusVisual(Icons.warning, Colors.orange);
    switch (status) {
      case 'pending_seller_acceptance':
        return _StatusVisual(Icons.hourglass_top, Colors.blue);
      case 'funded':
      case 'in_delivery':
      case 'delivered':
        return _StatusVisual(Icons.local_shipping, AmyalColors.primary);
      case 'released_to_seller':
        return _StatusVisual(Icons.check_circle, Colors.green.shade700);
      case 'refunded_to_buyer':
      case 'cancelled':
      case 'seller_rejected':
      case 'expired':
        return _StatusVisual(Icons.cancel, Colors.grey);
      case 'partially_refunded':
        return _StatusVisual(Icons.handshake, Colors.amber.shade700);
      default:
        return _StatusVisual(Icons.shield, AmyalColors.primary);
    }
  }

  String _fmtRelative(DateTime d) {
    final diff = DateTime.now().difference(d);
    if (diff.inMinutes < 60) return 'قبل ${diff.inMinutes} دقيقة';
    if (diff.inHours < 24) return 'قبل ${diff.inHours} ساعة';
    if (diff.inDays < 7) return 'قبل ${diff.inDays} يوم';
    return '${d.year}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';
  }
}

class _StatusVisual {
  final IconData icon;
  final Color color;
  _StatusVisual(this.icon, this.color);
}
