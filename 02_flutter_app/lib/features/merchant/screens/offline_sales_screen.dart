import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amial_pay/features/merchant/services/offline_sale_queue.dart';
import 'package:amial_pay/theme/amial_colors.dart';

/// AMIAL-OFFLINE-POS-001 — «المبيعات دون اتصال».
///
/// يعرض المبيعات المحفوظة محلياً بانتظار المزامنة ويتيح مزامنتها يدوياً.
class OfflineSalesScreen extends StatefulWidget {
  const OfflineSalesScreen({super.key});

  @override
  State<OfflineSalesScreen> createState() => _OfflineSalesScreenState();
}

class _OfflineSalesScreenState extends State<OfflineSalesScreen> {
  final _q = Get.find<OfflineSaleQueue>();
  List<Map<String, dynamic>> _items = [];
  bool _loading = true;
  bool _syncing = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _loading = true);
    _items = await _q.items();
    if (mounted) setState(() => _loading = false);
  }

  void _snack(String m, {bool ok = false}) => ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(content: Text(m), backgroundColor: ok ? AmialColors.success : AmialColors.red));

  Future<void> _sync() async {
    setState(() => _syncing = true);
    final done = await _q.sync();
    await _load();
    if (!mounted) return;
    setState(() => _syncing = false);
    if (done > 0) {
      _snack('تمّت مزامنة $done عملية', ok: true);
    } else if (_items.isNotEmpty) {
      _snack('تعذّرت المزامنة — تحقّق من الاتصال');
    } else {
      _snack('لا مبيعات معلّقة', ok: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(title: const Text('المبيعات دون اتصال'),
          backgroundColor: AmialColors.primary, foregroundColor: Colors.white),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : Column(children: [
              Container(
                width: double.infinity,
                margin: const EdgeInsets.all(14),
                padding: const EdgeInsets.all(16),
                decoration: BoxDecoration(
                  color: _items.isEmpty ? AmialColors.success.withValues(alpha: 0.08) : AmialColors.yellow.withValues(alpha: 0.15),
                  borderRadius: BorderRadius.circular(14),
                ),
                child: Row(children: [
                  Icon(_items.isEmpty ? Icons.cloud_done : Icons.cloud_off,
                      color: _items.isEmpty ? AmialColors.success : AmialColors.yellowDark, size: 30),
                  const SizedBox(width: 12),
                  Expanded(child: Text(
                    _items.isEmpty ? 'كل المبيعات مُزامَنة' : '${_items.length} عملية بانتظار المزامنة',
                    style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 15),
                  )),
                ]),
              ),
              Expanded(
                child: _items.isEmpty
                    ? const Center(child: Text('لا مبيعات معلّقة', style: TextStyle(color: AmialColors.textSecondary)))
                    : ListView.separated(
                        padding: const EdgeInsets.symmetric(horizontal: 14),
                        itemCount: _items.length,
                        separatorBuilder: (_, _) => const SizedBox(height: 8),
                        itemBuilder: (_, i) {
                          final s = _items[i];
                          final method = {'cash': 'نقد', 'credit': 'آجل', 'mixed': 'مختلط'}[s['payment_method']] ?? '${s['payment_method']}';
                          return Container(
                            decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(10)),
                            child: ListTile(
                              leading: const CircleAvatar(
                                backgroundColor: Color(0x1AF59E0B),
                                child: Icon(Icons.pending, color: AmialColors.yellowDark)),
                              title: Text('${s['total']} ر.ي — $method', style: const TextStyle(fontWeight: FontWeight.bold)),
                              subtitle: Text('${((s['items'] ?? []) as List).length} صنف • بانتظار المزامنة',
                                  style: const TextStyle(fontSize: 11)),
                            ),
                          );
                        },
                      ),
              ),
              if (_items.isNotEmpty)
                SafeArea(
                  child: Padding(
                    padding: const EdgeInsets.all(14),
                    child: FilledButton.icon(
                      onPressed: _syncing ? null : _sync,
                      icon: _syncing
                          ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                          : const Icon(Icons.sync),
                      label: Text(_syncing ? 'جارٍ المزامنة…' : 'مزامنة الآن'),
                      style: FilledButton.styleFrom(backgroundColor: AmialColors.primary, minimumSize: const Size.fromHeight(52)),
                    ),
                  ),
                ),
            ]),
    );
  }
}
