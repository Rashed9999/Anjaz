# ملحق — سجل العناصر التفاعلية: وحدة `amial`

> **مولَّد آليًّا** بتاريخ 2026-11-15 من شجرة `1a1d8c65` (مطابقة المحتوى للفرع الافتراضي `claude/project-code-review-yjagv@751ea393`). دليل **ساكن** فقط: لا يثبت تشغيلًا ولا ضغط زر ولا حالة إنتاج. المعرّفات مشتقة من تجزئة ثابتة (لا تتغير بإضافة صفوف). لا يُحذف شيء استنادًا إلى هذا الملف.

استخراج آلي بالتعابير النمطية من مصدر Dart: نوع الودجت، مفتاح/نص التسمية القريب، وترجمته من `assets/language/ar.json` إن وُجد المفتاح. **لا يثبت** الشروط الفعلية ولا رد الخادم ولا حالات التحميل؛ كل صف `INTERACTION_UNVERIFIED`. الأعمدة الناقصة عن القالب المعياري (التحقق/رد النجاح/رد الخطأ) تُملأ عند الفحص التفاعلي — انظر المهمة `PAY-T-012` في `plan.md`.


### `lib/features/amial/screens/account_recovery_screen.dart` — PAY-SCR-929665 — **غير موصول (UNREACHABLE)**

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-4334CC | زر | `TextButton` | `حسناً` | — | onPressed | 146 |
| PAY-UI-D189CB | زر | `ElevatedButton` | `إرسال OTP` | — | onPressed شرطي | 282 |
| PAY-UI-E3A9F0 | زر | `ElevatedButton` | `تحقق` | — | onPressed شرطي | 328 |
| PAY-UI-380273 | زر | `ElevatedButton` | `إتمام التغيير` | — | onPressed شرطي | 366 |
| PAY-UI-B63CDD | زر | `ElevatedButton` | — | — | — | 393 |
| PAY-UI-3DAFC9 | حقل إدخال | `TextFormField` | `الرقم الجديد` | — | — | 264 |
| PAY-UI-AD50DD | حقل إدخال | `PinCodeTextField` | `OTP من الرقم الجديد:` | — | onChanged | 308 |
| PAY-UI-265F9F | حقل إدخال | `PinCodeTextField` | — | — | onChanged | 319 |
| PAY-UI-33BD35 | حقل إدخال | `PinCodeTextField` | — | — | onChanged | 356 |
| PAY-UI-79D3FC | نافذة/ورقة | `showDialog` | `تم قبول طلبك` | — | استدعاء | 128 |

### `lib/features/amial/screens/terms_acceptance_screen.dart` — PAY-SCR-C04782 — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-EA56CF | زر | `TextButton` | `إعادة المحاولة` | — | onPressed | 131 |
| PAY-UI-363FE3 | زر | `ElevatedButton` | — | — | onPressed شرطي | 275 |
| PAY-UI-1238A2 | زر | `ElevatedButton` | — | — | — | 278 |
| PAY-UI-EEC8D5 | حقل إدخال | `CheckboxListTile` | — | — | onChanged | 261 |
