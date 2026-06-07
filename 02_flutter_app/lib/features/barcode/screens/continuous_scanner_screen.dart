import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:get/get.dart';
import 'package:mobile_scanner/mobile_scanner.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';
import 'package:amyal_pay/features/barcode/domain/repositories/barcode_repo.dart';

/// AMIAL-BARCODE-001 — شاشة المسح المستمر.
///
/// الفلسفة (للسوق اليمني):
///   - الكاميرا تبقى مفتوحة طوال الوقت.
///   - مسح ناجح → إضافة فورية للسلّة + اهتزاز + صوت تأكيد بصري.
///   - مسح فاشل → عرض "غير موجود" دون توقّف الكاميرا.
///   - debounce لمنع تكرار نفس الباركود خلال 1.5 ثانية.
///   - زر "إنهاء" يُرجع السلّة لـ caller.
///
/// نتيجة الإرجاع عبر Navigator.pop:
///   `List<ScannedItem>` — الأصناف الممسوحة بكمياتها.
class ContinuousScannerScreen extends StatefulWidget {
  /// 'wholesale' | 'pharmacy' | 'auto'
  final String context;

  /// إن true، يسمح بمسح نفس المنتج عدّة مرّات (يزيد الكمية).
  /// إن false، يرفض المسح المتكرّر.
  final bool allowDuplicates;

  const ContinuousScannerScreen({
    super.key,
    this.context = 'auto',
    this.allowDuplicates = true,
  });

  @override
  State<ContinuousScannerScreen> createState() => _ContinuousScannerScreenState();
}

class _ContinuousScannerScreenState extends State<ContinuousScannerScreen>
    with SingleTickerProviderStateMixin {
  late final MobileScannerController _scannerCtrl;
  late final BarcodeRepo _repo;
  late final AnimationController _flashAnim;

  // السلّة الحالية (تتراكم مع كل مسح ناجح)
  final List<ScannedItem> _cart = [];

  // debounce: لمنع تكرار مسح نفس الباركود
  final Map<String, DateTime> _lastScannedAt = {};
  static const _debounceDuration = Duration(milliseconds: 1500);

  // حالة الـ overlay (نجاح/فشل/تحميل)
  _ScanFeedback? _feedback;
  Timer? _feedbackTimer;
  bool _busy = false;

  // إعدادات
  bool _torchOn = false;

  @override
  void initState() {
    super.initState();
    _scannerCtrl = MobileScannerController(
      detectionSpeed: DetectionSpeed.normal,
      facing: CameraFacing.back,
      torchEnabled: false,
    );
    _repo = Get.find<BarcodeRepo>();
    _flashAnim = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 250),
    );
  }

  @override
  void dispose() {
    _feedbackTimer?.cancel();
    _scannerCtrl.dispose();
    _flashAnim.dispose();
    super.dispose();
  }

  /// يُستدعى كلّما اكتشف المسح كود جديد.
  Future<void> _onDetect(BarcodeCapture capture) async {
    if (_busy) return;
    if (capture.barcodes.isEmpty) return;

    final raw = capture.barcodes.first.rawValue;
    if (raw == null || raw.trim().isEmpty) return;

    final barcode = raw.trim();

    // Debounce: نفس الباركود خلال 1.5 ثانية → تجاهل
    final last = _lastScannedAt[barcode];
    if (last != null && DateTime.now().difference(last) < _debounceDuration) {
      return;
    }
    _lastScannedAt[barcode] = DateTime.now();

    // فحص duplicates
    if (!widget.allowDuplicates) {
      final existing = _cart.indexWhere((c) => c.barcode == barcode);
      if (existing >= 0) {
        _showFeedback(_ScanFeedback.duplicate('تم مسحه مسبقاً'));
        await HapticFeedback.lightImpact();
        return;
      }
    }

    setState(() => _busy = true);
    await HapticFeedback.lightImpact();

    try {
      final r = await _repo.lookup(barcode, context: widget.context);
      if (!mounted) return;

      if (r.statusCode == 200 && r.body is Map && r.body['success'] == true) {
        final product = Map<String, dynamic>.from(r.body['meta']['product'] as Map);
        _addToCart(product, barcode);
        _showFeedback(_ScanFeedback.success(product['name'] ?? product['trade_name'] ?? 'منتج'));
        await HapticFeedback.mediumImpact();
        _flashAnim.forward(from: 0);
      } else {
        _showFeedback(_ScanFeedback.notFound(barcode));
        await HapticFeedback.heavyImpact();
      }
    } catch (e) {
      _showFeedback(_ScanFeedback.error('خطأ في الشبكة'));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  void _addToCart(Map<String, dynamic> product, String barcode) {
    setState(() {
      final idx = _cart.indexWhere((c) => c.productId == product['id']);
      if (idx >= 0) {
        // زِد الكمية
        _cart[idx] = _cart[idx].copyWith(quantity: _cart[idx].quantity + 1);
      } else {
        _cart.add(ScannedItem(
          productId: product['id'] as int,
          barcode: barcode,
          name: (product['name'] ?? product['trade_name'] ?? '').toString(),
          unitPrice: _parseDouble(product['base_price'] ?? product['sale_price']),
          unit: (product['unit'] ?? 'قطعة').toString(),
          availableStock: _parseDouble(product['available_stock'] ?? product['current_stock']),
          quantity: 1,
        ));
      }
    });
  }

  double _parseDouble(dynamic v) {
    if (v == null) return 0;
    if (v is num) return v.toDouble();
    return double.tryParse(v.toString()) ?? 0;
  }

  void _showFeedback(_ScanFeedback fb) {
    _feedbackTimer?.cancel();
    setState(() => _feedback = fb);
    _feedbackTimer = Timer(const Duration(milliseconds: 1800), () {
      if (mounted) setState(() => _feedback = null);
    });
  }

  void _changeQuantity(int idx, int delta) {
    setState(() {
      final newQty = _cart[idx].quantity + delta;
      if (newQty <= 0) {
        _cart.removeAt(idx);
      } else {
        _cart[idx] = _cart[idx].copyWith(quantity: newQty);
      }
    });
  }

  void _removeItem(int idx) {
    setState(() => _cart.removeAt(idx));
  }

  void _finish() {
    Navigator.pop(context, _cart);
  }

  Future<void> _toggleTorch() async {
    await _scannerCtrl.toggleTorch();
    setState(() => _torchOn = !_torchOn);
  }

  Future<void> _switchCamera() async {
    await _scannerCtrl.switchCamera();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: Colors.black,
      body: Stack(children: [
        // ==== 1) Camera View ====
        MobileScanner(
          controller: _scannerCtrl,
          onDetect: _onDetect,
          errorBuilder: (ctx, err, child) => Center(
            child: Padding(
              padding: const EdgeInsets.all(24),
              child: Column(mainAxisAlignment: MainAxisAlignment.center, children: [
                const Icon(Icons.camera_alt_outlined, color: Colors.white, size: 64),
                const SizedBox(height: 16),
                Text('فشل تشغيل الكاميرا',
                    style: TextStyle(color: Colors.white, fontSize: 18, fontWeight: FontWeight.bold)),
                const SizedBox(height: 8),
                Text(err.errorDetails?.message ?? 'تحقّق من صلاحية الكاميرا',
                    style: const TextStyle(color: Colors.white70), textAlign: TextAlign.center),
              ]),
            ),
          ),
        ),

        // ==== 2) إطار المسح + Flash overlay عند النجاح ====
        AnimatedBuilder(
          animation: _flashAnim,
          builder: (_, child) => Positioned.fill(
            child: IgnorePointer(
              child: Container(
                color: Colors.green.withValues(alpha: _flashAnim.value * 0.3),
              ),
            ),
          ),
        ),
        Positioned.fill(child: IgnorePointer(child: _buildScanFrame())),

        // ==== 3) شريط علوي ====
        Positioned(top: 0, left: 0, right: 0, child: SafeArea(
          child: Container(
            padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 6),
            color: Colors.black54,
            child: Row(children: [
              IconButton(
                icon: const Icon(Icons.close, color: Colors.white),
                onPressed: () => Navigator.pop(context, _cart),
              ),
              const Spacer(),
              Text('${_cart.length} صنف • الإجمالي: ${_total.toStringAsFixed(0)} ر.ي',
                  style: const TextStyle(color: Colors.white, fontWeight: FontWeight.bold)),
              const Spacer(),
              IconButton(
                icon: Icon(_torchOn ? Icons.flash_on : Icons.flash_off, color: Colors.white),
                onPressed: _toggleTorch,
              ),
              IconButton(
                icon: const Icon(Icons.flip_camera_ios, color: Colors.white),
                onPressed: _switchCamera,
              ),
            ]),
          ),
        )),

        // ==== 4) Feedback overlay (نجاح/فشل) ====
        if (_feedback != null) Positioned(
          top: MediaQuery.of(context).size.height * 0.18,
          left: 16, right: 16,
          child: AnimatedOpacity(
            opacity: _feedback != null ? 1.0 : 0.0,
            duration: const Duration(milliseconds: 200),
            child: _buildFeedbackBanner(_feedback!),
          ),
        ),

        // ==== 5) السلّة في الأسفل ====
        Positioned(bottom: 0, left: 0, right: 0, child: SafeArea(
          child: _buildCartPanel(),
        )),
      ]),
    );
  }

  double get _total => _cart.fold(0.0, (s, c) => s + (c.unitPrice * c.quantity));

  Widget _buildScanFrame() {
    return Center(
      child: Container(
        width: 250, height: 180,
        decoration: BoxDecoration(
          border: Border.all(color: AmyalColors.yellow, width: 3),
          borderRadius: BorderRadius.circular(16),
        ),
      ),
    );
  }

  Widget _buildFeedbackBanner(_ScanFeedback fb) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
      decoration: BoxDecoration(
        color: fb.color,
        borderRadius: BorderRadius.circular(12),
        boxShadow: [BoxShadow(color: Colors.black.withValues(alpha: 0.3), blurRadius: 8)],
      ),
      child: Row(children: [
        Icon(fb.icon, color: Colors.white, size: 28),
        const SizedBox(width: 12),
        Expanded(child: Text(fb.message,
            style: const TextStyle(color: Colors.white, fontWeight: FontWeight.bold, fontSize: 15))),
      ]),
    );
  }

  Widget _buildCartPanel() {
    if (_cart.isEmpty) {
      return Container(
        padding: const EdgeInsets.all(14),
        decoration: const BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.vertical(top: Radius.circular(16)),
        ),
        child: const Row(children: [
          Icon(Icons.qr_code_scanner, color: AmyalColors.primary),
          SizedBox(width: 10),
          Expanded(child: Text('وجّه الكاميرا نحو الباركود', style: TextStyle(fontSize: 14))),
        ]),
      );
    }

    return Container(
      decoration: const BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.vertical(top: Radius.circular(16)),
      ),
      child: Column(mainAxisSize: MainAxisSize.min, children: [
        // قائمة منزلقة للأصناف (max 3 visible)
        Container(
          constraints: const BoxConstraints(maxHeight: 180),
          child: ListView.builder(
            shrinkWrap: true,
            padding: const EdgeInsets.symmetric(vertical: 6),
            itemCount: _cart.length,
            itemBuilder: (_, i) => _buildCartItem(i),
          ),
        ),
        const Divider(height: 1),
        // زر الإنهاء
        Padding(
          padding: const EdgeInsets.all(10),
          child: Row(children: [
            Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text('${_cart.length} صنف',
                  style: TextStyle(color: Colors.grey.shade700, fontSize: 12)),
              Text('${_total.toStringAsFixed(0)} ر.ي',
                  style: const TextStyle(fontSize: 22, fontWeight: FontWeight.bold, color: AmyalColors.primary)),
            ])),
            FilledButton.icon(
              onPressed: _finish,
              icon: const Icon(Icons.check),
              label: const Text('إنهاء المسح'),
              style: FilledButton.styleFrom(
                backgroundColor: AmyalColors.primary,
                padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 14),
              ),
            ),
          ]),
        ),
      ]),
    );
  }

  Widget _buildCartItem(int i) {
    final item = _cart[i];
    return Container(
      margin: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
      child: Row(children: [
        IconButton(
          icon: const Icon(Icons.delete_outline, color: Colors.red, size: 18),
          padding: EdgeInsets.zero, constraints: const BoxConstraints(),
          onPressed: () => _removeItem(i),
        ),
        const SizedBox(width: 4),
        // أزرار +/-
        InkWell(
          onTap: () => _changeQuantity(i, -1),
          child: Container(
            width: 28, height: 28,
            decoration: BoxDecoration(color: Colors.grey.shade200, borderRadius: BorderRadius.circular(6)),
            child: const Icon(Icons.remove, size: 16),
          ),
        ),
        Container(
          margin: const EdgeInsets.symmetric(horizontal: 6),
          padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
          decoration: BoxDecoration(
            color: AmyalColors.primary.withValues(alpha: 0.1),
            borderRadius: BorderRadius.circular(6),
          ),
          child: Text('${item.quantity}',
              style: const TextStyle(fontWeight: FontWeight.bold, color: AmyalColors.primary)),
        ),
        InkWell(
          onTap: () => _changeQuantity(i, 1),
          child: Container(
            width: 28, height: 28,
            decoration: BoxDecoration(color: Colors.grey.shade200, borderRadius: BorderRadius.circular(6)),
            child: const Icon(Icons.add, size: 16),
          ),
        ),
        const SizedBox(width: 8),
        Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
          Text(item.name, style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13),
              maxLines: 1, overflow: TextOverflow.ellipsis),
          Text('${(item.unitPrice * item.quantity).toStringAsFixed(0)} ر.ي',
              style: TextStyle(color: AmyalColors.primary, fontSize: 12, fontWeight: FontWeight.bold)),
        ])),
      ]),
    );
  }
}

// =========================================================================
// نموذج العنصر الممسوح (يُرجع لـ caller)
// =========================================================================
class ScannedItem {
  final int productId;
  final String barcode;
  final String name;
  final double unitPrice;
  final String unit;
  final double availableStock;
  final int quantity;

  const ScannedItem({
    required this.productId, required this.barcode, required this.name,
    required this.unitPrice, required this.unit,
    required this.availableStock, required this.quantity,
  });

  ScannedItem copyWith({int? quantity}) => ScannedItem(
    productId: productId, barcode: barcode, name: name,
    unitPrice: unitPrice, unit: unit, availableStock: availableStock,
    quantity: quantity ?? this.quantity,
  );

  Map<String, dynamic> toJson() => {
    'product_id': productId,
    'quantity': quantity,
    'name': name,
    'unit_price': unitPrice,
    'barcode': barcode,
  };
}

// =========================================================================
// نموذج الـ feedback (نجاح/فشل/duplicate)
// =========================================================================
class _ScanFeedback {
  final String message;
  final Color color;
  final IconData icon;
  const _ScanFeedback({required this.message, required this.color, required this.icon});

  factory _ScanFeedback.success(String name) => _ScanFeedback(
    message: '✓ $name',
    color: Colors.green.shade700,
    icon: Icons.check_circle,
  );
  factory _ScanFeedback.notFound(String barcode) => _ScanFeedback(
    message: 'باركود غير موجود: $barcode',
    color: AmyalColors.red,
    icon: Icons.error_outline,
  );
  factory _ScanFeedback.duplicate(String msg) => _ScanFeedback(
    message: msg,
    color: Colors.orange.shade700,
    icon: Icons.warning_amber,
  );
  factory _ScanFeedback.error(String msg) => _ScanFeedback(
    message: msg,
    color: Colors.grey.shade800,
    icon: Icons.wifi_off,
  );
}
