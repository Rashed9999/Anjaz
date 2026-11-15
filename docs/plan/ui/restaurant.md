# ملحق — سجل العناصر التفاعلية: وحدة `restaurant`

> **مولَّد آليًّا** بتاريخ 2026-11-15 من شجرة `1a1d8c65` (مطابقة المحتوى للفرع الافتراضي `claude/project-code-review-yjagv@751ea393`). دليل **ساكن** فقط: لا يثبت تشغيلًا ولا ضغط زر ولا حالة إنتاج. المعرّفات مشتقة من تجزئة ثابتة (لا تتغير بإضافة صفوف). لا يُحذف شيء استنادًا إلى هذا الملف.

استخراج آلي بالتعابير النمطية من مصدر Dart: نوع الودجت، مفتاح/نص التسمية القريب، وترجمته من `assets/language/ar.json` إن وُجد المفتاح. **لا يثبت** الشروط الفعلية ولا رد الخادم ولا حالات التحميل؛ كل صف `INTERACTION_UNVERIFIED`. الأعمدة الناقصة عن القالب المعياري (التحقق/رد النجاح/رد الخطأ) تُملأ عند الفحص التفاعلي — انظر المهمة `PAY-T-012` في `plan.md`.


### `lib/features/restaurant/screens/restaurant_cashier_screen.dart` — PAY-SCR-69A0DA — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-318B60 | زر | `IconButton` | `restaurant_refresh_orders` | تحديث الطلبات | onPressed شرطي | 98 |
| PAY-UI-E975FB | زر | `FilledButton` | `common_retry` | إعادة المحاولة | onPressed | 139 |
| PAY-UI-6B5C01 | عنصر قابل للنقر | `InkWell` | — | — | onTap | 197 |

### `lib/features/restaurant/screens/restaurant_order_screen.dart` — PAY-SCR-61E4CD — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-BE6AA5 | زر | `TextButton` | `إلغاء` | إلغاء | onPressed | 77 |
| PAY-UI-16D834 | زر | `FilledButton` | `إضافة` | إضافة | onPressed | 78 |
| PAY-UI-B8D192 | زر | `TextButton` | `إلغاء` | إلغاء | onPressed | 136 |
| PAY-UI-E97BC1 | زر | `FilledButton` | `تأكيد` | — | onPressed | 137 |
| PAY-UI-1E1503 | زر | `FloatingActionButton` | `صنف` | — | onPressed شرطي | 208 |
| PAY-UI-866408 | زر | `IconButton` | — | — | onPressed | 248 |
| PAY-UI-3A4482 | زر | `FilledButton` | `تحصيل وإغلاق الطلب` | — | onPressed شرطي | 268 |
| PAY-UI-3D2EDD | زر | `FilledButton` | — | — | onPressed | 272 |
| PAY-UI-33392A | زر | `OutlinedButton` | — | — | onPressed | 278 |
| PAY-UI-59CE7E | زر | `FilledButton` | `إغلاق ودفع` | — | onPressed شرطي | 288 |
| PAY-UI-F13785 | زر | `FilledButton` | — | — | — | 292 |
| PAY-UI-25C620 | عنصر قابل للنقر | `ListTile` | — | — | — | 241 |
| PAY-UI-AC88BA | حقل إدخال | `TextField` | `الصنف *` | — | — | 66 |
| PAY-UI-B0656F | حقل إدخال | `TextField` | `الكمية` | — | — | 69 |
| PAY-UI-6C575B | حقل إدخال | `TextField` | `السعر` | — | onPressed | 72 |
| PAY-UI-5E125A | نافذة/ورقة | `showDialog` | `إضافة صنف` | — | استدعاء | 61 |
| PAY-UI-0867B4 | نافذة/ورقة | `showDialog` | `إغلاق الطلب والدفع` | — | استدعاء | 123 |

### `lib/features/restaurant/screens/restaurant_screen.dart` — PAY-SCR-CF97AF — **غير موصول (UNREACHABLE)**

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-9A34F6 | زر | `TextButton` | `إلغاء` | إلغاء | onPressed | 90 |
| PAY-UI-C6888A | زر | `FilledButton` | `إضافة` | إضافة | onPressed | 91 |
| PAY-UI-1E6200 | زر | `IconButton` | `سفري` | — | onPressed | 127 |
| PAY-UI-F247CD | زر | `FloatingActionButton` | `طاولة` | — | onPressed | 131 |
| PAY-UI-63739A | زر | `FilledButton` | — | — | onPressed | 226 |
| PAY-UI-AACA3D | زر | `FilledButton` | — | — | — | 230 |
| PAY-UI-60AB0A | عنصر قابل للنقر | `InkWell` | — | — | onTap | 163 |
| PAY-UI-884C63 | حقل إدخال | `TextField` | `اسم/رقم الطاولة *` | — | onPressed | 84 |
| PAY-UI-DD3855 | حقل إدخال | `TextField` | `عدد المقاعد` | — | onPressed | 86 |
| PAY-UI-CC6E6E | نافذة/ورقة | `showDialog` | `طاولة جديدة` | — | استدعاء | 79 |
| PAY-UI-3CC4D9 | تبويب | `TabBar` | `سفري` | — | onPressed | 122 |
