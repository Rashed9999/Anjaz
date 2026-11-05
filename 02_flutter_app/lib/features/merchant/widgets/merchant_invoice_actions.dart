import 'package:flutter/material.dart';
import 'package:amial_pay/theme/amial_colors.dart';

/// AMIAL-MERCHANT-INVOICE-ACTIONS-001
///
/// أزرار ما بعد البيع الموحدة لكل القطاعات. لا تنفّذ منطقاً مالياً بنفسها؛
/// القطاع يمرّر دواله الموصولة بسنده الحقيقي. بهذا لا تختلف نتيجة البيع بين
/// صيدلية أو تجزئة أو وقود أو جملة، بينما يظل إدخال أصناف كل قطاع خاصاً به.
class MerchantInvoiceActions extends StatelessWidget {
  const MerchantInvoiceActions({
    super.key,
    required this.onPrint,
    required this.onWhatsApp,
    required this.onPdf,
    this.busy = false,
  });

  final VoidCallback? onPrint;
  final VoidCallback? onWhatsApp;
  final VoidCallback? onPdf;
  final bool busy;

  @override
  Widget build(BuildContext context) => Column(children: [
        Row(children: [
          Expanded(child: OutlinedButton.icon(
            onPressed: busy ? null : onPrint,
            icon: const Icon(Icons.print_outlined, size: 20),
            label: const Text('طباعة حرارية'),
            style: OutlinedButton.styleFrom(
              foregroundColor: AmialColors.primary,
              side: const BorderSide(color: AmialColors.primary),
              minimumSize: const Size.fromHeight(50),
            ),
          )),
          const SizedBox(width: 10),
          Expanded(child: OutlinedButton.icon(
            onPressed: busy ? null : onPdf,
            icon: const Icon(Icons.picture_as_pdf_outlined, size: 20),
            label: const Text('تنزيل PDF'),
            style: OutlinedButton.styleFrom(
              foregroundColor: AmialColors.red,
              side: const BorderSide(color: AmialColors.red),
              minimumSize: const Size.fromHeight(50),
            ),
          )),
        ]),
        const SizedBox(height: 10),
        SizedBox(width: double.infinity, child: FilledButton.icon(
          onPressed: busy ? null : onWhatsApp,
          icon: busy
              ? const SizedBox(width: 18, height: 18,
                  child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
              : const Icon(Icons.chat_outlined, size: 20),
          label: const Text('مشاركة الفاتورة عبر واتساب'),
          style: FilledButton.styleFrom(
            backgroundColor: const Color(0xFF25D366),
            minimumSize: const Size.fromHeight(50),
          ),
        )),
      ]);
}
