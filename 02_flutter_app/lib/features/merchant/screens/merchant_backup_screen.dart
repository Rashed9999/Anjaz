import 'dart:convert';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:path_provider/path_provider.dart';
import 'package:share_plus/share_plus.dart';
import 'package:amial_pay/data/api/api_client.dart';
import 'package:amial_pay/theme/amial_colors.dart';

/// AMIAL-BACKUP-001 — «النسخ الاحتياطي» (باقة التاجر برو فأعلى).
///
/// يجمع بيانات المتجر (المنتجات، العملات، حسابات الآجل، حسابات الشركات، المبيعات)
/// في ملف JSON واحد يُحفظ على الجهاز ويمكن مشاركته/تنزيله.
class MerchantBackupScreen extends StatefulWidget {
  const MerchantBackupScreen({super.key});

  @override
  State<MerchantBackupScreen> createState() => _MerchantBackupScreenState();
}

class _MerchantBackupScreenState extends State<MerchantBackupScreen> {
  final _api = Get.find<ApiClient>();
  bool _busy = false;
  String? _error;
  Map<String, dynamic>? _lastMeta;
  String? _savedPath;

  void _snack(String m, {bool ok = false}) => ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(content: Text(m), backgroundColor: ok ? const Color(0xFF2E7D32) : AmialColors.red));

  Future<void> _run() async {
    setState(() { _busy = true; _error = null; });
    try {
      final r = await _api.getData('/api/v1/amial/merchant/backup');
      if (r.statusCode == 402) {
        setState(() { _error = 'النسخ الاحتياطي متاح في باقة التاجر برو فأعلى'; });
        return;
      }
      if (r.statusCode != 200 || r.body is! Map) {
        setState(() { _error = (r.body is Map ? r.body['message']?.toString() : null) ?? 'تعذّر إنشاء النسخة'; });
        return;
      }
      final data = Map<String, dynamic>.from(r.body as Map);
      final meta = Map<String, dynamic>.from((data['meta'] ?? {}) as Map);

      // احفظ الملف على الجهاز
      final dir = await getApplicationDocumentsDirectory();
      final ts = DateTime.now();
      final name = 'amial_backup_'
          '${ts.year}${ts.month.toString().padLeft(2, '0')}${ts.day.toString().padLeft(2, '0')}'
          '_${ts.hour.toString().padLeft(2, '0')}${ts.minute.toString().padLeft(2, '0')}.json';
      final file = File('${dir.path}/$name');
      await file.writeAsString(const JsonEncoder.withIndent('  ').convert(data));

      setState(() { _lastMeta = meta; _savedPath = file.path; });
      _snack('تم إنشاء النسخة الاحتياطية', ok: true);
    } catch (_) {
      setState(() => _error = 'خطأ في الشبكة');
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _share() async {
    final p = _savedPath;
    if (p == null) return;
    await Share.shareXFiles([XFile(p)], text: 'نسخة احتياطية — أميال باي');
  }

  @override
  Widget build(BuildContext context) {
    final counts = (_lastMeta?['counts'] ?? {}) as Map;
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(title: const Text('النسخ الاحتياطي'),
          backgroundColor: AmialColors.primary, foregroundColor: Colors.white),
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(16),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Container(
            padding: const EdgeInsets.all(16),
            decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(12)),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              const Row(children: [
                Icon(Icons.backup, color: AmialColors.primary), SizedBox(width: 8),
                Text('نسخة احتياطية كاملة', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 16)),
              ]),
              const SizedBox(height: 8),
              const Text('يُجمَّع كل بيانات متجرك في ملف JSON واحد: المنتجات، العملات، '
                  'حسابات الآجل وحركاتها، حسابات الشركات، وآخر ٥٠٠٠ عملية بيع.',
                  style: TextStyle(fontSize: 13, color: AmialColors.textSecondary, height: 1.5)),
            ]),
          ),
          const SizedBox(height: 16),
          if (_error != null)
            Container(
              padding: const EdgeInsets.all(14),
              decoration: BoxDecoration(color: AmialColors.red.withValues(alpha: 0.08), borderRadius: BorderRadius.circular(12)),
              child: Row(children: [
                const Icon(Icons.workspace_premium, color: AmialColors.yellowDark),
                const SizedBox(width: 8),
                Expanded(child: Text(_error!, style: const TextStyle(fontWeight: FontWeight.w600))),
              ]),
            ),
          if (_lastMeta != null && _error == null) ...[
            Container(
              padding: const EdgeInsets.all(16),
              decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(12)),
              child: Column(children: [
                const Align(alignment: Alignment.centerRight,
                    child: Text('محتوى النسخة', style: TextStyle(fontWeight: FontWeight.bold))),
                const SizedBox(height: 8),
                _row('المنتجات', counts['products']),
                _row('العملات', counts['currencies']),
                _row('حسابات الآجل', counts['credit_accounts']),
                _row('حسابات الشركات', counts['corporate_accounts']),
                _row('المبيعات', counts['sales']),
              ]),
            ),
            const SizedBox(height: 12),
            FilledButton.icon(
              onPressed: _share,
              icon: const Icon(Icons.share),
              label: const Text('مشاركة / حفظ الملف'),
              style: FilledButton.styleFrom(backgroundColor: AmialColors.primary, minimumSize: const Size.fromHeight(50)),
            ),
            const SizedBox(height: 16),
          ],
          FilledButton.icon(
            onPressed: _busy ? null : _run,
            icon: _busy
                ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                : const Icon(Icons.cloud_download),
            label: Text(_busy ? 'جارٍ التجهيز…' : 'إنشاء نسخة احتياطية الآن'),
            style: FilledButton.styleFrom(
                backgroundColor: _lastMeta != null ? AmialColors.textSecondary : AmialColors.primary,
                minimumSize: const Size.fromHeight(52)),
          ),
        ]),
      ),
    );
  }

  Widget _row(String label, dynamic value) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 6),
        child: Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
          Text(label, style: const TextStyle(color: AmialColors.textSecondary)),
          Text('${value ?? 0}', style: const TextStyle(fontWeight: FontWeight.bold, color: AmialColors.primary)),
        ]),
      );
}
