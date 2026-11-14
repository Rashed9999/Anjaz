import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:amial_pay/features/merchant/controllers/cashier_controller.dart';
import 'package:amial_pay/features/merchant/screens/product_editor_screen.dart';
import 'package:amial_pay/theme/amial_colors.dart';

/// AMIAL-STOCK-ALERTS-001 — «تنبيهات المخزون» (التصاميم 43/44):
/// قائمة تنبيهات (نفاد/انخفاض) مع «إعادة التعبئة»، وضبط الحد الأدنى
/// لكل تصنيف (يُحفظ على الجهاز).
class StockAlertsScreen extends StatefulWidget {
  const StockAlertsScreen({super.key});

  @override
  State<StockAlertsScreen> createState() => _StockAlertsScreenState();
}

class _StockAlertsScreenState extends State<StockAlertsScreen> {
  CashierController get c => Get.find<CashierController>();

  static const _kThresholds = 'amial_stock_thresholds';
  static const _kEnabled = 'amial_stock_alerts_enabled';
  static const _defaultThreshold = 5;

  bool _enabled = true;
  Map<String, int> _thresholds = {};

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) async {
      await c.loadProducts();
      await _loadPrefs();
    });
  }

  Future<void> _loadPrefs() async {
    try {
      final sp = await SharedPreferences.getInstance();
      _enabled = sp.getBool(_kEnabled) ?? true;
      final raw = sp.getString(_kThresholds);
      if (raw != null && raw.isNotEmpty) {
        _thresholds = Map<String, int>.from(
            (jsonDecode(raw) as Map).map((k, v) => MapEntry('$k', v as int)));
      }
    } catch (_) {}
    if (mounted) setState(() {});
  }

  Future<void> _savePrefs() async {
    try {
      final sp = await SharedPreferences.getInstance();
      await sp.setBool(_kEnabled, _enabled);
      await sp.setString(_kThresholds, jsonEncode(_thresholds));
    } catch (_) {}
  }

  int _thresholdFor(String category) =>
      _thresholds[category] ?? _defaultThreshold;

  List<String> get _categories {
    final set = <String>{};
    for (final p in c.products) {
      final cat = '${p['category'] ?? ''}'.trim();
      if (cat.isNotEmpty) set.add(cat);
    }
    return set.toList();
  }

  double _qty(Map<String, dynamic> p) =>
      double.tryParse('${p['quantity'] ?? 0}') ?? 0;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(
        title: const Text('تنبيهات المخزون'),
      ),
      body: Obx(() {
        final active = c.products
            .where((p) => p['is_active'] != false && p['is_active'] != 0)
            .toList();
        final out = active.where((p) => _qty(p) <= 0).toList();
        final low = active
            .where((p) =>
                _qty(p) > 0 &&
                _qty(p) < _thresholdFor('${p['category'] ?? ''}'))
            .toList();

        return RefreshIndicator(
          onRefresh: () => c.loadProducts(),
          color: AmialColors.primary,
          child: ListView(
            padding: const EdgeInsets.all(16),
            children: [
              // ====== تفعيل التنبيهات ======
              Container(
                padding:
                    const EdgeInsets.symmetric(horizontal: 16, vertical: 6),
                decoration: BoxDecoration(
                  color: Colors.white,
                  borderRadius: BorderRadius.circular(16),
                ),
                child: SwitchListTile(
                  value: _enabled,
                  activeThumbColor: AmialColors.primary,
                  contentPadding: EdgeInsets.zero,
                  title: const Text('تفعيل التنبيهات التلقائية',
                      textAlign: TextAlign.right,
                      style: TextStyle(
                          fontWeight: FontWeight.bold, fontSize: 14)),
                  subtitle: const Text('سيتم إشعارك فور انخفاض المخزون',
                      textAlign: TextAlign.right,
                      style: TextStyle(fontSize: 11)),
                  onChanged: (v) {
                    setState(() => _enabled = v);
                    _savePrefs();
                  },
                ),
              ),
              const SizedBox(height: 18),

              if (_enabled) ...[
                // ====== التنبيهات النشطة ======
                if (out.isNotEmpty || low.isNotEmpty) ...[
                  Row(
                    mainAxisAlignment: MainAxisAlignment.end,
                    children: [
                      const Text('التنبيهات النشطة',
                          style: TextStyle(
                              fontWeight: FontWeight.bold, fontSize: 15)),
                      const SizedBox(width: 8),
                      Container(
                        padding: const EdgeInsets.symmetric(
                            horizontal: 8, vertical: 2),
                        decoration: BoxDecoration(
                          color: AmialColors.dangerSurface,
                          borderRadius: BorderRadius.circular(10),
                        ),
                        child: Text('${out.length + low.length}',
                            style: const TextStyle(
                                fontSize: 11,
                                fontWeight: FontWeight.bold,
                                color: AmialColors.red)),
                      ),
                    ],
                  ),
                  const SizedBox(height: 10),
                  ...out.map((p) => _alertCard(
                        p,
                        title: 'نفاد المخزون',
                        message: '${p['name']}: المخزون فارغ تماماً.',
                        color: AmialColors.red,
                        bg: AmialColors.dangerSurface,
                        icon: Icons.remove_shopping_cart_outlined,
                      )),
                  ...low.map((p) => _alertCard(
                        p,
                        title: 'تنبيه انخفاض المخزون',
                        message:
                            '${p['name']}: متبقي أقل من ${_thresholdFor('${p['category'] ?? ''}')} وحدات في المتجر.',
                        color: const Color(0xFFB8860B),
                        bg: const Color(0xFFFBF3D9),
                        icon: Icons.warning_amber_rounded,
                      )),
                ] else
                  Container(
                    padding: const EdgeInsets.all(28),
                    decoration: BoxDecoration(
                      color: Colors.white,
                      borderRadius: BorderRadius.circular(16),
                    ),
                    child: const Column(children: [
                      Icon(Icons.check_circle_outline,
                          size: 48, color: AmialColors.success),
                      SizedBox(height: 10),
                      Text('كل المنتجات فوق الحد الأدنى — لا تنبيهات',
                          style: TextStyle(color: AmialColors.textSecondary)),
                    ]),
                  ),
                const SizedBox(height: 22),

                // ====== تحديد الحد الأدنى ======
                const Align(
                  alignment: Alignment.centerRight,
                  child: Text('تحديد الحد الأدنى لكل تصنيف',
                      style: TextStyle(
                          fontWeight: FontWeight.bold, fontSize: 15)),
                ),
                const SizedBox(height: 10),
                ..._categories.map((cat) => _thresholdRow(cat)),
                const SizedBox(height: 14),
                const Text(
                  'نقوم بمراقبة مستويات مخزونك بدقة تامة لتجنّب نفاد الكميات وضمان استمرارية مبيعاتك بكل سهولة.',
                  textAlign: TextAlign.center,
                  style:
                      TextStyle(fontSize: 11, color: AmialColors.textMuted),
                ),
              ],
            ],
          ),
        );
      }),
    );
  }

  Widget _alertCard(
    Map<String, dynamic> p, {
    required String title,
    required String message,
    required Color color,
    required Color bg,
    required IconData icon,
  }) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: Container(
        padding: const EdgeInsets.all(14),
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(16),
          border: Border.all(color: color.withValues(alpha: 0.35)),
        ),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Row(children: [
            Container(
              height: 40,
              width: 40,
              decoration: BoxDecoration(
                color: bg,
                borderRadius: BorderRadius.circular(12),
              ),
              child: Icon(icon, color: color, size: 20),
            ),
            const Spacer(),
            Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
              Text(title,
                  style: TextStyle(
                      fontWeight: FontWeight.bold,
                      fontSize: 14,
                      color: color)),
              SizedBox(
                width: 230,
                child: Text(message,
                    textAlign: TextAlign.right,
                    style: const TextStyle(
                        fontSize: 12, color: AmialColors.textSecondary)),
              ),
            ]),
          ]),
          const SizedBox(height: 10),
          OutlinedButton.icon(
            onPressed: () async {
              final saved =
                  await Get.to<bool>(() => ProductEditorScreen(product: p));
              if (saved == true) c.loadProducts();
            },
            icon: const Icon(Icons.refresh, size: 16),
            label: const Text('إعادة التعبئة'),
            style: OutlinedButton.styleFrom(
              foregroundColor: color,
              side: BorderSide(color: color.withValues(alpha: 0.5)),
              minimumSize: const Size.fromHeight(42),
            ),
          ),
        ]),
      ),
    );
  }

  Widget _thresholdRow(String category) {
    final value = _thresholdFor(category);
    return Padding(
      padding: const EdgeInsets.only(bottom: 8),
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(14),
        ),
        child: Row(children: [
          // عدّاد الحد
          Container(
            decoration: BoxDecoration(
              color: const Color(0xFFF6F7F8),
              borderRadius: BorderRadius.circular(10),
            ),
            child: Row(children: [
              IconButton(
                visualDensity: VisualDensity.compact,
                icon: const Icon(Icons.add, size: 16),
                onPressed: () {
                  setState(() => _thresholds[category] = value + 1);
                  _savePrefs();
                },
              ),
              SizedBox(
                width: 32,
                child: Text('$value',
                    textAlign: TextAlign.center,
                    style: const TextStyle(fontWeight: FontWeight.bold)),
              ),
              IconButton(
                visualDensity: VisualDensity.compact,
                icon: const Icon(Icons.remove, size: 16),
                onPressed: value <= 1
                    ? null
                    : () {
                        setState(() => _thresholds[category] = value - 1);
                        _savePrefs();
                      },
              ),
            ]),
          ),
          const SizedBox(width: 8),
          const Text('وحدة',
              style: TextStyle(fontSize: 11, color: AmialColors.textMuted)),
          const Spacer(),
          Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
            Text(category,
                style: const TextStyle(
                    fontWeight: FontWeight.bold, fontSize: 13)),
            const Text('تنبيه عند الانخفاض عن',
                style:
                    TextStyle(fontSize: 10, color: AmialColors.textMuted)),
          ]),
        ]),
      ),
    );
  }
}
