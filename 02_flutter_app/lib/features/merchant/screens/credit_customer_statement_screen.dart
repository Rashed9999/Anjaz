import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:intl/intl.dart';
import 'package:uuid/uuid.dart';
import 'package:qr_flutter/qr_flutter.dart';
import 'package:amial_pay/data/api/api_client.dart';
import 'package:amial_pay/helper/pdf_downloader_helper.dart';
import 'package:amial_pay/theme/amial_colors.dart';
import 'package:amial_pay/util/money_format.dart';
import 'package:amial_pay/features/merchant/controllers/customer_credit_controller.dart';

/// AMIAL-CUSTOMER-CREDIT-001 — كشف حساب عميل + تسجيل سداد/مرتجع.
class CreditCustomerStatementScreen extends StatefulWidget {
  final Map<String, dynamic> customer;
  final bool collectionOnly;
  const CreditCustomerStatementScreen({
    super.key, required this.customer, this.collectionOnly = false,
  });

  @override
  State<CreditCustomerStatementScreen> createState() => _CreditCustomerStatementScreenState();
}

class _CreditCustomerStatementScreenState extends State<CreditCustomerStatementScreen> {
  late final CustomerCreditController c;
  DateTime? _from;
  DateTime? _to;
  bool _busyPdf = false;
  double? _remainingBalance;

  @override
  void initState() {
    super.initState();
    c = Get.find<CustomerCreditController>();
    WidgetsBinding.instance.addPostFrameCallback((_) => _refresh());
  }

  Future<void> _refresh() async {
    final id = widget.customer['id'] as int;
    if (widget.collectionOnly) {
      // POS sees only the requested debtor, never the full owner ledger.
      try {
        final phone = widget.customer['customer_phone']?.toString() ?? '';
        final r = await c.repo.lookupByPhone(phone);
        if (r.statusCode == 200 && r.body is Map && r.body['success'] == true) {
          final account = Map<String, dynamic>.from(r.body['meta'] as Map);
          if (account['found'] == true && account['account_id'] == id && mounted) {
            setState(() => _remainingBalance =
                double.tryParse('${account['current_balance']}'));
          }
        }
      } catch (_) { /* Retain last known amount; the server rechecks it. */ }
      await c.loadPendingCollections(accountId: id);
      return;
    }
    await Future.wait([
      c.loadStatement(
        id,
        from: _from != null ? DateFormat('yyyy-MM-dd').format(_from!) : null,
        to: _to != null ? DateFormat('yyyy-MM-dd').format(_to!) : null,
      ),
      c.loadPendingCollections(accountId: id),
    ]);
  }

  @override
  Widget build(BuildContext context) {
    if (widget.collectionOnly) {
      return Scaffold(
        backgroundColor: AmialColors.background,
        appBar: AppBar(title: Text(widget.customer['customer_name']?.toString()
            ?? 'credit_collect_pos_title'.tr)),
        body: Obx(() {
          final balance = _remainingBalance ??
              (double.tryParse('${widget.customer['current_balance'] ?? 0}') ?? 0);
          return RefreshIndicator(
            onRefresh: _refresh,
            child: ListView(
              padding: const EdgeInsets.all(16),
              children: [
                Container(
                  padding: const EdgeInsets.all(20),
                  decoration: BoxDecoration(color: AmialColors.primary,
                      borderRadius: BorderRadius.circular(16)),
                  child: Text('credit_collect_pos_balance'.trParams({
                    'amount': Money.format(balance),
                  }), style: const TextStyle(color: Colors.white,
                      fontSize: 20, fontWeight: FontWeight.bold)),
                ),
                const SizedBox(height: 16),
                if (balance > 0) _actionsRow(widget.customer),
                if (balance <= 0) Text('credit_collect_pos_no_debt'.tr),
                _pendingCollectionPanel(),
              ],
            ),
          );
        }),
      );
    }
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(
        title: Text(widget.customer['customer_name'] ?? 'كشف حساب'),
      ),
      body: Obx(() {
        if (c.isLoadingStatement.value) {
          return const Center(child: CircularProgressIndicator());
        }
        final s = c.statement.value;
        if (s == null) {
          return const Center(child: Text('لا توجد بيانات'));
        }
        final account = s['account'] as Map? ?? {};
        final movements = (s['movements'] ?? []) as List;
        final totals = (s['totals'] ?? {}) as Map;
        final closing = double.tryParse('${s['closing_balance'] ?? 0}') ?? 0;

        return RefreshIndicator(
          onRefresh: () async => _refresh(),
          child: ListView(
            padding: const EdgeInsets.all(16),
            children: [
              _balanceCard(account, closing),
              const SizedBox(height: 12),
              _dateFilter(),
              const SizedBox(height: 12),
              _totalsRow(totals),
              const SizedBox(height: 12),
              _actionsRow(account),
              _pendingCollectionPanel(),
              const SizedBox(height: 16),
              const Text('الحركات', style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold)),
              const SizedBox(height: 8),
              if (movements.isEmpty)
                const Padding(padding: EdgeInsets.all(20), child: Center(child: Text('لا توجد حركات في هذه الفترة')))
              else
                ...movements.map((m) => _movementTile(m as Map)).toList().reversed,
            ],
          ),
        );
      }),
    );
  }

  Widget _balanceCard(Map account, double closing) {
    final cls = account['classification'] ?? 'bronze';
    final clsColor = cls == 'gold' ? AmialColors.yellowDark
        : cls == 'silver' ? Colors.grey.shade400 : Colors.brown.shade400;
    final lim = double.tryParse('${account['credit_limit'] ?? 0}') ?? 0;
    final util = lim > 0 ? ((closing / lim) * 100).clamp(0, 200).toDouble() : 0.0;

    return Container(
      padding: const EdgeInsets.all(20),
      decoration: BoxDecoration(color: AmialColors.primary, borderRadius: BorderRadius.circular(16)),
      child: Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
        Row(children: [
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
            decoration: BoxDecoration(color: clsColor, borderRadius: BorderRadius.circular(8)),
            child: Text(cls == 'gold' ? '⭐ ذهبي' : cls == 'silver' ? 'فضّي' : 'برونزي',
                style: const TextStyle(color: Colors.white, fontWeight: FontWeight.bold, fontSize: 11)),
          ),
          const Spacer(),
          const Text('الرصيد الحالي', style: TextStyle(color: Colors.white70)),
        ]),
        const SizedBox(height: 10),
        Text(Money.format(closing),
            style: const TextStyle(color: Colors.white, fontSize: 32,
                fontWeight: FontWeight.bold, fontFeatures: Money.features)),
        if (lim > 0) ...[
          const SizedBox(height: 10),
          ClipRRect(
            borderRadius: BorderRadius.circular(4),
            child: LinearProgressIndicator(
              value: (util / 100).clamp(0, 1).toDouble(),
              backgroundColor: Colors.white24,
              valueColor: AlwaysStoppedAnimation(
                util < 60 ? Colors.green : util < 90 ? AmialColors.yellow : AmialColors.red,
              ),
              minHeight: 8,
            ),
          ),
          const SizedBox(height: 4),
          Text('الحد: ${lim.toStringAsFixed(0)} • الاستهلاك ${util.toStringAsFixed(0)}%',
              style: const TextStyle(color: Colors.white70, fontSize: 12)),
        ],
      ]),
    );
  }

  Widget _dateFilter() {
    return Row(children: [
      Expanded(child: _dateBtn('من', _from, (d) { setState(() => _from = d); _refresh(); })),
      const SizedBox(width: 8),
      Expanded(child: _dateBtn('إلى', _to, (d) { setState(() => _to = d); _refresh(); })),
      if (_from != null || _to != null)
        IconButton(
          icon: const Icon(Icons.clear),
          onPressed: () { setState(() { _from = null; _to = null; }); _refresh(); },
        ),
    ]);
  }

  Widget _dateBtn(String label, DateTime? value, void Function(DateTime) onPicked) {
    return OutlinedButton.icon(
      onPressed: () async {
        final picked = await showDatePicker(
          context: context,
          initialDate: value ?? DateTime.now(),
          firstDate: DateTime(2020),
          lastDate: DateTime(2030),
        );
        if (picked != null) onPicked(picked);
      },
      icon: const Icon(Icons.calendar_today, size: 16),
      label: Text(value == null ? label : DateFormat('yyyy-MM-dd').format(value)),
    );
  }

  /// **البطاقتان كانتا متبادلتَي القيمة — وهو أخطرُ ما في هذه الشاشة.**
  ///
  /// ══════════════════════════════════════════════════════════════════
  /// الخادمُ يعرّفهما بتعليق كاتبه في `CustomerCreditService::getStatement`:
  ///
  ///     $debitSum   // ما زاد عليه (مبيعات)      ⇐ مدين
  ///     $creditSum  // ما نزل (سداد/مرتجع)       ⇐ دائن
  ///
  /// وكان المكتوب: بطاقةُ «مدين» تعرض `credit`، وبطاقةُ «دائن» تعرض
  /// `debit`. **فعميلٌ اشترى بألفٍ ومئتين آجلاً ولم يسدّد شيئاً كان
  /// يُعرَض «مدين ٠ · دائن ١٢٠٠»** — أي أنّ الشاشة تقول للتاجر إنّ لا
  /// دَينَ على الرجل، وهو مدينٌ بكلّ المبلغ. (قِيس على حسابٍ حقيقيّ.)
  ///
  /// واللونان كانا مقلوبَين معها: المدينُ أخضرَ والدائنُ أحمر.
  /// **وفي دفتر ديون: ما يزيد الدَّينَ هو الأحمر.**
  Widget _totalsRow(Map totals) {
    return Row(children: [
      Expanded(child: _statCard('مدين (عليه)', totals['debit'] ?? '0', AmialColors.danger)),
      const SizedBox(width: 8),
      Expanded(child: _statCard('دائن (سدّد)', totals['credit'] ?? '0', AmialColors.success)),
    ]);
  }

  Widget _statCard(String title, dynamic value, Color color) {
    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(10)),
      child: Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
        Text(title, style: TextStyle(color: Colors.grey.shade600, fontSize: 12)),
        const SizedBox(height: 4),
        Text(Money.format(value),
            style: TextStyle(color: color, fontWeight: FontWeight.bold,
                fontSize: 16, fontFeatures: Money.features)),
      ]),
    );
  }

  Widget _actionsRow(Map account) {
    if (widget.collectionOnly) {
      return SizedBox(width: double.infinity,
        child: FilledButton.icon(
          onPressed: _collectionDialog,
          icon: const Icon(Icons.payments),
          label: Text('credit_collect_action'.tr),
          style: FilledButton.styleFrom(backgroundColor: Colors.green.shade700,
              minimumSize: const Size.fromHeight(52)),
        ),
      );
    }
    return Column(children: [
      Row(children: [
        Expanded(child: FilledButton.icon(
          onPressed: _collectionDialog,
          icon: const Icon(Icons.payments),
          label: Text('credit_collect_action'.tr),
          style: FilledButton.styleFrom(backgroundColor: Colors.green.shade700),
        )),
        const SizedBox(width: 8),
        Expanded(child: OutlinedButton.icon(
          onPressed: () => _movementDialog('مرتجع', 'return'),
          icon: const Icon(Icons.undo),
          label: const Text('مرتجع'),
        )),
        const SizedBox(width: 8),
        // **كان أيقونةً عاريةً بـtooltip وحده** — و`tooltip` لا يظهر
        // باللمس على الهاتف. فزرٌّ **يعدّل رصيدَ دَينٍ يدويّاً** كان بلا
        // اسمٍ بين «سداد» و«مرتجع» المكتوبَين. والفعلُ الماليُّ يُسمّى.
        Expanded(child: OutlinedButton.icon(
          onPressed: () => _movementDialog('تعديل', 'adjustment'),
          icon: const Icon(Icons.tune, size: 18),
          label: const Text('تعديل'),
          style: OutlinedButton.styleFrom(foregroundColor: AmialColors.warning),
        )),
      ]),
      const SizedBox(height: 8),
      // AMIAL-CREDIT-PDF-001 — زر تحميل/مشاركة كشف PDF
      Row(children: [
        Expanded(child: OutlinedButton.icon(
          onPressed: _busyPdf ? null : () => _downloadPdf(account),
          icon: _busyPdf
              ? const SizedBox(width: 16, height: 16,
                  child: CircularProgressIndicator(strokeWidth: 2))
              : const Icon(Icons.picture_as_pdf),
          label: Text(_busyPdf ? 'جارٍ التحضير…' : 'تحميل كشف PDF'),
          // **كان بالأحمر** — وهو في نظام الألوان للأخطاء والتحذيرات وحدَها.
          // فعلٌ حميدٌ بلون الخطر يُدرّب العينَ على تجاهل الأحمر.
          style: OutlinedButton.styleFrom(
            side: const BorderSide(color: AmialColors.primary),
            foregroundColor: AmialColors.primary,
          ),
        )),
      ]),
    ]);
  }

  /// **الزرُّ كان يكذب: مكتوبٌ عليه «تحميل» وهو ينسخ نصّاً.**
  ///
  /// ══════════════════════════════════════════════════════════════════
  /// وثلاثةُ أعطالٍ مركَّبةٍ فيه، كلٌّ منها يكفي وحده:
  ///
  ///   ① ما نُسخ **ليس رابطاً**: `/api/v1/...` مسارٌ نسبيٌّ بلا بروتوكولٍ
  ///     ولا نطاق. لصقُه في المتصفّح لا يفعل شيئاً — **فالتعليماتُ لا
  ///     تعمل ولو اتّبعها التاجرُ حرفيّاً.**
  ///   ② ولو كُتب كاملاً لَردّ ٤٠١: وسطاءُ النقطة `api · auth:api ·
  ///     amial.idempotency`، والمتصفّحُ الخارجيّ لا يحمل الرمز.
  ///   ③ ولا يفشل: يقول «الرابط جاهز» بلونٍ مطمئن. **ومعطَّلٌ يُرى خيرٌ
  ///     من موهِمٍ ينجح.**
  ///
  /// و`PdfDownloaderHelper` **مبنيٌّ في المشروع وتستعمله أربعُ شاشات** —
  /// الإيصالات والجملة والتقارير وكشف حساب أميال. يجلب عبر عميل الـAPI
  /// المصادَق فيحمل الرمز، ثمّ يكتب ويفتح. القطعتان الطرفيّتان كانتا
  /// تعملان، والوصلةُ بينهما وحدَها مكسورة.
  Future<void> _downloadPdf(Map account) async {
    final id = account['id'];
    if (id == null) return;

    setState(() => _busyPdf = true);

    try {
      final resp = await Get.find<ApiClient>().getData(
        '/api/v1/amial/merchant/credit/customers/$id/statement/pdf',
        headers: {'Accept': 'application/pdf'},
      );

      // **`Response.bodyBytes` في GetX تيّارٌ لا بايتات.** والبايتاتُ في
      // `bodyString`/`body`؛ فيُقرأ التيّارُ إلى `Uint8List` كما يفعل
      // `receipt_detail_screen` تماماً.
      final bytes = await _collect(resp.bodyBytes);

      if (resp.statusCode != 200 || bytes.isEmpty) {
        // **الفشلُ يُقال** — لا «الرابط جاهز» على ملفٍّ لم يصل.
        Get.snackbar('تعذّر تحضير الكشف',
            'لم يصل الملفّ من الخادم (${resp.statusCode}). أعِد المحاولة.',
            backgroundColor: AmialColors.dangerSurface,
            snackPosition: SnackPosition.BOTTOM);
        return;
      }

      await PdfDownloaderHelper.downloadAndOpenPdf(
        pdfData: bytes,
        baseFileName: 'كشف-${account['name'] ?? id}',
      );
    } finally {
      if (mounted) setState(() => _busyPdf = false);
    }
  }

  /// يجمع تيّارَ البايتات إلى `Uint8List` — نفسُ ما تفعله شاشةُ الإيصالات.
  static Future<Uint8List> _collect(Stream<List<int>>? stream) async {
    if (stream == null) return Uint8List(0);

    final chunks = <int>[];
    await for (final c in stream) {
      chunks.addAll(c);
    }

    return Uint8List.fromList(chunks);
  }

  Widget _movementTile(Map m) {
    final amount = '${m['amount']}';
    final isNegative = amount.startsWith('-');
    final type = m['type'];
    final label = type == 'sale' ? 'بيع آجل'
        : type == 'payment' ? 'سداد دفعة'
        : type == 'return' ? 'مرتجع مبيعات'
        : 'تعديل';
    final icon = type == 'sale' ? Icons.shopping_cart
        : type == 'payment' ? Icons.payments
        : type == 'return' ? Icons.undo : Icons.tune;
    // السالبُ سدادٌ أو مرتجع (ينزل الدَّين) ⇐ أخضر. والموجبُ بيعٌ آجل
    // (يزيده) ⇐ أحمر. ومن التوكِنز لا من لوحة Material.
    final color = isNegative ? AmialColors.success : AmialColors.danger;

    return Container(
      margin: const EdgeInsets.only(bottom: 6),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(10)),
      child: Row(children: [
        // المبلغ + الرصيد
        Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(Money.signed(amount),
              style: TextStyle(color: color, fontSize: 16,
                  fontWeight: FontWeight.bold, fontFeatures: Money.features)),
          Text('الرصيد: ${Money.plain(m['balance_after'])}',
              style: TextStyle(color: Colors.grey.shade600, fontSize: 11,
                  fontFeatures: Money.features)),
        ]),
        const Spacer(),
        // الوصف
        Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
          Text(label, style: const TextStyle(fontWeight: FontWeight.bold)),
          if (m['reference_number'] != null)
            // **الرمزُ يُعزَل.** قِيس أنّ `#GHPXVSQZ` يُعرَض `GHPXVSQZ#`:
            // نصٌّ لاتينيٌّ داخل سياقٍ عربيٍّ بلا عزل، فتقفز الشرطةُ إلى
            // آخره. **وهو الرمزُ الذي يُقرأ في الهاتف عند نزاع.**
            Text(Money.isolate('${m['reference_number']}'),
                style: TextStyle(color: Colors.grey.shade600, fontSize: 11)),
          if (m['due_date'] != null)
            Text('استحقاق: ${m['due_date'].toString().substring(0, 10)}',
                style: TextStyle(color: AmialColors.yellowDark, fontSize: 11)),
          if (m['note'] != null && '${m['note']}'.isNotEmpty)
            Text('${m['note']}', style: TextStyle(color: Colors.grey.shade700, fontSize: 11)),
        ])),
        const SizedBox(width: 8),
        Icon(icon, color: color, size: 28),
      ]),
    );
  }

  /// POS cash or customer-authorized Amial payment, with one retry key.
  Widget _pendingCollectionPanel() {
    if (c.pendingCollections.isEmpty) return const SizedBox.shrink();
    return Padding(
      padding: const EdgeInsets.only(top: 16),
      child: Container(
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(
          color: AmialColors.cardSurface,
          borderRadius: BorderRadius.circular(12),
          border: Border.all(color: AmialColors.primary.withValues(alpha: 0.25)),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text('credit_collect_pending_heading'.tr,
                style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16)),
            const SizedBox(height: 8),
            ...c.pendingCollections.map((item) {
              final review = item['needs_review'] == true;
              final amount = Money.format(double.tryParse('${item['paid'] ?? 0}') ?? 0);
              return Padding(
                padding: const EdgeInsets.only(bottom: 8),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Text('credit_collect_pending_amount'.trParams({'amount': amount}),
                        style: const TextStyle(fontWeight: FontWeight.w600)),
                    if (review)
                      Text('credit_collect_review_body'.tr,
                          style: const TextStyle(color: AmialColors.danger)),
                    if (!review)
                      OutlinedButton.icon(
                        onPressed: c.isSubmitting.value ? null
                            : () async => _collectionResult(item),
                        icon: const Icon(Icons.qr_code),
                        label: Text('credit_collect_resume'.tr),
                      ),
                  ],
                ),
              );
            }),
          ],
        ),
      ),
    );
  }

  Future<void> _collectionDialog() async {
    final accountId = widget.customer['id'] as int;
    final amountCtrl = TextEditingController();
    final noteCtrl = TextEditingController();
    final method = 'cash'.obs;
    final requestKey = const Uuid().v4();
    Map<String, dynamic>? collection;
    try {
      final ok = await Get.dialog<bool>(AlertDialog(
        title: Text('credit_collect_title'.tr),
        content: SingleChildScrollView(
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            Text('credit_collect_explainer'.tr),
            const SizedBox(height: 16),
            TextField(
              controller: amountCtrl,
              keyboardType: const TextInputType.numberWithOptions(decimal: true),
              decoration: InputDecoration(labelText: 'credit_collect_amount'.tr),
            ),
            const SizedBox(height: 12),
            Obx(() => DropdownButtonFormField<String>(
              value: method.value,
              decoration: InputDecoration(labelText: 'credit_collect_method'.tr),
              items: [
                DropdownMenuItem(value: 'cash', child: Text('credit_collect_cash'.tr)),
                DropdownMenuItem(value: 'amial_pay', child: Text('credit_collect_amial'.tr)),
              ],
              onChanged: c.isSubmitting.value ? null : (v) {
                if (v != null) method.value = v;
              },
            )),
            const SizedBox(height: 12),
            TextField(
              controller: noteCtrl,
              maxLength: 255,
              decoration: InputDecoration(labelText: 'credit_collect_note'.tr),
            ),
          ]),
        ),
        actions: [
          TextButton(onPressed: () => Get.back(result: false),
              child: Text('credit_collect_cancel'.tr)),
          Obx(() => FilledButton(
            onPressed: c.isSubmitting.value ? null : () async {
              final amount = double.tryParse(amountCtrl.text.trim());
              if (amount == null || amount <= 0) {
                Get.snackbar('credit_collect_amount_invalid_title'.tr, 'credit_collect_amount_invalid'.tr);
                return;
              }
              // Use the live statement rather than a stale customer list card.
              final account = widget.collectionOnly
                  ? null : c.statement.value?['account'] as Map?;
              final debt = _remainingBalance ?? double.tryParse(
                  '${account?['current_balance'] ?? widget.customer['current_balance'] ?? 0}') ?? 0;
              if (debt > 0 && amount > debt) {
                Get.snackbar('credit_collect_amount_exceeds_title'.tr, 'credit_collect_amount_exceeds'.tr);
                return;
              }
              collection = method.value == 'cash'
                  ? await c.collectCash(accountId, amountCtrl.text.trim(),
                      requestKey, note: noteCtrl.text.trim())
                  : await c.requestWallet(accountId, amountCtrl.text.trim(),
                      requestKey);
              if (collection != null) {
                Get.back(result: true);
              } else {
                Get.snackbar('credit_collect_failed'.tr, c.lastError.value,
                    snackPosition: SnackPosition.BOTTOM);
              }
            },
            child: c.isSubmitting.value
                ? const SizedBox(width: 18, height: 18,
                    child: CircularProgressIndicator(strokeWidth: 2,
                        color: Colors.white))
                : Text('credit_collect_continue'.tr),
          )),
        ],
      ));
      if (ok == true && collection != null) {
        _refresh();
        await _collectionResult(collection!);
      }
    } finally {
      amountCtrl.dispose();
      noteCtrl.dispose();
    }
  }

  Future<void> _collectionResult(Map<String, dynamic> collection) async {
    if (collection['status'] == 'completed') {
      final current = double.tryParse('${collection['new_balance']}');
      if (widget.collectionOnly && current != null && mounted) {
        setState(() => _remainingBalance = current);
      }
      await Get.dialog<void>(AlertDialog(
        title: Text('credit_collect_success_title'.tr),
        content: Text('credit_collect_success_details'.trParams({
          'number': collection['receipt_number']?.toString() ?? 'credit_collect_issuing'.tr,
          'balance': Money.format(double.tryParse('${collection['new_balance']}') ?? 0),
        })),
        actions: [
          TextButton(onPressed: () => Get.back(), child: Text('credit_collect_close'.tr)),
          FilledButton.icon(
            onPressed: collection['receipt_id'] == null ? null : () async {
              await _downloadCollectionReceipt(collection['collection_id'] as int);
            },
            icon: const Icon(Icons.picture_as_pdf),
            label: Text('credit_collect_print'.tr),
          ),
        ],
      ));
      return;
    }
    if (collection['needs_review'] == true) {
      Get.snackbar('credit_collect_review_title'.tr, 'credit_collect_review_body'.tr);
      return;
    }
    final url = collection['payment_url']?.toString() ?? '';
    await Get.dialog<void>(AlertDialog(
      title: Text('credit_collect_pending_title'.tr),
      content: SingleChildScrollView(child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Text('credit_collect_code'.trParams({'code': collection['payment_code']?.toString() ?? ''})),
          const SizedBox(height: 12),
          if (url.isNotEmpty) QrImageView(data: url, size: 210),
          const SizedBox(height: 12),
          Text('credit_collect_scan_explainer'.tr),
        ],
      )),
      actions: [
        TextButton(onPressed: () => Get.back(), child: Text('credit_collect_later'.tr)),
        Obx(() => FilledButton(
          onPressed: c.isSubmitting.value ? null : () async {
            final result = await c.confirmWallet(collection['collection_id'] as int);
            if (result == null) {
              Get.snackbar('credit_collect_pending_snackbar'.tr, c.lastError.value,
                  snackPosition: SnackPosition.BOTTOM);
              return;
            }
            Get.back();
            _refresh();
            await _collectionResult(result);
          },
          child: c.isSubmitting.value
              ? const SizedBox(width: 18, height: 18,
                  child: CircularProgressIndicator(strokeWidth: 2,
                      color: Colors.white))
              : Text('credit_collect_verify'.tr),
        )),
      ],
    ));
  }

  Future<void> _downloadCollectionReceipt(int id) async {
    final response = await c.repo.receiptPdf(id);
    final bytes = await _collect(response.bodyBytes);
    if (response.statusCode != 200 || bytes.isEmpty) {
      Get.snackbar('credit_collect_download_failed'.tr, 'credit_collect_download_failed_body'.tr);
      return;
    }
    await PdfDownloaderHelper.downloadAndOpenPdf(
      pdfData: bytes, baseFileName: 'collection-receipt-$id',
    );
  }

  Future<void> _movementDialog(String title, String type) async {
    final amountCtrl = TextEditingController();
    final noteCtrl = TextEditingController();
    final cid = widget.customer['id'] as int;

    final result = await Get.dialog<bool>(AlertDialog(
      title: Text(title),
      content: SingleChildScrollView(child: Column(mainAxisSize: MainAxisSize.min, children: [
        TextField(
          controller: amountCtrl,
          keyboardType: TextInputType.number,
          textAlign: TextAlign.right,
          decoration: const InputDecoration(labelText: 'المبلغ (موقّع للتعديل: +500 أو -200)'),
        ),
        const SizedBox(height: 12),
        TextField(
          controller: noteCtrl,
          textAlign: TextAlign.right,
          decoration: const InputDecoration(labelText: 'ملاحظة'),
        ),
      ])),
      actions: [
        TextButton(onPressed: () => Get.back(result: false), child: const Text('إلغاء')),
        Obx(() => FilledButton(
          onPressed: c.isSubmitting.value ? null : () async {
            bool ok = false;
            if (type == 'payment') {
              ok = await c.recordPayment(cid, amountCtrl.text, note: noteCtrl.text);
            } else if (type == 'return') {
              ok = await c.recordReturn(cid, amountCtrl.text, note: noteCtrl.text);
            } else {
              if (noteCtrl.text.isEmpty) {
                Get.snackbar('تنبيه', 'التعديل اليدوي يحتاج سبباً',
                    backgroundColor: AmialColors.red.withValues(alpha: 0.1));
                return;
              }
              ok = await c.recordAdjustment(cid, amountCtrl.text, noteCtrl.text);
            }
            if (ok) {
              Get.back(result: true);
            } else {
              Get.snackbar('فشل', c.lastError.value, backgroundColor: AmialColors.red.withValues(alpha: 0.1));
            }
          },
          child: c.isSubmitting.value
              ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
              : const Text('تأكيد'),
        )),
      ],
    ));

    if (result == true) _refresh();
  }
}
