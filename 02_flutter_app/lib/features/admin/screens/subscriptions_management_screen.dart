import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:intl/intl.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';
import 'package:amyal_pay/features/admin/controllers/subscriptions_controller.dart';

/// CRITICAL-001-SUBS — شاشة إدارة الاشتراكات للأدمن.
///
/// 3 tabs:
///   1. نظرة عامة: MRR + KPIs + by_plan
///   2. منتهية قريباً: قائمة + Quick Actions
///   3. سجل التدقيق: paginated audit log
class SubscriptionsManagementScreen extends StatefulWidget {
  const SubscriptionsManagementScreen({super.key});

  @override
  State<SubscriptionsManagementScreen> createState() => _SMState();
}

class _SMState extends State<SubscriptionsManagementScreen>
    with SingleTickerProviderStateMixin {
  late final SubscriptionsController c;
  late final TabController _tabs;

  @override
  void initState() {
    super.initState();
    c = Get.find<SubscriptionsController>();
    _tabs = TabController(length: 3, vsync: this);
    WidgetsBinding.instance.addPostFrameCallback((_) async {
      await c.loadSummary();
      await c.loadExpiring();
    });
  }

  @override
  void dispose() { _tabs.dispose(); super.dispose(); }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmyalColors.background,
      appBar: AppBar(
        title: const Text('إدارة الاشتراكات'),
        bottom: TabBar(
          controller: _tabs,
          isScrollable: true,
          labelColor: Colors.white,
          unselectedLabelColor: Colors.white60,
          indicatorColor: AmyalColors.yellow,
          tabs: const [
            Tab(icon: Icon(Icons.analytics), text: 'نظرة عامة'),
            Tab(icon: Icon(Icons.timer), text: 'منتهية قريباً'),
            Tab(icon: Icon(Icons.history), text: 'السجل'),
          ],
          onTap: (i) {
            if (i == 2 && c.logItems.isEmpty) c.loadLog(reset: true);
          },
        ),
      ),
      body: TabBarView(
        controller: _tabs,
        children: [
          _OverviewTab(c: c),
          _ExpiringTab(c: c),
          _LogTab(c: c),
        ],
      ),
    );
  }
}

// =========================================================================
// Tab 1: نظرة عامة
// =========================================================================

class _OverviewTab extends StatelessWidget {
  final SubscriptionsController c;
  const _OverviewTab({required this.c});

  @override
  Widget build(BuildContext context) {
    return RefreshIndicator(
      onRefresh: c.loadSummary,
      child: Obx(() {
        final s = c.summary.value;
        if (s == null) {
          return c.isLoading.value
            ? const Center(child: CircularProgressIndicator())
            : ListView(children: const [SizedBox(height: 200),
                Center(child: Text('لا توجد بيانات'))]);
        }

        return ListView(
          padding: const EdgeInsets.all(12),
          children: [
            // MRR + ARR Cards
            Row(children: [
              Expanded(child: _kpiCard(
                icon: Icons.payments,
                label: 'الإيراد الشهري (MRR)',
                value: '${_fmt(s['mrr_sar'])} ر.ي',
                color: const Color(0xFF059669),
                large: true,
              )),
              const SizedBox(width: 8),
              Expanded(child: _kpiCard(
                icon: Icons.calendar_month,
                label: 'الإيراد السنوي (ARR)',
                value: '${_fmt(s['arr_sar'])} ر.ي',
                color: AmyalColors.primary,
                large: true,
              )),
            ]),
            const SizedBox(height: 10),

            // عدد المشتركين النشطين
            Row(children: [
              Expanded(child: _kpiCard(
                icon: Icons.people,
                label: 'إجمالي النشطين',
                value: '${s['total_active'] ?? 0}',
                color: Colors.indigo,
              )),
              const SizedBox(width: 8),
              Expanded(child: _kpiCard(
                icon: Icons.workspace_premium,
                label: 'المدفوع (غير FREE)',
                value: '${s['total_paying'] ?? 0}',
                color: Colors.deepPurple,
              )),
            ]),

            const SizedBox(height: 16),

            // تنبيهات الانتهاء
            if ((s['expiring_in_7_days'] ?? 0) > 0)
              _alertBanner(
                icon: Icons.warning_amber,
                color: Colors.orange,
                title: '${s['expiring_in_7_days']} اشتراك ينتهي خلال 7 أيام',
                subtitle: 'يحتاج تجديد عاجل',
              ),
            if ((s['expired_not_processed'] ?? 0) > 0)
              _alertBanner(
                icon: Icons.error_outline,
                color: Colors.red,
                title: '${s['expired_not_processed']} اشتراك منتهي غير مُعالَج',
                subtitle: 'سيُعالَج تلقائياً في الـ cron القادم',
              ),

            const SizedBox(height: 12),

            // توزيع المشتركين على الخطط
            Container(
              padding: const EdgeInsets.all(14),
              decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(12)),
              child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                const Text('توزيع المشتركين على الخطط',
                    style: TextStyle(fontWeight: FontWeight.bold, fontSize: 15)),
                const Divider(),
                ...((s['by_plan'] ?? []) as List).map((p) => _planRow(p as Map)),
              ]),
            ),

            const SizedBox(height: 12),

            // نشاط آخر 30 يوم
            Container(
              padding: const EdgeInsets.all(14),
              decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(12)),
              child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                const Text('نشاط آخر 30 يوم',
                    style: TextStyle(fontWeight: FontWeight.bold, fontSize: 15)),
                const Divider(),
                _activityRow('💰 الإيرادات المُحصَّلة',
                    '${_fmt(s['last_30_days']?['revenue_collected_sar'])} ر.ي', Colors.green),
                _activityRow('⬆️ ترقيات',
                    '${s['last_30_days']?['upgrades'] ?? 0}', Colors.blue),
                _activityRow('⬇️ تخفيضات',
                    '${s['last_30_days']?['downgrades'] ?? 0}', Colors.orange),
                _activityRow('🔄 تجديدات',
                    '${s['last_30_days']?['renewals'] ?? 0}', Colors.teal),
                _activityRow('⏰ انتهاءات تلقائية',
                    '${s['last_30_days']?['auto_expirations'] ?? 0}', Colors.red),
              ]),
            ),

            const SizedBox(height: 16),

            // زر معالجة يدوية
            FilledButton.tonalIcon(
              onPressed: c.isLoading.value ? null : () async {
                final count = await c.processExpired();
                if (!context.mounted) return;
                ScaffoldMessenger.of(context).showSnackBar(SnackBar(
                  content: Text(count > 0
                    ? '✓ تمّت معالجة $count اشتراك منتهي'
                    : 'لا توجد اشتراكات منتهية للمعالجة'),
                  backgroundColor: count > 0 ? Colors.green : Colors.grey,
                ));
                await c.loadSummary();
              },
              icon: const Icon(Icons.refresh),
              label: const Text('معالجة المنتهية يدوياً'),
              style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(48)),
            ),
          ],
        );
      }),
    );
  }

  Widget _kpiCard({required IconData icon, required String label, required String value,
      required Color color, bool large = false}) {
    return Container(
      padding: EdgeInsets.all(large ? 14 : 12),
      decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(12)),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Icon(icon, color: color, size: large ? 28 : 24),
        const SizedBox(height: 6),
        Text(label, style: TextStyle(color: Colors.grey.shade700, fontSize: 11)),
        const SizedBox(height: 2),
        Text(value, style: TextStyle(
            color: color, fontWeight: FontWeight.bold, fontSize: large ? 18 : 16)),
      ]),
    );
  }

  Widget _alertBanner({required IconData icon, required Color color,
      required String title, required String subtitle}) {
    return Container(
      margin: const EdgeInsets.only(bottom: 8),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.1),
        borderRadius: BorderRadius.circular(10),
        border: Border.all(color: color.withValues(alpha: 0.3)),
      ),
      child: Row(children: [
        Icon(icon, color: color),
        const SizedBox(width: 10),
        Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(title, style: TextStyle(fontWeight: FontWeight.bold, color: color)),
          Text(subtitle, style: TextStyle(color: Colors.grey.shade700, fontSize: 11)),
        ])),
      ]),
    );
  }

  Widget _planRow(Map p) {
    final code = p['code']?.toString() ?? '';
    final count = p['count'] ?? 0;
    final revenue = p['revenue_monthly_sar'] ?? 0;
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 6),
      child: Row(children: [
        Container(width: 8, height: 8,
            decoration: BoxDecoration(color: _planColor(code), shape: BoxShape.circle)),
        const SizedBox(width: 8),
        Expanded(child: Text(p['label']?.toString() ?? code)),
        Text('$count مشترك', style: TextStyle(color: Colors.grey.shade700, fontSize: 12)),
        const SizedBox(width: 10),
        Text('${_fmt(revenue)} ر.ي',
            style: const TextStyle(fontWeight: FontWeight.bold, color: Color(0xFF059669))),
      ]),
    );
  }

  Widget _activityRow(String label, String value, Color color) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 4),
      child: Row(children: [
        Expanded(child: Text(label, style: const TextStyle(fontSize: 13))),
        Text(value, style: TextStyle(fontWeight: FontWeight.bold, color: color)),
      ]),
    );
  }

  static Color _planColor(String code) => switch(code) {
    'free' => Colors.grey,
    'starter' => Colors.green,
    'business' => AmyalColors.primary,
    'merchant_pro' => Colors.orange,
    'enterprise' => Colors.purple,
    _ => Colors.grey,
  };

  static String _fmt(dynamic v) {
    if (v == null) return '0';
    final num n = v is num ? v : (num.tryParse('$v') ?? 0);
    return NumberFormat('#,##0.##').format(n);
  }
}

// =========================================================================
// Tab 2: منتهية قريباً
// =========================================================================

class _ExpiringTab extends StatelessWidget {
  final SubscriptionsController c;
  const _ExpiringTab({required this.c});

  @override
  Widget build(BuildContext context) {
    return Column(children: [
      // Filter
      Padding(
        padding: const EdgeInsets.all(8),
        child: Obx(() => SegmentedButton<int>(
          segments: const [
            ButtonSegment(value: 1, label: Text('غداً')),
            ButtonSegment(value: 7, label: Text('7 أيام')),
            ButtonSegment(value: 30, label: Text('30 يوم')),
          ],
          selected: {c.expiringDays.value},
          onSelectionChanged: (s) => c.loadExpiring(days: s.first),
        )),
      ),
      Expanded(child: RefreshIndicator(
        onRefresh: () => c.loadExpiring(),
        child: Obx(() {
          if (c.isLoading.value && c.expiring.isEmpty) {
            return const Center(child: CircularProgressIndicator());
          }
          if (c.expiring.isEmpty) {
            return ListView(children: const [
              SizedBox(height: 200),
              Center(child: Column(children: [
                Icon(Icons.check_circle, color: Colors.green, size: 64),
                SizedBox(height: 12),
                Text('لا توجد اشتراكات تنتهي قريباً'),
              ])),
            ]);
          }
          return ListView.separated(
            padding: const EdgeInsets.all(12),
            itemCount: c.expiring.length,
            separatorBuilder: (_, _) => const SizedBox(height: 8),
            itemBuilder: (_, i) => _expiringCard(context, c.expiring[i]),
          );
        }),
      )),
    ]);
  }

  Widget _expiringCard(BuildContext context, Map<String, dynamic> m) {
    final daysLeft = m['days_left'];
    Color urgencyColor = Colors.green;
    if (daysLeft is num) {
      if (daysLeft <= 1) {
        urgencyColor = Colors.red;
      } else if (daysLeft <= 3) {
        urgencyColor = Colors.orange;
      } else if (daysLeft <= 7) {
        urgencyColor = Colors.amber;
      }
    }

    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: Colors.white, borderRadius: BorderRadius.circular(12),
        border: Border(right: BorderSide(color: urgencyColor, width: 4)),
      ),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(children: [
          Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(m['name']?.toString() ?? '',
                style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 15)),
            Text(m['phone']?.toString() ?? '',
                style: TextStyle(color: Colors.grey.shade600, fontSize: 12)),
          ])),
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
            decoration: BoxDecoration(color: urgencyColor.withValues(alpha: 0.15),
                borderRadius: BorderRadius.circular(20)),
            child: Text(
              daysLeft == 0 ? 'اليوم' : daysLeft == 1 ? 'غداً' : '$daysLeft أيام',
              style: TextStyle(color: urgencyColor, fontWeight: FontWeight.bold, fontSize: 12),
            ),
          ),
        ]),
        const SizedBox(height: 8),
        Row(children: [
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
            decoration: BoxDecoration(color: AmyalColors.primary.withValues(alpha: 0.1),
                borderRadius: BorderRadius.circular(6)),
            child: Text(m['current_plan_label']?.toString() ?? '',
                style: const TextStyle(color: AmyalColors.primary, fontSize: 11, fontWeight: FontWeight.bold)),
          ),
          const SizedBox(width: 8),
          Text('ينتهي: ${_formatDate(m['expires_at'])}',
              style: TextStyle(color: Colors.grey.shade700, fontSize: 11)),
        ]),
        const SizedBox(height: 10),
        Row(children: [
          Expanded(child: OutlinedButton.icon(
            icon: const Icon(Icons.refresh, size: 18),
            label: const Text('تجديد 30 يوم'),
            onPressed: () => _showRenewDialog(context, m),
          )),
          const SizedBox(width: 8),
          Expanded(child: OutlinedButton.icon(
            icon: const Icon(Icons.add, size: 18),
            label: const Text('تمديد'),
            onPressed: () => _showExtendDialog(context, m),
          )),
        ]),
      ]),
    );
  }

  void _showRenewDialog(BuildContext ctx, Map<String, dynamic> m) {
    final priceCtrl = TextEditingController();
    String? payment;
    final refCtrl = TextEditingController();

    showDialog(context: ctx, builder: (dCtx) => AlertDialog(
      title: Text('تجديد ${m['current_plan_label']}'),
      content: SingleChildScrollView(child: Column(mainAxisSize: MainAxisSize.min, children: [
        Text('التاجر: ${m['name']}', style: const TextStyle(fontWeight: FontWeight.bold)),
        const SizedBox(height: 4),
        const Text('سيُمدَّد لمدّة 30 يوم من الآن.',
            style: TextStyle(color: Colors.grey, fontSize: 12)),
        const SizedBox(height: 16),
        TextField(controller: priceCtrl, keyboardType: TextInputType.number,
            decoration: const InputDecoration(labelText: 'المبلغ المدفوع (ر.ي)', isDense: true)),
        const SizedBox(height: 8),
        StatefulBuilder(builder: (_, setSt) => DropdownButtonFormField<String>(
          decoration: const InputDecoration(labelText: 'طريقة الدفع', isDense: true),
          initialValue: payment,
          items: const [
            DropdownMenuItem(value: 'cash', child: Text('نقد')),
            DropdownMenuItem(value: 'bank', child: Text('تحويل بنكي')),
            DropdownMenuItem(value: 'amial_pay', child: Text('أميال باي')),
          ],
          onChanged: (v) => setSt(() => payment = v),
        )),
        const SizedBox(height: 8),
        TextField(controller: refCtrl,
            decoration: const InputDecoration(labelText: 'رقم المرجع (اختياري)', isDense: true)),
      ])),
      actions: [
        TextButton(onPressed: () => Navigator.pop(dCtx), child: const Text('إلغاء')),
        FilledButton(
          onPressed: () async {
            Navigator.pop(dCtx);
            final ok = await c.renew(m['merchant_user_id'] as int,
              pricePaidSar: double.tryParse(priceCtrl.text),
              paymentMethod: payment,
              paymentReference: refCtrl.text.isEmpty ? null : refCtrl.text,
            );
            if (!ctx.mounted) return;
            ScaffoldMessenger.of(ctx).showSnackBar(SnackBar(
              content: Text(ok ? '✓ تمّ التجديد' : 'فشل: ${c.lastError.value}'),
              backgroundColor: ok ? Colors.green : Colors.red,
            ));
          },
          child: const Text('تجديد'),
        ),
      ],
    ));
  }

  void _showExtendDialog(BuildContext ctx, Map<String, dynamic> m) {
    final daysCtrl = TextEditingController(text: '7');
    final priceCtrl = TextEditingController();

    showDialog(context: ctx, builder: (dCtx) => AlertDialog(
      title: const Text('تمديد الاشتراك'),
      content: Column(mainAxisSize: MainAxisSize.min, children: [
        Text(m['name']?.toString() ?? '', style: const TextStyle(fontWeight: FontWeight.bold)),
        const SizedBox(height: 12),
        TextField(controller: daysCtrl, keyboardType: TextInputType.number,
            decoration: const InputDecoration(labelText: 'عدد الأيام', isDense: true)),
        const SizedBox(height: 8),
        TextField(controller: priceCtrl, keyboardType: TextInputType.number,
            decoration: const InputDecoration(labelText: 'المبلغ (اختياري)', isDense: true)),
      ]),
      actions: [
        TextButton(onPressed: () => Navigator.pop(dCtx), child: const Text('إلغاء')),
        FilledButton(
          onPressed: () async {
            final days = int.tryParse(daysCtrl.text) ?? 0;
            if (days <= 0) return;
            Navigator.pop(dCtx);
            final ok = await c.extend(m['merchant_user_id'] as int, days,
              pricePaidSar: double.tryParse(priceCtrl.text),
            );
            if (!ctx.mounted) return;
            ScaffoldMessenger.of(ctx).showSnackBar(SnackBar(
              content: Text(ok ? '✓ تمّ التمديد $days يوم' : 'فشل'),
              backgroundColor: ok ? Colors.green : Colors.red,
            ));
          },
          child: const Text('تمديد'),
        ),
      ],
    ));
  }

  static String _formatDate(dynamic iso) {
    if (iso == null) return '—';
    try { return DateFormat('yyyy-MM-dd').format(DateTime.parse('$iso')); }
    catch (_) { return '—'; }
  }
}

// =========================================================================
// Tab 3: سجل التدقيق
// =========================================================================

class _LogTab extends StatelessWidget {
  final SubscriptionsController c;
  const _LogTab({required this.c});

  static const _actionFilters = [
    {'value': '', 'label': 'الكل'},
    {'value': 'upgrade', 'label': 'ترقيات'},
    {'value': 'downgrade', 'label': 'تخفيضات'},
    {'value': 'renew', 'label': 'تجديدات'},
    {'value': 'extend', 'label': 'تمديدات'},
    {'value': 'expire_auto', 'label': 'انتهاءات'},
  ];

  @override
  Widget build(BuildContext context) {
    return Column(children: [
      // Filters
      SizedBox(height: 44, child: Obx(() => ListView(
        scrollDirection: Axis.horizontal,
        padding: const EdgeInsets.symmetric(horizontal: 8),
        children: _actionFilters.map((f) => Padding(
          padding: const EdgeInsets.only(left: 6),
          child: ChoiceChip(
            label: Text(f['label']!),
            selected: c.logActionFilter.value == f['value'],
            onSelected: (_) => c.loadLog(action: f['value']!, reset: true),
          ),
        )).toList(),
      ))),
      Expanded(child: Obx(() {
        if (c.isLoading.value && c.logItems.isEmpty) {
          return const Center(child: CircularProgressIndicator());
        }
        if (c.logItems.isEmpty) {
          return const Center(child: Text('لا توجد سجلات'));
        }
        return ListView.separated(
          padding: const EdgeInsets.all(8),
          itemCount: c.logItems.length,
          separatorBuilder: (_, _) => const SizedBox(height: 6),
          itemBuilder: (_, i) => _logCard(c.logItems[i]),
        );
      })),
    ]);
  }

  Widget _logCard(Map<String, dynamic> log) {
    final action = log['action']?.toString() ?? '';
    final color = _actionColor(action);
    final merchant = log['merchant'] ?? {};
    final actor = log['actor'];

    return Container(
      padding: const EdgeInsets.all(10),
      decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(8)),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(children: [
          Container(width: 36, height: 36,
              decoration: BoxDecoration(color: color.withValues(alpha: 0.15), shape: BoxShape.circle),
              child: Icon(_actionIcon(action), color: color, size: 18)),
          const SizedBox(width: 8),
          Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(_actionLabel(action),
                style: TextStyle(fontWeight: FontWeight.bold, color: color, fontSize: 13)),
            Text(_buildSubtitle(log),
                style: TextStyle(color: Colors.grey.shade800, fontSize: 11)),
          ])),
          Text(_formatRelative(log['created_at']),
              style: TextStyle(color: Colors.grey.shade500, fontSize: 10)),
        ]),
        const SizedBox(height: 6),
        Row(children: [
          Text('${merchant['f_name'] ?? ''} ${merchant['l_name'] ?? ''}'.trim(),
              style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w500)),
          const Spacer(),
          if (log['price_paid_sar'] != null)
            Text('${log['price_paid_sar']} ر.ي',
                style: const TextStyle(color: Colors.green, fontWeight: FontWeight.bold, fontSize: 12)),
        ]),
        if (actor != null && actor['id'] != null) Padding(
          padding: const EdgeInsets.only(top: 2),
          child: Text('بواسطة: ${actor['f_name'] ?? ''} ${actor['l_name'] ?? ''}'.trim(),
              style: TextStyle(color: Colors.grey.shade600, fontSize: 10)),
        ),
      ]),
    );
  }

  String _buildSubtitle(Map<String, dynamic> log) {
    final old = log['old_plan']?.toString() ?? '';
    final newP = log['new_plan']?.toString() ?? '';
    if (old != newP && old.isNotEmpty) return '$old → $newP';
    return newP;
  }

  static IconData _actionIcon(String a) => switch(a) {
    'upgrade' => Icons.arrow_upward,
    'downgrade' => Icons.arrow_downward,
    'renew' => Icons.refresh,
    'extend' => Icons.add,
    'expire_auto' => Icons.timer_off,
    'change_plan' => Icons.swap_horiz,
    'cancel' => Icons.close,
    _ => Icons.history,
  };

  static Color _actionColor(String a) => switch(a) {
    'upgrade' => Colors.green,
    'downgrade' => Colors.orange,
    'renew' => Colors.blue,
    'extend' => Colors.teal,
    'expire_auto' => Colors.red,
    'cancel' => Colors.red.shade700,
    _ => Colors.grey,
  };

  static String _actionLabel(String a) => switch(a) {
    'upgrade' => '⬆️ ترقية',
    'downgrade' => '⬇️ تخفيض',
    'renew' => '🔄 تجديد',
    'extend' => '➕ تمديد',
    'expire_auto' => '⏰ انتهاء تلقائي',
    'change_plan' => '🔀 تغيير خطّة',
    'cancel' => '❌ إلغاء',
    _ => a,
  };

  static String _formatRelative(dynamic iso) {
    if (iso == null) return '';
    try {
      final d = DateTime.parse('$iso');
      final diff = DateTime.now().difference(d);
      if (diff.inMinutes < 60) return 'منذ ${diff.inMinutes}د';
      if (diff.inHours < 24) return 'منذ ${diff.inHours}س';
      if (diff.inDays < 30) return 'منذ ${diff.inDays}ي';
      return DateFormat('MM-dd').format(d);
    } catch (_) { return ''; }
  }
}
