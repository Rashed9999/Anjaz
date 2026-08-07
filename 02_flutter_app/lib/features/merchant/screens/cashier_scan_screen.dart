import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:mobile_scanner/mobile_scanner.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';
import 'package:amyal_pay/features/merchant/controllers/cashier_controller.dart';
import 'package:amyal_pay/features/shared/widgets/scanner_shell.dart';

/// AMIAL-CASHIER-BARCODE-001 — مسح باركود المنتجات في الكاشير.
///
/// كل مسح ناجح → بحث المنتج بالباركود وإضافته للسلّة فوراً (مع خصم المخزون عند البيع).
/// الباركود المجهول → حوار سريع لإنشاء المنتج بالباركود مُعبّأ ثم إضافته للسلّة.
class CashierScanScreen extends StatefulWidget {
  const CashierScanScreen({super.key});

  @override
  State<CashierScanScreen> createState() => _CashierScanScreenState();
}

class _CashierScanScreenState extends State<CashierScanScreen> {
  final MobileScannerController _scanner = MobileScannerController(
    detectionSpeed: DetectionSpeed.normal,
    facing: CameraFacing.back,
  );
  late final CashierController c;

  // debounce: لمنع تكرار معالجة نفس الباركود
  final Map<String, DateTime> _lastSeen = {};
  static const _debounce = Duration(milliseconds: 1500);
  bool _busy = false;
  String _feedback = '';
  Color _feedbackColor = Colors.transparent;
  int _addedCount = 0;

  @override
  void initState() {
    super.initState();
    c = Get.find<CashierController>();
  }

  @override
  void dispose() {
    _scanner.dispose();
    super.dispose();
  }

  Future<void> _onDetect(BarcodeCapture capture) async {
    if (_busy || capture.barcodes.isEmpty) return;
    final code = capture.barcodes.first.rawValue;
    if (code == null || code.isEmpty) return;

    final now = DateTime.now();
    if (_lastSeen[code] != null && now.difference(_lastSeen[code]!) < _debounce) return;
    _lastSeen[code] = now;

    _busy = true;
    final result = await c.lookupAndAddByBarcode(code);
    if (!mounted) {
      _busy = false;
      return;
    }
    if (result == 'added') {
      setState(() {
        _addedCount++;
        _show('✓ تمت الإضافة', Colors.green);
      });
    } else if (result == 'not_found') {
      await _quickCreate(code);
    } else {
      setState(() => _show('تعذّر البحث', AmyalColors.red));
    }
    _busy = false;
  }

  void _show(String msg, Color color) {
    _feedback = msg;
    _feedbackColor = color;
  }

  Future<void> _quickCreate(String barcode) async {
    final name = TextEditingController();
    final price = TextEditingController();
    final qty = TextEditingController(text: '1');

    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('باركود جديد — أضِف المنتج'),
        content: Column(mainAxisSize: MainAxisSize.min, children: [
          Text('الباركود: $barcode', style: const TextStyle(fontSize: 12, color: AmyalColors.textSecondary)),
          const SizedBox(height: 8),
          TextField(controller: name, decoration: const InputDecoration(labelText: 'اسم المنتج *')),
          TextField(controller: price, keyboardType: const TextInputType.numberWithOptions(decimal: true),
              decoration: const InputDecoration(labelText: 'سعر البيع *')),
          TextField(controller: qty, keyboardType: const TextInputType.numberWithOptions(decimal: true),
              decoration: const InputDecoration(labelText: 'الكمية (المخزون)')),
        ]),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('تخطّي')),
          FilledButton(
            style: FilledButton.styleFrom(backgroundColor: AmyalColors.primary),
            onPressed: () {
              if (name.text.trim().isEmpty || double.tryParse(price.text.trim()) == null) return;
              Navigator.pop(ctx, true);
            },
            child: const Text('حفظ وإضافة'),
          ),
        ],
      ),
    );

    if (ok != true) {
      if (mounted) setState(() => _show('تُخطّي الباركود', AmyalColors.textMuted));
      return;
    }

    final product = await c.addProductReturning({
      'name': name.text.trim(),
      'price': price.text.trim(),
      'barcode': barcode,
      if (qty.text.trim().isNotEmpty) 'quantity': qty.text.trim(),
    });
    if (!mounted) return;
    if (product != null) {
      c.addProductToCart(product);
      setState(() {
        _addedCount++;
        _show('✓ أُنشئ وأُضيف', Colors.green);
      });
    } else {
      setState(() => _show(c.lastError.value, AmyalColors.red));
    }
  }

  /// إدخال باركود يدوياً — يعمل حتى لو تعذّرت الكاميرا (يضمن عمل الباركود دائماً).
  Future<void> _manualEntry() async {
    final ctrl = TextEditingController();
    final code = await showDialog<String>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('إدخال الباركود يدوياً'),
        content: TextField(
          controller: ctrl,
          autofocus: true,
          keyboardType: TextInputType.number,
          textDirection: TextDirection.ltr,
          decoration: const InputDecoration(labelText: 'رقم الباركود', border: OutlineInputBorder()),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('إلغاء')),
          FilledButton(onPressed: () => Navigator.pop(ctx, ctrl.text.trim()), child: const Text('بحث')),
        ],
      ),
    );
    if (code == null || code.isEmpty || !mounted) return;
    final result = await c.lookupAndAddByBarcode(code);
    if (!mounted) return;
    if (result == 'added') {
      setState(() { _addedCount++; _show('✓ أُضيف للسلّة', Colors.green); });
    } else if (result == 'not_found') {
      setState(() => _show('لا يوجد منتج بهذا الباركود', AmyalColors.red));
    } else {
      setState(() => _show(c.lastError.value.isEmpty ? 'تعذّر البحث' : c.lastError.value, AmyalColors.red));
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: Colors.black,
      appBar: AppBar(
        title: const Text('مسح الباركود'),
        actions: [
          IconButton(icon: const Icon(Icons.keyboard), tooltip: 'إدخال يدوي', onPressed: _manualEntry),
          IconButton(icon: const Icon(Icons.flash_on), onPressed: () => _scanner.toggleTorch()),
          IconButton(icon: const Icon(Icons.cameraswitch), onPressed: () => _scanner.switchCamera()),
        ],
      ),
      // AMIAL-SCANNER-SHELL-001 — الكاميرا تعمل أو تقول لماذا لا.
      // كان هنا `MobileScanner` عارياً بلا `errorBuilder`، فأيّ فشلٍ
      // يُرسم سواداً صامتاً: لا رسالة ولا رمز خطأ ولا طريقَ خروج.
      body: ScannerShell(
        controller: _scanner,
        onDetect: _onDetect,
        onManualEntry: _manualEntry,
        manualEntryLabel: 'أدخل الباركود يدويّاً',
        overlay: Stack(children: [
        // إطار التوجيه
        Center(
          child: Container(
            width: 260, height: 160,
            decoration: BoxDecoration(
              border: Border.all(color: AmyalColors.yellow, width: 3),
              borderRadius: BorderRadius.circular(12),
            ),
          ),
        ),
        // شريط الحالة السفلي
        Positioned(
          left: 0, right: 0, bottom: 0,
          child: Container(
            color: Colors.black.withValues(alpha: 0.7),
            padding: const EdgeInsets.all(16),
            child: Column(mainAxisSize: MainAxisSize.min, children: [
              if (_feedback.isNotEmpty)
                Text(_feedback, style: TextStyle(color: _feedbackColor, fontWeight: FontWeight.bold)),
              const SizedBox(height: 8),
              Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
                Text('أُضيف: $_addedCount صنف', style: const TextStyle(color: Colors.white)),
                FilledButton.icon(
                  style: FilledButton.styleFrom(backgroundColor: AmyalColors.yellow, foregroundColor: Colors.black),
                  onPressed: () => Navigator.pop(context),
                  icon: const Icon(Icons.check),
                  label: const Text('تم — للسلّة'),
                ),
              ]),
            ]),
          ),
        ),
        ]),
      ),
    );
  }
}
