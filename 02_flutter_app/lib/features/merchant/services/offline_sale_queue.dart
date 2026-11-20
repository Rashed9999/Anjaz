import 'dart:convert';

import 'package:get/get.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:amial_pay/data/api/api_client.dart';
import 'package:amial_pay/features/merchant/services/merchant_business_api_adapter.dart';

/// AMIAL-OFFLINE-POS-001 — طابور مبيعات دون اتصال.
///
/// عند فشل الاتصال أثناء تسجيل بيع، تُحفظ الحمولة محلياً (بمفتاح client_uuid)
/// وتُزامَن لاحقاً عند عودة الشبكة. الخادم يمنع الازدواج عبر نفس المفتاح
/// (idempotency)، فإعادة الإرسال آمنة تماماً.
///
/// AMIAL-BUSINESS-UNIFY-U2: يحفظ مع كل عملية مصدرها (`_offline_backend`:
/// `business` أو `legacy`) لكي تبقى العمليات غير المتصلة متوافقة عند التحويل
/// أو التراجع عنه بلا ازدواج ولا فقدان.
class OfflineSaleQueue extends GetxService {
  static const _key = 'amial_offline_sales_v1';
  static const _endpoint = '/api/v1/amial/merchant/cashier/sales';

  final ApiClient? _injectedApiClient;
  final MerchantBusinessApiAdapter? _injectedBusinessAdapter;

  OfflineSaleQueue({
    ApiClient? apiClient,
    MerchantBusinessApiAdapter? businessAdapter,
  })  : _injectedApiClient = apiClient,
        _injectedBusinessAdapter = businessAdapter;

  /// عدد المبيعات المعلّقة (يتفاعل معه الواجهة).
  final RxInt pending = 0.obs;

  Future<SharedPreferences> get _sp => SharedPreferences.getInstance();

  MerchantBusinessApiAdapter? get _businessAdapter =>
      _injectedBusinessAdapter ??
      (Get.isRegistered<MerchantBusinessApiAdapter>()
          ? Get.find<MerchantBusinessApiAdapter>()
          : null);

  List<Map<String, dynamic>> _read(SharedPreferences sp) {
    final raw = sp.getString(_key);
    if (raw == null || raw.isEmpty) return [];
    try {
      return (jsonDecode(raw) as List).map((e) {
        final item = Map<String, dynamic>.from(e as Map);
        // توافق مع المبيعات التي حُفظت قبل إضافة حالة المزامنة أو وسم الخادم.
        item.putIfAbsent('_offline_state', () => 'pending');
        item.putIfAbsent('_offline_attempts', () => 0);
        item.putIfAbsent('_offline_backend', () => 'legacy');
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
    final adapter = _businessAdapter;
    final targetBackend = (adapter != null && adapter.isEnabledForActiveMerchant)
        ? 'business'
        : 'legacy';
    final merchantId = adapter?.config.activeMerchantId;
    final list = _read(sp)
      ..add({
        ...payload,
        '_offline_state': 'pending',
        '_offline_attempts': 0,
        '_offline_backend': payload['_offline_backend'] ?? targetBackend,
        if (targetBackend == 'business' && merchantId != null && merchantId.isNotEmpty)
          '_offline_merchant_id': payload['_offline_merchant_id'] ?? merchantId,
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
  /// أخطاء الشبكة و5xx و429 تبقى للمحاولة؛ أمّا 4xx النهائية (بما فيها
  /// 409 IDEMPOTENCY_CONFLICT أو بيانات أو صلاحية...) فتُعلَّم «failed»
  /// وتُعرض للكاشير ولا تُعاد بلا تغيير.
  Future<int> sync() async {
    final sp = await _sp;
    final list = _read(sp);
    if (list.isEmpty) { pending.value = 0; return 0; }

    final adapter = _businessAdapter;
    final remaining = <Map<String, dynamic>>[];
    int done = 0;

    for (final payload in list) {
      if (payload['_offline_state'] == 'failed') {
        remaining.add(payload);
        continue;
      }
      try {
        final isBusinessItem = payload['_offline_backend'] == 'business';
        final queuedMerchant = '${payload['_offline_merchant_id'] ?? ''}'.trim();
        final activeMerchant = adapter?.config.activeMerchantId?.trim() ?? '';
        final merchantMatches = queuedMerchant.isNotEmpty && queuedMerchant == activeMerchant;
        final useBusiness = isBusinessItem &&
            adapter != null &&
            adapter.isEnabledForActiveMerchant &&
            merchantMatches;
        final Response r;
        if (useBusiness) {
          r = await adapter.recordSale(payload);
        } else if (isBusinessItem) {
          // حماية عزل المنشآت على الأجهزة المشتركة (F-04): لا تُرسل عملية business
          // إلى خادم أميال باي القديم ولا إلى منشأة تاجر آخر أبداً.
          final waitReason = (adapter != null && adapter.isEnabledForActiveMerchant && !merchantMatches)
              ? 'بانتظار تسجيل دخول التاجر صاحب هذه العملية لمزامنتها بأمان'
              : 'بانتظار تفعيل خادم أميال أعمال لمزامنة هذه العملية';
          remaining.add(_markRetry(payload, waitReason));
          continue;
        } else {
          final api = _injectedApiClient ?? Get.find<ApiClient>();
          r = await api.postData(_endpoint, payload);
        }
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
