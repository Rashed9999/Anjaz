import 'package:flutter/material.dart';
import 'package:get/get.dart';

import 'package:amial_pay/common/widgets/amial_ltr_number.dart';
import 'package:amial_pay/features/requested_money/controllers/payment_request_controller.dart';
import 'package:amial_pay/helper/amial_money.dart';
import 'package:amial_pay/theme/amial_colors.dart';

/// AMIAL-REQUEST-DIRECT-003 — «طلباتي المرسلة»: أين ذهب ما طلبتُه.
///
/// ══════════════════════════════════════════════════════════════════════
/// **الشاشةُ الثانية التي لم تكن موجودة.** `PaymentRequestController.outgoing`
/// مبنيّة، و`listForUser(direction: 'outgoing')` مبنيّة في الخلفية —
/// **ولم تقرأهما شاشةٌ واحدة** (قِيس بالبحث: لا نداءَ لـ`loadList('outgoing')`
/// في التطبيق كلّه).
///
/// فمن أرسل طلباً خرج من شاشة النتيجة ولم يجد له أثراً: لا يعرف أوُوفق عليه
/// أم رُفض أم ما زال معلّقاً، ولا يملك سحبَه. **فيسأل بواتساب** — وهو نفسُ
/// الطريق الذي أُريد الخلاصُ منه.
///
/// (نمطُ العطل الأكثر تكراراً هنا: مبنيٌّ ولا يُوصَل إليه.)
class OutgoingRequestsScreen extends StatefulWidget {
  const OutgoingRequestsScreen({super.key});

  @override
  State<OutgoingRequestsScreen> createState() => _OutgoingRequestsScreenState();
}

class _OutgoingRequestsScreenState extends State<OutgoingRequestsScreen> {
  PaymentRequestController get c => Get.find<PaymentRequestController>();
  final ScrollController _scrollController = ScrollController();
  String? _selectedStatus;

  static const List<(String?, String)> _filters = [
    (null, 'الكل'),
    ('pending', 'معلّقة'),
    ('paid', 'مدفوعة'),
    ('declined', 'مرفوضة'),
    ('cancelled', 'ملغاة'),
    ('expired', 'منتهية'),
  ];

  @override
  void initState() {
    super.initState();
    _scrollController.addListener(_onScroll);
    WidgetsBinding.instance.addPostFrameCallback((_) => _reload());
  }

  @override
  void dispose() {
    _scrollController.removeListener(_onScroll);
    _scrollController.dispose();
    super.dispose();
  }

  void _onScroll() {
    if (_scrollController.position.pixels >=
        _scrollController.position.maxScrollExtent - 120) {
      c.loadMoreOutgoing();
    }
  }

  /// بلا `status` افتراضياً: **المعلَّقُ والمدفوعُ والمرفوضُ في مكانٍ واحد.**
  /// ويمكن التصفية بالحالة عبر شرائح الفلترة.
  Future<void> _reload() => _selectedStatus == null
      ? c.loadList('outgoing')
      : c.loadList('outgoing', status: _selectedStatus);

  String _formatDateTime(DateTime dt) {
    final local = dt.toLocal();
    final y = local.year.toString().padLeft(4, '0');
    final m = local.month.toString().padLeft(2, '0');
    final d = local.day.toString().padLeft(2, '0');
    final h = local.hour.toString().padLeft(2, '0');
    final min = local.minute.toString().padLeft(2, '0');
    return '$y-$m-$d $h:$min';
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(title: const Text('طلباتي المرسلة')),
      body: Column(
        children: [
          _filterChips(),
          Expanded(
            child: Obx(() {
              if (c.isLoadingOutgoing.value && c.outgoing.isEmpty) {
                return const Center(child: CircularProgressIndicator());
              }

              if (c.lastErrorOutgoing.value.isNotEmpty && c.outgoing.isEmpty) {
                return _error(c.lastErrorOutgoing.value);
              }

              if (c.outgoing.isEmpty) {
                return RefreshIndicator(
                  onRefresh: _reload,
                  child: _empty(),
                );
              }

              final showLoadMore = c.hasMoreOutgoing.value || c.isLoadingMoreOutgoing.value;
              return RefreshIndicator(
                onRefresh: _reload,
                child: ListView.separated(
                  controller: _scrollController,
                  physics: const AlwaysScrollableScrollPhysics(),
                  padding: const EdgeInsets.all(16),
                  itemCount: c.outgoing.length + (showLoadMore ? 1 : 0),
                  separatorBuilder: (_, __) => const SizedBox(height: 10),
                  itemBuilder: (_, i) {
                    if (i >= c.outgoing.length) {
                      return Center(
                        child: Padding(
                          padding: const EdgeInsets.symmetric(vertical: 12),
                          child: c.isLoadingMoreOutgoing.value
                              ? const CircularProgressIndicator()
                              : TextButton(
                                  onPressed: c.loadMoreOutgoing,
                                  child: const Text('تحميل المزيد'),
                                ),
                        ),
                      );
                    }
                    return _card(c.outgoing[i]);
                  },
                ),
              );
            }),
          ),
        ],
      ),
    );
  }

  Widget _filterChips() => SizedBox(
        height: 52,
        child: ListView.separated(
          scrollDirection: Axis.horizontal,
          reverse: true,
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
          itemCount: _filters.length,
          separatorBuilder: (_, __) => const SizedBox(width: 8),
          itemBuilder: (_, index) {
            final (statusKey, label) = _filters[index];
            final selected = _selectedStatus == statusKey;
            return ChoiceChip(
              label: Text(label),
              selected: selected,
              selectedColor: AmialColors.primary.withValues(alpha: 0.14),
              labelStyle: TextStyle(
                fontSize: 12.5,
                fontWeight: selected ? FontWeight.bold : FontWeight.normal,
                color: selected ? AmialColors.primary : Colors.black87,
              ),
              onSelected: (isSelected) {
                if (!isSelected || _selectedStatus == statusKey) return;
                setState(() => _selectedStatus = statusKey);
                _reload();
              },
            );
          },
        ),
      );

  Widget _error(String message) => Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            const Icon(Icons.cloud_off_outlined, size: 52, color: AmialColors.red),
            const SizedBox(height: 12),
            Text(message, textAlign: TextAlign.center),
            const SizedBox(height: 12),
            FilledButton.icon(
              onPressed: _reload,
              icon: const Icon(Icons.refresh),
              label: const Text('إعادة المحاولة'),
            ),
          ]),
        ),
      );

  Widget _empty() => ListView(
        physics: const AlwaysScrollableScrollPhysics(),
        children: [
          const SizedBox(height: 120),
          Icon(Icons.outbox_outlined, size: 64, color: Colors.grey.shade400),
          const SizedBox(height: 12),
          Text(
            _selectedStatus == null ? 'لم تطلب مالاً بعد' : 'لا توجد طلبات بهذه الحالة',
            textAlign: TextAlign.center,
            style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w600),
          ),
          const SizedBox(height: 6),
          Text('ما تطلبه من غيرك يظهر هنا حتى يوافق أو يرفض',
              textAlign: TextAlign.center,
              style: TextStyle(fontSize: 12.5, color: Colors.grey.shade600)),
        ],
      );

  /// الحالةُ بلونها ونصّها العربيّ — **ولا حالةَ بلا اسم**: `status`
  /// مجهولةٌ تُعرض كما جاءت لا تُبتلع صامتةً.
  (String, Color, IconData) _statusOf(String s) => switch (s) {
        'pending' => ('بانتظار موافقته', Colors.orange.shade800, Icons.hourglass_top_rounded),
        'paid' => ('دُفع', AmialColors.success, Icons.check_circle_rounded),
        'declined' => ('رفضه', AmialColors.red, Icons.cancel_rounded),
        'cancelled' => ('سحبتَه', Colors.grey.shade700, Icons.undo_rounded),
        'expired' => ('انتهت مدّته', Colors.grey.shade700, Icons.timer_off_rounded),
        _ => (s.isEmpty ? 'غير معروفة' : s, Colors.grey.shade700, Icons.help_outline),
      };

  Widget _card(Map<String, dynamic> r) {
    final id = r['id'] as int?;
    final amount = '${r['amount'] ?? '0'}';
    final to = (r['recipient_label'] ?? r['recipient_name'] ?? r['recipient_phone'] ?? '—')
        .toString();
    final note = (r['note'] ?? '').toString();
    final expiresAt = DateTime.tryParse('${r['expires_at'] ?? ''}');
    final isExpired = r['is_expired'] == true ||
        r['status'] == 'expired' ||
        (r['status'] == 'pending' &&
            expiresAt != null &&
            expiresAt.isBefore(DateTime.now()));
    final status = isExpired ? 'expired' : (r['status'] ?? '').toString();
    final (label, color, icon) = _statusOf(status);

    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: AmialColors.border),
      ),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Row(children: [
          const Icon(Icons.call_made, color: AmialColors.primary, size: 20),
          const SizedBox(width: 8),
          Expanded(
            child: Text('طلبتَ من $to',
                style: const TextStyle(fontSize: 14, fontWeight: FontWeight.w600)),
          ),
        ]),
        const SizedBox(height: 10),
        // AMIAL-RTL-SIGN-001: المبالغ تُلفّ باتجاه لاتينيّ داخل واجهة عربية.
        AmialLtrNumber(AmialMoney.yer(amount),
            style: const TextStyle(
                fontSize: 24, fontWeight: FontWeight.bold, color: AmialColors.primary)),
        if (note.isNotEmpty) ...[
          const SizedBox(height: 6),
          Text(note, style: TextStyle(fontSize: 12.5, color: Colors.grey.shade700)),
        ],
        if (expiresAt != null && (status == 'pending' || status == 'expired')) ...[
          const SizedBox(height: 6),
          Row(
            children: [
              Icon(
                isExpired ? Icons.timer_off_outlined : Icons.schedule_outlined,
                size: 14,
                color: isExpired ? Colors.grey.shade600 : AmialColors.textSecondary,
              ),
              const SizedBox(width: 4),
              Text(
                isExpired
                    ? 'انتهت الصلاحية (${_formatDateTime(expiresAt)})'
                    : 'ينتهي في ${_formatDateTime(expiresAt)}',
                style: TextStyle(
                  fontSize: 11.5,
                  color: isExpired ? Colors.grey.shade600 : AmialColors.textSecondary,
                ),
              ),
            ],
          ),
        ],
        const SizedBox(height: 12),
        Row(children: [
          Icon(icon, size: 16, color: color),
          const SizedBox(width: 6),
          Text(label,
              style: TextStyle(fontSize: 12.5, fontWeight: FontWeight.bold, color: color)),
          const Spacer(),
          // **السحبُ فعلٌ حقيقيّ لا زينة** — ولا يُعرض إلّا حين يمكن.
          if (status == 'pending' && id != null)
            Obx(() => TextButton(
                  onPressed: c.isSubmitting.value ? null : () => _cancel(id),
                  child: const Text('سحب الطلب',
                      style: TextStyle(color: AmialColors.red, fontSize: 12.5)),
                )),
        ]),
      ]),
    );
  }

  Future<void> _cancel(int id) async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('سحب الطلب؟'),
        content: const Text('لن يستطيع صاحبه دفعه بعد السحب.'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('تراجع')),
          FilledButton(
            onPressed: () => Navigator.pop(ctx, true),
            style: FilledButton.styleFrom(backgroundColor: AmialColors.red),
            child: const Text('اسحب'),
          ),
        ],
      ),
    );

    if (ok != true || !mounted) return;

    final done = await c.cancel(id);
    if (!mounted) return;

    // **والقائمةُ تُعاد قراءتها** — `cancel` تحذف الصفّ محلّيّاً، والحالةُ
    // الحقيقيّة «cancelled» لا «مختفٍ»: من سحب طلباً يريد أن يرى أنّه سُحب.
    if (done) await _reload();

    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
      content: Text(done ? 'سُحب الطلب' : (c.lastError.value.isEmpty ? 'تعذّر السحب' : c.lastError.value)),
      backgroundColor: done ? AmialColors.success : AmialColors.red,
    ));
  }
}
