import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';
import 'package:amyal_pay/features/admin/controllers/admin_controller.dart';
import 'package:amyal_pay/features/admin/screens/subscriptions_management_screen.dart';
import 'package:amyal_pay/features/admin/screens/whatsapp_settings_screen.dart';

/// CRITICAL-001-ADMIN — لوحة الإدارة الموحّدة.
///
/// 3 تبويبات:
///   1. التجار (قائمة + بحث + تغيير خطّة + توثيق)
///   2. العجز/الفائض (قيد المراجعة + حلّ)
///   3. الإحصائيات
class AdminDashboardScreen extends StatefulWidget {
  const AdminDashboardScreen({super.key});

  @override
  State<AdminDashboardScreen> createState() => _AdminDashboardScreenState();
}

class _AdminDashboardScreenState extends State<AdminDashboardScreen> {
  late final AdminController c;
  final _searchCtrl = TextEditingController();

  @override
  void initState() {
    super.initState();
    c = Get.find<AdminController>();
    WidgetsBinding.instance.addPostFrameCallback((_) async {
      await c.loadDashboard();
      await c.loadMerchants();
    });
  }

  @override
  void dispose() { _searchCtrl.dispose(); super.dispose(); }

  @override
  Widget build(BuildContext context) {
    return DefaultTabController(
      length: 3,
      child: Scaffold(
        backgroundColor: AmyalColors.background,
        appBar: AppBar(
          title: const Text('لوحة الإدارة'),
          backgroundColor: AmyalColors.primary,
          foregroundColor: Colors.white,
          actions: [
            // CRITICAL-001-SUBS — اختصار إدارة الاشتراكات
            IconButton(
              icon: const Icon(Icons.workspace_premium),
              tooltip: 'الاشتراكات',
              onPressed: () => Get.to(() => const SubscriptionsManagementScreen()),
            ),
            // AMIAL-WHATSAPP-OTP-001 — اختصار إعدادات واتساب
            IconButton(
              icon: const Icon(Icons.chat),
              tooltip: 'إعدادات واتساب',
              onPressed: () => Get.to(() => const WhatsappSettingsScreen()),
            ),
          ],
          bottom: const TabBar(
            indicatorColor: AmyalColors.yellow,
            labelColor: Colors.white,
            unselectedLabelColor: Colors.white70,
            tabs: [
              Tab(icon: Icon(Icons.store), text: 'التجار'),
              Tab(icon: Icon(Icons.warning_amber), text: 'العجز/الفائض'),
              Tab(icon: Icon(Icons.bar_chart), text: 'إحصائيات'),
            ],
          ),
        ),
        body: TabBarView(children: [
          _merchantsTab(),
          _variancesTab(),
          _statsTab(),
        ]),
      ),
    );
  }

  // ============ Tab 1: التجار ============
  Widget _merchantsTab() {
    return Column(children: [
      Container(
        padding: const EdgeInsets.all(10),
        color: Colors.white,
        child: TextField(
          controller: _searchCtrl,
          textAlign: TextAlign.right,
          onSubmitted: (v) { c.searchQuery.value = v; c.loadMerchants(); },
          decoration: InputDecoration(
            hintText: 'بحث (اسم/هاتف)',
            prefixIcon: const Icon(Icons.search),
            isDense: true,
            border: OutlineInputBorder(borderRadius: BorderRadius.circular(10)),
          ),
        ),
      ),
      Expanded(child: Obx(() {
        if (c.isLoading.value && c.merchants.isEmpty) return const Center(child: CircularProgressIndicator());
        if (c.merchants.isEmpty) return Center(child: Text('لا يوجد تجار', style: TextStyle(color: Colors.grey.shade600)));
        return RefreshIndicator(
          onRefresh: c.loadMerchants,
          child: ListView.builder(
            padding: const EdgeInsets.all(12),
            itemCount: c.merchants.length,
            itemBuilder: (_, i) => _merchantCard(c.merchants[i]),
          ),
        );
      })),
    ]);
  }

  Widget _merchantCard(Map<String, dynamic> m) {
    final user = (m['user'] ?? {}) as Map;
    final plan = m['subscription_plan']?.toString() ?? 'free';
    final bizType = m['business_type']?.toString();
    final status = m['verification_status']?.toString() ?? 'pending_review';

    return InkWell(
      onTap: () => _showMerchantActions(m),
      child: Container(
        margin: const EdgeInsets.only(bottom: 8),
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(10)),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(children: [
            const CircleAvatar(radius: 18, backgroundColor: AmyalColors.primary,
                child: Icon(Icons.store, color: Colors.white, size: 18)),
            const SizedBox(width: 10),
            Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text('${user['f_name'] ?? ''} ${user['l_name'] ?? ''}'.trim(),
                  style: const TextStyle(fontWeight: FontWeight.bold)),
              Text(user['phone'] ?? '', style: TextStyle(color: Colors.grey.shade600, fontSize: 12)),
            ])),
          ]),
          const SizedBox(height: 8),
          Wrap(spacing: 6, runSpacing: 6, children: [
            _chip(plan.toUpperCase(), AmyalColors.primary),
            if (bizType != null) _chip(bizType, AmyalColors.yellowDark),
            _chip(_statusLabel(status), _statusColor(status)),
          ]),
        ]),
      ),
    );
  }

  Widget _chip(String label, Color color) => Container(
    padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
    decoration: BoxDecoration(color: color.withValues(alpha: 0.15), borderRadius: BorderRadius.circular(6)),
    child: Text(label, style: TextStyle(color: color, fontSize: 11, fontWeight: FontWeight.bold)),
  );

  String _statusLabel(String s) => switch(s) {
    'verified' => 'موثّق',
    'pending_review' => 'قيد المراجعة',
    'rejected' => 'مرفوض',
    'resubmission_required' => 'يحتاج إعادة',
    _ => s,
  };

  Color _statusColor(String s) => switch(s) {
    'verified' => Colors.green,
    'pending_review' => Colors.orange,
    'rejected' => Colors.red,
    'resubmission_required' => Colors.purple,
    _ => Colors.grey,
  };

  void _showMerchantActions(Map<String, dynamic> m) {
    showModalBottomSheet(
      context: context,
      shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(20))),
      builder: (_) => Padding(
        padding: const EdgeInsets.all(16),
        child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Text('${(m['user'] ?? {})['f_name'] ?? ''}',
              style: const TextStyle(fontSize: 18, fontWeight: FontWeight.bold), textAlign: TextAlign.center),
          const SizedBox(height: 16),
          ListTile(
            leading: const Icon(Icons.workspace_premium, color: AmyalColors.yellowDark),
            title: const Text('تغيير الخطّة'),
            onTap: () { Navigator.pop(context); _showPlanDialog(m); },
          ),
          if (m['verification_status'] == 'pending_review') ...[
            ListTile(
              leading: const Icon(Icons.check_circle, color: Colors.green),
              title: const Text('اعتماد التوثيق'),
              onTap: () { Navigator.pop(context); _verifyAction(m, 'approve'); },
            ),
            ListTile(
              leading: const Icon(Icons.cancel, color: Colors.red),
              title: const Text('رفض'),
              onTap: () { Navigator.pop(context); _verifyAction(m, 'reject'); },
            ),
          ],
        ]),
      ),
    );
  }

  void _showPlanDialog(Map<String, dynamic> m) {
    String selected = m['subscription_plan']?.toString() ?? 'free';
    const plans = [
      ('free', 'مجاني', '0 ر.س'),
      ('starter', 'البداية', '15 ر.س'),
      ('business', 'الأعمال', '35 ر.س'),
      ('merchant_pro', 'تاجر محترف', '65 ر.س'),
      ('enterprise', 'مؤسسة', '150 ر.س'),
    ];
    final notes = TextEditingController();

    showDialog(context: context, builder: (ctx) => StatefulBuilder(builder: (_, setSt) => AlertDialog(
      title: const Text('اختر الخطّة'),
      content: SingleChildScrollView(child: Column(mainAxisSize: MainAxisSize.min, children: [
        ...plans.map((p) => RadioListTile<String>(
          title: Text(p.$2),
          subtitle: Text(p.$3),
          value: p.$1, groupValue: selected,
          onChanged: (v) => setSt(() => selected = v!),
          contentPadding: EdgeInsets.zero, dense: true,
        )),
        const SizedBox(height: 8),
        TextField(controller: notes, decoration: const InputDecoration(
          labelText: 'ملاحظات (طريقة الدفع، إلخ)',
        )),
      ])),
      actions: [
        TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('إلغاء')),
        Obx(() => FilledButton(
          onPressed: c.isSubmitting.value ? null : () async {
            final ok = await c.updateMerchantPlan(m['id'], selected,
                notes: notes.text.trim().isEmpty ? null : notes.text.trim());
            if (!mounted) return;
            if (ok) Navigator.pop(ctx);
            ScaffoldMessenger.of(context).showSnackBar(SnackBar(
              content: Text(ok ? 'تم التحديث' : c.lastError.value),
              backgroundColor: ok ? Colors.green : AmyalColors.red,
            ));
          },
          child: const Text('حفظ'),
        )),
      ],
    )));
  }

  Future<void> _verifyAction(Map<String, dynamic> m, String action) async {
    final ok = await c.verifyMerchant(m['id'], action);
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
      content: Text(ok ? 'تم' : c.lastError.value),
      backgroundColor: ok ? Colors.green : AmyalColors.red,
    ));
  }

  // ============ Tab 2: العجز/الفائض ============
  Widget _variancesTab() {
    return RefreshIndicator(
      onRefresh: c.loadPendingVariances,
      child: FutureBuilder(
        future: c.loadPendingVariances(),
        builder: (_, _) => Obx(() {
          if (c.isLoading.value && c.variances.isEmpty) return const Center(child: CircularProgressIndicator());
          if (c.variances.isEmpty) {
            return Center(child: Column(mainAxisAlignment: MainAxisAlignment.center, children: [
              const Icon(Icons.check_circle, size: 64, color: Colors.green),
              const SizedBox(height: 12),
              Text('لا عجز/فائض قيد المراجعة', style: TextStyle(color: Colors.grey.shade600)),
            ]));
          }
          return ListView.builder(
            padding: const EdgeInsets.all(12),
            itemCount: c.variances.length,
            itemBuilder: (_, i) => _varianceCard(c.variances[i]),
          );
        }),
      ),
    );
  }

  Widget _varianceCard(Map<String, dynamic> v) {
    final shift = (v['shift'] ?? {}) as Map;
    final isPositive = (v['variance_type'] ?? '') == 'surplus';
    return InkWell(
      onTap: () => _resolveDialog(v),
      child: Container(
        margin: const EdgeInsets.only(bottom: 8),
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(10),
          border: Border(right: BorderSide(color: isPositive ? Colors.green : Colors.red, width: 4)),
        ),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Row(children: [
            Icon(isPositive ? Icons.add_circle : Icons.remove_circle,
                color: isPositive ? Colors.green : Colors.red),
            const SizedBox(width: 8),
            Expanded(child: Text(isPositive ? 'فائض' : 'عجز',
                style: TextStyle(color: isPositive ? Colors.green : Colors.red, fontWeight: FontWeight.bold))),
            Text('${v['variance_amount']} ر.ي',
                style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold,
                    color: isPositive ? Colors.green : Colors.red)),
          ]),
          const SizedBox(height: 6),
          Text('Shift #${(shift['shift_ulid'] ?? '').toString().substring(0, 8)}...',
              style: TextStyle(color: Colors.grey.shade600, fontSize: 11, fontFamily: 'monospace')),
        ]),
      ),
    );
  }

  void _resolveDialog(Map<String, dynamic> v) {
    String resolution = 'accepted';
    final note = TextEditingController();
    const options = [
      ('accepted', 'قبول'),
      ('covered_by_employee', 'يتحمّلها الموظف'),
      ('charged_to_petty_cash', 'من صندوق نثرية'),
      ('written_off', 'شطب'),
    ];

    showDialog(context: context, builder: (ctx) => StatefulBuilder(builder: (_, setSt) => AlertDialog(
      title: const Text('حلّ العجز/الفائض'),
      content: SingleChildScrollView(child: Column(mainAxisSize: MainAxisSize.min, children: [
        ...options.map((o) => RadioListTile<String>(
          title: Text(o.$2),
          value: o.$1, groupValue: resolution,
          onChanged: (val) => setSt(() => resolution = val!),
          dense: true, contentPadding: EdgeInsets.zero,
        )),
        const SizedBox(height: 8),
        TextField(controller: note, maxLines: 2,
            decoration: const InputDecoration(labelText: 'ملاحظة')),
      ])),
      actions: [
        TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('إلغاء')),
        Obx(() => FilledButton(
          onPressed: c.isSubmitting.value ? null : () async {
            final ok = await c.resolveVariance(v['id'], resolution,
                note: note.text.trim().isEmpty ? null : note.text.trim());
            if (!mounted) return;
            if (ok) Navigator.pop(ctx);
            ScaffoldMessenger.of(context).showSnackBar(SnackBar(
              content: Text(ok ? 'تم الحلّ' : c.lastError.value),
              backgroundColor: ok ? Colors.green : AmyalColors.red,
            ));
          },
          child: const Text('حلّ'),
        )),
      ],
    )));
  }

  // ============ Tab 3: الإحصائيات ============
  Widget _statsTab() {
    return RefreshIndicator(
      onRefresh: c.loadDashboard,
      child: SingleChildScrollView(
        physics: const AlwaysScrollableScrollPhysics(),
        padding: const EdgeInsets.all(16),
        child: Obx(() {
          final d = c.dashboardData.value;
          if (d == null) return const Center(heightFactor: 8, child: CircularProgressIndicator());
          final totals = (d['totals'] ?? {}) as Map;
          final dist = (d['distribution'] ?? {}) as Map;
          final byPlan = (dist['by_plan'] ?? {}) as Map;
          final byBiz = (dist['by_business_type'] ?? {}) as Map;

          return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            _statBox('${totals['merchants'] ?? 0}', 'إجمالي التجار', AmyalColors.primary, Icons.store),
            const SizedBox(height: 8),
            Row(children: [
              Expanded(child: _statBox('${totals['pending_verification'] ?? 0}',
                  'قيد التوثيق', Colors.orange, Icons.pending_actions)),
              const SizedBox(width: 8),
              Expanded(child: _statBox('${totals['pending_variances'] ?? 0}',
                  'عجز/فائض', Colors.red, Icons.warning_amber)),
            ]),
            const SizedBox(height: 16),
            const Text('التوزيع على الخطط',
                style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold)),
            const SizedBox(height: 8),
            ...byPlan.entries.map((e) => _distRow(e.key.toString(), '${e.value}')),
            const SizedBox(height: 16),
            const Text('التوزيع على أنواع النشاط',
                style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold)),
            const SizedBox(height: 8),
            ...byBiz.entries.map((e) => _distRow(e.key.toString(), '${e.value}')),
          ]);
        }),
      ),
    );
  }

  Widget _statBox(String value, String label, Color color, IconData icon) => Container(
    padding: const EdgeInsets.all(14),
    decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(12)),
    child: Row(children: [
      Icon(icon, color: color, size: 32),
      const SizedBox(width: 12),
      Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Text(value, style: TextStyle(color: color, fontSize: 22, fontWeight: FontWeight.bold)),
        Text(label, style: TextStyle(color: Colors.grey.shade700, fontSize: 12)),
      ])),
    ]),
  );

  Widget _distRow(String label, String count) => Container(
    margin: const EdgeInsets.only(bottom: 4),
    padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
    decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(8)),
    child: Row(children: [
      Text(count, style: const TextStyle(fontWeight: FontWeight.bold, color: AmyalColors.primary)),
      const Spacer(),
      Text(label, style: const TextStyle(fontSize: 13)),
    ]),
  );
}
