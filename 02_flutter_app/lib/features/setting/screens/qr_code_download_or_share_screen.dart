import 'dart:io';
import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:flutter_svg/flutter_svg.dart';
import 'package:get/get.dart';
import 'package:open_file/open_file.dart';
import 'package:path_provider/path_provider.dart';
import 'package:screenshot/screenshot.dart';
import 'package:share_plus/share_plus.dart';
import 'package:amyal_pay/data/api/api_client.dart';
import 'package:amyal_pay/features/setting/controllers/profile_screen_controller.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';

/// AMIAL-MYQR-002 — «رمز QR الخاص بي» بتصميم موحّد:
/// بطاقة أميال (شعار + اسم صاحب الحساب + رمز QR + رقم الجوال + رقم الحساب)
/// قابلة للتنزيل صورةً ومشاركتها — يستقبل بها العميل الأموال.
class QrCodeDownloadOrShareScreen extends StatefulWidget {
  final String qrCode;
  final String phoneNumber;
  const QrCodeDownloadOrShareScreen(
      {super.key, required this.qrCode, required this.phoneNumber});

  @override
  State<QrCodeDownloadOrShareScreen> createState() =>
      _QrCodeDownloadOrShareScreenState();
}

class _QrCodeDownloadOrShareScreenState
    extends State<QrCodeDownloadOrShareScreen> {
  final ScreenshotController _shot = ScreenshotController();
  String? _accountNumber;
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    _loadAccountNumber();
  }

  /// رقم الحساب من الخادم (اختياري — البطاقة تعمل بدونه).
  Future<void> _loadAccountNumber() async {
    try {
      final r = await Get.find<ApiClient>()
          .getData('/api/v1/amial/me/account-number');
      if (r.statusCode == 200 && r.body is Map) {
        final meta = r.body['meta'];
        final acc = meta is Map
            ? (meta['account_number'] ?? meta['accountNumber'])
            : null;
        if (acc != null && mounted) {
          setState(() => _accountNumber = '$acc');
        }
      }
    } catch (_) {}
  }

  String get _ownerName {
    try {
      final u = Get.find<ProfileController>().userInfo;
      final n = ('${u?.fName ?? ''} ${u?.lName ?? ''}').trim();
      return n.isEmpty ? 'حسابي في أميال باي' : n;
    } catch (_) {
      return 'حسابي في أميال باي';
    }
  }

  Future<File?> _captureToFile() async {
    final Uint8List? bytes = await _shot.capture(pixelRatio: 3);
    if (bytes == null) return null;
    final dir = await getApplicationDocumentsDirectory();
    final file = File(
        '${dir.path}/amial_qr_${DateTime.now().millisecondsSinceEpoch}.png');
    await file.writeAsBytes(bytes, flush: true);
    return file;
  }

  Future<void> _download() async {
    setState(() => _busy = true);
    try {
      final file = await _captureToFile();
      if (file == null) throw Exception('capture failed');
      await OpenFile.open(file.path, type: 'image/png');
    } catch (_) {
      _snack('تعذّر حفظ الصورة — جرّب المشاركة');
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _share() async {
    setState(() => _busy = true);
    try {
      final file = await _captureToFile();
      if (file == null) throw Exception('capture failed');
      await Share.shareXFiles(
        [XFile(file.path, mimeType: 'image/png')],
        text: 'رمز الاستلام الخاص بي في أميال باي — ${widget.phoneNumber}',
      );
    } catch (_) {
      _snack('تعذّرت المشاركة');
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  void _snack(String m) => ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(m), backgroundColor: AmyalColors.red),
      );

  @override
  Widget build(BuildContext context) {
    final size = MediaQuery.of(context).size;
    return Scaffold(
      backgroundColor: AmyalColors.background,
      appBar: AppBar(
        backgroundColor: AmyalColors.primary,
        foregroundColor: Colors.white,
        title: const Text('رمز الاستلام الخاص بي'),
      ),
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(20),
        child: Column(children: [
          // ====== البطاقة (تُلتقط صورةً كاملة) ======
          Screenshot(
            controller: _shot,
            child: Container(
              decoration: BoxDecoration(
                color: Colors.white,
                borderRadius: BorderRadius.circular(24),
                boxShadow: [
                  BoxShadow(
                      color: Colors.black.withValues(alpha: 0.08),
                      blurRadius: 18,
                      offset: const Offset(0, 6)),
                ],
              ),
              child: Column(children: [
                // رأس أميال المتدرّج
                Container(
                  width: double.infinity,
                  padding: const EdgeInsets.symmetric(vertical: 18),
                  decoration: const BoxDecoration(
                    gradient: LinearGradient(
                      colors: [AmyalColors.primary, Color(0xFF1D4FB8)],
                      begin: Alignment.topRight,
                      end: Alignment.bottomLeft,
                    ),
                    borderRadius:
                        BorderRadius.vertical(top: Radius.circular(24)),
                  ),
                  child: Column(children: [
                    const Text('أميال باي',
                        style: TextStyle(
                            color: Colors.white,
                            fontSize: 20,
                            fontWeight: FontWeight.bold)),
                    const SizedBox(height: 2),
                    Text('دفع سريع وآمن',
                        style: TextStyle(
                            color: Colors.white.withValues(alpha: 0.8),
                            fontSize: 11)),
                  ]),
                ),
                const SizedBox(height: 18),

                Text(_ownerName,
                    style: const TextStyle(
                        fontSize: 17,
                        fontWeight: FontWeight.bold,
                        color: AmyalColors.primary)),
                const SizedBox(height: 14),

                // رمز QR داخل إطار ذهبي
                Container(
                  padding: const EdgeInsets.all(12),
                  decoration: BoxDecoration(
                    color: Colors.white,
                    borderRadius: BorderRadius.circular(18),
                    border: Border.all(color: AmyalColors.yellow, width: 2),
                  ),
                  child: SvgPicture.string(
                    widget.qrCode,
                    height: size.width * 0.52,
                    width: size.width * 0.52,
                  ),
                ),
                const SizedBox(height: 16),

                // رقم الجوال
                _infoRow(Icons.smartphone_rounded, 'رقم الجوال',
                    widget.phoneNumber),
                if (_accountNumber != null) ...[
                  const SizedBox(height: 8),
                  _infoRow(Icons.tag_rounded, 'رقم الحساب', _accountNumber!),
                ],
                const SizedBox(height: 14),

                const Divider(indent: 40, endIndent: 40),
                const Padding(
                  padding: EdgeInsets.fromLTRB(24, 6, 24, 18),
                  child: Text(
                    'امسح الرمز عبر تطبيق أميال باي لتحويل الأموال لهذا الحساب فوراً',
                    textAlign: TextAlign.center,
                    style: TextStyle(
                        fontSize: 12, color: AmyalColors.textSecondary),
                  ),
                ),
                // شريط ذهبي سفلي
                Container(
                  height: 10,
                  decoration: const BoxDecoration(
                    color: AmyalColors.yellow,
                    borderRadius:
                        BorderRadius.vertical(bottom: Radius.circular(24)),
                  ),
                ),
              ]),
            ),
          ),
          const SizedBox(height: 24),

          // ====== الأزرار ======
          Row(children: [
            Expanded(
              child: FilledButton.icon(
                onPressed: _busy ? null : _download,
                icon: _busy
                    ? const SizedBox(
                        height: 16,
                        width: 16,
                        child: CircularProgressIndicator(
                            strokeWidth: 2, color: Colors.white))
                    : const Icon(Icons.download_rounded, size: 20),
                label: const Text('تنزيل الصورة'),
                style: FilledButton.styleFrom(
                  backgroundColor: AmyalColors.primary,
                  minimumSize: const Size.fromHeight(52),
                  shape: RoundedRectangleBorder(
                      borderRadius: BorderRadius.circular(16)),
                ),
              ),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: OutlinedButton.icon(
                onPressed: _busy ? null : _share,
                icon: const Icon(Icons.share_rounded, size: 20),
                label: const Text('مشاركة'),
                style: OutlinedButton.styleFrom(
                  foregroundColor: AmyalColors.primary,
                  side: const BorderSide(color: AmyalColors.yellowDark),
                  minimumSize: const Size.fromHeight(52),
                  shape: RoundedRectangleBorder(
                      borderRadius: BorderRadius.circular(16)),
                ),
              ),
            ),
          ]),
        ]),
      ),
    );
  }

  Widget _infoRow(IconData icon, String label, String value) {
    return Row(mainAxisAlignment: MainAxisAlignment.center, children: [
      Text(value,
          textDirection: TextDirection.ltr,
          style: const TextStyle(
              fontSize: 16,
              fontWeight: FontWeight.bold,
              letterSpacing: 1.2)),
      const SizedBox(width: 8),
      Text('· $label',
          style:
              const TextStyle(fontSize: 12, color: AmyalColors.textMuted)),
      const SizedBox(width: 4),
      Icon(icon, size: 16, color: AmyalColors.yellowDark),
    ]);
  }
}
