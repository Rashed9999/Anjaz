import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/data/api/api_client.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';
import 'package:amyal_pay/common/widgets/async_state_view.dart';

/// AMIAL-STAFF-PERFORMANCE-001 — «أداء الموظفين».
///
/// يعرض مبيعات كل موظف كاشير (عدد + إجمالي + متوسط الفاتورة + اليوم) خلال فترة
/// مختارة، مرتّبين بالأعلى مبيعاً. البيانات من المبيعات الفعلية (merchant_sales).
class MerchantStaffPerformanceScreen extends StatefulWidget {
  const MerchantStaffPerformanceScreen({super.key});

  @override
  State<MerchantStaffPerformanceScreen> createState() => _MerchantStaffPerformanceScreenState();
}

class _MerchantStaffPerformanceScreenState extends State<MerchantStaffPerformanceScreen> {
  final _api = Get.find<ApiClient>();
  bool _loading = true;
  String? _error;
  bool _locked = false;
  int _days = 7;
  List<Map<String, dynamic>> _staff = [];
  String _grandTotal = '0';
  String _unattributed = '0';

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() { _loading = true; _error = null; _locked = false; });
    try {
      final r = await _api.getData('/api/v1/amial/merchant/staff/performance?days=$_days');
      if (r.statusCode == 200 && r.body is Map) {
        final meta = (r.body['meta'] ?? {}) as Map;
        _staff = ((meta['staff'] ?? []) as List).map((e) => Map<String, dynamic>.from(e as Map)).toList();
        _grandTotal = '${meta['grand_total'] ?? 0}';
        _unattributed = '${meta['unattributed_total'] ?? 0}';
      } else if (r.statusCode == 402) {
        _locked = true;
        _error = 'إدارة الموظفين وأداؤهم متاحة في باقة الأعمال فأعلى';
      } else {
        _error = 'تعذّر تحميل الأداء';
      }
    } catch (_) {
      _error = 'تعذّر الاتصال — تحقّق من الشبكة';
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  String _money(dynamic v) {
    final n = double.tryParse('$v') ?? 0;
    return n.toStringAsFixed(n == n.roundToDouble() ? 0 : 2)
        .replaceAllMapped(RegExp(r'(\d)(?=(\d{3})+(?:\.|$))'), (m) => '${m[1]},');
  }

  double get _maxTotal =>
      _staff.fold<double>(0, (a, s) => (double.tryParse('${s['sales_total']}') ?? 0) > a
          ? (double.tryParse('${s['sales_total']}') ?? 0) : a);

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmyalColors.background,
      appBar: AppBar(
        title: const Text('أداء الموظفين'),
        backgroundColor: AmyalColors.primary,
        foregroundColor: Colors.white,
        elevation: 0,
      ),
      body: Column(children: [
        // مبدّل الفترة
        Padding(
          padding: const EdgeInsets.all(12),
          child: Row(children: [7, 30, 90].map((d) {
            final sel = _days == d;
            return Expanded(
              child: Padding(
                padding: const EdgeInsets.symmetric(horizontal: 4),
                child: InkWell(
                  borderRadius: BorderRadius.circular(10),
                  onTap: sel ? null : () { setState(() => _days = d); _load(); },
                  child: Container(
                    padding: const EdgeInsets.symmetric(vertical: 10),
                    decoration: BoxDecoration(
                      color: sel ? AmyalColors.primary : Colors.white,
                      borderRadius: BorderRadius.circular(10),
                      border: Border.all(color: sel ? AmyalColors.primary : AmyalColors.border),
                    ),
                    child: Text('$d يوم', textAlign: TextAlign.center,
                        style: TextStyle(
                            color: sel ? Colors.white : AmyalColors.textPrimary,
                            fontWeight: FontWeight.bold, fontSize: 13)),
                  ),
                ),
              ),
            );
          }).toList()),
        ),
        Expanded(
          child: AsyncStateView(
            loading: _loading,
            error: _error,
            lockedError: _locked,
            isEmpty: _staff.isEmpty,
            emptyText: 'لا يوجد موظفون بعد',
            emptyIcon: Icons.badge_outlined,
            onRetry: _load,
            child: ListView(padding: const EdgeInsets.fromLTRB(12, 0, 12, 16), children: [
              _totalHeader(),
              const SizedBox(height: 12),
              ..._staff.asMap().entries.map((e) => _staffCard(e.key, e.value)),
              if ((double.tryParse(_unattributed) ?? 0) > 0) ...[
                const SizedBox(height: 8),
                _unattributedNote(),
              ],
            ]),
          ),
        ),
      ]),
    );
  }

  Widget _totalHeader() => Container(
        padding: const EdgeInsets.all(16),
        decoration: BoxDecoration(
          gradient: const LinearGradient(
            colors: [AmyalColors.primary, Color(0xFF1D4FB8)],
            begin: Alignment.topRight, end: Alignment.bottomLeft,
          ),
          borderRadius: BorderRadius.circular(16),
        ),
        child: Row(children: [
          const Icon(Icons.groups, color: AmyalColors.yellow, size: 30),
          const SizedBox(width: 12),
          Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text('إجمالي مبيعات $_days يوم', style: const TextStyle(color: Colors.white70, fontSize: 12)),
              Text('${_money(_grandTotal)} ر.ي',
                  style: const TextStyle(color: Colors.white, fontSize: 22, fontWeight: FontWeight.bold)),
            ]),
          ),
          Text('${_staff.length} موظف',
              style: const TextStyle(color: Colors.white, fontWeight: FontWeight.bold)),
        ]),
      );

  Widget _staffCard(int rank, Map<String, dynamic> s) {
    final total = double.tryParse('${s['sales_total']}') ?? 0;
    final ratio = _maxTotal > 0 ? total / _maxTotal : 0.0;
    final active = s['is_active'] == true;
    final medal = rank == 0 ? '🥇' : rank == 1 ? '🥈' : rank == 2 ? '🥉' : '';
    return Container(
      margin: const EdgeInsets.only(bottom: 10),
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(14)),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Row(children: [
          CircleAvatar(
            radius: 20,
            backgroundColor: AmyalColors.primary.withValues(alpha: 0.1),
            child: Text(medal.isNotEmpty ? medal : '${rank + 1}',
                style: const TextStyle(fontWeight: FontWeight.bold, color: AmyalColors.primary)),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Row(children: [
                Text('${s['display_name'] ?? 'موظف'}',
                    style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 15)),
                const SizedBox(width: 6),
                if (!active)
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 1),
                    decoration: BoxDecoration(color: Colors.grey.shade200, borderRadius: BorderRadius.circular(6)),
                    child: const Text('معطّل', style: TextStyle(fontSize: 10, color: AmyalColors.textSecondary)),
                  ),
              ]),
              Text('نقطة بيع: ${s['pos_number'] ?? ''}',
                  style: const TextStyle(fontSize: 11, color: AmyalColors.textSecondary)),
            ]),
          ),
          Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
            Text('${_money(s['sales_total'])} ر.ي',
                style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 15, color: AmyalColors.primary)),
            Text('${s['sales_count'] ?? 0} عملية', style: const TextStyle(fontSize: 11, color: AmyalColors.textSecondary)),
          ]),
        ]),
        const SizedBox(height: 10),
        ClipRRect(
          borderRadius: BorderRadius.circular(4),
          child: LinearProgressIndicator(
            value: ratio, minHeight: 6,
            backgroundColor: AmyalColors.border,
            valueColor: AlwaysStoppedAnimation(rank == 0 ? AmyalColors.yellowDark : AmyalColors.primary),
          ),
        ),
        const SizedBox(height: 10),
        Row(children: [
          _miniStat('متوسط الفاتورة', '${_money(s['avg_ticket'])} ر.ي'),
          _miniStat('اليوم', '${_money(s['today_total'])} ر.ي'),
          _miniStat('عمليات اليوم', '${s['today_count'] ?? 0}'),
        ]),
      ]),
    );
  }

  Widget _miniStat(String label, String value) => Expanded(
        child: Column(children: [
          Text(value, style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13)),
          Text(label, style: const TextStyle(fontSize: 10, color: AmyalColors.textSecondary)),
        ]),
      );

  Widget _unattributedNote() => Container(
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(12)),
        child: Row(children: [
          const Icon(Icons.info_outline, size: 18, color: AmyalColors.textMuted),
          const SizedBox(width: 8),
          Expanded(child: Text('مبيعات سجّلها التاجر مباشرةً (بلا موظف): ${_money(_unattributed)} ر.ي',
              style: const TextStyle(fontSize: 12, color: AmyalColors.textSecondary))),
        ]),
      );
}
