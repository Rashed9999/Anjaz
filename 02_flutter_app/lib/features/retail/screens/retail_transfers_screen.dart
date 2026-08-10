import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amial_pay/common/widgets/vertical_state_view.dart';
import 'package:amial_pay/features/retail/controllers/retail_vertical_controller.dart';
import 'package:amial_pay/theme/amial_colors.dart';

/// AMIAL-RETAIL-VERTICAL-001 · المرحلة ١٠ — **التحويلات بمراحلها**.
///
/// وكلُّ زرٍّ هنا مرحلةٌ واحدة: من يملك «الإرسال» لا يرى «الاعتماد».
class RetailTransfersScreen extends StatefulWidget {
  const RetailTransfersScreen({super.key});

  @override
  State<RetailTransfersScreen> createState() => _RetailTransfersScreenState();
}

class _RetailTransfersScreenState extends State<RetailTransfersScreen> {
  RetailVerticalController get c => Get.find<RetailVerticalController>();

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => c.loadTransfers());
  }

  Color _statusColor(String s) => switch (s) {
        'requested' => Colors.orange,
        'approved' => AmialColors.primary,
        'shipped' => Colors.deepPurple,
        'received' => Colors.green,
        'partially_received' => AmialColors.yellowDark,
        'cancelled' => AmialColors.textMuted,
        _ => AmialColors.textSecondary,
      };

  Future<void> _act(Future<bool> Function() run, String done) async {
    final ok = await run();
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
      content: Text(ok ? done : (c.lastError.value.isNotEmpty
          ? c.lastError.value : 'تعذّر إتمام العملية')),
      backgroundColor: ok ? Colors.green : AmialColors.red,
    ));
    if (ok) await c.loadTransfers();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(
        title: const Text('التحويلات'),
        backgroundColor: AmialColors.background,
        elevation: 0,
      ),
      body: RefreshIndicator(
        onRefresh: c.loadTransfers,
        child: Obx(() => VerticalStateView(
              c: c,
              isEmpty: c.transfers.isEmpty,
              emptyTitle: 'لا تحويلات بعد',
              emptyHint: 'التحويل ينقل البضاعة بين موقعين بمراحله: '
                  'طلب ثم اعتماد ثم إرسال ثم استلام.',
              emptyIcon: Icons.swap_horiz_rounded,
              onRetry: c.loadTransfers,
              grantedBy: 'مالك المتجر أو مدير المخزون',
              child: ListView.builder(
                padding: const EdgeInsets.all(16),
                itemCount: c.transfers.length,
                itemBuilder: (_, i) => _row(c.transfers[i]),
              ),
            )),
      ),
    );
  }

  Widget _row(Map<String, dynamic> t) {
    final status = '${t['status']}';
    final id = t['id'] as int;

    return Card(
      color: AmialColors.cardSurface,
      margin: const EdgeInsets.only(bottom: 8),
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(
                  child: Text('${t['from']} ← ${t['to']}',
                      style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14)),
                ),
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                  decoration: BoxDecoration(
                    color: _statusColor(status).withValues(alpha: 0.15),
                    borderRadius: BorderRadius.circular(20),
                  ),
                  child: Text('${t['status_ar']}',
                      style: TextStyle(fontSize: 11, color: _statusColor(status))),
                ),
              ],
            ),
            const SizedBox(height: 4),
            Text('${t['code']} · ${t['lines']} صنفاً',
                style: const TextStyle(fontSize: 12, color: AmialColors.textMuted)),
            const SizedBox(height: 10),
            Wrap(spacing: 8, runSpacing: 8, children: [
              // **ولا يُرسم زرٌّ لمرحلةٍ ليست الحالية** — ولا لمن لا يملكها.
              if (status == 'requested')
                VerticalActionButton(
                  c: c,
                  permission: RetailVerticalController.pTransferApprove,
                  label: 'اعتماد',
                  icon: Icons.check_rounded,
                  onPressed: () => _act(() => c.approveTransfer(id), 'اعتُمد التحويل'),
                ),
              if (status == 'approved')
                VerticalActionButton(
                  c: c,
                  permission: RetailVerticalController.pTransferShip,
                  label: 'إرسال',
                  icon: Icons.local_shipping_outlined,
                  color: Colors.deepPurple,
                  onPressed: () => _act(() => c.shipTransfer(id, {}),
                      'أُرسل التحويل — البضاعة في الطريق'),
                ),
              if (status == 'shipped')
                VerticalActionButton(
                  c: c,
                  permission: RetailVerticalController.pTransferReceive,
                  label: 'استلام',
                  icon: Icons.inventory_2_outlined,
                  color: Colors.green,
                  onPressed: () => _act(() => c.receiveTransfer(id, {}), 'استُلم التحويل'),
                ),
            ]),
          ],
        ),
      ),
    );
  }
}
