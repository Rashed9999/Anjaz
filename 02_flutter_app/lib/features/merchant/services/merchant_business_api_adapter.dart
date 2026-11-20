import 'dart:convert';

import 'package:get/get.dart';
import 'package:http/http.dart' as http;
import 'package:amial_pay/features/merchant/services/merchant_business_backend_config.dart';

/// AMIAL-BUSINESS-UNIFY-U2 — محوّل عقد التطبيق المحمول إلى `Amial-Merchant` (`/api/v1/mobile/*`).
///
/// يعمل فقط عندما يكون `merchantBusinessBackendEnabled` مفعّلاً صراحةً للتاجر النشط
/// مع رمز Bearer خاص بأميال أعمال. لا يغيّر ترويسات `ApiClient` الخاصة بالمحفظة
/// أو العملاء، ويحافظ على تنسيق السلاسل العشرية و`Idempotency-Key` و`client_uuid`
/// وتوافق غلاف `{ success, meta }` الكامل مع `CashierController` و`CashierRefundController`
/// و`CustomerCreditController` (B3).
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

  Future<Response> saleDetail(String saleIdOrUlid) async {
    final res = await _get('/pos/sales/$saleIdOrUlid');
    if (res.statusCode == 200 && res.body is Map) {
      final data = (res.body as Map)['data'];
      if (data is Map && data['invoice'] is Map) {
        final inv = Map<String, dynamic>.from(data['invoice'] as Map);
        final refundable = inv['refundable'] is Map
            ? Map<String, dynamic>.from(inv['refundable'] as Map)
            : <String, dynamic>{};
        return Response(
          statusCode: 200,
          statusText: res.statusText,
          body: {
            'success': true,
            'ok': true,
            'code': 'OK',
            'data': data,
            'meta': {
              'sale': inv,
              'invoice': inv,
              'lines': inv['lines'] ?? const [],
              'currency': data['currency'],
              'refunded_so_far': refundable['refunded_so_far'] ?? inv['returned_total'] ?? '0',
              'remaining': refundable['remaining'] ?? inv['total'] ?? '0',
              'available_methods': refundable['available_methods'] ?? const [],
            },
          },
        );
      }
    }
    return res;
  }

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

  Future<Response> heldTickets() async {
    final res = await _get('/pos/held-carts');
    if (res.statusCode == 200 && res.body is Map) {
      final data = (res.body as Map)['data'];
      if (data is Map && data['held_carts'] is List) {
        final tickets = (data['held_carts'] as List).map((e) {
          final m = Map<String, dynamic>.from(e as Map);
          return _adaptHeldTicket(m);
        }).toList();
        return Response(
          statusCode: 200,
          statusText: res.statusText,
          body: {
            'success': true,
            'ok': true,
            'code': 'OK',
            'data': data,
            'meta': {'tickets': tickets, 'currency': data['currency']},
          },
        );
      }
    }
    return res;
  }

  Future<Response> holdCart(Map<String, dynamic> data) async {
    final rawItems = (data['items'] is List) ? data['items'] as List : const [];
    final items = rawItems.map((entry) {
      final m = Map<String, dynamic>.from(entry as Map);
      final qty = m['quantity'] ?? m['qty'] ?? '1';
      return <String, dynamic>{
        'product_id': m['product_id'],
        'quantity': '$qty',
        if (m['unit_price'] != null) 'unit_price': '${m['unit_price']}',
        if (m['unit_id'] != null) 'unit_id': m['unit_id'],
      };
    }).toList();

    final noteParts = <String>[
      if (data['label'] != null && '${data['label']}'.trim().isNotEmpty) '${data['label']}'.trim(),
      if (data['customer_name'] != null && '${data['customer_name']}'.trim().isNotEmpty) '${data['customer_name']}'.trim(),
      if (data['note'] != null && '${data['note']}'.trim().isNotEmpty) '${data['note']}'.trim(),
      if (data['notes'] != null && '${data['notes']}'.trim().isNotEmpty) '${data['notes']}'.trim(),
    ];

    final res = await _post('/pos/held-carts', {
      if (data['customer_id'] != null) 'customer_id': data['customer_id'],
      if (noteParts.isNotEmpty) 'note': noteParts.join(' · '),
      'items': items,
    });
    return _adaptSingleHeldResponse(res);
  }

  Future<Response> resumeHeld(String cartId) async {
    final res = await _post('/pos/held-carts/$cartId/resume', const {});
    return _adaptSingleHeldResponse(res);
  }

  Future<Response> discardHeld(String cartId) async {
    final res = await _delete('/pos/held-carts/$cartId');
    return _adaptSingleHeldResponse(res);
  }

  // ── المرتجعات ───────────────────────────────────────────────────

  Future<Response> createReturn(String invoiceIdOrUlid, Map<String, dynamic> body) async {
    final key = _extractIdempotencyKey(body);
    final adapted = adaptReturnPayload(body);
    final res = await _post('/pos/sales/$invoiceIdOrUlid/returns', adapted, idempotencyKey: key);
    if ((res.statusCode == 200 || res.statusCode == 201) && res.body is Map) {
      final map = res.body as Map;
      final data = map['data'];
      if (data is Map && data['return'] is Map) {
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
              'refund': data['return'],
              'return': data['return'],
              'currency': data['currency'],
              'replayed': map['replayed'] ?? false,
            },
          },
        );
      }
    }
    return res;
  }

  // ── العملاء والتحصيل الآجل ──────────────────────────────────────

  Future<Response> listContacts({String? search}) async {
    final res = await _get('/contacts', query: {
      if (search != null && search.isNotEmpty) 'q': search,
    });
    if (res.statusCode == 200 && res.body is Map) {
      final data = (res.body as Map)['data'];
      if (data is Map && data['contacts'] is List) {
        return Response(
          statusCode: 200,
          statusText: res.statusText,
          body: {
            'success': true,
            'ok': true,
            'code': 'OK',
            'data': data,
            'meta': {
              'customers': data['contacts'],
              'contacts': data['contacts'],
              'currency': data['currency'],
            },
          },
        );
      }
    }
    return res;
  }

  Future<Response> collectFromContact({
    required int contactId,
    required String amount,
    required String method,
    required String idempotencyKey,
    int? cashBoxId,
  }) async {
    final res = await _post(
      '/contacts/$contactId/collect',
      {
        'amount': amount,
        'method': method,
        'client_operation_id': idempotencyKey,
        if (cashBoxId != null) 'cash_box_id': cashBoxId,
      },
      idempotencyKey: idempotencyKey,
    );
    if ((res.statusCode == 200 || res.statusCode == 201) && res.body is Map) {
      final map = res.body as Map;
      final data = map['data'];
      if (data is Map) {
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
              'payment': data['payment'],
              'remaining_receivable': data['remaining_receivable'],
              'currency': data['currency'],
              'replayed': map['replayed'] ?? false,
            },
          },
        );
      }
    }
    return res;
  }

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

  /// يحوّل حمولة الكاشير القديمة (`CashierController`) أو الحديثة إلى عقد `/api/v1/mobile/pos/sales`:
  /// - `amial_pay` => `wallet`
  /// - `amount_received` / `cash_tendered` / `paid_amount` / `total` (للنقدي) => `tendered`
  /// - `items[].qty` => `items[].quantity` (سلسلة عشرية)
  Map<String, dynamic> adaptSalePayload(Map<String, dynamic> raw) {
    final rawMethod = '${raw['method'] ?? raw['payment_method'] ?? 'cash'}';
    final method = rawMethod == 'amial_pay' ? 'wallet' : rawMethod;
    final tenderedRaw = raw['tendered'] ??
        raw['amount_received'] ??
        raw['cash_tendered'] ??
        raw['paid_amount'] ??
        (method == 'cash' ? raw['total'] : null);
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

  /// يحوّل حمولة المرتجع من `CashierRefundController` (`items`) أو مباشرة (`quantities`) إلى عقد `/returns`.
  Map<String, dynamic> adaptReturnPayload(Map<String, dynamic> raw) {
    final quantities = <String, String>{};
    if (raw['quantities'] is Map) {
      (raw['quantities'] as Map).forEach((k, v) {
        if (v != null && '$v'.trim().isNotEmpty) {
          quantities['$k'] = '$v';
        }
      });
    } else if (raw['items'] is List) {
      for (final entry in (raw['items'] as List)) {
        if (entry is Map) {
          final lineId = entry['line_id'] ?? entry['sales_invoice_line_id'] ?? entry['id'];
          final q = entry['quantity'] ?? entry['qty'];
          if (lineId != null && q != null && '$q'.trim().isNotEmpty) {
            quantities['$lineId'] = '$q';
          }
        }
      }
    }
    final key = _extractIdempotencyKey(raw);
    return <String, dynamic>{
      'quantities': quantities,
      if (raw['reason'] != null && '${raw['reason']}'.trim().isNotEmpty) 'reason': '${raw['reason']}',
      if (key != null) 'client_operation_id': key,
    };
  }

  Map<String, dynamic> _adaptHeldTicket(Map<String, dynamic> cart) {
    final rawItems = (cart['items'] is List) ? cart['items'] as List : const [];
    final normalizedItems = rawItems.map((e) {
      final m = Map<String, dynamic>.from(e as Map);
      return <String, dynamic>{
        ...m,
        'qty': m['qty'] ?? m['quantity'] ?? 1,
        'price': m['price'] ?? m['unit_price'] ?? '0',
      };
    }).toList();
    return <String, dynamic>{
      ...cart,
      'ticket_ulid': '${cart['id'] ?? cart['reference'] ?? ''}',
      'items': normalizedItems,
    };
  }

  Response _adaptSingleHeldResponse(Response res) {
    if ((res.statusCode == 200 || res.statusCode == 201) && res.body is Map) {
      final data = (res.body as Map)['data'];
      if (data is Map && data['held_cart'] is Map) {
        final ticket = _adaptHeldTicket(Map<String, dynamic>.from(data['held_cart'] as Map));
        return Response(
          statusCode: res.statusCode,
          statusText: res.statusText,
          body: {
            'success': true,
            'ok': true,
            'code': 'OK',
            'data': data,
            'meta': {'ticket': ticket, 'currency': data['currency']},
          },
        );
      }
    }
    return res;
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
    final text = r.bodyBytes.isNotEmpty
        ? utf8.decode(r.bodyBytes, allowMalformed: true)
        : '';
    dynamic decoded;
    try {
      decoded = text.isNotEmpty ? jsonDecode(text) : null;
    } catch (_) {
      decoded = {'ok': false, 'message': text};
    }
    return Response(
      statusCode: r.statusCode,
      statusText: r.reasonPhrase,
      body: decoded,
      bodyString: text,
    );
  }
}
