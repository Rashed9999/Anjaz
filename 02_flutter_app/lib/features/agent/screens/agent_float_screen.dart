import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/features/agent/domain/repositories/agent_repo.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';
import 'package:amyal_pay/helper/amial_money.dart';

/// AMIAL-AGENT-NETWORK-001 (v2.4)
///
/// لوحة سيولة الوكيل — الرصيد، حركة اليوم، طلب شراء رصيد.
class AgentFloatScreen extends StatefulWidget {
  const AgentFloatScreen({super.key});

  @override
  State<AgentFloatScreen> createState() => _AgentFloatScreenState();
}

class _AgentFloatScreenState extends State<AgentFloatScreen> {
  Map<String, dynamic>? _data;
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _loading = true);
    try {
      final r = await Get.find<AgentRepo>().floatDashboard();
      if (r.statusCode == 200 && r.body is Map) {
        setState(() => _data = Map<String, dynamic>.from(r.body['meta'] ?? {}));
      }
    } catch (_) {}
    setState(() => _loading = false);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmyalColors.background,
      appBar: AppBar(
        backgroundColor: AmyalColors.primary,
        foregroundColor: Colors.white,
        title: const Text('سيولتي'),
      ),
      body: _loading
          ? const Center(child: CircularProgressIndicator(color: AmyalColors.primary))
          : RefreshIndicator(
              onRefresh: _load,
              color: AmyalColors.primary,
              child: ListView(
                padding: const EdgeInsets.all(16),
                children: [
                  _buildFloatCard(),
                  const SizedBox(height: 16),
                  if (_data?['low_float_warning'] == true) _buildLowFloatWarning(),
                  _buildTodayCard(),
                  const SizedBox(height: 16),
                  _buildTopupButton(),
                ],
              ),
            ),
    );
  }

  Widget _buildFloatCard() {
    final float = _data?['current_float']?.toString() ?? '0';
    final remaining = _data?['limits']?['daily_cash_in_remaining']?.toString() ?? '—';

    return Container(
      padding: const EdgeInsets.all(20),
      decoration: BoxDecoration(
        gradient: const LinearGradient(
          colors: [AmyalColors.primary, Color(0xFF1D4FB8)],
        ),
        borderRadius: BorderRadius.circular(16),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Text('الرصيد المتاح (السيولة)',
              style: TextStyle(color: Colors.white70, fontSize: 13)),
          const SizedBox(height: 4),
          Text('${AmialMoney.fmt(float)} ر.ي',
              style: const TextStyle(
                  color: Colors.white, fontSize: 30, fontWeight: FontWeight.bold)),
          const SizedBox(height: 12),
          Container(
            padding: const EdgeInsets.all(10),
            decoration: BoxDecoration(
              color: Colors.white.withValues(alpha: 0.15),
              borderRadius: BorderRadius.circular(8),
            ),
            child: Row(
              children: [
                const Icon(Icons.trending_up, color: Colors.white70, size: 18),
                const SizedBox(width: 8),
                Text('المتبقي من حد الإيداع اليومي: ${AmialMoney.fmt(remaining)} ر.ي',
                    style: const TextStyle(color: Colors.white, fontSize: 12)),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildLowFloatWarning() {
    return Container(
      margin: const EdgeInsets.only(bottom: 16),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: AmyalColors.red.withValues(alpha: 0.1),
        borderRadius: BorderRadius.circular(8),
        border: Border.all(color: AmyalColors.red.withValues(alpha: 0.4)),
      ),
      child: const Row(
        children: [
          Icon(Icons.warning_amber, color: AmyalColors.red),
          SizedBox(width: 8),
          Expanded(
            child: Text('سيولتك منخفضة! اطلب شراء رصيد لمواصلة الإيداع للعملاء.',
                style: TextStyle(fontSize: 12)),
          ),
        ],
      ),
    );
  }

  Widget _buildTodayCard() {
    final today = _data?['today'] ?? {};
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: AmyalColors.border),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Text('حركة اليوم',
              style: TextStyle(fontWeight: FontWeight.bold, fontSize: 14)),
          const SizedBox(height: 16),
          _row('إيداعات للعملاء', '${AmialMoney.fmt(today['cash_in_total'] ?? 0)} ر.ي',
              Icons.arrow_downward, const Color(0xFF10B981)),
          const Divider(height: 24),
          _row('سحوبات من العملاء', '${AmialMoney.fmt(today['cash_out_total'] ?? 0)} ر.ي',
              Icons.arrow_upward, const Color(0xFFEF4444)),
          const Divider(height: 24),
          _row('شراء رصيد', '${AmialMoney.fmt(today['topup_total'] ?? 0)} ر.ي',
              Icons.add_card, AmyalColors.primary),
          const Divider(height: 24),
          _row('العمولة', '${AmialMoney.fmt(today['commission_earned'] ?? 0)} ر.ي',
              Icons.star, AmyalColors.yellow),
          const Divider(height: 24),
          _row('عدد العمليات', '${today['transaction_count'] ?? 0}',
              Icons.receipt_long, AmyalColors.textSecondary),
        ],
      ),
    );
  }

  Widget _row(String label, String value, IconData icon, Color color) {
    return Row(
      children: [
        CircleAvatar(
          radius: 16, backgroundColor: color.withValues(alpha: 0.15),
          child: Icon(icon, color: color, size: 16),
        ),
        const SizedBox(width: 12),
        Expanded(child: Text(label, style: const TextStyle(fontSize: 13))),
        Text(value, style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14)),
      ],
    );
  }

  Widget _buildTopupButton() {
    return ElevatedButton.icon(
      onPressed: _showTopupSheet,
      icon: const Icon(Icons.add_card),
      label: const Text('طلب شراء رصيد',
          style: TextStyle(fontSize: 16, fontWeight: FontWeight.w600)),
      style: ElevatedButton.styleFrom(
        backgroundColor: AmyalColors.primary,
        foregroundColor: Colors.white,
        padding: const EdgeInsets.symmetric(vertical: 16),
      ),
    );
  }

  void _showTopupSheet() {
    final amountCtrl = TextEditingController();
    final refCtrl = TextEditingController();
    String method = 'cash';

    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(16)),
      ),
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setSheet) => Padding(
          padding: EdgeInsets.only(
            left: 20, right: 20, top: 20,
            bottom: MediaQuery.of(ctx).viewInsets.bottom + 20,
          ),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              const Text('طلب شراء رصيد',
                  style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold)),
              const SizedBox(height: 4),
              const Text('ادفع كاش للإدارة/الموزّع واحصل على رصيد رقمي',
                  style: TextStyle(fontSize: 12, color: AmyalColors.textMuted)),
              const SizedBox(height: 16),
              TextField(
                controller: amountCtrl,
                keyboardType: const TextInputType.numberWithOptions(decimal: true),
                inputFormatters: [FilteringTextInputFormatter.allow(RegExp(r'[\d.]'))],
                decoration: const InputDecoration(
                  labelText: 'المبلغ (ر.ي)',
                  prefixIcon: Icon(Icons.attach_money),
                  border: OutlineInputBorder(),
                ),
              ),
              const SizedBox(height: 12),
              SegmentedButton<String>(
                segments: const [
                  ButtonSegment(value: 'cash', label: Text('كاش')),
                  ButtonSegment(value: 'bank', label: Text('بنك')),
                ],
                selected: {method},
                onSelectionChanged: (s) => setSheet(() => method = s.first),
              ),
              const SizedBox(height: 12),
              TextField(
                controller: refCtrl,
                decoration: const InputDecoration(
                  labelText: 'مرجع الدفع (اختياري)',
                  prefixIcon: Icon(Icons.tag),
                  border: OutlineInputBorder(),
                ),
              ),
              const SizedBox(height: 20),
              ElevatedButton(
                onPressed: () async {
                  final amount = amountCtrl.text.trim();
                  if (double.tryParse(amount) == null || double.parse(amount) <= 0) {
                    ScaffoldMessenger.of(ctx).showSnackBar(
                      const SnackBar(content: Text('مبلغ غير صحيح')));
                    return;
                  }
                  Navigator.pop(ctx);
                  await _submitTopup(amount, method, refCtrl.text.trim());
                },
                style: ElevatedButton.styleFrom(
                  backgroundColor: AmyalColors.primary,
                  foregroundColor: Colors.white,
                  padding: const EdgeInsets.symmetric(vertical: 14),
                ),
                child: const Text('إرسال الطلب'),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Future<void> _submitTopup(String amount, String method, String ref) async {
    try {
      final r = await Get.find<AgentRepo>().requestTopup(
        amount: amount,
        paymentMethod: method,
        paymentReference: ref.isEmpty ? null : ref,
      );
      if (!mounted) return;
      if (r.statusCode == 200 && r.body is Map && r.body['success'] == true) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text('تم إرسال طلب شراء الرصيد. ينتظر الموافقة.'),
            backgroundColor: Color(0xFF10B981),
          ),
        );
        _load();
      } else {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(r.body is Map ? (r.body['message'] ?? 'فشل') : 'فشل'),
            backgroundColor: AmyalColors.red,
          ),
        );
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('خطأ في الشبكة'), backgroundColor: AmyalColors.red),
        );
      }
    }
  }
}
