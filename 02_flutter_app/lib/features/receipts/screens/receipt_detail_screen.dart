import 'package:amial_pay/common/widgets/amial_ltr_number.dart';
import 'package:amial_pay/helper/amial_money.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:get/get.dart';
import 'package:http/http.dart' as http;
import 'package:share_plus/share_plus.dart';
import 'package:amial_pay/features/receipts/controllers/receipts_controller.dart';
import 'package:amial_pay/data/api/secure_storage_helper.dart';
import 'package:amial_pay/helper/pdf_downloader_helper.dart';
import 'package:amial_pay/features/shared/utils/operation_status.dart';
import 'package:amial_pay/theme/amial_colors.dart';
import 'package:amial_pay/features/favorite_number/controllers/amial_favorites_controller.dart';
import 'package:amial_pay/features/favorite_number/widgets/amial_favorite_star.dart';
import 'package:amial_pay/util/arabic_number_words.dart';

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

    // AMIAL-RECEIPT-TIME-001: كان يطبع DateTime خاماً
    // (2026-07-26 12:12:00.000) في رسالة يقرؤها متلقٍّ عاديّ.
    final shareText = '''
إيصال Amial Pay
الرقم: ${receipt.receiptNumber}
النوع: ${receipt.arabicTypeLabel}
المبلغ: ${AmialMoney.fmt(receipt.amount)} ر.ي
التاريخ: ${_formatWhen(receipt.issuedAt)}

للتحقق:
${Get.find<ReceiptsController>().getDownloadUrl(receipt.id)}
''';
    await Share.share(shareText, subject: receipt.receiptNumber);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(
        title: const Text('تفاصيل الإيصال'),
        actions: [
          // AMIAL-FAVORITES-001: حفظ العملية للتكرار — الإيجار والاشتراك
          // والتحويل الشهري تُعاد بنفس التفاصيل، وإيجادها في السجلّ كل
          // مرّة بحثٌ لا داعي له.
          Obx(() {
            final r = Get.find<ReceiptsController>().selectedReceipt.value;
            if (r == null) return const SizedBox.shrink();
            return AmialFavoriteStar(
              kind: FavKind.operation,
              value: r.receiptNumber,
              label: r.arabicTypeLabel,
              metadata: {
                'amount': r.amount,
                'type_label': r.arabicTypeLabel,
                'receipt_id': r.id,
              },
            );
          }),
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
              child: CircularProgressIndicator(color: AmialColors.primary));
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
                  // AMIAL-RECEIPT-STYLE-003: كان مستطيلاً أصفر مشبعاً يصرخ في
                  // وجه المستخدم. الإيصالات المصرفية تستعمل خلفية محايدة
                  // والمبلغ وحده ملوّن — أخضر للوارد وأحمر للصادر.
                  color: const Color(0xFFEEF1F7),
                  borderRadius: BorderRadius.circular(16),
                ),
                child: Column(
                  children: [
                    Text(
                      r.arabicTypeLabel,
                      style: const TextStyle(
                        color: AmialColors.textSecondary,
                        fontSize: 13,
                        fontWeight: FontWeight.w600,
                      ),
                    ),
                    const SizedBox(height: 8),
                    // AMIAL-RTL-SIGN-001
                    AmialLtrNumber(
                      (isCredit ? '+' : '-') + AmialMoney.yer(r.amount),
                      style: TextStyle(
                        color: isCredit
                            ? const Color(0xFF16A34A)
                            : AmialColors.red,
                        fontSize: 32,
                        fontWeight: FontWeight.bold,
                      ),
                    ),
                    const SizedBox(height: 6),
                    // AMIAL-RECEIPT-TAFQIT-001: المبلغ بالحروف — معيار مصرفي
                    // يمنع تحريف الرقم، وكان غائباً عن إيصالاتنا كلّها.
                    Builder(builder: (_) {
                      final words = ArabicNumberWords.yerFromString(r.amount);
                      if (words == null) return const SizedBox.shrink();
                      return Text(
                        words,
                        textAlign: TextAlign.center,
                        style: const TextStyle(
                          color: Color(0xFF1A2433),
                          fontSize: 12.5,
                          fontWeight: FontWeight.w600,
                          height: 1.5,
                        ),
                      );
                    }),
                    const SizedBox(height: 6),
                    Text(
                      isCredit ? 'مضاف لحسابك' : 'مخصوم من حسابك',
                      style: const TextStyle(
                        color: AmialColors.textMuted,
                        fontSize: 11.5,
                      ),
                    ),
                    const SizedBox(height: 10),
                    // AMIAL-OP-STATUS-001: حالة العملية الحقيقية
                    OperationStatus.of(r.opStatus).chip(fontSize: 11),
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
                  // AMIAL-RECEIPT-FEES-001: تفصيل الرسوم يظهر **دائماً**، حتى
                  // حين تكون صفراً. إخفاؤه عند الصفر يترك المستخدم يظنّ أن
                  // ثمّة اقتطاعاً خفياً — وهي الشكوى الأصلية عن النِسَب.
                  // ثلاثة أسطر كما في الإيصالات المصرفية: المبلغ، الرسوم،
                  // الإجمالي.
                  _detailRow('المبلغ', AmialMoney.yer(r.amount)),
                  const Divider(height: 1),
                  _detailRow('رسوم العملية', AmialMoney.yer(r.fee)),
                  const Divider(height: 1),
                  _detailRow('الإجمالي', AmialMoney.yer(r.netAmount), bold: true),
                  const Divider(height: 1),
                  // AMIAL-ZONE-AR-001: كان يُعرض رمز المنطقة الخام «SOUTH»
                  // بالإنجليزية للمستخدم. الخادم يملك التسمية العربية أصلاً
                  // (ZonePolicyService::zoneNameAr) ولم تكن مربوطة هنا.
                  _detailRow('المنطقة', _zoneAr(r.zoneCode)),
                ]),
              ),

              const SizedBox(height: 16),

              // Verification code
              Container(
                padding: const EdgeInsets.all(16),
                decoration: BoxDecoration(
                  color: Colors.white,
                  borderRadius: BorderRadius.circular(8),
                  border: Border.all(color: AmialColors.border),
                ),
                child: Column(
                  children: [
                    const Text(
                      'كود التحقق',
                      style: TextStyle(fontSize: 12, color: AmialColors.textSecondary),
                    ),
                    const SizedBox(height: 8),
                    SelectableText(
                      r.verificationCode,
                      style: const TextStyle(
                        fontFamily: 'monospace',
                        fontSize: 18,
                        fontWeight: FontWeight.bold,
                        color: AmialColors.primary,
                        letterSpacing: 2,
                      ),
                    ),
                    const SizedBox(height: 4),
                    const Text(
                      'استخدم هذا الكود للتحقق من صحة الإيصال',
                      style: TextStyle(fontSize: 11, color: AmialColors.textMuted),
                    ),
                  ],
                ),
              ),
              const SizedBox(height: 24),

              // AMIAL-RECEIPT-ACTIONS-001: ثلاثة إجراءات كمربّعات — كما في
              // الإيصالات المصرفية. كان زرّ PDF وحيداً وأيقونة مشاركة تائهة
              // في شريط العنوان، فلا يجد المستخدم «نسخ» أصلاً.
              Row(children: [
                Expanded(
                  child: _receiptAction(
                    icon: Icons.picture_as_pdf_outlined,
                    label: 'تحميل PDF',
                    onTap: _downloadPdf,
                  ),
                ),
                const SizedBox(width: 10),
                Expanded(
                  child: _receiptAction(
                    icon: Icons.copy_rounded,
                    label: 'نسخ',
                    onTap: () => _copySummary(r),
                  ),
                ),
                const SizedBox(width: 10),
                Expanded(
                  child: _receiptAction(
                    icon: Icons.share_outlined,
                    label: 'مشاركة',
                    onTap: _shareReceipt,
                  ),
                ),
              ]),
            ],
          ),
        );
      }),
    );
  }

  /// مربّع إجراء على الإيصال — شكل موحّد للثلاثة.
  Widget _receiptAction({
    required IconData icon,
    required String label,
    required VoidCallback onTap,
  }) {
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(16),
      child: Container(
        padding: const EdgeInsets.symmetric(vertical: 14),
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(16),
          border: Border.all(color: AmialColors.border),
        ),
        child: Column(children: [
          Icon(icon, size: 22, color: AmialColors.primary),
          const SizedBox(height: 6),
          Text(label,
              style: const TextStyle(
                  fontSize: 11.5,
                  fontWeight: FontWeight.w600,
                  color: AmialColors.primary)),
        ]),
      ),
    );
  }

  /// ينسخ ملخّص الإيصال نصّاً — بما فيه التفقيط وتفصيل الرسوم.
  void _copySummary(dynamic r) {
    final buf = StringBuffer()
      ..writeln('إيصال أميال باي')
      ..writeln('العملية: ${r.arabicTypeLabel}');
    if ((r.transactionNo ?? '').toString().isNotEmpty) {
      buf.writeln('رقم العملية: ${r.transactionNo}');
    }
    buf
      ..writeln('رقم الإيصال: ${r.receiptNumber}')
      ..writeln('المبلغ: ${AmialMoney.yer(r.amount)}')
      ..writeln('(${ArabicNumberWords.yerFromString(r.amount) ?? ''})')
      ..writeln('رسوم العملية: ${AmialMoney.yer(r.fee)}')
      ..writeln('الإجمالي: ${AmialMoney.yer(r.netAmount)}')
      ..writeln('كود التحقق: ${r.verificationCode}');
    Clipboard.setData(ClipboardData(text: buf.toString()));
    ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
      content: Text('نُسخ ملخّص الإيصال'),
      backgroundColor: Color(0xFF16A34A),
      duration: Duration(seconds: 2),
    ));
  }

  /// AMIAL-ZONE-AR-001: تحويل رمز المنطقة إلى اسمها العربي.
  /// نفس خريطة `ZonePolicyService::zoneNameAr` في الخادم.
  String _zoneAr(String code) {
    switch (code.trim().toUpperCase()) {
      case 'SOUTH':
        return 'الجنوب';
      case 'NORTH':
        return 'الشمال';
      case 'EAST':
        return 'الشرق';
      case 'WEST':
        return 'الغرب';
      case 'ALL':
        return 'كل المناطق';
      default:
        return code.isEmpty ? '—' : code;
    }
  }

  Widget _detailRow(String label, String value,
      {bool monospace = false, bool bold = false}) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 6),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(
            width: 100,
            child: Text(label,
                style: TextStyle(color: AmialColors.textSecondary, fontSize: 13)),
          ),
          Expanded(
            child: SelectableText(
              value,
              style: TextStyle(
                fontFamily: monospace ? 'monospace' : null,
                fontWeight: bold
                    ? FontWeight.bold
                    : (monospace ? FontWeight.w600 : FontWeight.w500),
                fontSize: bold ? 14.5 : (monospace ? 12 : 13),
                color: bold ? AmialColors.primary : null,
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
                child: Icon(Icons.copy_rounded, size: 16, color: AmialColors.primary),
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

  /// تاريخ ووقت مقروءان في نصّ المشاركة.
  /// الوقت جزء من هوية العملية: يوم واحد قد يحمل عدّة عمليات بنفس المبلغ.
  static String _formatWhen(DateTime? d) {
    if (d == null) return '—';
    String two(int n) => n.toString().padLeft(2, '0');
    return '${two(d.day)}-${two(d.month)}-${d.year} • ${two(d.hour)}:${two(d.minute)}';
  }
}
