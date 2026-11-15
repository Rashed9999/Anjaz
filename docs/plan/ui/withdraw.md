# ملحق — سجل العناصر التفاعلية: وحدة `withdraw`

> **مولَّد آليًّا** بتاريخ 2026-11-15 من شجرة `1a1d8c65` (مطابقة المحتوى للفرع الافتراضي `claude/project-code-review-yjagv@751ea393`). دليل **ساكن** فقط: لا يثبت تشغيلًا ولا ضغط زر ولا حالة إنتاج. المعرّفات مشتقة من تجزئة ثابتة (لا تتغير بإضافة صفوف). لا يُحذف شيء استنادًا إلى هذا الملف.

استخراج آلي بالتعابير النمطية من مصدر Dart: نوع الودجت، مفتاح/نص التسمية القريب، وترجمته من `assets/language/ar.json` إن وُجد المفتاح. **لا يثبت** الشروط الفعلية ولا رد الخادم ولا حالات التحميل؛ كل صف `INTERACTION_UNVERIFIED`. الأعمدة الناقصة عن القالب المعياري (التحقق/رد النجاح/رد الخطأ) تُملأ عند الفحص التفاعلي — انظر المهمة `PAY-T-012` في `plan.md`.


### `lib/features/withdraw/screens/withdraw_history_screen.dart` — PAY-SCR-CBB121 — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-C03594 | حقل إدخال | `TextField` | `ابحث عن رقم العملية...` | — | onChanged | 103 |

### `lib/features/withdraw/screens/withdraw_pending_screen.dart` — PAY-SCR-B3BA85 — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-E3CB56 | زر | `FilledButton` | `تراجع` | تراجع | onPressed | 116 |
| PAY-UI-6EF79D | زر | `FilledButton` | `تراجع` | تراجع | onPressed | 118 |
| PAY-UI-046D99 | زر | `OutlinedButton` | `نعم، إلغاء العملية` | — | onPressed | 127 |
| PAY-UI-BC90CB | زر | `OutlinedButton` | — | — | — | 131 |
| PAY-UI-0B75EB | زر | `IconButton` | — | — | onPressed | 189 |
| PAY-UI-49BBF5 | زر | `OutlinedButton` | `نسخ الرقم` | — | onPressed شرطي | 309 |
| PAY-UI-728CAD | زر | `OutlinedButton` | — | — | onPressed | 313 |
| PAY-UI-735235 | زر | `FilledButton` | `إلغاء` | إلغاء | onPressed شرطي | 322 |
| PAY-UI-CFD1ED | زر | `FilledButton` | — | — | — | 326 |
| PAY-UI-E13C5A | نافذة/ورقة | `showModalBottomSheet` | — | — | استدعاء | 71 |

### `lib/features/withdraw/screens/withdraw_request_screen.dart` — PAY-SCR-235520 — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-CAB53D | زر | `IconButton` | `سجل طلبات السحب` | — | onPressed | 104 |
| PAY-UI-86C275 | زر | `AmialButton` | `تأكيد طلب السحب` | — | onPressed شرطي | 289 |
| PAY-UI-64BE0F | عنصر قابل للنقر | `InkWell` | — | — | onTap | 308 |
| PAY-UI-A76A8A | حقل إدخال | `TextField` | — | — | — | 161 |
