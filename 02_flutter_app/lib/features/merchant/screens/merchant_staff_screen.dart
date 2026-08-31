import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amial_pay/data/api/api_client.dart';
import 'package:amial_pay/features/access/widgets/access_gate.dart';
import 'package:amial_pay/features/merchant/models/staff_roles.dart';
import 'package:amial_pay/features/merchant/screens/merchant_staff_performance_screen.dart';
import 'package:amial_pay/theme/amial_colors.dart';

/// AMIAL-MERCHANT-STAFF-001 — إدارة الموظفين وحساباتهم (باقة الأعمال فأعلى).
///
/// التاجر يضيف موظفاً برمز دخول + كلمة مرور + صلاحيات، ويفعّل/يعطّل.
/// موصولة بالخادم الحقيقي (/merchant/staff).
class MerchantStaffScreen extends StatefulWidget {
  const MerchantStaffScreen({super.key});

  @override
  State<MerchantStaffScreen> createState() => _MerchantStaffScreenState();
}

class _MerchantStaffScreenState extends State<MerchantStaffScreen> {
  final _api = Get.find<ApiClient>();
  bool _loading = true;
  List<Map<String, dynamic>> _staff = [];
  String? _error;


  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() { _loading = true; _error = null; });
    try {
      final r = await _api.getData('/api/v1/amial/merchant/staff');
      if (r.statusCode == 200 && r.body is Map && r.body['success'] == true) {
        final meta = (r.body['meta'] ?? {}) as Map;
        setState(() => _staff = ((meta['staff'] ?? []) as List)
            .map((e) => Map<String, dynamic>.from(e as Map))
            .toList());
      } else if (r.statusCode == 402) {
        _error = 'إدارة الموظفين متاحة في باقة الأعمال فأعلى';
      } else {
        _error = 'تعذّر تحميل الموظفين';
      }
    } catch (_) {
      _error = 'خطأ في الشبكة';
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _toggle(int id) async {
    final r = await _api.postData('/api/v1/amial/merchant/staff/$id/toggle', {});
    if (r.statusCode == 200) {
      _load();
    } else {
      _snack('تعذّر تغيير الحالة');
    }
  }

  void _snack(String m, {bool ok = false}) => ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(content: Text(m), backgroundColor: ok ? AmialColors.success : AmialColors.red));

  Future<void> _addDialog() async {
    final employeeCodeCtrl = TextEditingController();
    final nameCtrl = TextEditingController();
    final passCtrl = TextEditingController();
    // **الافتراضُ «كاشير»** — وهو نفسُ ما كانت عليه الشاشة (`{'sell'}`)،
    // فمن ضغط «إنشاء» بلا قراءةٍ يحصل على ما كان يحصل عليه بالضبط.
    var role = StaffRoles.defaultRole;
    final perms = <String>{...StaffRoles.permissions[StaffRoles.defaultRole]!};

    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => StatefulBuilder(builder: (ctx, setD) {
        return AlertDialog(
          title: const Text('موظف جديد'),
          content: SingleChildScrollView(
            child: Column(mainAxisSize: MainAxisSize.min, children: [
              TextField(controller: nameCtrl, decoration: const InputDecoration(
                  labelText: 'اسم الموظف', border: OutlineInputBorder())),
              const SizedBox(height: 10),
              TextField(controller: employeeCodeCtrl, decoration: const InputDecoration(
                  labelText: 'رمز الموظف', hintText: 'EMP-01', border: OutlineInputBorder())),
              const SizedBox(height: 10),
              TextField(controller: passCtrl, obscureText: true, decoration: const InputDecoration(
                  labelText: 'كلمة مرور الموظف', border: OutlineInputBorder())),
              const SizedBox(height: 14),

              // **سؤالٌ واحدٌ مكانَ خمسة.**
              DropdownButtonFormField<String>(
                initialValue: role,
                isExpanded: true,
                decoration: const InputDecoration(
                    labelText: 'الوظيفة', border: OutlineInputBorder()),
                items: StaffRoles.labels.entries
                    .map((e) => DropdownMenuItem(value: e.key, child: Text(e.value)))
                    .toList(),
                onChanged: (v) => setD(() {
                  role = v ?? StaffRoles.defaultRole;
                  // **و«مخصّص» يبدأ ممّا اختاره قبلَه** — لا من فراغٍ
                  // يجعله يبني الصلاحيّاتِ من الصفر.
                  final preset = StaffRoles.permissions[role];
                  if (preset != null) {
                    perms
                      ..clear()
                      ..addAll(preset);
                  }
                }),
              ),
              const SizedBox(height: 6),
              Align(
                alignment: Alignment.centerRight,
                child: Text(StaffRoles.says[role] ?? '',
                    style: TextStyle(fontSize: 12.5, color: AmialColors.textSecondary)),
              ),

              // المربّعاتُ الخمسةُ لا تختفي — تنتظر من يطلبها.
              if (role == StaffRoles.custom) ...[
                const SizedBox(height: 8),
                const Align(alignment: Alignment.centerRight,
                    child: Text('الصلاحيات:', style: TextStyle(fontWeight: FontWeight.bold))),
                ...StaffRoles.allPermissions.entries.map((e) => CheckboxListTile(
                      dense: true,
                      contentPadding: EdgeInsets.zero,
                      title: Text(e.value),
                      value: perms.contains(e.key),
                      onChanged: (v) => setD(() => v == true ? perms.add(e.key) : perms.remove(e.key)),
                    )),
              ],
            ]),
          ),
          actions: [
            TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('إلغاء')),
            FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('إنشاء')),
          ],
        );
      }),
    );
    if (ok != true || !mounted) return;

    if (nameCtrl.text.trim().isEmpty || employeeCodeCtrl.text.trim().isEmpty || passCtrl.text.length < 4) {
      _snack('أكمل البيانات (كلمة المرور 4 أحرف على الأقل)');
      return;
    }
    final r = await _api.postData('/api/v1/amial/merchant/staff', {
      'employee_code': employeeCodeCtrl.text.trim(),
      'display_name': nameCtrl.text.trim(),
      'password': passCtrl.text,
      'permissions': perms.toList(),
    });
    if (r.statusCode == 201) {
      _snack('تم إنشاء الموظف', ok: true);
      _load();
    } else {
      final msg = (r.body is Map ? r.body['message']?.toString() : null) ?? 'تعذّر الإنشاء';
      _snack(msg);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(
        title: const Text('الموظفون وحساباتهم'),
        actions: [
          IconButton(
            icon: const Icon(Icons.bar_chart),
            tooltip: 'الأداء',
            onPressed: () => Get.to(() => const MerchantStaffPerformanceScreen()),
          ),
        ],
      ),
      floatingActionButton: _error == null
          ? FloatingActionButton.extended(
              onPressed: _addDialog,
              backgroundColor: AmialColors.primary,
              icon: const Icon(Icons.person_add),
              label: const Text('موظف جديد'),
            )
          : null,
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : _error != null
              ? Center(child: Padding(
                  padding: const EdgeInsets.all(24),
                  child: Column(mainAxisSize: MainAxisSize.min, children: [
                    const Icon(Icons.workspace_premium, size: 56, color: AmialColors.yellowDark),
                    const SizedBox(height: 12),
                    Text(_error!, textAlign: TextAlign.center,
                        style: const TextStyle(fontSize: 15, fontWeight: FontWeight.w600)),
                  ]),
                ))
              : RefreshIndicator(
                  onRefresh: _load,
                  child: _staff.isEmpty
                      ? ListView(children: const [
                          SizedBox(height: 120),
                          Icon(Icons.group_outlined, size: 64, color: AmialColors.textMuted),
                          SizedBox(height: 12),
                          Center(child: Text('لا يوجد موظفون بعد — أضِف أول موظف')),
                        ])
                      : ListView.builder(
                          padding: const EdgeInsets.all(12),
                          itemCount: _staff.length,
                          itemBuilder: (_, i) => _staffCard(_staff[i]),
                        ),
                ),
    );
  }

  Widget _staffCard(Map<String, dynamic> s) {
    final active = s['is_active'] == true;
    final isOps = s['is_operations_manager'] == true;
    final isFin = s['is_financial_manager'] == true;
    final badge = isOps ? 'مدير عمليات' : (isFin ? 'مدير مالي' : null);
    final perms = ((s['permissions'] ?? []) as List)
        .where((p) => p != 'operations_manager' && p != 'financial_manager')
        .map((p) => StaffRoles.allPermissions[p] ?? '$p').join(' • ');
    return Container(
      margin: const EdgeInsets.only(bottom: 10),
      decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(14)),
      child: ListTile(
        leading: CircleAvatar(
          backgroundColor: (active ? AmialColors.primary : AmialColors.textMuted).withValues(alpha: 0.12),
          child: Icon((isOps || isFin) ? Icons.manage_accounts : Icons.badge,
              color: active ? AmialColors.primary : AmialColors.textMuted),
        ),
        title: Row(children: [
          Flexible(child: Text('${s['display_name'] ?? ''}',
              overflow: TextOverflow.ellipsis, style: const TextStyle(fontWeight: FontWeight.bold))),
          if (badge != null) ...[
            const SizedBox(width: 6),
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
              decoration: BoxDecoration(color: AmialColors.primary.withValues(alpha: 0.12),
                  borderRadius: BorderRadius.circular(6)),
              child: Text(badge, style: const TextStyle(fontSize: 9, color: AmialColors.primary, fontWeight: FontWeight.bold)),
            ),
          ],
        ]),
        subtitle: Text('رمز الموظف: ${s['employee_code'] ?? s['pos_number'] ?? ''}${perms.isEmpty ? '' : '  •  $perms'}',
            style: const TextStyle(fontSize: 11)),
        trailing: Row(mainAxisSize: MainAxisSize.min, children: [
          AccessGate(
            anyOf: const ['operations_manager', 'financial_manager'],
            child: PopupMenuButton<String>(
              icon: const Icon(Icons.admin_panel_settings_outlined, color: AmialColors.primary),
              tooltip: 'الأدوار الإدارية',
              onSelected: (v) {
                switch (v) {
                  case 'ops_on': _setRole(s['id'] as int, 'operations-manager', true);
                  case 'ops_off': _setRole(s['id'] as int, 'operations-manager', false);
                  case 'fin_on': _setRole(s['id'] as int, 'financial-manager', true);
                  case 'fin_off': _setRole(s['id'] as int, 'financial-manager', false);
                }
              },
              itemBuilder: (_) => [
                if (!isOps) const PopupMenuItem(value: 'ops_on', child: Text('تعيين مدير عمليات')),
                if (isOps) const PopupMenuItem(value: 'ops_off', child: Text('إلغاء مدير العمليات')),
                if (!isFin) const PopupMenuItem(value: 'fin_on', child: Text('تعيين مدير مالي')),
                if (isFin) const PopupMenuItem(value: 'fin_off', child: Text('إلغاء المدير المالي')),
              ],
            ),
          ),
          Switch(
            value: active,
            activeColor: AmialColors.primary,
            onChanged: (_) => _toggle(s['id'] as int),
          ),
        ]),
      ),
    );
  }

  Future<void> _setRole(int id, String role, bool enabled) async {
    final r = await _api.postData('/api/v1/amial/merchant/staff/$id/$role', {'enabled': enabled});
    if (r.statusCode == 200) {
      _snack(enabled ? 'تم التعيين' : 'تم الإلغاء', ok: true);
      _load();
    } else {
      _snack((r.body is Map ? r.body['message']?.toString() : null) ?? 'تعذّر التنفيذ');
    }
  }
}
