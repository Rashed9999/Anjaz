import 'dart:io';

import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:http/http.dart' as http;
import 'package:open_file/open_file.dart';
import 'package:path_provider/path_provider.dart';
import 'package:amial_pay/data/api/api_client.dart';
import 'package:amial_pay/features/safe_payment/domain/models/safe_payment_models.dart';
import 'package:amial_pay/theme/amial_colors.dart';
import 'package:amial_pay/util/app_constants.dart';

/// AMIAL-SAFEPAY-EVIDENCE-001 — معرض الأدلّة.
///
/// الطرفان يريان أدلّة بعضهما. هذا مقصود: من يرى دليل خصمه يعرف موقفه
/// فيوضّح أو يتراجع — وأكثر النزاعات تُغلق قبل بلوغ الإدارة.
///
/// **قرارات مقصودة:**
///   - التجميع بالمرحلة لا بالزمن: «عند الشحن» و«عند التسليم» و«مع النزاع»
///     تُثبت أشياء مختلفة، وخلطها في شريط واحد يُضيع معناها.
///   - كل دليل يحمل صاحبه ووقته وبصمته: صورة بلا هذه الثلاثة ليست دليلاً.
///   - الملفّ يُجلب عند الطلب لا مع الشاشة: تحميل عشرين صورة لمن فتح
///     الصفحة ليقرأ المبلغ إهدارٌ لباقته.
class EvidenceGallery extends StatelessWidget {
  const EvidenceGallery({super.key, required this.evidence});

  final Map<String, List<AmialEvidenceItem>> evidence;

  /// ترتيب المراحل بترتيب وقوعها لا بترتيب حروفها.
  static const _order = ['created', 'in_delivery', 'delivered', 'dispute', 'admin_review'];

  static String fileUrl(int id) =>
      '${Get.find<ApiClient>().appBaseUrl}${AppConstants.amialSafePayments}/evidence/$id/file';

  static Map<String, String> authHeaders() =>
      {'Authorization': 'Bearer ${Get.find<ApiClient>().token}'};

  @override
  Widget build(BuildContext context) {
    final stages = _order.where((s) => (evidence[s] ?? []).isNotEmpty).toList();
    for (final key in evidence.keys) {
      if (!_order.contains(key) && evidence[key]!.isNotEmpty) stages.add(key);
    }

    if (stages.isEmpty) {
      return Container(
        padding: const EdgeInsets.all(14),
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(8),
          border: Border.all(color: AmialColors.border),
        ),
        child: const Row(children: [
          Icon(Icons.photo_library_outlined, size: 18, color: AmialColors.textMuted),
          SizedBox(width: 10),
          Expanded(
            child: Text(
              'لا توجد أدلّة بعد. الصور المرفوعة في وقتها تحسم النزاع لصاحبها.',
              style: TextStyle(fontSize: 11.5, height: 1.6, color: AmialColors.textMuted),
            ),
          ),
        ]),
      );
    }

    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(8),
        border: Border.all(color: AmialColors.border),
      ),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        const Padding(
          padding: EdgeInsets.only(bottom: 4),
          child: Text('الأدلّة',
              style: TextStyle(
                  fontWeight: FontWeight.bold,
                  fontSize: 13,
                  color: AmialColors.textSecondary)),
        ),
        for (final stage in stages) ...[
          const SizedBox(height: 8),
          Row(children: [
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
              decoration: BoxDecoration(
                color: AmialColors.primary.withValues(alpha: 0.08),
                borderRadius: BorderRadius.circular(20),
              ),
              child: Text(AmialEvidenceItem.stageLabel(stage),
                  style: const TextStyle(
                      fontSize: 10.5,
                      fontWeight: FontWeight.bold,
                      color: AmialColors.primary)),
            ),
            const SizedBox(width: 6),
            Text('${evidence[stage]!.length}',
                style: const TextStyle(fontSize: 10.5, color: AmialColors.textMuted)),
          ]),
          const SizedBox(height: 8),
          SizedBox(
            height: 104,
            child: ListView.separated(
              scrollDirection: Axis.horizontal,
              itemCount: evidence[stage]!.length,
              separatorBuilder: (_, __) => const SizedBox(width: 8),
              itemBuilder: (_, i) => _EvidenceThumb(
                item: evidence[stage]![i],
                all: evidence[stage]!,
                index: i,
              ),
            ),
          ),
        ],
      ]),
    );
  }
}

class _EvidenceThumb extends StatelessWidget {
  const _EvidenceThumb({required this.item, required this.all, required this.index});

  final AmialEvidenceItem item;
  final List<AmialEvidenceItem> all;
  final int index;

  @override
  Widget build(BuildContext context) {
    return InkWell(
      onTap: () {
        if (item.isPdf) {
          _openPdf(context, item);
        } else {
          Navigator.of(context).push(MaterialPageRoute(
            builder: (_) => EvidenceViewerScreen(items: all, initialIndex: index),
          ));
        }
      },
      borderRadius: BorderRadius.circular(10),
      child: SizedBox(
        width: 88,
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          ClipRRect(
            borderRadius: BorderRadius.circular(10),
            child: item.isPdf
                ? Container(
                    width: 88,
                    height: 78,
                    color: const Color(0xFFF0F1F3),
                    child: const Icon(Icons.picture_as_pdf_outlined,
                        size: 30, color: AmialColors.red),
                  )
                : Image.network(
                    EvidenceGallery.fileUrl(item.id),
                    headers: EvidenceGallery.authHeaders(),
                    width: 88,
                    height: 78,
                    fit: BoxFit.cover,
                    loadingBuilder: (_, child, progress) => progress == null
                        ? child
                        : Container(
                            width: 88,
                            height: 78,
                            color: const Color(0xFFF0F1F3),
                            child: const Center(
                              child: SizedBox(
                                width: 18,
                                height: 18,
                                child: CircularProgressIndicator(strokeWidth: 2),
                              ),
                            ),
                          ),
                    errorBuilder: (_, __, ___) => Container(
                      width: 88,
                      height: 78,
                      color: const Color(0xFFF0F1F3),
                      child: const Icon(Icons.broken_image_outlined,
                          size: 24, color: AmialColors.textMuted),
                    ),
                  ),
          ),
          const SizedBox(height: 3),
          Text(item.roleLabel,
              style: const TextStyle(fontSize: 9.5, color: AmialColors.textMuted)),
        ]),
      ),
    );
  }

  static Future<void> _openPdf(BuildContext context, AmialEvidenceItem item) async {
    final messenger = ScaffoldMessenger.of(context);
    messenger.showSnackBar(
        const SnackBar(content: Text('جارٍ فتح الملف…'), duration: Duration(seconds: 1)));
    try {
      final resp = await http.get(
        Uri.parse(EvidenceGallery.fileUrl(item.id)),
        headers: EvidenceGallery.authHeaders(),
      );
      if (resp.statusCode != 200) {
        messenger.showSnackBar(const SnackBar(content: Text('تعذّر فتح الملف')));
        return;
      }
      final dir = await getTemporaryDirectory();
      final f = File('${dir.path}/evidence_${item.id}.pdf');
      await f.writeAsBytes(resp.bodyBytes, flush: true);
      await OpenFile.open(f.path);
    } catch (_) {
      messenger.showSnackBar(const SnackBar(content: Text('تعذّر فتح الملف')));
    }
  }
}

/// عارض ملء الشاشة — الدليل يُقرأ بتفاصيله لا بمصغّرته.
class EvidenceViewerScreen extends StatefulWidget {
  const EvidenceViewerScreen({super.key, required this.items, required this.initialIndex});

  final List<AmialEvidenceItem> items;
  final int initialIndex;

  @override
  State<EvidenceViewerScreen> createState() => _EvidenceViewerScreenState();
}

class _EvidenceViewerScreenState extends State<EvidenceViewerScreen> {
  late final PageController _pages = PageController(initialPage: widget.initialIndex);
  late int _current = widget.initialIndex;

  @override
  void dispose() {
    _pages.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final item = widget.items[_current];

    return Scaffold(
      backgroundColor: Colors.black,
      appBar: AppBar(
        backgroundColor: Colors.black,
        foregroundColor: Colors.white,
        title: Text('دليل ${_current + 1} من ${widget.items.length}',
            style: const TextStyle(fontSize: 15)),
      ),
      body: Column(children: [
        Expanded(
          child: PageView.builder(
            controller: _pages,
            itemCount: widget.items.length,
            onPageChanged: (i) => setState(() => _current = i),
            itemBuilder: (_, i) => InteractiveViewer(
              minScale: 1,
              maxScale: 4,
              child: Center(
                child: Image.network(
                  EvidenceGallery.fileUrl(widget.items[i].id),
                  headers: EvidenceGallery.authHeaders(),
                  fit: BoxFit.contain,
                  errorBuilder: (_, __, ___) => const Icon(Icons.broken_image_outlined,
                      size: 48, color: Colors.white38),
                ),
              ),
            ),
          ),
        ),

        // بطاقة الإسناد: من رفعه ومتى وببصمة أيّ ملفّ. هذه هي التي تحوّل
        // الصورة من «صورة» إلى دليل يُحتجّ به.
        Container(
          width: double.infinity,
          color: const Color(0xFF141414),
          padding: const EdgeInsets.fromLTRB(16, 12, 16, 20),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text('رفعه: ${item.roleLabel} — ${AmialEvidenceItem.stageLabel(item.stage)}',
                style: const TextStyle(color: Colors.white, fontSize: 12.5)),
            const SizedBox(height: 3),
            if (item.uploadedAt != null)
              Text(_fmt(item.uploadedAt!),
                  style: const TextStyle(color: Colors.white54, fontSize: 11)),
            const SizedBox(height: 3),
            Text('البصمة: ${item.fingerprint}',
                textDirection: TextDirection.ltr,
                style: const TextStyle(color: Colors.white38, fontSize: 10)),
          ]),
        ),
      ]),
    );
  }

  static String _fmt(DateTime d) {
    final l = d.toLocal();
    return '${l.year}-${l.month.toString().padLeft(2, '0')}-${l.day.toString().padLeft(2, '0')}'
        ' ${l.hour.toString().padLeft(2, '0')}:${l.minute.toString().padLeft(2, '0')}';
  }
}
