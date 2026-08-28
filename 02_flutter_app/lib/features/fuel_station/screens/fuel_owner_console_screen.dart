import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amial_pay/theme/amial_colors.dart';
import 'package:amial_pay/features/fuel_station/controllers/fuel_vertical_controller.dart';
import 'package:amial_pay/features/fuel_station/widgets/fuel_state_view.dart';
import 'package:amial_pay/features/fuel_station/screens/fuel_ops_center_screen.dart';
import 'package:amial_pay/features/fuel_station/screens/fuel_tanks_screen.dart';
import 'package:amial_pay/features/fuel_station/screens/fuel_deliveries_screen.dart';
import 'package:amial_pay/features/fuel_station/screens/fuel_variances_screen.dart';
import 'package:amial_pay/features/fuel_station/screens/fuel_prices_screen.dart';
import 'package:amial_pay/features/fuel_station/screens/fuel_roles_screen.dart';
import 'package:amial_pay/features/fuel_station/screens/fuel_shifts_screen.dart';
import 'package:amial_pay/features/fuel_station/screens/fuel_sale_screen.dart';
import 'package:amial_pay/features/fuel_station/screens/fuel_sales_history_screen.dart';
import 'package:amial_pay/features/fuel_station/screens/fuel_companies_screen.dart';

/// AMIAL-FUEL-VERTICAL-001 · المرحلة ٨ — **لوحةُ المحطّة**.
///
/// ══════════════════════════════════════════════════════════════════════
/// **وهي شاشةٌ واحدةٌ للجميع، تختلف بما يملكه الداخل.**
///
/// فالمالكُ يرى الأقسامَ السبعة، والكاشيرُ يرى «البيع» و«ورديّتي» وحدهما،
/// وموظّفُ المخزون يرى «الخزانات» و«التوريدات» ولا يرى ريالاً.
///
/// **ولا `if (role == 'cashier')` في هذا الملفّ ولا في غيره.** الخادمُ
/// يردّ الصلاحيّات، والبطاقةُ تُرسم إن كانت صلاحيّتُها فيها.
///
/// وهذا هو الفرقُ بين «شاشةٍ تُبنى من نوع النشاط» و«شاشةٍ تُبنى من
/// القدرات» — وهو ما تفرضه `amial-verticals` صراحةً.
class FuelOwnerConsoleScreen extends StatefulWidget {
  const FuelOwnerConsoleScreen({super.key});

  @override
  State<FuelOwnerConsoleScreen> createState() => _FuelOwnerConsoleScreenState();
}

class _FuelOwnerConsoleScreenState extends State<FuelOwnerConsoleScreen> {
  late final FuelVerticalController c;

  @override
  void initState() {
    super.initState();
    c = Get.find<FuelVerticalController>();

    WidgetsBinding.instance.addPostFrameCallback((_) async {
      await c.loadPermissions();
      if (!c.permissionDenied.value) await c.loadOps();
    });
  }

  Future<void> _refresh() async {
    await c.loadPermissions();
    await c.loadOps();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(
        title: const Text('لوحة المحطة'),
        actions: [
          IconButton(
            key: const Key('fuel-console-refresh'),
            tooltip: 'تحديث',
            onPressed: _refresh,
            icon: const Icon(Icons.refresh_rounded),
          ),
        ],
      ),
      body: RefreshIndicator(
        onRefresh: _refresh,
        child: FuelStateView(
          c: c,
          isEmpty: c.permissions.isEmpty && !c.isOwner.value,
          emptyTitle: 'لم تُمنح أي صلاحية بعد',
          emptyHint: 'اطلب من مالك المحطة إسناد دورٍ لحسابك.',
          emptyIcon: Icons.lock_person_outlined,
          onRetry: _refresh,
          child: ListView(
            padding: const EdgeInsets.all(16),
            children: [
              _statusStrip(),
              const SizedBox(height: 16),
              ..._sections(),
              const SizedBox(height: 24),
            ],
          ),
        ),
      ),
    );
  }

  // ── شريطُ الحالة ───────────────────────────────────────────────────
  //
  // **أوّلُ ما يُقرأ ليس المبيعات**: أوردية مفتوحة؟ وكم تحذيراً ينتظر؟
  Widget _statusStrip() {
    return Obx(() {
      final o = c.ops.value;
      if (o == null) return const SizedBox.shrink();

      final shift = o['shift'] as Map?;
      final unlinked = (o['unlinked_nozzles'] ?? 0) as int;
      final pendingDel = (o['pending_deliveries'] ?? 0) as int;
      final openVar = (o['open_variances'] ?? 0) as int;
      final pendingPrice = (o['pending_prices'] ?? 0) as int;

      return Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Card(
            color: shift != null ? const Color(0xFFE8F5E9) : const Color(0xFFFFF3E0),
            child: ListTile(
              key: const Key('fuel-shift-status'),
              leading: Icon(
                shift != null ? Icons.play_circle_fill_rounded : Icons.pause_circle_outline,
                color: shift != null ? Colors.green.shade700 : Colors.orange.shade800,
                size: 32,
              ),
              title: Text(
                shift != null ? 'الوردية مفتوحة' : 'لا وردية مفتوحة',
                style: const TextStyle(fontWeight: FontWeight.bold),
              ),
              // **الرفضُ يدلّ على الزرّ** — لا بابٌ مغلقٌ بلا مخرج.
              subtitle: Text(shift != null
                  ? 'منذ ${_shortTime(shift['opened_at'])}'
                  : 'لا بيع قبل فتح وردية — افتحها من «الورديات»'),
              trailing: c.can('shift.open') || c.can('shift.close')
                  ? TextButton(
                      key: const Key('fuel-goto-shifts'),
                      onPressed: () => Get.to(() => const FuelShiftsScreen()),
                      child: Text(shift != null ? 'إدارة' : 'افتح وردية'),
                    )
                  : null,
            ),
          ),

          // **التحذيراتُ تُعرض ولا تُخبَّأ في شاشةٍ داخليّة.**
          if (unlinked > 0)
            _warning(
              key: 'fuel-warn-unlinked',
              icon: Icons.link_off_rounded,
              text: '$unlinked مسدس بلا خزان — لتراتها خارج المصالحة كلّها',
              action: c.can('fuel.pump.manage') ? 'اربطها' : null,
              onTap: () => Get.to(() => const FuelOpsCenterScreen()),
            ),

          if (openVar > 0)
            _warning(
              key: 'fuel-warn-variance',
              icon: Icons.warning_amber_rounded,
              text: '$openVar تحقيق فرق مخزون مفتوح',
              action: c.can('fuel.recon.view') ? 'راجعها' : null,
              onTap: () => Get.to(() => const FuelVariancesScreen()),
              danger: true,
            ),

          if (pendingDel > 0 && c.can('fuel.delivery.receive'))
            _warning(
              key: 'fuel-warn-deliveries',
              icon: Icons.local_shipping_outlined,
              text: '$pendingDel توريد لم يُرحَّل بعد',
              action: 'أكملها',
              onTap: () => Get.to(() => const FuelDeliveriesScreen()),
            ),

          if (pendingPrice > 0 && c.can('fuel.price.view'))
            _warning(
              key: 'fuel-warn-prices',
              icon: Icons.price_change_outlined,
              text: '$pendingPrice سعر ينتظر الاعتماد',
              action: c.can('fuel.price.approve') ? 'اعتمدها' : 'عرض',
              onTap: () => Get.to(() => const FuelPricesScreen()),
            ),
        ],
      );
    });
  }

  Widget _warning({
    required String key,
    required IconData icon,
    required String text,
    required VoidCallback onTap,
    String? action,
    bool danger = false,
  }) {
    final color = danger ? Colors.red.shade700 : Colors.orange.shade800;

    return Card(
      key: Key(key),
      color: danger ? const Color(0xFFFFEBEE) : AmialColors.warningSurface,
      child: ListTile(
        leading: Icon(icon, color: color),
        title: Text(text, style: TextStyle(color: color, fontSize: 14)),
        trailing: action == null
            ? null
            : TextButton(onPressed: onTap, child: Text(action)),
        onTap: action == null ? null : onTap,
      ),
    );
  }

  // ── الأقسام — كلٌّ بصلاحيّته ──────────────────────────────────────

  List<Widget> _sections() {
    final groups = <_Group>[
      _Group('التشغيل', Icons.point_of_sale_rounded, [
        _Item('بيع وقود', Icons.local_gas_station_rounded, 'fuel.sale.create',
            () => const FuelSaleScreen()),
        _Item('مركز العمليات', Icons.dashboard_rounded, 'fuel.pump.view',
            () => const FuelOpsCenterScreen()),
        _Item('العمليات والفواتير', Icons.receipt_long_rounded, 'fuel.sale.view_all',
            () => const FuelSalesHistoryScreen()),
        _Item('الورديات', Icons.access_time_rounded, 'shift.close',
            () => const FuelShiftsScreen()),
        // التقرير هنا سجلّ مبيعات الوقود نفسه، لا لوحة «بيع سريع» عامة.
        _Item('سجل وتقارير المبيعات', Icons.insights_rounded, 'report.sales',
            () => const FuelSalesHistoryScreen()),
      ]),

      _Group('المخزون', Icons.propane_tank_rounded, [
        _Item('الخزانات والقياسات', Icons.propane_tank_outlined, 'fuel.tank.view',
            () => const FuelTanksScreen()),
        _Item('التوريدات', Icons.local_shipping_rounded, 'fuel.delivery.receive',
            () => const FuelDeliveriesScreen()),
        _Item('فروقات المخزون', Icons.rule_rounded, 'fuel.recon.view',
            () => const FuelVariancesScreen()),
      ]),

      _Group('التسعير والآجل', Icons.attach_money_rounded, [
        _Item('الأسعار', Icons.price_change_rounded, 'fuel.price.view',
            () => const FuelPricesScreen()),
        _Item('حسابات الشركات', Icons.business_rounded, 'fuel.company.manage',
            () => const FuelCompaniesScreen()),
      ]),

      _Group('الإدارة', Icons.admin_panel_settings_rounded, [
        _Item('الأدوار والصلاحيات', Icons.manage_accounts_rounded, 'role.view',
            () => const FuelRolesScreen()),
      ]),
    ];

    final out = <Widget>[];

    for (final g in groups) {
      final visible = g.items.where((i) => c.can(i.permission)).toList();

      // **قسمٌ بلا بندٍ لا يُرسم** — عنوانٌ فوق فراغٍ يوهم بعطل.
      if (visible.isEmpty) continue;

      out.add(Padding(
        padding: const EdgeInsets.fromLTRB(4, 16, 4, 8),
        child: Row(children: [
          Icon(g.icon, size: 18, color: AmialColors.textSecondary),
          const SizedBox(width: 8),
          Text(g.title,
              style: TextStyle(
                  fontWeight: FontWeight.bold,
                  fontSize: 15,
                  color: AmialColors.textSecondary)),
        ]),
      ));

      out.add(Card(
        margin: EdgeInsets.zero,
        child: Column(
          children: [
            for (int i = 0; i < visible.length; i++) ...[
              ListTile(
                key: Key('fuel-item-${visible[i].permission}'),
                leading: Icon(visible[i].icon, color: AmialColors.primary),
                title: Text(visible[i].label),
                trailing: const Icon(Icons.chevron_left_rounded),
                onTap: () => Get.to(visible[i].screen),
              ),
              if (i < visible.length - 1) const Divider(height: 1, indent: 56),
            ],
          ],
        ),
      ));
    }

    return out;
  }

  String _shortTime(dynamic iso) {
    if (iso == null) return '—';
    final t = DateTime.tryParse('$iso');
    if (t == null) return '—';

    final d = DateTime.now().difference(t);
    if (d.inMinutes < 60) return '${d.inMinutes} دقيقة';
    if (d.inHours < 24) return '${d.inHours} ساعة';
    return '${d.inDays} يوم';
  }
}

class _Group {
  final String title;
  final IconData icon;
  final List<_Item> items;
  const _Group(this.title, this.icon, this.items);
}

class _Item {
  final String label;
  final IconData icon;
  final String permission;
  final Widget Function() screen;
  const _Item(this.label, this.icon, this.permission, this.screen);
}
