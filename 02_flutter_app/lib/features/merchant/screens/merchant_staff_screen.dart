import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/data/api/api_client.dart';
import 'package:amyal_pay/features/access/widgets/access_gate.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';

/// AMIAL-MERCHANT-STAFF-001 — إدارة موظفي نقاط البيع (باقة الأعمال فأعلى).
///
/// التاجر يضيف موظفاً برقم نقطة بيع + كلمة مرور + صلاحيات، ويفعّل/يعطّل.
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

  static const _allPerms = {
    'sell': 'البيع',
    'refund': 'المرتجعات',
    'products': 'المنتجات',
    'reports': 'التقارير',
    'credit': 'الآجل',
  };

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
      SnackBar(content: Text(m), backgroundColor: ok ? const Color(0xFF2E7D32) : AmyalColors.red));

  Future<void> _addDialog() async {
    final posCtrl = TextEditingController();
    final nameCtrl = TextEditingController();
    final passCtrl = TextEditingController();
    final perms = <String>{'sell'};

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
              TextField(controller: posCtrl, decoration: const InputDecoration(
                  labelText: 'رقم نقطة البيع (POS)', hintText: 'POS-01', border: OutlineInputBorder())),
              const SizedBox(height: 10),
              TextField(controller: passCtrl, obscureText: true, decoration: const InputDecoration(
                  labelText: 'كلمة مرور الموظف', border: OutlineInputBorder())),
              const SizedBox(height: 12),
              const Align(alignment: Alignment.centerRight,
                  child: Text('الصلاحيات:', style: TextStyle(fontWeight: FontWeight.bold))),
              ..._allPerms.entries.map((e) => CheckboxListTile(
                    dense: true,
                    contentPadding: EdgeInsets.zero,
                    title: Text(e.value),
                    value: perms.contains(e.key),
                    onChanged: (v) => setD(() => v == true ? perms.add(e.key) : perms.remove(e.key)),
                  )),
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

    if (nameCtrl.text.trim().isEmpty || posCtrl.text.trim().isEmpty || passCtrl.text.length < 4) {
      _snack('أكمل البيانات (كلمة المرور 4 أحرف على الأقل)');
      return;
    }
    final r = await _api.postData('/api/v1/amial/merchant/staff', {
      'pos_number': posCtrl.text.trim(),
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
      backgroundColor: AmyalColors.background,
      appBar: AppBar(
        title: const Text('الموظفون'),
        backgroundColor: AmyalColors.primary,
        foregroundColor: Colors.white,
      ),
      floatingActionButton: _error == null
          ? FloatingActionButton.extended(
              onPressed: _addDialog,
              backgroundColor: AmyalColors.primary,
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
                    const Icon(Icons.workspace_premium, size: 56, color: AmyalColors.yellowDark),
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
                          Icon(Icons.group_outlined, size: 64, color: AmyalColors.textMuted),
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
        .map((p) => _allPerms[p] ?? '$p').join(' • ');
    return Container(
      margin: const EdgeInsets.only(bottom: 10),
      decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(14)),
      child: ListTile(
        leading: CircleAvatar(
          backgroundColor: (active ? AmyalColors.primary : AmyalColors.textMuted).withValues(alpha: 0.12),
          child: Icon((isOps || isFin) ? Icons.manage_accounts : Icons.badge,
              color: active ? AmyalColors.primary : AmyalColors.textMuted),
        ),
        title: Row(children: [
          Flexible(child: Text('${s['display_name'] ?? ''}',
              overflow: TextOverflow.ellipsis, style: const TextStyle(fontWeight: FontWeight.bold))),
          if (badge != null) ...[
            const SizedBox(width: 6),
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
              decoration: BoxDecoration(color: AmyalColors.primary.withValues(alpha: 0.12),
                  borderRadius: BorderRadius.circular(6)),
              child: Text(badge, style: const TextStyle(fontSize: 9, color: AmyalColors.primary, fontWeight: FontWeight.bold)),
            ),
          ],
        ]),
        subtitle: Text('POS: ${s['pos_number']}${perms.isEmpty ? '' : '  •  $perms'}',
            style: const TextStyle(fontSize: 11)),
        trailing: Row(mainAxisSize: MainAxisSize.min, children: [
          AccessGate(
            anyOf: const ['operations_manager', 'financial_manager'],
            child: PopupMenuButton<String>(
              icon: const Icon(Icons.admin_panel_settings_outlined, color: AmyalColors.primary),
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
            activeColor: AmyalColors.primary,
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
