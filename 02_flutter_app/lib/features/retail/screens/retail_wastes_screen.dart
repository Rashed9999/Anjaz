import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amial_pay/common/widgets/vertical_state_view.dart';
import 'package:amial_pay/features/retail/controllers/retail_vertical_controller.dart';
import 'package:amial_pay/theme/amial_colors.dart';

/// AMIAL-RETAIL-VERTICAL-001 · المرحلة ١٠ — **الهالك باعتماد**.
///
/// **وتكلفةٌ غير معروفةٍ تُقال «—» لا صفراً**: مجموعٌ فيه أصنافٌ بلا تكلفة
/// يُقرأ خسارةً أقلَّ ممّا وقع.
class RetailWastesScreen extends StatefulWidget {
  const RetailWastesScreen({super.key});

  @override
  State<RetailWastesScreen> createState() => _RetailWastesScreenState();
}

class _RetailWastesScreenState extends State<RetailWastesScreen> {
  RetailVerticalController get c => Get.find<RetailVerticalController>();

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => c.loadWastes());
  }

  Future<void> _act(Future<bool> Function() run, String done) async {
    final ok = await run();
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
      content: Text(ok ? done : (c.lastError.value.isNotEmpty
          ? c.lastError.value : 'تعذّر إتمام العملية')),
      backgroundColor: ok ? Colors.green : AmialColors.red,
    ));
    if (ok) await c.loadWastes();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(
        title: const Text('الهالك'),
        backgroundColor: AmialColors.background,
        elevation: 0,
      ),
      body: RefreshIndicator(
        onRefresh: c.loadWastes,
        child: Obx(() => VerticalStateView(
              c: c,
              isEmpty: c.wastes.isEmpty,
              emptyTitle: 'لا هالك مسجَّل',
              emptyHint: 'الهالك يُسجَّل بسببه ثم يُعتمد — ولا يُخصم قبل الاعتماد.',
              emptyIcon: Icons.delete_outline,
              onRetry: c.loadWastes,
              grantedBy: 'مالك المتجر أو مدير المخزون',
              child: ListView(
                padding: const EdgeInsets.all(16),
                children: [
                  _summary(),
                  const SizedBox(height: 16),
                  ...c.wastes.map(_row),
                ],
              ),
            )),
      ),
    );
  }

  Widget _summary() {
    final r = c.wasteReport;
    if (r.isEmpty) return const SizedBox.shrink();

    final unknown = (r['unknown_cost_lines'] ?? 0) as int;

    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: AmialColors.cardSurface,
        borderRadius: BorderRadius.circular(12),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text('${r['total_cost'] ?? '0'} ر.ي',
              style: const TextStyle(
                  fontSize: 24, fontWeight: FontWeight.bold, color: AmialColors.red)),
          Text('قيمة الهالك المعتمَد خلال ${r['days'] ?? 30} يوماً',
              style: const TextStyle(fontSize: 12, color: AmialColors.textMuted)),
          if (unknown > 0) ...[
            const SizedBox(height: 8),
            Text('$unknown سطراً بلا تكلفة مُدخَلة — غير محسوبة في المبلغ أعلاه',
                style: const TextStyle(fontSize: 11, color: AmialColors.yellowDark)),
          ],
          const SizedBox(height: 12),
          ...(((r['by_reason'] ?? []) as List)
              .map((e) => Map<String, dynamic>.from(e as Map))
              .map((b) => Padding(
                    padding: const EdgeInsets.symmetric(vertical: 2),
                    child: Row(
                      children: [
                        Expanded(child: Text('${b['reason_ar']}',
                            style: const TextStyle(fontSize: 13))),
                        Text('${b['quantity']} · ${b['cost']} ر.ي',
                            style: const TextStyle(
                                fontSize: 12, color: AmialColors.textSecondary)),
                      ],
                    ),
                  ))),
        ],
      ),
    );
  }

  Widget _row(Map<String, dynamic> w) {
    final id = w['id'] as int;
    final pending = w['status'] == 'pending';

    return Card(
      color: AmialColors.cardSurface,
      margin: const EdgeInsets.only(bottom: 8),
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(children: [
              Expanded(child: Text('${w['name']}',
                  style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14))),
              Text('${w['quantity']}',
                  style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14)),
            ]),
            const SizedBox(height: 4),
            Text(
              '${w['reason_ar']} · '
              // **«—» لا صفر**: تكلفةٌ مجهولةٌ ليست بلا قيمة.
              '${w['total_cost'] == null ? 'التكلفة غير معروفة' : '${w['total_cost']} ر.ي'}',
              style: const TextStyle(fontSize: 12, color: AmialColors.textMuted),
            ),
            if (pending) ...[
              const SizedBox(height: 10),
              Wrap(spacing: 8, children: [
                VerticalActionButton(
                  c: c,
                  permission: RetailVerticalController.pWasteApprove,
                  label: 'اعتماد',
                  icon: Icons.check_rounded,
                  color: Colors.green,
                  onPressed: () => _act(() => c.approveWaste(id),
                      'اعتُمد الهالك وخُصم من المخزون'),
                ),
                VerticalActionButton(
                  c: c,
                  permission: RetailVerticalController.pWasteApprove,
                  label: 'رفض',
                  icon: Icons.close_rounded,
                  color: AmialColors.red,
                  onPressed: () => _act(
                      () => c.rejectWaste(id, 'غير مقنع'), 'رُفض الهالك'),
                ),
              ]),
            ],
          ],
        ),
      ),
    );
  }
}
