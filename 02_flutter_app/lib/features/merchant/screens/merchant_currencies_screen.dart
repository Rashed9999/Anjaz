import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amial_pay/data/api/api_client.dart';
import 'package:amial_pay/theme/amial_colors.dart';

/// AMIAL-MULTI-CURRENCY-002 — **محافظ التاجر متعدّدة العملات.**
///
/// ══════════════════════════════════════════════════════════════════════
/// سأل صاحبُ المشروع «ما وظيفتها حاليّاً» فقِيس، فإذا هذه الشاشةُ **ليست
/// محافظ**: جدولُ رموزٍ وسعرٍ يكتبه التاجرُ بيده ليُطبَع سطرَ مكافئٍ في
/// أسفل الإيصال. والمالُ أحاديُّ العملة من طرفه إلى طرفه.
///
/// فصارت ما طلبه: **رصيدٌ حقيقيٌّ لكلّ عملة**، يُقبَض فيه ويُصرَف بينها.
///
/// **وثلاثةُ فروقٍ عن سابقتها تُقرأ ولا تُخمَّن:**
///
/// ① **لا يكتب التاجرُ سعراً.** السعرُ من المنصّة، ومعه **مصدرُه ولحظتُه**
///    على الشاشة. ورقمٌ يكتبه صاحبُ المصلحة في مالٍ يقبضه ليس سعراً.
///
/// ② **ولا يخترع رمزاً.** أربعُ عملاتٍ بأعيانها. و`usd` و`USD` و`US$`
///    كانت تصير ثلاثَ محافظَ ينقسم المالُ بينها **بلا خطأٍ في أيّ سجلّ**.
///
/// ③ **وكلُّ عملةٍ معروضةٌ ولو برصيد صفر** — محفظةٌ لا تُعرَض لا يعرف
///    التاجرُ أنّها متاحةٌ أصلاً. (مبنيٌّ ولا يُوصَل إليه.)
class MerchantCurrenciesScreen extends StatefulWidget {
  const MerchantCurrenciesScreen({super.key});

  @override
  State<MerchantCurrenciesScreen> createState() => _MerchantCurrenciesScreenState();
}

class _MerchantCurrenciesScreenState extends State<MerchantCurrenciesScreen> {
  final _api = Get.find<ApiClient>();
  bool _loading = true;
  String? _error;
  String _base = 'YER';
  String _baseSymbol = 'ر.ي';
  List<Map<String, dynamic>> _wallets = [];

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final r = await _api.getData('/api/v1/amial/merchant/wallets');

      if (r.statusCode == 200 && r.body is Map && r.body['success'] == true) {
        final meta = (r.body['meta'] ?? {}) as Map;
        setState(() {
          _base = '${meta['base'] ?? 'YER'}';
          _baseSymbol = '${meta['base_symbol'] ?? 'ر.ي'}';
          _wallets = ((meta['wallets'] ?? []) as List)
              .map((e) => Map<String, dynamic>.from(e as Map))
              .toList();
        });
        return;
      }

      // ══════════════════════════════════════════════════════════════
      // AMIAL-EMPTY-LIES-001 — **فشلٌ يُعرَض فراغاً.**
      //
      // كان كلُّ ما ليس ٢٠٠ ولا ٤٠٢ **يسقط صامتاً**: `_error` يبقى فارغاً
      // والقائمةُ فارغة، فتقول الشاشةُ «لا عملات مضافة» — **وهي لم تسأل
      // أصلاً**. فقرأ صاحبُ المشروع شاشةً هادئةً وكان الردُّ ٤٠١.
      //
      // (القاعدة السابعة: «غير معروف» ليس صفراً — والصفرُ يُقرأ «فحصنا
      // فلم نجد».)
      // ══════════════════════════════════════════════════════════════
      String? serverSays;
      try {
        if (r.body is Map && r.body['message'] != null) {
          final m = '${r.body['message']}';
          if (m.isNotEmpty) serverSays = m;
        }
      } catch (_) {}

      _error = switch (r.statusCode) {
        402 => serverSays ?? 'محافظ العملات متاحة في باقة المؤسّسة',
        401 => 'انتهت الجلسة — سجّل الدخول من جديد',
        403 => serverSays ?? 'لا تملك الصلاحية اللازمة',
        -1 => 'أوقف VPN ثم حاول مجدداً',
        0 || 1 => 'لا اتصال بالخادم — تحقّق من الشبكة',
        _ => serverSays ?? 'تعذّر تحميل المحافظ (رمز ${r.statusCode})',
      };
    } catch (_) {
      _error = 'تعذّر تحميل المحافظ — خطأ غير متوقَّع';
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  void _snack(String m, {bool ok = false}) =>
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
        content: Text(m),
        backgroundColor: ok ? AmialColors.success : AmialColors.red,
      ));

  /// تشغيل/إطفاء قبولِ عملةٍ في نقطة البيع.
  Future<void> _toggleAccept(Map<String, dynamic> w) async {
    final r = await _api.postData('/api/v1/amial/merchant/wallets/accept', {
      'currency': w['currency'],
      'accepts': !(w['accepts_payments'] == true),
    });
    if (r.statusCode == 200) {
      _load();
    } else {
      _snack((r.body is Map ? r.body['message']?.toString() : null) ?? 'تعذّر الحفظ');
    }
  }

  // ═══════════════════════════════════════════════════════════════════
  // الصرف — **والسعرُ يُعرَض قبل التأكيد لا بعده.**
  //
  // تحويلٌ يقع ثمّ يُقرأ سعرُه بعده يجعل السعرَ مفاجأةً، وهو مال.
  // ═══════════════════════════════════════════════════════════════════

  Future<void> _convertDialog(Map<String, dynamic> from) async {
    final amount = TextEditingController();
    // الوجهةُ الافتراضيّةُ الأساس — وهو الطريقُ إلى النقد.
    String to = from['currency'] == _base ? _otherThanBase() : _base;
    Map<String, dynamic>? quote;
    bool quoting = false;
    String? quoteError;

    await showDialog<void>(
      context: context,
      builder: (ctx) => StatefulBuilder(builder: (ctx, setLocal) {
        Future<void> refreshQuote() async {
          final v = double.tryParse(amount.text.trim()) ?? 0;
          if (v <= 0) {
            setLocal(() {
              quote = null;
              quoteError = null;
            });
            return;
          }
          setLocal(() {
            quoting = true;
            quoteError = null;
          });
          final r = await _api.postData('/api/v1/amial/merchant/wallets/quote', {
            'from': from['currency'],
            'to': to,
            'amount': amount.text.trim(),
          });
          setLocal(() {
            quoting = false;
            if (r.statusCode == 200 && r.body is Map && r.body['success'] == true) {
              quote = Map<String, dynamic>.from((r.body['meta'] ?? {}) as Map);
            } else {
              quote = null;
              quoteError = (r.body is Map ? r.body['message']?.toString() : null) ??
                  'تعذّر حساب السعر';
            }
          });
        }

        return AlertDialog(
          title: Text('صرف من ${_nameOf(from['currency'])}'),
          content: SingleChildScrollView(
            child: Column(mainAxisSize: MainAxisSize.min, children: [
              Text('الرصيد المتاح: ${from['balance']} ${from['symbol']}',
                  style: const TextStyle(fontSize: 12, color: AmialColors.textSecondary)),
              const SizedBox(height: 12),
              TextField(
                controller: amount,
                keyboardType: const TextInputType.numberWithOptions(decimal: true),
                onChanged: (_) => refreshQuote(),
                decoration: InputDecoration(
                  labelText: 'المبلغ بـ${from['symbol']}',
                  border: const OutlineInputBorder(),
                ),
              ),
              const SizedBox(height: 12),
              DropdownButtonFormField<String>(
                value: to,
                decoration: const InputDecoration(labelText: 'إلى', border: OutlineInputBorder()),
                items: _wallets
                    .where((w) => w['currency'] != from['currency'])
                    .map((w) => DropdownMenuItem(
                        value: '${w['currency']}',
                        child: Text('${_nameOf(w['currency'])} (${w['symbol']})')))
                    .toList(),
                onChanged: (v) {
                  if (v == null) return;
                  setLocal(() => to = v);
                  refreshQuote();
                },
              ),
              const SizedBox(height: 14),
              if (quoting) const LinearProgressIndicator(minHeight: 2),

              // **السعرُ ومصدرُه ولحظتُه** — رقمٌ بلا مصدرٍ في مالٍ لا يُقبل.
              if (quote != null)
                Container(
                  width: double.infinity,
                  padding: const EdgeInsets.all(12),
                  decoration: BoxDecoration(
                    color: AmialColors.primary.withValues(alpha: 0.06),
                    borderRadius: BorderRadius.circular(10),
                  ),
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Text('تستلم: ${quote!['converted']} ${_symbolOf(to)}',
                        style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16)),
                    const SizedBox(height: 6),
                    Text('السعر: 1 ${from['currency']} = ${quote!['rate']} $to',
                        style: const TextStyle(fontSize: 12)),
                    Text('المصدر: ${quote!['rate_source'] ?? '—'}',
                        style: const TextStyle(fontSize: 11, color: AmialColors.textSecondary)),
                    if (quote!['rate_at'] != null)
                      Text('سريانه: ${'${quote!['rate_at']}'.split('T').first}',
                          style: const TextStyle(fontSize: 11, color: AmialColors.textSecondary)),
                  ]),
                ),

              // **وغيابُ السعر يُقال ولا يُترَك فراغاً.**
              if (quoteError != null)
                Padding(
                  padding: const EdgeInsets.only(top: 8),
                  child: Text(quoteError!,
                      style: const TextStyle(fontSize: 12, color: AmialColors.red)),
                ),
            ]),
          ),
          actions: [
            TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('إلغاء')),
            FilledButton(
              // **ولا تأكيدَ بلا سعرٍ محسوب** — زرٌّ يُضغط على مجهولٍ في
              // المال ليس زرّاً.
              onPressed: quote == null
                  ? null
                  : () {
                      Navigator.pop(ctx);
                      _doConvert(from['currency'], to, amount.text.trim());
                    },
              child: const Text('تأكيد الصرف'),
            ),
          ],
        );
      }),
    );
  }

  Future<void> _doConvert(String from, String to, String amount) async {
    final r = await _api.postData('/api/v1/amial/merchant/wallets/convert', {
      'from': from,
      'to': to,
      'amount': amount,
    });
    if (r.statusCode == 200 && r.body is Map && r.body['success'] == true) {
      final meta = (r.body['meta'] ?? {}) as Map;
      _snack('تمّ الصرف — استلمت ${meta['converted']} ${_symbolOf(to)}', ok: true);
      _load();
    } else {
      _snack((r.body is Map ? r.body['message']?.toString() : null) ?? 'تعذّر الصرف');
    }
  }

  String _nameOf(dynamic code) => _wallets
      .firstWhere((w) => w['currency'] == code, orElse: () => {'name': '$code'})['name']
      .toString();

  String _symbolOf(dynamic code) => _wallets
      .firstWhere((w) => w['currency'] == code, orElse: () => {'symbol': '$code'})['symbol']
      .toString();

  String _otherThanBase() {
    final o = _wallets.firstWhere((w) => w['currency'] != _base, orElse: () => {});
    return '${o['currency'] ?? _base}';
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(
        title: const Text('محافظي'),
        backgroundColor: AmialColors.primary,
        foregroundColor: Colors.white,
        actions: [
          IconButton(
            tooltip: 'تحديث',
            icon: const Icon(Icons.refresh),
            onPressed: _loading ? null : _load,
          ),
        ],
      ),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : _error != null
              ? Center(
                  child: Padding(
                    padding: const EdgeInsets.all(24),
                    child: Column(mainAxisSize: MainAxisSize.min, children: [
                      const Icon(Icons.workspace_premium,
                          size: 56, color: AmialColors.yellowDark),
                      const SizedBox(height: 12),
                      Text(_error!,
                          textAlign: TextAlign.center,
                          style: const TextStyle(fontSize: 15, fontWeight: FontWeight.w600)),
                      const SizedBox(height: 16),
                      OutlinedButton.icon(
                        onPressed: _load,
                        icon: const Icon(Icons.refresh),
                        label: const Text('إعادة المحاولة'),
                      ),
                    ]),
                  ),
                )
              : RefreshIndicator(
                  onRefresh: _load,
                  child: ListView(padding: const EdgeInsets.all(12), children: [
                    Padding(
                      padding: const EdgeInsets.all(8),
                      child: Text(
                        'العملة الأساس: $_baseSymbol. تقبض بأيّ عملةٍ مفعّلة، '
                        'وتصرف بينها بسعر المنصّة. والتسوية النقدية مع الوكيل '
                        'تبقى بـ$_baseSymbol — فاصرف إليه قبل السحب.',
                        style: const TextStyle(
                            fontSize: 12, color: AmialColors.textSecondary, height: 1.5),
                      ),
                    ),
                    ..._wallets.map(_card),
                  ]),
                ),
    );
  }

  Widget _card(Map<String, dynamic> w) {
    final isBase = w['is_base'] == true;
    final rateMissing = w['rate_missing'] == true;
    final accepts = w['accepts_payments'] == true;
    final balance = '${w['balance']}';
    final hasMoney = (double.tryParse(balance) ?? 0) > 0;

    return Container(
      margin: const EdgeInsets.only(bottom: 10),
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(14),
        border: isBase
            ? Border.all(color: AmialColors.primary.withValues(alpha: 0.35), width: 1.5)
            : null,
      ),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(children: [
          CircleAvatar(
            backgroundColor: AmialColors.primary.withValues(alpha: 0.1),
            child: Text('${w['symbol']}',
                style: const TextStyle(
                    fontWeight: FontWeight.bold, color: AmialColors.primary, fontSize: 12)),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Row(children: [
                Text('${w['name']}',
                    style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 15)),
                if (isBase) ...[
                  const SizedBox(width: 6),
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                    decoration: BoxDecoration(
                      color: AmialColors.primary.withValues(alpha: 0.12),
                      borderRadius: BorderRadius.circular(6),
                    ),
                    child: const Text('الأساس',
                        style: TextStyle(fontSize: 10, color: AmialColors.primary)),
                  ),
                ],
              ]),
              const SizedBox(height: 2),
              Text('$balance ${w['symbol']}',
                  textDirection: TextDirection.ltr,
                  style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 18)),
            ]),
          ),
          if (hasMoney)
            IconButton(
              tooltip: 'صرف',
              icon: const Icon(Icons.swap_horiz, color: AmialColors.primary),
              onPressed: () => _convertDialog(w),
            ),
        ]),
        const SizedBox(height: 8),

        // ── السعرُ ومصدرُه — أو غيابُه صراحةً ──────────────────────────
        if (!isBase)
          rateMissing
              // **«غير معروف» ليس واحداً.** عملةٌ بلا سعرٍ تُقال ولا
              // يُملأ سعرُها بـ١ فيصير مئةُ دولارٍ مئةَ ريال.
              ? Row(children: [
                  const Icon(Icons.error_outline, size: 14, color: AmialColors.red),
                  const SizedBox(width: 6),
                  const Expanded(
                    child: Text('لا سعر صرف مضبوط — تضبطه الإدارة قبل القبض بهذه العملة',
                        style: TextStyle(fontSize: 11, color: AmialColors.red)),
                  ),
                ])
              : Text(
                  '1 ${w['currency']} = ${w['rate_to_base']} $_baseSymbol'
                  '  ·  المصدر: ${w['rate_source'] ?? '—'}',
                  textDirection: TextDirection.ltr,
                  style: const TextStyle(fontSize: 11, color: AmialColors.textSecondary),
                ),

        if (!isBase) ...[
          const Divider(height: 18),
          Row(children: [
            const Expanded(
              child: Text('أقبل الدفع بهذه العملة', style: TextStyle(fontSize: 13)),
            ),
            Switch(
              value: accepts,
              activeColor: AmialColors.primary,
              // **ولا يُفتَح القبضُ على عملةٍ بلا سعر** — الخادمُ يرفضها
              // أيضاً، والإطفاءُ هنا يقول لماذا قبل أن يُضغط.
              onChanged: rateMissing ? null : (_) => _toggleAccept(w),
            ),
          ]),
        ],
      ]),
    );
  }
}
