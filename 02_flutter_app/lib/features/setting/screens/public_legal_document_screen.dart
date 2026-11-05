import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:amial_pay/data/api/api_client.dart';
import 'package:amial_pay/theme/amial_colors.dart';

/// يعرض الوثائق القانونية المنشورة من الخادم، لا قيمة HTML قديمة قد تكون فارغة.
class PublicLegalDocumentScreen extends StatefulWidget {
  final String title;
  final String slug;

  const PublicLegalDocumentScreen({
    super.key,
    required this.title,
    required this.slug,
  });

  @override
  State<PublicLegalDocumentScreen> createState() =>
      _PublicLegalDocumentScreenState();
}

class _PublicLegalDocumentScreenState extends State<PublicLegalDocumentScreen> {
  String? _content;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _content = null;
      _error = null;
    });
    try {
      final response = await Get.find<ApiClient>()
          .getData('/api/v1/amial/legal-docs/' + widget.slug);
      final body = response.body;
      final meta = body is Map ? body['meta'] : null;
      final content = meta is Map ? meta['content']?.toString().trim() : '';
      if (response.statusCode == 200 && content != null && content.isNotEmpty) {
        if (mounted) setState(() => _content = content);
      } else if (mounted) {
        setState(() => _error = 'تعذر تحميل الوثيقة الآن.');
      }
    } catch (_) {
      if (mounted) setState(() => _error = 'تعذر الاتصال لتحميل الوثيقة.');
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AmialColors.background,
      appBar: AppBar(title: Text(widget.title)),
      body: _content == null && _error == null
          ? const Center(child: CircularProgressIndicator())
          : _error != null
              ? Center(
                  child: Padding(
                    padding: const EdgeInsets.all(24),
                    child: Column(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        const Icon(Icons.description_outlined,
                            size: 44, color: AmialColors.textMuted),
                        const SizedBox(height: 12),
                        Text(_error!, textAlign: TextAlign.center),
                        const SizedBox(height: 12),
                        OutlinedButton.icon(
                          onPressed: _load,
                          icon: const Icon(Icons.refresh_rounded),
                          label: const Text('إعادة المحاولة'),
                        ),
                      ],
                    ),
                  ),
                )
              : ListView(
                  padding: const EdgeInsets.all(20),
                  children: _content!
                      .split('\n')
                      .where((line) => line.trim().isNotEmpty)
                      .map(_line)
                      .toList(),
                ),
    );
  }

  Widget _line(String source) {
    final line = source.trim();
    final level = line.startsWith('###')
        ? 3
        : line.startsWith('##')
            ? 2
            : line.startsWith('#')
                ? 1
                : 0;
    final text = line
        .replaceFirst(RegExp(r'^#{1,3}\s*'), '')
        .replaceAll('**', '')
        .replaceFirst(RegExp(r'^-\s*'), '• ');
    if (level > 0) {
      return Padding(
        padding: const EdgeInsets.only(top: 18, bottom: 8),
        child: Text(
          text,
          style: TextStyle(
            fontSize: level == 1 ? 21 : 17,
            fontWeight: FontWeight.w800,
            color: AmialColors.textPrimary,
          ),
        ),
      );
    }
    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: SelectableText(
        text,
        textAlign: TextAlign.start,
        style: const TextStyle(
          height: 1.7,
          color: AmialColors.textSecondary,
        ),
      ),
    );
  }
}
