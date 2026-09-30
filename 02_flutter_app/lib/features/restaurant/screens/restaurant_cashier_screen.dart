import 'package:flutter/material.dart';
import 'package:get/get.dart';

import 'package:amial_pay/data/api/api_client.dart';
import 'package:amial_pay/features/merchant/widgets/shift_gate.dart';
import 'package:amial_pay/features/restaurant/screens/restaurant_order_screen.dart';
import 'package:amial_pay/helper/amial_money.dart';
import 'package:amial_pay/theme/amial_colors.dart';

/// AMIAL-RESTAURANT-POS-001 — واجهة كاشير المطعم فقط.
///
/// النادل والمطبخ يديران الطلب، والكاشير يرى الطلبات المفتوحة ويحصّلها.
/// لا تظهر هنا إدارة الطاولات أو تعديل الأصناف أو شاشة المطبخ.
class RestaurantCashierScreen extends StatefulWidget {
  const RestaurantCashierScreen({super.key});

  @override
  State<RestaurantCashierScreen> createState() =>
      _RestaurantCashierScreenState();
}

class _RestaurantCashierScreenState extends State<RestaurantCashierScreen> {
  final ApiClient _api = Get.find<ApiClient>();
  bool _loading = true;
  String? _error;
  List<Map<String, dynamic>> _orders = const [];

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    if (mounted) {
      setState(() {
        _loading = true;
        _error = null;
      });
    }

    try {
      final response = await _api.getData('/api/v1/amial/restaurant/orders');

      if (response.statusCode == 200 && response.body is Map) {
        final body = Map<String, dynamic>.from(response.body as Map);
        final meta = body['meta'] is Map
            ? Map<String, dynamic>.from(body['meta'] as Map)
            : <String, dynamic>{};
        final raw = meta['orders'] is List ? meta['orders'] as List : const [];
        final orders = raw
            .whereType<Map>()
            .map((row) => Map<String, dynamic>.from(row))
            .where((row) => (row['status'] ?? '').toString() != 'closed')
            .toList(growable: false);

        if (mounted) setState(() => _orders = orders);
      } else {
        final message = response.body is Map
            ? response.body['message']?.toString()
            : null;
        if (mounted) {
          setState(() => _error = message != null && message.isNotEmpty
              ? message
              : 'تعذّر تحميل طلبات المطعم');
        }
      }
    } catch (_) {
      if (mounted) setState(() => _error = 'تعذّر الاتصال بالخادم');
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _open(Map<String, dynamic> order) async {
    final tableId = (order['table_id'] as num?)?.toInt();
    final changed = await Get.to<bool>(() => RestaurantOrderScreen(
          tableId: tableId,
          tableLabel: tableId == null ? 'سفري' : 'طاولة #' + tableId.toString(),
          existingOrder: order,
          checkoutOnly: true,
          nextSalePage: () => const RestaurantCashierScreen(),
        ));
    if (changed == true || changed == null) await _load();
  }

  @override
  Widget build(BuildContext context) {
    return ShiftGate(
      child: Scaffold(
        backgroundColor: AmialColors.background,
        appBar: AppBar(
          title: const Text('كاشير المطعم'),
          backgroundColor: AmialColors.primary,
          foregroundColor: Colors.white,
          actions: [
            IconButton(
              tooltip: 'تحديث الطلبات',
              onPressed: _loading ? null : _load,
              icon: const Icon(Icons.refresh_rounded),
            ),
          ],
        ),
        body: RefreshIndicator(onRefresh: _load, child: _body()),
      ),
    );
  }

  Widget _body() {
    if (_loading && _orders.isEmpty) {
      return ListView(
        physics: const AlwaysScrollableScrollPhysics(),
        children: const [
          SizedBox(height: 220),
          Center(child: CircularProgressIndicator(color: AmialColors.primary)),
        ],
      );
    }

    if (_error != null && _orders.isEmpty) {
      return ListView(
        physics: const AlwaysScrollableScrollPhysics(),
        padding: const EdgeInsets.all(24),
        children: [
          const SizedBox(height: 100),
          const Icon(Icons.cloud_off_rounded,
              size: 54, color: AmialColors.textMuted),
          const SizedBox(height: 12),
          Text(
            _error!,
            textAlign: TextAlign.center,
            style: const TextStyle(
              color: AmialColors.textSecondary,
              fontWeight: FontWeight.w700,
            ),
          ),
          const SizedBox(height: 16),
          FilledButton.icon(
            onPressed: _load,
            icon: const Icon(Icons.refresh_rounded),
            label: const Text('إعادة المحاولة'),
          ),
        ],
      );
    }

    if (_orders.isEmpty) {
      return ListView(
        physics: const AlwaysScrollableScrollPhysics(),
        padding: const EdgeInsets.all(24),
        children: const [
          SizedBox(height: 110),
          Icon(Icons.receipt_long_outlined,
              size: 58, color: AmialColors.textMuted),
          SizedBox(height: 12),
          Text(
            'لا توجد طلبات بانتظار التحصيل',
            textAlign: TextAlign.center,
            style: TextStyle(
              fontSize: 16,
              fontWeight: FontWeight.w800,
              color: AmialColors.textSecondary,
            ),
          ),
          SizedBox(height: 6),
          Text(
            'عندما يرسل النادل أو المطبخ طلباً سيظهر هنا ليقوم الكاشير بتحصيله وإصدار الفاتورة.',
            textAlign: TextAlign.center,
            style: TextStyle(color: AmialColors.textMuted, height: 1.6),
          ),
        ],
      );
    }

    return ListView.separated(
      physics: const AlwaysScrollableScrollPhysics(),
      padding: const EdgeInsets.fromLTRB(14, 14, 14, 30),
      itemCount: _orders.length,
      separatorBuilder: (_, __) => const SizedBox(height: 10),
      itemBuilder: (_, index) {
        final order = _orders[index];
        final tableId = (order['table_id'] as num?)?.toInt();
        final status = (order['status'] ?? 'open').toString();
        final items = order['items'] is List ? order['items'] as List : const [];
        final total = order['total'] ?? order['subtotal'] ?? 0;
        final orderNo = order['order_no']?.toString() ??
            '#' + (order['id']?.toString() ?? '');

        return Card(
          elevation: 0,
          color: Colors.white,
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(14),
            side: const BorderSide(color: AmialColors.border),
          ),
          child: InkWell(
            borderRadius: BorderRadius.circular(14),
            onTap: () => _open(order),
            child: Padding(
              padding: const EdgeInsets.all(14),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Row(
                    children: [
                      Container(
                        padding: const EdgeInsets.symmetric(
                            horizontal: 9, vertical: 5),
                        decoration: BoxDecoration(
                          color: AmialColors.primary.withValues(alpha: .09),
                          borderRadius: BorderRadius.circular(999),
                        ),
                        child: Text(
                          tableId == null
                              ? 'سفري'
                              : 'طاولة #' + tableId.toString(),
                          style: const TextStyle(
                            color: AmialColors.primary,
                            fontWeight: FontWeight.w800,
                            fontSize: 11,
                          ),
                        ),
                      ),
                      const Spacer(),
                      Text(
                        orderNo,
                        style: const TextStyle(
                          fontWeight: FontWeight.w900,
                          color: AmialColors.textSecondary,
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 12),
                  Row(
                    children: [
                      Expanded(
                        child: Text(
                          items.length.toString() +
                              ' صنف · ' +
                              _statusLabel(status),
                          style: const TextStyle(
                            color: AmialColors.textSecondary,
                            fontWeight: FontWeight.w700,
                          ),
                        ),
                      ),
                      Text(
                        AmialMoney.yer(total),
                        style: const TextStyle(
                          color: AmialColors.primary,
                          fontWeight: FontWeight.w900,
                          fontSize: 18,
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 10),
                  const Row(
                    children: [
                      Icon(Icons.point_of_sale_rounded,
                          size: 18, color: AmialColors.success),
                      SizedBox(width: 6),
                      Text(
                        'اضغط للتحصيل وإغلاق الطلب',
                        style: TextStyle(
                          color: AmialColors.success,
                          fontWeight: FontWeight.w800,
                          fontSize: 12,
                        ),
                      ),
                    ],
                  ),
                ],
              ),
            ),
          ),
        );
      },
    );
  }

  static String _statusLabel(String status) => const {
        'open': 'مفتوح',
        'preparing': 'قيد التحضير',
        'ready': 'جاهز',
        'served': 'مُقدَّم',
      }[status] ??
      status;
}
