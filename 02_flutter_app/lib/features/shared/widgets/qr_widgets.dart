import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:image_picker/image_picker.dart';
import 'package:mobile_scanner/mobile_scanner.dart';
import 'package:qr_flutter/qr_flutter.dart';
import 'package:amyal_pay/features/shared/widgets/scanner_shell.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';

/// AMIAL-QR-001 (v1.8)
///
/// QrDisplayWidget — يعرض QR code من نص.
/// يستخدم في: استلام دفعة التاجر، إعداد 2FA، مشاركة الإيصال.
class QrDisplayWidget extends StatelessWidget {
  final String data;
  final double size;
  final String? caption;

  const QrDisplayWidget({
    super.key,
    required this.data,
    this.size = 200,
    this.caption,
  });

  @override
  Widget build(BuildContext context) {
    return Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        Container(
          padding: const EdgeInsets.all(12),
          decoration: BoxDecoration(
            color: Colors.white,
            borderRadius: BorderRadius.circular(12),
            border: Border.all(color: AmyalColors.border),
          ),
          child: QrImageView(
            data: data,
            version: QrVersions.auto,
            size: size,
            backgroundColor: Colors.white,
            eyeStyle: const QrEyeStyle(
              eyeShape: QrEyeShape.square,
              color: AmyalColors.primary,
            ),
            dataModuleStyle: const QrDataModuleStyle(
              dataModuleShape: QrDataModuleShape.square,
              color: AmyalColors.primary,
            ),
          ),
        ),
        if (caption != null) ...[
          const SizedBox(height: 8),
          Text(caption!,
              style: const TextStyle(fontSize: 12, color: AmyalColors.textMuted)),
        ],
      ],
    );
  }
}

/// QrScannerScreen — يمسح QR ويعيد النتيجة.
///
/// استخدام:
///   final result = await Get.to(() => const QrScannerScreen());
///   if (result != null) { /* process scanned data */ }
class QrScannerScreen extends StatefulWidget {
  final String title;
  const QrScannerScreen({super.key, this.title = 'مسح رمز QR'});

  @override
  State<QrScannerScreen> createState() => _QrScannerScreenState();
}

class _QrScannerScreenState extends State<QrScannerScreen> {
  final MobileScannerController _controller = MobileScannerController(
    detectionSpeed: DetectionSpeed.normal,
    facing: CameraFacing.back,
  );
  bool _handled = false;

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  void _onDetect(BarcodeCapture capture) {
    if (_handled) return;
    final barcodes = capture.barcodes;
    if (barcodes.isEmpty) return;
    final value = barcodes.first.rawValue;
    if (value == null || value.isEmpty) return;

    _handled = true;
    Get.back(result: value);
  }

  /// AMIAL-QR-GALLERY: قراءة رمز QR من صورة في معرض الهاتف —
  /// كان الماسح يفتح الكاميرا فقط بينما الرمز قد يكون لقطة محفوظة.
  Future<void> _pickFromGallery() async {
    try {
      final picked =
          await ImagePicker().pickImage(source: ImageSource.gallery);
      if (picked == null || _handled) return;

      final capture = await _controller.analyzeImage(picked.path);
      final value = capture?.barcodes.isNotEmpty == true
          ? capture!.barcodes.first.rawValue
          : null;

      if (value != null && value.isNotEmpty) {
        _handled = true;
        Get.back(result: value);
      } else if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
          content: Text('لم يُعثر على رمز QR في هذه الصورة'),
          backgroundColor: AmyalColors.red,
        ));
      }
    } catch (_) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
          content: Text('تعذّرت قراءة الصورة — جرّب صورة أوضح'),
          backgroundColor: AmyalColors.red,
        ));
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: Colors.black,
      appBar: AppBar(
        title: Text(widget.title),
        actions: [
          IconButton(
            icon: const Icon(Icons.flash_on),
            onPressed: () => _controller.toggleTorch(),
          ),
          IconButton(
            icon: const Icon(Icons.cameraswitch),
            onPressed: () => _controller.switchCamera(),
          ),
        ],
      ),
      // AMIAL-SCANNER-SHELL-001 — النقصُ نفسُه كان هنا: `MobileScanner`
      // بلا `errorBuilder`. وهذه شاشةُ مسحِ رمزِ التاجر — فسوادُها
      // الصامتُ يقف بالعميل عند الصندوق بلا ما يدلّه.
      //
      // و«اختر رمزاً من المعرض» يصير هنا **طريقَ الخروج** حين تُرفض
      // الكاميرا: صورةٌ محفوظةٌ للرمز تُقرأ بلا كاميرا إطلاقاً.
      body: ScannerShell(
        controller: _controller,
        onDetect: _onDetect,
        onManualEntry: _pickFromGallery,
        manualEntryLabel: 'اختر رمزاً من معرض الصور',
        overlay: Stack(
        children: [
          // إطار توجيهي
          Center(
            child: Container(
              width: 250,
              height: 250,
              decoration: BoxDecoration(
                border: Border.all(color: AmyalColors.yellow, width: 3),
                borderRadius: BorderRadius.circular(16),
              ),
            ),
          ),
          Positioned(
            bottom: 110,
            left: 0,
            right: 0,
            child: Center(
              child: Container(
                padding:
                    const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
                decoration: BoxDecoration(
                  color: Colors.black54,
                  borderRadius: BorderRadius.circular(20),
                ),
                child: const Text(
                  'وجّه الكاميرا نحو رمز QR',
                  style: TextStyle(color: Colors.white, fontSize: 13),
                ),
              ),
            ),
          ),
          // AMIAL-QR-GALLERY: زر «من المعرض» — لصور الرموز المحفوظة
          Positioned(
            bottom: 36,
            left: 0,
            right: 0,
            child: Center(
              child: FilledButton.icon(
                onPressed: _pickFromGallery,
                icon: const Icon(Icons.photo_library_outlined, size: 20),
                label: const Text('اختر رمزاً من معرض الصور'),
                style: FilledButton.styleFrom(
                  backgroundColor: AmyalColors.yellow,
                  foregroundColor: const Color(0xFF053391),
                  padding: const EdgeInsets.symmetric(
                      horizontal: 20, vertical: 12),
                  shape: RoundedRectangleBorder(
                      borderRadius: BorderRadius.circular(24)),
                ),
              ),
            ),
          ),
        ],
        ),
      ),
    );
  }
}
