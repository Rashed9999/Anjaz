import 'package:flutter/material.dart';
import 'package:get/get.dart';

/// AMIAL-MERCHANT-PAY-002 — نافذةُ رمز المعاملات، واحدةً لكلّ أبواب الدفع.
///
/// ══════════════════════════════════════════════════════════════════════
/// **ولمَ ملفٌّ واحدٌ لا نسخةٌ في كلّ شاشة:**
///
/// لأبواب الدفع أكثرُ من مدخل: مسحُ رمز، ورقمُ حسابٍ وفاتورة، وقائمةُ
/// الطلبات الواردة. ونسخُ النافذة في كلٍّ يعني أنّ تحسيناً في واحدةٍ لا
/// يبلغ الباقي — **وأنّ باباً يُنسى فيبقى بلا رمز**، وهو أخطرُ ما يقع.
///
/// وحاجزٌ على بابٍ من ثلاثةٍ ليس حاجزاً.
Future<String?> askPin(BuildContext context, {String? title, String? subtitle}) {
  final ctrl = TextEditingController();

  return Get.dialog<String>(
    AlertDialog(
      title: Text(title ?? 'رمز الحماية'),
      content: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          if (subtitle != null) ...[
            Text(subtitle, textAlign: TextAlign.center,
                style: const TextStyle(fontSize: 13)),
            const SizedBox(height: 12),
          ],
          TextField(
            controller: ctrl,
            autofocus: true,
            obscureText: true,
            keyboardType: TextInputType.number,
            maxLength: 4,
            textAlign: TextAlign.center,
            style: const TextStyle(fontSize: 24, letterSpacing: 12),
            decoration: const InputDecoration(
              counterText: '',
              hintText: '••••',
              border: OutlineInputBorder(),
            ),
          ),
        ],
      ),
      actions: [
        TextButton(onPressed: () => Get.back(), child: const Text('إلغاء')),
        FilledButton(
          onPressed: () {
            final v = ctrl.text.trim();
            // أربعةُ أرقامٍ شرطُ الخادم — فيُقال هنا بدل أن يُردّ ٤٢٢ بعد
            // انتظارِ شبكة.
            if (v.length != 4) return;
            Get.back(result: v);
          },
          child: const Text('تأكيد'),
        ),
      ],
    ),
    barrierDismissible: false,
  );
}
