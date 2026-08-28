import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amial_pay/data/api/api_client.dart';
import 'package:amial_pay/theme/amial_colors.dart';
import 'package:amial_pay/helper/date_converter_helper.dart';

/// AMIAL-MERCHANT-AUDIT-001 — «سجلّ التدقيق» للتاجر (باقة التاجر برو فأعلى).
///
/// قيود غير قابلة للتعديل (append-only) يكون التاجر طرفاً فيها — للشفافية.
class MerchantAuditLogScreen extends StatefulWidget {
  const MerchantAuditLogScreen({super.key});

  @override
  State<MerchantAuditLogScreen> createState() => _MerchantAuditLogScreenState();
}

class _MerchantAuditLogScreenState extends State<MerchantAuditLogScreen> {
  final _api = Get.find<ApiClient>();
  bool _loading = true;
  List<Map<String, dynamic>> _entries = [];
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() { _loading = true; _error = null; });
    try {
      final r = await _api.getData('/api/v1/amial/merchant/audit-log');
      if (r.statusCode == 200 && r.body is Map && r.body['success'] == true) {
        final meta = (r.body['meta'] ?? {}) as Map;
        setState(() => _entries = ((meta['entries'] ?? []) as List)
            .map((e) => Map<String, dynamic>.from(e as Map))
            .toList());
      } else if (r.statusCode == 402) {
        _error = 'سجلّ التدقيق متاح في باقة التاجر برو فأعلى';
      } else {
        _error = 'تعذّر تحميل السجلّ';
      }
    } catch (_) {
      _error = 'خطأ في الشبكة';
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Color _sevColor(String s) => switch (s) {
        'critical' => AmialColors.red,
        'warning' => AmialColors.yellowDark,
        _ => AmialColors.primary,
      };

  String _dt(String? iso) {
    if (iso == null) return '';
    final d = DateConverterHelper.tryFromApi(iso);
    if (d == null) return '';
    return '${d.year}/${d.month.toString().padLeft(2, '0')}/${d.day.toString().padLeft(2, '0')} '
        '${d.hour.toString().padLeft(2, '0')}:${d.minute.toString().padLeft(2, '0')}';
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(
        title: const Text('سجلّ التدقيق'),
      ),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : _error != null
              ? Center(child: Padding(
                  padding: const EdgeInsets.all(24),
                  child: Column(mainAxisSize: MainAxisSize.min, children: [
                    const Icon(Icons.workspace_premium, size: 56, color: AmialColors.yellowDark),
                    const SizedBox(height: 12),
                    Text(_error!, textAlign: TextAlign.center,
                        style: const TextStyle(fontSize: 15, fontWeight: FontWeight.w600)),
                  ]),
                ))
              : RefreshIndicator(
                  onRefresh: _load,
                  child: _entries.isEmpty
                      ? ListView(children: const [
                          SizedBox(height: 120),
                          Icon(Icons.verified_user_outlined, size: 64, color: AmialColors.textMuted),
                          SizedBox(height: 12),
                          Center(child: Text('لا قيود بعد')),
                        ])
                      : ListView.separated(
                          padding: const EdgeInsets.all(12),
                          itemCount: _entries.length,
                          separatorBuilder: (_, __) => const SizedBox(height: 8),
                          itemBuilder: (_, i) => _entryCard(_entries[i]),
                        ),
                ),
    );
  }

  Widget _entryCard(Map<String, dynamic> e) {
    final sev = '${e['severity'] ?? 'info'}';
    final c = _sevColor(sev);
    return Container(
      decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(12)),
      child: ListTile(
        leading: Container(width: 6, height: 42, decoration: BoxDecoration(
            color: c, borderRadius: BorderRadius.circular(3))),
        title: Text('${e['action_label'] ?? e['action'] ?? ''}',
            style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14)),
        subtitle: Text([
          if (e['reason'] != null) '${e['reason']}',
          _dt('${e['created_at']}'),
        ].where((s) => s.isNotEmpty).join('\n'), style: const TextStyle(fontSize: 11)),
        trailing: e['decision_code'] != null
            ? Text('${e['decision_code']}', style: TextStyle(fontSize: 10, color: c, fontWeight: FontWeight.bold))
            : null,
        isThreeLine: e['reason'] != null,
      ),
    );
  }
}
