# ملحق — سجل العناصر التفاعلية: وحدة `printer`

> **مولَّد آليًّا** بتاريخ 2026-11-15 من شجرة `1a1d8c65` (مطابقة المحتوى للفرع الافتراضي `claude/project-code-review-yjagv@751ea393`). دليل **ساكن** فقط: لا يثبت تشغيلًا ولا ضغط زر ولا حالة إنتاج. المعرّفات مشتقة من تجزئة ثابتة (لا تتغير بإضافة صفوف). لا يُحذف شيء استنادًا إلى هذا الملف.

استخراج آلي بالتعابير النمطية من مصدر Dart: نوع الودجت، مفتاح/نص التسمية القريب، وترجمته من `assets/language/ar.json` إن وُجد المفتاح. **لا يثبت** الشروط الفعلية ولا رد الخادم ولا حالات التحميل؛ كل صف `INTERACTION_UNVERIFIED`. الأعمدة الناقصة عن القالب المعياري (التحقق/رد النجاح/رد الخطأ) تُملأ عند الفحص التفاعلي — انظر المهمة `PAY-T-012` في `plan.md`.


### `lib/features/printer/screens/printer_settings_screen.dart` — PAY-SCR-779CAA — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-0C6252 | زر | `IconButton` | `printer_refresh` | تحديث | onPressed شرطي | 142 |
| PAY-UI-177332 | زر | `TextButton` | `printer_open_system_settings` | فتح الإعدادات | onPressed | 160 |
| PAY-UI-9C3497 | زر | `OutlinedButton` | `printer_save_network` | حفظ طابعة الشبكة | onPressed | 176 |
| PAY-UI-7B9908 | زر | `IconButton` | `printer_remove` | إزالة | onPressed | 204 |
| PAY-UI-722990 | زر | `FilledButton` | `printer_printing` | جارٍ الطباعة… | onPressed | 275 |
| PAY-UI-9B03F1 | زر | `FilledButton` | — | — | onTap | 281 |
| PAY-UI-463543 | عنصر قابل للنقر | `ListTile` | `printer_default_name` | طابعة | — | 261 |
| PAY-UI-E4124F | عنصر قابل للنقر | `InkWell` | — | — | onTap | 293 |
| PAY-UI-44ED75 | حقل إدخال | `DropdownButtonFormField` | `printer_connection_method` | طريقة الاتصال | onChanged | 164 |
| PAY-UI-E205C6 | حقل إدخال | `TextField` | `printer_ip_address` | عنوان IP للطابعة | — | 172 |
| PAY-UI-3AD813 | حقل إدخال | `TextField` | `printer_port` | المنفذ | onPressed | 174 |
