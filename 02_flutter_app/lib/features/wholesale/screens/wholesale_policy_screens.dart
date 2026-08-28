import 'package:flutter/material.dart';
import 'package:get/get.dart';

import 'package:amial_pay/features/access/controllers/access_controller.dart';
import 'package:amial_pay/features/plans/screens/plans_catalog_screen.dart';
import 'package:amial_pay/features/payments/screens/amial_qr_collect_screen.dart';
import 'package:amial_pay/features/wholesale/controllers/wholesale_access_controller.dart';
import 'package:amial_pay/features/wholesale/controllers/wholesale_controller.dart';
import 'package:amial_pay/features/wholesale/screens/wholesale_pro_screens.dart';
import 'package:amial_pay/features/wholesale/screens/wholesale_workflow_screens.dart';
import 'package:amial_pay/theme/amial_colors.dart';
import 'package:amial_pay/theme/amial_spacing.dart';

/// AMIAL-WHOLESALE-ACCESS-001
///
/// هذه الواجهات هي المداخل العامة لتاجر الجملة. الشاشات Pro تبقى مكوّنات
/// تشغيل داخلية، لكن لا تُفتح مباشرة قبل قرار action-level من الخادم.

class WholesalePolicyDashboardScreen extends StatefulWidget {
  const WholesalePolicyDashboardScreen({super.key});

  @override
  State<WholesalePolicyDashboardScreen> createState() =>
      _WholesalePolicyDashboardScreenState();
}

class _WholesalePolicyDashboardScreenState
    extends State<WholesalePolicyDashboardScreen> {
  final access = WholesaleAccessController.ensureRegistered();
  WholesaleController get c => Get.find<WholesaleController>();

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _reload());
  }

  Future<void> _reload() async {
    await access.load(force: true);
    if (!mounted) return;
    if (access.state('access.view') != WholesaleAccessController.available) return;
    await c.loadBusiness();
    if (access.allows('dashboard.metrics')) {
      await c.loadDashboard();
    }
  }

  @override
  Widget build(BuildContext context) {
    return Obx(() {
      if (access.isLoading.value && !access.isLoaded.value) {
        return const Scaffold(body: Center(child: CircularProgressIndicator()));
      }
      if (!access.isLoaded.value) {
        return _PolicyBlocked(
          title: 'تعذر تحميل صلاحيات الجملة',
          message: access.lastError.value,
          retry: _reload,
        );
      }
      if (access.state('access.view') != WholesaleAccessController.available) {
        return const _PolicyBlocked(
          title: 'هذه المساحة لتاجر الجملة فقط',
          message: 'الحساب الحالي غير مربوط بنشاط تجارة الجملة.',
        );
      }

      final d = c.dashboardData.value;
      final business = c.business.value;
      return Scaffold(
        backgroundColor: AmialColors.background,
        appBar: AppBar(
          backgroundColor: AmialColors.background,
          elevation: 0,
          centerTitle: true,
          title: const Text('الجملة'),
        ),
        body: RefreshIndicator(
          onRefresh: _reload,
          child: ListView(
            physics: const AlwaysScrollableScrollPhysics(),
            padding: const EdgeInsets.fromLTRB(
              AmialSpacing.screen,
              AmialSpacing.sm,
              AmialSpacing.screen,
              AmialSpacing.xxl,
            ),
            children: [
              _hero(business),
              const SizedBox(height: AmialSpacing.md),
              if (access.allows('dashboard.metrics') && d != null) ...[
                _metrics(d),
                const SizedBox(height: AmialSpacing.md),
              ],
              if (access.shouldShow('invoice.create')) ...[
                _ActionCard(
                  icon: Icons.add_business_rounded,
                  title: 'فاتورة جديدة',
                  subtitle: access.allows('invoice.create')
                      ? 'إنشاء فاتورة جملة مرتبطة بعميل ومنتجات حقيقية'
                      : access.badge('invoice.create'),
                  locked: !access.allows('invoice.create'),
                  onTap: () => _openAction(
                    context,
                    'invoice.create',
                    () => Get.to(() => const WholesalePolicyInvoiceCreateScreen()),
                  ),
                ),
                const SizedBox(height: AmialSpacing.lg),
              ],
              Text('إدارة الجملة',
                  style: Theme.of(context).textTheme.titleMedium?.copyWith(
                        fontWeight: FontWeight.w900,
                      )),
              const SizedBox(height: AmialSpacing.sm),
              _actions(context),
              const SizedBox(height: AmialSpacing.md),
              if (!access.isOwner.value)
                const _InfoBox(
                  icon: Icons.admin_panel_settings_outlined,
                  text:
                      'تظهر لك فقط أدوات دورك داخل مؤسسة الجملة. الباقة تخص المنشأة، والصلاحية يحددها المالك.',
                ),
            ],
          ),
        ),
      );
    });
  }

  Widget _hero(Map<String, dynamic>? b) {
    String planLabel;
    try {
      final a = Get.find<AccessController>();
      planLabel = a.subscriptionPlanLabel.value ?? access.plan.value;
    } catch (_) {
      planLabel = access.plan.value;
    }
    return Container(
      padding: const EdgeInsets.all(AmialSpacing.lg),
      decoration: BoxDecoration(
        gradient: const LinearGradient(
          colors: [AmialColors.primaryDark, AmialColors.primary],
        ),
        borderRadius: BorderRadius.circular(AmialSpacing.radiusXl),
      ),
      child: Row(
        children: [
          Container(
            width: 54,
            height: 54,
            decoration: BoxDecoration(
              color: AmialColors.yellow,
              borderRadius: BorderRadius.circular(AmialSpacing.radiusMd),
            ),
            child: const Icon(Icons.warehouse_rounded,
                color: AmialColors.primaryDark),
          ),
          const SizedBox(width: AmialSpacing.md),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text('${b?['business_name'] ?? 'متجر جملة'}',
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(
                        color: Colors.white,
                        fontSize: 20,
                        fontWeight: FontWeight.w900)),
                const SizedBox(height: 4),
                Text(planLabel,
                    style: TextStyle(
                        color: Colors.white.withValues(alpha: .82),
                        fontWeight: FontWeight.w700)),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _metrics(Map<String, dynamic> d) {
    final today = d['today'] is Map ? d['today'] as Map : const {};
    return Row(
      children: [
        Expanded(
          child: _Metric(
            label: 'فواتير اليوم',
            value: '${today['invoices_count'] ?? '—'}',
            icon: Icons.receipt_long_outlined,
          ),
        ),
        const SizedBox(width: AmialSpacing.sm),
        Expanded(
          child: _Metric(
            label: 'المستحقات',
            value: '${_money(d['total_receivable'])} ر.ي',
            icon: Icons.account_balance_outlined,
          ),
        ),
      ],
    );
  }

  Widget _actions(BuildContext context) {
    final rows = <({String label, IconData icon, String action, Widget Function() page})>[
      (label: 'الفواتير', icon: Icons.receipt_long_outlined, action: 'invoice.view', page: () => const WholesalePolicyInvoicesScreen()),
      (label: 'المنتجات', icon: Icons.inventory_2_outlined, action: 'product.view', page: () => const WholesalePolicyProductsScreen()),
      (label: 'العملاء', icon: Icons.groups_2_outlined, action: 'customer.view', page: () => const WholesalePolicyCustomersScreen()),
      (label: 'تقادم الديون', icon: Icons.analytics_outlined, action: 'report.view', page: () => const WholesalePolicyAgingScreen()),
      (label: 'أداء المندوبين', icon: Icons.leaderboard_outlined, action: 'report.view', page: () => const WholesaleProSalesRepsReportScreen()),
      (label: 'إدارة المندوبين', icon: Icons.badge_outlined, action: 'rep.view', page: () => const WholesaleProSalesRepsScreen()),
      (label: 'مرتجعات الجملة', icon: Icons.assignment_return_outlined, action: 'return.view', page: () => const WholesaleProReturnsScreen()),
      (label: 'تنبيهات المخزون', icon: Icons.notifications_active_outlined, action: 'stock_alert.view', page: () => const WholesalePolicyStockAlertsScreen()),
      (label: 'صلاحية المنتجات', icon: Icons.event_busy_outlined, action: 'expiry.view', page: () => const WholesalePolicyExpiryScreen()),
    ];

    final visible = rows.where((r) => access.shouldShow(r.action)).toList();
    return GridView.builder(
      shrinkWrap: true,
      physics: const NeverScrollableScrollPhysics(),
      itemCount: visible.length,
      gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
        crossAxisCount: 2,
        crossAxisSpacing: AmialSpacing.sm,
        mainAxisSpacing: AmialSpacing.sm,
        childAspectRatio: 1.5,
      ),
      itemBuilder: (_, i) {
        final r = visible[i];
        final locked = !access.allows(r.action);
        return _ActionCard(
          icon: r.icon,
          title: r.label,
          subtitle: locked ? access.badge(r.action) : 'متاحة',
          locked: locked,
          compact: true,
          onTap: () => _openAction(context, r.action, () => Get.to(r.page)),
        );
      },
    );
  }

  void _openAction(BuildContext context, String action, VoidCallback open) {
    if (access.allows(action)) {
      open();
      return;
    }
    _showActionBlock(context, access, action);
  }
}

class WholesalePolicyProductsScreen extends StatefulWidget {
  const WholesalePolicyProductsScreen({super.key});
  @override
  State<WholesalePolicyProductsScreen> createState() => _WholesalePolicyProductsScreenState();
}

class _WholesalePolicyProductsScreenState extends State<WholesalePolicyProductsScreen> {
  final access = WholesaleAccessController.ensureRegistered();
  WholesaleController get c => Get.find<WholesaleController>();

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) async {
      await access.load();
      if (access.allows('product.view')) await c.loadProducts();
    });
  }

  @override
  Widget build(BuildContext context) => Obx(() {
        if (!access.isLoaded.value) {
          return const Scaffold(body: Center(child: CircularProgressIndicator()));
        }
        if (!access.allows('product.view')) {
          return _ActionBlocked(action: 'product.view');
        }
        // من يملك إدارة المنتج يحصل على شاشة التشغيل الكاملة. القراءة فقط
        // لا تعرض FAB أو زر تعديل إطلاقاً.
        if (access.allows('product.update') || access.allows('product.create')) {
          return const WholesaleProProductsScreen();
        }
        return _ReadOnlyProducts(controller: c);
      });
}

class WholesalePolicyCustomersScreen extends StatefulWidget {
  const WholesalePolicyCustomersScreen({super.key});
  @override
  State<WholesalePolicyCustomersScreen> createState() => _WholesalePolicyCustomersScreenState();
}

class _WholesalePolicyCustomersScreenState extends State<WholesalePolicyCustomersScreen> {
  final access = WholesaleAccessController.ensureRegistered();
  WholesaleController get c => Get.find<WholesaleController>();

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) async {
      await access.load();
      if (access.allows('customer.view')) await c.loadCustomers();
    });
  }

  @override
  Widget build(BuildContext context) => Obx(() {
        if (!access.isLoaded.value) {
          return const Scaffold(body: Center(child: CircularProgressIndicator()));
        }
        if (!access.allows('customer.view')) {
          return _ActionBlocked(action: 'customer.view');
        }
        if (access.allows('customer.manage')) {
          return const WholesaleProCustomersScreen();
        }
        return _ReadOnlyCustomers(controller: c);
      });
}

class WholesalePolicyInvoiceCreateScreen extends StatefulWidget {
  const WholesalePolicyInvoiceCreateScreen({super.key});
  @override
  State<WholesalePolicyInvoiceCreateScreen> createState() =>
      _WholesalePolicyInvoiceCreateScreenState();
}

class _WholesalePolicyInvoiceCreateScreenState
    extends State<WholesalePolicyInvoiceCreateScreen> {
  final access = WholesaleAccessController.ensureRegistered();
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => access.load());
  }

  @override
  Widget build(BuildContext context) => Obx(() {
        if (!access.isLoaded.value) {
          return const Scaffold(body: Center(child: CircularProgressIndicator()));
        }
        if (!access.allows('invoice.create')) {
          return const _ActionBlocked(action: 'invoice.create');
        }
        return const WholesaleProInvoiceCreateScreen();
      });
}

class WholesalePolicyInvoicesScreen extends StatefulWidget {
  const WholesalePolicyInvoicesScreen({super.key, this.initialFilter = 'all'});
  final String initialFilter;

  @override
  State<WholesalePolicyInvoicesScreen> createState() => _WholesalePolicyInvoicesScreenState();
}

class _WholesalePolicyInvoicesScreenState extends State<WholesalePolicyInvoicesScreen> {
  final access = WholesaleAccessController.ensureRegistered();
  WholesaleController get c => Get.find<WholesaleController>();
  late String filter;

  @override
  void initState() {
    super.initState();
    filter = widget.initialFilter;
    WidgetsBinding.instance.addPostFrameCallback((_) => _load());
  }

  Future<void> _load() async {
    await access.load();
    if (!access.allows('invoice.view')) return;
    if (filter == 'overdue') return c.loadInvoices(overdueOnly: true);
    if (filter == 'all') return c.loadInvoices();
    return c.loadInvoices(status: filter);
  }

  @override
  Widget build(BuildContext context) => Obx(() {
        if (!access.isLoaded.value) {
          return const Scaffold(body: Center(child: CircularProgressIndicator()));
        }
        if (!access.allows('invoice.view')) {
          return const _ActionBlocked(action: 'invoice.view');
        }
        return Scaffold(
          backgroundColor: AmialColors.background,
          appBar: AppBar(title: const Text('الفواتير'), centerTitle: true),
          floatingActionButton: access.allows('invoice.create')
              ? FloatingActionButton.extended(
                  onPressed: () => Get.to(() => const WholesalePolicyInvoiceCreateScreen()),
                  icon: const Icon(Icons.add_rounded),
                  label: const Text('فاتورة جديدة'),
                )
              : null,
          body: RefreshIndicator(
            onRefresh: _load,
            child: ListView(
              physics: const AlwaysScrollableScrollPhysics(),
              padding: const EdgeInsets.fromLTRB(16, 12, 16, 96),
              children: [
                SingleChildScrollView(
                  scrollDirection: Axis.horizontal,
                  child: Row(
                    children: [
                      for (final f in const [
                        ('all', 'الكل'),
                        ('paid', 'مدفوعة'),
                        ('partial_paid', 'جزئية'),
                        ('issued', 'قيد السداد'),
                        ('overdue', 'متأخرة'),
                      ])
                        Padding(
                          padding: const EdgeInsets.only(left: 6),
                          child: ChoiceChip(
                            label: Text(f.$2),
                            selected: filter == f.$1,
                            onSelected: (_) {
                              setState(() => filter = f.$1);
                              _load();
                            },
                          ),
                        ),
                    ],
                  ),
                ),
                const SizedBox(height: 12),
                if (c.isLoading.value && c.invoices.isEmpty)
                  const Center(child: Padding(
                    padding: EdgeInsets.all(32),
                    child: CircularProgressIndicator(),
                  ))
                else if (c.invoices.isEmpty)
                  const _Empty(text: 'لا توجد فواتير في هذه الحالة')
                else
                  ...c.invoices.map((inv) => _InvoiceTile(
                        invoice: inv,
                        onTap: () => Get.to(() => WholesalePolicyInvoiceDetailsScreen(
                              invoiceId: (inv['id'] as num).toInt(),
                            )),
                      )),
              ],
            ),
          ),
        );
      });
}

class WholesalePolicyInvoiceDetailsScreen extends StatefulWidget {
  const WholesalePolicyInvoiceDetailsScreen({super.key, required this.invoiceId});
  final int invoiceId;
  @override
  State<WholesalePolicyInvoiceDetailsScreen> createState() =>
      _WholesalePolicyInvoiceDetailsScreenState();
}

class _WholesalePolicyInvoiceDetailsScreenState
    extends State<WholesalePolicyInvoiceDetailsScreen> {
  final access = WholesaleAccessController.ensureRegistered();
  WholesaleController get c => Get.find<WholesaleController>();

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _load());
  }

  Future<void> _load() async {
    await access.load();
    if (access.allows('invoice.view')) await c.loadInvoiceDetails(widget.invoiceId);
  }

  @override
  Widget build(BuildContext context) => Obx(() {
        if (!access.isLoaded.value) {
          return const Scaffold(body: Center(child: CircularProgressIndicator()));
        }
        if (!access.allows('invoice.view')) {
          return const _ActionBlocked(action: 'invoice.view');
        }
        final inv = c.currentInvoice.value;
        if (c.isLoading.value && inv == null) {
          return const Scaffold(body: Center(child: CircularProgressIndicator()));
        }
        if (inv == null) {
          return _PolicyBlocked(
            title: 'تعذر تحميل الفاتورة',
            message: c.lastError.value,
            retry: _load,
          );
        }
        final customer = inv['customer'] is Map ? inv['customer'] as Map : const {};
        final items = inv['items'] is List ? inv['items'] as List : const [];
        final balance = _num(inv['balance_due']);
        final active = '${inv['status']}' != 'voided';

        return Scaffold(
          backgroundColor: AmialColors.background,
          appBar: AppBar(
            centerTitle: true,
            title: const Text('تفاصيل الفاتورة'),
            actions: [
              IconButton(
                tooltip: 'PDF',
                onPressed: () async {
                  final ok = await c.downloadInvoicePdf(widget.invoiceId);
                  if (!ok && context.mounted) _snack(context, c.lastError.value, true);
                },
                icon: const Icon(Icons.picture_as_pdf_outlined),
              ),
            ],
          ),
          body: RefreshIndicator(
            onRefresh: _load,
            child: ListView(
              physics: const AlwaysScrollableScrollPhysics(),
              padding: const EdgeInsets.all(16),
              children: [
                _InfoCard(children: [
                  Row(children: [
                    _Status('${inv['status'] ?? ''}'),
                    const Spacer(),
                    Text('${inv['invoice_number'] ?? '—'}',
                        style: const TextStyle(fontWeight: FontWeight.w900, fontSize: 20)),
                  ]),
                  const SizedBox(height: 12),
                  Text('العميل: ${customer['full_name'] ?? '—'}'),
                  const SizedBox(height: 6),
                  Text('${_money(inv['total_amount'])} ر.ي',
                      style: const TextStyle(
                          color: AmialColors.primary,
                          fontSize: 28,
                          fontWeight: FontWeight.w900)),
                  Text('المتبقي: ${_money(balance)} ر.ي'),
                ]),
                const SizedBox(height: 12),
                _InfoCard(children: [
                  const Text('الأصناف', style: TextStyle(fontWeight: FontWeight.w900)),
                  const Divider(),
                  for (final raw in items)
                    Builder(builder: (_) {
                      final item = raw is Map ? raw : const {};
                      return ListTile(
                        contentPadding: EdgeInsets.zero,
                        title: Text('${item['product_name'] ?? '—'}'),
                        subtitle: Text('${item['quantity'] ?? '—'} × ${_money(item['unit_price'])}'),
                        trailing: Text('${_money(item['line_total'])} ر.ي'),
                      );
                    }),
                ]),
                if (active && balance > 0 && access.allows('collection.record')) ...[
                  const SizedBox(height: 12),
                  FilledButton.icon(
                    onPressed: () => _collect(context, balance),
                    icon: const Icon(Icons.payments_outlined),
                    label: const Text('تسجيل تحصيل'),
                  ),
                ],
                if (active && access.allows('invoice.void')) ...[
                  const SizedBox(height: 8),
                  OutlinedButton.icon(
                    onPressed: () => _void(context),
                    icon: const Icon(Icons.cancel_outlined),
                    label: const Text('إبطال الفاتورة'),
                  ),
                ],
                if (active && access.allows('return.request')) ...[
                  const SizedBox(height: 8),
                  OutlinedButton.icon(
                    onPressed: () => Get.to(() => WholesaleProReturnRequestScreen(invoice: inv)),
                    icon: const Icon(Icons.assignment_return_outlined),
                    label: const Text('طلب مرتجع'),
                  ),
                ],
              ],
            ),
          ),
        );
      });

  Future<void> _collect(BuildContext context, double max) async {
    final amount = TextEditingController(text: _plain(max));
    String method = 'cash';
    final reference = TextEditingController();
    await showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      builder: (sheet) => StatefulBuilder(builder: (_, setSheet) {
        return Padding(
          padding: EdgeInsets.fromLTRB(20, 20, 20,
              MediaQuery.viewInsetsOf(sheet).bottom + 20),
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            const Text('تسجيل تحصيل',
                style: TextStyle(fontWeight: FontWeight.w900, fontSize: 20)),
            const SizedBox(height: 12),
            TextField(
              controller: amount,
              keyboardType: const TextInputType.numberWithOptions(decimal: true),
              decoration: const InputDecoration(labelText: 'المبلغ'),
            ),
            const SizedBox(height: 8),
            Wrap(spacing: 6, children: [
              for (final m in const [
                ('cash', 'نقد'),
                ('bank_transfer', 'تحويل'),
                ('amial_pay', 'أميال'),
                ('check', 'شيك'),
              ])
                ChoiceChip(
                  label: Text(m.$2),
                  selected: method == m.$1,
                  onSelected: (_) => setSheet(() => method = m.$1),
                ),
            ]),
            TextField(
              controller: reference,
              decoration: const InputDecoration(labelText: 'رقم المرجع (اختياري)'),
            ),
            const SizedBox(height: 16),
            SizedBox(
              width: double.infinity,
              child: FilledButton(
                onPressed: () async {
                  final value = double.tryParse(amount.text.trim()) ?? 0;
                  if (value <= 0 || value > max) {
                    _snack(context, 'المبلغ غير صحيح', true);
                    return;
                  }
                  // «أميال» ليس رقم مرجع يكتبه الموظف: نفتح QR باسم
                  // محفظة المالك، ثم نسجل التحصيل فقط بعد حركة مدفوعة.
                  if (method == 'amial_pay') {
                    if (sheet.mounted) Navigator.pop(sheet);
                    await Get.to(() => AmialQrCollectScreen(
                          amount: value,
                          title: 'تحصيل دين جملة — أميال باي',
                          createPaymentRequest: (amount, note) =>
                              c.createCollectionPaymentRequest(
                                  widget.invoiceId, amount, note),
                          cancelPaymentRequest:
                              c.cancelWholesalePaymentRequest,
                          onPaid: (transactionId) async {
                            final settled = await c.recordCollection(
                              widget.invoiceId,
                              {
                                'amount': value,
                                'payment_method': 'amial_pay',
                                'paid_transaction_id': transactionId,
                              },
                            );
                            if (settled && mounted) await _load();
                            return settled;
                          },
                        ));
                    return;
                  }
                  final ok = await c.recordCollection(widget.invoiceId, {
                    'amount': value,
                    'payment_method': method,
                    if (reference.text.trim().isNotEmpty)
                      'reference_number': reference.text.trim(),
                  });
                  if (!sheet.mounted) return;
                  if (ok) {
                    Navigator.pop(sheet);
                    await _load();
                  } else if (context.mounted) {
                    _snack(context, c.lastError.value, true);
                  }
                },
                child: const Text('تأكيد التحصيل'),
              ),
            ),
          ]),
        );
      }),
    );
  }

  Future<void> _void(BuildContext context) async {
    final reason = TextEditingController();
    final ok = await showDialog<bool>(
      context: context,
      builder: (dialog) => AlertDialog(
        title: const Text('إبطال الفاتورة'),
        content: TextField(
          controller: reason,
          decoration: const InputDecoration(labelText: 'سبب الإبطال *'),
          maxLines: 2,
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(dialog, false), child: const Text('إلغاء')),
          FilledButton(
            onPressed: () {
              if (reason.text.trim().isEmpty) return;
              Navigator.pop(dialog, true);
            },
            child: const Text('تأكيد الإبطال'),
          ),
        ],
      ),
    );
    if (ok != true) return;
    final done = await c.voidInvoice(widget.invoiceId, reason.text.trim());
    if (!mounted) return;
    if (done) {
      await _load();
    } else {
      _snack(context, c.lastError.value, true);
    }
  }
}

class WholesalePolicyAgingScreen extends StatelessWidget {
  const WholesalePolicyAgingScreen({super.key});
  @override
  Widget build(BuildContext context) => const _ActionGate(
        action: 'report.view',
        child: WholesaleProAgingScreen(),
      );
}

class WholesalePolicyCustomerStatementScreen extends StatelessWidget {
  const WholesalePolicyCustomerStatementScreen({super.key, required this.customer});
  final Map<String, dynamic> customer;
  @override
  Widget build(BuildContext context) => _ActionGate(
        action: 'report.view',
        child: WholesaleProCustomerStatementScreen(customer: customer),
      );
}

class WholesalePolicyStockAlertsScreen extends StatefulWidget {
  const WholesalePolicyStockAlertsScreen({super.key});
  @override
  State<WholesalePolicyStockAlertsScreen> createState() => _WholesalePolicyStockAlertsScreenState();
}

class _WholesalePolicyStockAlertsScreenState extends State<WholesalePolicyStockAlertsScreen> {
  final access = WholesaleAccessController.ensureRegistered();
  WholesaleController get c => Get.find<WholesaleController>();

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) async {
      await access.load();
      if (access.allows('stock_alert.view')) await c.loadProducts(lowStockOnly: true);
    });
  }

  @override
  Widget build(BuildContext context) => Obx(() {
        if (!access.isLoaded.value) return const Scaffold(body: Center(child: CircularProgressIndicator()));
        if (!access.allows('stock_alert.view')) return const _ActionBlocked(action: 'stock_alert.view');
        if (access.allows('product.update') && access.allows('stock.adjust')) {
          return const WholesaleStockAlertsScreen();
        }
        return _ReadOnlyStockAlerts(controller: c);
      });
}

class WholesalePolicyExpiryScreen extends StatelessWidget {
  const WholesalePolicyExpiryScreen({super.key});
  @override
  Widget build(BuildContext context) => const _ActionGate(
        action: 'expiry.view',
        child: WholesaleExpiryAlertsScreen(),
      );
}

// ---------------------------------------------------------------------------
// Action gate + read-only surfaces
// ---------------------------------------------------------------------------

class _ActionGate extends StatefulWidget {
  const _ActionGate({required this.action, required this.child});
  final String action;
  final Widget child;
  @override
  State<_ActionGate> createState() => _ActionGateState();
}

class _ActionGateState extends State<_ActionGate> {
  final access = WholesaleAccessController.ensureRegistered();
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => access.load());
  }

  @override
  Widget build(BuildContext context) => Obx(() {
        if (!access.isLoaded.value) return const Scaffold(body: Center(child: CircularProgressIndicator()));
        if (!access.allows(widget.action)) return _ActionBlocked(action: widget.action);
        return widget.child;
      });
}

class _ActionBlocked extends StatelessWidget {
  const _ActionBlocked({required this.action});
  final String action;
  @override
  Widget build(BuildContext context) {
    final access = WholesaleAccessController.ensureRegistered();
    return Obx(() {
      final planLocked = access.isPlanLocked(action) || access.isLimitReached(action);
      return _PolicyBlocked(
        title: access.badge(action),
        message: access.message(action),
        actionLabel: planLocked && access.isOwner.value ? 'مقارنة الباقات' : null,
        action: planLocked && access.isOwner.value
            ? () => Get.to(() => PlansCatalogScreen(suggestedPlan: access.suggestedPlan(action)))
            : null,
      );
    });
  }
}

class _ReadOnlyProducts extends StatelessWidget {
  const _ReadOnlyProducts({required this.controller});
  final WholesaleController controller;
  @override
  Widget build(BuildContext context) => Scaffold(
        backgroundColor: AmialColors.background,
        appBar: AppBar(title: const Text('المنتجات'), centerTitle: true),
        body: Obx(() => RefreshIndicator(
              onRefresh: controller.loadProducts,
              child: ListView(
                physics: const AlwaysScrollableScrollPhysics(),
                padding: const EdgeInsets.all(16),
                children: [
                  const _InfoBox(
                    icon: Icons.visibility_outlined,
                    text: 'عرض فقط — دورك لا يسمح بإضافة أو تعديل المنتجات.',
                  ),
                  const SizedBox(height: 12),
                  if (controller.products.isEmpty)
                    const _Empty(text: 'لا توجد منتجات')
                  else
                    ...controller.products.map((p) => Card(
                          child: ListTile(
                            leading: const Icon(Icons.inventory_2_outlined),
                            title: Text('${p['name'] ?? '—'}'),
                            subtitle: Text('${p['sku'] ?? p['barcode'] ?? ''}'),
                            trailing: Text('${_plain(_num(p['current_stock']))} ${p['unit'] ?? 'وحدة'}'),
                          ),
                        )),
                ],
              ),
            )),
      );
}

class _ReadOnlyCustomers extends StatelessWidget {
  const _ReadOnlyCustomers({required this.controller});
  final WholesaleController controller;
  @override
  Widget build(BuildContext context) => Scaffold(
        backgroundColor: AmialColors.background,
        appBar: AppBar(title: const Text('العملاء'), centerTitle: true),
        body: Obx(() => RefreshIndicator(
              onRefresh: controller.loadCustomers,
              child: ListView(
                physics: const AlwaysScrollableScrollPhysics(),
                padding: const EdgeInsets.all(16),
                children: [
                  const _InfoBox(
                    icon: Icons.visibility_outlined,
                    text: 'عرض فقط — لا يمكنك تغيير حد الائتمان أو بيانات العميل.',
                  ),
                  const SizedBox(height: 12),
                  if (controller.customers.isEmpty)
                    const _Empty(text: 'لا يوجد عملاء')
                  else
                    ...controller.customers.map((p) => Card(
                          child: ListTile(
                            leading: const Icon(Icons.groups_2_outlined),
                            title: Text('${p['full_name'] ?? '—'}'),
                            subtitle: Text('${p['company_name'] ?? p['phone'] ?? ''}'),
                            trailing: Text('${_money(p['current_balance'])} ر.ي'),
                          ),
                        )),
                ],
              ),
            )),
      );
}

class _ReadOnlyStockAlerts extends StatelessWidget {
  const _ReadOnlyStockAlerts({required this.controller});
  final WholesaleController controller;
  @override
  Widget build(BuildContext context) => Scaffold(
        backgroundColor: AmialColors.background,
        appBar: AppBar(title: const Text('تنبيهات المخزون'), centerTitle: true),
        body: Obx(() => RefreshIndicator(
              onRefresh: () => controller.loadProducts(lowStockOnly: true),
              child: ListView(
                physics: const AlwaysScrollableScrollPhysics(),
                padding: const EdgeInsets.all(16),
                children: [
                  const _InfoBox(
                    icon: Icons.notifications_active_outlined,
                    text: 'يمكنك رؤية التنبيهات، لكن تعديل المخزون أو حد التنبيه يحتاج صلاحية إدارة.',
                  ),
                  const SizedBox(height: 12),
                  if (controller.products.isEmpty)
                    const _Empty(text: 'لا توجد منتجات تحت حد التنبيه')
                  else
                    ...controller.products.map((p) => Card(
                          child: ListTile(
                            leading: Icon(
                              _num(p['current_stock']) <= 0
                                  ? Icons.error_outline
                                  : Icons.schedule_outlined,
                              color: _num(p['current_stock']) <= 0
                                  ? AmialColors.danger
                                  : AmialColors.warning,
                            ),
                            title: Text('${p['name'] ?? '—'}'),
                            subtitle: Text('حد التنبيه ${_plain(_num(p['low_stock_threshold']))}'),
                            trailing: Text('المتبقي ${_plain(_num(p['current_stock']))}'),
                          ),
                        )),
                ],
              ),
            )),
      );
}

// ---------------------------------------------------------------------------
// Small visual primitives
// ---------------------------------------------------------------------------

class _ActionCard extends StatelessWidget {
  const _ActionCard({
    required this.icon,
    required this.title,
    required this.subtitle,
    required this.onTap,
    this.locked = false,
    this.compact = false,
  });
  final IconData icon;
  final String title;
  final String subtitle;
  final VoidCallback onTap;
  final bool locked;
  final bool compact;

  @override
  Widget build(BuildContext context) => InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(16),
        child: Container(
          padding: EdgeInsets.all(compact ? 14 : 18),
          decoration: BoxDecoration(
            color: AmialColors.cardSurface,
            borderRadius: BorderRadius.circular(16),
            border: Border.all(color: locked ? AmialColors.warning : AmialColors.border),
          ),
          child: Row(children: [
            Icon(locked ? Icons.lock_outline_rounded : icon,
                color: locked ? AmialColors.warning : AmialColors.primary),
            const SizedBox(width: 10),
            Expanded(child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                Text(title, style: const TextStyle(fontWeight: FontWeight.w900)),
                Text(subtitle,
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    style: TextStyle(
                        color: locked ? AmialColors.warning : AmialColors.textMuted,
                        fontSize: 11)),
              ],
            )),
          ]),
        ),
      );
}

class _Metric extends StatelessWidget {
  const _Metric({required this.label, required this.value, required this.icon});
  final String label;
  final String value;
  final IconData icon;
  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.all(14),
        decoration: BoxDecoration(
          color: AmialColors.cardSurface,
          borderRadius: BorderRadius.circular(16),
          border: Border.all(color: AmialColors.border),
        ),
        child: Row(children: [
          Icon(icon, color: AmialColors.primary),
          const SizedBox(width: 8),
          Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(value, maxLines: 1, overflow: TextOverflow.ellipsis,
                style: const TextStyle(fontWeight: FontWeight.w900, color: AmialColors.primary)),
            Text(label, style: const TextStyle(fontSize: 11, color: AmialColors.textMuted)),
          ])),
        ]),
      );
}

class _InvoiceTile extends StatelessWidget {
  const _InvoiceTile({required this.invoice, required this.onTap});
  final Map<String, dynamic> invoice;
  final VoidCallback onTap;
  @override
  Widget build(BuildContext context) {
    final customer = invoice['customer'] is Map ? invoice['customer'] as Map : const {};
    final balance = _num(invoice['balance_due']);
    return Card(
      child: ListTile(
        onTap: onTap,
        leading: const Icon(Icons.description_outlined, color: AmialColors.primary),
        title: Text('${invoice['invoice_number'] ?? '—'}',
            style: const TextStyle(fontWeight: FontWeight.w900)),
        subtitle: Text('${customer['full_name'] ?? '—'} · ${_statusLabel('${invoice['status'] ?? ''}')}'),
        trailing: Column(mainAxisAlignment: MainAxisAlignment.center, crossAxisAlignment: CrossAxisAlignment.end, children: [
          Text('${_money(invoice['total_amount'])} ر.ي',
              style: const TextStyle(color: AmialColors.primary, fontWeight: FontWeight.w900)),
          if (balance > 0)
            Text('متبقي ${_money(balance)}',
                style: const TextStyle(color: AmialColors.danger, fontSize: 10)),
        ]),
      ),
    );
  }
}

class _InfoCard extends StatelessWidget {
  const _InfoCard({required this.children});
  final List<Widget> children;
  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.all(16),
        decoration: BoxDecoration(
          color: AmialColors.cardSurface,
          borderRadius: BorderRadius.circular(16),
          border: Border.all(color: AmialColors.border),
        ),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: children),
      );
}

class _InfoBox extends StatelessWidget {
  const _InfoBox({required this.icon, required this.text});
  final IconData icon;
  final String text;
  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(
          color: AmialColors.primary.withValues(alpha: .05),
          borderRadius: BorderRadius.circular(12),
        ),
        child: Row(children: [
          Icon(icon, color: AmialColors.primary),
          const SizedBox(width: 8),
          Expanded(child: Text(text, style: const TextStyle(color: AmialColors.textSecondary))),
        ]),
      );
}

class _Status extends StatelessWidget {
  const _Status(this.status);
  final String status;
  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
        decoration: BoxDecoration(
          color: AmialColors.primary.withValues(alpha: .06),
          borderRadius: BorderRadius.circular(20),
        ),
        child: Text(_statusLabel(status),
            style: const TextStyle(fontWeight: FontWeight.w800)),
      );
}

class _Empty extends StatelessWidget {
  const _Empty({required this.text});
  final String text;
  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.all(32),
        child: Center(child: Text(text, style: const TextStyle(color: AmialColors.textMuted))),
      );
}

class _PolicyBlocked extends StatelessWidget {
  const _PolicyBlocked({
    required this.title,
    required this.message,
    this.retry,
    this.actionLabel,
    this.action,
  });
  final String title;
  final String message;
  final Future<void> Function()? retry;
  final String? actionLabel;
  final VoidCallback? action;

  @override
  Widget build(BuildContext context) => Scaffold(
        backgroundColor: AmialColors.background,
        appBar: AppBar(title: const Text('تجارة الجملة'), centerTitle: true),
        body: Center(
          child: Padding(
            padding: const EdgeInsets.all(24),
            child: Column(mainAxisSize: MainAxisSize.min, children: [
              const Icon(Icons.lock_outline_rounded, size: 54, color: AmialColors.warning),
              const SizedBox(height: 12),
              Text(title, textAlign: TextAlign.center,
                  style: const TextStyle(fontWeight: FontWeight.w900, fontSize: 20)),
              const SizedBox(height: 8),
              Text(message, textAlign: TextAlign.center,
                  style: const TextStyle(color: AmialColors.textSecondary)),
              if (retry != null) ...[
                const SizedBox(height: 16),
                OutlinedButton.icon(
                  onPressed: retry,
                  icon: const Icon(Icons.refresh_rounded),
                  label: const Text('إعادة المحاولة'),
                ),
              ],
              if (action != null && actionLabel != null) ...[
                const SizedBox(height: 8),
                FilledButton(onPressed: action, child: Text(actionLabel!)),
              ],
            ]),
          ),
        ),
      );
}

void _showActionBlock(
    BuildContext context, WholesaleAccessController access, String action) {
  final planLocked = access.isPlanLocked(action) || access.isLimitReached(action);
  showModalBottomSheet<void>(
    context: context,
    builder: (sheet) => Padding(
      padding: const EdgeInsets.all(22),
      child: Column(mainAxisSize: MainAxisSize.min, children: [
        Icon(planLocked ? Icons.workspace_premium_outlined : Icons.lock_outline_rounded,
            size: 44, color: AmialColors.warning),
        const SizedBox(height: 10),
        Text(access.badge(action),
            style: const TextStyle(fontWeight: FontWeight.w900, fontSize: 18)),
        const SizedBox(height: 6),
        Text(access.message(action), textAlign: TextAlign.center),
        if (planLocked && access.isOwner.value) ...[
          const SizedBox(height: 14),
          FilledButton(
            onPressed: () {
              Navigator.pop(sheet);
              Get.to(() => PlansCatalogScreen(suggestedPlan: access.suggestedPlan(action)));
            },
            child: const Text('مقارنة الباقات'),
          ),
        ],
      ]),
    ),
  );
}

void _snack(BuildContext context, String message, bool error) {
  ScaffoldMessenger.of(context).showSnackBar(SnackBar(
    content: Text(message.isEmpty ? 'تعذر إتمام العملية' : message),
    backgroundColor: error ? AmialColors.danger : AmialColors.success,
  ));
}

double _num(dynamic v) => v is num ? v.toDouble() : (double.tryParse('$v') ?? 0);
String _plain(double v) => v == v.roundToDouble() ? '${v.toInt()}' : v.toStringAsFixed(2);
String _money(dynamic v) {
  final n = _num(v).round();
  final s = n.abs().toString();
  final b = StringBuffer();
  for (var i = 0; i < s.length; i++) {
    if (i > 0 && (s.length - i) % 3 == 0) b.write(',');
    b.write(s[i]);
  }
  return '${n < 0 ? '-' : ''}$b';
}
String _statusLabel(String s) => switch (s) {
      'paid' => 'مدفوعة',
      'partial_paid' => 'جزئية',
      'issued' => 'قيد السداد',
      'overdue' => 'متأخرة',
      'voided' => 'ملغاة',
      _ => s.isEmpty ? 'غير معروف' : s,
    };
