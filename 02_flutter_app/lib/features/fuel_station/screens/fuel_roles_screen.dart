import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amial_pay/theme/amial_colors.dart';
import 'package:amial_pay/features/fuel_station/controllers/fuel_vertical_controller.dart';
import 'package:amial_pay/features/fuel_station/widgets/fuel_state_view.dart';

/// AMIAL-FUEL-VERTICAL-001 · المرحلة ٨ — الأدوارُ والصلاحيّات.
///
/// **والدورُ حزمةُ صلاحيّاتٍ لا اسمٌ في الشيفرة.** ولذلك تُعرض هنا
/// صلاحيّةً صلاحيّةً بنطاقها وحدّها — فمالكُ المحطّة يرى ما يمنحه فعلاً
/// لا كلمةً مثل «كاشير».
class FuelRolesScreen extends StatefulWidget {
  const FuelRolesScreen({super.key});

  @override
  State<FuelRolesScreen> createState() => _FuelRolesScreenState();
}

class _FuelRolesScreenState extends State<FuelRolesScreen> {
  late final FuelVerticalController c;

  @override
  void initState() {
    super.initState();
    c = Get.find<FuelVerticalController>();
    WidgetsBinding.instance.addPostFrameCallback((_) => c.loadRoles());
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(title: const Text('الأدوار والصلاحيات')),
      body: RefreshIndicator(
        onRefresh: c.loadRoles,
        child: FuelStateView(
          c: c,
          isEmpty: c.roles.isEmpty,
          emptyTitle: 'لا أدوار بعد',
          emptyHint: 'أنشئ الأدوار الستة القياسية ثم عدّلها كما تشاء.',
          emptyIcon: Icons.manage_accounts_outlined,
          onRetry: c.loadRoles,
          child: Obx(() => ListView(
                padding: const EdgeInsets.all(16),
                children: [
                  for (final r in c.roles) _roleCard(r),
                  const SizedBox(height: 80),
                ],
              )),
        ),
      ),
      floatingActionButton: Obx(() {
        // **يُعرض ما دامت الأدوار ناقصة** — ولا يُعرض بعد اكتمالها فيوهم
        // بأنّ الضغط يُنشئ شيئاً جديداً.
        if (!c.can('role.manage') || c.roles.length >= 6) {
          return const SizedBox.shrink();
        }

        return FloatingActionButton.extended(
          key: const Key('fuel-seed-roles'),
          onPressed: _seed,
          icon: const Icon(Icons.auto_awesome_rounded),
          label: const Text('إنشاء الأدوار الستة'),
        );
      }),
    );
  }

  Widget _roleCard(Map<String, dynamic> r) {
    final perms = List<Map<String, dynamic>>.from(
        (r['permissions'] ?? const []).map((e) => Map<String, dynamic>.from(e)));

    // **تُجمَّع بمجموعاتها** — أربعون صلاحيّةً في قائمةٍ مسطّحة لا تُقرأ.
    final grouped = <String, List<Map<String, dynamic>>>{};

    for (final p in perms) {
      final meta = c.catalogue['${p['code']}'];
      final group = (meta is Map ? meta['group'] : null) ?? 'أخرى';
      grouped.putIfAbsent('$group', () => []).add(p);
    }

    return Card(
      key: Key('fuel-role-${r['code']}'),
      child: ExpansionTile(
        leading: CircleAvatar(
          backgroundColor: AmialColors.primary.withValues(alpha: 0.1),
          child: Text('${perms.length}',
              style: TextStyle(color: AmialColors.primary, fontSize: 13)),
        ),
        title: Text('${r['name_ar']}',
            style: const TextStyle(fontWeight: FontWeight.bold)),
        subtitle: Text('${perms.length} صلاحية'
            '${r['is_system'] == true ? ' · دور قياسي' : ''}'),
        children: [
          for (final g in grouped.entries) ...[
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 8, 16, 4),
              child: Align(
                alignment: AlignmentDirectional.centerStart,
                child: Text(g.key,
                    style: TextStyle(
                        fontSize: 12,
                        fontWeight: FontWeight.bold,
                        color: AmialColors.textSecondary)),
              ),
            ),
            for (final p in g.value) _permRow(p),
          ],
          const SizedBox(height: 8),
        ],
      ),
    );
  }

  Widget _permRow(Map<String, dynamic> p) {
    final meta = c.catalogue['${p['code']}'];
    final name = (meta is Map ? meta['name'] : null) ?? '${p['code']}';
    final sensitive = meta is Map && meta['sensitive'] == true;

    final limit = p['max_amount'];
    final scope = '${p['scope_type']}';
    final approval = '${p['approval']}';

    final tags = <String>[
      if (scope != 'merchant') _scopeLabel(scope),
      if (limit != null) 'حتى $limit',
      if (approval != 'none') 'باعتماد ${_approvalLabel(approval)}',
    ];

    return Padding(
      padding: const EdgeInsets.fromLTRB(24, 3, 16, 3),
      child: Row(children: [
        Icon(sensitive ? Icons.shield_outlined : Icons.check_rounded,
            size: 15,
            color: sensitive ? Colors.orange.shade800 : Colors.green.shade700),
        const SizedBox(width: 8),
        Expanded(child: Text('$name', style: const TextStyle(fontSize: 13))),
        if (tags.isNotEmpty)
          Flexible(
            child: Text(tags.join(' · '),
                textAlign: TextAlign.end,
                style: TextStyle(fontSize: 11, color: AmialColors.textMuted)),
          ),
      ]),
    );
  }

  String _scopeLabel(String s) => switch (s) {
        'station' => 'محطته',
        'branch' => 'فرعه',
        'shift' => 'ورديته',
        'own' => 'ما يخصه',
        _ => s,
      };

  String _approvalLabel(String s) => switch (s) {
        'supervisor' => 'المشرف',
        'manager' => 'المدير',
        'owner' => 'المالك',
        _ => s,
      };

  Future<void> _seed() async {
    final ok = await Get.dialog<bool>(AlertDialog(
      title: const Text('إنشاء الأدوار الستة'),
      content: const Text(
        'مالك · مدير · محاسب · مشرف وردية · كاشير · موظف مخزون.\n\n'
        'تُنشأ مرة واحدة ثم تملكها أنت — تعديلاتك لن تُكتب فوقها لاحقاً.',
      ),
      actions: [
        TextButton(onPressed: () => Get.back(result: false), child: const Text('إلغاء')),
        ElevatedButton(
          key: const Key('fuel-seed-confirm'),
          onPressed: () => Get.back(result: true),
          child: const Text('أنشئ'),
        ),
      ],
    ));

    if (ok != true) return;

    final done = await c.seedRoles();

    if (!mounted) return;
    Get.snackbar(done ? 'تم' : 'تنبيه',
        done ? 'أُنشئت الأدوار — عدّلها كما تشاء' : c.lastError.value,
        backgroundColor: done ? Colors.green.shade50 : Colors.red.shade50,
        colorText: done ? Colors.green.shade900 : Colors.red.shade900,
        snackPosition: SnackPosition.BOTTOM);
  }
}
