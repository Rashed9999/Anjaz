import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:http/http.dart' as http;
import 'package:share_plus/share_plus.dart';
import 'package:amyal_pay/features/receipts/controllers/receipts_controller.dart';
import 'package:amyal_pay/data/api/secure_storage_helper.dart';
import 'package:amyal_pay/helper/pdf_downloader_helper.dart';
import 'package:amyal_pay/theme/amyal_colors.dart';

/// AMIAL-RECEIPTS-001 (v0.9-D)
class ReceiptDetailScreen extends StatefulWidget {
  final int receiptId;
  const ReceiptDetailScreen({super.key, required this.receiptId});

  @override
  State<ReceiptDetailScreen> createState() => _ReceiptDetailScreenState();
}

class _ReceiptDetailScreenState extends State<ReceiptDetailScreen> {
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      Get.find<ReceiptsController>().selectReceipt(widget.receiptId);
    });
  }

  Future<void> _downloadPdf() async {
    // AMIAL-FIX(PDF): كان يفتح الرابط في المتصفّح الخارجي بلا رمز الدخول →
    // يُرفض (401). الآن نُنزّل الـ PDF داخل التطبيق مع ترويسة المصادقة، نحفظه
    // مؤقّتاً، ثمّ نفتحه بعارض النظام.
    if (!mounted) return;
    final messenger = ScaffoldMessenger.of(context);
    messenger.showSnackBar(
      const SnackBar(content: Text('جارٍ تحضير الإيصال...')),
    );
    try {
      final url = Get.find<ReceiptsController>().getDownloadUrl(widget.receiptId);
      String? token;
      try {
        token = await SecureStorageHelper.instance.getToken();
      } catch (_) {}
      final resp = await http.get(
        Uri.parse(url),
        headers: {
          if (token != null && token.isNotEmpty) 'Authorization': 'Bearer $token',
          'Accept': 'application/pdf',
        },
      ).timeout(const Duration(seconds: 30));

      final contentType = resp.headers['content-type'] ?? '';
      if (resp.statusCode != 200 || contentType.contains('json')) {
        messenger.showSnackBar(
          SnackBar(content: Text('تعذّر تحميل الإيصال (${resp.statusCode})')),
        );
        return;
      }

      // نُسلّم البايتات للمساعد القويّ (يحفظ + يفتح مع بدائل حسب المنصّة).
      await PdfDownloaderHelper.downloadAndOpenPdf(
        pdfData: resp.bodyBytes,
        baseFileName: 'receipt_${widget.receiptId}',
      );
    } catch (e) {
      messenger.showSnackBar(
        SnackBar(content: Text('فشل تحميل PDF: $e')),
      );
    }
  }

  Future<void> _shareReceipt() async {
    final receipt = Get.find<ReceiptsController>().selectedReceipt.value;
    if (receipt == null) return;

    final shareText = '''
إيصال Amyal Pay
الرقم: ${receipt.receiptNumber}
النوع: ${receipt.arabicTypeLabel}
المبلغ: ${receipt.amount} ر.س
التاريخ: ${receipt.issuedAt}

للتحقق:
${Get.find<ReceiptsController>().getDownloadUrl(receipt.id)}
''';
    await Share.share(shareText, subject: receipt.receiptNumber);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmyalColors.background,
      appBar: AppBar(
        backgroundColor: AmyalColors.primary,
        foregroundColor: Colors.white,
        title: const Text('تفاصيل الإيصال'),
        actions: [
          IconButton(
            icon: const Icon(Icons.share),
            onPressed: _shareReceipt,
          ),
        ],
      ),
      body: Obx(() {
        final ctrl = Get.find<ReceiptsController>();
        final r = ctrl.selectedReceipt.value;

        if (ctrl.isLoading.value || r == null) {
          return const Center(
              child: CircularProgressIndicator(color: AmyalColors.primary));
        }

        final isCredit = r.direction == 'credit';

        return SingleChildScrollView(
          padding: const EdgeInsets.all(16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              // Amount card
              Container(
                padding: const EdgeInsets.all(24),
                decoration: BoxDecoration(
                  color: AmyalColors.yellow,
                  borderRadius: BorderRadius.circular(12),
                ),
                child: Column(
                  children: [
                    Text(
                      r.arabicTypeLabel,
                      style: const TextStyle(
                        color: AmyalColors.primary,
                        fontSize: 14,
                        fontWeight: FontWeight.w600,
                      ),
                    ),
                    const SizedBox(height: 8),
                    Text(
                      '${isCredit ? '+' : '-'}${r.amount} ر.س',
                      style: TextStyle(
                        color: AmyalColors.primary,
                        fontSize: 32,
                        fontWeight: FontWeight.bold,
                      ),
                    ),
                    const SizedBox(height: 4),
                    Text(
                      isCredit ? 'مضاف لحسابك' : 'مخصوم من حسابك',
                      style: TextStyle(
                        color: AmyalColors.primary.withValues(alpha: 0.7),
                        fontSize: 12,
                      ),
                    ),
                  ],
                ),
              ),
              const SizedBox(height: 16),

              // Details
              _detailRow('رقم الإيصال', r.receiptNumber, monospace: true),
              _detailRow('المعاملة', r.referenceTransactionId, monospace: true),
              if (double.tryParse(r.fee) != null && double.parse(r.fee) > 0)
                _detailRow('الرسوم', '${r.fee} ر.س'),
              if (double.tryParse(r.fee) != null && double.parse(r.fee) > 0)
                _detailRow('الإجمالي', '${r.netAmount} ر.س'),
              if (r.issuedAt != null)
                _detailRow('التاريخ', _fmtDate(r.issuedAt!)),
              _detailRow('المنطقة', r.zoneCode),

              const SizedBox(height: 16),

              // Verification code
              Container(
                padding: const EdgeInsets.all(16),
                decoration: BoxDecoration(
                  color: Colors.white,
                  borderRadius: BorderRadius.circular(8),
                  border: Border.all(color: AmyalColors.border),
                ),
                child: Column(
                  children: [
                    const Text(
                      'كود التحقق',
                      style: TextStyle(fontSize: 12, color: AmyalColors.textSecondary),
                    ),
                    const SizedBox(height: 8),
                    SelectableText(
                      r.verificationCode,
                      style: const TextStyle(
                        fontFamily: 'monospace',
                        fontSize: 18,
                        fontWeight: FontWeight.bold,
                        color: AmyalColors.primary,
                        letterSpacing: 2,
                      ),
                    ),
                    const SizedBox(height: 4),
                    const Text(
                      'استخدم هذا الكود للتحقق من صحة الإيصال',
                      style: TextStyle(fontSize: 11, color: AmyalColors.textMuted),
                    ),
                  ],
                ),
              ),
              const SizedBox(height: 24),

              // Action: download PDF
              if (r.isReady)
                ElevatedButton.icon(
                  onPressed: _downloadPdf,
                  icon: const Icon(Icons.download),
                  label: const Text('تحميل PDF'),
                  style: ElevatedButton.styleFrom(
                    backgroundColor: AmyalColors.primary,
                    foregroundColor: Colors.white,
                    padding: const EdgeInsets.symmetric(vertical: 14),
                  ),
                )
              else if (r.isPending)
                Container(
                  padding: const EdgeInsets.all(12),
                  decoration: BoxDecoration(
                    color: AmyalColors.yellow.withValues(alpha: 0.3),
                    borderRadius: BorderRadius.circular(8),
                  ),
                  child: Row(
                    children: const [
                      SizedBox(width: 18, height: 18,
                          child: CircularProgressIndicator(
                              strokeWidth: 2, color: AmyalColors.primary)),
                      SizedBox(width: 12),
                      Expanded(
                        child: Text(
                          'PDF قيد التحضير… أعد المحاولة بعد ثوانٍ.',
                          style: TextStyle(fontSize: 13, color: AmyalColors.primary),
                        ),
                      ),
                    ],
                  ),
                )
              else if (r.isFailed)
                Container(
                  padding: const EdgeInsets.all(12),
                  decoration: BoxDecoration(
                    color: AmyalColors.red.withValues(alpha: 0.1),
                    borderRadius: BorderRadius.circular(8),
                    border: Border.all(color: AmyalColors.red),
                  ),
                  child: const Text(
                    'فشل توليد PDF — تواصل مع الدعم لإعادة المحاولة',
                    style: TextStyle(color: AmyalColors.red),
                  ),
                ),
            ],
          ),
        );
      }),
    );
  }

  Widget _detailRow(String label, String value, {bool monospace = false}) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 6),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(
            width: 100,
            child: Text(label,
                style: TextStyle(color: AmyalColors.textSecondary, fontSize: 13)),
          ),
          Expanded(
            child: SelectableText(
              value,
              style: TextStyle(
                fontFamily: monospace ? 'monospace' : null,
                fontWeight: monospace ? FontWeight.w600 : FontWeight.w500,
                fontSize: monospace ? 12 : 13,
              ),
            ),
          ),
        ],
      ),
    );
  }

  String _fmtDate(DateTime d) {
    return '${d.year}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')} '
        '${d.hour.toString().padLeft(2, '0')}:${d.minute.toString().padLeft(2, '0')}';
  }
}
