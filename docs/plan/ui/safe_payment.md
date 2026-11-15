# ملحق — سجل العناصر التفاعلية: وحدة `safe_payment`

> **مولَّد آليًّا** بتاريخ 2026-11-15 من شجرة `1a1d8c65` (مطابقة المحتوى للفرع الافتراضي `claude/project-code-review-yjagv@751ea393`). دليل **ساكن** فقط: لا يثبت تشغيلًا ولا ضغط زر ولا حالة إنتاج. المعرّفات مشتقة من تجزئة ثابتة (لا تتغير بإضافة صفوف). لا يُحذف شيء استنادًا إلى هذا الملف.

استخراج آلي بالتعابير النمطية من مصدر Dart: نوع الودجت، مفتاح/نص التسمية القريب، وترجمته من `assets/language/ar.json` إن وُجد المفتاح. **لا يثبت** الشروط الفعلية ولا رد الخادم ولا حالات التحميل؛ كل صف `INTERACTION_UNVERIFIED`. الأعمدة الناقصة عن القالب المعياري (التحقق/رد النجاح/رد الخطأ) تُملأ عند الفحص التفاعلي — انظر المهمة `PAY-T-012` في `plan.md`.


### `lib/features/safe_payment/screens/create_safe_payment_screen.dart` — PAY-SCR-BF6116 — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-5DEF3D | زر | `TextButton` | `إلغاء` | إلغاء | onPressed | 96 |
| PAY-UI-529C14 | زر | `ElevatedButton` | `تأكيد ودفع` | — | onPressed | 100 |
| PAY-UI-ED9168 | زر | `ElevatedButton` | `تأكيد ودفع` | — | — | 102 |
| PAY-UI-936C7A | زر | `TextButton` | — | — | onPressed | 231 |
| PAY-UI-BBEEB1 | زر | `ElevatedButton` | — | — | onPressed شرطي | 315 |
| PAY-UI-5222DE | زر | `ElevatedButton` | `يمكنك إلغاء الطلب وسحب أموالك قبل بدء التسليم` | — | — | 325 |
| PAY-UI-E66905 | حقل إدخال | `TextFormField` | `رقم جوال البائع *` | — | — | 193 |
| PAY-UI-036CFF | حقل إدخال | `TextFormField` | `عنوان السلعة/الخدمة *` | — | — | 249 |
| PAY-UI-A12F0B | حقل إدخال | `TextFormField` | `الوصف التفصيلي *` | — | — | 266 |
| PAY-UI-57BA43 | حقل إدخال | `TextFormField` | `المبلغ (ر.ي) *` | — | — | 284 |
| PAY-UI-E8FE1D | حقل إدخال | `TextFormField` | `شروط التسليم (اختياري)` | — | — | 301 |
| PAY-UI-632CFE | نافذة/ورقة | `showDialog` | `تأكيد العملية` | — | استدعاء | 52 |

### `lib/features/safe_payment/screens/my_safe_payments_screen.dart` — PAY-SCR-E35187 — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-1FDED5 | زر | `FloatingActionButton` | `طلب دفع آمن` | — | onPressed | 55 |
| PAY-UI-161CD1 | زر | `OutlinedButton` | `إعادة المحاولة` | — | onPressed | 82 |
| PAY-UI-BDAFC5 | عنصر قابل للنقر | `ListTile` | — | — | — | 139 |
| PAY-UI-08C8D0 | تبويب | `TabBar` | — | — | — | 43 |

### `lib/features/safe_payment/screens/safe_payment_detail_screen.dart` — PAY-SCR-B7C913 — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-0BB5AD | زر | `TextButton` | `إلغاء` | إلغاء | onPressed | 61 |
| PAY-UI-C7C27D | زر | `ElevatedButton` | — | — | onPressed | 64 |
| PAY-UI-D10807 | زر | `ElevatedButton` | `تأكيد` | — | — | 72 |
| PAY-UI-181CF0 | زر | `TextButton` | `إلغاء` | إلغاء | onPressed | 96 |
| PAY-UI-D750A6 | زر | `ElevatedButton` | `تأكيد` | — | onPressed | 97 |
| PAY-UI-0FD0A0 | زر | `ElevatedButton` | `تأكيد` | — | — | 99 |
| PAY-UI-22F2F0 | زر | `OutlinedButton` | `إعادة المحاولة` | — | onPressed | 140 |
| PAY-UI-8AED4C | زر | `OutlinedButton` | — | — | onPressed | 396 |
| PAY-UI-3A7302 | زر | `OutlinedButton` | — | — | — | 401 |
| PAY-UI-F8F561 | زر | `ElevatedButton` | — | — | onPressed | 532 |
| PAY-UI-79A232 | زر | `ElevatedButton` | — | — | — | 536 |
| PAY-UI-939DD9 | حقل إدخال | `TextFormField` | — | — | — | 46 |
| PAY-UI-6A1D6A | نافذة/ورقة | `showDialog` | — | — | استدعاء | 40 |
| PAY-UI-3372DC | نافذة/ورقة | `showDialog` | `إلغاء` | إلغاء | onPressed | 90 |

### `lib/features/safe_payment/widgets/delivery_code_card.dart` — — — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-CF449F | زر | `ElevatedButton` | — | — | onPressed شرطي | 200 |
| PAY-UI-3BD93B | زر | `ElevatedButton` | `تأكيد` | — | — | 202 |
| PAY-UI-F4AB27 | عنصر قابل للنقر | `InkWell` | `نُسخ الرمز` | — | onTap | 47 |
| PAY-UI-554F8E | حقل إدخال | `TextField` | — | — | — | 179 |

### `lib/features/safe_payment/widgets/dispute_sheet.dart` — — — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-AC8AAC | زر | `ElevatedButton` | — | — | onPressed شرطي | 210 |
| PAY-UI-54FD75 | زر | `ElevatedButton` | — | — | — | 220 |
| PAY-UI-750EE3 | حقل إدخال | `TextField` | `ماذا حدث بالضبط؟` | — | — | 166 |
| PAY-UI-B499AE | نافذة/ورقة | `showModalBottomSheet` | — | — | استدعاء | 25 |

### `lib/features/safe_payment/widgets/evidence_gallery.dart` — PAY-SCR-41A2EA — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-27E184 | عنصر قابل للنقر | `InkWell` | — | — | onTap | 129 |

### `lib/features/safe_payment/widgets/evidence_picker_sheet.dart` — — — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-E6FD5B | زر | `OutlinedButton` | `كاميرا` | — | onPressed | 200 |
| PAY-UI-3C9E1A | زر | `OutlinedButton` | `المعرض` | — | onPressed | 210 |
| PAY-UI-FC6EBB | زر | `ElevatedButton` | — | — | onPressed شرطي | 286 |
| PAY-UI-259D7B | زر | `ElevatedButton` | — | — | — | 295 |
| PAY-UI-E5D1A6 | عنصر قابل للنقر | `InkWell` | — | — | onTap شرطي | 248 |
| PAY-UI-8A40D5 | حقل إدخال | `TextField` | `ملاحظة (اختيارية)` | — | — | 265 |
| PAY-UI-67022D | نافذة/ورقة | `showModalBottomSheet` | — | — | استدعاء | 41 |
