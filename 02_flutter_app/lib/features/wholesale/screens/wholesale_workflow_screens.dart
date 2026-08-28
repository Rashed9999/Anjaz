import 'package:flutter/material.dart';
import 'package:get/get.dart';

import 'package:amial_pay/features/payments/screens/amial_qr_collect_screen.dart';
import 'package:amial_pay/features/wholesale/controllers/wholesale_controller.dart';
import 'package:amial_pay/theme/amial_colors.dart';

/// الشاشات التشغيلية التي تستدعيها مساحة الجملة العامة. كل إجراء فيها يمر
/// بالمتحكم ثم API الجملة؛ لا توجد بيانات تجريبية أو أزرار للعرض فقط.

class WholesaleProCustomersScreen extends StatefulWidget {
  const WholesaleProCustomersScreen({super.key});

  @override
  State<WholesaleProCustomersScreen> createState() => _WholesaleProCustomersScreenState();
}

class _WholesaleProCustomersScreenState extends State<WholesaleProCustomersScreen> {
  WholesaleController get c => Get.find<WholesaleController>();
  final _search = TextEditingController();

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => c.loadCustomers());
  }

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  Future<void> _edit([Map<String, dynamic>? customer]) async {
    final name = TextEditingController(text: '${customer?['full_name'] ?? ''}');
    final phone = TextEditingController(text: '${customer?['phone'] ?? ''}');
    final company = TextEditingController(text: '${customer?['company_name'] ?? ''}');
    final limit = TextEditingController(text: '${customer?['credit_limit'] ?? '0'}');
    final days = TextEditingController(text: '${customer?['payment_terms_days'] ?? '0'}');
    final saved = await showDialog<bool>(
      context: context,
      builder: (dialog) => AlertDialog(
        title: Text(customer == null ? 'عميل جديد' : 'تعديل العميل'),
        content: SingleChildScrollView(child: Column(mainAxisSize: MainAxisSize.min, children: [
          _Input(controller: name, label: 'اسم العميل *', icon: Icons.person_outline),
          _Input(controller: phone, label: 'رقم الهاتف', icon: Icons.phone_outlined),
          _Input(controller: company, label: 'اسم المنشأة', icon: Icons.business_outlined),
          _Input(controller: limit, label: 'حد الائتمان', icon: Icons.account_balance_wallet_outlined, number: true),
          _Input(controller: days, label: 'أيام السداد', icon: Icons.calendar_month_outlined, number: true),
        ])),
        actions: [
          TextButton(onPressed: () => Navigator.pop(dialog, false), child: const Text('إلغاء')),
          FilledButton(onPressed: () => Navigator.pop(dialog, true), child: const Text('حفظ')),
        ],
      ),
    );
    if (saved != true || !mounted) return;
    if (name.text.trim().isEmpty) {
      _message('اسم العميل مطلوب');
      return;
    }
    final body = <String, dynamic>{
      'full_name': name.text.trim(),
      if (phone.text.trim().isNotEmpty) 'phone': phone.text.trim(),
      if (company.text.trim().isNotEmpty) 'company_name': company.text.trim(),
      'credit_limit': limit.text.trim().isEmpty ? '0' : limit.text.trim(),
      'payment_terms_days': days.text.trim().isEmpty ? '0' : days.text.trim(),
    };
    final ok = customer == null
        ? await c.addCustomer(body)
        : await c.updateCustomer(_id(customer), body);
    if (!mounted) return;
    _message(ok ? 'تم حفظ العميل' : c.lastError.value, ok: ok);
  }

  void _message(String text, {bool ok = false}) => ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(text), backgroundColor: ok ? AmialColors.success : AmialColors.red),
      );

  @override
  Widget build(BuildContext context) => Scaffold(
        backgroundColor: AmialColors.background,
        appBar: AppBar(title: const Text('العملاء'), centerTitle: true),
        floatingActionButton: FloatingActionButton.extended(
          onPressed: () => _edit(), icon: const Icon(Icons.person_add_alt_1_outlined), label: const Text('عميل جديد'),
        ),
        body: Obx(() => RefreshIndicator(
              onRefresh: () => c.loadCustomers(search: _search.text.trim()),
              child: ListView(
                physics: const AlwaysScrollableScrollPhysics(),
                padding: const EdgeInsets.fromLTRB(16, 12, 16, 96),
                children: [
                  TextField(
                    controller: _search,
                    decoration: InputDecoration(
                      hintText: 'ابحث بالاسم أو الهاتف أو المنشأة', prefixIcon: const Icon(Icons.search),
                      suffixIcon: IconButton(icon: const Icon(Icons.search), onPressed: () => c.loadCustomers(search: _search.text.trim())),
                    ),
                    onSubmitted: (v) => c.loadCustomers(search: v.trim()),
                  ),
                  const SizedBox(height: 12),
                  if (c.isLoading.value && c.customers.isEmpty) const Center(child: Padding(padding: EdgeInsets.all(32), child: CircularProgressIndicator()))
                  else if (c.customers.isEmpty) const _EmptyState(icon: Icons.groups_outlined, text: 'لا يوجد عملاء بعد')
                  else ...c.customers.map((customer) => Card(
                        child: ListTile(
                          leading: const CircleAvatar(child: Icon(Icons.person_outline)),
                          title: Text('${customer['full_name'] ?? '—'}'),
                          subtitle: Text('${customer['company_name'] ?? customer['phone'] ?? ''}\nحد الائتمان: ${_money(customer['credit_limit'])} ر.ي'),
                          isThreeLine: true,
                          trailing: Text('${_money(customer['current_balance'])} ر.ي', style: const TextStyle(fontWeight: FontWeight.w900, color: AmialColors.primary)),
                          onTap: () => _edit(customer),
                        ),
                      )),
                ],
              ),
            )),
      );
}

class WholesaleProInvoiceCreateScreen extends StatefulWidget {
  const WholesaleProInvoiceCreateScreen({super.key});
  @override
  State<WholesaleProInvoiceCreateScreen> createState() => _WholesaleProInvoiceCreateScreenState();
}

class WholesaleProInvoicesScreen extends StatefulWidget {
  const WholesaleProInvoicesScreen({super.key, this.initialFilter = 'all'});
  final String initialFilter;
  @override
  State<WholesaleProInvoicesScreen> createState() => _WholesaleProInvoicesScreenState();
}

class _WholesaleProInvoicesScreenState extends State<WholesaleProInvoicesScreen> {
  WholesaleController get c => Get.find<WholesaleController>();
  late String _filter;
  @override void initState() { super.initState(); _filter = widget.initialFilter; WidgetsBinding.instance.addPostFrameCallback((_) => _load()); }
  Future<void> _load() => c.loadInvoices(status: _filter == 'all' ? null : _filter, overdueOnly: _filter == 'overdue');
  @override Widget build(BuildContext context) => Scaffold(backgroundColor: AmialColors.background, appBar: AppBar(title: const Text('فواتير الجملة'), centerTitle: true), floatingActionButton: FloatingActionButton.extended(onPressed: () => Get.to(() => const WholesaleProInvoiceCreateScreen()), icon: const Icon(Icons.add), label: const Text('فاتورة جديدة')), body: Obx(() => RefreshIndicator(onRefresh: _load, child: ListView(physics: const AlwaysScrollableScrollPhysics(), padding: const EdgeInsets.fromLTRB(16, 12, 16, 96), children: [Wrap(spacing: 6, children: [for (final item in const [('all', 'الكل'), ('paid', 'مدفوعة'), ('issued', 'قيد السداد'), ('overdue', 'متأخرة')]) ChoiceChip(label: Text(item.$2), selected: _filter == item.$1, onSelected: (_) { setState(() => _filter = item.$1); _load(); })]), const SizedBox(height: 12), if (c.isLoading.value && c.invoices.isEmpty) const Center(child: Padding(padding: EdgeInsets.all(32), child: CircularProgressIndicator())) else if (c.invoices.isEmpty) const _EmptyState(icon: Icons.receipt_long_outlined, text: 'لا توجد فواتير') else ...c.invoices.map((x) { final customer = x['customer'] is Map ? x['customer'] as Map : const {}; return Card(child: ListTile(leading: const Icon(Icons.receipt_long_outlined), title: Text('${x['invoice_number'] ?? '—'}'), subtitle: Text('${customer['full_name'] ?? '—'} • ${x['status'] ?? ''}'), trailing: Text('${_money(x['total_amount'])} ر.ي', style: const TextStyle(fontWeight: FontWeight.w900, color: AmialColors.primary)), onTap: () => Get.to(() => WholesaleProInvoiceDetailsScreen(invoiceId: _id(x))))); })]))));
}

class WholesaleProInvoiceDetailsScreen extends StatefulWidget {
  const WholesaleProInvoiceDetailsScreen({super.key, required this.invoiceId});
  final int invoiceId;
  @override State<WholesaleProInvoiceDetailsScreen> createState() => _WholesaleProInvoiceDetailsScreenState();
}

class _WholesaleProInvoiceDetailsScreenState extends State<WholesaleProInvoiceDetailsScreen> {
  WholesaleController get c => Get.find<WholesaleController>();
  @override void initState() { super.initState(); WidgetsBinding.instance.addPostFrameCallback((_) => c.loadInvoiceDetails(widget.invoiceId)); }
  @override Widget build(BuildContext context) => Scaffold(
    backgroundColor: AmialColors.background,
    appBar: AppBar(title: const Text('تفاصيل الفاتورة'), centerTitle: true, actions: [
      IconButton(tooltip: 'عرض / طباعة PDF', icon: const Icon(Icons.picture_as_pdf_outlined), onPressed: () async {
        final ok = await c.downloadInvoicePdf(widget.invoiceId);
        if (!ok && mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(c.lastError.value)));
      }),
    ]),
    body: Obx(() {
      final invoice = c.currentInvoice.value;
      if (c.isLoading.value && invoice == null) return const Center(child: CircularProgressIndicator());
      if (invoice == null) return _EmptyState(icon: Icons.receipt_long_outlined, text: c.lastError.value.isEmpty ? 'تعذر تحميل الفاتورة' : c.lastError.value);
      final customer = invoice['customer'] is Map ? invoice['customer'] as Map : const {};
      final items = invoice['items'] is List ? invoice['items'] as List : const [];
      return RefreshIndicator(onRefresh: () => c.loadInvoiceDetails(widget.invoiceId), child: ListView(physics: const AlwaysScrollableScrollPhysics(), padding: const EdgeInsets.all(16), children: [
        Card(child: Padding(padding: const EdgeInsets.all(16), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text('${invoice['invoice_number'] ?? '—'}', style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w900)),
          Text('العميل: ${customer['full_name'] ?? '—'}'),
          const SizedBox(height: 8),
          Text('${_money(invoice['total_amount'])} ر.ي', style: const TextStyle(fontSize: 28, fontWeight: FontWeight.w900, color: AmialColors.primary)),
          Text('المتبقي: ${_money(invoice['balance_due'])} ر.ي'),
        ]))),
        const SizedBox(height: 12), const Text('الأصناف', style: TextStyle(fontWeight: FontWeight.w900)),
        ...items.map((raw) { final row = raw as Map; return Card(child: ListTile(title: Text('${row['product_name'] ?? '—'}'), subtitle: Text('${row['quantity'] ?? 0} × ${_money(row['unit_price'])}'), trailing: Text('${_money(row['line_total'])} ر.ي'))); }),
        const SizedBox(height: 8),
        SizedBox(width: double.infinity, child: OutlinedButton.icon(onPressed: () async { final ok = await c.downloadInvoicePdf(widget.invoiceId); if (!ok && mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(c.lastError.value))); }, icon: const Icon(Icons.print_outlined), label: const Text('عرض / طباعة الفاتورة PDF'))),
      ]));
    }),
  );
}

class _WholesaleProInvoiceCreateScreenState extends State<WholesaleProInvoiceCreateScreen> {
  WholesaleController get c => Get.find<WholesaleController>();
  final _discount = TextEditingController();
  final _notes = TextEditingController();
  String _paymentType = 'cash';

  @override
  void initState() {
    super.initState();
    c.clearCart();
    WidgetsBinding.instance.addPostFrameCallback((_) async {
      await c.loadCustomers();
      await c.loadProducts();
    });
  }

  @override
  void dispose() { _discount.dispose(); _notes.dispose(); super.dispose(); }

  double get _discountValue => double.tryParse(_discount.text.trim()) ?? 0;
  double get _total => (c.cartSubtotal - _discountValue).clamp(0, double.infinity).toDouble();

  Future<void> _chooseCustomer() async {
    await showModalBottomSheet<void>(
      context: context,
      builder: (sheet) => SafeArea(child: Obx(() => ListView(
        shrinkWrap: true,
        children: c.customers.map((x) => ListTile(
          leading: const Icon(Icons.person_outline), title: Text('${x['full_name'] ?? '—'}'),
          subtitle: Text('${x['phone'] ?? ''}'),
          onTap: () { c.clearCart(); c.selectedCustomer.value = x; Navigator.pop(sheet); },
        )).toList(),
      ))),
    );
  }

  Future<void> _addProduct() async {
    if (c.selectedCustomer.value == null) { _toast('اختر العميل أولاً'); return; }
    await showModalBottomSheet<void>(
      context: context,
      builder: (sheet) => SafeArea(child: Obx(() => ListView(
        shrinkWrap: true,
        children: c.products.map((x) => ListTile(
          leading: const Icon(Icons.inventory_2_outlined), title: Text('${x['name'] ?? '—'}'),
          subtitle: Text('المتاح ${x['current_stock'] ?? 0} • السعر الأساسي ${_money(x['base_price'])} ر.ي'),
          onTap: () { Navigator.pop(sheet); _quantity(x); },
        )).toList(),
      ))),
    );
  }

  Future<void> _quantity(Map<String, dynamic> product) async {
    final amount = TextEditingController(text: '1');
    final ok = await showDialog<bool>(context: context, builder: (dialog) => AlertDialog(
      title: Text('${product['name'] ?? 'صنف'}'),
      content: _Input(controller: amount, label: 'الكمية', icon: Icons.numbers_outlined, number: true),
      actions: [TextButton(onPressed: () => Navigator.pop(dialog, false), child: const Text('إلغاء')), FilledButton(onPressed: () => Navigator.pop(dialog, true), child: const Text('إضافة'))],
    ));
    if (ok != true) return;
    final qty = double.tryParse(amount.text.trim()) ?? 0;
    if (qty <= 0) { _toast('الكمية يجب أن تكون أكبر من صفر'); return; }
    final added = await c.addToCart(product, qty);
    if (!mounted) return;
    _toast(added ? 'أضيف الصنف بسعر العميل من الخادم' : c.lastError.value, ok: added);
  }

  Future<void> _submit() async {
    if (c.selectedCustomer.value == null || c.cart.isEmpty) { _toast('اختر العميل وأضف صنفاً واحداً على الأقل'); return; }
    if (_discountValue < 0 || _discountValue > c.cartSubtotal) { _toast('خصم الفاتورة غير صحيح'); return; }
    if (_paymentType == 'amial_pay') {
      await Get.to(() => AmialQrCollectScreen(
        amount: _total,
        title: 'تحصيل فاتورة جملة — أميال باي',
        note: _notes.text.trim().isEmpty ? 'تحصيل فاتورة جملة' : _notes.text.trim(),
        createPaymentRequest: c.createInvoicePaymentRequest,
        cancelPaymentRequest: c.cancelWholesalePaymentRequest,
        onPaid: (transactionId) => _create(transactionId),
      ));
      return;
    }
    await _create();
  }

  Future<bool> _create([String? transactionId]) async {
    final ok = await c.createInvoice(
      paymentType: _paymentType, paidTransactionId: transactionId,
      discountAmount: _discount.text.trim(), notes: _notes.text.trim(),
    );
    if (!mounted) return ok;
    _toast(ok ? 'تم إنشاء الفاتورة بنجاح' : c.lastError.value, ok: ok);
    final invoiceId = _id(c.currentInvoice.value ?? const <String, dynamic>{});
    if (ok && invoiceId > 0) {
      Get.off(() => WholesaleProInvoiceDetailsScreen(invoiceId: invoiceId));
    }
    return ok;
  }

  void _toast(String text, {bool ok = false}) => ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(text), backgroundColor: ok ? AmialColors.success : AmialColors.red));

  @override
  Widget build(BuildContext context) => Scaffold(
    backgroundColor: AmialColors.background,
    appBar: AppBar(title: const Text('فاتورة جملة جديدة'), centerTitle: true),
    body: Obx(() => Column(children: [
      Padding(padding: const EdgeInsets.all(16), child: OutlinedButton.icon(
        onPressed: _chooseCustomer, icon: const Icon(Icons.person_search_outlined),
        label: Text(c.selectedCustomer.value == null ? 'اختر العميل *' : '${c.selectedCustomer.value!['full_name']}'),
      )),
      Expanded(child: c.cart.isEmpty ? _EmptyState(icon: Icons.shopping_cart_outlined, text: 'السلة فارغة — أضف أصنافاً بعد اختيار العميل') : ListView.builder(
        itemCount: c.cart.length, itemBuilder: (_, i) { final row = c.cart[i]; final p = row['product'] as Map; final price = double.tryParse('${p['quoted_unit_price'] ?? p['base_price']}') ?? 0; final qty = double.tryParse('${row['quantity']}') ?? 0; return ListTile(
          title: Text('${p['name'] ?? '—'}'), subtitle: Text('${qty.toStringAsFixed(2)} ${p['quoted_unit'] ?? p['unit'] ?? ''} × ${_money(price)}'),
          trailing: IconButton(icon: const Icon(Icons.delete_outline, color: AmialColors.red), onPressed: () => c.removeFromCart(_id(row), unitId: row['unit_id'])),
        ); },
      )),
      Container(padding: const EdgeInsets.all(16), decoration: const BoxDecoration(color: Colors.white, border: Border(top: BorderSide(color: Color(0xFFE3E7EE)))), child: Column(children: [
        Row(children: [OutlinedButton.icon(onPressed: _addProduct, icon: const Icon(Icons.add), label: const Text('إضافة صنف')), const Spacer(), Text('${_money(c.cartSubtotal)} ر.ي', style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w900, color: AmialColors.primary))]),
        _Input(controller: _discount, label: 'خصم الفاتورة', icon: Icons.discount_outlined, number: true),
        _Input(controller: _notes, label: 'ملاحظات', icon: Icons.notes_outlined),
        Wrap(spacing: 6, children: [for (final item in const [('cash', 'نقد'), ('amial_pay', 'أميال باي'), ('credit', 'آجل')]) ChoiceChip(label: Text(item.$2), selected: _paymentType == item.$1, onSelected: (_) => setState(() => _paymentType = item.$1))]),
        const SizedBox(height: 8), Text('الإجمالي ${_money(_total)} ر.ي', style: const TextStyle(fontWeight: FontWeight.w900, color: AmialColors.primary)),
        const SizedBox(height: 8), SizedBox(width: double.infinity, child: FilledButton.icon(onPressed: c.isSubmitting.value ? null : _submit, icon: const Icon(Icons.check_circle_outline), label: const Text('إنشاء الفاتورة'))),
      ])),
    ])),
  );
}

class WholesaleProAgingScreen extends StatefulWidget {
  const WholesaleProAgingScreen({super.key});
  @override
  State<WholesaleProAgingScreen> createState() => _WholesaleProAgingScreenState();
}

class _WholesaleProAgingScreenState extends State<WholesaleProAgingScreen> {
  WholesaleController get c => Get.find<WholesaleController>();
  @override void initState() { super.initState(); WidgetsBinding.instance.addPostFrameCallback((_) => c.loadAgingReport()); }
  @override
  Widget build(BuildContext context) => Scaffold(backgroundColor: AmialColors.background, appBar: AppBar(title: const Text('تقادم الديون'), centerTitle: true), body: Obx(() {
    final report = c.agingReport.value;
    if (c.isLoading.value && report == null) return const Center(child: CircularProgressIndicator());
    if (report == null) return _EmptyState(icon: Icons.analytics_outlined, text: c.lastError.value.isEmpty ? 'لا توجد بيانات تقرير' : c.lastError.value);
    final buckets = report['buckets'] is List ? report['buckets'] as List : const [];
    final customers = report['customers'] is List ? report['customers'] as List : const [];
    return RefreshIndicator(onRefresh: c.loadAgingReport, child: ListView(physics: const AlwaysScrollableScrollPhysics(), padding: const EdgeInsets.all(16), children: [
      _Info(text: 'يعرض التقرير أرصدة العملاء حسب مدة الاستحقاق من خادم الجملة.'),
      const SizedBox(height: 12),
      ...buckets.map((raw) { final b = raw as Map; return Card(child: ListTile(leading: const Icon(Icons.calendar_today_outlined), title: Text('${b['label'] ?? 'شريحة'}'), trailing: Text('${_money(b['amount'])} ر.ي', style: const TextStyle(fontWeight: FontWeight.w900, color: AmialColors.primary)))); }),
      const SizedBox(height: 8), const Text('العملاء المتأخرون', style: TextStyle(fontWeight: FontWeight.w900)),
      ...customers.map((raw) { final x = raw as Map; return Card(child: ListTile(title: Text('${x['full_name'] ?? '—'}'), subtitle: Text('${x['days_overdue'] ?? 0} يوم تأخير'), trailing: Text('${_money(x['balance_due'] ?? x['balance'])} ر.ي'))); }),
    ]));
  }));
}

class WholesaleProSalesRepsScreen extends StatefulWidget {
  const WholesaleProSalesRepsScreen({super.key});
  @override State<WholesaleProSalesRepsScreen> createState() => _WholesaleProSalesRepsScreenState();
}

class _WholesaleProSalesRepsScreenState extends State<WholesaleProSalesRepsScreen> {
  WholesaleController get c => Get.find<WholesaleController>();
  @override void initState() { super.initState(); WidgetsBinding.instance.addPostFrameCallback((_) => c.loadSalesReps()); }
  Future<void> _add() async {
    final name = TextEditingController(); final phone = TextEditingController(); final rate = TextEditingController(text: '0');
    final ok = await showDialog<bool>(context: context, builder: (d) => AlertDialog(title: const Text('مندوب مبيعات جديد'), content: Column(mainAxisSize: MainAxisSize.min, children: [_Input(controller: name, label: 'الاسم *', icon: Icons.badge_outlined), _Input(controller: phone, label: 'الهاتف', icon: Icons.phone_outlined), _Input(controller: rate, label: 'نسبة العمولة', icon: Icons.percent, number: true)]), actions: [TextButton(onPressed: () => Navigator.pop(d, false), child: const Text('إلغاء')), FilledButton(onPressed: () => Navigator.pop(d, true), child: const Text('حفظ'))]));
    if (ok != true || name.text.trim().isEmpty) return;
    final saved = await c.addSalesRep({'full_name': name.text.trim(), 'phone': phone.text.trim(), 'default_commission_rate': rate.text.trim().isEmpty ? '0' : rate.text.trim()});
    if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(saved ? 'تم إضافة المندوب' : c.lastError.value), backgroundColor: saved ? AmialColors.success : AmialColors.red));
  }
  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(title: const Text('مندوبي المبيعات'), centerTitle: true),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: _add,
        icon: const Icon(Icons.person_add_alt),
        label: const Text('مندوب جديد'),
      ),
      body: Obx(() => RefreshIndicator(
        onRefresh: c.loadSalesReps,
        child: ListView(
          physics: const AlwaysScrollableScrollPhysics(),
          padding: const EdgeInsets.fromLTRB(16, 12, 16, 90),
          children: [
            if (c.salesReps.isEmpty)
              const _EmptyState(icon: Icons.badge_outlined, text: 'لا يوجد مندوبون'),
            ...c.salesReps.map((x) => Card(
              child: ListTile(
                leading: const CircleAvatar(child: Icon(Icons.badge_outlined)),
                title: Text('${x['full_name'] ?? '—'}'),
                subtitle: Text('${x['phone'] ?? ''}'),
                trailing: Text('عمولة ${x['default_commission_rate'] ?? 0}%'),
              ),
            )),
          ],
        ),
      )),
    );
  }
}

class WholesaleProSalesRepsReportScreen extends StatefulWidget {
  const WholesaleProSalesRepsReportScreen({super.key});
  @override State<WholesaleProSalesRepsReportScreen> createState() => _WholesaleProSalesRepsReportScreenState();
}

class _WholesaleProSalesRepsReportScreenState extends State<WholesaleProSalesRepsReportScreen> {
  WholesaleController get c => Get.find<WholesaleController>();
  @override void initState() { super.initState(); WidgetsBinding.instance.addPostFrameCallback((_) => c.loadSalesRepsReport()); }
  @override Widget build(BuildContext context) => Scaffold(backgroundColor: AmialColors.background, appBar: AppBar(title: const Text('أداء المندوبين'), centerTitle: true), body: Obx(() { final report = c.salesRepsReport.value; if (c.isLoading.value && report == null) return const Center(child: CircularProgressIndicator()); final reps = report?['sales_reps'] is List ? report!['sales_reps'] as List : const []; return RefreshIndicator(onRefresh: c.loadSalesRepsReport, child: ListView(physics: const AlwaysScrollableScrollPhysics(), padding: const EdgeInsets.all(16), children: [const _Info(text: 'المبيعات والعمولات تُحسب من الفواتير الفعلية، لا من إدخال يدوي.'), const SizedBox(height: 12), if (reps.isEmpty) const _EmptyState(icon: Icons.leaderboard_outlined, text: 'لا توجد نتائج للفترة'), ...reps.map((raw) { final x = raw as Map; final period = x['period'] is Map ? x['period'] as Map : const {}; return Card(child: ListTile(leading: const CircleAvatar(child: Icon(Icons.leaderboard_outlined)), title: Text('${x['full_name'] ?? '—'}'), subtitle: Text('${period['invoices_count'] ?? 0} فاتورة'), trailing: Column(mainAxisAlignment: MainAxisAlignment.center, crossAxisAlignment: CrossAxisAlignment.end, children: [Text('${_money(period['total_sales'])} ر.ي', style: const TextStyle(fontWeight: FontWeight.w900, color: AmialColors.primary)), Text('عمولة ${_money(period['total_commission'])}')]))); })])); }));
}

class WholesaleProReturnsScreen extends StatefulWidget {
  const WholesaleProReturnsScreen({super.key});
  @override State<WholesaleProReturnsScreen> createState() => _WholesaleProReturnsScreenState();
}

class _WholesaleProReturnsScreenState extends State<WholesaleProReturnsScreen> {
  WholesaleController get c => Get.find<WholesaleController>();
  @override void initState() { super.initState(); WidgetsBinding.instance.addPostFrameCallback((_) => c.loadReturns()); }
  Future<void> _resolve(Map<String, dynamic> item, bool approve) async { final ok = await c.resolveReturn(_id(item), approve); if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(ok ? (approve ? 'تم اعتماد المرتجع' : 'تم رفض المرتجع') : c.lastError.value), backgroundColor: ok ? AmialColors.success : AmialColors.red)); }
  @override Widget build(BuildContext context) => Scaffold(backgroundColor: AmialColors.background, appBar: AppBar(title: const Text('مرتجعات الجملة'), centerTitle: true), body: Obx(() => RefreshIndicator(onRefresh: c.loadReturns, child: ListView(physics: const AlwaysScrollableScrollPhysics(), padding: const EdgeInsets.all(16), children: [const _Info(text: 'المرتجع لا يعيد المخزون ولا يخفض الدين قبل اعتماد مسؤول مخوّل.'), const SizedBox(height: 12), if (c.returns.isEmpty) const _EmptyState(icon: Icons.assignment_return_outlined, text: 'لا توجد طلبات مرتجع'), ...c.returns.map((x) { final status = '${x['status'] ?? ''}'; return Card(child: Padding(padding: const EdgeInsets.all(8), child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [ListTile(title: Text('${(x['invoice'] as Map?)?['invoice_number'] ?? '—'}'), subtitle: Text('${(x['customer'] as Map?)?['full_name'] ?? '—'}\n${x['reason'] ?? ''}'), isThreeLine: true, trailing: Text(status)), if (status == 'requested') Row(children: [Expanded(child: OutlinedButton(onPressed: () => _resolve(x, false), child: const Text('رفض'))), const SizedBox(width: 8), Expanded(child: FilledButton(onPressed: () => _resolve(x, true), child: const Text('اعتماد')))])]))); })]))));
}

class WholesaleProReturnRequestScreen extends StatefulWidget {
  const WholesaleProReturnRequestScreen({super.key, required this.invoice});
  final Map<String, dynamic> invoice;
  @override State<WholesaleProReturnRequestScreen> createState() => _WholesaleProReturnRequestScreenState();
}

class _WholesaleProReturnRequestScreenState extends State<WholesaleProReturnRequestScreen> {
  WholesaleController get c => Get.find<WholesaleController>();
  final _reason = TextEditingController();
  final Map<int, TextEditingController> _quantity = {};
  final Set<int> _selected = <int>{};
  @override void initState() { super.initState(); for (final raw in (widget.invoice['items'] as List? ?? const [])) { final row = raw as Map; _quantity[_id(row)] = TextEditingController(text: '1'); } }
  @override void dispose() { _reason.dispose(); for (final x in _quantity.values) { x.dispose(); } super.dispose(); }
  Future<void> _send() async { final rows = _selected.map((id) => {'invoice_item_id': id, 'quantity': _quantity[id]!.text.trim()}).toList(); if (rows.isEmpty || _reason.text.trim().isEmpty) { ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('اختر أصنافاً وأدخل السبب'))); return; } final ok = await c.requestReturn(_id(widget.invoice), {'reason': _reason.text.trim(), 'items': rows}); if (!mounted) return; ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(ok ? 'تم إرسال طلب المرتجع للمراجعة' : c.lastError.value), backgroundColor: ok ? AmialColors.success : AmialColors.red)); if (ok) Get.back(); }
  @override Widget build(BuildContext context) { final items = widget.invoice['items'] as List? ?? const []; return Scaffold(backgroundColor: AmialColors.background, appBar: AppBar(title: const Text('طلب مرتجع'), centerTitle: true), body: ListView(padding: const EdgeInsets.all(16), children: [const _Info(text: 'لن يتغير المخزون أو دين العميل حتى يعتمد مسؤول مخوّل الطلب.'), const SizedBox(height: 12), ...items.map((raw) { final x = raw as Map; final id = _id(x); return Card(child: CheckboxListTile(value: _selected.contains(id), onChanged: (v) => setState(() { if (v == true) _selected.add(id); else _selected.remove(id); }), title: Text('${x['product_name'] ?? '—'}'), subtitle: Text('المباع ${x['quantity'] ?? 0}'), secondary: SizedBox(width: 78, child: _Input(controller: _quantity[id]!, label: 'كمية', icon: Icons.numbers_outlined, number: true)))); }), _Input(controller: _reason, label: 'سبب المرتجع *', icon: Icons.notes_outlined), const SizedBox(height: 8), FilledButton.icon(onPressed: c.isSubmitting.value ? null : _send, icon: const Icon(Icons.send_outlined), label: const Text('إرسال للمراجعة'))])); }
}

class _Input extends StatelessWidget {
  const _Input({required this.controller, required this.label, required this.icon, this.number = false});
  final TextEditingController controller; final String label; final IconData icon; final bool number;
  @override Widget build(BuildContext context) => Padding(padding: const EdgeInsets.only(bottom: 10), child: TextField(controller: controller, keyboardType: number ? const TextInputType.numberWithOptions(decimal: true) : TextInputType.text, decoration: InputDecoration(labelText: label, prefixIcon: Icon(icon), border: const OutlineInputBorder())));
}

class _EmptyState extends StatelessWidget { const _EmptyState({required this.icon, required this.text}); final IconData icon; final String text; @override Widget build(BuildContext context) => Center(child: Padding(padding: const EdgeInsets.all(32), child: Column(mainAxisSize: MainAxisSize.min, children: [Icon(icon, size: 54, color: AmialColors.textMuted), const SizedBox(height: 12), Text(text, textAlign: TextAlign.center)]))); }
class _Info extends StatelessWidget { const _Info({required this.text}); final String text; @override Widget build(BuildContext context) => Container(padding: const EdgeInsets.all(12), decoration: BoxDecoration(color: AmialColors.primary.withValues(alpha: .08), borderRadius: BorderRadius.circular(12)), child: Text(text, style: const TextStyle(color: AmialColors.textSecondary, height: 1.45))); }
int _id(Map value) => int.tryParse('${value['id'] ?? 0}') ?? 0;
String _money(dynamic value) { final n = double.tryParse('${value ?? 0}') ?? 0; return n.toStringAsFixed(0).replaceAllMapped(RegExp(r'(\d)(?=(\d{3})+$)'), (m) => '${m[1]},'); }
