import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:get/get.dart';
import 'package:amial_pay/data/api/api_client.dart';
import 'package:amial_pay/theme/amial_colors.dart';

/// AMIAL-LOYALTY-001 — «برنامج الولاء» (باقة الأعمال فأعلى).
///
/// التاجر يضبط معدّل الكسب/قيمة النقطة، يبحث عن رصيد عميل، يستبدل نقاطاً بخصم،
/// ويرى أعلى العملاء نقاطاً. النقاط تُكتسب تلقائياً مع كل بيع بعميل معروف.
class MerchantLoyaltyScreen extends StatefulWidget {
  const MerchantLoyaltyScreen({super.key});

  @override
  State<MerchantLoyaltyScreen> createState() => _MerchantLoyaltyScreenState();
}

class _MerchantLoyaltyScreenState extends State<MerchantLoyaltyScreen> {
  final _api = Get.find<ApiClient>();
  bool _loading = true;
  String? _error;

  bool _active = true;
  final _earn = TextEditingController();
  final _redeemVal = TextEditingController();
  final _minRedeem = TextEditingController();

  List<Map<String, dynamic>> _accounts = [];

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _earn.dispose();
    _redeemVal.dispose();
    _minRedeem.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() { _loading = true; _error = null; });
    try {
      final r = await _api.getData('/api/v1/amial/merchant/loyalty/program');
      if (r.statusCode == 402) {
        setState(() { _error = 'برنامج الولاء متاح في باقة الأعمال فأعلى'; _loading = false; });
        return;
      }
      if (r.statusCode == 200 && r.body is Map) {
        final p = ((r.body['meta'] ?? {})['program'] ?? {}) as Map;
        _active = p['is_active'] == true;
        _earn.text = '${p['earn_points_per_100'] ?? '1'}';
        _redeemVal.text = '${p['redeem_value_per_point'] ?? '1'}';
        _minRedeem.text = '${p['min_redeem_points'] ?? '0'}';
      }
      await _loadAccounts();
    } catch (_) {
      _error = 'خطأ في الشبكة';
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _loadAccounts() async {
    final r = await _api.getData('/api/v1/amial/merchant/loyalty/accounts');
    if (r.statusCode == 200 && r.body is Map) {
      _accounts = (((r.body['meta'] ?? {})['accounts'] ?? []) as List)
          .map((e) => Map<String, dynamic>.from(e as Map)).toList();
    }
  }

  void _snack(String m, {bool ok = false}) => ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(content: Text(m), backgroundColor: ok ? AmialColors.success : AmialColors.red));

  Future<void> _save() async {
    final r = await _api.postData('/api/v1/amial/merchant/loyalty/program', {
      'is_active': _active,
      'earn_points_per_100': _earn.text.trim(),
      'redeem_value_per_point': _redeemVal.text.trim(),
      'min_redeem_points': int.tryParse(_minRedeem.text.trim()) ?? 0,
    });
    if (r.statusCode == 200) { _snack('تم حفظ إعداد البرنامج', ok: true); } else { _snack('تعذّر الحفظ'); }
  }

  /// بحث عن رصيد عميل ثم استبدال/تعديل.
  Future<void> _lookup() async {
    final phone = TextEditingController();
    await showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.white,
      shape: const RoundedRectangleBorder(
          borderRadius: BorderRadius.vertical(top: Radius.circular(24))),
      builder: (ctx) => StatefulBuilder(builder: (ctx, setLocal) {
        Map<String, dynamic>? info;
        Future<void> doLookup() async {
          final r = await _api.getData('/api/v1/amial/merchant/loyalty/lookup',
              query: {'phone': phone.text.trim()});
          if (r.statusCode == 200 && r.body is Map) {
            setLocal(() => info = Map<String, dynamic>.from((r.body['meta'] ?? {}) as Map));
          } else { _snack('تعذّر الاستعلام'); }
        }

        Future<void> doRedeem() async {
          final ptsCtrl = TextEditingController();
          final ok = await showDialog<bool>(
            context: ctx,
            builder: (d) => AlertDialog(
              title: const Text('استبدال نقاط بخصم'),
              content: TextField(controller: ptsCtrl, keyboardType: TextInputType.number,
                  decoration: const InputDecoration(labelText: 'عدد النقاط', border: OutlineInputBorder())),
              actions: [
                TextButton(onPressed: () => Navigator.pop(d, false), child: const Text('إلغاء')),
                FilledButton(onPressed: () => Navigator.pop(d, true), child: const Text('استبدال')),
              ],
            ),
          );
          if (ok != true) return;
          final r = await _api.postData('/api/v1/amial/merchant/loyalty/redeem',
              {'phone': phone.text.trim(), 'points': double.tryParse(ptsCtrl.text.trim()) ?? 0});
          if (r.statusCode == 200 && r.body is Map) {
            final disc = (r.body['meta'] ?? {})['discount'];
            // AMIAL-LOYALTY-AT-PAYMENT-001 — **ويُقال إنّ هذا البابَ
            // الاحتياطيّ لا الأصليّ.** الاستبدالُ من هنا يُنقص النقاطَ
            // **الآن** والخصمُ يُطبَّق بيدٍ في شاشةٍ أخرى — فإن نسِيَه
            // الكاشيرُ ذهبت النقاطُ ودفع العميلُ كاملاً. والمسارُ السليم
            // مدخلُ «نقاط الولاء» في شاشة الدفع: يُصرَف مع الفاتورة
            // نفسِها فتسقط معها إن سقطت.
            _snack('نُقصت النقاط الآن — طبّق خصم $disc ر.ي يدويّاً على '
                'الفاتورة. والأفضل: استبدلها من شاشة الدفع لتُربَط بالبيعة',
                ok: true);
            await doLookup();
            _loadAccounts().then((_) { if (mounted) setState(() {}); });
          } else {
            _snack((r.body is Map ? r.body['message']?.toString() : null) ?? 'تعذّر الاستبدال');
          }
        }

        return Padding(
          padding: EdgeInsets.fromLTRB(20, 16, 20, 20 + MediaQuery.of(ctx).viewInsets.bottom),
          child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            const Text('رصيد نقاط عميل', style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold), textAlign: TextAlign.center),
            const SizedBox(height: 14),
            Row(children: [
              Expanded(child: TextField(controller: phone, keyboardType: TextInputType.phone,
                  decoration: const InputDecoration(labelText: 'هاتف العميل', border: OutlineInputBorder()))),
              const SizedBox(width: 8),
              IconButton.filled(onPressed: doLookup, icon: const Icon(Icons.search),
                  style: IconButton.styleFrom(backgroundColor: AmialColors.primary)),
            ]),
            if (info != null) ...[
              const SizedBox(height: 16),
              Container(
                padding: const EdgeInsets.all(16),
                decoration: BoxDecoration(
                    color: AmialColors.primary.withValues(alpha: 0.06),
                    borderRadius: BorderRadius.circular(14)),
                child: Column(children: [
                  Text('${info!['points_balance'] ?? '0'} نقطة',
                      style: const TextStyle(fontSize: 26, fontWeight: FontWeight.bold, color: AmialColors.primary)),
                  Text('≈ ${info!['estimated_value'] ?? '0'} ر.ي',
                      style: const TextStyle(color: AmialColors.textSecondary)),
                ]),
              ),
              const SizedBox(height: 12),
              FilledButton.icon(onPressed: doRedeem, icon: const Icon(Icons.redeem),
                  label: const Text('استبدال بخصم'),
                  style: FilledButton.styleFrom(backgroundColor: AmialColors.primary, minimumSize: const Size.fromHeight(48))),
            ],
          ]),
        );
      }),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(title: const Text('برنامج الولاء'),
          backgroundColor: AmialColors.primary, foregroundColor: Colors.white),
      floatingActionButton: _error == null && !_loading
          ? FloatingActionButton.extended(onPressed: _lookup,
              backgroundColor: AmialColors.primary, icon: const Icon(Icons.person_search),
              label: const Text('رصيد عميل'))
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
                  child: ListView(padding: const EdgeInsets.all(14), children: [
                    _configCard(),
                    const SizedBox(height: 16),
                    const Text('أعلى العملاء نقاطاً',
                        style: TextStyle(fontSize: 15, fontWeight: FontWeight.bold)),
                    const SizedBox(height: 8),
                    if (_accounts.isEmpty)
                      const Padding(padding: EdgeInsets.symmetric(vertical: 24),
                          child: Center(child: Text('لا عملاء بعد — تُكتسب النقاط مع كل بيع بعميل معروف',
                              textAlign: TextAlign.center, style: TextStyle(color: AmialColors.textSecondary)))),
                    ..._accounts.map(_accountTile),
                  ]),
                ),
    );
  }

  Widget _configCard() => Container(
        padding: const EdgeInsets.all(16),
        decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(14)),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Row(children: [
            const Icon(Icons.card_giftcard, color: AmialColors.primary),
            const SizedBox(width: 8),
            const Expanded(child: Text('إعداد البرنامج',
                style: TextStyle(fontWeight: FontWeight.bold, fontSize: 15))),
            Switch(value: _active, activeColor: AmialColors.primary,
                onChanged: (v) => setState(() => _active = v)),
          ]),
          const SizedBox(height: 8),
          _numField(_earn, 'نقاط تُكتسب لكل 100 ر.ي', 'مثال: 1'),
          const SizedBox(height: 10),
          _numField(_redeemVal, 'قيمة النقطة بالريال عند الاستبدال', 'مثال: 1'),
          const SizedBox(height: 10),
          _numField(_minRedeem, 'أدنى نقاط للاستبدال', 'مثال: 50'),
          const SizedBox(height: 14),
          FilledButton.icon(onPressed: _save, icon: const Icon(Icons.save),
              label: const Text('حفظ الإعداد'),
              style: FilledButton.styleFrom(backgroundColor: AmialColors.primary, minimumSize: const Size.fromHeight(48))),
        ]),
      );

  Widget _numField(TextEditingController c, String label, String hint) => TextField(
        controller: c,
        keyboardType: const TextInputType.numberWithOptions(decimal: true),
        inputFormatters: [FilteringTextInputFormatter.allow(RegExp(r'[0-9.]'))],
        decoration: InputDecoration(labelText: label, hintText: hint, border: const OutlineInputBorder(), isDense: true),
      );

  Widget _accountTile(Map<String, dynamic> a) => Container(
        margin: const EdgeInsets.only(bottom: 8),
        decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(12)),
        child: ListTile(
          leading: CircleAvatar(
            backgroundColor: AmialColors.yellow.withValues(alpha: 0.25),
            child: const Icon(Icons.stars, color: AmialColors.yellowDark),
          ),
          title: Text('${a['customer_name']?.toString().isNotEmpty == true ? a['customer_name'] : a['customer_phone']}',
              style: const TextStyle(fontWeight: FontWeight.bold)),
          subtitle: Text('${a['customer_phone']}', textDirection: TextDirection.ltr,
              style: const TextStyle(fontSize: 11)),
          trailing: Column(mainAxisAlignment: MainAxisAlignment.center, crossAxisAlignment: CrossAxisAlignment.end, children: [
            Text('${a['points_balance']}',
                style: const TextStyle(fontWeight: FontWeight.bold, color: AmialColors.primary, fontSize: 16)),
            const Text('نقطة', style: TextStyle(fontSize: 10, color: AmialColors.textSecondary)),
          ]),
        ),
      );
}
