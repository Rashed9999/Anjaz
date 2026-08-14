import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:mobile_scanner/mobile_scanner.dart';
import 'package:amial_pay/theme/amial_colors.dart';
import 'package:amial_pay/features/merchant/controllers/cashier_controller.dart';
import 'package:amial_pay/features/shared/widgets/scanner_shell.dart';

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
      setState(() => _show('✓ تمت الإضافة', Colors.green));
    } else if (result == 'not_found') {
      await _quickCreate(code);
    } else {
      setState(() => _show('تعذّر البحث', AmialColors.red));
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
          Text('الباركود: $barcode', style: const TextStyle(fontSize: 12, color: AmialColors.textSecondary)),
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
            style: FilledButton.styleFrom(backgroundColor: AmialColors.primary),
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
      if (mounted) setState(() => _show('تُخطّي الباركود', AmialColors.textMuted));
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
      setState(() => _show('✓ أُنشئ وأُضيف', Colors.green));
    } else {
      setState(() => _show(c.lastError.value, AmialColors.red));
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
      setState(() => _show('✓ أُضيف للسلّة', Colors.green));
    } else if (result == 'not_found') {
      setState(() => _show('لا يوجد منتج بهذا الباركود', AmialColors.red));
    } else {
      setState(() => _show(c.lastError.value.isEmpty ? 'تعذّر البحث' : c.lastError.value, AmialColors.red));
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
              border: Border.all(color: AmialColors.yellow, width: 3),
              borderRadius: BorderRadius.circular(12),
            ),
          ),
        ),
        // السلّة الحيّة أسفل الكاميرا
        Positioned(left: 0, right: 0, bottom: 0, child: SafeArea(child: _cartPanel())),
        ]),
      ),
    );
  }

  // ══════════════════════════════════════════════════════════════════
  //  AMIAL-CASHIER-BARCODE-002 — **الكاشير يرى ما مسحه وهو يمسح.**
  //
  //  كان أسفل الشاشة سطرٌ واحد: «أُضيف: ٧ صنف». **رقمٌ بلا أسماء ولا
  //  أسعارٍ ولا مجموع** — والكاشير يمسح اثني عشر صنفاً وهو أعمى:
  //
  //   · مسح صنفاً مرّتين بالخطأ ⇒ لا يعرف، والعدّاد يزيد فيطمئنّ.
  //   · مسح الصنف الخطأ ⇒ لا اسمَ يُراجعه، ولا حذفَ من هنا.
  //   · الزبون يسأل «كم المجموع؟» ⇒ لا جواب حتّى يخرج من الشاشة.
  //
  //  والعلاجُ كان مبنيّاً في المشروع أصلاً: `ContinuousScannerScreen`
  //  تعرض سلّةً حيّةً بأزرار الكمّيّة والحذف والمجموع منذ بُنيت —
  //  **وتستعملها الجملةُ والصيدليّة، والتجزئةُ لا.** وهي أكثرُ الأصناف
  //  عدداً. (القاعدة الرابعة: ميزةٌ لها مدخلان تُختبر من مدخليها —
  //  فأُصلح مدخلٌ وتُرك الآخر.)
  //
  //  ولا تُنسخ الشاشةُ الأخرى هنا: سلّةُ `CashierController` هي مصدرُ
  //  الحقيقة، وهي نفسُها التي تُقرأ في نقطة البيع. فنسخةٌ ثانيةٌ من
  //  السلّة تعني رقمين لا يلتقيان.
  // ══════════════════════════════════════════════════════════════════

  Widget _cartPanel() {
    return Obx(() {
      final lines = c.cart;

      return Container(
        decoration: const BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.vertical(top: Radius.circular(18)),
        ),
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          if (_feedback.isNotEmpty)
            Container(
              width: double.infinity,
              padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
              color: _feedbackColor.withValues(alpha: 0.12),
              child: Text(_feedback,
                  textAlign: TextAlign.center,
                  style: TextStyle(color: _feedbackColor, fontWeight: FontWeight.bold, fontSize: 13)),
            ),

          if (lines.isEmpty)
            const Padding(
              padding: EdgeInsets.all(16),
              child: Row(children: [
                Icon(Icons.qr_code_scanner, color: AmialColors.primary),
                SizedBox(width: 10),
                Expanded(child: Text('وجّه الكاميرا نحو الباركود',
                    style: TextStyle(fontSize: 14))),
              ]),
            )
          else
            // **مرتفعٌ محدود**: السلّةُ لا تأكل الكاميرا مهما طالت،
            // والأحدثُ أوّلاً — فآخرُ ما مُسح هو ما يُراجَع.
            Container(
              constraints: const BoxConstraints(maxHeight: 190),
              child: ListView.separated(
                shrinkWrap: true,
                padding: const EdgeInsets.symmetric(vertical: 4),
                itemCount: lines.length,
                separatorBuilder: (_, __) => const Divider(height: 1),
                itemBuilder: (_, i) => _cartLine(lines.length - 1 - i),
              ),
            ),

          const Divider(height: 1),
          Padding(
            padding: const EdgeInsets.all(12),
            child: Row(children: [
              Expanded(
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text('${lines.length} صنف · ${lines.fold<int>(0, (s, l) => s + l.qty)} قطعة',
                      style: TextStyle(color: Colors.grey.shade700, fontSize: 11.5)),
                  Text('${c.cartTotal.toStringAsFixed(0)} ر.ي',
                      style: const TextStyle(
                          fontSize: 22, fontWeight: FontWeight.bold, color: AmialColors.primary)),
                ]),
              ),
              FilledButton.icon(
                style: FilledButton.styleFrom(
                  backgroundColor: AmialColors.yellow,
                  foregroundColor: Colors.black,
                  padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 14),
                ),
                onPressed: () => Navigator.pop(context),
                icon: const Icon(Icons.point_of_sale),
                label: Text(lines.isEmpty ? 'رجوع' : 'إتمام البيع'),
              ),
            ]),
          ),
        ]),
      );
    });
  }

  Widget _cartLine(int i) {
    final l = c.cart[i];

    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
      child: Row(children: [
        IconButton(
          icon: const Icon(Icons.delete_outline, color: AmialColors.red, size: 19),
          padding: EdgeInsets.zero,
          constraints: const BoxConstraints(),
          tooltip: 'حذف',
          onPressed: () => c.removeLine(i),
        ),
        const SizedBox(width: 6),
        _step(Icons.remove, () => c.decLine(i)),
        Container(
          margin: const EdgeInsets.symmetric(horizontal: 6),
          padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 3),
          decoration: BoxDecoration(
            color: AmialColors.primary.withValues(alpha: 0.10),
            borderRadius: BorderRadius.circular(6),
          ),
          child: Text('${l.qty}',
              style: const TextStyle(fontWeight: FontWeight.bold, color: AmialColors.primary)),
        ),
        _step(Icons.add, () => c.incLine(i)),
        const SizedBox(width: 8),
        Expanded(
          child: Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
            Text(l.name,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13)),
            Text('${l.lineTotal.toStringAsFixed(0)} ر.ي',
                style: TextStyle(fontSize: 11.5, color: Colors.grey.shade700)),
          ]),
        ),
      ]),
    );
  }

  Widget _step(IconData icon, VoidCallback onTap) => InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(6),
        child: Container(
          width: 30,
          height: 30,
          decoration: BoxDecoration(
              color: Colors.grey.shade200, borderRadius: BorderRadius.circular(6)),
          child: Icon(icon, size: 16),
        ),
      );
}
