import 'dart:convert';

import 'package:get/get.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:amyal_pay/data/api/api_client.dart';

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
      return (jsonDecode(raw) as List).map((e) => Map<String, dynamic>.from(e as Map)).toList();
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
    final list = _read(sp)..add(payload);
    await _write(sp, list);
  }

  Future<int> refreshCount() async {
    final sp = await _sp;
    final n = _read(sp).length;
    pending.value = n;
    return n;
  }

  /// حمولات معلّقة (للعرض).
  Future<List<Map<String, dynamic>>> items() async => _read(await _sp);

  /// يزامن كل المعلّق. يعيد عدد ما نجح. يبقي ما تعذّر (لا يزال دون اتصال) للمحاولة
  /// لاحقاً — والـ idempotency في الخادم يضمن ألا يتكرّر أي بيع نجح فعلاً.
  Future<int> sync() async {
    final sp = await _sp;
    final list = _read(sp);
    if (list.isEmpty) { pending.value = 0; return 0; }

    final api = Get.find<ApiClient>();
    final remaining = <Map<String, dynamic>>[];
    int done = 0;

    for (final payload in list) {
      try {
        final r = await api.postData(_endpoint, payload);
        if (r.statusCode == 200 || r.statusCode == 201) {
          done++; // نجح (أو أعاده الخادم كما هو — idempotent)
        } else if (r.statusCode == 1 || r.statusCode == -1) {
          remaining.add(payload); // ما زال دون اتصال — أبقِه
        } else {
          remaining.add(payload); // خطأ مؤقّت — أبقِه للمحاولة (آمن بالمفتاح)
        }
      } catch (_) {
        remaining.add(payload);
      }
    }

    await _write(sp, remaining);
    return done;
  }
}
