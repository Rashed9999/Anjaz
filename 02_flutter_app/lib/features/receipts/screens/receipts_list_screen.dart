import 'dart:async';

import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amial_pay/common/widgets/amial_ltr_number.dart';
import 'package:amial_pay/features/receipts/controllers/receipts_controller.dart';
import 'package:amial_pay/features/receipts/domain/models/receipt_models.dart';
import 'package:amial_pay/features/receipts/screens/receipt_detail_screen.dart';
import 'package:amial_pay/features/shared/utils/operation_status.dart';
import 'package:amial_pay/theme/amial_colors.dart';

/// AMIAL-RECEIPTS-001 (v0.9-D)
///
/// قائمة الإيصالات — chronological list مع icon لكل نوع.
class ReceiptsListScreen extends StatefulWidget {
  const ReceiptsListScreen({super.key});

  @override
  State<ReceiptsListScreen> createState() => _ReceiptsListScreenState();
}

class _ReceiptsListScreenState extends State<ReceiptsListScreen> {
  final ScrollController _scrollController = ScrollController();
  final TextEditingController _searchCtrl = TextEditingController();

  /// **مؤقّتٌ يمنع نداءً لكلّ حرف.** بلا هذا يُطلق من يكتب ٩ أرقامٍ تسعةَ
  /// نداءات، ويصل ردُّ الرابع بعد السابع فيعرض نتائجَ رقمٍ ناقص.
  Timer? _debounce;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      Get.find<ReceiptsController>().loadReceipts(refresh: true);
    });
    _scrollController.addListener(_onScroll);
  }

  void _onScroll() {
    final ctrl = Get.find<ReceiptsController>();
    if (_scrollController.position.pixels >=
            _scrollController.position.maxScrollExtent - 200 &&
        !ctrl.isLoadingMore.value &&
        ctrl.hasMore.value) {
      ctrl.loadReceipts();
    }
  }

  @override
  void dispose() {
    _debounce?.cancel();
    _searchCtrl.dispose();
    _scrollController.removeListener(_onScroll);
    _scrollController.dispose();
    super.dispose();
  }

  void _onSearchChanged(String v) {
    _debounce?.cancel();
    _debounce = Timer(const Duration(milliseconds: 400), () {
      final ctrl = Get.find<ReceiptsController>();
      ctrl.query.value = v;
      ctrl.loadReceipts(refresh: true);
    });
  }


  // ── شريطُ الاتّجاه ──────────────────────────────────────────────────
  //
  // **أكثرُ فلترٍ يُطلب**: «أرني الصادر» أو «أرني الوارد». فيُعرض دائماً
  // لا يُخبَّأ في نافذة.
  Widget _directionStrip() {
    return Obx(() {
      final ctrl = Get.find<ReceiptsController>();

      Widget chip(String label, String value, IconData icon) => Padding(
            padding: const EdgeInsets.only(left: 8),
            child: FilterChip(
              key: Key('receipts-dir-${value.isEmpty ? 'all' : value}'),
              selected: ctrl.direction.value == value,
              avatar: Icon(icon, size: 16),
              label: Text(label),
              onSelected: (_) {
                ctrl.direction.value = value;
                ctrl.loadReceipts(refresh: true);
              },
            ),
          );

      return SizedBox(
        height: 44,
        child: ListView(
          scrollDirection: Axis.horizontal,
          padding: const EdgeInsets.symmetric(horizontal: 12),
          children: [
            chip('الكل', '', Icons.all_inclusive_rounded),
            chip('صادر', 'debit', Icons.arrow_upward_rounded),
            chip('وارد', 'credit', Icons.arrow_downward_rounded),
          ],
        ),
      );
    });
  }

  // ── نافذةُ الفلاتر ─────────────────────────────────────────────────

  Future<void> _openFilters() async {
    final ctrl = Get.find<ReceiptsController>();

    String type = ctrl.typeFilter.value;
    String from = ctrl.fromDate.value;
    String to = ctrl.toDate.value;
    final minCtrl = TextEditingController(text: ctrl.minAmount.value);
    final maxCtrl = TextEditingController(text: ctrl.maxAmount.value);

    String fmt(DateTime d) =>
        '${d.year}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';

    final applied = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.white,
      shape: const RoundedRectangleBorder(
          borderRadius: BorderRadius.vertical(top: Radius.circular(20))),
      builder: (ctx) => StatefulBuilder(builder: (ctx, setSheet) {
        Future<void> pick(bool isFrom) async {
          final d = await showDatePicker(
            context: ctx,
            initialDate: DateTime.now(),
            firstDate: DateTime(2024),
            lastDate: DateTime.now().add(const Duration(days: 1)),
          );
          if (d != null) setSheet(() => isFrom ? from = fmt(d) : to = fmt(d));
        }

        return Padding(
          padding: EdgeInsets.only(
            left: 16, right: 16, top: 16,
            bottom: MediaQuery.of(ctx).viewInsets.bottom + 16,
          ),
          child: SingleChildScrollView(
            child: Column(mainAxisSize: MainAxisSize.min, children: [
              Container(width: 40, height: 4,
                  decoration: BoxDecoration(
                      color: AmialColors.border,
                      borderRadius: BorderRadius.circular(2))),
              const SizedBox(height: 16),
              const Text('تصفية الإيصالات',
                  style: TextStyle(fontSize: 17, fontWeight: FontWeight.bold)),
              const SizedBox(height: 16),

              DropdownButtonFormField<String>(
                key: const Key('receipts-filter-type'),
                initialValue: type.isEmpty ? null : type,
                decoration: const InputDecoration(
                    labelText: 'نوع العملية', isDense: true),
                items: const [
                  DropdownMenuItem(value: '', child: Text('كل الأنواع')),
                  DropdownMenuItem(value: 'send_money', child: Text('تحويل أموال')),
                  DropdownMenuItem(value: 'cash_in', child: Text('إيداع نقدي')),
                  DropdownMenuItem(value: 'cash_out', child: Text('سحب نقدي')),
                  DropdownMenuItem(value: 'merchant_pay', child: Text('دفع لتاجر')),
                  DropdownMenuItem(value: 'safe_payment', child: Text('دفع آمن')),
                ],
                onChanged: (v) => setSheet(() => type = v ?? ''),
              ),
              const SizedBox(height: 14),

              Row(children: [
                Expanded(
                  child: OutlinedButton.icon(
                    key: const Key('receipts-filter-from'),
                    onPressed: () => pick(true),
                    icon: const Icon(Icons.calendar_today_rounded, size: 16),
                    label: Text(from.isEmpty ? 'من تاريخ' : from,
                        style: const TextStyle(fontSize: 13)),
                  ),
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: OutlinedButton.icon(
                    key: const Key('receipts-filter-to'),
                    onPressed: () => pick(false),
                    icon: const Icon(Icons.event_rounded, size: 16),
                    label: Text(to.isEmpty ? 'إلى تاريخ' : to,
                        style: const TextStyle(fontSize: 13)),
                  ),
                ),
              ]),
              const SizedBox(height: 14),

              Row(children: [
                Expanded(
                  child: TextField(
                    key: const Key('receipts-filter-min'),
                    controller: minCtrl,
                    keyboardType: TextInputType.number,
                    decoration: const InputDecoration(
                        labelText: 'أقل مبلغ', isDense: true),
                  ),
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: TextField(
                    key: const Key('receipts-filter-max'),
                    controller: maxCtrl,
                    keyboardType: TextInputType.number,
                    decoration: const InputDecoration(
                        labelText: 'أعلى مبلغ', isDense: true),
                  ),
                ),
              ]),
              const SizedBox(height: 20),

              Row(children: [
                Expanded(
                  child: OutlinedButton(
                    key: const Key('receipts-filter-reset'),
                    onPressed: () {
                      setSheet(() {
                        type = ''; from = ''; to = '';
                        minCtrl.clear(); maxCtrl.clear();
                      });
                    },
                    child: const Text('مسح'),
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  flex: 2,
                  child: ElevatedButton(
                    key: const Key('receipts-filter-apply'),
                    style: ElevatedButton.styleFrom(
                        backgroundColor: AmialColors.primary,
                        foregroundColor: Colors.white,
                        minimumSize: const Size.fromHeight(46)),
                    onPressed: () => Navigator.pop(ctx, true),
                    child: const Text('تطبيق'),
                  ),
                ),
              ]),
              const SizedBox(height: 8),
            ]),
          ),
        );
      }),
    );

    if (applied != true) return;

    ctrl.typeFilter.value = type;
    ctrl.fromDate.value = from;
    ctrl.toDate.value = to;
    ctrl.minAmount.value = minCtrl.text.trim();
    ctrl.maxAmount.value = maxCtrl.text.trim();

    await ctrl.loadReceipts(refresh: true);
    if (mounted) setState(() {});
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(
        title: const Text('الإيصالات'),
        actions: [
          Obx(() {
            final ctrl = Get.find<ReceiptsController>();
            final n = ctrl.activeFilterCount;

            return Stack(alignment: Alignment.center, children: [
              IconButton(
                key: const Key('receipts-filter-btn'),
                tooltip: 'تصفية',
                onPressed: _openFilters,
                icon: const Icon(Icons.tune_rounded),
              ),
              // **عدُّ الفلاتر يُعرض** — فلترٌ منسيٌّ يُخفي النتائج ويبدو
              // العطلُ في النظام لا في الفلتر.
              if (n > 0)
                Positioned(
                  top: 8, right: 6,
                  child: Container(
                    padding: const EdgeInsets.all(4),
                    decoration: const BoxDecoration(
                        color: Colors.red, shape: BoxShape.circle),
                    child: Text('$n',
                        style: const TextStyle(
                            color: Colors.white, fontSize: 9,
                            fontWeight: FontWeight.bold)),
                  ),
                ),
            ]);
          }),
        ],
        bottom: PreferredSize(
          preferredSize: const Size.fromHeight(104),
          child: Column(children: [
            Padding(
              padding: const EdgeInsets.fromLTRB(12, 0, 12, 8),
              child: TextField(
                key: const Key('receipts-search'),
                controller: _searchCtrl,
                onChanged: _onSearchChanged,
                textInputAction: TextInputAction.search,
                decoration: InputDecoration(
                  hintText: 'ابحث برقم الهاتف أو الاسم أو رقم العملية',
                  prefixIcon: const Icon(Icons.search_rounded),
                  suffixIcon: _searchCtrl.text.isEmpty
                      ? null
                      : IconButton(
                          key: const Key('receipts-search-clear'),
                          icon: const Icon(Icons.close_rounded),
                          onPressed: () {
                            _searchCtrl.clear();
                            _onSearchChanged('');
                            setState(() {});
                          },
                        ),
                  isDense: true,
                  filled: true,
                  fillColor: Colors.white,
                  border: OutlineInputBorder(
                    borderRadius: BorderRadius.circular(10),
                    borderSide: BorderSide.none,
                  ),
                ),
              ),
            ),
            _directionStrip(),
          ]),
        ),
      ),
      body: RefreshIndicator(
        onRefresh: () => Get.find<ReceiptsController>().loadReceipts(refresh: true),
        color: AmialColors.primary,
        child: Obx(() {
          final ctrl = Get.find<ReceiptsController>();

          if (ctrl.isLoading.value && ctrl.receipts.isEmpty) {
            return const Center(
              child: CircularProgressIndicator(color: AmialColors.primary),
            );
          }

          if (ctrl.receipts.isEmpty) {
            return ListView(
              children: [
                SizedBox(height: MediaQuery.of(context).size.height * 0.2),
                Icon(Icons.receipt_long_outlined,
                    size: 80, color: AmialColors.textMuted.withValues(alpha: 0.5)),
                const SizedBox(height: 16),
                // **«لا نتائج للبحث» ليست «لا إيصالات بعد».**
                //
                // والأولى تُرسل الباحثَ يوسّع بحثَه، والثانية تُقنعه أنّ
                // العمليّة لم تقع أصلاً. وخلطُهما يجعل من يبحث عن تحويلٍ
                // نفّذه بالأمس يظنّ أنّ النظام ابتلعه.
                Text(
                  ctrl.lastError.value.isNotEmpty
                      ? ctrl.lastError.value
                      : (ctrl.hasAnyFilter
                          ? 'لا نتائج مطابقة'
                          : 'لا توجد إيصالات بعد'),
                  textAlign: TextAlign.center,
                  style: TextStyle(color: AmialColors.textSecondary, fontSize: 14),
                ),
                if (ctrl.hasAnyFilter && ctrl.lastError.value.isEmpty) ...[
                  const SizedBox(height: 6),
                  Text('جرّب توسيع البحث أو امسح الفلاتر',
                      textAlign: TextAlign.center,
                      style: TextStyle(color: AmialColors.textMuted, fontSize: 12)),
                ],
                const SizedBox(height: 12),
                Center(
                  child: ctrl.hasAnyFilter
                      ? TextButton.icon(
                          key: const Key('receipts-clear-filters'),
                          onPressed: () {
                            _searchCtrl.clear();
                            ctrl.clearFilters();
                            ctrl.loadReceipts(refresh: true);
                            setState(() {});
                          },
                          icon: const Icon(Icons.filter_alt_off_rounded),
                          label: const Text('مسح البحث والفلاتر'),
                        )
                      : TextButton(
                          onPressed: () => ctrl.loadReceipts(refresh: true),
                          child: const Text('إعادة المحاولة'),
                        ),
                ),
              ],
            );
          }

          return ListView.separated(
            controller: _scrollController,
            padding: const EdgeInsets.symmetric(vertical: 8),
            itemCount: ctrl.receipts.length + (ctrl.hasMore.value ? 1 : 0),
            separatorBuilder: (_, _) => const Divider(height: 1, color: AmialColors.border),
            itemBuilder: (context, index) {
              if (index >= ctrl.receipts.length) {
                return const Padding(
                  padding: EdgeInsets.symmetric(vertical: 16),
                  child: Center(
                      child: CircularProgressIndicator(color: AmialColors.primary)),
                );
              }
              return _ReceiptListTile(receipt: ctrl.receipts[index]);
            },
          );
        }),
      ),
    );
  }
}

class _ReceiptListTile extends StatelessWidget {
  final AmialReceipt receipt;
  const _ReceiptListTile({required this.receipt});

  @override
  Widget build(BuildContext context) {
    final isCredit = receipt.direction == 'credit';
    final amountColor = isCredit ? Colors.green.shade700 : AmialColors.red;
    final iconData = _iconForType(receipt.receiptType);

    return ListTile(
      tileColor: Colors.white,
      contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 4),
      leading: CircleAvatar(
        backgroundColor: AmialColors.yellow.withValues(alpha: 0.25),
        child: Icon(iconData, color: AmialColors.primary, size: 20),
      ),
      title: Text(
        receipt.arabicTypeLabel,
        style: const TextStyle(fontWeight: FontWeight.w600),
      ),
      subtitle: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            receipt.receiptNumber,
            style: TextStyle(
              fontSize: 11,
              color: AmialColors.textMuted,
              fontFamily: 'monospace',
            ),
          ),
          if (receipt.issuedAt != null)
            Text(
              _formatDate(receipt.issuedAt!),
              style: TextStyle(fontSize: 11, color: AmialColors.textSecondary),
            ),
        ],
      ),
      trailing: Column(
        mainAxisAlignment: MainAxisAlignment.center,
        crossAxisAlignment: CrossAxisAlignment.end,
        children: [
          // AMIAL-RTL-SIGN-001: مبلغ بإشارة يُعرض باتجاه لاتيني صريح.
          AmialLtrNumber(
            '${isCredit ? '+' : '-'}${_fmtAmount(receipt.amount)}',
            textAlign: TextAlign.end,
            style: TextStyle(
              fontWeight: FontWeight.bold,
              color: amountColor,
              fontSize: 14,
            ),
          ),
          // AMIAL-OP-STATUS-001: نعرض حالة العملية الحقيقية (مكتملة/ملغية/قيد
          // المراجعة/قيد التحضير) لا حالة توليد الـPDF الداخلية — فالإيصال لا
          // يُصدَر إلا بعد اكتمال العملية. الشارة موحّدة اللون عبر كل الشاشات.
          Padding(
            padding: const EdgeInsets.only(top: 2),
            child: OperationStatus.of(receipt.opStatus).chip(fontSize: 9),
          ),
        ],
      ),
      onTap: () {
        Get.to(() => ReceiptDetailScreen(receiptId: receipt.id));
      },
    );
  }

  IconData _iconForType(String type) {
    switch (type) {
      case 'send_money': return Icons.send;
      case 'cash_in': return Icons.arrow_downward;
      case 'cash_out': return Icons.arrow_upward;
      case 'add_money': return Icons.add_circle_outline;
      case 'pay_merchant':
      case 'pos_payment':
      case 'qr_payment': return Icons.store;
      case 'refund': return Icons.replay;
      case 'family_fund_contribute':
      case 'family_fund_disburse': return Icons.diversity_3;
      case 'safe_payment_funded':
      case 'safe_payment_released':
      case 'safe_payment_refunded': return Icons.lock;
      case 'fee_charge': return Icons.receipt;
      default: return Icons.receipt_long;
    }
  }

  String _fmtAmount(String s) {
    final v = double.tryParse(s) ?? 0;
    return v.toStringAsFixed(2);
  }

  String _formatDate(DateTime d) {
    final months = ['يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو',
                    'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'];
    return '${d.day} ${months[d.month - 1]} ${d.year} • ${d.hour.toString().padLeft(2, '0')}:${d.minute.toString().padLeft(2, '0')}';
  }
}
