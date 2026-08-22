import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amial_pay/data/api/api_client.dart';
import 'package:amial_pay/theme/amial_colors.dart';
import 'package:amial_pay/common/widgets/async_state_view.dart';

/// AMIAL-PROFILE-CHANGE-006 — «تحديث بياناتي» (جهة العميل).
///
/// ══════════════════════════════════════════════════════════════════════
/// **الحلقةُ الأخيرة.** الخادمُ يفتح الطلبَ ويحسمه، واللوحةُ تعرض الطابور،
/// **ولا مكانَ يملأ فيه العميل** — فيبقى الطلبُ `PENDING_CUSTOMER` إلى
/// الأبد.
///
/// وهو نفسُ عطل مستندات الهويّة: زرٌّ في لوحة الدعم يضع علامةً على
/// المستخدم ثمّ لا مكان يستجيب فيه. **الزرُّ يعمل والعميلُ ينتظر ما لن
/// يأتي.**
///
/// ══════════════════════════════════════════════════════════════════════
/// **وشاشةٌ واحدةٌ لسؤالين متلازمين:** «ماذا يُطلب منّي؟» و«متى تنتهي
/// هويّتي؟». وفصلُهما يجعل الثانيَ لا يُرى — ووثيقةٌ رسميّةٌ في اليمن
/// تُستخرج في أسابيع، فمن عَلِم يومَ انتهائها لا يجد وقتاً.
///
/// **والحقولُ تُسأل من الخادم ولا تُكتب هنا:** قائمةٌ مكتوبةٌ في التطبيق
/// تشيخ — يُضاف حقلٌ فلا يظهر أبداً، ويُحذف آخرُ فيبقى معروضاً ويُرفض عند
/// الإرسال.
class MyProfileChangesScreen extends StatefulWidget {
  const MyProfileChangesScreen({super.key});

  @override
  State<MyProfileChangesScreen> createState() => _MyProfileChangesScreenState();
}

class _MyProfileChangesScreenState extends State<MyProfileChangesScreen> {
  final _api = Get.find<ApiClient>();

  bool _loading = true;
  String? _error;

  List<Map<String, dynamic>> _requests = [];
  List<Map<String, dynamic>> _fields = [];
  Map<String, dynamic> _identity = {};

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() { _loading = true; _error = null; });
    try {
      final r = await _api.getData('/api/v1/amial/me/profile-changes');
      if (r.statusCode == 200 && r.body is Map) {
        final d = (r.body['data'] ?? {}) as Map;
        _requests = ((d['requests'] ?? []) as List)
            .map((e) => Map<String, dynamic>.from(e as Map)).toList();
        _identity = Map<String, dynamic>.from((d['identity'] ?? {}) as Map);
      } else {
        _error = 'تعذّر تحميل طلباتك';
      }

      final f = await _api.getData('/api/v1/amial/me/profile-changes/fields');
      if (f.statusCode == 200 && f.body is Map) {
        _fields = ((f.body['data'] ?? []) as List)
            .map((e) => Map<String, dynamic>.from(e as Map)).toList();
      }
    } catch (_) {
      _error = 'تعذّر الاتصال — تحقّق من الشبكة';
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  void _snack(String m, {bool ok = false}) =>
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
        content: Text(m),
        backgroundColor: ok ? AmialColors.success : AmialColors.red,
      ));

  /// **والرسالةُ من الخادم تُعرَض كما هي** — هو الذي يعرف لماذا رفض،
  /// و«تعذّر الإرسال» تُنتج مكالمةَ دعمٍ لا إجراءً.
  String _serverMessage(dynamic body, String fallback) {
    if (body is Map && body['message'] is String &&
        (body['message'] as String).trim().isNotEmpty) {
      return body['message'] as String;
    }
    return fallback;
  }

  // ══════════════════════════════════════════════════════════════════
  //  ① فتحُ طلبٍ جديد
  // ══════════════════════════════════════════════════════════════════

  Future<void> _openRequest() async {
    if (_fields.isEmpty) {
      _snack('قائمةُ الحقول لم تُحمَّل بعد');
      return;
    }

    String field = _fields.first['field'] as String;
    final reason = TextEditingController();

    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setLocal) => AlertDialog(
          title: const Text('طلبُ تحديث بيان'),
          content: Column(mainAxisSize: MainAxisSize.min, children: [
            DropdownButtonFormField<String>(
              key: const Key('pc-field'),
              initialValue: field,
              isExpanded: true,
              decoration: const InputDecoration(
                  labelText: 'ما الذي تريد تحديثه؟', border: OutlineInputBorder()),
              items: [
                for (final f in _fields)
                  DropdownMenuItem(
                    value: f['field'] as String,
                    child: Text(
                      (f['label'] ?? f['field']).toString()
                          + ((f['needs_document'] == true) ? ' — يلزمه وثيقة' : ''),
                      overflow: TextOverflow.ellipsis,
                    ),
                  ),
              ],
              onChanged: (v) => setLocal(() => field = v ?? field),
            ),
            const SizedBox(height: 12),
            TextField(
              key: const Key('pc-reason'),
              controller: reason,
              maxLines: 2,
              decoration: const InputDecoration(
                labelText: 'لماذا؟',
                hintText: 'مثال: انتقلتُ إلى سكنٍ جديد',
                border: OutlineInputBorder(),
              ),
            ),
            const SizedBox(height: 10),
            // **ويُقال للعميل ما سيقع قبل أن يقع** — مفاجأةُ «حسابك عاد
            // إلى التوثيق» بعد الإرسال تُنتج شكوى، وقولُها قبله يُنتج قراراً.
            if (_fields.firstWhere((f) => f['field'] == field,
                    orElse: () => const {})['resets_verification'] == true)
              const Text(
                'تنبيه: تغييرُ هذا البيان يُعيد حسابَك إلى مراجعة التوثيق — '
                'فالوثيقةُ المعتمَدةُ تخصّ البيانَ القديم.',
                style: TextStyle(fontSize: 12, height: 1.6),
              ),
          ]),
          actions: [
            TextButton(onPressed: () => Navigator.pop(ctx, false),
                child: const Text('إلغاء')),
            ElevatedButton(
              key: const Key('pc-open-confirm'),
              onPressed: () => Navigator.pop(ctx, true),
              child: const Text('أرسل الطلب'),
            ),
          ],
        ),
      ),
    );

    if (ok != true) return;

    if (reason.text.trim().length < 5) {
      _snack('اذكر السببَ بوضوح — وطلبٌ بلا سببٍ لا يُراجَع');
      return;
    }

    try {
      final r = await _api.postData('/api/v1/amial/me/profile-changes',
          {'field': field, 'reason': reason.text.trim()});

      if (r.statusCode == 201) {
        _snack('فُتح الطلب — أدخل القيمة الجديدة الآن', ok: true);
        await _load();
      } else {
        _snack(_serverMessage(r.body, 'تعذّر فتحُ الطلب'));
      }
    } catch (_) {
      _snack('تعذّر الاتصال');
    }
  }

  // ══════════════════════════════════════════════════════════════════
  //  ② ملءُ القيمة الجديدة — وهي الحلقةُ التي كانت مفقودة
  // ══════════════════════════════════════════════════════════════════

  Future<void> _submit(Map<String, dynamic> req) async {
    final value = TextEditingController();
    final needsDoc = req['needs_document'] == true;

    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text('${req['field_label']}'),
        content: Column(mainAxisSize: MainAxisSize.min, children: [
          // **و«قبل» تُعرَض مع «بعد»** — فالعميلُ يرى ما يُغيّره لا ما
          // يكتبه فحسب، ويلتقط خطأَه قبل أن يصل المراجع.
          Align(
            alignment: AlignmentDirectional.centerStart,
            child: Text('القيمة الحاليّة: ${req['old_value'] ?? '(فارغة)'}',
                style: const TextStyle(fontSize: 13, color: Colors.black54)),
          ),
          const SizedBox(height: 12),
          TextField(
            key: const Key('pc-new-value'),
            controller: value,
            decoration: const InputDecoration(
                labelText: 'القيمة الجديدة', border: OutlineInputBorder()),
          ),
          if (needsDoc) ...[
            const SizedBox(height: 12),
            // **ورقمُ هويّةٍ يتغيّر بلا وثيقةٍ ليس تحديثاً بل استبدالَ شخص.**
            //
            // ويُقال ذلك ها هنا صراحةً: الرفعُ من شاشة «مستنداتي»، وشاشةٌ
            // ترفض ولا تقول أين المخرجُ تُنتج تذكرةَ دعم.
            const Text(
              'هذا البيان يُعرّف شخصك — ارفع وثيقةً داعمةً من شاشة «مستنداتي» '
              'قبل الإرسال، وأدخل رقمَها أدناه.',
              style: TextStyle(fontSize: 12, height: 1.6),
            ),
            const SizedBox(height: 8),
            TextField(
              key: const Key('pc-doc-id'),
              controller: _docId,
              keyboardType: TextInputType.number,
              decoration: const InputDecoration(
                  labelText: 'رقم الوثيقة المرفوعة', border: OutlineInputBorder()),
            ),
          ],
        ]),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false),
              child: const Text('إلغاء')),
          ElevatedButton(
            key: const Key('pc-submit-confirm'),
            onPressed: () => Navigator.pop(ctx, true),
            child: const Text('إرسال للمراجعة'),
          ),
        ],
      ),
    );

    if (ok != true) return;

    if (value.text.trim().isEmpty) {
      _snack('أدخل القيمة الجديدة');
      return;
    }

    try {
      final body = <String, dynamic>{'new_value': value.text.trim()};
      if (needsDoc && _docId.text.trim().isNotEmpty) {
        body['supporting_document_id'] = _docId.text.trim();
      }

      final r = await _api.postData(
          '/api/v1/amial/me/profile-changes/${req['id']}/submit', body);

      if (r.statusCode == 200) {
        _snack('أُرسل للمراجعة', ok: true);
        _docId.clear();
        await _load();
      } else {
        _snack(_serverMessage(r.body, 'تعذّر الإرسال'));
      }
    } catch (_) {
      _snack('تعذّر الاتصال');
    }
  }

  final _docId = TextEditingController();

  Future<void> _cancel(Map<String, dynamic> req) async {
    try {
      final r = await _api.postData(
          '/api/v1/amial/me/profile-changes/${req['id']}/cancel', {});

      if (r.statusCode == 200) {
        _snack('أُلغي الطلب', ok: true);
        await _load();
      } else {
        _snack(_serverMessage(r.body, 'تعذّر الإلغاء'));
      }
    } catch (_) {
      _snack('تعذّر الاتصال');
    }
  }

  @override
  void dispose() {
    _docId.dispose();
    super.dispose();
  }

  // ══════════════════════════════════════════════════════════════════

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('تحديث بياناتي')),
      floatingActionButton: FloatingActionButton.extended(
        key: const Key('pc-open'),
        onPressed: _openRequest,
        icon: const Icon(Icons.add),
        label: const Text('طلبُ تحديث'),
      ),
      body: RefreshIndicator(
        onRefresh: _load,
        child: AsyncStateView(
          loading: _loading,
          error: _error,
          onRetry: _load,
          child: ListView(
            padding: const EdgeInsets.fromLTRB(16, 16, 16, 96),
            children: [
              _identityCard(),
              const SizedBox(height: 20),
              const Text('طلباتي', style: TextStyle(
                  fontSize: 16, fontWeight: FontWeight.bold)),
              const SizedBox(height: 8),
              if (_requests.isEmpty)
                // **وفراغٌ يُقال فراغاً** — لا قائمةٌ خاليةٌ بلا كلمة.
                const Padding(
                  padding: EdgeInsets.symmetric(vertical: 24),
                  child: Text('لا طلباتٍ بعد. اضغط «طلبُ تحديث» لفتح واحد.',
                      textAlign: TextAlign.center,
                      style: TextStyle(color: Colors.black54)),
                )
              else
                for (final r in _requests) _requestCard(r),
            ],
          ),
        ),
      ),
    );
  }

  /// **بطاقةُ الهويّة — وهي جوابُ السؤال الذي لم يكن يُجاب.**
  ///
  /// `identification_expiry_date` كان يُملأ عند التسجيل ولا يقرؤه سطرٌ
  /// واحدٌ في المنتج كلِّه. فلا العميلُ يعرف، ولا المراجعُ يُنذَر.
  Widget _identityCard() {
    final state = (_identity['state'] ?? 'UNKNOWN').toString();
    final days = _identity['days'];
    final expires = _identity['expires_at'];

    late final Color tone;
    late final String title;

    switch (state) {
      case 'VALID':
        tone = AmialColors.success;
        title = 'هويّتك سارية';
        break;
      case 'DUE':
        tone = AmialColors.warning;
        title = 'هويّتك تقترب من الانتهاء';
        break;
      case 'EXPIRED':
        tone = AmialColors.red;
        title = 'هويّتك منتهية';
        break;
      default:
        // **و«غير معروف» ليس «سارية»** — يُقال صراحةً مع سببه، ولا
        // يُعرَض أخضرَ يطمئن على ما لا نعرفه.
        tone = Colors.blueGrey;
        title = 'لا تاريخَ انتهاءٍ مسجَّلٌ لهويّتك';
    }

    return Container(
      key: const Key('pc-identity'),
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: tone.withValues(alpha: 0.08),
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: tone.withValues(alpha: 0.45)),
      ),
      child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Icon(state == 'VALID' ? Icons.verified_user_outlined
                              : Icons.badge_outlined,
            color: tone),
        const SizedBox(width: 12),
        Expanded(
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(title, style: TextStyle(
                fontWeight: FontWeight.bold, color: tone, height: 1.6)),
            if (expires != null)
              Text(
                state == 'EXPIRED'
                    ? 'انتهت في $expires — جدّدها ثمّ افتح طلبَ تحديثٍ لتاريخها'
                    : 'تنتهي في $expires${days is int ? ' (بعد $days يوماً)' : ''}',
                style: const TextStyle(fontSize: 13, height: 1.7),
              )
            else
              const Text(
                'أضِف تاريخَ الانتهاء بطلبِ تحديثٍ — به نُنذرك قبل أن تنتهي.',
                style: TextStyle(fontSize: 13, height: 1.7),
              ),
          ]),
        ),
      ]),
    );
  }

  Widget _requestCard(Map<String, dynamic> r) {
    final status = (r['status'] ?? '').toString();

    const labels = {
      'PENDING_CUSTOMER': 'بانتظارك — أدخل القيمة الجديدة',
      'PENDING_REVIEW': 'بانتظار المراجعة',
      'APPROVED': 'اعتُمد',
      'REJECTED': 'رُفض',
      'CANCELLED': 'أُلغي',
    };

    final tone = switch (status) {
      'APPROVED' => AmialColors.success,
      'REJECTED' => AmialColors.red,
      'PENDING_CUSTOMER' => AmialColors.warning,
      _ => Colors.blueGrey,
    };

    return Card(
      margin: const EdgeInsets.only(bottom: 12),
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(children: [
            Expanded(
              child: Text('${r['field_label']}',
                  style: const TextStyle(fontWeight: FontWeight.bold)),
            ),
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
              decoration: BoxDecoration(
                color: tone.withValues(alpha: 0.12),
                borderRadius: BorderRadius.circular(20),
              ),
              child: Text(labels[status] ?? status,
                  style: TextStyle(fontSize: 12, color: tone)),
            ),
          ]),
          const SizedBox(height: 8),
          Text('من: ${r['old_value'] ?? '(فارغة)'}',
              style: const TextStyle(fontSize: 13, color: Colors.black54)),
          Text('إلى: ${r['new_value'] ?? '(لم تُدخَل بعد)'}',
              style: const TextStyle(fontSize: 13)),

          // **ورفضٌ بلا سببٍ يجعل العميلَ يعيد الطلبَ نفسَه مرّةً بعد مرّة.**
          if (status == 'REJECTED' && r['decision_reason'] != null) ...[
            const SizedBox(height: 8),
            Text('سببُ الرفض: ${r['decision_reason']}',
                style: TextStyle(fontSize: 13, color: AmialColors.red, height: 1.6)),
          ],

          if (status == 'PENDING_CUSTOMER' || status == 'PENDING_REVIEW') ...[
            const SizedBox(height: 10),
            Row(children: [
              if (status == 'PENDING_CUSTOMER')
                ElevatedButton(
                  key: Key('pc-fill-${r['id']}'),
                  onPressed: () => _submit(r),
                  child: const Text('أدخل القيمة'),
                ),
              const SizedBox(width: 8),
              TextButton(
                key: Key('pc-cancel-${r['id']}'),
                onPressed: () => _cancel(r),
                child: const Text('إلغاء الطلب'),
              ),
            ]),
          ],
        ]),
      ),
    );
  }
}
