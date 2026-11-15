# ملحق — سجل العناصر التفاعلية: وحدة `retail`

> **مولَّد آليًّا** بتاريخ 2026-11-15 من شجرة `1a1d8c65` (مطابقة المحتوى للفرع الافتراضي `claude/project-code-review-yjagv@751ea393`). دليل **ساكن** فقط: لا يثبت تشغيلًا ولا ضغط زر ولا حالة إنتاج. المعرّفات مشتقة من تجزئة ثابتة (لا تتغير بإضافة صفوف). لا يُحذف شيء استنادًا إلى هذا الملف.

استخراج آلي بالتعابير النمطية من مصدر Dart: نوع الودجت، مفتاح/نص التسمية القريب، وترجمته من `assets/language/ar.json` إن وُجد المفتاح. **لا يثبت** الشروط الفعلية ولا رد الخادم ولا حالات التحميل؛ كل صف `INTERACTION_UNVERIFIED`. الأعمدة الناقصة عن القالب المعياري (التحقق/رد النجاح/رد الخطأ) تُملأ عند الفحص التفاعلي — انظر المهمة `PAY-T-012` في `plan.md`.


### `lib/features/retail/screens/product_attributes_screen.dart` — PAY-SCR-8F1455 — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-79A362 | زر | `TextButton` | `إلغاء` | إلغاء | onPressed | 97 |
| PAY-UI-FB22E0 | زر | `FilledButton` | `إضافة` | إضافة | onPressed | 98 |
| PAY-UI-F0E808 | زر | `TextButton` | `إلغاء` | إلغاء | onPressed | 131 |
| PAY-UI-1F9A4F | زر | `FilledButton` | `إضافة` | إضافة | onPressed | 132 |
| PAY-UI-150A59 | زر | `TextButton` | `تراجع` | تراجع | onPressed | 168 |
| PAY-UI-CF5F8B | زر | `FilledButton` | `حذف` | — | onPressed | 169 |
| PAY-UI-E6C2A9 | زر | `FilledButton` | `حذف` | — | onPressed | 170 |
| PAY-UI-B366F8 | زر | `FloatingActionButton` | `سمة جديدة` | — | onPressed | 194 |
| PAY-UI-D931B8 | زر | `OutlinedButton` | `إعادة المحاولة` | — | onPressed | 246 |
| PAY-UI-0EC31B | زر | `IconButton` | `إضافة قيم` | — | onPressed | 273 |
| PAY-UI-19F33B | زر | `IconButton` | `حذف السمة` | — | onPressed | 278 |
| PAY-UI-D468F2 | حقل إدخال | `TextField` | `اسم السمة` | — | — | 69 |
| PAY-UI-46765A | حقل إدخال | `TextField` | `القيم — تُفصَل بفاصلة` | — | — | 79 |
| PAY-UI-1266AC | حقل إدخال | `TextField` | `قيم جديدة — تُفصَل بفاصلة` | — | onPressed | 121 |
| PAY-UI-33E4C0 | نافذة/ورقة | `showDialog` | `سمة جديدة` | — | استدعاء | 64 |
| PAY-UI-05A6CA | نافذة/ورقة | `showDialog` | `قيم جديدة — تُفصَل بفاصلة` | — | استدعاء | 117 |
| PAY-UI-E23DE5 | نافذة/ورقة | `showDialog` | `تراجع` | تراجع | onPressed | 159 |

### `lib/features/retail/screens/product_variants_screen.dart` — PAY-SCR-7BD390 — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-C0A4FE | زر | `TextButton` | `تراجع` | تراجع | onPressed | 102 |
| PAY-UI-F6A815 | زر | `FilledButton` | `توليد` | — | onPressed | 103 |
| PAY-UI-2D46EB | زر | `IconButton` | — | — | onPressed | 180 |
| PAY-UI-CAB776 | زر | `TextButton` | — | — | onPressed | 205 |
| PAY-UI-2720EC | زر | `FilledButton` | — | — | onPressed | 245 |
| PAY-UI-05980E | زر | `FilledButton` | — | — | — | 254 |
| PAY-UI-D2435D | حقل إدخال | `TextField` | `اسم المحور` | — | onChanged | 169 |
| PAY-UI-8E26B6 | حقل إدخال | `TextField` | `القيم` | — | onChanged | 189 |
| PAY-UI-A3D911 | نافذة/ورقة | `Get.dialog` | `توليد المتغيّرات` | — | onPressed | 97 |

### `lib/features/retail/screens/retail_catalog_screen.dart` — PAY-SCR-4D0EBA — **غير موصول (UNREACHABLE)**

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-9CCB29 | زر | `TextButton` | `إلغاء` | إلغاء | onPressed | 43 |
| PAY-UI-31D0B7 | زر | `ElevatedButton` | `حفظ` | حفظ | onPressed | 44 |
| PAY-UI-BC797A | زر | `FloatingActionButton` | — | — | onPressed | 185 |
| PAY-UI-8B9061 | عنصر قابل للنقر | `ListTile` | — | — | — | 142 |
| PAY-UI-70F6A9 | عنصر قابل للنقر | `ListTile` | — | — | — | 163 |
| PAY-UI-38C0D6 | حقل إدخال | `TextField` | `الاسم` | — | onPressed | 37 |
| PAY-UI-49E829 | نافذة/ورقة | `showDialog` | `الاسم` | — | استدعاء | 29 |
| PAY-UI-E67C26 | تبويب | `TabBar` | — | — | — | 76 |

### `lib/features/retail/screens/retail_counts_screen.dart` — PAY-SCR-C0E167 — **غير موصول (UNREACHABLE)**

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-4EB487 | زر | `TextButton` | `إلغاء` | إلغاء | onPressed | 65 |
| PAY-UI-6985B8 | زر | `ElevatedButton` | `فتح` | — | onPressed | 66 |
| PAY-UI-890AE3 | زر | `FloatingActionButton` | `جرد جديد` | — | onPressed | 87 |
| PAY-UI-C84C99 | حقل إدخال | `DropdownButtonFormField` | `الموقع` | — | onChanged شرطي | 54 |
| PAY-UI-5DA082 | نافذة/ورقة | `showDialog` | `فتح جرد كامل` | — | استدعاء | 49 |

### `lib/features/retail/screens/retail_locations_screen.dart` — PAY-SCR-B646CB — **غير موصول (UNREACHABLE)**

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-E5CA84 | زر | `TextButton` | `إلغاء` | إلغاء | onPressed | 73 |
| PAY-UI-04EF49 | زر | `ElevatedButton` | `حفظ` | حفظ | onPressed | 74 |
| PAY-UI-448FF0 | زر | `FloatingActionButton` | — | — | onPressed | 104 |
| PAY-UI-6E76A6 | عنصر قابل للنقر | `ListTile` | — | — | — | 125 |
| PAY-UI-9E0EC1 | حقل إدخال | `TextField` | `الاسم` | — | — | 42 |
| PAY-UI-B2E915 | حقل إدخال | `TextField` | `الرمز (مثل WH1)` | — | — | 44 |
| PAY-UI-F2AD0F | حقل إدخال | `DropdownButtonFormField` | `النوع` | — | onChanged شرطي | 47 |
| PAY-UI-BD942A | حقل إدخال | `DropdownButtonFormField` | `retail_store_branch` | فرع المتجر | — | 58 |
| PAY-UI-7A5006 | نافذة/ورقة | `showDialog` | `موقع جديد` | — | استدعاء | 36 |

### `lib/features/retail/screens/retail_ops_center_screen.dart` — PAY-SCR-97B86E — **غير موصول (UNREACHABLE)**

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-21D134 | زر | `IconButton` | `تحديث` | — | onPressed | 51 |
| PAY-UI-F8302E | عنصر قابل للنقر | `ListTile` | — | — | — | 109 |
| PAY-UI-C43DF5 | عنصر قابل للنقر | `ListTile` | — | — | — | 199 |
| PAY-UI-B187E3 | عنصر قابل للنقر | `ListTile` | — | — | — | 246 |
| PAY-UI-92C14F | عنصر قابل للنقر | `ListTile` | — | — | — | 316 |
| PAY-UI-0936BA | عنصر قابل للنقر | `ListTile` | — | — | onTap | 370 |

### `lib/features/retail/screens/variant_editor_screen.dart` — PAY-SCR-5695E6 — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-E170F3 | زر | `IconButton` | `تحديث` | — | onPressed شرطي | 144 |
| PAY-UI-D9D6C5 | زر | `OutlinedButton` | `إعادة المحاولة` | — | onPressed | 164 |
| PAY-UI-F63439 | زر | `FilledButton` | — | — | onPressed شرطي | 284 |
| PAY-UI-6DDB62 | زر | `FilledButton` | — | — | — | 286 |
| PAY-UI-040C12 | حقل إدخال | `TextField` | `السعر (ر.ي)` | — | — | 257 |
| PAY-UI-919D74 | حقل إدخال | `TextField` | `المخزون` | المخزون | — | 270 |
