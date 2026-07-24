import 'package:amyal_pay/helper/amial_money.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
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
    // AMIAL-FIX(PDF-FINAL): الخادم قد يُسقط الاتصال أثناء إرسال ملفّ يُولَّد لحظياً
    // عبر شبكة جوّال بطيئة («Connection closed while receiving data»). الحلّ
    // النهائي: إعادة المحاولة تلقائياً حتى 3 مرّات بمهلة أطول وتراجع تصاعدي —
    // والخادم يخزّن الملفّ مؤقّتاً فتصل المحاولة الثانية فوراً من الكاش.
    if (!mounted) return;
    final messenger = ScaffoldMessenger.of(context);
    messenger.showSnackBar(
      const SnackBar(content: Text('جارٍ تحضير الإيصال...')),
    );

    final url = Get.find<ReceiptsController>().getDownloadUrl(widget.receiptId);
    String? token;
    try {
      token = await SecureStorageHelper.instance.getToken();
    } catch (_) {}
    final headers = {
      if (token != null && token.isNotEmpty) 'Authorization': 'Bearer $token',
      'Accept': 'application/pdf',
    };

    const maxAttempts = 3;
    Object? lastError;
    for (var attempt = 1; attempt <= maxAttempts; attempt++) {
      try {
        final resp = await http
            .get(Uri.parse(url), headers: headers)
            .timeout(const Duration(seconds: 60));

        final contentType = resp.headers['content-type'] ?? '';
        if (resp.statusCode != 200 || contentType.contains('json')) {
          // خطأ منطقي (مصادقة/عدم وجود) — التكرار لن يُصلحه.
          if (!mounted) return;
          messenger.showSnackBar(
            SnackBar(content: Text('تعذّر تحميل الإيصال (${resp.statusCode})')),
          );
          return;
        }
        if (resp.bodyBytes.isEmpty) {
          throw Exception('ملفّ فارغ');
        }

        await PdfDownloaderHelper.downloadAndOpenPdf(
          pdfData: resp.bodyBytes,
          baseFileName: 'receipt_${widget.receiptId}',
        );
        return; // نجاح
      } catch (e) {
        lastError = e;
        if (attempt < maxAttempts) {
          if (mounted) {
            messenger.showSnackBar(SnackBar(
              content: Text('تعذّر الاتصال — إعادة المحاولة ($attempt/$maxAttempts)...'),
              duration: const Duration(milliseconds: 900),
            ));
          }
          await Future.delayed(Duration(seconds: attempt * 2)); // 2s ثمّ 4s
        }
      }
    }

    if (!mounted) return;
    messenger.showSnackBar(SnackBar(
      content: Text('تعذّر تحميل الإيصال بعد $maxAttempts محاولات. تحقّق من الاتصال وحاول لاحقاً.\n$lastError'),
      duration: const Duration(seconds: 4),
    ));
  }

  Future<void> _shareReceipt() async {
    final receipt = Get.find<ReceiptsController>().selectedReceipt.value;
    if (receipt == null) return;

    final shareText = '''
إيصال Amyal Pay
الرقم: ${receipt.receiptNumber}
النوع: ${receipt.arabicTypeLabel}
المبلغ: ${AmialMoney.fmt(receipt.amount)} ر.ي
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
                      (isCredit ? '+' : '-')+AmialMoney.yer(r.amount),
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

              // AMIAL-RECEIPT-STYLE-002: تفاصيل بأسلوب «جيب» — أرقام عملية نظيفة
              // + من/إلى، داخل بطاقة بيضاء أنيقة.
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 6),
                decoration: BoxDecoration(
                  color: Colors.white,
                  borderRadius: BorderRadius.circular(14),
                ),
                child: Column(children: [
                  // AMIAL-TXN-NO-001: رقم العملية الرسمي (15 خانة، بادئة حسب
                  // النوع) كما يولّده النظام. كان يُعرض رقم مرجع مُلفَّق محلياً
                  // من الوقت+المعرّف — وهو غير مقبول في منتج مالي.
                  if ((r.transactionNo ?? '').isNotEmpty) ...[
                    _detailRow('رقم العملية', r.transactionNo!, monospace: true),
                    const Divider(height: 1),
                  ],
                  _detailRow('العملية', r.arabicTypeLabel),
                  const Divider(height: 1),
                  _detailRow('رقم الإيصال', r.receiptNumber, monospace: true),
                  const Divider(height: 1),
                  if (r.issuedAt != null) ...[
                    _detailRow('تاريخ العملية', _fmtDate(r.issuedAt!)),
                    const Divider(height: 1),
                  ],
                  if (_party(r, 'from') != null) ...[
                    _detailRow('من', _party(r, 'from')!),
                    const Divider(height: 1),
                  ],
                  if (_party(r, 'to') != null) ...[
                    _detailRow('إلى', _party(r, 'to')!),
                    const Divider(height: 1),
                  ],
                  if (double.tryParse(r.fee) != null && double.parse(r.fee) > 0) ...[
                    _detailRow('الرسوم', AmialMoney.yer(r.fee)),
                    const Divider(height: 1),
                    _detailRow('الإجمالي', AmialMoney.yer(r.netAmount)),
                    const Divider(height: 1),
                  ],
                  _detailRow('المنطقة', r.zoneCode),
                ]),
              ),

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

              // Action: download PDF — يُولَّد عند الطلب على الخادم دائماً،
              // فلا حاجة لحالة «قيد التحضير» (الزرّ متاح دوماً).
              ElevatedButton.icon(
                onPressed: _downloadPdf,
                icon: const Icon(Icons.download),
                label: const Text('تحميل PDF'),
                style: ElevatedButton.styleFrom(
                  backgroundColor: AmyalColors.primary,
                  foregroundColor: Colors.white,
                  padding: const EdgeInsets.symmetric(vertical: 14),
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
          // AMIAL-UNIFY-UI-001: نسخ رقم المرجع/الإيصال بضغطة (كان طويلاً وغير قابل للنسخ بوضوح)
          if (monospace && value.isNotEmpty)
            InkWell(
              onTap: () {
                Clipboard.setData(ClipboardData(text: value));
                ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
                  content: Text('نُسخ الرقم'),
                  backgroundColor: Color(0xFF2E7D32),
                  duration: Duration(seconds: 1),
                ));
              },
              borderRadius: BorderRadius.circular(8),
              child: const Padding(
                padding: EdgeInsets.all(4),
                child: Icon(Icons.copy_rounded, size: 16, color: AmyalColors.primary),
              ),
            ),
        ],
      ),
    );
  }

  String _fmtDate(DateTime d) {
    return '${d.day.toString().padLeft(2, '0')}/${d.month.toString().padLeft(2, '0')}/${d.year} '
        '(${d.hour.toString().padLeft(2, '0')}:${d.minute.toString().padLeft(2, '0')})';
  }


  /// اسم الطرف (من/إلى) من الميتاداتا إن توفّر — وإلا null فلا يُعرض السطر.
  String? _party(dynamic r, String side) {
    final meta = r.metadata as Map<String, dynamic>?;
    if (meta == null) return null;
    final keys = side == 'from'
        ? ['from_name', 'sender_name', 'payer_name', 'from', 'counterparty_from']
        : ['to_name', 'receiver_name', 'recipient_name', 'merchant_name', 'to', 'counterparty_to'];
    for (final k in keys) {
      final v = meta[k];
      if (v != null && v.toString().trim().isNotEmpty) return v.toString();
    }
    return null;
  }
}
