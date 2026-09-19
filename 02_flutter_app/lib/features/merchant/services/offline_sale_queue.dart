import 'dart:convert';

import 'package:get/get.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:amial_pay/data/api/api_client.dart';

/// AMIAL-OFFLINE-POS-001 — طابور مبيعات دون اتصال.
///
/// عند فشل الاتصال أثناء تسجيل بيع، تُحفظ الحمولة محلياً (بمفتاح client_uuid)
/// وتُزامَن لاحقاً عند عودة الشبكة. الخادم يمنع الازدواج عبر نفس المفتاح
/// (idempotency)، فإعادة الإرسال آمنة تماماً.
class OfflineSaleQueue extends GetxService {
  static const _key = 'amial_offline_sales_v1';
  static const _endpoint = '/api/v1/amial/merchant/cashier/sales';

  /// عدد المبيعات المعلّقة (يتفاعل معه الواجهة).
  final RxInt pending = 0.obs;

  Future<SharedPreferences> get _sp => SharedPreferences.getInstance();

  List<Map<String, dynamic>> _read(SharedPreferences sp) {
    final raw = sp.getString(_key);
    if (raw == null || raw.isEmpty) return [];
    try {
      return (jsonDecode(raw) as List).map((e) {
        final item = Map<String, dynamic>.from(e as Map);
        // توافق مع المبيعات التي حُفظت قبل إضافة حالة المزامنة.
        item.putIfAbsent('_offline_state', () => 'pending');
        item.putIfAbsent('_offline_attempts', () => 0);
        return item;
      }).toList();
    } catch (_) {
      return [];
    }
  }

  Future<void> _write(SharedPreferences sp, List<Map<String, dynamic>> list) async {
    await sp.setString(_key, jsonEncode(list));
    pending.value = list.length;
  }

  /// أضِف بيعاً للطابور (يجب أن تحتوي الحمولة client_uuid).
  Future<void> enqueue(Map<String, dynamic> payload) async {
    final sp = await _sp;
    final list = _read(sp)
      ..add({
        ...payload,
        '_offline_state': 'pending',
        '_offline_attempts': 0,
        '_offline_queued_at': DateTime.now().toIso8601String(),
      });
    await _write(sp, list);
  }

  Future<int> refreshCount() async {
    final sp = await _sp;
    final n = _read(sp).where((e) => e['_offline_state'] != 'failed').length;
    pending.value = n;
    return n;
  }

  /// حمولات معلّقة (للعرض).
  Future<List<Map<String, dynamic>>> items() async => _read(await _sp);

  /// يزامن المعلّق القابل للإعادة فقط.
  ///
  /// أخطاء الشبكة و5xx و429 تبقى للمحاولة؛ أمّا 4xx النهائية (بيانات، صلاحية،
  /// حد باقة...) فتُعلَّم «failed» وتُعرض للكاشير ولا تُعاد بلا تغيير.
  Future<int> sync() async {
    final sp = await _sp;
    final list = _read(sp);
    if (list.isEmpty) { pending.value = 0; return 0; }

    final api = Get.find<ApiClient>();
    final remaining = <Map<String, dynamic>>[];
    int done = 0;

    for (final payload in list) {
      if (payload['_offline_state'] == 'failed') {
        remaining.add(payload);
        continue;
      }
      try {
        final r = await api.postData(_endpoint, payload);
        if (r.statusCode == 200 || r.statusCode == 201) {
          done++; // نجح (أو أعاده الخادم كما هو — idempotent)
        } else if (_isRetryable(r.statusCode)) {
          remaining.add(_markRetry(payload));
        } else {
          remaining.add(_markFailed(payload, r.statusCode, _message(r.body)));
        }
      } catch (e) {
        // الاستثناء المحلي أو فقد الشبكة لا يثبت أن الخادم رفض البيع.
        remaining.add(_markRetry(payload, e.toString()));
      }
    }

    await _write(sp, remaining);
    return done;
  }

  Future<void> discard(String clientUuid) async {
    final sp = await _sp;
    final list = _read(sp)..removeWhere((e) => e['client_uuid'] == clientUuid);
    await _write(sp, list);
  }

  bool _isRetryable(int? statusCode) {
    if (statusCode == null || statusCode <= 0 || statusCode == 1 || statusCode == -1) return true;
    return statusCode == 408 || statusCode == 429 || statusCode >= 500;
  }

  Map<String, dynamic> _markRetry(Map<String, dynamic> payload, [String? error]) => {
        ...payload,
        '_offline_state': 'pending',
        '_offline_attempts': ((payload['_offline_attempts'] as num?)?.toInt() ?? 0) + 1,
        if (error != null) '_offline_last_error': error,
      };

  Map<String, dynamic> _markFailed(Map<String, dynamic> payload, int? statusCode, String message) => {
        ...payload,
        '_offline_state': 'failed',
        '_offline_attempts': ((payload['_offline_attempts'] as num?)?.toInt() ?? 0) + 1,
        '_offline_failure_code': statusCode,
        '_offline_last_error': message.isEmpty ? 'رفض الخادم العملية' : message,
      };

  String _message(dynamic body) {
    if (body is Map) {
      return '${body['message'] ?? body['error'] ?? ''}';
    }
    return '';
  }
}
