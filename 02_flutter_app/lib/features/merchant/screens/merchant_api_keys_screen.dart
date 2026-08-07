import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:get/get.dart';
import 'package:amial_pay/data/api/api_client.dart';
import 'package:amial_pay/theme/amial_colors.dart';
import 'package:amial_pay/util/app_constants.dart';

/// AMIAL-API-ACCESS-001 — «مفاتيح API» (الباقة المؤسسية).
///
/// التاجر يولّد مفاتيح للتكامل الخارجي؛ يظهر المفتاح الكامل مرّة واحدة فقط.
/// تُستخدم في واجهة الشركاء: GET {base}/api/v1/amial/partner/sales
class MerchantApiKeysScreen extends StatefulWidget {
  const MerchantApiKeysScreen({super.key});

  @override
  State<MerchantApiKeysScreen> createState() => _MerchantApiKeysScreenState();
}

class _MerchantApiKeysScreenState extends State<MerchantApiKeysScreen> {
  final _api = Get.find<ApiClient>();
  bool _loading = true;
  String? _error;
  List<Map<String, dynamic>> _keys = [];

  String get _endpoint => '${AppConstants.baseUrl}/api/v1/amial/partner/sales';

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() { _loading = true; _error = null; });
    try {
      final r = await _api.getData('/api/v1/amial/merchant/api-keys');
      if (r.statusCode == 402) {
        setState(() { _error = 'الوصول عبر API متاح في الباقة المؤسسية'; _loading = false; });
        return;
      }
      if (r.statusCode == 200 && r.body is Map && r.body['success'] == true) {
        setState(() => _keys = (((r.body['meta'] ?? {})['keys'] ?? []) as List)
            .map((e) => Map<String, dynamic>.from(e as Map)).toList());
      }
    } catch (_) {
      _error = 'خطأ في الشبكة';
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  void _snack(String m, {bool ok = false}) => ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(content: Text(m), backgroundColor: ok ? const Color(0xFF2E7D32) : AmialColors.red));

  Future<void> _generate() async {
    final label = TextEditingController();
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('مفتاح API جديد'),
        content: TextField(controller: label,
            decoration: const InputDecoration(labelText: 'وصف المفتاح (اختياري)',
                hintText: 'مثال: تكامل المحاسبة', border: OutlineInputBorder())),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('إلغاء')),
          FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('توليد')),
        ],
      ),
    );
    if (ok != true || !mounted) return;
    final r = await _api.postData('/api/v1/amial/merchant/api-keys',
        {'label': label.text.trim()});
    if ((r.statusCode == 200 || r.statusCode == 201) && r.body is Map) {
      final full = (r.body['meta'] ?? {})['api_key']?.toString() ?? '';
      _load();
      if (mounted) _showSecret(full);
    } else {
      _snack((r.body is Map ? r.body['message']?.toString() : null) ?? 'تعذّر التوليد');
    }
  }

  /// يظهر المفتاح الكامل مرّة واحدة — مع تحذير بحفظه.
  Future<void> _showSecret(String full) async {
    await showDialog<void>(
      context: context,
      barrierDismissible: false,
      builder: (ctx) => AlertDialog(
        title: const Row(children: [
          Icon(Icons.vpn_key, color: AmialColors.primary), SizedBox(width: 8), Text('مفتاحك الجديد'),
        ]),
        content: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
          Container(
            padding: const EdgeInsets.all(12),
            decoration: BoxDecoration(color: AmialColors.background, borderRadius: BorderRadius.circular(8)),
            child: SelectableText(full, textDirection: TextDirection.ltr,
                style: const TextStyle(fontFamily: 'monospace', fontSize: 13, fontWeight: FontWeight.bold)),
          ),
          const SizedBox(height: 10),
          Container(
            padding: const EdgeInsets.all(10),
            decoration: BoxDecoration(color: AmialColors.red.withValues(alpha: 0.08), borderRadius: BorderRadius.circular(8)),
            child: const Row(children: [
              Icon(Icons.warning_amber, color: AmialColors.red, size: 18),
              SizedBox(width: 6),
              Expanded(child: Text('احفظ المفتاح الآن — لن يظهر مجدداً بعد الإغلاق.',
                  style: TextStyle(fontSize: 12, color: AmialColors.red, fontWeight: FontWeight.w600))),
            ]),
          ),
        ]),
        actions: [
          TextButton.icon(
            onPressed: () { Clipboard.setData(ClipboardData(text: full)); _snack('نُسخ المفتاح', ok: true); },
            icon: const Icon(Icons.copy, size: 18),
            label: const Text('نسخ'),
          ),
          FilledButton(onPressed: () => Navigator.pop(ctx), child: const Text('تم، حفظته')),
        ],
      ),
    );
  }

  Future<void> _toggle(int id) async {
    final r = await _api.postData('/api/v1/amial/merchant/api-keys/$id/toggle', {});
    if (r.statusCode == 200) _load(); else _snack('تعذّر');
  }

  Future<void> _delete(int id) async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('حذف المفتاح'),
        content: const Text('سيتوقّف أي تكامل يستخدم هذا المفتاح فوراً. متابعة؟'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('إلغاء')),
          FilledButton(style: FilledButton.styleFrom(backgroundColor: AmialColors.red),
              onPressed: () => Navigator.pop(ctx, true), child: const Text('حذف')),
        ],
      ),
    );
    if (ok != true) return;
    final r = await _api.deleteData('/api/v1/amial/merchant/api-keys/$id');
    if (r.statusCode == 200) { _snack('تم الحذف', ok: true); _load(); } else { _snack('تعذّر الحذف'); }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(title: const Text('مفاتيح API'),
          backgroundColor: AmialColors.primary, foregroundColor: Colors.white),
      floatingActionButton: _error == null
          ? FloatingActionButton.extended(onPressed: _generate,
              backgroundColor: AmialColors.primary, icon: const Icon(Icons.add), label: const Text('مفتاح جديد'))
          : null,
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : _error != null
              ? Center(child: Padding(padding: const EdgeInsets.all(24),
                  child: Column(mainAxisSize: MainAxisSize.min, children: [
                    const Icon(Icons.workspace_premium, size: 56, color: AmialColors.yellowDark),
                    const SizedBox(height: 12),
                    Text(_error!, textAlign: TextAlign.center,
                        style: const TextStyle(fontSize: 15, fontWeight: FontWeight.w600)),
                  ])))
              : RefreshIndicator(
                  onRefresh: _load,
                  child: ListView(padding: const EdgeInsets.all(12), children: [
                    _docsCard(),
                    const SizedBox(height: 8),
                    if (_keys.isEmpty) const Padding(padding: EdgeInsets.symmetric(vertical: 40),
                        child: Center(child: Text('لا مفاتيح — أنشئ مفتاحاً للتكامل الخارجي'))),
                    ..._keys.map(_card),
                  ]),
                ),
    );
  }

  Widget _docsCard() => Container(
        padding: const EdgeInsets.all(14),
        decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(12)),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          const Row(children: [
            Icon(Icons.api, color: AmialColors.primary, size: 20), SizedBox(width: 6),
            Text('واجهة الشركاء', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 15)),
          ]),
          const SizedBox(height: 8),
          const Text('أرسل الطلب مع ترويسة X-Api-Key لجلب مبيعاتك:',
              style: TextStyle(fontSize: 12, color: AmialColors.textSecondary)),
          const SizedBox(height: 8),
          Container(
            width: double.infinity,
            padding: const EdgeInsets.all(10),
            decoration: BoxDecoration(color: AmialColors.background, borderRadius: BorderRadius.circular(8)),
            child: SelectableText('GET $_endpoint\nX-Api-Key: amk_...',
                textDirection: TextDirection.ltr,
                style: const TextStyle(fontFamily: 'monospace', fontSize: 11)),
          ),
          const SizedBox(height: 6),
          Align(
            alignment: Alignment.centerLeft,
            child: TextButton.icon(
              onPressed: () { Clipboard.setData(ClipboardData(text: _endpoint)); _snack('نُسخ الرابط', ok: true); },
              icon: const Icon(Icons.copy, size: 16),
              label: const Text('نسخ الرابط'),
            ),
          ),
        ]),
      );

  Widget _card(Map<String, dynamic> k) {
    final active = k['is_active'] == true;
    final lastUsed = k['last_used_at']?.toString();
    return Container(
      margin: const EdgeInsets.only(bottom: 8),
      decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(12)),
      child: ListTile(
        leading: CircleAvatar(
          backgroundColor: (active ? AmialColors.primary : AmialColors.textSecondary).withValues(alpha: 0.12),
          child: Icon(Icons.vpn_key, color: active ? AmialColors.primary : AmialColors.textSecondary, size: 20),
        ),
        title: Text('${k['label'] ?? 'مفتاح'}', style: const TextStyle(fontWeight: FontWeight.bold)),
        subtitle: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text('${k['masked'] ?? ''}', textDirection: TextDirection.ltr,
              style: const TextStyle(fontFamily: 'monospace', fontSize: 11)),
          Text(lastUsed == null ? 'لم يُستخدم بعد' : 'آخر استخدام: ${_shortDate(lastUsed)}',
              style: const TextStyle(fontSize: 10, color: AmialColors.textSecondary)),
        ]),
        trailing: Row(mainAxisSize: MainAxisSize.min, children: [
          Switch(value: active, activeColor: AmialColors.primary, onChanged: (_) => _toggle(k['id'] as int)),
          IconButton(icon: const Icon(Icons.delete_outline, size: 20, color: AmialColors.red),
              onPressed: () => _delete(k['id'] as int)),
        ]),
      ),
    );
  }

  String _shortDate(String iso) {
    final d = DateTime.tryParse(iso);
    if (d == null) return iso;
    final l = d.toLocal();
    return '${l.year}/${l.month.toString().padLeft(2, '0')}/${l.day.toString().padLeft(2, '0')}';
  }
}
