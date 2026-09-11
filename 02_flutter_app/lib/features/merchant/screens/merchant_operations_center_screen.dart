import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amial_pay/data/api/api_client.dart';
import 'package:amial_pay/features/access/controllers/access_controller.dart';
import 'package:amial_pay/features/plans/screens/plans_catalog_screen.dart';
import 'package:amial_pay/features/merchant/screens/merchant_audit_log_screen.dart';
import 'package:amial_pay/theme/amial_colors.dart';

/// مدخل المالك الواحد للدور والموظف والجهاز والوردية.
///
/// لا يحتفظ هذا الملف بحقيقةٍ تشغيلية محلية؛ البيانات والإجراءات جميعها
/// من `/merchant/operations-center` والخدمات القائمة للموظفين والأجهزة.
class MerchantOperationsCenterScreen extends StatefulWidget {
  const MerchantOperationsCenterScreen({super.key});

  @override
  State<MerchantOperationsCenterScreen> createState() =>
      _MerchantOperationsCenterScreenState();
}

class _MerchantOperationsCenterScreenState
    extends State<MerchantOperationsCenterScreen>
    with SingleTickerProviderStateMixin {
  static const _base = '/api/v1/amial/merchant/operations-center';

  final _api = Get.find<ApiClient>();
  late final TabController _tabs;

  bool _loading = true;
  bool _loadingRequest = false;
  bool _saving = false;
  String? _error;
  Map<String, String> _sectionErrors = {};
  Map<String, int?> _sectionStatuses = {};
  Map<String, dynamic> _summary = {};
  List<Map<String, dynamic>> _roles = [];
  List<Map<String, dynamic>> _permissionCatalogue = [];
  List<Map<String, dynamic>> _staff = [];
  List<Map<String, dynamic>> _devices = [];
  List<Map<String, dynamic>> _branches = [];

  @override
  void initState() {
    super.initState();
    _tabs = TabController(length: 6, vsync: this);
    _load();
  }

  @override
  void dispose() {
    _tabs.dispose();
    super.dispose();
  }

  Map<String, dynamic> _payload(dynamic body) {
    if (body is! Map) return {};
    final raw = body['meta'] ?? body['data'] ?? body;
    return raw is Map ? Map<String, dynamic>.from(raw) : {};
  }

  List<Map<String, dynamic>> _rows(dynamic value) => value is List
      ? value.whereType<Map>().map((row) => Map<String, dynamic>.from(row)).toList()
      : <Map<String, dynamic>>[];

  int _number(dynamic value) => value is num ? value.toInt() : int.tryParse('$value') ?? 0;

  Future<void> _load() async {
    if (!mounted || _loadingRequest || _saving) return;
    _loadingRequest = true;
    setState(() {
      _loading = true;
      _error = null;
    });

    try {
      // Branch management is plan-gated. Do not send an inapplicable request
      // for lower plans; employee/device creation still uses server defaults.
      final canSelectBranches = Get.find<AccessController>().has('branches');
      final replies = await Future.wait([
        _api.getData(_base),
        _api.getData('$_base/roles'),
        _api.getData('/api/v1/amial/merchant/staff'),
        _api.getData('/api/v1/amial/merchant/pos-devices'),
        if (canSelectBranches) _api.getData('/api/v1/amial/merchant/branches'),
      ]);
      if (!mounted) return;

      final summaryReply = replies[0];
      if (summaryReply.statusCode != 200 || summaryReply.body is! Map ||
          summaryReply.body['success'] != true) {
        setState(() => _error = _message(summaryReply.body) ??
            'تعذّر فتح مركز تشغيل المنشأة');
        return;
      }

      final summary = _payload(summaryReply.body);
      final counts = summary['counts'];
      const requiredCounts = ['active_employees', 'active_device_sessions', 'open_shifts'];
      if (counts is! Map || summary['open_shifts'] is! List ||
          requiredCounts.any((key) => counts[key] is! num || counts[key] < 0)) {
        setState(() => _error = 'تعذّر قراءة ملخص التشغيل؛ أعد المحاولة');
        return;
      }
      final sectionErrors = <String, String>{};
      final sectionStatuses = <String, int?>{};
      final sections = ['roles', 'staff', 'devices', if (canSelectBranches) 'branches'];
      final labels = ['الأدوار', 'الموظفين', 'الأجهزة', if (canSelectBranches) 'الفروع'];
      for (var i = 0; i < sections.length; i++) {
        final reply = replies[i + 1];
        if (reply.statusCode != 200 || reply.body is! Map ||
            reply.body['success'] != true ||
            _payload(reply.body)[sections[i]] is! List ||
            (sections[i] == 'roles' && _payload(reply.body)['permission_catalogue'] is! List)) {
          sectionStatuses[sections[i]] = reply.statusCode;
          sectionErrors[sections[i]] = [402, 403].contains(reply.statusCode)
              ? (_message(reply.body) ?? 'هذه الخدمة غير متاحة لهذا الحساب')
              : 'تعذّر تحميل ${labels[i]}؛ أعد المحاولة';
        }
      }
      final rolesPayload = _payload(replies[1].body);
      final staffPayload = _payload(replies[2].body);
      final devicesPayload = _payload(replies[3].body);
      final branchesPayload = canSelectBranches ? _payload(replies[4].body) : <String, dynamic>{};

      setState(() {
        _summary = summary;
        _sectionErrors = sectionErrors;
        _sectionStatuses = sectionStatuses;
        _roles = _rows(rolesPayload['roles']);
        _permissionCatalogue = _rows(rolesPayload['permission_catalogue']);
        _staff = _rows(staffPayload['staff']);
        _devices = _rows(devicesPayload['devices']);
        _branches = _rows(branchesPayload['branches']);
      });
    } catch (_) {
      if (mounted) setState(() => _error = 'تعذّر الاتصال بالخدمة');
    } finally {
      _loadingRequest = false;
      if (mounted) setState(() => _loading = false);
    }
  }

  String? _message(dynamic body) => body is Map ? body['message']?.toString() : null;

  void _notice(String message, {bool success = false}) {
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
      content: Text(message),
      backgroundColor: success ? AmialColors.success : AmialColors.red,
    ));
  }

  // A 200 response with success:false is a rejection, not a completed action.
  Future<Map<String, dynamic>?> _post(
      String endpoint, Map<String, dynamic> payload, String failure) async {
    if (_saving) return null;
    setState(() => _saving = true);
    try {
      final response = await _api.postData(endpoint, payload);
      if (!mounted) return null;
      if ((response.statusCode != 200 && response.statusCode != 201) ||
          response.body is! Map || response.body['success'] != true) {
        _notice(_message(response.body) ?? failure);
        return null;
      }
      return _payload(response.body);
    } catch (_) {
      if (mounted) _notice(failure);
      return null;
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  bool _requiresReload(List<String> sections) {
    if (_saving) return true;
    for (final section in sections) {
      final error = _sectionErrors[section];
      if (error != null) {
        _notice(error);
        return true;
      }
    }
    return false;
  }

  Future<bool?> _showForm({
    required List<TextEditingController> controllers,
    required WidgetBuilder builder,
  }) {
    final route = DialogRoute<bool>(context: context, builder: builder);
    // The pop result arrives before the closing animation finishes. Keep
    // controllers alive until the dialog's widgets have left the overlay.
    route.completed.then((_) {
      for (final controller in controllers) {
        controller.dispose();
      }
    });
    return Navigator.of(context, rootNavigator: true).push(route);
  }

  Future<void> _createRole() async {
    if (_requiresReload(['roles'])) return;
    final name = TextEditingController();
    final description = TextEditingController();
    final chosen = <String>{};
    final groups = <String, List<Map<String, dynamic>>>{};
    for (final permission in _permissionCatalogue) {
      final group = '${permission['group'] ?? 'أخرى'}';
      groups.putIfAbsent(group, () => []).add(permission);
    }

    final confirmed = await _showForm(
      controllers: [name, description],
      builder: (dialogContext) => StatefulBuilder(
        builder: (_, setDialog) => AlertDialog(
          title: const Text('إنشاء دور تشغيلي'),
          content: SizedBox(
            width: double.maxFinite,
            child: SingleChildScrollView(
              child: Column(mainAxisSize: MainAxisSize.min, children: [
                TextField(
                  controller: name,
                  decoration: const InputDecoration(
                    labelText: 'اسم الدور',
                    hintText: 'كاشير',
                    border: OutlineInputBorder(),
                  ),
                ),
                const SizedBox(height: 10),
                TextField(
                  controller: description,
                  maxLines: 2,
                  decoration: const InputDecoration(
                    labelText: 'وصف مختصر (اختياري)',
                    border: OutlineInputBorder(),
                  ),
                ),
                const SizedBox(height: 14),
                const Align(
                  alignment: AlignmentDirectional.centerStart,
                  child: Text('الأفعال المسموحة',
                      style: TextStyle(fontWeight: FontWeight.bold)),
                ),
                const SizedBox(height: 6),
                ...groups.entries.map((entry) => ExpansionTile(
                  title: Text(entry.key),
                  children: entry.value.map((permission) {
                    final code = '${permission['code']}';
                    return CheckboxListTile(
                      dense: true,
                      contentPadding: EdgeInsets.zero,
                      value: chosen.contains(code),
                      title: Text('${permission['name'] ?? code}'),
                      onChanged: (value) => setDialog(() {
                        if (value == true) {
                          chosen.add(code);
                        } else {
                          chosen.remove(code);
                        }
                      }),
                    );
                  }).toList(),
                )),
              ]),
            ),
          ),
          actions: [
            TextButton(onPressed: () => Navigator.pop(dialogContext, false),
                child: const Text('إلغاء')),
            FilledButton(onPressed: () => Navigator.pop(dialogContext, true),
                child: const Text('إنشاء الدور')),
          ],
        ),
      ),
    );
    final roleName = name.text.trim();
    final roleDescription = description.text.trim();
    if (confirmed != true || !mounted) return;
    if (roleName.isEmpty || chosen.isEmpty) {
      _notice('اكتب اسم الدور واختر فعلاً واحداً على الأقل');
      return;
    }

    final data = await _post('$_base/roles', {
      'name_ar': roleName,
      if (roleDescription.isNotEmpty) 'description_ar': roleDescription,
      'permissions': chosen.toList(),
    }, 'تعذّر إنشاء الدور');
    if (!mounted || data == null) return;
    if (data['role'] is Map && data['role']['id'] != null) {
      _notice('تم إنشاء الدور', success: true);
      _load();
    } else {
      _notice('لم يصل تأكيد إنشاء الدور؛ حدّث القائمة قبل المحاولة مجدداً');
    }
  }

  Future<void> _addEmployee() async {
    if (_requiresReload(['roles', 'staff', 'branches'])) return;
    final activeRoles = _roles.where((role) => role['is_active'] == true).toList();
    if (activeRoles.isEmpty) {
      _tabs.animateTo(1);
      _notice('أنشئ دوراً أولاً ثم أضف الموظف');
      return;
    }
    final name = TextEditingController();
    final code = TextEditingController();
    final password = TextEditingController();
    final activeBranches = _branches.where((branch) => branch['is_active'] != false).toList();
    int? roleId = _number(activeRoles.first['id']);
    int? branchId = activeBranches.isEmpty ? null : _number(activeBranches.first['id']);

    final confirmed = await _showForm(
      controllers: [name, code, password],
      builder: (dialogContext) => StatefulBuilder(
        builder: (_, setDialog) => AlertDialog(
          title: const Text('إضافة موظف للدور'),
          content: SingleChildScrollView(child: Column(mainAxisSize: MainAxisSize.min, children: [
            TextField(controller: name,
                decoration: const InputDecoration(labelText: 'اسم الموظف', border: OutlineInputBorder())),
            const SizedBox(height: 10),
            TextField(controller: code,
                decoration: const InputDecoration(labelText: 'رمز الموظف', hintText: 'CAS-01', border: OutlineInputBorder())),
            const SizedBox(height: 10),
            TextField(controller: password, obscureText: true,
                decoration: const InputDecoration(labelText: 'كلمة المرور', border: OutlineInputBorder())),
            const SizedBox(height: 10),
            DropdownButtonFormField<int>(
              initialValue: roleId,
              isExpanded: true,
              decoration: const InputDecoration(labelText: 'الدور', border: OutlineInputBorder()),
              items: activeRoles.map((role) => DropdownMenuItem<int>(
                value: _number(role['id']), child: Text('${role['name_ar'] ?? ''}'))).toList(),
              onChanged: (value) => setDialog(() => roleId = value),
            ),
            if (activeBranches.isNotEmpty) ...[
              const SizedBox(height: 10),
              DropdownButtonFormField<int>(
                initialValue: branchId,
                isExpanded: true,
                decoration: const InputDecoration(labelText: 'فرع العمل', border: OutlineInputBorder()),
                items: activeBranches
                    .map((branch) => DropdownMenuItem<int>(
                      value: _number(branch['id']), child: Text('${branch['name'] ?? ''}'))).toList(),
                onChanged: (value) => setDialog(() => branchId = value),
              ),
            ],
          ])),
          actions: [
            TextButton(onPressed: () => Navigator.pop(dialogContext, false), child: const Text('إلغاء')),
            FilledButton(onPressed: () => Navigator.pop(dialogContext, true), child: const Text('إنشاء الموظف')),
          ],
        ),
      ),
    );
    final employeeName = name.text.trim();
    final employeeCode = code.text.trim();
    final employeePassword = password.text;
    if (confirmed != true || !mounted) return;
    if (employeeName.isEmpty || employeeCode.isEmpty || employeePassword.length < 4 || roleId == null) {
      _notice('أكمل بيانات الموظف والدور');
      return;
    }

    final data = await _post('/api/v1/amial/merchant/staff', {
      'display_name': employeeName,
      'employee_code': employeeCode,
      'password': employeePassword,
      'merchant_role_id': roleId,
      if (branchId != null) 'branch_id': branchId,
    }, 'تعذّر إنشاء الموظف');
    if (!mounted || data == null) return;
    if (data['id'] != null) {
      _notice('تم إنشاء الموظف وربطه بالدور', success: true);
      _load();
    } else {
      _notice('لم يصل تأكيد إنشاء الموظف؛ حدّث القائمة قبل المحاولة مجدداً');
    }
  }

  Future<void> _createDeviceActivationCode() async {
    if (_requiresReload(['devices', 'branches'])) return;
    final name = TextEditingController();
    final activeBranches = _branches.where((branch) => branch['is_active'] != false).toList();
    int? branchId = activeBranches.isEmpty ? null : _number(activeBranches.first['id']);
    final confirmed = await _showForm(
      controllers: [name],
      builder: (dialogContext) => StatefulBuilder(
        builder: (_, setDialog) => AlertDialog(
          title: const Text('تفعيل جهاز نقطة بيع'),
          content: Column(mainAxisSize: MainAxisSize.min, children: [
            const Text('أنشئ رمزاً مؤقتاً ثم أدخله على جهاز الكاشير نفسه. لا يصبح الجهاز مقعداً نشطاً قبل تفعيله.'),
            const SizedBox(height: 12),
            TextField(controller: name,
                decoration: const InputDecoration(labelText: 'اسم الجهاز', hintText: 'كاشير الواجهة', border: OutlineInputBorder())),
            if (activeBranches.isNotEmpty) ...[
              const SizedBox(height: 10),
              DropdownButtonFormField<int>(
                initialValue: branchId,
                isExpanded: true,
                decoration: const InputDecoration(labelText: 'فرع الجهاز', border: OutlineInputBorder()),
                items: activeBranches
                    .map((branch) => DropdownMenuItem<int>(
                      value: _number(branch['id']), child: Text('${branch['name'] ?? ''}'))).toList(),
                onChanged: (value) => setDialog(() => branchId = value),
              ),
            ],
          ]),
          actions: [
            TextButton(onPressed: () => Navigator.pop(dialogContext, false), child: const Text('إلغاء')),
            FilledButton(onPressed: () => Navigator.pop(dialogContext, true), child: const Text('إنشاء الرمز')),
          ],
        ),
      ),
    );
    final deviceName = name.text.trim();
    if (confirmed != true || !mounted) return;
    if (deviceName.isEmpty) {
      _notice('اكتب اسماً يميز الجهاز');
      return;
    }

    final data = await _post('/api/v1/amial/merchant/pos-devices/activation-codes', {
      'display_name': deviceName,
      if (branchId != null) 'branch_id': branchId,
    }, 'تعذّر إنشاء رمز التفعيل');
    if (!mounted || data == null) return;
    final activationCode = '${data['activation_code'] ?? ''}';
    if (activationCode.isNotEmpty) {
      await showDialog<void>(
        context: context,
        barrierDismissible: false,
        builder: (dialogContext) => AlertDialog(
          title: const Text('رمز تفعيل الجهاز'),
          content: Column(mainAxisSize: MainAxisSize.min, children: [
            const Text('من جهاز الكاشير افتح التطبيق ثم اختر «تفعيل جهاز نقطة البيع» وأدخل الرمز خلال 15 دقيقة.'),
            const SizedBox(height: 16),
            SelectableText(activationCode,
                style: const TextStyle(fontSize: 26, letterSpacing: 3, fontWeight: FontWeight.bold, color: AmialColors.primary)),
          ]),
          actions: [FilledButton(onPressed: () => Navigator.pop(dialogContext), child: const Text('تم'))],
        ),
      );
      _load();
    } else {
      _notice('لم يصل رمز التفعيل؛ حدّث البيانات قبل المحاولة مجدداً');
    }
  }

  @override
  Widget build(BuildContext context) {
    return Directionality(
      textDirection: TextDirection.rtl,
      child: Scaffold(
        backgroundColor: AmialColors.background,
        appBar: AppBar(
          title: const Text('مركز تشغيل المنشأة'),
          actions: [IconButton(onPressed: _saving || _loading ? null : _load, tooltip: 'تحديث', icon: const Icon(Icons.refresh))],
          bottom: TabBar(
            controller: _tabs,
            isScrollable: true,
            tabs: const [
              Tab(text: 'نظرة عامة'), Tab(text: 'الأدوار'), Tab(text: 'الموظفون'),
              Tab(text: 'الأجهزة'), Tab(text: 'الورديات'), Tab(text: 'السجل'),
            ],
          ),
        ),
        bottomNavigationBar: _saving ? const LinearProgressIndicator() : null,
        body: _loading
            ? const Center(child: CircularProgressIndicator())
            : _error != null
                ? _failure()
                : TabBarView(controller: _tabs, children: [
                    _overview(), _rolesTab(), _staffTab(), _devicesTab(), _shiftsTab(), _auditTab(),
                  ]),
      ),
    );
  }

  Widget _failure() => Center(child: Padding(
    padding: const EdgeInsets.all(24),
    child: Column(mainAxisSize: MainAxisSize.min, children: [
      const Icon(Icons.lock_outline, size: 54, color: AmialColors.yellowDark),
      const SizedBox(height: 12), Text(_error!, textAlign: TextAlign.center), const SizedBox(height: 14),
      OutlinedButton.icon(onPressed: _load, icon: const Icon(Icons.refresh), label: const Text('إعادة المحاولة')),
    ]),
  ));

  Widget _overview() {
    final counts = _summary['counts'] is Map ? Map<String, dynamic>.from(_summary['counts']) : <String, dynamic>{};
    final setup = _summary['setup'] is Map ? Map<String, dynamic>.from(_summary['setup']) : <String, dynamic>{};
    return RefreshIndicator(onRefresh: _load, child: ListView(padding: const EdgeInsets.all(16), children: [
      _hero(counts), const SizedBox(height: 16),
      if (_sectionErrors.isNotEmpty) ...[
        Text(_sectionErrors.values.join('\n'), style: const TextStyle(color: AmialColors.red)),
        const SizedBox(height: 12),
      ],
      const Text('مسار إعداد الكاشير', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 16)),
      const SizedBox(height: 8),
      _workflowStep('1', 'أنشئ الدور', 'حدد أفعال الكاشير بدقة', setup['has_role'] == true, () => _tabs.animateTo(1)),
      _workflowStep('2', 'أضف الموظف', 'اربطه بالدور والفرع', setup['has_employee'] == true, () => _tabs.animateTo(2)),
      _workflowStep('3', 'فعّل الجهاز', 'الجهاز مورد مستقل عن الموظف', setup['has_device'] == true, () => _tabs.animateTo(3)),
      _workflowStep('4', 'ابدأ الوردية', 'يفتحها الموظف من جهاز مفعّل قبل البيع', _number(counts['open_shifts']) > 0, () => _tabs.animateTo(4)),
      const SizedBox(height: 14),
      FilledButton.icon(onPressed: _saving ? null : _addEmployee, icon: const Icon(Icons.person_add),
          label: const Text('إضافة عضو تشغيل'), style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(50))),
    ]));
  }

  Widget _hero(Map<String, dynamic> counts) => Container(
    padding: const EdgeInsets.all(18),
    decoration: BoxDecoration(color: AmialColors.primary, borderRadius: BorderRadius.circular(18)),
    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      const Text('تشغيل منشأتك من مكان واحد', style: TextStyle(color: Colors.white, fontSize: 18, fontWeight: FontWeight.bold)),
      const SizedBox(height: 6),
      const Text('الدور ← الموظف ← الجهاز ← الوردية ← البيع', style: TextStyle(color: Colors.white70)),
      const SizedBox(height: 16),
      Wrap(spacing: 8, runSpacing: 8, children: [
        _countBadge(Icons.badge_outlined, '${_number(counts['active_employees'])} موظف نشط'),
        _countBadge(Icons.point_of_sale, '${_number(counts['active_device_sessions'])} جلسة جهاز نشطة'),
        _countBadge(Icons.schedule, '${_number(counts['open_shifts'])} وردية مفتوحة'),
      ]),
    ]),
  );

  Widget _countBadge(IconData icon, String label) => Container(
    padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 7),
    decoration: BoxDecoration(color: Colors.white.withValues(alpha: .14), borderRadius: BorderRadius.circular(10)),
    child: Row(mainAxisSize: MainAxisSize.min, children: [Icon(icon, color: Colors.white, size: 16), const SizedBox(width: 5), Text(label, style: const TextStyle(color: Colors.white, fontSize: 12))]),
  );

  Widget _workflowStep(String number, String title, String detail, bool completed, VoidCallback onTap) => Card(
    child: ListTile(
      onTap: onTap,
      leading: CircleAvatar(backgroundColor: (completed ? AmialColors.success : AmialColors.primary).withValues(alpha: .14),
          child: completed ? const Icon(Icons.check, color: AmialColors.success) : Text(number, style: const TextStyle(color: AmialColors.primary, fontWeight: FontWeight.bold))),
      title: Text(title, style: const TextStyle(fontWeight: FontWeight.bold)), subtitle: Text(detail),
      trailing: const Icon(Icons.chevron_left),
    ),
  );

  Widget _rolesTab() => _sectionErrors.containsKey('roles') ? _sectionFailure('roles') : RefreshIndicator(onRefresh: _load, child: ListView(padding: const EdgeInsets.all(16), children: [
    Row(children: [const Expanded(child: Text('الأدوار الفعلية', style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold))), FilledButton.icon(onPressed: _saving ? null : _createRole, icon: const Icon(Icons.add), label: const Text('دور جديد'))]),
    const SizedBox(height: 8),
    if (_roles.isEmpty) _empty(Icons.admin_panel_settings_outlined, 'لا توجد أدوار بعد', 'أنشئ دور الكاشير قبل إضافة موظف.'),
    ..._roles.map((role) => Card(child: ListTile(
      leading: const CircleAvatar(child: Icon(Icons.badge_outlined)),
      title: Text('${role['name_ar'] ?? ''}', style: const TextStyle(fontWeight: FontWeight.bold)),
      subtitle: Text('${_number(role['permissions_count'])} صلاحية • ${_number(role['assignments_count'])} موظف'),
      trailing: role['is_system'] == true ? const Chip(label: Text('نظامي', style: TextStyle(fontSize: 10))) : null,
    ))),
  ]));

  Widget _staffTab() => _sectionErrors.containsKey('staff') ? _sectionFailure('staff') : RefreshIndicator(onRefresh: _load, child: ListView(padding: const EdgeInsets.all(16), children: [
    Row(children: [const Expanded(child: Text('الموظفون وحساباتهم', style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold))), FilledButton.icon(onPressed: _saving ? null : _addEmployee, icon: const Icon(Icons.person_add), label: const Text('موظف جديد'))]),
    const SizedBox(height: 8),
    if (_staff.isEmpty) _empty(Icons.group_outlined, 'لا يوجد موظفون بعد', 'أضف موظفاً بعد اختيار دوره.'),
    ..._staff.map((staff) {
      final active = staff['is_active'] == true;
      return Card(child: ListTile(
        leading: CircleAvatar(backgroundColor: (active ? AmialColors.success : AmialColors.textMuted).withValues(alpha: .14), child: Icon(Icons.person, color: active ? AmialColors.success : AmialColors.textMuted)),
        title: Text('${staff['display_name'] ?? ''}', style: const TextStyle(fontWeight: FontWeight.bold)),
        subtitle: Text('رمز: ${staff['employee_code'] ?? '—'} • ${staff['branch_name'] ?? 'الفرع الافتراضي'}'),
        trailing: Text(active ? 'نشط' : 'معطّل', style: TextStyle(color: active ? AmialColors.success : AmialColors.red, fontSize: 12)),
      ));
    }),
  ]));

  Widget _devicesTab() => _sectionErrors.containsKey('devices') ? _sectionFailure('devices') : RefreshIndicator(onRefresh: _load, child: ListView(padding: const EdgeInsets.all(16), children: [
    Row(children: [const Expanded(child: Text('أجهزة نقطة البيع', style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold))), FilledButton.icon(onPressed: _saving ? null : _createDeviceActivationCode, icon: const Icon(Icons.qr_code_2), label: const Text('رمز تفعيل'))]),
    const SizedBox(height: 8),
    const Text('الجهاز ليس حساب موظف؛ يفعّله المالك ثم يدخل الموظفون بحساباتهم.', style: TextStyle(color: AmialColors.textSecondary, fontSize: 12)),
    const SizedBox(height: 8),
    if (_devices.isEmpty) _empty(Icons.point_of_sale_outlined, 'لا توجد أجهزة مفعّلة', 'أنشئ رمز تفعيل وأدخله من جهاز الكاشير.'),
    ..._devices.map((device) => Card(child: ListTile(
      leading: const CircleAvatar(child: Icon(Icons.point_of_sale)),
      title: Text('${device['display_name'] ?? 'جهاز نقطة بيع'}', style: const TextStyle(fontWeight: FontWeight.bold)),
      subtitle: Text('${device['branch_name'] ?? 'الفرع الافتراضي'} • •••${device['hint'] ?? ''}'),
      trailing: Text(device['is_active'] == true ? 'نشط' : 'موقوف', style: TextStyle(color: device['is_active'] == true ? AmialColors.success : AmialColors.red, fontSize: 12)),
    ))),
  ]));

  Widget _shiftsTab() {
    final shifts = _rows(_summary['open_shifts']);
    return RefreshIndicator(onRefresh: _load, child: ListView(padding: const EdgeInsets.all(16), children: [
      const Text('الورديات المفتوحة', style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold)),
      const SizedBox(height: 6),
      const Text('لا ينشئ المالك وردية دائمة. الموظف يفتحها من جهاز مفعّل قبل أول مبيعة، فتُربط به وبالجهاز والفرع.', style: TextStyle(color: AmialColors.textSecondary, fontSize: 12)),
      const SizedBox(height: 12),
      if (shifts.isEmpty) _empty(Icons.schedule_outlined, 'لا توجد وردية مفتوحة', 'هذه حالة تشغيل وليست خطأ. تظهر الوردية هنا فور فتحها من جهاز مفعّل.'),
      if (_summary['open_shifts_has_more'] == true)
        const Text('تُعرض أحدث 12 وردية؛ العدد الإجمالي موضح في النظرة العامة.'),
      ...shifts.map((shift) => Card(child: ListTile(
        leading: const CircleAvatar(backgroundColor: Color(0xFFE8F5E9), child: Icon(Icons.play_circle, color: AmialColors.success)),
        title: Text('${shift['opened_by_name'] ?? 'موظف'}', style: const TextStyle(fontWeight: FontWeight.bold)),
        subtitle: Text('${shift['employee_code'] ?? ''} • ${shift['branch_name'] ?? 'الفرع الافتراضي'}'),
        trailing: const Text('مفتوحة', style: TextStyle(color: AmialColors.success, fontSize: 12)),
      ))),
    ]));
  }

  Widget _auditTab() => ListView(padding: const EdgeInsets.all(16), children: [
    const Icon(Icons.fact_check_outlined, size: 56, color: AmialColors.primary), const SizedBox(height: 12),
    const Text('سجل تشغيل المنشأة', textAlign: TextAlign.center, style: TextStyle(fontSize: 17, fontWeight: FontWeight.bold)),
    const SizedBox(height: 6), const Text('راجع الأحداث التي سجّلها الخادم مع المنفذ والوقت. إتاحة السجل تخضع لباقتك وصلاحيات حسابك.', textAlign: TextAlign.center, style: TextStyle(color: AmialColors.textSecondary)),
    const SizedBox(height: 18), FilledButton.icon(onPressed: () => Get.to(() => const MerchantAuditLogScreen()), icon: const Icon(Icons.open_in_new), label: const Text('فتح سجل التدقيق')),
  ]);

  Widget _empty(IconData icon, String title, String detail) => Padding(
    padding: const EdgeInsets.symmetric(vertical: 52),
    child: Column(children: [Icon(icon, size: 58, color: AmialColors.textMuted), const SizedBox(height: 12), Text(title, style: const TextStyle(fontWeight: FontWeight.bold)), const SizedBox(height: 5), Text(detail, textAlign: TextAlign.center, style: const TextStyle(color: AmialColors.textSecondary))]),
  );

  Widget _sectionFailure(String section) => Center(child: Padding(
    padding: const EdgeInsets.all(24),
    child: Column(mainAxisSize: MainAxisSize.min, children: [
      Text(_sectionErrors[section]!, textAlign: TextAlign.center),
      const SizedBox(height: 12),
      if (_sectionStatuses[section] == 402)
        OutlinedButton(onPressed: () => Get.to(() => const PlansCatalogScreen()),
            child: const Text('عرض الباقات'))
      else if (_sectionStatuses[section] != 403)
        OutlinedButton.icon(onPressed: _saving ? null : _load,
            icon: const Icon(Icons.refresh), label: const Text('إعادة المحاولة')),
    ]),
  ));
}
