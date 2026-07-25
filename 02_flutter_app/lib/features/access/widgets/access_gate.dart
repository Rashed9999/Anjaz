import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';
import 'package:amyal_pay/features/access/controllers/access_controller.dart';

/// CRITICAL-001 — AccessGate widget.
///
/// يُظهر `child` فقط إن كان المستخدم يملك `feature`.
/// الاستخدام:
///   AccessGate(feature: 'inventory', child: _serviceCard(...))
///   AccessGate(feature: 'fuel_pos', child: ..., fallback: ...)
class AccessGate extends StatelessWidget {
  final String? feature;
  final List<String>? anyOf;
  final List<String>? allOf;
  final Widget child;
  final Widget? fallback;

  const AccessGate({
    super.key,
    this.feature,
    this.anyOf,
    this.allOf,
    required this.child,
    this.fallback,
  });

  @override
  Widget build(BuildContext context) {
    final access = Get.find<AccessController>();
    return Obx(() {
      bool allowed = true;
      if (feature != null) allowed = allowed && access.has(feature!);
      if (anyOf != null) allowed = allowed && access.hasAny(anyOf!);
      if (allOf != null) allowed = allowed && access.hasAll(allOf!);
      return allowed ? child : (fallback ?? const SizedBox.shrink());
    });
  }
}

/// شاشة اختيار نوع النشاط للتاجر.
/// تظهر تلقائياً للتجار الذين لم يختاروا نوع نشاطهم بعد.
class BusinessTypeSelectionScreen extends StatefulWidget {
  /// إن true، الشاشة إلزامية (لا يمكن الرجوع منها).
  final bool mandatory;

  const BusinessTypeSelectionScreen({super.key, this.mandatory = false});

  @override
  State<BusinessTypeSelectionScreen> createState() => _BusinessTypeSelectionScreenState();
}

class _BusinessTypeSelectionScreenState extends State<BusinessTypeSelectionScreen> {
  String? _selected;
  bool _submitting = false;

  static const _options = [
    _BizOption('quick_sale', 'بيع سريع', 'أسماك، خضار، بسطات', Icons.shopping_basket, Color(0xFF2196F3)),
    _BizOption('retail', 'تجزئة', 'بقالة، سوبرماركت', Icons.storefront, Color(0xFF4CAF50)),
    _BizOption('fuel', 'محطة وقود', 'بنزين، ديزل', Icons.local_gas_station, Color(0xFF1B5E20)),
    _BizOption('pharmacy', 'صيدلية', 'أدوية + Batches', Icons.local_pharmacy, Color(0xFF7B1FA2)),
    _BizOption('wholesale', 'جملة', 'فواتير + حسابات آجلة', Icons.warehouse, Color(0xFFE65100)),
    _BizOption('restaurant', 'مطعم', '(قريباً)', Icons.restaurant, Color(0xFF795548)),
  ];

  Future<void> _submit() async {
    if (_selected == null) return;
    setState(() => _submitting = true);
    final access = Get.find<AccessController>();
    final ok = await access.updateMyBusinessType(_selected!);
    if (!mounted) return;
    setState(() => _submitting = false);
    if (ok) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('تم حفظ نوع النشاط'), backgroundColor: Colors.green),
      );
      Get.back(result: true);
    } else {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(access.lastError.value), backgroundColor: AmyalColors.red),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    return WillPopScope(
      onWillPop: () async => !widget.mandatory,
      child: Scaffold(
        backgroundColor: AmyalColors.background,
        appBar: AppBar(
          title: const Text('نوع النشاط التجاري'),
          automaticallyImplyLeading: !widget.mandatory,
        ),
        body: SingleChildScrollView(
          padding: const EdgeInsets.all(16),
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            Container(
              padding: const EdgeInsets.all(14),
              decoration: BoxDecoration(
                color: AmyalColors.yellow.withValues(alpha: 0.2),
                borderRadius: BorderRadius.circular(12),
                border: Border.all(color: AmyalColors.yellow),
              ),
              child: const Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Row(children: [
                  Icon(Icons.info_outline, color: AmyalColors.yellowDark),
                  SizedBox(width: 8),
                  Text('اختر نوع نشاطك التجاري',
                      style: TextStyle(fontWeight: FontWeight.bold, fontSize: 15)),
                ]),
                SizedBox(height: 6),
                Text('سيُحدّد ذلك الميزات والشاشات التي ستراها. يمكنك تغييره لاحقاً.',
                    style: TextStyle(fontSize: 13)),
              ]),
            ),
            const SizedBox(height: 16),
            ..._options.map((o) => _optionTile(o)),
            const SizedBox(height: 20),
            FilledButton.icon(
              onPressed: _selected == null || _submitting ? null : _submit,
              icon: _submitting
                  ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                  : const Icon(Icons.check_circle),
              label: Text(_selected == null ? 'اختر نوعاً' : 'حفظ والمتابعة',
                  style: const TextStyle(fontSize: 16, fontWeight: FontWeight.bold)),
              style: FilledButton.styleFrom(
                backgroundColor: AmyalColors.primary,
                minimumSize: const Size.fromHeight(52),
              ),
            ),
          ]),
        ),
      ),
    );
  }

  Widget _optionTile(_BizOption o) {
    final selected = _selected == o.value;
    return InkWell(
      borderRadius: BorderRadius.circular(14),
      onTap: () => setState(() => _selected = o.value),
      child: Container(
        margin: const EdgeInsets.only(bottom: 10),
        padding: const EdgeInsets.all(14),
        decoration: BoxDecoration(
          color: selected ? o.color.withValues(alpha: 0.1) : Colors.white,
          borderRadius: BorderRadius.circular(14),
          border: Border.all(
            color: selected ? o.color : Colors.grey.shade300,
            width: selected ? 2 : 1,
          ),
        ),
        child: Row(children: [
          Container(
            width: 48, height: 48,
            decoration: BoxDecoration(color: o.color, borderRadius: BorderRadius.circular(12)),
            child: Icon(o.icon, color: Colors.white, size: 26),
          ),
          const SizedBox(width: 14),
          Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(o.label, style: const TextStyle(fontSize: 17, fontWeight: FontWeight.bold)),
            Text(o.description, style: TextStyle(color: Colors.grey.shade600, fontSize: 12)),
          ])),
          if (selected) Icon(Icons.check_circle, color: o.color),
        ]),
      ),
    );
  }
}

class _BizOption {
  final String value;
  final String label;
  final String description;
  final IconData icon;
  final Color color;
  const _BizOption(this.value, this.label, this.description, this.icon, this.color);
}
