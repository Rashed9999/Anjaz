# ملحق — سجل العناصر التفاعلية: وحدة `receipts`

> **مولَّد آليًّا** بتاريخ 2026-11-15 من شجرة `1a1d8c65` (مطابقة المحتوى للفرع الافتراضي `claude/project-code-review-yjagv@751ea393`). دليل **ساكن** فقط: لا يثبت تشغيلًا ولا ضغط زر ولا حالة إنتاج. المعرّفات مشتقة من تجزئة ثابتة (لا تتغير بإضافة صفوف). لا يُحذف شيء استنادًا إلى هذا الملف.

استخراج آلي بالتعابير النمطية من مصدر Dart: نوع الودجت، مفتاح/نص التسمية القريب، وترجمته من `assets/language/ar.json` إن وُجد المفتاح. **لا يثبت** الشروط الفعلية ولا رد الخادم ولا حالات التحميل؛ كل صف `INTERACTION_UNVERIFIED`. الأعمدة الناقصة عن القالب المعياري (التحقق/رد النجاح/رد الخطأ) تُملأ عند الفحص التفاعلي — انظر المهمة `PAY-T-012` في `plan.md`.


### `lib/features/receipts/screens/receipt_detail_screen.dart` — PAY-SCR-1E7717 — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-2461D3 | زر | `IconButton` | — | — | onPressed | 382 |
| PAY-UI-7DBB11 | عنصر قابل للنقر | `InkWell` | — | — | onTap | 299 |
| PAY-UI-B3E58E | عنصر قابل للنقر | `InkWell` | — | — | onTap | 606 |
| PAY-UI-1062A4 | عنصر قابل للنقر | `InkWell` | `نُسخ الرقم` | — | onTap | 696 |
| PAY-UI-1F3129 | نافذة/ورقة | `showModalBottomSheet` | — | — | استدعاء | 215 |

### `lib/features/receipts/screens/receipts_list_screen.dart` — PAY-SCR-8F7F05 — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-F5B409 | زر | `OutlinedButton` | `receipts_filter_from_date` | من تاريخ | onPressed | 167 |
| PAY-UI-4AD30D | زر | `OutlinedButton` | `receipts_filter_to_date` | إلى تاريخ | onPressed | 177 |
| PAY-UI-FF3BB4 | زر | `OutlinedButton` | `receipts_filter_reset` | مسح | onPressed | 213 |
| PAY-UI-0D86A0 | زر | `ElevatedButton` | — | — | onPressed | 227 |
| PAY-UI-BC317C | زر | `ElevatedButton` | `receipts_filter_apply` | تطبيق | onPressed | 229 |
| PAY-UI-0365F1 | زر | `IconButton` | `receipts_filter_tooltip` | تصفية | onPressed | 269 |
| PAY-UI-086C11 | زر | `IconButton` | — | — | onPressed | 308 |
| PAY-UI-247AF5 | زر | `TextButton` | — | — | onPressed | 373 |
| PAY-UI-88C7A2 | زر | `TextButton` | `receipts_retry` | إعادة المحاولة | onPressed | 384 |
| PAY-UI-B5B19B | عنصر قابل للنقر | `ListTile` | — | — | — | 425 |
| PAY-UI-6F1885 | حقل إدخال | `DropdownButtonFormField` | `receipts_filter_type` | نوع العملية | — | 147 |
| PAY-UI-A0233F | حقل إدخال | `TextField` | `receipts_filter_min_amount` | أقل مبلغ | — | 190 |
| PAY-UI-5B92F2 | حقل إدخال | `TextField` | `receipts_filter_max_amount` | أعلى مبلغ | — | 200 |
| PAY-UI-F39EAF | حقل إدخال | `TextField` | `receipts_search_hint` | ابحث برقم الهاتف أو الاسم أو رقم العملية | onChanged | 298 |
| PAY-UI-9A0C9A | نافذة/ورقة | `showModalBottomSheet` | — | — | استدعاء | 114 |
| PAY-UI-B0511C | نافذة/ورقة | `showDatePicker` | — | — | استدعاء | 122 |
