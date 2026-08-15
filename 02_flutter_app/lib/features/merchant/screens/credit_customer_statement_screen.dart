import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:intl/intl.dart';
import 'package:amial_pay/data/api/api_client.dart';
import 'package:amial_pay/helper/pdf_downloader_helper.dart';
import 'package:amial_pay/theme/amial_colors.dart';
import 'package:amial_pay/util/money_format.dart';
import 'package:amial_pay/features/merchant/controllers/customer_credit_controller.dart';

/// AMIAL-CUSTOMER-CREDIT-001 — كشف حساب عميل + تسجيل سداد/مرتجع.
class CreditCustomerStatementScreen extends StatefulWidget {
  final Map<String, dynamic> customer;
  const CreditCustomerStatementScreen({super.key, required this.customer});

  @override
  State<CreditCustomerStatementScreen> createState() => _CreditCustomerStatementScreenState();
}

class _CreditCustomerStatementScreenState extends State<CreditCustomerStatementScreen> {
  late final CustomerCreditController c;
  DateTime? _from;
  DateTime? _to;
  bool _busyPdf = false;

  @override
  void initState() {
    super.initState();
    c = Get.find<CustomerCreditController>();
    WidgetsBinding.instance.addPostFrameCallback((_) => _refresh());
  }

  void _refresh() {
    c.loadStatement(
      widget.customer['id'] as int,
      from: _from != null ? DateFormat('yyyy-MM-dd').format(_from!) : null,
      to: _to != null ? DateFormat('yyyy-MM-dd').format(_to!) : null,
    );
  }

  @override
  Widget build(BuildContext context) {
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
    return Column(children: [
      Row(children: [
        Expanded(child: FilledButton.icon(
          onPressed: () => _movementDialog('سداد', 'payment'),
          icon: const Icon(Icons.payments),
          label: const Text('سداد'),
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
