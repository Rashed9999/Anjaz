import 'dart:io';

import 'package:flutter/material.dart';
import 'package:share_plus/share_plus.dart';
import 'package:url_launcher/url_launcher.dart';

/// AMIAL-INVOICE-WHATSAPP-001 — مشاركة الفاتورة لا تعني الإرسال التلقائي.
///
/// يفتح الكاشير محادثة واتساب مع رسالة جاهزة بعد إدخال الرقم، أو يفتح
/// نظام مشاركة الهاتف ليرفق ملف الفاتورة بنفسه. لا نخزّن رقم العميل هنا.
class InvoiceWhatsAppSheet extends StatefulWidget {
  const InvoiceWhatsAppSheet({
    super.key,
    required this.message,
    required this.invoiceNumber,
    this.initialPhone,
    this.captureFile,
  });

  final String message;
  final String invoiceNumber;
  final String? initialPhone;
  final Future<File?> Function()? captureFile;

  static Future<void> open(
    BuildContext context, {
    required String message,
    required String invoiceNumber,
    String? initialPhone,
    Future<File?> Function()? captureFile,
  }) {
    return showModalBottomSheet<void>(
      context: context,
      useSafeArea: true,
      isScrollControlled: true,
      backgroundColor: Colors.white,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
      ),
      builder: (_) => InvoiceWhatsAppSheet(
        message: message,
        invoiceNumber: invoiceNumber,
        initialPhone: initialPhone,
        captureFile: captureFile,
      ),
    );
  }

  @override
  State<InvoiceWhatsAppSheet> createState() => _InvoiceWhatsAppSheetState();
}

class _InvoiceWhatsAppSheetState extends State<InvoiceWhatsAppSheet> {
  late final TextEditingController _phone;
  bool _opening = false;
  bool _sharing = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _phone = TextEditingController(text: widget.initialPhone ?? '');
  }

  @override
  void dispose() {
    _phone.dispose();
    super.dispose();
  }

  String? _normalizeYemenPhone(String raw) {
    var number = raw.replaceAll(RegExp(r'[^0-9]'), '');
    if (number.startsWith('00')) number = number.substring(2);
    if (number.startsWith('0')) number = number.substring(1);
    if (number.length == 9) number = '967$number';
    // اليمن: 967 + تسعة أرقام. لا نفتعل رقماً صحيحاً من إدخال ناقص.
    if (!RegExp(r'^967\d{9}$').hasMatch(number)) return null;
    return number;
  }

  Future<void> _openChat() async {
    final phone = _normalizeYemenPhone(_phone.text);
    if (phone == null) {
      setState(() => _error = 'أدخل رقم جوال يمني صحيحاً، مثل 77xxxxxxx');
      return;
    }
    setState(() { _opening = true; _error = null; });
    try {
      final uri = Uri.parse('https://wa.me/$phone?text=${Uri.encodeComponent(widget.message)}');
      final opened = await launchUrl(uri, mode: LaunchMode.externalApplication);
      if (!opened && mounted) setState(() => _error = 'تعذّر فتح واتساب. تأكّد أنه مثبت على الجهاز.');
    } catch (_) {
      if (mounted) setState(() => _error = 'تعذّر فتح واتساب. حاول مرة أخرى.');
    } finally {
      if (mounted) setState(() => _opening = false);
    }
  }

  Future<void> _shareAttachment() async {
    if (widget.captureFile == null) return;
    setState(() { _sharing = true; _error = null; });
    try {
      final file = await widget.captureFile!();
      if (file == null) throw StateError('capture');
      await Share.shareXFiles(
        [XFile(file.path, mimeType: 'image/png')],
        text: widget.message,
        subject: widget.invoiceNumber,
      );
    } catch (_) {
      if (mounted) setState(() => _error = 'تعذّر تجهيز صورة الفاتورة للمشاركة.');
    } finally {
      if (mounted) setState(() => _sharing = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final bottom = MediaQuery.viewInsetsOf(context).bottom;
    return Padding(
      padding: EdgeInsets.fromLTRB(20, 12, 20, bottom + 20),
      child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        const Center(child: SizedBox(width: 40, child: Divider(thickness: 4))),
        const SizedBox(height: 10),
        const Text('مشاركة عبر واتساب', textAlign: TextAlign.center,
            style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold)),
        const SizedBox(height: 4),
        const Text('افتح محادثة العميل برسالة الفاتورة. إرفاق الملف يتم عبر قائمة المشاركة.',
            textAlign: TextAlign.center, style: TextStyle(fontSize: 12, color: Color(0xFF677186))),
        const SizedBox(height: 18),
        TextField(
          controller: _phone,
          keyboardType: TextInputType.phone,
          textDirection: TextDirection.ltr,
          autofocus: true,
          decoration: InputDecoration(
            labelText: 'رقم جوال العميل',
            hintText: '77xxxxxxx',
            prefixIcon: const Icon(Icons.phone_outlined),
            errorText: _error,
            border: const OutlineInputBorder(),
          ),
          onChanged: (_) { if (_error != null) setState(() => _error = null); },
        ),
        const SizedBox(height: 12),
        FilledButton.icon(
          onPressed: _opening ? null : _openChat,
          icon: _opening
              ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
              : const Icon(Icons.arrow_forward_rounded),
          label: const Text('فتح محادثة واتساب'),
          style: FilledButton.styleFrom(backgroundColor: const Color(0xFF25D366), minimumSize: const Size.fromHeight(52)),
        ),
        if (widget.captureFile != null) ...[
          const SizedBox(height: 8),
          OutlinedButton.icon(
            onPressed: _sharing ? null : _shareAttachment,
            icon: _sharing
                ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2))
                : const Icon(Icons.attach_file_rounded),
            label: const Text('مشاركة صورة الفاتورة'),
          ),
        ],
      ]),
    );
  }
}
