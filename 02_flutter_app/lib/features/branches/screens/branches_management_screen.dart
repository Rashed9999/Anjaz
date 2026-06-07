import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';
import 'package:amyal_pay/features/branches/controllers/branches_controller.dart';

/// P1-BRANCHES — شاشة إدارة الفروع.
///
/// تعرض:
/// - قائمة بكل فروع التاجر مع badges (افتراضي/نشط).
/// - زر إضافة (يفحص حدّ الخطّة → 402 = upgrade dialog).
/// - ضغطة طويلة → خيارات (تعديل/حذف/تعيين افتراضي/تقرير).
class BranchesManagementScreen extends StatefulWidget {
  const BranchesManagementScreen({super.key});

  @override
  State<BranchesManagementScreen> createState() => _BMSState();
}

class _BMSState extends State<BranchesManagementScreen> {
  late final BranchesController c;

  @override
  void initState() {
    super.initState();
    c = Get.find<BranchesController>();
    WidgetsBinding.instance.addPostFrameCallback((_) => c.loadBranches());
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmyalColors.background,
      appBar: AppBar(
        title: const Text('الفروع'),
        backgroundColor: AmyalColors.primary,
        foregroundColor: Colors.white,
      ),
      body: RefreshIndicator(
        onRefresh: () => c.loadBranches(),
        child: Obx(() {
          if (c.isLoading.value && c.branches.isEmpty) {
            return const Center(child: CircularProgressIndicator());
          }
          if (c.branches.isEmpty) {
            return _emptyState();
          }
          return ListView.separated(
            padding: const EdgeInsets.all(12),
            itemCount: c.branches.length,
            separatorBuilder: (_, _) => const SizedBox(height: 8),
            itemBuilder: (_, i) => _branchCard(c.branches[i]),
          );
        }),
      ),
      floatingActionButton: FloatingActionButton.extended(
        backgroundColor: AmyalColors.primary,
        foregroundColor: Colors.white,
        icon: const Icon(Icons.add),
        label: const Text('فرع جديد'),
        onPressed: () => _showEditDialog(null),
      ),
    );
  }

  Widget _emptyState() {
    return ListView(children: [
      const SizedBox(height: 120),
      Center(child: Column(children: [
        Icon(Icons.store_mall_directory_outlined, size: 80, color: Colors.grey.shade400),
        const SizedBox(height: 16),
        Text('لا توجد فروع بعد',
            style: TextStyle(color: Colors.grey.shade700, fontSize: 16, fontWeight: FontWeight.bold)),
        const SizedBox(height: 8),
        Text('أضف فرعك الأوّل لبدء إدارة عمليات منفصلة',
            style: TextStyle(color: Colors.grey.shade600, fontSize: 13)),
        const SizedBox(height: 12),
        Text('🔒 يتطلّب خطّة MERCHANT_PRO أو ENTERPRISE',
            style: TextStyle(color: Colors.orange.shade700, fontSize: 11)),
      ])),
    ]);
  }

  Widget _branchCard(Map<String, dynamic> b) {
    final isDefault = b['is_default'] == true;
    final isActive = b['is_active'] == true;
    final isCurrent = c.activeBranchId.value == b['id'];

    return Container(
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(
          color: isCurrent ? AmyalColors.yellow : Colors.transparent,
          width: 2,
        ),
        boxShadow: [BoxShadow(color: Colors.black.withValues(alpha: 0.04), blurRadius: 8)],
      ),
      child: InkWell(
        borderRadius: BorderRadius.circular(12),
        onTap: () => c.switchBranch(b['id'] as int),
        onLongPress: () => _showActions(b),
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Row(children: [
            Container(width: 50, height: 50,
              decoration: BoxDecoration(
                color: isActive
                  ? AmyalColors.primary.withValues(alpha: 0.1)
                  : Colors.grey.shade200,
                borderRadius: BorderRadius.circular(12),
              ),
              child: Icon(
                isDefault ? Icons.star : Icons.store,
                color: isActive ? AmyalColors.primary : Colors.grey,
                size: 26,
              ),
            ),
            const SizedBox(width: 12),
            Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Row(children: [
                Expanded(child: Text(b['name']?.toString() ?? '',
                    style: const TextStyle(fontSize: 16, fontWeight: FontWeight.bold))),
                if (isDefault) _badge('افتراضي', AmyalColors.yellowDark),
                if (isCurrent) ...[
                  const SizedBox(width: 4),
                  _badge('نشط', Colors.green),
                ],
              ]),
              if (b['city'] != null) Text(
                '📍 ${b['city']}${b['address'] != null ? " — ${b['address']}" : ""}',
                style: TextStyle(color: Colors.grey.shade700, fontSize: 12),
                maxLines: 1, overflow: TextOverflow.ellipsis,
              ),
              if (b['phone'] != null) Text('📞 ${b['phone']}',
                  style: TextStyle(color: Colors.grey.shade600, fontSize: 11)),
              if (!isActive) const Padding(
                padding: EdgeInsets.only(top: 4),
                child: Text('⛔ معطّل', style: TextStyle(color: Colors.red, fontSize: 11)),
              ),
            ])),
            IconButton(
              icon: const Icon(Icons.more_vert, color: Colors.grey),
              onPressed: () => _showActions(b),
            ),
          ]),
        ),
      ),
    );
  }

  Widget _badge(String text, Color color) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
      decoration: BoxDecoration(color: color.withValues(alpha: 0.15),
          borderRadius: BorderRadius.circular(12)),
      child: Text(text, style: TextStyle(color: color, fontSize: 10, fontWeight: FontWeight.bold)),
    );
  }

  void _showActions(Map<String, dynamic> b) {
    showModalBottomSheet(context: context, builder: (ctx) => SafeArea(
      child: Wrap(children: [
        ListTile(
          leading: const Icon(Icons.edit, color: AmyalColors.primary),
          title: const Text('تعديل'),
          onTap: () { Navigator.pop(ctx); _showEditDialog(b); },
        ),
        if (b['is_default'] != true) ListTile(
          leading: const Icon(Icons.star, color: AmyalColors.yellowDark),
          title: const Text('تعيين كافتراضي'),
          onTap: () async {
            Navigator.pop(ctx);
            final ok = await c.setDefault(b['id'] as int);
            if (mounted) {
              ScaffoldMessenger.of(context).showSnackBar(SnackBar(
              content: Text(ok ? '✓ تم' : 'فشل'),
              backgroundColor: ok ? Colors.green : Colors.red,
            ));
            }
          },
        ),
        ListTile(
          leading: const Icon(Icons.analytics, color: Colors.indigo),
          title: const Text('تقرير الفرع'),
          onTap: () { Navigator.pop(ctx); _showReport(b); },
        ),
        if (b['is_default'] != true) ListTile(
          leading: const Icon(Icons.delete, color: Colors.red),
          title: const Text('حذف', style: TextStyle(color: Colors.red)),
          onTap: () { Navigator.pop(ctx); _confirmDelete(b); },
        ),
      ]),
    ));
  }

  void _showEditDialog(Map<String, dynamic>? b) {
    final isNew = b == null;
    final nameCtrl = TextEditingController(text: b?['name']?.toString() ?? '');
    final cityCtrl = TextEditingController(text: b?['city']?.toString() ?? '');
    final addressCtrl = TextEditingController(text: b?['address']?.toString() ?? '');
    final phoneCtrl = TextEditingController(text: b?['phone']?.toString() ?? '');
    final codeCtrl = TextEditingController(text: b?['code']?.toString() ?? '');

    showDialog(context: context, builder: (ctx) => AlertDialog(
      title: Text(isNew ? 'فرع جديد' : 'تعديل ${b['name']}'),
      content: SingleChildScrollView(child: Column(mainAxisSize: MainAxisSize.min, children: [
        TextField(controller: nameCtrl,
            decoration: const InputDecoration(labelText: 'اسم الفرع *', isDense: true)),
        const SizedBox(height: 8),
        TextField(controller: codeCtrl,
            decoration: const InputDecoration(labelText: 'الكود (اختياري)', isDense: true)),
        const SizedBox(height: 8),
        TextField(controller: cityCtrl,
            decoration: const InputDecoration(labelText: 'المدينة', isDense: true)),
        const SizedBox(height: 8),
        TextField(controller: addressCtrl,
            decoration: const InputDecoration(labelText: 'العنوان', isDense: true), maxLines: 2),
        const SizedBox(height: 8),
        TextField(controller: phoneCtrl, keyboardType: TextInputType.phone,
            decoration: const InputDecoration(labelText: 'الهاتف', isDense: true)),
      ])),
      actions: [
        TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('إلغاء')),
        FilledButton(
          onPressed: () async {
            final name = nameCtrl.text.trim();
            if (name.isEmpty) return;
            Navigator.pop(ctx);
            final data = {
              'name': name,
              if (codeCtrl.text.isNotEmpty) 'code': codeCtrl.text.trim(),
              if (cityCtrl.text.isNotEmpty) 'city': cityCtrl.text.trim(),
              if (addressCtrl.text.isNotEmpty) 'address': addressCtrl.text.trim(),
              if (phoneCtrl.text.isNotEmpty) 'phone': phoneCtrl.text.trim(),
            };
            final ok = isNew ? await c.create(data) : await c.updateBranch(b['id'] as int, data);
            if (mounted) {
              ScaffoldMessenger.of(context).showSnackBar(SnackBar(
              content: Text(ok ? (isNew ? '✓ تمّ الإنشاء' : '✓ تمّ التحديث') : 'فشل'),
              backgroundColor: ok ? Colors.green : Colors.red,
            ));
            }
          },
          child: Text(isNew ? 'إنشاء' : 'حفظ'),
        ),
      ],
    ));
  }

  void _confirmDelete(Map<String, dynamic> b) {
    showDialog(context: context, builder: (ctx) => AlertDialog(
      title: const Text('تأكيد الحذف'),
      content: Text('هل تريد حذف "${b['name']}"؟\nسجلّات هذا الفرع تبقى محفوظة.'),
      actions: [
        TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('إلغاء')),
        FilledButton(
          style: FilledButton.styleFrom(backgroundColor: Colors.red),
          onPressed: () async {
            Navigator.pop(ctx);
            final ok = await c.delete(b['id'] as int);
            if (mounted) {
              ScaffoldMessenger.of(context).showSnackBar(SnackBar(
              content: Text(ok ? '✓ تمّ الحذف' : c.lastError.value),
              backgroundColor: ok ? Colors.green : Colors.red,
            ));
            }
          },
          child: const Text('حذف'),
        ),
      ],
    ));
  }

  void _showReport(Map<String, dynamic> b) async {
    await c.loadReport(b['id'] as int);
    if (!mounted) return;
    final r = c.currentReport.value;
    if (r == null) return;
    final wh = (r['sections']?['wholesale'] ?? {}) as Map;

    showDialog(context: context, builder: (ctx) => AlertDialog(
      title: Text('📊 ${b['name']}'),
      content: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
        Text('الفترة: ${r['period']?['from']} → ${r['period']?['to']}',
            style: TextStyle(color: Colors.grey.shade600, fontSize: 12)),
        const Divider(),
        const Text('الجملة', style: TextStyle(fontWeight: FontWeight.bold)),
        Text('عدد الفواتير: ${wh['invoice_count'] ?? 0}'),
        Text('الإجمالي: ${wh['total_amount'] ?? 0} ر.ي'),
        Text('المدفوع: ${wh['paid_amount'] ?? 0} ر.ي', style: const TextStyle(color: Colors.green)),
        Text('المتبقّي: ${wh['balance_due'] ?? 0} ر.ي', style: const TextStyle(color: Colors.red)),
        const Divider(),
        Text('الموظّفون النشطون: ${r['sections']?['employees']?['active_count'] ?? 0}'),
      ]),
      actions: [
        TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('إغلاق')),
      ],
    ));
  }
}
