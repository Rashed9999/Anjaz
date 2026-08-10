import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amial_pay/common/widgets/vertical_state_view.dart';
import 'package:amial_pay/features/retail/controllers/retail_vertical_controller.dart';
import 'package:amial_pay/theme/amial_colors.dart';

/// AMIAL-RETAIL-VERTICAL-001 · المرحلة ١٠ — **الجرد**.
///
/// **و«لم يُعدّ» تُعرض «—» لا صفراً** (القاعدة ٧): صفرٌ يُقرأ «عُدّ فلم
/// يوجد»، وشتّان بين الاثنين حين يُعتمد الجرد.
class RetailCountsScreen extends StatefulWidget {
  const RetailCountsScreen({super.key});

  @override
  State<RetailCountsScreen> createState() => _RetailCountsScreenState();
}

class _RetailCountsScreenState extends State<RetailCountsScreen> {
  RetailVerticalController get c => Get.find<RetailVerticalController>();

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) async {
      await c.loadCounts();
      await c.loadLocations();
    });
  }

  Future<void> _act(Future<bool> Function() run, String done) async {
    final ok = await run();
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
      content: Text(ok ? done : (c.lastError.value.isNotEmpty
          ? c.lastError.value : 'تعذّر إتمام العملية')),
      backgroundColor: ok ? Colors.green : AmialColors.red,
    ));
    if (ok) await c.loadCounts();
  }

  Future<void> _openCount() async {
    if (c.locations.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
        content: Text('لا مواقع — أضف موقعاً أولاً')));
      return;
    }

    int locationId = c.locations.first['id'] as int;

    final go = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('فتح جرد كامل'),
        content: StatefulBuilder(
          builder: (_, setLocal) => DropdownButtonFormField<int>(
            initialValue: locationId,
            decoration: const InputDecoration(labelText: 'الموقع'),
            items: c.locations
                .map((l) => DropdownMenuItem(
                    value: l['id'] as int, child: Text('${l['name']}')))
                .toList(),
            onChanged: (v) => setLocal(() => locationId = v ?? locationId),
          ),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('إلغاء')),
          ElevatedButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('فتح')),
        ],
      ),
    );

    if (go == true) {
      await _act(() => c.openCount({'location_id': locationId, 'kind': 'full'}),
          'فُتح الجرد');
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(
        title: const Text('الجرد'),
        backgroundColor: AmialColors.background,
        elevation: 0,
      ),
      floatingActionButton: Obx(() => c.can(RetailVerticalController.pCountStart)
          ? FloatingActionButton.extended(
              onPressed: _openCount,
              backgroundColor: AmialColors.primary,
              icon: const Icon(Icons.add, color: Colors.white),
              label: const Text('جرد جديد', style: TextStyle(color: Colors.white)),
            )
          : const SizedBox.shrink()),
      body: RefreshIndicator(
        onRefresh: c.loadCounts,
        child: Obx(() => VerticalStateView(
              c: c,
              isEmpty: c.counts.isEmpty,
              emptyTitle: 'لا جرد بعد',
              emptyHint: 'الجرد يقارن المعدود بالنظام، ولكلّ فرقٍ سببٌ واعتماد.',
              emptyIcon: Icons.rule_outlined,
              onRetry: c.loadCounts,
              grantedBy: 'مالك المتجر أو مدير المخزون',
              child: ListView.builder(
                padding: const EdgeInsets.all(16),
                itemCount: c.counts.length,
                itemBuilder: (_, i) => _row(c.counts[i]),
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
            Text('${t['code']} · ${t['kind_ar']}',
                style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14)),
            const SizedBox(height: 4),
            Text('${t['location']} · ${t['lines']} صنفاً · ${t['status_ar']}',
                style: const TextStyle(fontSize: 12, color: AmialColors.textMuted)),
            const SizedBox(height: 10),
            Wrap(spacing: 8, runSpacing: 8, children: [
              if (status == 'counting')
                VerticalActionButton(
                  c: c,
                  permission: RetailVerticalController.pCountEnter,
                  label: 'رفع للمراجعة',
                  icon: Icons.upload_rounded,
                  onPressed: () => _act(() => c.submitCount(id), 'رُفع الجرد للمراجعة'),
                ),
              if (status == 'review')
                VerticalActionButton(
                  c: c,
                  permission: RetailVerticalController.pCountApprove,
                  label: 'اعتماد وتسوية',
                  icon: Icons.check_rounded,
                  color: Colors.green,
                  onPressed: () => _act(() => c.approveCount(id),
                      'اعتُمد الجرد وسُوّيت الفروق'),
                ),
            ]),
          ],
        ),
      ),
    );
  }
}
