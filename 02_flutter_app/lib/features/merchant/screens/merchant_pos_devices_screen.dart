import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amial_pay/data/api/api_client.dart';
import 'package:amial_pay/data/api/pos_device_identity.dart';
import 'package:amial_pay/theme/amial_colors.dart';

/// AMIAL-POS-DEVICES-008 — إدارة أجهزة نقاط البيع.
///
/// ══════════════════════════════════════════════════════════════════════
/// **الجهازُ مقعدُ ترخيصٍ يملكه التاجر، لا حسابُ موظّف.**
///
/// والموظّفون يتناوبون عليه بحساباتهم. فهذه الشاشةُ غيرُ «الموظفون»:
/// تلك تُدير مَن يعمل، وهذه تُدير على ماذا يعملون.
///
/// **ولا تُعرض البصمةُ كاملةً** — أربعةُ محارفَ يميّز بها التاجرُ جهازَه.
/// فالمقصودُ مقعدٌ موثوق، لا تتبّعُ حامله. والخادمُ لا يُرسل أكثرَ من
/// ذلك أصلاً.
///
/// موصولةٌ بالخادم الحقيقيّ: `/api/v1/amial/merchant/pos-devices`.
class MerchantPosDevicesScreen extends StatefulWidget {
  const MerchantPosDevicesScreen({super.key});

  @override
  State<MerchantPosDevicesScreen> createState() => _MerchantPosDevicesScreenState();
}

class _MerchantPosDevicesScreenState extends State<MerchantPosDevicesScreen> {
  final _api = Get.find<ApiClient>();

  bool _loading = true;
  String? _error;
  List<Map<String, dynamic>> _devices = [];
  int _used = 0;
  int _max = 0;
  bool _unlimited = false;

  static const _base = '/api/v1/amial/merchant/pos-devices';

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
      final r = await _api.getData(_base);

      if (r.statusCode == 200 && r.body is Map && r.body['success'] == true) {
        final data = (r.body['data'] ?? {}) as Map;

        setState(() {
          _devices = ((data['devices'] ?? []) as List)
              .map((e) => Map<String, dynamic>.from(e as Map))
              .toList();
          _used = (data['used'] ?? 0) as int;
          _max = (data['max'] ?? 0) as int;
          _unlimited = (data['unlimited'] ?? false) as bool;
        });
      } else {
        _error = _messageOf(r.body) ?? 'تعذّر تحميل الأجهزة';
      }
    } catch (_) {
      _error = 'خطأ في الشبكة';
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  String? _messageOf(dynamic body) =>
      body is Map ? body['message']?.toString() : null;

  void _snack(String m, {bool ok = false}) =>
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
        content: Text(m),
        backgroundColor: ok ? AmialColors.success : AmialColors.red,
      ));

  /// **تسجيلُ الجهاز الحاليّ — وهو الفعلُ الذي بدونه لا يعمل شيء.**
  ///
  /// ══════════════════════════════════════════════════════════════════
  /// **ولمَ يفعله صاحبُ الحساب لا الموظّف:**
  ///
  /// المقعدُ مورِدٌ في باقة التاجر. ولو سجّله الموظّفُ لاستنفد كاشيرٌ
  /// حدَّ متجرٍ كامل بفتح التطبيق على هواتفه. **وتسجيلُ الجهاز ≠ مصادقةُ
  /// الموظّف** — وهو قيدٌ صريحٌ في المواصفة.
  ///
  /// فالترتيبُ على جهازٍ جديد: يدخل **التاجر** مرّةً ويضغط هنا، ثمّ
  /// يتناوب موظّفوه عليه بحساباتهم.
  Future<void> _registerThisDevice() async {
    final uuid = await PosDeviceIdentity.get();

    if (uuid == null) {
      _snack('تعذّر توليد هويّة لهذا الجهاز — المخزن الآمن غير متاح');
      return;
    }

    if (!mounted) return;

    final nameCtrl = TextEditingController();

    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('تسجيل هذا الجهاز'),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Text(
              'سيشغل هذا الجهاز مقعداً من باقتك، فيصير مصرّحاً له بتشغيل '
              'نقطة البيع. والموظّفون يدخلون عليه بحساباتهم.',
              style: TextStyle(fontSize: 13),
            ),
            const SizedBox(height: 12),
            TextField(
              controller: nameCtrl,
              decoration: const InputDecoration(
                labelText: 'اسمٌ يميّزه',
                hintText: 'كاشير الواجهة',
                border: OutlineInputBorder(),
              ),
            ),
          ],
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx, false),
            child: const Text('إلغاء'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(ctx, true),
            child: const Text('تسجيل'),
          ),
        ],
      ),
    );

    if (ok != true || !mounted) return;

    final r = await _api.postData(_base, {
      'device_uuid': uuid,
      'display_name': nameCtrl.text.trim(),
      'platform': Theme.of(context).platform.name,
    });

    if (r.statusCode == 200) {
      // AMIAL-DART-PARSE-001 — **تعبيرٌ شرطيٌّ يلتبس على المحلّل.**
      //
      // `cond ? a?['k'] : b` — المحلّلُ يقرأ `?[` بعد تعبيرٍ داخل شرطيٍّ
      // فيضطرب: `Expected an identifier` و`Expected to find ':'` في
      // الموضع نفسِه. ولا يظهر هنا إطلاقاً (‏لا Flutter في هذه الحاوية)
      // ويسقط بناءُ Codemagic — **وأوّلُ من يراه صاحبُ المشروع**.
      //
      // فيُفكَّك إلى خطوتين: أوضحُ للقارئ، وبلا التباسٍ للمحلّل.
      final body = r.body;
      final data = body is Map ? body['data'] : null;
      final created = data is Map && data['created'] == true;

      _snack(created ? 'سُجّل الجهاز وشُغل مقعده' : 'هذا الجهاز مسجَّلٌ سلفاً',
          ok: true);
      _load();
    } else {
      // **ورسالةُ الخادم تُعرض كما هي** — فهي تقول «بلغتَ حدَّ الأجهزة
      // (٣ من ٣)» وتلك أنفعُ من «تعذّر التسجيل».
      _snack(_messageOf(r.body) ?? 'تعذّر تسجيل الجهاز');
    }
  }

  /// **الإلغاءُ يُؤكَّد** — فهو يُخرج جهازاً من الخدمة فوراً ويقطع جلساته.
  Future<void> _revoke(Map<String, dynamic> d) async {
    final name = (d['display_name'] ?? '').toString().trim();
    final label = name.isEmpty ? 'الجهاز •••${d['hint'] ?? ''}' : name;

    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('إلغاء الجهاز'),
        content: Text(
          'سيتوقف «$label» عن العمل فوراً، وتُقطع جلساتُه المفتوحة.\n\n'
          'ويُفرَج عن مقعده فيمكن تسجيل جهازٍ آخر مكانه.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx, false),
            child: const Text('تراجع'),
          ),
          FilledButton(
            style: FilledButton.styleFrom(backgroundColor: AmialColors.red),
            onPressed: () => Navigator.pop(ctx, true),
            child: const Text('إلغاء الجهاز'),
          ),
        ],
      ),
    );

    if (ok != true || !mounted) return;

    final r = await _api.deleteData('$_base/${d['id']}');

    if (r.statusCode == 200) {
      _snack('أُلغي الجهاز وأُفرِج عن مقعده', ok: true);
      _load();
    } else {
      _snack(_messageOf(r.body) ?? 'تعذّر الإلغاء');
    }
  }

  Future<void> _rename(Map<String, dynamic> d) async {
    final ctrl = TextEditingController(text: (d['display_name'] ?? '').toString());

    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('اسم الجهاز'),
        content: TextField(
          controller: ctrl,
          decoration: const InputDecoration(
            labelText: 'اسمٌ يميّزه',
            hintText: 'كاشير الواجهة',
            border: OutlineInputBorder(),
          ),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx, false),
            child: const Text('إلغاء'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(ctx, true),
            child: const Text('حفظ'),
          ),
        ],
      ),
    );

    if (ok != true || !mounted) return;

    final r = await _api.putData('$_base/${d['id']}', {
      'display_name': ctrl.text.trim(),
    });

    if (r.statusCode == 200) {
      _snack('حُفظ الاسم', ok: true);
      _load();
    } else {
      _snack(_messageOf(r.body) ?? 'تعذّر الحفظ');
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(
        title: const Text('أجهزة نقاط البيع'),
        actions: [
          IconButton(
            tooltip: 'تسجيل هذا الجهاز',
            onPressed: _loading ? null : _registerThisDevice,
            icon: const Icon(Icons.add_to_home_screen),
          ),
          IconButton(
            tooltip: 'تحديث',
            onPressed: _loading ? null : _load,
            icon: const Icon(Icons.refresh),
          ),
        ],
      ),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : _error != null
              ? _errorState()
              : RefreshIndicator(
                  onRefresh: _load,
                  child: _devices.isEmpty ? _emptyState() : _list(),
                ),
    );
  }

  Widget _errorState() => Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              const Icon(Icons.error_outline, size: 48, color: AmialColors.red),
              const SizedBox(height: 12),
              Text(_error!, textAlign: TextAlign.center),
              const SizedBox(height: 16),
              FilledButton(onPressed: _load, child: const Text('إعادة المحاولة')),
            ],
          ),
        ),
      );

  Widget _emptyState() => ListView(
        children: [
          _quotaBar(),
          const SizedBox(height: 60),
          const Icon(Icons.devices_other, size: 56, color: Colors.grey),
          const SizedBox(height: 12),
          const Center(
            child: Padding(
              padding: EdgeInsets.symmetric(horizontal: 32),
              child: Text(
                'لا جهازَ مسجَّلٌ بعد.\n\n'
                'سجّل هذا الجهاز ليعمل عليه موظّفوك، أو افتح التطبيق على '
                'جهاز الكاشير وسجّله من هنا بحسابك.',
                textAlign: TextAlign.center,
                style: TextStyle(color: Colors.grey),
              ),
            ),
          ),
          const SizedBox(height: 20),
          Center(
            child: FilledButton.icon(
              onPressed: _registerThisDevice,
              icon: const Icon(Icons.add_to_home_screen),
              label: const Text('سجّل هذا الجهاز'),
            ),
          ),
        ],
      );

  /// **المستهلَكُ من الحدّ يُقرأ من الخادم** — ولا يُحسب هنا.
  ///
  /// فحسابُه في الشاشة يُنتج تعريفاً ثانياً للحدّ، وهو العطلُ الذي تكرّر
  /// في هذا المشروع: مصدرا حقيقةٍ لشيءٍ واحدٍ يفترقان بهدوء.
  Widget _quotaBar() {
    final full = !_unlimited && _used >= _max;

    return Container(
      margin: const EdgeInsets.all(12),
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
      decoration: BoxDecoration(
        // **`AmialColors.surface` لا وجودَ لها** — التوكِنُ اسمُه
        // `cardSurface`. ولونٌ يُخترَع اسمُه هو ما ولّد ستّةَ أخضرَ
        // للنجاح في هذا المشروع. (‏وثيقةُ الهويّة البصريّة.)
        color: full ? AmialColors.red.withValues(alpha: 0.08) : AmialColors.cardSurface,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(
          color: full ? AmialColors.red : AmialColors.border,
        ),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(children: [
            Icon(Icons.devices, color: full ? AmialColors.red : AmialColors.primary),
            const SizedBox(width: 12),
            Expanded(child: Text(
              _unlimited
                  ? 'المقاعد المستعملة: $_used (بلا حدّ في باقتك)'
                  : 'المقاعد المستعملة: $_used من $_max',
              style: const TextStyle(fontWeight: FontWeight.bold),
            )),
            if (full) const Text('ممتلئ', style: TextStyle(color: AmialColors.red)),
          ]),
          const SizedBox(height: 6),
          const Text(
            'هذا جهاز تشغيل فقط: لا يملك حساب دخول أو محفظة. أضف حساب الموظف من شاشة «الموظفون وحساباتهم».',
            style: TextStyle(fontSize: 11, color: AmialColors.textMuted),
          ),
        ],
      ),
    );
  }

  Widget _list() => ListView.builder(
        itemCount: _devices.length + 1,
        itemBuilder: (_, i) {
          if (i == 0) return _quotaBar();

          final d = _devices[i - 1];
          final active = (d['is_active'] ?? false) as bool;
          final name = (d['display_name'] ?? '').toString().trim();
          final live = (d['live_sessions'] ?? 0) as int;

          return Card(
            margin: const EdgeInsets.symmetric(horizontal: 12, vertical: 5),
            child: ListTile(
              leading: Icon(
                active ? Icons.point_of_sale : Icons.block,
                color: active ? AmialColors.success : Colors.grey,
              ),
              title: Text(name.isEmpty ? 'جهاز •••${d['hint'] ?? ''}' : name),
              subtitle: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                mainAxisSize: MainAxisSize.min,
                children: [
                  Text('المعرّف: •••${d['hint'] ?? '—'}   ·   ${d['platform'] ?? '—'}'),
                  Text(active
                      ? 'آخر نشاط: ${_when(d['last_seen_at'])}'
                      : 'أُلغي: ${_when(d['revoked_at'])}'),
                  if (active && live > 0)
                    Text('جلسات مفتوحة: $live',
                        style: const TextStyle(color: AmialColors.primary)),
                ],
              ),
              isThreeLine: true,
              trailing: active
                  ? Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        IconButton(
                          tooltip: 'تسمية',
                          icon: const Icon(Icons.edit_outlined),
                          onPressed: () => _rename(d),
                        ),
                        IconButton(
                          tooltip: 'إلغاء الجهاز',
                          icon: const Icon(Icons.link_off, color: AmialColors.red),
                          onPressed: () => _revoke(d),
                        ),
                      ],
                    )
                  : const Text('ملغى', style: TextStyle(color: Colors.grey)),
            ),
          );
        },
      );

  /// **والتاريخُ الغائبُ يُقال «—» ولا يُقرأ الآن.**
  String _when(dynamic iso) {
    if (iso == null) return '—';

    final t = DateTime.tryParse(iso.toString());

    if (t == null) return '—';

    final d = DateTime.now().difference(t);

    if (d.inMinutes < 1) return 'الآن';
    if (d.inMinutes < 60) return 'قبل ${d.inMinutes} دقيقة';
    if (d.inHours < 24) return 'قبل ${d.inHours} ساعة';

    return 'قبل ${d.inDays} يوم';
  }
}
