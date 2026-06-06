import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';
import 'package:amyal_pay/features/fuel_station/controllers/fuel_station_controller.dart';
import 'package:amyal_pay/features/fuel_station/screens/fuel_sale_screen.dart';
import 'package:amyal_pay/features/fuel_station/screens/fuel_settings_screen.dart';
import 'package:amyal_pay/features/fuel_station/screens/fuel_companies_screen.dart';
import 'package:amyal_pay/features/fuel_station/screens/fuel_shifts_screen.dart';
import 'package:amyal_pay/features/fuel_station/screens/fuel_sales_history_screen.dart';

/// AMIAL-FUEL-001 — لوحة محطة الوقود.
/// نقطة الدخول الرئيسية: تعرض إحصائيات اليوم + 4 أزرار رئيسية.
class FuelStationDashboardScreen extends StatefulWidget {
  const FuelStationDashboardScreen({super.key});

  @override
  State<FuelStationDashboardScreen> createState() => _FuelStationDashboardScreenState();
}

class _FuelStationDashboardScreenState extends State<FuelStationDashboardScreen> {
  late final FuelStationController c;

  @override
  void initState() {
    super.initState();
    c = Get.find<FuelStationController>();
    WidgetsBinding.instance.addPostFrameCallback((_) async {
      await c.loadStation();
      await c.loadDashboard();
    });
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmyalColors.background,
      appBar: AppBar(
        title: const Text('محطة الوقود'),
        backgroundColor: AmyalColors.primary,
        foregroundColor: Colors.white,
        actions: [
          IconButton(
            icon: const Icon(Icons.settings),
            onPressed: () => Get.to(() => const FuelSettingsScreen()),
            tooltip: 'الإعدادات',
          ),
        ],
      ),
      body: RefreshIndicator(
        onRefresh: () async {
          await c.loadStation();
          await c.loadDashboard();
        },
        child: SingleChildScrollView(
          physics: const AlwaysScrollableScrollPhysics(),
          padding: const EdgeInsets.all(16),
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            // ====== بطاقة المحطة ======
            Obx(() {
              final station = c.station.value;
              return Container(
                padding: const EdgeInsets.all(18),
                decoration: BoxDecoration(
                  gradient: const LinearGradient(
                    colors: [AmyalColors.primary, Color(0xFF021A55)],
                    begin: Alignment.topLeft, end: Alignment.bottomRight,
                  ),
                  borderRadius: BorderRadius.circular(16),
                ),
                child: Row(children: [
                  Container(
                    width: 56, height: 56,
                    decoration: BoxDecoration(
                      color: AmyalColors.yellow,
                      borderRadius: BorderRadius.circular(14),
                    ),
                    child: const Icon(Icons.local_gas_station, color: Colors.black87, size: 32),
                  ),
                  const SizedBox(width: 14),
                  Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Text(station?['station_name'] ?? 'محطّتي',
                        style: const TextStyle(color: Colors.white, fontSize: 18, fontWeight: FontWeight.bold)),
                    if (station?['city'] != null)
                      Text('${station!['city']}',
                          style: const TextStyle(color: Colors.white70, fontSize: 13)),
                  ])),
                ]),
              );
            }),

            const SizedBox(height: 16),

            // ====== إحصائيات اليوم ======
            Obx(() {
              final d = c.dashboardData.value?['today'] as Map?;
              return Container(
                padding: const EdgeInsets.all(16),
                decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(14)),
                child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                  Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
                    const Icon(Icons.today, color: AmyalColors.primary, size: 18),
                    const Text('إحصائيات اليوم',
                        style: TextStyle(fontWeight: FontWeight.bold, fontSize: 14)),
                  ]),
                  const SizedBox(height: 12),
                  Row(children: [
                    Expanded(child: _statBox(
                      'الإجمالي', '${_fmt(d?['total_amount'])} ر.ي',
                      AmyalColors.primary, Icons.attach_money,
                    )),
                    const SizedBox(width: 8),
                    Expanded(child: _statBox(
                      'اللترات', '${_fmt(d?['total_liters'])}',
                      AmyalColors.yellowDark, Icons.local_gas_station,
                    )),
                    const SizedBox(width: 8),
                    Expanded(child: _statBox(
                      'عمليات', '${d?['sales_count'] ?? 0}',
                      Colors.green.shade700, Icons.receipt_long,
                    )),
                  ]),
                  if (d?['by_payment'] != null && (d!['by_payment'] as List).isNotEmpty) ...[
                    const SizedBox(height: 12),
                    const Divider(),
                    const SizedBox(height: 8),
                    const Text('حسب طريقة الدفع', textAlign: TextAlign.right,
                        style: TextStyle(fontWeight: FontWeight.bold, fontSize: 13)),
                    const SizedBox(height: 6),
                    ...((d['by_payment'] as List).map((row) {
                      final m = row as Map;
                      final method = m['payment_method'] ?? '';
                      final label = method == 'cash' ? 'نقدي' :
                                    method == 'amial_pay' ? 'أميال باي' : 'حسابات الشركات';
                      return Padding(
                        padding: const EdgeInsets.symmetric(vertical: 3),
                        child: Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
                          Text('${_fmt(m['total'])} ر.ي (${m['c']})',
                              style: const TextStyle(fontWeight: FontWeight.bold)),
                          Text(label, style: TextStyle(color: Colors.grey.shade700)),
                        ]),
                      );
                    })),
                  ],
                ]),
              );
            }),

            const SizedBox(height: 20),

            // ====== الأزرار الكبيرة ======
            _bigAction(
              icon: Icons.point_of_sale,
              label: 'بيع جديد',
              subtitle: 'تسجيل عملية بيع وقود',
              color: AmyalColors.primary,
              onTap: () => Get.to(() => const FuelSaleScreen()),
            ),
            const SizedBox(height: 10),
            // 4 اختصارات في صفّين
            Row(children: [
              Expanded(child: _miniAction(
                Icons.event_note, 'النوبات', () => Get.to(() => const FuelShiftsScreen()),
              )),
              const SizedBox(width: 8),
              Expanded(child: _miniAction(
                Icons.business, 'الشركات', () => Get.to(() => const FuelCompaniesScreen()),
              )),
            ]),
            const SizedBox(height: 8),
            Row(children: [
              Expanded(child: _miniAction(
                Icons.history, 'سجل المبيعات', () => Get.to(() => const FuelSalesHistoryScreen()),
              )),
              const SizedBox(width: 8),
              Expanded(child: _miniAction(
                Icons.tune, 'الإعدادات', () => Get.to(() => const FuelSettingsScreen()),
              )),
            ]),
          ]),
        ),
      ),
    );
  }

  String _fmt(dynamic value) {
    if (value == null) return '0';
    final n = double.tryParse(value.toString()) ?? 0;
    return n.toStringAsFixed(n == n.roundToDouble() ? 0 : 2);
  }

  Widget _statBox(String label, String value, Color color, IconData icon) {
    return Container(
      padding: const EdgeInsets.all(10),
      decoration: BoxDecoration(
        color: color.withOpacity(0.1),
        borderRadius: BorderRadius.circular(10),
      ),
      child: Column(children: [
        Icon(icon, color: color, size: 20),
        const SizedBox(height: 4),
        Text(value, style: TextStyle(color: color, fontWeight: FontWeight.bold, fontSize: 14),
            textAlign: TextAlign.center, overflow: TextOverflow.ellipsis),
        const SizedBox(height: 2),
        Text(label, style: TextStyle(color: Colors.grey.shade700, fontSize: 10)),
      ]),
    );
  }

  Widget _bigAction({
    required IconData icon, required String label, required String subtitle,
    required Color color, required VoidCallback onTap,
  }) {
    return InkWell(
      borderRadius: BorderRadius.circular(14),
      onTap: onTap,
      child: Container(
        padding: const EdgeInsets.all(16),
        decoration: BoxDecoration(color: color, borderRadius: BorderRadius.circular(14)),
        child: Row(children: [
          Container(
            width: 48, height: 48,
            decoration: BoxDecoration(
              color: Colors.white.withOpacity(0.2),
              borderRadius: BorderRadius.circular(12),
            ),
            child: Icon(icon, color: Colors.white, size: 28),
          ),
          const SizedBox(width: 12),
          Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(label, style: const TextStyle(color: Colors.white, fontSize: 17, fontWeight: FontWeight.bold)),
            Text(subtitle, style: const TextStyle(color: Colors.white70, fontSize: 12)),
          ])),
          const Icon(Icons.chevron_left, color: Colors.white),
        ]),
      ),
    );
  }

  Widget _miniAction(IconData icon, String label, VoidCallback onTap) {
    return InkWell(
      borderRadius: BorderRadius.circular(12),
      onTap: onTap,
      child: Container(
        padding: const EdgeInsets.symmetric(vertical: 16),
        decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(12)),
        child: Column(children: [
          Icon(icon, color: AmyalColors.primary, size: 24),
          const SizedBox(height: 6),
          Text(label, style: const TextStyle(fontWeight: FontWeight.bold)),
        ]),
      ),
    );
  }
}
