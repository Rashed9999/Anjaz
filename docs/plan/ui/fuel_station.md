# ملحق — سجل العناصر التفاعلية: وحدة `fuel_station`

> **مولَّد آليًّا** بتاريخ 2026-11-15 من شجرة `1a1d8c65` (مطابقة المحتوى للفرع الافتراضي `claude/project-code-review-yjagv@751ea393`). دليل **ساكن** فقط: لا يثبت تشغيلًا ولا ضغط زر ولا حالة إنتاج. المعرّفات مشتقة من تجزئة ثابتة (لا تتغير بإضافة صفوف). لا يُحذف شيء استنادًا إلى هذا الملف.

استخراج آلي بالتعابير النمطية من مصدر Dart: نوع الودجت، مفتاح/نص التسمية القريب، وترجمته من `assets/language/ar.json` إن وُجد المفتاح. **لا يثبت** الشروط الفعلية ولا رد الخادم ولا حالات التحميل؛ كل صف `INTERACTION_UNVERIFIED`. الأعمدة الناقصة عن القالب المعياري (التحقق/رد النجاح/رد الخطأ) تُملأ عند الفحص التفاعلي — انظر المهمة `PAY-T-012` في `plan.md`.


### `lib/features/fuel_station/screens/fuel_cashier_screen.dart` — PAY-SCR-004220 — **غير موصول (UNREACHABLE)**

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-65D583 | زر | `FilledButton` | `استلام بـ QR (العميل يمسح ويدفع)` | — | onPressed شرطي | 250 |
| PAY-UI-1EF379 | زر | `FilledButton` | `نقد` | نقد | onPressed شرطي | 256 |
| PAY-UI-7C02CE | زر | `OutlinedButton` | `نقد` | نقد | onPressed شرطي | 261 |
| PAY-UI-165976 | زر | `OutlinedButton` | `السجل` | — | onPressed | 267 |
| PAY-UI-C05A2F | زر | `OutlinedButton` | `بيع تفصيلي: آجل أو حساب شركة` | — | onPressed | 274 |
| PAY-UI-D2AE54 | زر | `OutlinedButton` | `آخر العمليات` | — | — | 280 |
| PAY-UI-655509 | عنصر قابل للنقر | `InkWell` | — | — | onTap | 301 |
| PAY-UI-CC0AA4 | عنصر قابل للنقر | `InkWell` | — | — | onTap | 323 |
| PAY-UI-5B166C | عنصر قابل للنقر | `InkWell` | — | — | onTap | 340 |
| PAY-UI-3977D7 | عنصر قابل للنقر | `InkWell` | — | — | onTap | 359 |
| PAY-UI-B8E5EE | عنصر قابل للنقر | `ListTile` | — | — | onTap | 407 |
| PAY-UI-AB665C | عنصر قابل للنقر | `ListTile` | — | — | onTap | 423 |
| PAY-UI-FBC866 | نافذة/ورقة | `showModalBottomSheet` | `اختر المضخّة` | — | استدعاء | 403 |
| PAY-UI-20462E | نافذة/ورقة | `showModalBottomSheet` | `اختر نوع الوقود` | — | استدعاء | 419 |

### `lib/features/fuel_station/screens/fuel_companies_screen.dart` — PAY-SCR-5148C5 — **غير موصول (UNREACHABLE)**

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-AF8EF0 | زر | `FloatingActionButton` | `شركة جديدة` | — | onPressed | 31 |
| PAY-UI-3299DB | زر | `OutlinedButton` | `البطاقات` | — | onPressed | 124 |
| PAY-UI-2AE38D | زر | `OutlinedButton` | `سداد` | — | onPressed شرطي | 128 |
| PAY-UI-E0506E | زر | `FilledButton` | `سداد` | — | onPressed شرطي | 131 |
| PAY-UI-3CDA2C | زر | `FilledButton` | — | — | — | 135 |
| PAY-UI-671128 | زر | `TextButton` | `إلغاء` | إلغاء | onPressed | 168 |
| PAY-UI-A97AF0 | زر | `FilledButton` | — | — | onPressed شرطي | 169 |
| PAY-UI-7570B4 | زر | `TextButton` | `إلغاء` | إلغاء | onPressed | 215 |
| PAY-UI-71EFF2 | زر | `FilledButton` | — | — | onPressed شرطي | 216 |
| PAY-UI-A59568 | زر | `FilledButton` | — | — | onPressed شرطي | 217 |
| PAY-UI-9CC5B0 | زر | `FilledButton` | `جديدة` | — | onPressed | 257 |
| PAY-UI-82E518 | زر | `TextButton` | `إلغاء` | إلغاء | onPressed | 349 |
| PAY-UI-C8A326 | زر | `FilledButton` | — | — | onPressed | 350 |
| PAY-UI-60AB5B | حقل إدخال | `TextField` | `اسم الشركة *` | — | — | 153 |
| PAY-UI-29C9CC | حقل إدخال | `TextField` | `الشخص المسؤول` | — | — | 155 |
| PAY-UI-664CAE | حقل إدخال | `TextField` | `الهاتف` | الهاتف | — | 157 |
| PAY-UI-294BEB | حقل إدخال | `TextField` | `حد الائتمان` | — | — | 160 |
| PAY-UI-1B83BA | حقل إدخال | `TextField` | `الحد الشهري` | — | onPressed | 163 |
| PAY-UI-EE2C0C | حقل إدخال | `TextField` | `مبلغ السداد *` | — | — | 204 |
| PAY-UI-291F2A | حقل إدخال | `TextField` | `ملاحظة (اختياري)` | — | onPressed | 212 |
| PAY-UI-9511EC | حقل إدخال | `TextField` | `رقم البطاقة *` | — | — | 333 |
| PAY-UI-01365C | حقل إدخال | `TextField` | `وصف (اختياري)` | — | — | 335 |
| PAY-UI-AFCFA5 | حقل إدخال | `TextField` | `لوحة السيارة` | — | — | 337 |
| PAY-UI-F6F90A | حقل إدخال | `TextField` | `اسم السائق` | — | — | 339 |
| PAY-UI-D7506A | حقل إدخال | `TextField` | `الحد اليومي (0 = بلا حد)` | — | — | 341 |
| PAY-UI-BAE2E7 | حقل إدخال | `TextField` | `الحد الشهري (0 = بلا حد)` | — | onPressed | 344 |
| PAY-UI-6A37F8 | نافذة/ورقة | `showDialog` | `إضافة شركة` | — | استدعاء | 149 |
| PAY-UI-F9CFF0 | نافذة/ورقة | `showDialog` | — | — | استدعاء | 198 |
| PAY-UI-530E3B | نافذة/ورقة | `showModalBottomSheet` | — | — | استدعاء | 240 |
| PAY-UI-03B294 | نافذة/ورقة | `showDialog` | `رقم البطاقة *` | — | استدعاء | 329 |

### `lib/features/fuel_station/screens/fuel_deliveries_screen.dart` — PAY-SCR-CA79F3 — **غير موصول (UNREACHABLE)**

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-BC984C | زر | `FloatingActionButton` | `توريد جديد` | — | onPressed | 89 |
| PAY-UI-B25C30 | زر | `OutlinedButton` | `تحقّق بقياس الخزان` | — | onPressed | 144 |
| PAY-UI-B1A210 | زر | `ElevatedButton` | `ترحيل — يرفع المخزون` | — | onPressed | 154 |
| PAY-UI-CE711F | زر | `ElevatedButton` | `ترحيل — يرفع المخزون` | — | — | 157 |
| PAY-UI-24798A | زر | `TextButton` | `إلغاء` | إلغاء | onPressed | 256 |
| PAY-UI-E1056D | زر | `ElevatedButton` | `تسجيل` | — | onPressed | 257 |
| PAY-UI-5CB88A | زر | `TextButton` | `إلغاء` | إلغاء | onPressed | 308 |
| PAY-UI-A2CE1F | زر | `ElevatedButton` | `تحقّق` | — | onPressed | 309 |
| PAY-UI-C96DBC | زر | `TextButton` | `تراجع` | تراجع | onPressed | 335 |
| PAY-UI-C5501A | زر | `ElevatedButton` | `رحّل` | — | onPressed | 336 |
| PAY-UI-9667DE | زر | `ElevatedButton` | `رحّل` | — | — | 339 |
| PAY-UI-F2EC6E | حقل إدخال | `DropdownButtonFormField` | `الخزان` | — | — | 216 |
| PAY-UI-CD6EA7 | حقل إدخال | `TextField` | `الكمية حسب الفاتورة (لتر)` | — | — | 229 |
| PAY-UI-54A452 | حقل إدخال | `TextField` | `قياس الخزان قبل التفريغ (لتر)` | — | — | 236 |
| PAY-UI-AA8D0E | حقل إدخال | `TextField` | `رقم الفاتورة` | — | — | 246 |
| PAY-UI-7BFCDB | حقل إدخال | `TextField` | `سعر اللتر (اختياري)` | — | onPressed | 248 |
| PAY-UI-80B524 | حقل إدخال | `TextField` | `القياس قبل التفريغ` | — | — | 294 |
| PAY-UI-9F7627 | حقل إدخال | `TextField` | `القياس بعد التفريغ` | — | onPressed | 300 |
| PAY-UI-E0FB18 | نافذة/ورقة | `Get.dialog` | `توريد جديد` | — | استدعاء | 211 |
| PAY-UI-CC3CC9 | نافذة/ورقة | `Get.dialog` | `تحقق من التوريد` | — | استدعاء | 287 |
| PAY-UI-655DF0 | نافذة/ورقة | `Get.dialog` | `تأكيد الترحيل` | — | onPressed | 330 |

### `lib/features/fuel_station/screens/fuel_owner_console_screen.dart` — PAY-SCR-175410 — **غير موصول (UNREACHABLE)**

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-8D8E4F | زر | `IconButton` | `تحديث` | — | onPressed | 64 |
| PAY-UI-EBF58C | زر | `TextButton` | — | — | onPressed | 129 |
| PAY-UI-55C23D | زر | `TextButton` | — | — | onPressed | 197 |
| PAY-UI-F7D8DF | عنصر قابل للنقر | `ListTile` | — | — | — | 113 |
| PAY-UI-FBEB61 | عنصر قابل للنقر | `ListTile` | — | — | onPressed | 192 |
| PAY-UI-5C3368 | عنصر قابل للنقر | `ListTile` | — | — | onTap | 287 |

### `lib/features/fuel_station/screens/fuel_prices_screen.dart` — PAY-SCR-1A9D18 — **غير موصول (UNREACHABLE)**

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-773139 | زر | `ElevatedButton` | `اعتماد — يسري فوراً` | — | onPressed | 107 |
| PAY-UI-B93D84 | زر | `ElevatedButton` | `اعتماد — يسري فوراً` | — | — | 110 |
| PAY-UI-393365 | زر | `TextButton` | `اقتراح سعر` | — | onPressed | 137 |
| PAY-UI-AD27C9 | زر | `TextButton` | `إلغاء` | إلغاء | onPressed | 176 |
| PAY-UI-7DD194 | زر | `ElevatedButton` | `اقتراح` | — | onPressed | 177 |
| PAY-UI-716A53 | زر | `TextButton` | `تراجع` | تراجع | onPressed | 204 |
| PAY-UI-8E8B11 | زر | `ElevatedButton` | `اعتمد` | — | onPressed | 205 |
| PAY-UI-6AA7C8 | عنصر قابل للنقر | `ListTile` | — | — | — | 129 |
| PAY-UI-9571AF | حقل إدخال | `TextField` | `السعر المقترح (ريال/لتر)` | — | — | 157 |
| PAY-UI-38DB88 | حقل إدخال | `TextField` | `السبب` | السبب | onPressed | 166 |
| PAY-UI-59432E | نافذة/ورقة | `Get.dialog` | — | — | استدعاء | 151 |
| PAY-UI-84A6FB | نافذة/ورقة | `Get.dialog` | `تأكيد اعتماد السعر` | — | onPressed | 199 |

### `lib/features/fuel_station/screens/fuel_qr_collect_screen.dart` — PAY-SCR-EB0DE7 — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-65F53B | زر | `FilledButton` | `إعادة المحاولة` | — | onPressed | 204 |
| PAY-UI-4DDA05 | زر | `FilledButton` | `رجوع` | — | onPressed | 208 |
| PAY-UI-FE36CE | زر | `TextButton` | `رجوع` | — | onPressed | 213 |
| PAY-UI-FED2B5 | زر | `OutlinedButton` | `إلغاء الطلب` | — | onPressed | 249 |
| PAY-UI-FAF38E | زر | `OutlinedButton` | — | — | — | 253 |

### `lib/features/fuel_station/screens/fuel_receipt_screen.dart` — PAY-SCR-FC998F — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-98F6BC | زر | `IconButton` | — | — | onPressed | 230 |
| PAY-UI-C7CA92 | زر | `FilledButton` | `عملية جديدة` | — | onPressed | 290 |
| PAY-UI-933909 | زر | `FilledButton` | — | — | — | 295 |

### `lib/features/fuel_station/screens/fuel_roles_screen.dart` — PAY-SCR-EDC8B4 — **غير موصول (UNREACHABLE)**

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-DFEFE3 | زر | `FloatingActionButton` | `إنشاء الأدوار الستة` | — | onPressed | 58 |
| PAY-UI-3B9B41 | زر | `TextButton` | `إلغاء` | إلغاء | onPressed | 169 |
| PAY-UI-733833 | زر | `ElevatedButton` | `أنشئ` | — | onPressed | 170 |
| PAY-UI-ECF3DB | نافذة/ورقة | `Get.dialog` | `إنشاء الأدوار الستة` | — | onPressed | 162 |

### `lib/features/fuel_station/screens/fuel_sale_screen.dart` — PAY-SCR-86CC7C — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-E9C6ED | زر | `FilledButton` | `تأكيد البيع` | — | onPressed شرطي | 283 |
| PAY-UI-838A20 | زر | `FilledButton` | — | — | — | 289 |
| PAY-UI-C7AB8F | عنصر قابل للنقر | `InkWell` | — | — | onTap | 306 |
| PAY-UI-2CFF83 | عنصر قابل للنقر | `InkWell` | — | — | onTap | 334 |
| PAY-UI-6F27BA | عنصر قابل للنقر | `InkWell` | — | — | onTap | 357 |
| PAY-UI-E32F99 | حقل إدخال | `TextField` | `المبلغ` | — | — | 383 |
| PAY-UI-42ECFE | حقل إدخال | `TextField` | `اللترات` | — | — | 410 |
| PAY-UI-AC0B80 | حقل إدخال | `TextField` | `القراءة الفعلية بعد البيع` | — | — | 451 |
| PAY-UI-228B71 | حقل إدخال | `TextField` | — | — | — | 467 |

### `lib/features/fuel_station/screens/fuel_sales_history_screen.dart` — PAY-SCR-A80D65 — **غير موصول (UNREACHABLE)**

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-769875 | زر | `OutlinedButton` | — | — | onPressed | 208 |
| PAY-UI-EC6712 | زر | `OutlinedButton` | — | — | — | 252 |
| PAY-UI-4EDF85 | عنصر قابل للنقر | `InkWell` | — | — | onTap | 96 |
| PAY-UI-C6BB6A | عنصر قابل للنقر | `InkWell` | — | — | onTap | 127 |
| PAY-UI-E818F8 | نافذة/ورقة | `showModalBottomSheet` | — | — | استدعاء | 179 |

### `lib/features/fuel_station/screens/fuel_settings_screen.dart` — PAY-SCR-9C6E56 — **غير موصول (UNREACHABLE)**

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-CB9B10 | زر | `FloatingActionButton` | `مضخّة جديدة` | — | onPressed | 155 |
| PAY-UI-589775 | زر | `TextButton` | `إلغاء` | إلغاء | onPressed | 289 |
| PAY-UI-8E768A | زر | `FilledButton` | — | — | onPressed شرطي | 290 |
| PAY-UI-F97E6A | زر | `FloatingActionButton` | `نوع جديد` | — | onPressed | 323 |
| PAY-UI-D7E157 | زر | `TextButton` | `إلغاء` | إلغاء | onPressed | 401 |
| PAY-UI-270445 | زر | `FilledButton` | — | — | onPressed شرطي | 402 |
| PAY-UI-EAD3E6 | زر | `TextButton` | `إلغاء` | إلغاء | onPressed | 452 |
| PAY-UI-AB17C1 | زر | `FilledButton` | — | — | onPressed شرطي | 453 |
| PAY-UI-BED33E | زر | `FilledButton` | — | — | onPressed شرطي | 489 |
| PAY-UI-E9B3F5 | زر | `FilledButton` | — | — | — | 506 |
| PAY-UI-32CF60 | عنصر قابل للنقر | `InkWell` | — | — | onTap | 346 |
| PAY-UI-5F3D2E | حقل إدخال | `Switch` | — | — | onChanged | 225 |
| PAY-UI-DF9FFB | حقل إدخال | `TextField` | `رقم المضخّة *` | — | — | 245 |
| PAY-UI-0F4163 | حقل إدخال | `TextField` | `الوصف (اختياري)` | — | — | 252 |
| PAY-UI-A8C369 | حقل إدخال | `TextField` | `قراءة العدّاد الحالية` | — | onPressed | 280 |
| PAY-UI-9FDC06 | حقل إدخال | `TextField` | `الاسم * (بنزين 91...)` | — | — | 390 |
| PAY-UI-C21CD4 | حقل إدخال | `TextField` | `الرمز (اختياري)` | — | — | 392 |
| PAY-UI-DDF09A | حقل إدخال | `TextField` | `السعر للّتر *` | — | onPressed | 394 |
| PAY-UI-60A2F0 | حقل إدخال | `TextField` | `السعر الجديد للّتر` | — | — | 434 |
| PAY-UI-A6CA1F | حقل إدخال | `TextField` | `سبب التغيير (اختياري)` | — | onPressed | 442 |
| PAY-UI-464DEF | حقل إدخال | `TextField` | — | — | — | 519 |
| PAY-UI-368056 | نافذة/ورقة | `showDialog` | `إضافة مضخّة` | — | استدعاء | 240 |
| PAY-UI-8275F8 | نافذة/ورقة | `showDialog` | `إضافة نوع وقود` | — | استدعاء | 387 |
| PAY-UI-838593 | نافذة/ورقة | `showDialog` | — | — | استدعاء | 429 |
| PAY-UI-D19B96 | تبويب | `TabBar` | — | — | — | 38 |

### `lib/features/fuel_station/screens/fuel_shift_cash_screen.dart` — PAY-SCR-F00714 — **غير موصول (UNREACHABLE)**

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-78EC5E | زر | `FloatingActionButton` | `حركة نقد` | — | onPressed | 58 |
| PAY-UI-2D07FB | زر | `TextButton` | `إلغاء` | إلغاء | onPressed | 172 |
| PAY-UI-184C91 | زر | `ElevatedButton` | `تسجيل` | — | onPressed | 173 |
| PAY-UI-64D7CE | عنصر قابل للنقر | `ListTile` | — | — | — | 106 |
| PAY-UI-7D268E | حقل إدخال | `DropdownButtonFormField` | `السبب` | السبب | — | 139 |
| PAY-UI-83250E | حقل إدخال | `TextField` | `المبلغ` | — | — | 153 |
| PAY-UI-26F1AD | حقل إدخال | `TextField` | `ملاحظة` | — | onPressed | 166 |
| PAY-UI-8B0AB0 | نافذة/ورقة | `Get.dialog` | `حركة نقد` | — | استدعاء | 135 |

### `lib/features/fuel_station/screens/fuel_shifts_screen.dart` — PAY-SCR-338339 — **غير موصول (UNREACHABLE)**

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-F27C34 | زر | `FilledButton` | `فتح نوبة جديدة` | — | onPressed | 83 |
| PAY-UI-583855 | زر | `FilledButton` | — | — | — | 87 |
| PAY-UI-D134C5 | زر | `OutlinedButton` | `مصروفات وحركة النقد` | — | onPressed | 140 |
| PAY-UI-D79D16 | زر | `OutlinedButton` | `إغلاق النوبة` | — | onPressed | 146 |
| PAY-UI-16AC02 | زر | `FilledButton` | `إغلاق النوبة` | — | onPressed | 154 |
| PAY-UI-02BF3B | زر | `FilledButton` | — | — | — | 158 |
| PAY-UI-20DE5B | زر | `TextButton` | `إلغاء` | إلغاء | onPressed | 186 |
| PAY-UI-3C5ACE | زر | `FilledButton` | — | — | onPressed شرطي | 187 |
| PAY-UI-DB83D9 | زر | `FilledButton` | — | — | onPressed شرطي | 188 |
| PAY-UI-A0F9FF | زر | `TextButton` | `إلغاء` | إلغاء | onPressed | 295 |
| PAY-UI-815493 | زر | `FilledButton` | — | — | onPressed شرطي | 296 |
| PAY-UI-022F36 | زر | `FilledButton` | — | — | onPressed شرطي | 297 |
| PAY-UI-65F366 | زر | `FilledButton` | `حسناً` | — | onPressed | 375 |
| PAY-UI-285C29 | زر | `FilledButton` | `حسناً` | — | onPressed | 376 |
| PAY-UI-48A2CE | حقل إدخال | `TextField` | `النقد الافتتاحي في الصندوق` | — | — | 175 |
| PAY-UI-96B84A | حقل إدخال | `TextField` | `ملاحظة (اختياري)` | — | onPressed | 182 |
| PAY-UI-F76BB6 | حقل إدخال | `TextField` | — | — | — | 261 |
| PAY-UI-471F1F | حقل إدخال | `TextField` | `النقد الفعلي في الصندوق *` | — | — | 275 |
| PAY-UI-85B6CD | حقل إدخال | `TextField` | `سبب الفرق (إن وُجد)` | — | — | 286 |
| PAY-UI-1B3985 | حقل إدخال | `TextField` | `ملاحظات الإغلاق` | — | onPressed | 289 |
| PAY-UI-69F376 | نافذة/ورقة | `showDialog` | `فتح نوبة جديدة` | — | استدعاء | 172 |
| PAY-UI-F981EB | نافذة/ورقة | `showDialog` | `إغلاق النوبة` | — | استدعاء | 221 |
| PAY-UI-C76C88 | نافذة/ورقة | `showDialog` | — | — | استدعاء | 353 |

### `lib/features/fuel_station/screens/fuel_tanks_screen.dart` — PAY-SCR-719567 — **غير موصول (UNREACHABLE)**

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-679E69 | زر | `FloatingActionButton` | `خزان جديد` | — | onPressed | 54 |
| PAY-UI-DB31D5 | زر | `OutlinedButton` | `تسجيل قياس` | — | onPressed | 134 |
| PAY-UI-624EA7 | زر | `ElevatedButton` | `مصالحة` | — | onPressed | 145 |
| PAY-UI-20DD14 | زر | `ElevatedButton` | `مصالحة` | — | — | 148 |
| PAY-UI-8FEDEF | زر | `TextButton` | `إلغاء` | إلغاء | onPressed | 202 |
| PAY-UI-D0BC1A | زر | `ElevatedButton` | `حفظ` | حفظ | onPressed | 203 |
| PAY-UI-C20238 | زر | `TextButton` | `إلغاء` | إلغاء | onPressed | 263 |
| PAY-UI-2F3E3D | زر | `ElevatedButton` | `احسب` | — | onPressed | 264 |
| PAY-UI-3AE462 | زر | `TextButton` | `إغلاق` | إغلاق | onPressed | 313 |
| PAY-UI-9B2A39 | زر | `ElevatedButton` | `حفظ المصالحة` | — | onPressed | 314 |
| PAY-UI-7F068D | زر | `TextButton` | `لاحقاً` | — | onPressed | 364 |
| PAY-UI-1E80A0 | زر | `ElevatedButton` | `إعدادات المحطّة` | — | onPressed | 367 |
| PAY-UI-D3828A | زر | `TextButton` | `إلغاء` | إلغاء | onPressed | 430 |
| PAY-UI-C88CE6 | زر | `ElevatedButton` | `حفظ` | حفظ | onPressed | 431 |
| PAY-UI-01A841 | حقل إدخال | `TextField` | `الكمية المقيسة (لتر)` | — | — | 172 |
| PAY-UI-178E11 | حقل إدخال | `DropdownButtonFormField` | `نوع القياس` | — | — | 184 |
| PAY-UI-45E7BA | حقل إدخال | `TextField` | `ملاحظة (اختياري)` | — | onPressed | 196 |
| PAY-UI-145D0D | حقل إدخال | `TextField` | `القياس الختامي الفعلي (لتر)` | — | — | 250 |
| PAY-UI-8A2D9C | حقل إدخال | `TextField` | `رقم الخزان` | — | — | 393 |
| PAY-UI-CAD35E | حقل إدخال | `TextField` | `الاسم (اختياري)` | — | — | 399 |
| PAY-UI-D5D442 | حقل إدخال | `DropdownButtonFormField` | `نوع الوقود *` | — | — | 402 |
| PAY-UI-75798A | حقل إدخال | `TextField` | `السعة (لتر)` | — | — | 416 |
| PAY-UI-19B41D | حقل إدخال | `TextField` | `حد التنبيه (لتر)` | — | onPressed | 422 |
| PAY-UI-664C55 | نافذة/ورقة | `Get.dialog` | — | — | استدعاء | 167 |
| PAY-UI-0F344C | نافذة/ورقة | `Get.dialog` | `المعادلة: الافتتاحي + المورَّد − المباع = المتوقَّع، ` | — | استدعاء | 241 |
| PAY-UI-6DFC84 | نافذة/ورقة | `Get.dialog` | — | — | استدعاء | 292 |
| PAY-UI-74DD30 | نافذة/ورقة | `Get.dialog` | `لا أنواعَ وقودٍ في المحطّة` | — | onPressed | 357 |
| PAY-UI-28E087 | نافذة/ورقة | `Get.dialog` | `خزان جديد` | — | استدعاء | 388 |

### `lib/features/fuel_station/screens/fuel_variances_screen.dart` — PAY-SCR-5ADAE4 — **غير موصول (UNREACHABLE)**

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-39CF6B | زر | `OutlinedButton` | `إغلاق التحقيق` | — | onPressed | 142 |
| PAY-UI-2DE316 | زر | `TextButton` | `إلغاء` | إلغاء | onPressed | 206 |
| PAY-UI-4C910A | زر | `ElevatedButton` | `إغلاق` | إغلاق | onPressed | 207 |
| PAY-UI-2B6152 | حقل إدخال | `TextField` | `نتيجة التحقيق` | — | — | 183 |
| PAY-UI-E7FDED | حقل إدخال | `DropdownButtonFormField` | `الحالة` | — | onChanged | 194 |
| PAY-UI-E62A0C | نافذة/ورقة | `Get.dialog` | `إغلاق التحقيق` | — | استدعاء | 173 |

### `lib/features/fuel_station/widgets/fuel_shift_gate.dart` — — — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-0EFFE9 | زر | `FilledButton` | `تحديث حالة الوردية` | — | onPressed | 139 |
| PAY-UI-5E9031 | زر | `FilledButton` | — | — | — | 143 |
