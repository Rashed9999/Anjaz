# ملحق — سجل العناصر التفاعلية: وحدة `merchant`

> **مولَّد آليًّا** بتاريخ 2026-11-15 من شجرة `1a1d8c65` (مطابقة المحتوى للفرع الافتراضي `claude/project-code-review-yjagv@751ea393`). دليل **ساكن** فقط: لا يثبت تشغيلًا ولا ضغط زر ولا حالة إنتاج. المعرّفات مشتقة من تجزئة ثابتة (لا تتغير بإضافة صفوف). لا يُحذف شيء استنادًا إلى هذا الملف.

استخراج آلي بالتعابير النمطية من مصدر Dart: نوع الودجت، مفتاح/نص التسمية القريب، وترجمته من `assets/language/ar.json` إن وُجد المفتاح. **لا يثبت** الشروط الفعلية ولا رد الخادم ولا حالات التحميل؛ كل صف `INTERACTION_UNVERIFIED`. الأعمدة الناقصة عن القالب المعياري (التحقق/رد النجاح/رد الخطأ) تُملأ عند الفحص التفاعلي — انظر المهمة `PAY-T-012` في `plan.md`.


### `lib/features/merchant/screens/cashier_payment_screen.dart` — PAY-SCR-5DE4B9 — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-F86281 | زر | `TextButton` | `إلغاء` | إلغاء | onPressed | 242 |
| PAY-UI-DAB225 | زر | `FilledButton` | `تطبيق` | — | onPressed | 243 |
| PAY-UI-B8E82A | زر | `TextButton` | `إلغاء` | إلغاء | onPressed | 280 |
| PAY-UI-415C72 | زر | `FilledButton` | `تطبيق` | — | onPressed | 281 |
| PAY-UI-7566B8 | زر | `TextButton` | `إلغاء` | إلغاء | onPressed | 368 |
| PAY-UI-890B44 | زر | `FilledButton` | `متابعة` | — | onPressed | 369 |
| PAY-UI-2525E0 | زر | `TextButton` | `إلغاء` | إلغاء | onPressed شرطي | 568 |
| PAY-UI-E0AEAD | زر | `IconButton` | — | — | onPressed | 590 |
| PAY-UI-F6305D | زر | `IconButton` | — | — | — | 598 |
| PAY-UI-FD9119 | زر | `OutlinedButton` | `استبدال نقاط بخصم` | — | onPressed شرطي | 628 |
| PAY-UI-ED8766 | زر | `OutlinedButton` | — | — | — | 632 |
| PAY-UI-09AB4A | زر | `OutlinedButton` | — | — | onPressed | 787 |
| PAY-UI-2BABDA | زر | `OutlinedButton` | — | — | onPressed | 792 |
| PAY-UI-9C1E5F | زر | `FilledButton` | `اسم العميل ورقمه مطلوبان` | — | onPressed | 805 |
| PAY-UI-3CC3D5 | زر | `FilledButton` | `تسجيل البيع الآجل` | — | — | 815 |
| PAY-UI-BDBC81 | زر | `TextButton` | `إزالة الخصم` | — | onPressed شرطي | 907 |
| PAY-UI-DC93CD | زر | `OutlinedButton` | `تطبيق خصم / كوبون` | — | onPressed شرطي | 912 |
| PAY-UI-54BA47 | زر | `OutlinedButton` | — | — | — | 916 |
| PAY-UI-1949B7 | زر | `FilledButton` | `تأكيد ومعالجة الدفع` | — | onPressed شرطي | 994 |
| PAY-UI-34C849 | زر | `FilledButton` | `إلغاء العملية` | — | onPressed | 1005 |
| PAY-UI-2EF2EA | زر | `TextButton` | `إلغاء العملية` | — | onPressed | 1013 |
| PAY-UI-CF8346 | عنصر قابل للنقر | `ListTile` | — | — | onTap | 426 |
| PAY-UI-E8D50C | عنصر قابل للنقر | `InkWell` | — | — | onTap | 1034 |
| PAY-UI-24162E | حقل إدخال | `TextField` | `عدد النقاط` | — | onPressed | 233 |
| PAY-UI-493AB9 | حقل إدخال | `TextField` | `رمز الكوبون (اتركه فارغاً للعرض التلقائي)` | — | onPressed | 271 |
| PAY-UI-1AA2A7 | حقل إدخال | `TextField` | `الجزء المدفوع محفظةً (أميال باي)` | — | onChanged | 345 |
| PAY-UI-E54FE3 | حقل إدخال | `TextField` | `رقم العميل` | — | — | 576 |
| PAY-UI-6952F4 | حقل إدخال | `TextField` | `اسم العميل *` | — | — | 766 |
| PAY-UI-E844D9 | حقل إدخال | `TextField` | `رقم العميل *` | — | onChanged | 773 |
| PAY-UI-5F7655 | نافذة/ورقة | `showDialog` | `استبدال نقاط بخصم` | — | استدعاء | 223 |
| PAY-UI-8E8494 | نافذة/ورقة | `showDialog` | `خصم / كوبون` | — | استدعاء | 267 |
| PAY-UI-16CF81 | نافذة/ورقة | `showDialog` | `دفع مختلط` | — | استدعاء | 334 |
| PAY-UI-43EB14 | نافذة/ورقة | `showModalBottomSheet` | — | — | استدعاء | 740 |
| PAY-UI-FF5790 | نافذة/ورقة | `showDatePicker` | — | — | استدعاء | 795 |

### `lib/features/merchant/screens/cashier_pos_screen.dart` — PAY-SCR-527084 — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-949E67 | زر | `TextButton` | `إلغاء` | إلغاء | onPressed | 140 |
| PAY-UI-CDA56A | زر | `ElevatedButton` | `متابعة للدفع` | متابعة للدفع | onPressed | 143 |
| PAY-UI-A5FFC5 | زر | `ElevatedButton` | `متابعة للدفع` | متابعة للدفع | — | 145 |
| PAY-UI-4C8143 | زر | `FilledButton` | `متابعة للدفع` | متابعة للدفع | onPressed | 238 |
| PAY-UI-AA6D29 | زر | `FilledButton` | — | — | onPressed | 247 |
| PAY-UI-3D47BA | زر | `TextButton` | `إفراغ السلة` | إفراغ السلة | onPressed | 255 |
| PAY-UI-10FFD7 | زر | `IconButton` | `آلة حاسبة` | آلة حاسبة | onPressed | 318 |
| PAY-UI-106C88 | زر | `FilledButton` | `متابعة للدفع` | متابعة للدفع | onPressed | 400 |
| PAY-UI-707F67 | زر | `FilledButton` | — | — | — | 409 |
| PAY-UI-69DAAF | زر | `IconButton` | `آلة حاسبة` | آلة حاسبة | onPressed | 460 |
| PAY-UI-B7A84F | زر | `IconButton` | `مبيعات دون اتصال` | مبيعات دون اتصال | onPressed | 472 |
| PAY-UI-C1D02E | زر | `IconButton` | `التذاكر المفتوحة` | التذاكر المفتوحة | onPressed | 495 |
| PAY-UI-EF2A0C | زر | `IconButton` | `إدخال يدوي` | إدخال يدوي | onPressed | 518 |
| PAY-UI-40D446 | زر | `ElevatedButton` | `أدخل المبلغ` | أدخل المبلغ | onPressed | 553 |
| PAY-UI-1028DC | زر | `ElevatedButton` | — | — | — | 559 |
| PAY-UI-AAF0F6 | زر | `IconButton` | `تعليق الفاتورة` | تعليق الفاتورة | onPressed شرطي | 757 |
| PAY-UI-77162E | زر | `TextButton` | `إلغاء` | إلغاء | onPressed | 1032 |
| PAY-UI-686DDF | زر | `FilledButton` | `تعليق` | تعليق | onPressed | 1033 |
| PAY-UI-FAFF2E | زر | `FilledButton` | `استئناف` | استئناف | onPressed | 1146 |
| PAY-UI-4EA081 | زر | `FilledButton` | `إلغاء` | إلغاء | onPressed | 1150 |
| PAY-UI-217F93 | زر | `OutlinedButton` | `إلغاء` | إلغاء | onPressed | 1156 |
| PAY-UI-B9D2AD | زر | `OutlinedButton` | — | — | — | 1160 |
| PAY-UI-8DFE4B | زر | `TextButton` | `تراجع` | تراجع | onPressed | 1182 |
| PAY-UI-9534E0 | زر | `TextButton` | `علّق الحالية أولاً` | علّق الحالية أولاً | onPressed | 1184 |
| PAY-UI-AE8148 | زر | `FilledButton` | `استبدال` | استبدال | onPressed | 1186 |
| PAY-UI-12FA43 | زر | `TextButton` | `تراجع` | تراجع | onPressed | 1228 |
| PAY-UI-5FC827 | زر | `FilledButton` | `إلغاء التذكرة` | إلغاء التذكرة | onPressed | 1229 |
| PAY-UI-90D727 | زر | `FilledButton` | `إلغاء التذكرة` | إلغاء التذكرة | — | 1231 |
| PAY-UI-CA6BC1 | عنصر قابل للنقر | `InkWell` | — | — | onTap | 574 |
| PAY-UI-A2FD2D | عنصر قابل للنقر | `InkWell` | — | — | onTap | 686 |
| PAY-UI-FC51F7 | عنصر قابل للنقر | `InkWell` | — | — | onTap | 817 |
| PAY-UI-7B7BB8 | عنصر قابل للنقر | `InkWell` | — | — | onTap | 832 |
| PAY-UI-DA4D81 | عنصر قابل للنقر | `InkWell` | — | — | onTap | 956 |
| PAY-UI-003B43 | حقل إدخال | `TextField` | `0` | — | — | 127 |
| PAY-UI-099BC9 | حقل إدخال | `TextField` | — | — | — | 371 |
| PAY-UI-E17FBC | حقل إدخال | `TextField` | `بحث عن منتج أو كود...` | بحث عن منتج أو كود... | onChanged | 593 |
| PAY-UI-33302C | حقل إدخال | `TextField` | `اسم يميّزها (اختياري)` | اسم يميّزها (اختياري) | — | 1017 |
| PAY-UI-8EB448 | حقل إدخال | `TextField` | `السبب` | السبب | onPressed | 1218 |
| PAY-UI-231159 | نافذة/ورقة | `showDialog` | `إدخال مبلغ يدوي` | إدخال مبلغ يدوي | استدعاء | 123 |
| PAY-UI-57172C | نافذة/ورقة | `showModalBottomSheet` | — | — | استدعاء | 161 |
| PAY-UI-E6932F | نافذة/ورقة | `showDialog` | `تعليق الفاتورة` | تعليق الفاتورة | استدعاء | 1009 |
| PAY-UI-FCB1E6 | نافذة/ورقة | `showModalBottomSheet` | — | — | استدعاء | 1056 |
| PAY-UI-116AF5 | نافذة/ورقة | `showDialog` | `في السلة أصناف` | في السلة أصناف | onPressed | 1175 |
| PAY-UI-B6A8EE | نافذة/ورقة | `showDialog` | `إلغاء التذكرة` | إلغاء التذكرة | استدعاء | 1214 |

### `lib/features/merchant/screens/cashier_products_screen.dart` — PAY-SCR-5D004D — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-99B76B | زر | `IconButton` | `ابحث في الكتالوج` | — | onPressed | 86 |
| PAY-UI-E9ABEE | زر | `TextButton` | `استعمل اسم الكتالوج` | — | onPressed | 142 |
| PAY-UI-074D03 | زر | `FilledButton` | `أضِفه لمنتجاتي` | — | — | 159 |
| PAY-UI-9EEA61 | زر | `FilledButton` | — | — | onPressed | 163 |
| PAY-UI-3E654B | زر | `TextButton` | — | — | onPressed | 208 |
| PAY-UI-1133FE | زر | `TextButton` | — | — | onPressed | 218 |
| PAY-UI-A6433D | زر | `TextButton` | `إلغاء` | إلغاء | onPressed | 232 |
| PAY-UI-BE7016 | زر | `ElevatedButton` | `حفظ` | حفظ | onPressed | 233 |
| PAY-UI-00F5A8 | زر | `IconButton` | `سمات المنتجات (اللون · المقاس)` | — | onPressed | 287 |
| PAY-UI-EF3214 | زر | `FloatingActionButton` | `إضافة` | إضافة | onPressed | 294 |
| PAY-UI-DF26AC | زر | `IconButton` | `تعديل المنتج` | — | onPressed | 374 |
| PAY-UI-AFD9F4 | زر | `IconButton` | `متغيّرات (لون · مقاس)` | — | onPressed | 386 |
| PAY-UI-AA2494 | زر | `IconButton` | `الأنواع — سعر ومخزون كلّ نوع` | — | onPressed | 404 |
| PAY-UI-0A6146 | عنصر قابل للنقر | `ListTile` | — | — | onTap | 318 |
| PAY-UI-8D8E58 | حقل إدخال | `TextField` | `الاسم *` | — | — | 59 |
| PAY-UI-B94485 | حقل إدخال | `TextField` | `سعر البيع *` | سعر البيع * | — | 60 |
| PAY-UI-463226 | حقل إدخال | `TextField` | `سعر التكلفة` | — | — | 61 |
| PAY-UI-CBA278 | حقل إدخال | `TextField` | `سعر العرض (اختياري)` | — | — | 62 |
| PAY-UI-C70471 | حقل إدخال | `TextField` | `الكمية (المخزون)` | — | — | 63 |
| PAY-UI-D2007E | حقل إدخال | `TextField` | `التصنيف (اختياري)` | — | — | 64 |
| PAY-UI-C1DE47 | حقل إدخال | `TextField` | `الباركود (اختياري)` | الباركود (اختياري) | — | 73 |
| PAY-UI-0570FC | نافذة/ورقة | `showDatePicker` | `منتج جديد` | منتج جديد | استدعاء | 43 |
| PAY-UI-86191D | نافذة/ورقة | `showDialog` | `منتج جديد` | منتج جديد | استدعاء | 50 |

### `lib/features/merchant/screens/cashier_receipt_screen.dart` — PAY-SCR-6499A4 — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-F786B7 | زر | `IconButton` | — | — | onPressed | 276 |
| PAY-UI-F49298 | زر | `FilledButton` | `عملية جديدة` | — | onPressed | 350 |
| PAY-UI-FD2559 | زر | `FilledButton` | — | — | — | 362 |

### `lib/features/merchant/screens/cashier_refund_screen.dart` — PAY-SCR-F99686 — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-67E832 | زر | `FilledButton` | `تأكيد عملية المرتجع` | — | onPressed شرطي | 287 |
| PAY-UI-D3D167 | زر | `FilledButton` | — | — | — | 293 |
| PAY-UI-C38CD6 | زر | `IconButton` | — | — | onPressed | 357 |
| PAY-UI-A535B2 | زر | `IconButton` | — | — | onPressed | 375 |
| PAY-UI-1B4CBD | عنصر قابل للنقر | `InkWell` | — | — | onTap | 445 |
| PAY-UI-00A0EA | حقل إدخال | `TextField` | — | — | — | 233 |
| PAY-UI-DA56B7 | حقل إدخال | `TextField` | `سبب الإرجاع (اختياري)` | — | — | 273 |
| PAY-UI-70A741 | حقل إدخال | `DropdownButtonFormField` | `حالة الصنف المرتجع` | — | — | 407 |

### `lib/features/merchant/screens/cashier_report_screen.dart` — PAY-SCR-F5221C — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-502B17 | زر | `TextButton` | `استرجاع` | — | onPressed شرطي | 121 |
| PAY-UI-1B813D | زر | `TextButton` | — | — | — | 125 |
| PAY-UI-0699E2 | عنصر قابل للنقر | `ListTile` | — | — | onTap شرطي | 89 |
| PAY-UI-C9B24E | عنصر قابل للنقر | `ListTile` | — | — | — | 303 |

### `lib/features/merchant/screens/cashier_sale_detail_screen.dart` — PAY-SCR-5D7E35 — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-977086 | زر | `TextButton` | `إعادة المحاولة` | — | onPressed | 73 |

### `lib/features/merchant/screens/cashier_scan_screen.dart` — PAY-SCR-055058 — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-60FC81 | زر | `TextButton` | `تخطّي` | — | onPressed | 92 |
| PAY-UI-47011B | زر | `FilledButton` | `حفظ وإضافة` | — | onPressed | 93 |
| PAY-UI-D8CE14 | زر | `FilledButton` | `حفظ وإضافة` | — | onPressed | 94 |
| PAY-UI-1C5FBA | زر | `TextButton` | `إلغاء` | إلغاء | onPressed | 139 |
| PAY-UI-960E5E | زر | `FilledButton` | `بحث` | — | onPressed | 140 |
| PAY-UI-D34E90 | زر | `IconButton` | `إدخال يدوي` | إدخال يدوي | onPressed | 163 |
| PAY-UI-B1F1A7 | زر | `IconButton` | — | — | onPressed | 164 |
| PAY-UI-4BE420 | زر | `IconButton` | — | — | onPressed | 165 |
| PAY-UI-840A42 | زر | `FilledButton` | — | — | onPressed | 271 |
| PAY-UI-E03A7C | زر | `FilledButton` | — | — | onPressed | 272 |
| PAY-UI-D98387 | زر | `IconButton` | `حذف` | — | onPressed | 294 |
| PAY-UI-47D679 | عنصر قابل للنقر | `InkWell` | — | — | onTap | 329 |
| PAY-UI-8D731F | حقل إدخال | `TextField` | `اسم المنتج *` | — | — | 85 |
| PAY-UI-9D807A | حقل إدخال | `TextField` | `سعر البيع *` | سعر البيع * | — | 86 |
| PAY-UI-E0650A | حقل إدخال | `TextField` | `الكمية (المخزون)` | — | onPressed | 88 |
| PAY-UI-226E84 | حقل إدخال | `TextField` | `رقم الباركود` | — | onPressed | 131 |
| PAY-UI-CE739B | نافذة/ورقة | `showDialog` | `باركود جديد — أضِف المنتج` | — | استدعاء | 78 |
| PAY-UI-F3AF4D | نافذة/ورقة | `showDialog` | `إدخال الباركود يدوياً` | — | استدعاء | 127 |

### `lib/features/merchant/screens/cashier_shift_screen.dart` — PAY-SCR-3D9C2D — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-F887CB | زر | `TextButton` | `إلغاء` | إلغاء | onPressed | 103 |
| PAY-UI-48CDC9 | زر | `FilledButton` | `بدء` | — | onPressed | 104 |
| PAY-UI-37F9F8 | زر | `TextButton` | `إلغاء` | إلغاء | onPressed | 166 |
| PAY-UI-C69E9F | زر | `FilledButton` | `تسجيل التسليم` | — | onPressed | 167 |
| PAY-UI-BA9F56 | زر | `TextButton` | `إلغاء` | إلغاء | onPressed | 214 |
| PAY-UI-CEB55A | زر | `FilledButton` | `إقفال` | — | onPressed | 215 |
| PAY-UI-A50EB1 | زر | `FilledButton` | `تم` | — | onPressed | 265 |
| PAY-UI-B840B3 | زر | `OutlinedButton` | `إعادة المحاولة` | — | onPressed | 335 |
| PAY-UI-F9C8C6 | زر | `FilledButton` | `بدء وردية` | — | onPressed | 353 |
| PAY-UI-569014 | زر | `FilledButton` | — | — | — | 355 |
| PAY-UI-5C7B4D | زر | `OutlinedButton` | `تسليم نقد للخزنة` | — | onPressed | 384 |
| PAY-UI-5D5B07 | زر | `OutlinedButton` | — | — | — | 388 |
| PAY-UI-B07052 | زر | `FilledButton` | `إقفال الوردية (Z)` | — | onPressed | 397 |
| PAY-UI-7A1F11 | زر | `FilledButton` | — | — | — | 399 |
| PAY-UI-340059 | عنصر قابل للنقر | `ListTile` | — | — | — | 304 |
| PAY-UI-7CB1C1 | حقل إدخال | `TextField` | `النقد الافتتاحي في الدرج` | — | onPressed | 100 |
| PAY-UI-E43DC6 | حقل إدخال | `TextField` | `المبلغ المسلّم` | — | — | 136 |
| PAY-UI-75D27B | حقل إدخال | `TextField` | `مرجع التسليم (اختياري)` | — | — | 147 |
| PAY-UI-53F946 | حقل إدخال | `TextField` | `ملاحظة (اختياري)` | — | onPressed | 156 |
| PAY-UI-24EB55 | حقل إدخال | `TextField` | `النقد المجرود فعلاً` | — | — | 208 |
| PAY-UI-2CDE5E | حقل إدخال | `TextField` | `ملاحظات (اختياري)` | — | onPressed | 211 |
| PAY-UI-50B2CC | نافذة/ورقة | `showDialog` | `بدء وردية` | — | onPressed | 96 |
| PAY-UI-D83F41 | نافذة/ورقة | `showDialog` | `تسليم نقد للخزنة` | — | استدعاء | 119 |
| PAY-UI-B13040 | نافذة/ورقة | `showDialog` | `إقفال الوردية (جرد الدرج)` | — | استدعاء | 200 |
| PAY-UI-83F645 | نافذة/ورقة | `showDialog` | `تقرير Z` | — | استدعاء | 245 |

### `lib/features/merchant/screens/credit_add_customer_dialog.dart` — — — **غير موصول (UNREACHABLE)**

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-567EF8 | زر | `TextButton` | `إلغاء` | إلغاء | onPressed | 79 |
| PAY-UI-4D90CA | زر | `FilledButton` | `حفظ` | حفظ | onPressed شرطي | 80 |
| PAY-UI-FB1FF3 | زر | `FilledButton` | `حفظ` | حفظ | — | 82 |
| PAY-UI-F29C45 | حقل إدخال | `TextField` | `الاسم الكامل` | — | — | 39 |
| PAY-UI-89F49C | حقل إدخال | `TextField` | `رقم الهاتف` | — | — | 48 |
| PAY-UI-64F39F | حقل إدخال | `TextField` | `حد الائتمان (اختياري — 0 يعني بلا حد)` | — | — | 59 |

### `lib/features/merchant/screens/credit_customer_statement_screen.dart` — PAY-SCR-B2CDBD — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-6206A2 | زر | `IconButton` | — | — | onPressed | 198 |
| PAY-UI-CCF6B3 | زر | `OutlinedButton` | — | — | onPressed | 206 |
| PAY-UI-C4D23D | زر | `FilledButton` | `credit_collect_action` | تحصيل دين | onPressed | 260 |
| PAY-UI-66F9DE | زر | `FilledButton` | `credit_collect_action` | تحصيل دين | onPressed | 264 |
| PAY-UI-C66A0F | زر | `FilledButton` | `credit_collect_action` | تحصيل دين | onPressed | 271 |
| PAY-UI-5540A0 | زر | `FilledButton` | `مرتجع` | — | onPressed | 275 |
| PAY-UI-684F43 | زر | `OutlinedButton` | `مرتجع` | — | onPressed | 278 |
| PAY-UI-AB5D91 | زر | `OutlinedButton` | `تعديل` | — | onPressed | 287 |
| PAY-UI-328D4B | زر | `OutlinedButton` | — | — | onPressed شرطي | 291 |
| PAY-UI-50AA21 | زر | `OutlinedButton` | — | — | onPressed شرطي | 297 |
| PAY-UI-311059 | زر | `OutlinedButton` | — | — | — | 306 |
| PAY-UI-651F71 | زر | `OutlinedButton` | `credit_collect_resume` | استئناف الدفع وإصدار السند | onPressed شرطي | 458 |
| PAY-UI-98F929 | زر | `TextButton` | `credit_collect_cancel` | إلغاء | onPressed | 514 |
| PAY-UI-AEE885 | زر | `FilledButton` | `credit_collect_amount_invalid_title` | تحقق من المبلغ | onPressed شرطي | 516 |
| PAY-UI-919CB1 | زر | `TextButton` | `credit_collect_close` | إغلاق | onPressed | 575 |
| PAY-UI-8FABC0 | زر | `FilledButton` | `credit_collect_print` | طباعة / تحميل السند | onPressed شرطي | 576 |
| PAY-UI-168A45 | زر | `TextButton` | `credit_collect_later` | لاحقاً | onPressed | 605 |
| PAY-UI-0B1F32 | زر | `FilledButton` | `credit_collect_pending_snackbar` | انتظار الدفع | onPressed شرطي | 606 |
| PAY-UI-D0676F | زر | `TextButton` | `إلغاء` | إلغاء | onPressed | 662 |
| PAY-UI-37C9CF | زر | `FilledButton` | — | — | onPressed شرطي | 663 |
| PAY-UI-103753 | حقل إدخال | `TextField` | `credit_collect_amount` | المبلغ بالريال اليمني | — | 488 |
| PAY-UI-99E1B8 | حقل إدخال | `DropdownButtonFormField` | `credit_collect_method` | طريقة الدفع | onChanged شرطي | 494 |
| PAY-UI-8F33B7 | حقل إدخال | `TextField` | `credit_collect_note` | ملاحظة (اختياري) | onPressed | 506 |
| PAY-UI-1BA17D | حقل إدخال | `TextField` | `المبلغ (موقّع للتعديل: +500 أو -200)` | — | — | 648 |
| PAY-UI-00F668 | حقل إدخال | `TextField` | `ملاحظة` | — | onPressed | 655 |
| PAY-UI-9A8EFD | نافذة/ورقة | `showDatePicker` | — | — | استدعاء | 208 |
| PAY-UI-308692 | نافذة/ورقة | `Get.dialog` | `credit_collect_title` | تحصيل من حساب الآجل | استدعاء | 482 |
| PAY-UI-073682 | نافذة/ورقة | `Get.dialog` | `credit_collect_success_title` | تم التحصيل وإصدار السند | onPressed | 568 |
| PAY-UI-6D2C04 | نافذة/ورقة | `Get.dialog` | `credit_collect_pending_title` | بانتظار دفع العميل عبر أميال | استدعاء | 592 |
| PAY-UI-0CEDCE | نافذة/ورقة | `Get.dialog` | `المبلغ (موقّع للتعديل: +500 أو -200)` | — | استدعاء | 645 |

### `lib/features/merchant/screens/credit_customers_screen.dart` — PAY-SCR-5D8C59 — **غير موصول (UNREACHABLE)**

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-871E74 | زر | `FloatingActionButton` | — | — | onPressed | 46 |
| PAY-UI-D57555 | عنصر قابل للنقر | `InkWell` | — | — | onTap | 136 |
| PAY-UI-341720 | حقل إدخال | `TextField` | `ابحث بالاسم أو رقم الهاتف...` | — | — | 60 |
| PAY-UI-D5D6FB | نافذة/ورقة | `Get.dialog` | `إضافة عميل` | — | استدعاء | 48 |

### `lib/features/merchant/screens/credit_dashboard_screen.dart` — PAY-SCR-AD313C — **غير موصول (UNREACHABLE)**

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-82556C | زر | `FilledButton` | `عرض كل العملاء` | — | onPressed | 73 |
| PAY-UI-CBCB60 | زر | `FilledButton` | — | — | — | 77 |

### `lib/features/merchant/screens/financial_truth_report_screen.dart` — PAY-SCR-1367D1 — **غير موصول (UNREACHABLE)**

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-E9F97F | عنصر قابل للنقر | `ListTile` | — | — | — | 36 |

### `lib/features/merchant/screens/inventory_audit_screen.dart` — PAY-SCR-7DB383 — **غير موصول (UNREACHABLE)**

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-BB4E94 | زر | `TextButton` | `تراجع` | تراجع | onPressed | 78 |
| PAY-UI-31E693 | زر | `ElevatedButton` | `اعتماد` | — | onPressed | 81 |
| PAY-UI-B332DF | زر | `ElevatedButton` | `اعتماد` | — | — | 83 |
| PAY-UI-4771DE | زر | `FilledButton` | — | — | onPressed شرطي | 133 |
| PAY-UI-227E06 | زر | `FilledButton` | — | — | — | 148 |
| PAY-UI-C766A8 | زر | `IconButton` | — | — | onPressed | 301 |
| PAY-UI-3C3D3E | زر | `IconButton` | `الكمية الفعلية` | — | onPressed | 324 |
| PAY-UI-CDD2E2 | حقل إدخال | `TextField` | `بحث عن منتج أو رمز...` | — | onChanged | 183 |
| PAY-UI-078BF0 | حقل إدخال | `TextField` | — | — | — | 309 |
| PAY-UI-609281 | نافذة/ورقة | `showDialog` | `اعتماد الجرد؟` | — | onPressed | 68 |

### `lib/features/merchant/screens/inventory_screen.dart` — PAY-SCR-FA29AD — **غير موصول (UNREACHABLE)**

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-3B13EB | زر | `IconButton` | `تدقيق المخزون (جرد)` | — | onPressed | 84 |
| PAY-UI-D6FC2D | زر | `FloatingActionButton` | `إضافة منتج` | — | onPressed | 94 |
| PAY-UI-206D6D | عنصر قابل للنقر | `InkWell` | — | — | onTap | 138 |
| PAY-UI-CFB466 | حقل إدخال | `TextField` | `بحث عن منتج أو باركود...` | — | onChanged | 151 |

### `lib/features/merchant/screens/merchant_accept_payment_screen.dart` — PAY-SCR-74A8FD — **غير موصول (UNREACHABLE)**

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-C1A720 | زر | `OutlinedButton` | `الإيصال` | — | onPressed | 142 |
| PAY-UI-3B2272 | زر | `OutlinedButton` | `فاتورة جديدة` | — | onPressed | 153 |
| PAY-UI-E47CD9 | زر | `ElevatedButton` | `تم` | — | onPressed | 169 |
| PAY-UI-98A3BF | زر | `ElevatedButton` | `تم` | — | — | 174 |
| PAY-UI-5E925D | زر | `ElevatedButton` | — | — | onPressed شرطي | 268 |
| PAY-UI-173CFC | زر | `ElevatedButton` | — | — | — | 281 |
| PAY-UI-BF5910 | حقل إدخال | `TextFormField` | — | — | — | 203 |
| PAY-UI-A4EBCF | حقل إدخال | `TextFormField` | `وصف الدفعة (اختياري)` | — | — | 224 |
| PAY-UI-3167B4 | حقل إدخال | `CheckboxListTile` | `إرسال الطلب لرقم عميل محدد` | — | onChanged شرطي | 234 |
| PAY-UI-8DE0BE | حقل إدخال | `TextFormField` | `رقم جوال العميل *` | — | — | 249 |
| PAY-UI-50C0C6 | نافذة/ورقة | `showModalBottomSheet` | — | — | استدعاء | 64 |

### `lib/features/merchant/screens/merchant_account_screen.dart` — PAY-SCR-AF985F — **غير موصول (UNREACHABLE)**

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-B651BA | عنصر قابل للنقر | `ListTile` | — | — | onTap | 137 |

### `lib/features/merchant/screens/merchant_adaptive_shell.dart` — — — **غير موصول (UNREACHABLE)**

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-573A69 | زر | `IconButton` | `القائمة` | — | onPressed | 84 |
| PAY-UI-38780D | زر | `TextButton` | `ملفُّ اعتماد المتجر` | — | onPressed | 214 |
| PAY-UI-1DF8FC | زر | `TextButton` | `إلغاء` | إلغاء | onPressed | 874 |
| PAY-UI-D8136E | زر | `FilledButton` | `تسجيل الخروج` | — | onPressed | 878 |
| PAY-UI-54F082 | زر | `FilledButton` | `تسجيل الخروج` | — | — | 880 |
| PAY-UI-EC2873 | عنصر قابل للنقر | `ListTile` | — | — | — | 754 |
| PAY-UI-7C6636 | عنصر قابل للنقر | `InkWell` | — | — | onTap | 788 |
| PAY-UI-2E6773 | نافذة/ورقة | `showDialog` | `تسجيل الخروج` | — | onPressed | 868 |

### `lib/features/merchant/screens/merchant_advanced_reports_screen.dart` — PAY-SCR-5A48B1 — **غير موصول (UNREACHABLE)**

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-14D0EB | عنصر قابل للنقر | `ListTile` | — | — | onTap | 64 |

### `lib/features/merchant/screens/merchant_api_keys_screen.dart` — PAY-SCR-A0B973 — **غير موصول (UNREACHABLE)**

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-56E4B5 | زر | `TextButton` | `إلغاء` | إلغاء | onPressed | 65 |
| PAY-UI-613210 | زر | `FilledButton` | `توليد` | — | onPressed | 66 |
| PAY-UI-770E01 | زر | `TextButton` | `نسخ` | — | onPressed | 110 |
| PAY-UI-0890F3 | زر | `FilledButton` | `تم، حفظته` | — | onPressed | 115 |
| PAY-UI-854C8F | زر | `TextButton` | `إلغاء` | إلغاء | onPressed | 133 |
| PAY-UI-6D6145 | زر | `FilledButton` | `حذف` | — | onPressed | 134 |
| PAY-UI-483FF2 | زر | `FilledButton` | `حذف` | — | onPressed | 134 |
| PAY-UI-BEBA84 | زر | `FloatingActionButton` | `مفتاح جديد` | — | onPressed | 151 |
| PAY-UI-C0BA8B | زر | `TextButton` | `نسخ الرابط` | — | onPressed | 200 |
| PAY-UI-DB725F | زر | `IconButton` | — | — | onPressed | 229 |
| PAY-UI-B31943 | عنصر قابل للنقر | `ListTile` | — | — | — | 215 |
| PAY-UI-2F7281 | حقل إدخال | `TextField` | `وصف المفتاح (اختياري)` | — | onPressed | 61 |
| PAY-UI-735E5D | حقل إدخال | `Switch` | — | — | onChanged | 228 |
| PAY-UI-8DEA0C | نافذة/ورقة | `showDialog` | `مفتاح API جديد` | — | onPressed | 57 |
| PAY-UI-33704E | نافذة/ورقة | `showDialog` | `مفتاحك الجديد` | — | استدعاء | 83 |
| PAY-UI-668718 | نافذة/ورقة | `showDialog` | `حذف المفتاح` | — | onPressed | 127 |

### `lib/features/merchant/screens/merchant_audit_log_screen.dart` — PAY-SCR-C534B9 — **غير موصول (UNREACHABLE)**

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-649FF1 | زر | `TextButton` | `cancel` | إلغاء | onPressed | 152 |
| PAY-UI-077F2D | عنصر قابل للنقر | `ListTile` | — | — | — | 109 |
| PAY-UI-764B0B | نافذة/ورقة | `showDialog` | `audit_details` | تفاصيل التدقيق | استدعاء | 136 |

### `lib/features/merchant/screens/merchant_backup_screen.dart` — PAY-SCR-640DB5 — **غير موصول (UNREACHABLE)**

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-FDD8E2 | زر | `FilledButton` | `مشاركة / حفظ الملف` | — | onPressed | 120 |
| PAY-UI-42FDE1 | زر | `FilledButton` | — | — | onPressed شرطي | 124 |
| PAY-UI-C2FB21 | زر | `FilledButton` | — | — | onPressed شرطي | 128 |
| PAY-UI-B37FEE | زر | `FilledButton` | — | — | — | 134 |

### `lib/features/merchant/screens/merchant_business_refunds_screen.dart` — PAY-SCR-4474FB — **غير موصول (UNREACHABLE)**

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-CA773E | زر | `TextButton` | `common_cancel` | إلغاء | onPressed | 70 |
| PAY-UI-871A3A | زر | `FilledButton` | `business_refund_approve` | موافقة | onPressed | 71 |
| PAY-UI-BBCA0A | زر | `TextButton` | `common_cancel` | إلغاء | onPressed | 86 |
| PAY-UI-2A673A | زر | `FilledButton` | `business_refund_reject` | رفض | onPressed | 87 |
| PAY-UI-8A6AB9 | زر | `FilledButton` | `business_refund_reject` | رفض | onPressed | 87 |
| PAY-UI-C3ADDD | زر | `TextButton` | `common_cancel` | إلغاء | onPressed | 108 |
| PAY-UI-A03913 | زر | `FilledButton` | `business_refund_mandate_save` | حفظ | onPressed | 109 |
| PAY-UI-252E34 | زر | `TextButton` | `business_refund_mandate_edit` | تعديل | onPressed شرطي | 186 |
| PAY-UI-8B76BB | زر | `FilledButton` | `business_refund_approve` | موافقة | onPressed شرطي | 205 |
| PAY-UI-0CB539 | زر | `OutlinedButton` | `business_refund_reject` | رفض | onPressed شرطي | 207 |
| PAY-UI-3C66BF | عنصر قابل للنقر | `ListTile` | — | — | — | 216 |
| PAY-UI-9E4E07 | حقل إدخال | `TextField` | `business_refund_reject_reason` | السبب (اختياري) | onPressed | 84 |
| PAY-UI-EAAB16 | حقل إدخال | `TextField` | `business_refund_mandate_per` | حدّ العملية الواحدة | — | 102 |
| PAY-UI-61FB99 | حقل إدخال | `TextField` | `business_refund_mandate_daily` | الحدّ اليومي | onPressed | 104 |
| PAY-UI-A84076 | نافذة/ورقة | `Get.dialog` | `business_refund_confirm_title` | تأكيد الاسترداد | onPressed | 63 |
| PAY-UI-BA25BD | نافذة/ورقة | `Get.dialog` | `business_refund_reject_title` | رفض الاسترداد | onPressed | 82 |
| PAY-UI-A1845F | نافذة/ورقة | `Get.dialog` | `business_refund_mandate_title` | الاسترداد التلقائي | استدعاء | 97 |

### `lib/features/merchant/screens/merchant_capability_hub_screen.dart` — PAY-SCR-2346FA — **غير موصول (UNREACHABLE)**

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-2508AE | زر | `FilledButton` | — | — | onPressed | 582 |
| PAY-UI-EAD600 | زر | `FilledButton` | `مقارنة الباقات` | — | — | 587 |
| PAY-UI-2BB020 | زر | `FilledButton` | — | — | onPressed | 642 |
| PAY-UI-063644 | عنصر قابل للنقر | `InkWell` | — | — | onTap | 345 |
| PAY-UI-72DDCB | نافذة/ورقة | `showModalBottomSheet` | — | — | استدعاء | 542 |
| PAY-UI-A99A9A | نافذة/ورقة | `showModalBottomSheet` | — | — | استدعاء | 607 |

### `lib/features/merchant/screens/merchant_cash_out_screen.dart` — PAY-SCR-920C7F — **غير موصول (UNREACHABLE)**

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-9390D1 | زر | `FilledButton` | `merchant_cash_out_submit` | إنشاء رقم السحب | onPressed شرطي | 136 |
| PAY-UI-3EA33C | زر | `TextButton` | `merchant_cash_out_cancel` | إلغاء وفك الحجز | onPressed | 160 |
| PAY-UI-766785 | عنصر قابل للنقر | `ListTile` | `merchant_cash_out_fee` | الرسم: @fee | — | 105 |
| PAY-UI-DEB0B0 | عنصر قابل للنقر | `GestureDetector` | — | — | onTap | 150 |
| PAY-UI-74DC9C | حقل إدخال | `TextField` | `merchant_cash_out_amount` | المبلغ | — | 125 |

### `lib/features/merchant/screens/merchant_currencies_screen.dart` — PAY-SCR-46363F — **غير موصول (UNREACHABLE)**

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-112182 | زر | `TextButton` | `إلغاء` | إلغاء | onPressed | 227 |
| PAY-UI-4D7A98 | زر | `FilledButton` | `تأكيد الصرف` | — | onPressed | 228 |
| PAY-UI-2EAF44 | زر | `IconButton` | `تحديث` | — | onPressed شرطي | 282 |
| PAY-UI-E1B916 | زر | `OutlinedButton` | `إعادة المحاولة` | — | onPressed | 303 |
| PAY-UI-4DFE28 | زر | `IconButton` | `صرف` | — | onPressed | 381 |
| PAY-UI-C19868 | حقل إدخال | `TextField` | — | — | onChanged | 168 |
| PAY-UI-A110E5 | حقل إدخال | `DropdownButtonFormField` | `إلى` | — | — | 178 |
| PAY-UI-AD63A1 | حقل إدخال | `Switch` | — | — | onChanged شرطي | 414 |
| PAY-UI-27D7FE | نافذة/ورقة | `showDialog` | — | — | استدعاء | 128 |

### `lib/features/merchant/screens/merchant_dashboard_screen.dart` — PAY-SCR-7678B8 — **غير موصول (UNREACHABLE)**

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-C60BDD | زر | `TextButton` | `عرض الكل` | — | onPressed | 307 |
| PAY-UI-16CF2E | زر | `IconButton` | — | — | onPressed | 618 |
| PAY-UI-594E6A | عنصر قابل للنقر | `InkWell` | — | — | onTap | 181 |
| PAY-UI-9D5BFB | عنصر قابل للنقر | `InkWell` | — | — | onTap | 488 |
| PAY-UI-09B3CA | عنصر قابل للنقر | `ListTile` | — | — | onTap | 581 |

### `lib/features/merchant/screens/merchant_excel_export_screen.dart` — PAY-SCR-2A9B42 — **غير موصول (UNREACHABLE)**

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-FAABAD | زر | `FilledButton` | `تنزيل الملف` | — | onPressed | 145 |
| PAY-UI-A95E46 | زر | `FilledButton` | `تصدير الآن` | — | onPressed | 149 |
| PAY-UI-6996BF | زر | `FilledButton` | `تصدير الآن` | — | onPressed | 155 |
| PAY-UI-F9A2EB | زر | `FilledButton` | — | — | — | 159 |

### `lib/features/merchant/screens/merchant_expenses_screen.dart` — PAY-SCR-648183 — **غير موصول (UNREACHABLE)**

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-30581B | زر | `FilledButton` | — | — | onPressed | 115 |
| PAY-UI-342952 | زر | `FilledButton` | — | — | — | 116 |
| PAY-UI-65E15C | زر | `FloatingActionButton` | `مصروف` | — | onPressed | 142 |
| PAY-UI-5A7F24 | زر | `IconButton` | — | — | onPressed | 199 |
| PAY-UI-D97048 | زر | `IconButton` | — | — | onPressed | 200 |
| PAY-UI-5835EA | عنصر قابل للنقر | `ListTile` | — | — | — | 192 |
| PAY-UI-1D4EA6 | حقل إدخال | `TextField` | `البيان *` | — | — | 103 |
| PAY-UI-0927DD | حقل إدخال | `TextField` | `المبلغ ر.ي *` | — | — | 105 |
| PAY-UI-7EB1E6 | حقل إدخال | `DropdownButtonFormField` | `الفئة` | — | onChanged شرطي | 108 |
| PAY-UI-74A3FA | نافذة/ورقة | `showModalBottomSheet` | — | — | استدعاء | 92 |

### `lib/features/merchant/screens/merchant_gift_cards_screen.dart` — PAY-SCR-276160 — **غير موصول (UNREACHABLE)**

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-2590B2 | زر | `TextButton` | `إلغاء` | إلغاء | onPressed | 65 |
| PAY-UI-65DD0C | زر | `FilledButton` | `إصدار` | — | onPressed | 66 |
| PAY-UI-153325 | زر | `TextButton` | `نسخ` | — | onPressed | 97 |
| PAY-UI-34DFE5 | زر | `FilledButton` | `تم` | — | onPressed | 99 |
| PAY-UI-9D4D49 | زر | `TextButton` | `إلغاء` | إلغاء | onPressed | 119 |
| PAY-UI-F711F4 | زر | `FilledButton` | `تأكيد` | — | onPressed | 120 |
| PAY-UI-1DA174 | زر | `FloatingActionButton` | `بطاقة جديدة` | — | onPressed | 136 |
| PAY-UI-E77CE4 | عنصر قابل للنقر | `ListTile` | — | — | — | 166 |
| PAY-UI-015741 | حقل إدخال | `TextField` | `قيمة البطاقة ر.ي *` | — | — | 56 |
| PAY-UI-159BBB | حقل إدخال | `TextField` | `اسم المستفيد (اختياري)` | — | — | 59 |
| PAY-UI-76CBF9 | حقل إدخال | `TextField` | `هاتف المستفيد (اختياري — لتظهر له)` | — | onPressed | 61 |
| PAY-UI-B3A302 | حقل إدخال | `TextField` | `المبلغ ر.ي` | — | onPressed | 116 |
| PAY-UI-2D9468 | نافذة/ورقة | `showDialog` | `إصدار بطاقة هدية` | — | استدعاء | 51 |
| PAY-UI-A7CD52 | نافذة/ورقة | `showDialog` | `البطاقة جاهزة` | — | استدعاء | 86 |
| PAY-UI-F96E4D | نافذة/ورقة | `showDialog` | `المبلغ ر.ي` | — | onPressed | 112 |

### `lib/features/merchant/screens/merchant_installments_screen.dart` — PAY-SCR-B73DC1 — **غير موصول (UNREACHABLE)**

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-3E8974 | زر | `FilledButton` | `حفظ الشروط` | — | onPressed | 104 |
| PAY-UI-46C6E9 | زر | `FilledButton` | `حفظ الشروط` | — | — | 105 |
| PAY-UI-1ACE74 | زر | `OutlinedButton` | `احسب القسط` | — | onPressed | 199 |
| PAY-UI-795F90 | زر | `FilledButton` | `إنشاء العقد وتحصيل الدفعة` | — | onPressed | 217 |
| PAY-UI-4D2F87 | زر | `FilledButton` | `البيع بالتقسيط` | البيع بالتقسيط | — | 219 |
| PAY-UI-685A12 | زر | `IconButton` | `الشروط` | — | onPressed | 234 |
| PAY-UI-62A537 | زر | `FloatingActionButton` | `عقد جديد` | — | onPressed | 237 |
| PAY-UI-FFB200 | زر | `TextButton` | `تعديل` | — | onPressed | 280 |
| PAY-UI-BFF382 | عنصر قابل للنقر | `ListTile` | — | — | — | 292 |
| PAY-UI-697DC3 | حقل إدخال | `SwitchListTile` | `تفعيل البيع بالتقسيط` | — | onChanged | 81 |
| PAY-UI-52C535 | حقل إدخال | `TextField` | `المدد المتاحة (أشهر، مفصولة بفاصلة)` | — | — | 92 |
| PAY-UI-F76393 | حقل إدخال | `SwitchListTile` | `اشتراط توثيق الهوية (KYC)` | — | onChanged | 95 |
| PAY-UI-D55C52 | حقل إدخال | `SwitchListTile` | `اشتراط كفيل مسجّل` | — | onChanged | 99 |
| PAY-UI-C21DDC | حقل إدخال | `TextField` | `هاتف العميل (مسجّل) *` | — | — | 178 |
| PAY-UI-A9F33B | حقل إدخال | `TextField` | `اسم السلعة (اختياري)` | — | onChanged | 181 |
| PAY-UI-91CDDA | حقل إدخال | `TextField` | `السعر ر.ي *` | — | onChanged | 184 |
| PAY-UI-0BBCF4 | حقل إدخال | `DropdownButtonFormField` | `المدّة` | — | onChanged شرطي | 187 |
| PAY-UI-4F05C3 | حقل إدخال | `TextField` | — | — | onPressed | 194 |
| PAY-UI-28055B | حقل إدخال | `TextField` | — | — | — | 307 |
| PAY-UI-31E7C9 | نافذة/ورقة | `showModalBottomSheet` | — | — | استدعاء | 69 |
| PAY-UI-D1CB6E | نافذة/ورقة | `showModalBottomSheet` | — | — | استدعاء | 135 |

### `lib/features/merchant/screens/merchant_loyalty_screen.dart` — PAY-SCR-698637 — **غير موصول (UNREACHABLE)**

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-9FB3F8 | زر | `TextButton` | `إلغاء` | إلغاء | onPressed | 114 |
| PAY-UI-EF9E9C | زر | `FilledButton` | `استبدال` | استبدال | onPressed | 115 |
| PAY-UI-D4884D | زر | `IconButton` | — | — | onPressed | 149 |
| PAY-UI-B856B9 | زر | `IconButton` | — | — | — | 150 |
| PAY-UI-00AECC | زر | `FilledButton` | `استبدال بخصم` | — | onPressed | 167 |
| PAY-UI-8D5D4A | زر | `FilledButton` | `برنامج الولاء` | برنامج الولاء | — | 169 |
| PAY-UI-32A95D | زر | `FloatingActionButton` | `رصيد عميل` | — | onPressed | 184 |
| PAY-UI-37978A | زر | `FilledButton` | `حفظ الإعداد` | — | onPressed | 235 |
| PAY-UI-B99AEF | زر | `FilledButton` | — | — | — | 237 |
| PAY-UI-A9DE9E | عنصر قابل للنقر | `ListTile` | — | — | — | 251 |
| PAY-UI-C70628 | حقل إدخال | `TextField` | `عدد النقاط` | — | onPressed | 111 |
| PAY-UI-601DA5 | حقل إدخال | `TextField` | `هاتف العميل` | — | onPressed | 146 |
| PAY-UI-C0745B | حقل إدخال | `Switch` | — | — | onChanged | 225 |
| PAY-UI-D2238D | حقل إدخال | `TextField` | — | — | — | 241 |
| PAY-UI-EF7A5A | نافذة/ورقة | `showModalBottomSheet` | — | — | استدعاء | 89 |
| PAY-UI-941DE5 | نافذة/ورقة | `showDialog` | `استبدال نقاط بخصم` | — | onPressed | 107 |

### `lib/features/merchant/screens/merchant_operations_center_screen.dart` — PAY-SCR-46BA4D — **غير موصول (UNREACHABLE)**

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-0EC552 | زر | `TextButton` | `ops_cancel` | إلغاء | onPressed | 267 |
| PAY-UI-302DCC | زر | `FilledButton` | `ops_create_role` | إنشاء الدور | onPressed | 269 |
| PAY-UI-C392EE | زر | `TextButton` | `ops_cancel` | إلغاء | onPressed | 349 |
| PAY-UI-C38E0D | زر | `FilledButton` | `ops_create_employee` | إنشاء الموظف | onPressed | 350 |
| PAY-UI-1B0022 | زر | `TextButton` | `ops_cancel` | إلغاء | onPressed | 409 |
| PAY-UI-8A25EC | زر | `FilledButton` | `ops_create_code` | إنشاء الرمز | onPressed | 410 |
| PAY-UI-C67A88 | زر | `FilledButton` | `ops_done` | تم | onPressed | 440 |
| PAY-UI-10AE39 | زر | `IconButton` | `ops_refresh` | تحديث | onPressed شرطي | 457 |
| PAY-UI-7C8044 | زر | `OutlinedButton` | `ops_retry` | إعادة المحاولة | onPressed | 484 |
| PAY-UI-86A305 | زر | `FilledButton` | `ops_add_member` | إضافة عضو تشغيل | onPressed شرطي | 504 |
| PAY-UI-61A7DA | زر | `FilledButton` | `ops_hero_title` | تشغيل منشأتك من مكان واحد | — | 505 |
| PAY-UI-70ED5C | زر | `FilledButton` | `ops_new_role` | دور جديد | onPressed شرطي | 542 |
| PAY-UI-F804E4 | زر | `FilledButton` | `ops_new_employee` | موظف جديد | onPressed شرطي | 554 |
| PAY-UI-B6AB17 | زر | `FilledButton` | `ops_activation_button` | رمز تفعيل | onPressed شرطي | 569 |
| PAY-UI-DD3BF1 | زر | `FilledButton` | `ops_open_audit` | فتح سجل التدقيق | onPressed | 605 |
| PAY-UI-941927 | زر | `OutlinedButton` | `ops_view_plans` | عرض الباقات | onPressed | 619 |
| PAY-UI-8C9BB9 | زر | `OutlinedButton` | `ops_retry` | إعادة المحاولة | onPressed شرطي | 622 |
| PAY-UI-CC3659 | عنصر قابل للنقر | `ListTile` | — | — | onTap | 532 |
| PAY-UI-372611 | عنصر قابل للنقر | `ListTile` | `ops_role_counts` | @permissions صلاحية • @employees موظف | — | 545 |
| PAY-UI-972054 | عنصر قابل للنقر | `ListTile` | `ops_employee_branch` | رمز: @code • @branch | — | 559 |
| PAY-UI-1E02B9 | عنصر قابل للنقر | `ListTile` | `ops_pos_device` | جهاز نقطة بيع | — | 574 |
| PAY-UI-A1BA2C | عنصر قابل للنقر | `ListTile` | `ops_employee` | موظف | — | 592 |
| PAY-UI-066846 | حقل إدخال | `TextField` | `ops_role_name` | اسم الدور | — | 220 |
| PAY-UI-1BF226 | حقل إدخال | `TextField` | `ops_description` | وصف مختصر (اختياري) | — | 229 |
| PAY-UI-EF1844 | حقل إدخال | `CheckboxListTile` | — | — | onChanged | 248 |
| PAY-UI-ED2F52 | حقل إدخال | `TextField` | `ops_employee_name` | اسم الموظف | — | 318 |
| PAY-UI-6845FB | حقل إدخال | `TextField` | `ops_employee_code` | رمز الموظف | — | 321 |
| PAY-UI-6D0DDC | حقل إدخال | `TextField` | `ops_password` | كلمة المرور | — | 324 |
| PAY-UI-6C673F | حقل إدخال | `DropdownButtonFormField` | `ops_role` | الدور | onChanged | 327 |
| PAY-UI-9196CB | حقل إدخال | `DropdownButtonFormField` | `ops_work_branch` | فرع العمل | — | 337 |
| PAY-UI-D76203 | حقل إدخال | `TextField` | `ops_device_name` | اسم الجهاز | — | 393 |
| PAY-UI-D3B76E | حقل إدخال | `DropdownButtonFormField` | `ops_device_branch` | فرع الجهاز | — | 397 |
| PAY-UI-EBB04E | نافذة/ورقة | `showDialog` | `ops_activation_code` | رمز تفعيل الجهاز | استدعاء | 429 |
| PAY-UI-7D4634 | تبويب | `TabBar` | `ops_overview` | نظرة عامة | — | 458 |

### `lib/features/merchant/screens/merchant_pay_screen.dart` — PAY-SCR-ED5080 — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-F97DCB | زر | `TextButton` | `إلغاء` | إلغاء | onPressed | 164 |
| PAY-UI-728899 | زر | `FilledButton` | `ادفع الآن` | — | onPressed | 165 |
| PAY-UI-EC89CA | زر | `FilledButton` | `تم` | — | onPressed | 184 |
| PAY-UI-2DE0E6 | زر | `TextButton` | `إلغاء` | إلغاء | onPressed | 218 |
| PAY-UI-733C51 | زر | `FilledButton` | `عرض الفاتورة` | — | onPressed | 219 |
| PAY-UI-EE4F93 | زر | `IconButton` | `مسح رمز التاجر` | — | onPressed | 319 |
| PAY-UI-5C5920 | زر | `TextButton` | `لديّ رقم فاتورة` | — | onPressed | 334 |
| PAY-UI-CA0182 | زر | `ElevatedButton` | — | — | onPressed شرطي | 393 |
| PAY-UI-D5C183 | زر | `ElevatedButton` | — | — | — | 395 |
| PAY-UI-3158AA | حقل إدخال | `TextField` | `رقم حساب التاجر` | — | — | 203 |
| PAY-UI-367C16 | حقل إدخال | `TextField` | `رقم الفاتورة` | — | onPressed | 210 |
| PAY-UI-778C34 | حقل إدخال | `TextFormField` | `رقم هاتف التاجر` | — | — | 311 |
| PAY-UI-5733E5 | حقل إدخال | `TextFormField` | `المبلغ` | — | onChanged | 342 |
| PAY-UI-8366A9 | حقل إدخال | `TextFormField` | `ملاحظة (اختياري)` | — | — | 358 |
| PAY-UI-CF286F | نافذة/ورقة | `Get.dialog` | `تأكيد الدفع` | — | استدعاء | 144 |
| PAY-UI-7F4AAD | نافذة/ورقة | `Get.dialog` | `تم الدفع` | — | onPressed | 178 |
| PAY-UI-9BA64E | نافذة/ورقة | `Get.dialog` | `الدفع برقم الفاتورة` | — | استدعاء | 200 |

### `lib/features/merchant/screens/merchant_payout_screen.dart` — PAY-SCR-B724A8 — **غير موصول (UNREACHABLE)**

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-0A479F | زر | `TextButton` | `common_cancel` | إلغاء | onPressed | 65 |
| PAY-UI-BF959B | زر | `FilledButton` | `merchant_send_request` | إرسال الطلب | onPressed | 66 |
| PAY-UI-16F85D | زر | `TextButton` | `merchant_not_yet` | ليس بعد | onPressed | 96 |
| PAY-UI-2CAA4E | زر | `FilledButton` | `merchant_cash_received` | استلمت النقد | onPressed | 97 |
| PAY-UI-B5E659 | زر | `FilledButton` | `merchant_sending` | جارٍ الإرسال… | onPressed شرطي | 132 |
| PAY-UI-42C805 | زر | `TextButton` | `common_retry` | إعادة المحاولة | onPressed | 152 |
| PAY-UI-19CFE7 | زر | `OutlinedButton` | `merchant_cash_receipt_confirm_title` | تأكيد استلام النقد | onPressed | 171 |
| PAY-UI-DBFF71 | حقل إدخال | `TextField` | `merchant_amount` | المبلغ | — | 126 |
| PAY-UI-71E937 | حقل إدخال | `TextField` | `merchant_admin_note_optional` | ملاحظة للإدارة (اختياري) | onPressed شرطي | 129 |
| PAY-UI-1CB24A | نافذة/ورقة | `Get.dialog` | `merchant_payout_confirm_title` | تأكيد طلب السحب | onPressed | 61 |
| PAY-UI-56953C | نافذة/ورقة | `Get.dialog` | `merchant_cash_receipt_confirm_title` | تأكيد استلام النقد | onPressed | 92 |

### `lib/features/merchant/screens/merchant_pos_devices_screen.dart` — PAY-SCR-875140 — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-0FB705 | زر | `TextButton` | `إلغاء` | إلغاء | onPressed | 115 |
| PAY-UI-7485FE | زر | `FilledButton` | `إنشاء الرمز` | — | onPressed | 116 |
| PAY-UI-74898C | زر | `FilledButton` | `تم` | — | onPressed | 145 |
| PAY-UI-D637FD | زر | `TextButton` | `إلغاء` | إلغاء | onPressed | 199 |
| PAY-UI-8F8A6D | زر | `FilledButton` | `تسجيل` | — | onPressed | 203 |
| PAY-UI-079BB9 | زر | `TextButton` | `تراجع` | تراجع | onPressed | 255 |
| PAY-UI-3CCFB0 | زر | `FilledButton` | `إلغاء الجهاز` | — | onPressed | 259 |
| PAY-UI-D2AE27 | زر | `FilledButton` | `إلغاء الجهاز` | — | onPressed | 260 |
| PAY-UI-05B6B1 | زر | `TextButton` | `إلغاء` | إلغاء | onPressed | 296 |
| PAY-UI-28EF56 | زر | `FilledButton` | `حفظ` | حفظ | onPressed | 300 |
| PAY-UI-4F1347 | زر | `IconButton` | `إضافة جهاز برمز تفعيل` | — | onPressed شرطي | 329 |
| PAY-UI-3155A8 | زر | `IconButton` | `تسجيل هذا الجهاز` | — | onPressed شرطي | 334 |
| PAY-UI-21E59C | زر | `IconButton` | `تحديث` | — | onPressed شرطي | 339 |
| PAY-UI-70A4AC | زر | `FilledButton` | `إعادة المحاولة` | — | onPressed | 367 |
| PAY-UI-7CA28C | زر | `FilledButton` | `إضافة جهاز وإنشاء رمز` | — | onPressed | 393 |
| PAY-UI-ED1CEE | زر | `IconButton` | `تسمية` | — | onPressed | 484 |
| PAY-UI-09CCAF | زر | `IconButton` | `إلغاء الجهاز` | — | onPressed | 489 |
| PAY-UI-569CDD | عنصر قابل للنقر | `ListTile` | — | — | — | 457 |
| PAY-UI-C8C541 | حقل إدخال | `TextField` | `اسم الجهاز` | — | — | 98 |
| PAY-UI-04597E | حقل إدخال | `DropdownButtonFormField` | `merchant_device_branch` | فرع الجهاز | — | 104 |
| PAY-UI-D17EF5 | حقل إدخال | `TextField` | `اسمٌ يميّزه` | — | onPressed | 188 |
| PAY-UI-BECAE3 | حقل إدخال | `TextField` | `اسمٌ يميّزه` | — | onPressed | 287 |
| PAY-UI-D9C371 | نافذة/ورقة | `showDialog` | `إضافة جهاز نقطة بيع` | — | استدعاء | 91 |
| PAY-UI-57EC6E | نافذة/ورقة | `showDialog` | `رمز تفعيل الجهاز` | — | استدعاء | 133 |
| PAY-UI-5944A5 | نافذة/ورقة | `showDialog` | `تسجيل هذا الجهاز` | — | استدعاء | 175 |
| PAY-UI-8D4E8C | نافذة/ورقة | `showDialog` | `إلغاء الجهاز` | — | onPressed | 246 |
| PAY-UI-3CF667 | نافذة/ورقة | `showDialog` | `اسم الجهاز` | — | استدعاء | 283 |

### `lib/features/merchant/screens/merchant_promotions_screen.dart` — PAY-SCR-B71BBA — **غير موصول (UNREACHABLE)**

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-8CCED5 | زر | `FilledButton` | — | — | onPressed | 100 |
| PAY-UI-6A7BFB | زر | `FilledButton` | — | — | — | 102 |
| PAY-UI-123378 | زر | `FloatingActionButton` | `عرض جديد` | — | onPressed | 146 |
| PAY-UI-FF071A | زر | `IconButton` | — | — | onPressed | 197 |
| PAY-UI-14393A | زر | `IconButton` | — | — | onPressed | 200 |
| PAY-UI-704F0E | عنصر قابل للنقر | `ListTile` | — | — | — | 182 |
| PAY-UI-855EE5 | حقل إدخال | `TextField` | `اسم العرض *` | — | — | 71 |
| PAY-UI-1C3C6A | حقل إدخال | `TextField` | — | — | — | 84 |
| PAY-UI-7E8ACE | حقل إدخال | `TextField` | `رمز الكوبون (فارغ = خصم تلقائي)` | — | — | 87 |
| PAY-UI-B529AB | حقل إدخال | `TextField` | `أدنى مبلغ فاتورة — اختياري` | — | — | 90 |
| PAY-UI-87E1A2 | حقل إدخال | `TextField` | `سقف الخصم (ر.ي) — اختياري` | — | — | 94 |
| PAY-UI-E97A9D | حقل إدخال | `TextField` | `حدّ الاستخدام — اختياري` | — | onPressed | 97 |
| PAY-UI-B43D60 | حقل إدخال | `Switch` | — | — | onChanged | 199 |
| PAY-UI-342843 | نافذة/ورقة | `showModalBottomSheet` | — | — | استدعاء | 59 |

### `lib/features/merchant/screens/merchant_refund_screen.dart` — PAY-SCR-67086F — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-32CA13 | زر | `TextButton` | `إلغاء` | إلغاء | onPressed | 43 |
| PAY-UI-5C4B6D | زر | `ElevatedButton` | `استرجاع` | — | onPressed | 46 |
| PAY-UI-4149FC | زر | `ElevatedButton` | `استرجاع` | — | — | 48 |
| PAY-UI-B0816B | زر | `TextButton` | `حسناً` | — | onPressed | 75 |
| PAY-UI-BDB9A3 | زر | `ElevatedButton` | — | — | onPressed شرطي | 175 |
| PAY-UI-407B21 | زر | `ElevatedButton` | — | — | — | 186 |
| PAY-UI-B3CE39 | حقل إدخال | `TextFormField` | `رقم العملية الأصلية *` | — | — | 129 |
| PAY-UI-7D7BCD | حقل إدخال | `TextFormField` | `مبلغ الاسترجاع (ر.ي) *` | — | — | 141 |
| PAY-UI-19DDF2 | حقل إدخال | `TextFormField` | `سبب الاسترجاع (اختياري)` | — | — | 161 |
| PAY-UI-919F77 | نافذة/ورقة | `showDialog` | `تأكيد الاسترجاع` | — | onPressed | 34 |
| PAY-UI-F10BC9 | نافذة/ورقة | `showDialog` | `تم الاسترجاع` | — | onPressed | 66 |

### `lib/features/merchant/screens/merchant_sales_history_screen.dart` — PAY-SCR-40885E — **غير موصول (UNREACHABLE)**

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-2096DD | زر | `TextButton` | `common_retry` | إعادة المحاولة | onPressed | 216 |
| PAY-UI-2638E4 | عنصر قابل للنقر | `ListTile` | `merchant_sales_date` | تاريخ المبيعات | onTap شرطي | 151 |
| PAY-UI-9C34C7 | عنصر قابل للنقر | `ListTile` | `merchant_invoice` | فاتورة | onTap | 182 |
| PAY-UI-155257 | نافذة/ورقة | `showDatePicker` | — | — | استدعاء | 59 |

### `lib/features/merchant/screens/merchant_services_hub_screen.dart` — PAY-SCR-16F8E5 — **غير موصول (UNREACHABLE)**

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-3072AD | زر | `TextButton` | `ترقية` | ترقية | onPressed | 204 |
| PAY-UI-563E18 | زر | `TextButton` | `ترقية` | ترقية | — | 206 |
| PAY-UI-868BF3 | زر | `FilledButton` | — | — | onPressed | 412 |
| PAY-UI-E60A1F | زر | `FilledButton` | `افتح الخدمة` | افتح الخدمة | onPressed | 416 |
| PAY-UI-715495 | زر | `FilledButton` | `افتح الخدمة` | افتح الخدمة | onPressed | 422 |
| PAY-UI-545056 | زر | `FilledButton` | — | — | — | 426 |
| PAY-UI-E9E42A | عنصر قابل للنقر | `ListTile` | `مركز تشغيل المنشأة` | مركز تشغيل المنشأة | onTap | 93 |
| PAY-UI-D49331 | عنصر قابل للنقر | `InkWell` | — | — | onTap | 268 |
| PAY-UI-29969D | نافذة/ورقة | `showModalBottomSheet` | — | — | استدعاء | 330 |

### `lib/features/merchant/screens/merchant_staff_performance_screen.dart` — PAY-SCR-95DC3B — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-C32B96 | عنصر قابل للنقر | `InkWell` | — | — | onTap شرطي | 81 |

### `lib/features/merchant/screens/merchant_staff_screen.dart` — PAY-SCR-2FD8F6 — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-A30F2D | زر | `TextButton` | `إلغاء` | إلغاء | onPressed | 162 |
| PAY-UI-FA1400 | زر | `FilledButton` | `إنشاء` | — | onPressed | 163 |
| PAY-UI-62D922 | زر | `IconButton` | `الأداء` | — | onPressed | 197 |
| PAY-UI-147C42 | زر | `FloatingActionButton` | `موظف جديد` | — | onPressed | 205 |
| PAY-UI-2052E2 | زر | `OutlinedButton` | `إعادة المحاولة` | — | onPressed | 223 |
| PAY-UI-5C6641 | زر | `IconButton` | `merchant_change_work_branch` | تغيير فرع العمل | onPressed | 284 |
| PAY-UI-2D634B | زر | `TextButton` | `cancel` | إلغاء | onPressed | 338 |
| PAY-UI-DE076D | زر | `FilledButton` | `save` | حفظ | onPressed | 339 |
| PAY-UI-63BDDD | عنصر قابل للنقر | `ListTile` | — | — | — | 259 |
| PAY-UI-05E736 | حقل إدخال | `TextField` | `اسم الموظف` | — | — | 94 |
| PAY-UI-7E0ED8 | حقل إدخال | `TextField` | `رمز الموظف` | — | — | 97 |
| PAY-UI-0DFF93 | حقل إدخال | `TextField` | `كلمة مرور الموظف` | — | — | 100 |
| PAY-UI-7BD4A5 | حقل إدخال | `DropdownButtonFormField` | `merchant_staff_work_branch` | فرع عمل الموظف | — | 105 |
| PAY-UI-113095 | حقل إدخال | `DropdownButtonFormField` | `الوظيفة` | — | onChanged | 120 |
| PAY-UI-CB2069 | حقل إدخال | `CheckboxListTile` | — | — | onChanged شرطي | 151 |
| PAY-UI-DAC8AF | حقل إدخال | `Switch` | — | — | onChanged | 310 |
| PAY-UI-A9D5CC | حقل إدخال | `DropdownButtonFormField` | `audit_branch` | الفرع | onChanged | 329 |
| PAY-UI-B07AEA | نافذة/ورقة | `showDialog` | `موظف جديد` | — | استدعاء | 87 |
| PAY-UI-4397B3 | نافذة/ورقة | `showDialog` | `merchant_staff_work_branch_title` | فرع عمل @name | استدعاء | 324 |

### `lib/features/merchant/screens/merchant_transactions_screen.dart` — PAY-SCR-94D930 — **غير موصول (UNREACHABLE)**

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-C6DFA6 | زر | `TextButton` | `عرض تقرير المال التشغيلي` | — | onPressed | 78 |

### `lib/features/merchant/screens/merchant_wallet_screen.dart` — PAY-SCR-D7F43B — **غير موصول (UNREACHABLE)**

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-A39C94 | زر | `TextButton` | `common_view_all` | عرض الكل | onPressed | 130 |
| PAY-UI-7B6FA5 | عنصر قابل للنقر | `InkWell` | — | — | onTap | 267 |

### `lib/features/merchant/screens/offline_sales_screen.dart` — PAY-SCR-0D8729 — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-66B4A1 | زر | `IconButton` | `حذف العملية المرفوضة` | — | onPressed | 103 |
| PAY-UI-DAA735 | زر | `FilledButton` | — | — | onPressed شرطي | 121 |
| PAY-UI-7E18C9 | زر | `FilledButton` | — | — | — | 127 |
| PAY-UI-422023 | عنصر قابل للنقر | `ListTile` | — | — | — | 94 |

### `lib/features/merchant/screens/pos_credit_lookup_screen.dart` — PAY-SCR-B0E9B3 — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-B73058 | زر | `FilledButton` | `credit_collect_pos_lookup` | البحث عن دين العميل | onPressed شرطي | 96 |
| PAY-UI-CBCF78 | زر | `FilledButton` | — | — | — | 103 |
| PAY-UI-FFA730 | حقل إدخال | `TextField` | `credit_collect_pos_phone` | رقم هاتف العميل | onSubmitted | 81 |

### `lib/features/merchant/screens/pos_device_activation_screen.dart` — PAY-SCR-310F17 — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-56AAC9 | زر | `FilledButton` | `متابعة` | — | onPressed | 58 |
| PAY-UI-71799A | زر | `FilledButton` | — | — | onPressed شرطي | 102 |
| PAY-UI-2DE888 | حقل إدخال | `TextField` | `رمز التفعيل` | — | — | 89 |
| PAY-UI-541B4D | نافذة/ورقة | `showDialog` | `تم تفعيل الجهاز` | — | onPressed | 53 |

### `lib/features/merchant/screens/pos_employee_home_screen.dart` — PAY-SCR-C4F9A2 — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-3FAFF3 | زر | `IconButton` | `pos_my_notifications` | إشعاراتي | onPressed | 101 |
| PAY-UI-516D9A | زر | `IconButton` | `common_logout` | تسجيل الخروج | onPressed | 106 |
| PAY-UI-E18C1A | زر | `TextButton` | `إلغاء` | إلغاء | onPressed | 226 |
| PAY-UI-D0847E | زر | `FilledButton` | `خروج` | — | onPressed | 229 |
| PAY-UI-24CA57 | زر | `FilledButton` | `خروج` | — | — | 231 |
| PAY-UI-011519 | عنصر قابل للنقر | `InkWell` | — | — | onTap | 331 |
| PAY-UI-1B3F7C | عنصر قابل للنقر | `ListTile` | — | — | onTap | 411 |
| PAY-UI-3CBAC2 | نافذة/ورقة | `showDialog` | `تسجيل الخروج` | — | onPressed | 218 |

### `lib/features/merchant/screens/product_editor_screen.dart` — PAY-SCR-E71F80 — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-C82BBF | زر | `FilledButton` | — | — | onPressed شرطي | 173 |
| PAY-UI-04BF3A | زر | `FilledButton` | — | — | — | 185 |
| PAY-UI-016980 | زر | `IconButton` | — | — | onPressed | 252 |
| PAY-UI-B352CC | زر | `IconButton` | `الكمية المتوفرة` | — | onPressed | 263 |
| PAY-UI-944FC6 | عنصر قابل للنقر | `InkWell` | — | — | onTap | 373 |
| PAY-UI-47615D | حقل إدخال | `TextField` | — | — | — | 201 |
| PAY-UI-2677D9 | حقل إدخال | `TextField` | — | — | — | 206 |
| PAY-UI-9798E5 | حقل إدخال | `TextField` | — | — | — | 234 |
| PAY-UI-6DA580 | حقل إدخال | `TextField` | — | — | — | 293 |
| PAY-UI-B02F8D | حقل إدخال | `TextField` | — | — | — | 361 |
| PAY-UI-01B65E | نافذة/ورقة | `showDatePicker` | — | — | استدعاء | 152 |

### `lib/features/merchant/screens/profit_report_screen.dart` — PAY-SCR-162F89 — **غير موصول (UNREACHABLE)**

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-2A584C | زر | `IconButton` | `المبيعات بالصنف` | — | onPressed | 60 |
| PAY-UI-0610A1 | زر | `IconButton` | — | — | onPressed | 65 |

### `lib/features/merchant/screens/receipt_settings_screen.dart` — PAY-SCR-D10AD1 — **غير موصول (UNREACHABLE)**

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-13FA82 | زر | `OutlinedButton` | `اختيار شعار المتجر` | — | onPressed | 154 |
| PAY-UI-B77FE8 | زر | `OutlinedButton` | — | — | onPressed | 227 |
| PAY-UI-73C40F | زر | `FilledButton` | `حفظ الإعدادات` | — | onPressed شرطي | 239 |
| PAY-UI-A93710 | زر | `FilledButton` | — | — | — | 245 |
| PAY-UI-DF5557 | عنصر قابل للنقر | `InkWell` | — | — | onTap | 311 |
| PAY-UI-E704C3 | حقل إدخال | `SwitchListTile` | `إظهار الشعار` | — | onChanged | 160 |
| PAY-UI-CEC710 | حقل إدخال | `SwitchListTile` | `إظهار الهاتف` | — | onChanged | 170 |
| PAY-UI-208BBB | حقل إدخال | `SwitchListTile` | `إظهار العنوان` | — | onChanged | 177 |
| PAY-UI-294B00 | حقل إدخال | `TextField` | — | — | onChanged | 254 |

### `lib/features/merchant/screens/sales_breakdown_screen.dart` — PAY-SCR-773DD2 — **غير موصول (UNREACHABLE)**

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-BE4E27 | زر | `TextButton` | `أعِد المحاولة` | — | onPressed | 120 |
| PAY-UI-935550 | عنصر قابل للنقر | `ListTile` | — | — | — | 248 |
| PAY-UI-F97DDA | عنصر قابل للنقر | `ListTile` | — | — | — | 288 |
| PAY-UI-BBB4DE | تبويب | `TabBar` | — | — | — | 66 |

### `lib/features/merchant/screens/split_bill_create_screen.dart` — PAY-SCR-8E28EB — **غير موصول (UNREACHABLE)**

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-EC6A2D | زر | `TextButton` | `تم` | — | onPressed | 105 |
| PAY-UI-7E57BA | زر | `TextButton` | `إضافة` | إضافة | onPressed | 152 |
| PAY-UI-A135DA | زر | `IconButton` | — | — | onPressed | 178 |
| PAY-UI-718C1E | زر | `ElevatedButton` | — | — | onPressed شرطي | 195 |
| PAY-UI-742258 | زر | `ElevatedButton` | — | — | — | 197 |
| PAY-UI-2DA008 | عنصر قابل للنقر | `ListTile` | — | — | — | 94 |
| PAY-UI-6C9758 | حقل إدخال | `TextFormField` | `إجمالي المبلغ` | — | — | 133 |
| PAY-UI-9B59CA | حقل إدخال | `TextFormField` | — | — | — | 165 |
| PAY-UI-8FA1AE | حقل إدخال | `TextFormField` | `ملاحظة (اختياري)` | — | onPressed | 186 |
| PAY-UI-EA1283 | نافذة/ورقة | `showDialog` | `تم إنشاء الفاتورة المقسّمة` | — | استدعاء | 78 |

### `lib/features/merchant/screens/split_bill_my_shares_screen.dart` — PAY-SCR-1DD447 — **غير موصول (UNREACHABLE)**

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-2B5A69 | زر | `TextButton` | `إلغاء` | إلغاء | onPressed | 33 |
| PAY-UI-8A8EB5 | زر | `ElevatedButton` | `ادفع` | — | onPressed | 34 |
| PAY-UI-C0D265 | زر | `ElevatedButton` | `ادفع` | — | — | 36 |
| PAY-UI-83E97F | زر | `ElevatedButton` | — | — | onPressed شرطي | 120 |
| PAY-UI-B4E9CF | زر | `ElevatedButton` | — | — | — | 122 |
| PAY-UI-8CCA51 | نافذة/ورقة | `showDialog` | `تأكيد دفع الحصة` | — | onPressed | 27 |

### `lib/features/merchant/screens/stock_alerts_screen.dart` — PAY-SCR-86D733 — **غير موصول (UNREACHABLE)**

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-DEA73D | زر | `OutlinedButton` | `إعادة التعبئة` | — | onPressed | 252 |
| PAY-UI-59B568 | زر | `OutlinedButton` | — | — | — | 260 |
| PAY-UI-39F3AE | زر | `IconButton` | — | — | onPressed | 289 |
| PAY-UI-A4D350 | زر | `IconButton` | — | — | onPressed | 303 |
| PAY-UI-DB1EF3 | حقل إدخال | `SwitchListTile` | `تفعيل التنبيهات التلقائية` | — | — | 105 |

### `lib/features/merchant/widgets/cash_tendered_field.dart` — — — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-80CE93 | زر | `TextButton` | `مسح` | — | onPressed | 101 |
| PAY-UI-81F50B | حقل إدخال | `TextField` | — | — | — | 107 |

### `lib/features/merchant/widgets/invoice_whatsapp_sheet.dart` — — — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-4486DC | زر | `FilledButton` | `فتح محادثة واتساب` | — | onPressed شرطي | 145 |
| PAY-UI-CBC8C8 | زر | `FilledButton` | — | — | onPressed شرطي | 151 |
| PAY-UI-27553D | زر | `OutlinedButton` | `مشاركة صورة الفاتورة` | — | onPressed شرطي | 155 |
| PAY-UI-9DF5F2 | حقل إدخال | `TextField` | `رقم جوال العميل` | — | — | 130 |
| PAY-UI-47D12E | نافذة/ورقة | `showModalBottomSheet` | — | — | استدعاء | 31 |

### `lib/features/merchant/widgets/merchant_invoice_actions.dart` — — — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-C143E4 | زر | `OutlinedButton` | `طباعة حرارية` | — | onPressed شرطي | 25 |
| PAY-UI-681E4D | زر | `OutlinedButton` | — | — | onPressed شرطي | 29 |
| PAY-UI-0E0E9F | زر | `OutlinedButton` | `تنزيل PDF` | — | onPressed شرطي | 36 |
| PAY-UI-6FCBDD | زر | `OutlinedButton` | — | — | onPressed شرطي | 40 |
| PAY-UI-506125 | زر | `FilledButton` | `مشاركة الفاتورة عبر واتساب` | — | onPressed شرطي | 48 |
| PAY-UI-F87157 | زر | `FilledButton` | — | — | — | 55 |

### `lib/features/merchant/widgets/merchant_payment_method_picker.dart` — — — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-D23772 | عنصر قابل للنقر | `InkWell` | — | — | onTap شرطي | 121 |
| PAY-UI-E3809C | عنصر قابل للنقر | `InkWell` | — | — | onTap شرطي | 201 |

### `lib/features/merchant/widgets/quick_calculator_sheet.dart` — — — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-51EAFB | زر | `TextButton` | `نسخ الناتج` | — | onPressed | 264 |
| PAY-UI-A0DEC6 | عنصر قابل للنقر | `InkWell` | — | — | onTap | 289 |
| PAY-UI-3CE54B | نافذة/ورقة | `showModalBottomSheet` | — | — | استدعاء | 36 |

### `lib/features/merchant/widgets/shift_gate.dart` — — — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-BFC551 | زر | `FilledButton` | `بدء الوردية` | — | onPressed شرطي | 198 |
| PAY-UI-3C3A0C | زر | `FilledButton` | `تحديث` | — | onPressed | 207 |
| PAY-UI-81D56F | زر | `TextButton` | `تحديث` | — | onPressed | 214 |
| PAY-UI-E166A4 | حقل إدخال | `TextField` | `النقد الافتتاحي في الدرج (العهدة)` | — | — | 184 |

### `lib/features/merchant/widgets/shift_status_tile.dart` — — — **غير موصول (UNREACHABLE)**

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-31CC61 | عنصر قابل للنقر | `ListTile` | — | — | onTap شرطي | 140 |
