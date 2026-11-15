# ملحق — سجل العناصر التفاعلية: وحدة `credit`

> **مولَّد آليًّا** بتاريخ 2026-11-15 من شجرة `1a1d8c65` (مطابقة المحتوى للفرع الافتراضي `claude/project-code-review-yjagv@751ea393`). دليل **ساكن** فقط: لا يثبت تشغيلًا ولا ضغط زر ولا حالة إنتاج. المعرّفات مشتقة من تجزئة ثابتة (لا تتغير بإضافة صفوف). لا يُحذف شيء استنادًا إلى هذا الملف.

استخراج آلي بالتعابير النمطية من مصدر Dart: نوع الودجت، مفتاح/نص التسمية القريب، وترجمته من `assets/language/ar.json` إن وُجد المفتاح. **لا يثبت** الشروط الفعلية ولا رد الخادم ولا حالات التحميل؛ كل صف `INTERACTION_UNVERIFIED`. الأعمدة الناقصة عن القالب المعياري (التحقق/رد النجاح/رد الخطأ) تُملأ عند الفحص التفاعلي — انظر المهمة `PAY-T-012` في `plan.md`.


### `lib/features/credit/screens/my_credits_screen.dart` — PAY-SCR-43635A — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-468D0A | زر | `TextButton` | `إلغاء` | إلغاء | onPressed | 189 |
| PAY-UI-01D89F | زر | `FilledButton` | `credit_settle_continue` | متابعة | onPressed | 190 |
| PAY-UI-0C308B | زر | `TextButton` | `إلغاء` | إلغاء | onPressed | 375 |
| PAY-UI-21B425 | زر | `FilledButton` | `credit_settle_continue` | متابعة | onPressed | 376 |
| PAY-UI-928F1B | زر | `TextButton` | `credit_collect_close` | إغلاق | onPressed | 432 |
| PAY-UI-48F8DC | زر | `FilledButton` | `credit_settle_open_receipt` | عرض سند السداد | onPressed | 436 |
| PAY-UI-986690 | زر | `FilledButton` | `سداد من محفظتي` | — | onPressed | 487 |
| PAY-UI-051DD1 | زر | `FilledButton` | `الفواتير المستحقة` | — | — | 491 |
| PAY-UI-D944E6 | زر | `IconButton` | `عرض الفاتورة PDF` | — | onPressed شرطي | 523 |
| PAY-UI-D6A926 | زر | `IconButton` | `عرض الفاتورة PDF` | — | onPressed شرطي | 561 |
| PAY-UI-E9DBD5 | عنصر قابل للنقر | `ListTile` | — | — | — | 136 |
| PAY-UI-079658 | عنصر قابل للنقر | `ListTile` | — | — | — | 230 |
| PAY-UI-22A732 | عنصر قابل للنقر | `ListTile` | `عرض الفاتورة PDF` | — | onPressed شرطي | 521 |
| PAY-UI-078A63 | عنصر قابل للنقر | `ListTile` | `عرض الفاتورة PDF` | — | onPressed شرطي | 559 |
| PAY-UI-319032 | حقل إدخال | `TextField` | `مبلغ السداد` | — | — | 178 |
| PAY-UI-128AFF | حقل إدخال | `TextField` | `مبلغ السداد` | — | — | 364 |
| PAY-UI-E895A4 | نافذة/ورقة | `showDialog` | `credit_business_pay_title` | سداد دين لدى @merchant | استدعاء | 170 |
| PAY-UI-C1F325 | نافذة/ورقة | `showDialog` | — | — | استدعاء | 356 |
| PAY-UI-A2C1D8 | نافذة/ورقة | `showDialog` | `credit_settle_success_title` | تم سداد الآجل بنجاح | استدعاء | 412 |
