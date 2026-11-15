# ملحق — سجل العناصر التفاعلية: وحدة `bill_pay`

> **مولَّد آليًّا** بتاريخ 2026-11-15 من شجرة `1a1d8c65` (مطابقة المحتوى للفرع الافتراضي `claude/project-code-review-yjagv@751ea393`). دليل **ساكن** فقط: لا يثبت تشغيلًا ولا ضغط زر ولا حالة إنتاج. المعرّفات مشتقة من تجزئة ثابتة (لا تتغير بإضافة صفوف). لا يُحذف شيء استنادًا إلى هذا الملف.

استخراج آلي بالتعابير النمطية من مصدر Dart: نوع الودجت، مفتاح/نص التسمية القريب، وترجمته من `assets/language/ar.json` إن وُجد المفتاح. **لا يثبت** الشروط الفعلية ولا رد الخادم ولا حالات التحميل؛ كل صف `INTERACTION_UNVERIFIED`. الأعمدة الناقصة عن القالب المعياري (التحقق/رد النجاح/رد الخطأ) تُملأ عند الفحص التفاعلي — انظر المهمة `PAY-T-012` في `plan.md`.


### `lib/features/bill_pay/screens/bill_pay_form_screen.dart` — PAY-SCR-182D41 — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-5DB14A | زر | `ElevatedButton` | — | — | onPressed شرطي | 229 |
| PAY-UI-412ABB | زر | `ElevatedButton` | — | — | — | 231 |
| PAY-UI-F249E7 | حقل إدخال | `TextFormField` | — | — | — | 143 |
| PAY-UI-3B6FDD | حقل إدخال | `TextFormField` | `المبلغ (ر.ي) *` | — | — | 195 |

### `lib/features/bill_pay/screens/bill_pay_history_screen.dart` — PAY-SCR-93EFB7 — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-4430E6 | زر | `OutlinedButton` | — | — | onPressed | 301 |

### `lib/features/bill_pay/screens/bill_pay_providers_screen.dart` — PAY-SCR-62148E — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-09A2FE | زر | `IconButton` | `عمليات السداد` | — | onPressed | 32 |
| PAY-UI-E47CA9 | عنصر قابل للنقر | `InkWell` | — | — | onTap | 88 |
