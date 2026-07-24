import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/data/api/api_client.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';
import 'package:amyal_pay/features/restaurant/screens/restaurant_order_screen.dart';
import 'package:amyal_pay/helper/amial_money.dart';

/// AMIAL-RESTAURANT-001 — لوحة المطعم: الطاولات + شاشة المطبخ.
class RestaurantScreen extends StatefulWidget {
  const RestaurantScreen({super.key});

  @override
  State<RestaurantScreen> createState() => _RestaurantScreenState();
}

class _RestaurantScreenState extends State<RestaurantScreen> with SingleTickerProviderStateMixin {
  final _api = Get.find<ApiClient>();
  late final TabController _tab;
  bool _loading = true;
  String? _error;
  List<Map<String, dynamic>> _tables = [];
  List<Map<String, dynamic>> _orders = [];
  List<Map<String, dynamic>> _kitchen = [];

  @override
  void initState() {
    super.initState();
    _tab = TabController(length: 2, vsync: this);
    _load();
  }

  @override
  void dispose() {
    _tab.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() { _loading = true; _error = null; });
    try {
      final rt = await _api.getData('/api/v1/amial/restaurant/tables');
      if (rt.statusCode == 402 || rt.statusCode == 403) {
        setState(() { _error = 'قطاع المطاعم متاح لحسابات المطاعم'; _loading = false; });
        return;
      }
      if (rt.statusCode == 200 && rt.body is Map) {
        _tables = (((rt.body['meta'] ?? {})['tables'] ?? []) as List).map((e) => Map<String, dynamic>.from(e as Map)).toList();
      }
      final ro = await _api.getData('/api/v1/amial/restaurant/orders');
      if (ro.statusCode == 200 && ro.body is Map) {
        _orders = (((ro.body['meta'] ?? {})['orders'] ?? []) as List).map((e) => Map<String, dynamic>.from(e as Map)).toList();
      }
      final rk = await _api.getData('/api/v1/amial/restaurant/kitchen');
      if (rk.statusCode == 200 && rk.body is Map) {
        _kitchen = (((rk.body['meta'] ?? {})['orders'] ?? []) as List).map((e) => Map<String, dynamic>.from(e as Map)).toList();
      }
    } catch (_) { _error = 'خطأ في الشبكة'; }
    finally { if (mounted) setState(() => _loading = false); }
  }

  void _snack(String m, {bool ok = false}) => ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(content: Text(m), backgroundColor: ok ? const Color(0xFF2E7D32) : AmyalColors.red));

  Map<String, dynamic>? _orderForTable(int tableId) {
    for (final o in _orders) { if (o['table_id'] == tableId) return o; }
    return null;
  }

  Future<void> _addTable() async {
    final label = TextEditingController();
    final seats = TextEditingController(text: '4');
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('طاولة جديدة'),
        content: Column(mainAxisSize: MainAxisSize.min, children: [
          TextField(controller: label, decoration: const InputDecoration(labelText: 'اسم/رقم الطاولة *', border: OutlineInputBorder())),
          const SizedBox(height: 10),
          TextField(controller: seats, keyboardType: TextInputType.number,
              decoration: const InputDecoration(labelText: 'عدد المقاعد', border: OutlineInputBorder())),
        ]),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('إلغاء')),
          FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('إضافة')),
        ],
      ),
    );
    if (ok != true) return;
    if (label.text.trim().isEmpty) { _snack('أدخل اسم الطاولة'); return; }
    final r = await _api.postData('/api/v1/amial/restaurant/tables',
        {'label': label.text.trim(), 'seats': int.tryParse(seats.text.trim()) ?? 4});
    if (r.statusCode == 201) { _snack('أُضيفت الطاولة', ok: true); _load(); }
    else { _snack((r.body is Map ? r.body['message']?.toString() : null) ?? 'تعذّر'); }
  }

  Future<void> _openOrder({int? tableId, String? label, Map<String, dynamic>? existing}) async {
    final changed = await Get.to<bool>(() => RestaurantOrderScreen(
          tableId: tableId, tableLabel: label, existingOrder: existing));
    if (changed == true || changed == null) _load();
  }

  Future<void> _advance(Map<String, dynamic> o) async {
    final next = o['status'] == 'open' ? 'preparing' : o['status'] == 'preparing' ? 'ready' : 'served';
    final r = await _api.postData('/api/v1/amial/restaurant/orders/${o['id']}/status', {'status': next});
    if (r.statusCode == 200) _load(); else _snack('تعذّر');
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmyalColors.background,
      appBar: AppBar(
        title: const Text('المطعم'),
        backgroundColor: AmyalColors.primary, foregroundColor: Colors.white,
        bottom: _error == null ? TabBar(controller: _tab, indicatorColor: Colors.white, tabs: const [
          Tab(text: 'الطاولات', icon: Icon(Icons.table_restaurant)),
          Tab(text: 'المطبخ', icon: Icon(Icons.soup_kitchen)),
        ]) : null,
        actions: [
          IconButton(onPressed: () => _openOrder(label: 'سفري'), icon: const Icon(Icons.takeout_dining), tooltip: 'طلب سفري'),
        ],
      ),
      floatingActionButton: _error == null && _tab.index == 0
          ? FloatingActionButton.extended(onPressed: _addTable, backgroundColor: AmyalColors.primary,
              icon: const Icon(Icons.add), label: const Text('طاولة'))
          : null,
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : _error != null
              ? Center(child: Padding(padding: const EdgeInsets.all(24),
                  child: Column(mainAxisSize: MainAxisSize.min, children: [
                    const Icon(Icons.restaurant, size: 56, color: AmyalColors.yellowDark),
                    const SizedBox(height: 12),
                    Text(_error!, textAlign: TextAlign.center, style: const TextStyle(fontSize: 15, fontWeight: FontWeight.w600)),
                  ])))
              : TabBarView(controller: _tab, children: [_tablesTab(), _kitchenTab()]),
    );
  }

  Widget _tablesTab() {
    return RefreshIndicator(
      onRefresh: _load,
      child: _tables.isEmpty
          ? ListView(children: const [SizedBox(height: 120), Center(child: Text('لا طاولات — أضِف طاولة'))])
          : GridView.count(
              crossAxisCount: 3, padding: const EdgeInsets.all(12),
              mainAxisSpacing: 10, crossAxisSpacing: 10, childAspectRatio: 0.95,
              children: _tables.map(_tableCard).toList(),
            ),
    );
  }

  Widget _tableCard(Map<String, dynamic> t) {
    final occupied = t['status'] == 'occupied';
    final order = _orderForTable(t['id'] as int);
    return InkWell(
      borderRadius: BorderRadius.circular(14),
      onTap: () => occupied
          ? _openOrder(tableId: t['id'] as int, label: '${t['label']}', existing: order)
          : _openOrder(tableId: t['id'] as int, label: '${t['label']}'),
      child: Container(
        decoration: BoxDecoration(
          color: occupied ? AmyalColors.red.withValues(alpha: 0.1) : const Color(0xFF2E7D32).withValues(alpha: 0.08),
          borderRadius: BorderRadius.circular(14),
          border: Border.all(color: occupied ? AmyalColors.red : const Color(0xFF2E7D32), width: 1.2),
        ),
        child: Column(mainAxisAlignment: MainAxisAlignment.center, children: [
          Icon(Icons.table_restaurant, size: 30, color: occupied ? AmyalColors.red : const Color(0xFF2E7D32)),
          const SizedBox(height: 6),
          Text('${t['label']}', style: const TextStyle(fontWeight: FontWeight.bold)),
          Text(occupied ? 'مشغولة' : 'متاحة',
              style: TextStyle(fontSize: 11, color: occupied ? AmyalColors.red : const Color(0xFF2E7D32))),
          if (occupied && order != null)
            Text('${AmialMoney.fmt(order['total'])} ر.ي', style: const TextStyle(fontSize: 11, fontWeight: FontWeight.bold)),
        ]),
      ),
    );
  }

  Widget _kitchenTab() {
    return RefreshIndicator(
      onRefresh: _load,
      child: _kitchen.isEmpty
          ? ListView(children: const [SizedBox(height: 120), Center(child: Text('لا طلبات في المطبخ'))])
          : ListView(padding: const EdgeInsets.all(12), children: _kitchen.map(_kitchenCard).toList()),
    );
  }

  Widget _kitchenCard(Map<String, dynamic> o) {
    final status = '${o['status']}';
    final color = status == 'ready' ? const Color(0xFF2E7D32) : status == 'preparing' ? AmyalColors.yellowDark : AmyalColors.primary;
    final items = (o['items'] ?? []) as List;
    return Container(
      margin: const EdgeInsets.only(bottom: 10),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(12),
          border: Border(right: BorderSide(color: color, width: 4))),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
            decoration: BoxDecoration(color: color.withValues(alpha: 0.12), borderRadius: BorderRadius.circular(8)),
            child: Text({'open': 'جديد', 'preparing': 'قيد التحضير', 'ready': 'جاهز'}[status] ?? status,
                style: TextStyle(color: color, fontWeight: FontWeight.bold, fontSize: 12)),
          ),
          Text('${o['order_no']}', style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 12)),
        ]),
        const SizedBox(height: 8),
        ...items.map((it) => Padding(
              padding: const EdgeInsets.symmetric(vertical: 2),
              child: Text('• ${it['qty'] ?? it['quantity']}× ${it['name']}${it['notes'] != null ? ' (${it['notes']})' : ''}',
                  style: const TextStyle(fontSize: 13)),
            )),
        if (o['notes'] != null && '${o['notes']}'.isNotEmpty)
          Padding(padding: const EdgeInsets.only(top: 4),
              child: Text('ملاحظة: ${o['notes']}', style: const TextStyle(fontSize: 11, color: AmyalColors.textSecondary))),
        const SizedBox(height: 8),
        if (status != 'ready')
          FilledButton.icon(
            onPressed: () => _advance(o),
            icon: const Icon(Icons.arrow_forward, size: 18),
            label: Text(status == 'open' ? 'بدء التحضير' : 'تعليم جاهز'),
            style: FilledButton.styleFrom(backgroundColor: color, minimumSize: const Size.fromHeight(42)),
          ),
      ]),
    );
  }
}
