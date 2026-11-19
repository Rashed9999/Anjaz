import 'dart:convert';

import 'package:get/get.dart';
import 'package:http/http.dart' as http;
import 'package:amial_pay/features/merchant/services/merchant_business_backend_config.dart';

/// AMIAL-BUSINESS-UNIFY-U2 — محوّل عقد التطبيق المحمول إلى `Amial-Merchant` (`/api/v1/mobile/*`).
///
/// يعمل فقط عندما يكون `merchantBusinessBackendEnabled` مفعّلاً صراحةً للتاجر النشط
/// مع رمز Bearer خاص بأميال أعمال. لا يغيّر ترويسات `ApiClient` الخاصة بالمحفظة
/// أو العملاء، ويحافظ على تنسيق السلاسل العشرية و`Idempotency-Key` و`client_uuid`.
class MerchantBusinessApiAdapter extends GetxService {
  static const String _mobilePrefix = '/api/v1/mobile';
  static const Duration _timeout = Duration(seconds: 30);

  final MerchantBusinessBackendConfig config;
  final http.Client _http;

  MerchantBusinessApiAdapter({
    required this.config,
    http.Client? httpClient,
  }) : _http = httpClient ?? http.Client();

  bool get isEnabledForActiveMerchant => config.isReadyForActiveMerchant;

  Map<String, String> _headers({String? idempotencyKey, String? overrideToken}) {
    final token = overrideToken ?? config.businessTokenFor(config.activeMerchantId);
    return <String, String>{
      'Accept': 'application/json',
      'Content-Type': 'application/json; charset=UTF-8',
      if (token != null && token.isNotEmpty) 'Authorization': 'Bearer $token',
      if (idempotencyKey != null && idempotencyKey.trim().isNotEmpty)
        'Idempotency-Key': idempotencyKey.trim(),
    };
  }

  Uri _uri(String path, {Map<String, String>? query}) {
    final base = config.businessBaseUrlFor(config.activeMerchantId);
    final raw = '$base$_mobilePrefix$path';
    if (query == null || query.isEmpty) {
      return Uri.parse(raw);
    }
    final filtered = <String, String>{};
    query.forEach((k, v) {
      if (v.isNotEmpty) filtered[k] = v;
    });
    final parsed = Uri.parse(raw);
    return parsed.replace(queryParameters: {...parsed.queryParameters, ...filtered});
  }

  // ── المصادقة والهوية ─────────────────────────────────────────────

  Future<Response> login({
    required String login,
    required String password,
    String? deviceName,
    String? posDeviceCode,
  }) {
    return _post('/auth/login', {
      'login': login,
      'password': password,
      if (deviceName != null && deviceName.isNotEmpty) 'device_name': deviceName,
      if (posDeviceCode != null && posDeviceCode.isNotEmpty) 'pos_device_code': posDeviceCode,
    });
  }

  Future<Response> me() => _get('/me');

  Future<Response> logout() => _post('/auth/logout', const {});

  // ── نقطة البيع العامة (POS) ──────────────────────────────────────

  Future<Response> products({String? search}) async {
    final res = await _get('/pos/products', query: {
      if (search != null && search.isNotEmpty) 'q': search,
    });
    return _adaptProductsListResponse(res);
  }

  Future<Response> lookupBarcode(String barcode) async {
    final res = await _get('/pos/barcode', query: {'code': barcode});
    if (res.statusCode == 200 && res.body is Map) {
      final data = (res.body as Map)['data'];
      if (data is Map && data['item'] is Map) {
        return Response(
          statusCode: 200,
          statusText: res.statusText,
          body: {
            'success': true,
            'ok': true,
            'code': 'OK',
            'data': data,
            'meta': {
              'product': data['item'],
              'kind': data['kind'],
              'currency': data['currency'],
            },
          },
        );
      }
    }
    return res;
  }

  Future<Response> quote(Map<String, dynamic> data) => _post('/pos/quote', data);

  Future<Response> recordSale(Map<String, dynamic> legacyOrMobilePayload) async {
    final key = _extractIdempotencyKey(legacyOrMobilePayload);
    final adapted = adaptSalePayload(legacyOrMobilePayload);
    final res = await _post('/pos/sales', adapted, idempotencyKey: key);
    return _adaptSaleResponse(res);
  }

  Future<Response> sales({int limit = 30}) async {
    final res = await _get('/pos/sales', query: {'limit': '$limit'});
    if (res.statusCode == 200 && res.body is Map) {
      final data = (res.body as Map)['data'];
      if (data is Map && data['invoices'] is List) {
        return Response(
          statusCode: 200,
          statusText: res.statusText,
          body: {
            'success': true,
            'ok': true,
            'code': 'OK',
            'data': data,
            'meta': {'sales': data['invoices'], 'currency': data['currency']},
          },
        );
      }
    }
    return res;
  }

  Future<Response> saleDetail(String saleIdOrUlid) => _get('/pos/sales/$saleIdOrUlid');

  // ── الورديات ────────────────────────────────────────────────────

  Future<Response> currentShift() => _get('/pos/shifts/current');

  Future<Response> openShift({
    required String openingFloat,
    int? cashBoxId,
    int? posDeviceId,
  }) =>
      _post('/pos/shifts/open', {
        'opening_float': openingFloat,
        if (cashBoxId != null) 'cash_box_id': cashBoxId,
        if (posDeviceId != null) 'pos_device_id': posDeviceId,
      });

  Future<Response> closeShift(int sessionId, {required String countedCash}) =>
      _post('/pos/shifts/$sessionId/close', {'counted_cash': countedCash});

  // ── السلات المعلّقة ──────────────────────────────────────────────

  Future<Response> heldTickets() => _get('/pos/held-carts');

  Future<Response> holdCart(Map<String, dynamic> data) => _post('/pos/held-carts', data);

  Future<Response> resumeHeld(String cartId) => _post('/pos/held-carts/$cartId/resume', const {});

  Future<Response> discardHeld(String cartId) => _delete('/pos/held-carts/$cartId');

  // ── المرتجعات ───────────────────────────────────────────────────

  Future<Response> createReturn(String invoiceId, Map<String, dynamic> body) {
    final key = _extractIdempotencyKey(body);
    return _post('/pos/sales/$invoiceId/returns', body, idempotencyKey: key);
  }

  // ── العملاء والتحصيل الآجل ──────────────────────────────────────

  Future<Response> listContacts({String? search}) => _get('/contacts', query: {
        if (search != null && search.isNotEmpty) 'q': search,
      });

  Future<Response> collectFromContact({
    required int contactId,
    required String amount,
    required String method,
    required String idempotencyKey,
    int? cashBoxId,
  }) =>
      _post(
        '/contacts/$contactId/collect',
        {
          'amount': amount,
          'method': method,
          'client_operation_id': idempotencyKey,
          if (cashBoxId != null) 'cash_box_id': cashBoxId,
        },
        idempotencyKey: idempotencyKey,
      );

  // ── محطات الوقود (Mobile Fuel API — sector:fuel) ─────────────────

  Future<Response> fuelStation() => _get('/fuel/station');

  Future<Response> fuelNozzles() => _get('/fuel/nozzles');

  Future<Response> currentFuelShift() => _get('/fuel/shifts/current');

  Future<Response> openFuelShift({
    required String openingFloat,
    required List<int> nozzleIds,
    int? cashBoxId,
    int? posDeviceId,
  }) =>
      _post('/fuel/shifts/open', {
        'opening_float': openingFloat,
        'nozzle_ids': nozzleIds,
        if (cashBoxId != null) 'cash_box_id': cashBoxId,
        if (posDeviceId != null) 'pos_device_id': posDeviceId,
      });

  Future<Response> closeFuelShift(
    int sessionId, {
    required String countedCash,
    required Map<int, String> closingMeters,
  }) =>
      _post('/fuel/shifts/$sessionId/close', {
        'counted_cash': countedCash,
        'closing_meters': closingMeters.map((k, v) => MapEntry('$k', v)),
      });

  Future<Response> recordFuelSale(Map<String, dynamic> payload) {
    final key = _extractIdempotencyKey(payload);
    return _post('/fuel/sales', payload, idempotencyKey: key);
  }

  Future<Response> fuelShiftReports({int limit = 20}) =>
      _get('/fuel/reports/shifts', query: {'limit': '$limit'});

  // ── محوّلات الحمولة (Payload/Response Adaptation) ─────────────────

  /// يحوّل حمولة الكاشير القديمة أو الحديثة إلى عقد `/api/v1/mobile/pos/sales`
  /// مع الحفاظ على السلاسل العشرية و`client_uuid` وتجاهل الحقول المحلية `_offline_*`.
  Map<String, dynamic> adaptSalePayload(Map<String, dynamic> raw) {
    final method = '${raw['method'] ?? raw['payment_method'] ?? 'cash'}';
    final tenderedRaw = raw['tendered'] ?? raw['cash_tendered'] ?? raw['paid_amount'];
    final clientKey = _extractIdempotencyKey(raw);
    final rawItems = (raw['items'] is List) ? raw['items'] as List : const [];

    final items = rawItems.map((entry) {
      final m = Map<String, dynamic>.from(entry as Map);
      final qty = m['quantity'] ?? m['qty'];
      return <String, dynamic>{
        'product_id': m['product_id'],
        if (qty != null) 'quantity': '$qty',
        if (m['amount'] != null) 'amount': '${m['amount']}',
        if (m['unit_price'] != null) 'unit_price': '${m['unit_price']}',
        if (m['unit_id'] != null) 'unit_id': m['unit_id'],
      };
    }).toList();

    return <String, dynamic>{
      'method': method,
      if (tenderedRaw != null && '$tenderedRaw'.isNotEmpty) 'tendered': '$tenderedRaw',
      if (raw['customer_id'] != null) 'customer_id': raw['customer_id'],
      if (raw['prescription_reference'] != null)
        'prescription_reference': raw['prescription_reference'],
      if (clientKey != null) 'client_operation_id': clientKey,
      if (clientKey != null) 'client_uuid': clientKey,
      'items': items,
    };
  }

  String? _extractIdempotencyKey(Map<String, dynamic> payload) {
    final raw = payload['client_operation_id'] ??
        payload['client_uuid'] ??
        payload['idempotency_key'];
    if (raw == null) return null;
    final trimmed = '$raw'.trim();
    return trimmed.isEmpty ? null : trimmed;
  }

  Response _adaptProductsListResponse(Response res) {
    if (res.statusCode == 200 && res.body is Map) {
      final data = (res.body as Map)['data'];
      if (data is Map && data['items'] is List) {
        return Response(
          statusCode: 200,
          statusText: res.statusText,
          body: {
            'success': true,
            'ok': true,
            'code': 'OK',
            'data': data,
            'meta': {'products': data['items'], 'currency': data['currency']},
          },
        );
      }
    }
    return res;
  }

  Response _adaptSaleResponse(Response res) {
    if ((res.statusCode == 200 || res.statusCode == 201) && res.body is Map) {
      final map = res.body as Map;
      final data = map['data'];
      if (data is Map && data['invoice'] is Map) {
        return Response(
          statusCode: res.statusCode,
          statusText: res.statusText,
          body: {
            'success': true,
            'ok': true,
            'code': map['code'] ?? 'OK',
            'replayed': map['replayed'] ?? false,
            'data': data,
            'meta': {
              'sale': data['invoice'],
              'invoice': data['invoice'],
              'currency': data['currency'],
              'replayed': map['replayed'] ?? false,
            },
          },
        );
      }
    }
    return res;
  }

  Future<Response> _get(String path, {Map<String, String>? query}) async {
    try {
      final r = await _http.get(_uri(path, query: query), headers: _headers()).timeout(_timeout);
      return _decode(r);
    } catch (e) {
      return Response(statusCode: 1, statusText: e.toString());
    }
  }

  Future<Response> _post(String path, Map<String, dynamic> body, {String? idempotencyKey}) async {
    try {
      final r = await _http
          .post(_uri(path), headers: _headers(idempotencyKey: idempotencyKey), body: jsonEncode(body))
          .timeout(_timeout);
      return _decode(r);
    } catch (e) {
      return Response(statusCode: 1, statusText: e.toString());
    }
  }

  Future<Response> _delete(String path) async {
    try {
      final r = await _http.delete(_uri(path), headers: _headers()).timeout(_timeout);
      return _decode(r);
    } catch (e) {
      return Response(statusCode: 1, statusText: e.toString());
    }
  }

  Response _decode(http.Response r) {
    dynamic decoded;
    try {
      decoded = r.body.isNotEmpty ? jsonDecode(r.body) : null;
    } catch (_) {
      decoded = {'ok': false, 'message': r.body};
    }
    return Response(
      statusCode: r.statusCode,
      statusText: r.reasonPhrase,
      body: decoded,
      bodyString: r.body,
    );
  }
}
