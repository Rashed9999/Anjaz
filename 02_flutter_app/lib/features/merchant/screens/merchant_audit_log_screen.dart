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

  String _status(Map<String, dynamic> e) =>
      '${e['decision_label'] ?? e['decision_code'] ?? 'audit_recorded_event'.tr}';

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
          _status(e),
          if (e['actor_label'] != null)
            'audit_by_actor'.trParams({'name': '${e['actor_label']}'}),
          if (e['branch'] is Map)
            'audit_branch_name'.trParams({'name': '${(e['branch'] as Map)['name']}'}),
          if (e['reason'] != null && '${e['reason']}'.trim().isNotEmpty) '${e['reason']}',
          _dt('${e['created_at']}'),
        ].where((s) => s.isNotEmpty).join('\n'), style: const TextStyle(fontSize: 11)),
        trailing: Text('${e['severity_label'] ?? 'audit_information'.tr}',
            style: TextStyle(fontSize: 10, color: c, fontWeight: FontWeight.bold)),
        isThreeLine: true,
        onTap: () => _showDetails(e),
      ),
    );
  }

  void _showDetails(Map<String, dynamic> e) {
    final details = ((e['details'] ?? []) as List)
        .whereType<Map>()
        .map((d) => Map<String, dynamic>.from(d))
        .toList();
    showDialog<void>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text('${e['action_label'] ?? 'audit_details'.tr}'),
        content: SingleChildScrollView(
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            _detail('audit_status'.tr, _status(e)),
            _detail('audit_actor'.tr, '${e['actor_label'] ?? '—'}'),
            if (e['branch'] is Map) _detail('audit_branch'.tr, '${(e['branch'] as Map)['name'] ?? '—'}'),
            if (e['reason'] != null && '${e['reason']}'.trim().isNotEmpty)
              _detail('audit_reason'.tr, '${e['reason']}'),
            if (e['transaction_id'] != null) _detail('audit_transaction_reference'.tr, '${e['transaction_id']}'),
            ...details.map((d) => _detail('${d['label'] ?? 'audit_detail'.tr}', '${d['value'] ?? '—'}')),
            _detail('audit_recorded_at'.tr, _dt('${e['created_at']}')),
          ]),
        ),
        actions: [TextButton(onPressed: () => Navigator.pop(ctx), child: Text('cancel'.tr))],
      ),
    );
  }

  Widget _detail(String label, String value) => Padding(
        padding: const EdgeInsets.only(bottom: 10),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(label, style: const TextStyle(fontSize: 11, color: AmialColors.textMuted)),
          const SizedBox(height: 2),
          SelectableText(value, style: const TextStyle(fontWeight: FontWeight.w600)),
        ]),
      );
}
