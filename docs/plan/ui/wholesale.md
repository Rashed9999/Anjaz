# ملحق — سجل العناصر التفاعلية: وحدة `wholesale`

> **مولَّد آليًّا** بتاريخ 2026-11-15 من شجرة `1a1d8c65` (مطابقة المحتوى للفرع الافتراضي `claude/project-code-review-yjagv@751ea393`). دليل **ساكن** فقط: لا يثبت تشغيلًا ولا ضغط زر ولا حالة إنتاج. المعرّفات مشتقة من تجزئة ثابتة (لا تتغير بإضافة صفوف). لا يُحذف شيء استنادًا إلى هذا الملف.

استخراج آلي بالتعابير النمطية من مصدر Dart: نوع الودجت، مفتاح/نص التسمية القريب، وترجمته من `assets/language/ar.json` إن وُجد المفتاح. **لا يثبت** الشروط الفعلية ولا رد الخادم ولا حالات التحميل؛ كل صف `INTERACTION_UNVERIFIED`. الأعمدة الناقصة عن القالب المعياري (التحقق/رد النجاح/رد الخطأ) تُملأ عند الفحص التفاعلي — انظر المهمة `PAY-T-012` في `plan.md`.


### `lib/features/wholesale/screens/wholesale_policy_screens.dart` — PAY-SCR-36679A — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-E3A388 | زر | `FloatingActionButton` | `فاتورة جديدة` | — | onPressed | 397 |
| PAY-UI-4328F2 | زر | `IconButton` | `PDF` | — | onPressed | 510 |
| PAY-UI-3A212F | زر | `FilledButton` | `تسجيل تحصيل` | — | onPressed | 560 |
| PAY-UI-F317D2 | زر | `OutlinedButton` | `إبطال الفاتورة` | — | onPressed | 568 |
| PAY-UI-65B75E | زر | `OutlinedButton` | `طلب مرتجع` | — | onPressed | 576 |
| PAY-UI-CBE3FF | زر | `FilledButton` | — | — | onPressed | 629 |
| PAY-UI-69187F | زر | `TextButton` | `إلغاء` | إلغاء | onPressed | 698 |
| PAY-UI-AD585A | زر | `FilledButton` | `تأكيد الإبطال` | — | onPressed | 699 |
| PAY-UI-B3F707 | زر | `TextButton` | `إلغاء` | إلغاء | onPressed | 893 |
| PAY-UI-71626E | زر | `FilledButton` | `إضافة` | إضافة | onPressed | 894 |
| PAY-UI-318D4E | زر | `FilledButton` | `حفظ بيانات المنشأة` | — | onPressed شرطي | 966 |
| PAY-UI-263312 | زر | `TextButton` | `إضافة` | إضافة | onPressed | 976 |
| PAY-UI-1090E4 | زر | `OutlinedButton` | `إعادة المحاولة` | — | onPressed | 1359 |
| PAY-UI-BD2841 | زر | `FilledButton` | — | — | onPressed | 1367 |
| PAY-UI-D4F225 | زر | `FilledButton` | `مقارنة الباقات` | — | onPressed | 1392 |
| PAY-UI-92B3DE | عنصر قابل للنقر | `ListTile` | — | — | — | 550 |
| PAY-UI-A2C225 | عنصر قابل للنقر | `ListTile` | `افتراضية` | — | — | 981 |
| PAY-UI-D85109 | عنصر قابل للنقر | `ListTile` | — | — | — | 1075 |
| PAY-UI-2233C4 | عنصر قابل للنقر | `ListTile` | — | — | — | 1110 |
| PAY-UI-C14EB1 | عنصر قابل للنقر | `ListTile` | — | — | — | 1145 |
| PAY-UI-1DC113 | عنصر قابل للنقر | `InkWell` | — | — | onTap | 1185 |
| PAY-UI-4D03D8 | عنصر قابل للنقر | `ListTile` | — | — | onTap | 1251 |
| PAY-UI-1EE180 | حقل إدخال | `TextField` | `المبلغ` | — | — | 603 |
| PAY-UI-2BBE24 | حقل إدخال | `TextField` | `رقم المرجع (اختياري)` | — | onPressed | 622 |
| PAY-UI-0D8611 | حقل إدخال | `TextField` | `سبب الإبطال *` | — | onPressed | 692 |
| PAY-UI-79DDF5 | حقل إدخال | `TextField` | `اسم الشريحة *` | — | — | 871 |
| PAY-UI-6DDB3E | حقل إدخال | `TextField` | `رمز الشريحة * (مثل vip)` | — | — | 877 |
| PAY-UI-F079A9 | حقل إدخال | `CheckboxListTile` | `اجعلها الشريحة الافتراضية` | — | onChanged | 884 |
| PAY-UI-802DC7 | حقل إدخال | `TextField` | — | — | — | 996 |
| PAY-UI-14516E | نافذة/ورقة | `showModalBottomSheet` | `تسجيل تحصيل` | — | استدعاء | 592 |
| PAY-UI-A1029E | نافذة/ورقة | `showDialog` | `إبطال الفاتورة` | — | onPressed | 688 |
| PAY-UI-21C5FF | نافذة/ورقة | `showDialog` | `شريحة سعر جديدة` | — | استدعاء | 864 |
| PAY-UI-EA8081 | نافذة/ورقة | `showModalBottomSheet` | — | — | استدعاء | 1378 |

### `lib/features/wholesale/screens/wholesale_pro_screens.dart` — PAY-SCR-743D95 — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-A6AE85 | زر | `FloatingActionButton` | `منتج جديد` | منتج جديد | onPressed | 490 |
| PAY-UI-405766 | زر | `IconButton` | `أسعار الجملة` | — | onPressed | 592 |
| PAY-UI-F31C3A | زر | `IconButton` | `الوحدات والدفعات` | — | onPressed | 599 |
| PAY-UI-82E1C1 | زر | `IconButton` | `تعديل` | — | onPressed | 605 |
| PAY-UI-936D58 | زر | `FilledButton` | — | — | onPressed شرطي | 666 |
| PAY-UI-6E855E | زر | `IconButton` | — | — | onPressed | 724 |
| PAY-UI-6F17B0 | زر | `OutlinedButton` | `إضافة وحدة بيع` | — | onPressed | 743 |
| PAY-UI-D3868F | زر | `TextButton` | `إلغاء` | إلغاء | onPressed | 756 |
| PAY-UI-9617C8 | زر | `FilledButton` | `حفظ` | حفظ | onPressed | 757 |
| PAY-UI-480960 | زر | `FilledButton` | `استلام دفعة` | — | onPressed | 781 |
| PAY-UI-11EACA | زر | `TextButton` | `إلغاء` | إلغاء | onPressed | 796 |
| PAY-UI-43604D | زر | `FilledButton` | `استلام` | — | onPressed | 797 |
| PAY-UI-4564FB | زر | `IconButton` | — | — | onPressed | 882 |
| PAY-UI-5933E6 | زر | `FilledButton` | — | — | onPressed | 986 |
| PAY-UI-0E8E93 | زر | `FilledButton` | — | — | onPressed | 987 |
| PAY-UI-5FD0E3 | زر | `OutlinedButton` | `تعديل الحد` | — | onPressed | 1318 |
| PAY-UI-777487 | زر | `FilledButton` | `تعديل المخزون` | — | onPressed | 1326 |
| PAY-UI-C8B5A6 | زر | `FilledButton` | `تعديل المخزون` | — | onPressed | 1327 |
| PAY-UI-643F65 | زر | `TextButton` | `إلغاء` | إلغاء | onPressed | 1349 |
| PAY-UI-6B0BCD | زر | `FilledButton` | — | — | onPressed | 1350 |
| PAY-UI-81D12C | زر | `TextButton` | `إلغاء` | إلغاء | onPressed | 1385 |
| PAY-UI-F56E49 | زر | `FilledButton` | — | — | onPressed | 1386 |
| PAY-UI-C44A73 | زر | `FilledButton` | `مقارنة الباقات` | — | onPressed | 1672 |
| PAY-UI-6CCA80 | زر | `IconButton` | — | — | onPressed | 1811 |
| PAY-UI-CC2DD3 | زر | `OutlinedButton` | `إعادة المحاولة` | — | onPressed | 1904 |
| PAY-UI-A0F247 | زر | `FilledButton` | — | — | onPressed | 1912 |
| PAY-UI-248081 | عنصر قابل للنقر | `InkWell` | — | — | onTap | 255 |
| PAY-UI-B0159B | عنصر قابل للنقر | `InkWell` | — | — | onTap | 289 |
| PAY-UI-8FE03B | عنصر قابل للنقر | `InkWell` | — | — | onTap | 351 |
| PAY-UI-1CE02A | عنصر قابل للنقر | `ListTile` | — | — | — | 648 |
| PAY-UI-4BA427 | عنصر قابل للنقر | `ListTile` | `أساس` | — | — | 735 |
| PAY-UI-107C08 | عنصر قابل للنقر | `ListTile` | — | — | — | 774 |
| PAY-UI-ED42A1 | عنصر قابل للنقر | `ListTile` | — | — | — | 1138 |
| PAY-UI-6B2DD7 | عنصر قابل للنقر | `InkWell` | — | — | onTap | 1550 |
| PAY-UI-B7EF72 | حقل إدخال | `DropdownButtonFormField` | `شريحة السعر` | — | onChanged شرطي | 657 |
| PAY-UI-72408F | حقل إدخال | `DropdownButtonFormField` | `الوحدة` | الوحدة | onChanged | 790 |
| PAY-UI-5F07F5 | حقل إدخال | `TextField` | — | — | onSubmitted | 1804 |
| PAY-UI-BEBE67 | حقل إدخال | `TextField` | — | — | — | 1844 |
| PAY-UI-A8BBE1 | نافذة/ورقة | `showModalBottomSheet` | — | — | استدعاء | 634 |
| PAY-UI-686662 | نافذة/ورقة | `showModalBottomSheet` | — | — | استدعاء | 711 |
| PAY-UI-967F8C | نافذة/ورقة | `showDialog` | `وحدة بيع جديدة` | — | استدعاء | 749 |
| PAY-UI-FD0580 | نافذة/ورقة | `showDialog` | `استلام دفعة` | — | استدعاء | 787 |
| PAY-UI-B88A11 | نافذة/ورقة | `showModalBottomSheet` | — | — | استدعاء | 848 |
| PAY-UI-19A9C6 | نافذة/ورقة | `showDialog` | `تعديل حد التنبيه` | — | onPressed | 1343 |
| PAY-UI-B9CC58 | نافذة/ورقة | `showDialog` | — | — | استدعاء | 1373 |
| PAY-UI-873861 | نافذة/ورقة | `showModalBottomSheet` | — | — | استدعاء | 1649 |
| PAY-UI-BB8544 | تبويب | `TabBar` | — | — | — | 729 |

### `lib/features/wholesale/screens/wholesale_screens_secondary.dart` — PAY-SCR-4C62B3 — **غير موصول (UNREACHABLE)**

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-A19F62 | زر | `IconButton` | `المتأخّرة فقط` | — | onPressed | 32 |
| PAY-UI-C27534 | زر | `IconButton` | `تحميل PDF` | — | onPressed شرطي | 133 |
| PAY-UI-435FE7 | زر | `FilledButton` | — | — | onPressed | 247 |
| PAY-UI-3B8125 | زر | `FilledButton` | — | — | — | 251 |
| PAY-UI-D4936E | زر | `TextButton` | `إلغاء` | إلغاء | onPressed | 298 |
| PAY-UI-009168 | زر | `FilledButton` | — | — | onPressed شرطي | 299 |
| PAY-UI-1F9223 | عنصر قابل للنقر | `InkWell` | — | — | onTap | 68 |
| PAY-UI-038F1F | حقل إدخال | `TextField` | `المبلغ *` | — | — | 281 |
| PAY-UI-697C64 | حقل إدخال | `TextField` | `رقم المرجع (اختياري)` | — | onPressed | 295 |
| PAY-UI-BB7622 | نافذة/ورقة | `showDialog` | `تسجيل تحصيل` | — | استدعاء | 278 |

### `lib/features/wholesale/screens/wholesale_workflow_screens.dart` — PAY-SCR-4840C2 — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-A829D2 | زر | `TextButton` | `إلغاء` | إلغاء | onPressed | 90 |
| PAY-UI-6694F1 | زر | `FilledButton` | `حفظ` | حفظ | onPressed | 91 |
| PAY-UI-A68365 | زر | `FloatingActionButton` | `عميل جديد` | عميل جديد | onPressed | 123 |
| PAY-UI-391E83 | زر | `IconButton` | — | — | onPressed | 136 |
| PAY-UI-ECC599 | زر | `FloatingActionButton` | `فاتورة جديدة` | — | onPressed | 177 |
| PAY-UI-D7F480 | زر | `IconButton` | `تنزيل الفاتورة PDF` | — | onPressed | 214 |
| PAY-UI-4E3E68 | زر | `IconButton` | — | — | onPressed | 414 |
| PAY-UI-D58399 | زر | `IconButton` | — | — | onPressed | 416 |
| PAY-UI-DE230F | زر | `FilledButton` | `إلغاء` | إلغاء | onPressed شرطي | 421 |
| PAY-UI-4A20FF | زر | `TextButton` | `إلغاء` | إلغاء | onPressed | 422 |
| PAY-UI-450A4B | زر | `IconButton` | `تعديل الكمية` | — | onPressed | 496 |
| PAY-UI-4FB5BE | زر | `IconButton` | `حذف الصنف` | — | onPressed | 497 |
| PAY-UI-E0824E | زر | `OutlinedButton` | `إضافة صنف إلى الفاتورة` | — | onPressed | 502 |
| PAY-UI-250094 | زر | `FilledButton` | — | — | onPressed شرطي | 532 |
| PAY-UI-6F4882 | زر | `TextButton` | `إلغاء` | إلغاء | onPressed | 576 |
| PAY-UI-06F198 | زر | `FilledButton` | `حفظ` | حفظ | onPressed | 576 |
| PAY-UI-FB5178 | زر | `FloatingActionButton` | `مندوب جديد` | — | onPressed | 587 |
| PAY-UI-A68AD9 | زر | `OutlinedButton` | `رفض` | — | onPressed | 637 |
| PAY-UI-AC042B | زر | `FilledButton` | `اعتماد` | — | onPressed | 637 |
| PAY-UI-93CE47 | زر | `FilledButton` | `إرسال للمراجعة` | — | onPressed شرطي | 654 |
| PAY-UI-1884D6 | عنصر قابل للنقر | `ListTile` | — | — | — | 144 |
| PAY-UI-6B6A62 | عنصر قابل للنقر | `ListTile` | — | — | onTap | 177 |
| PAY-UI-DFBFAA | عنصر قابل للنقر | `ListTile` | `document_verify_invoice` | تحقق من أصالة الفاتورة | — | 236 |
| PAY-UI-5A702A | عنصر قابل للنقر | `ListTile` | `اختيار العميل` | — | onChanged | 319 |
| PAY-UI-32C1B0 | عنصر قابل للنقر | `ListTile` | — | — | — | 331 |
| PAY-UI-5DFE4D | عنصر قابل للنقر | `ListTile` | `إضافة أصناف` | — | onChanged | 357 |
| PAY-UI-3B7F8E | عنصر قابل للنقر | `ListTile` | — | — | — | 369 |
| PAY-UI-0031BD | عنصر قابل للنقر | `InkWell` | — | — | onTap | 480 |
| PAY-UI-C43E02 | عنصر قابل للنقر | `ListTile` | — | — | — | 491 |
| PAY-UI-62184E | عنصر قابل للنقر | `ListTile` | `العملاء المتأخرون` | — | — | 557 |
| PAY-UI-CB799D | عنصر قابل للنقر | `ListTile` | — | — | — | 559 |
| PAY-UI-5FE9BC | عنصر قابل للنقر | `ListTile` | — | — | — | 602 |
| PAY-UI-0CFA81 | عنصر قابل للنقر | `ListTile` | — | — | — | 624 |
| PAY-UI-9ED384 | عنصر قابل للنقر | `ListTile` | `رفض` | — | onPressed | 637 |
| PAY-UI-6DF505 | حقل إدخال | `DropdownButtonFormField` | `شريحة السعر` | — | — | 75 |
| PAY-UI-BE474A | حقل إدخال | `TextField` | `ابحث بالاسم أو الهاتف أو المنشأة` | — | onPressed | 132 |
| PAY-UI-F534F5 | حقل إدخال | `TextField` | `ابحث بالاسم أو الهاتف أو المنشأة` | — | onChanged | 320 |
| PAY-UI-8D8D22 | حقل إدخال | `TextField` | `ابحث بالاسم أو الباركود أو الرمز` | — | onChanged | 358 |
| PAY-UI-9CA09F | حقل إدخال | `DropdownButtonFormField` | `وحدة البيع` | — | onChanged | 409 |
| PAY-UI-26580D | حقل إدخال | `TextField` | — | — | onChanged | 415 |
| PAY-UI-2DA7A0 | حقل إدخال | `CheckboxListTile` | `كمية` | — | onChanged | 654 |
| PAY-UI-E514A0 | حقل إدخال | `TextField` | — | — | — | 660 |
| PAY-UI-9F6182 | نافذة/ورقة | `showDialog` | `اسم العميل *` | — | استدعاء | 64 |
| PAY-UI-90FC77 | نافذة/ورقة | `showModalBottomSheet` | — | — | استدعاء | 311 |
| PAY-UI-063DC5 | نافذة/ورقة | `showModalBottomSheet` | — | — | استدعاء | 349 |
| PAY-UI-E7D96D | نافذة/ورقة | `showModalBottomSheet` | — | — | استدعاء | 399 |
| PAY-UI-73C76E | نافذة/ورقة | `showDialog` | `مندوب مبيعات جديد` | — | استدعاء | 576 |

### `lib/features/wholesale/widgets/wholesale_entitlement_gate.dart` — — — **غير موصول (UNREACHABLE)**

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-F32AC3 | زر | `FilledButton` | — | — | onPressed | 295 |
