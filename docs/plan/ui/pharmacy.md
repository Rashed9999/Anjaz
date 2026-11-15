# ملحق — سجل العناصر التفاعلية: وحدة `pharmacy`

> **مولَّد آليًّا** بتاريخ 2026-11-15 من شجرة `1a1d8c65` (مطابقة المحتوى للفرع الافتراضي `claude/project-code-review-yjagv@751ea393`). دليل **ساكن** فقط: لا يثبت تشغيلًا ولا ضغط زر ولا حالة إنتاج. المعرّفات مشتقة من تجزئة ثابتة (لا تتغير بإضافة صفوف). لا يُحذف شيء استنادًا إلى هذا الملف.

استخراج آلي بالتعابير النمطية من مصدر Dart: نوع الودجت، مفتاح/نص التسمية القريب، وترجمته من `assets/language/ar.json` إن وُجد المفتاح. **لا يثبت** الشروط الفعلية ولا رد الخادم ولا حالات التحميل؛ كل صف `INTERACTION_UNVERIFIED`. الأعمدة الناقصة عن القالب المعياري (التحقق/رد النجاح/رد الخطأ) تُملأ عند الفحص التفاعلي — انظر المهمة `PAY-T-012` في `plan.md`.


### `lib/features/pharmacy/screens/pharmacy_dashboard_screen.dart` — PAY-SCR-E0DDCA — **غير موصول (UNREACHABLE)**

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-460D59 | زر | `IconButton` | `مخزون منخفض فقط` | مخزون منخفض فقط | onPressed | 262 |
| PAY-UI-EC33A9 | زر | `FloatingActionButton` | `منتج جديد` | منتج جديد | onPressed | 269 |
| PAY-UI-77E154 | زر | `IconButton` | `بدائل الدواء` | بدائل الدواء | onPressed | 334 |
| PAY-UI-8884EE | زر | `OutlinedButton` | `بدائل` | بدائل | onPressed | 365 |
| PAY-UI-260097 | زر | `FilledButton` | `جديد` | جديد | onPressed | 374 |
| PAY-UI-6A86F1 | زر | `IconButton` | `سحب التشغيلة` | سحب التشغيلة | onPressed | 439 |
| PAY-UI-444AEE | زر | `IconButton` | `إخراج الدفعة المنتهية` | إخراج الدفعة المنتهية | onPressed | 448 |
| PAY-UI-C63DCB | زر | `TextButton` | `تراجع` | تراجع | onPressed | 513 |
| PAY-UI-6AC1EF | زر | `FilledButton` | `تأكيد الإخراج` | تأكيد الإخراج | onPressed | 514 |
| PAY-UI-DFC826 | زر | `TextButton` | `تراجع` | تراجع | onPressed | 536 |
| PAY-UI-B4255A | زر | `FilledButton` | `سحب التشغيلة` | سحب التشغيلة | onPressed | 537 |
| PAY-UI-3D7A4E | زر | `FilledButton` | `سحب التشغيلة` | سحب التشغيلة | onPressed | 537 |
| PAY-UI-5BEFD3 | زر | `TextButton` | `إلغاء` | إلغاء | onPressed | 602 |
| PAY-UI-BC4F84 | زر | `FilledButton` | — | — | onPressed شرطي | 603 |
| PAY-UI-B6F838 | زر | `TextButton` | `إلغاء` | إلغاء | onPressed | 731 |
| PAY-UI-31EEF0 | زر | `FilledButton` | — | — | onPressed شرطي | 732 |
| PAY-UI-C5E945 | زر | `FloatingActionButton` | `عميل جديد` | عميل جديد | onPressed | 801 |
| PAY-UI-123E12 | زر | `TextButton` | `إلغاء` | إلغاء | onPressed | 914 |
| PAY-UI-837CB1 | زر | `FilledButton` | — | — | onPressed شرطي | 915 |
| PAY-UI-D1D74B | زر | `IconButton` | `فحص التواريخ` | فحص التواريخ | onPressed | 971 |
| PAY-UI-36D237 | زر | `IconButton` | `إغلاق` | إغلاق | onPressed | 1054 |
| PAY-UI-F78E5E | زر | `FilledButton` | `تنزيل الفاتورة وفتحها` | تنزيل الفاتورة وفتحها | onPressed | 1213 |
| PAY-UI-7DABB4 | عنصر قابل للنقر | `ListTile` | `آلة حاسبة` | آلة حاسبة | — | 140 |
| PAY-UI-FBEA1E | عنصر قابل للنقر | `InkWell` | — | — | onTap | 196 |
| PAY-UI-4716FB | عنصر قابل للنقر | `InkWell` | — | — | onTap | 217 |
| PAY-UI-EBAB24 | عنصر قابل للنقر | `InkWell` | — | — | onTap | 301 |
| PAY-UI-408993 | عنصر قابل للنقر | `ListTile` | — | — | — | 481 |
| PAY-UI-2FE081 | عنصر قابل للنقر | `InkWell` | — | — | onTap | 829 |
| PAY-UI-5B87DA | عنصر قابل للنقر | `InkWell` | — | — | onTap | 1118 |
| PAY-UI-92D201 | عنصر قابل للنقر | `ListTile` | `تشغيلة غير متاحة` | تشغيلة غير متاحة | — | 1202 |
| PAY-UI-C74682 | حقل إدخال | `DropdownButtonFormField` | `مصير الدفعة` | مصير الدفعة | onChanged شرطي | 500 |
| PAY-UI-247C80 | حقل إدخال | `TextField` | `السبب/المرجع *` | السبب/المرجع * | onPressed | 510 |
| PAY-UI-82987E | حقل إدخال | `TextField` | `سبب السحب *` | سبب السحب * | onPressed | 534 |
| PAY-UI-5D96B2 | حقل إدخال | `TextField` | `رقم الـ Batch *` | رقم الـ Batch * | — | 560 |
| PAY-UI-A4DCB4 | حقل إدخال | `TextField` | `الكمية *` | الكمية * | — | 562 |
| PAY-UI-AA4ECB | حقل إدخال | `TextField` | `تاريخ الصلاحية *` | تاريخ الصلاحية * | onTap | 566 |
| PAY-UI-E79844 | حقل إدخال | `TextField` | `تاريخ الإنتاج (اختياري)` | تاريخ الإنتاج (اختياري) | onTap | 581 |
| PAY-UI-B6D839 | حقل إدخال | `TextField` | `المورّد (اختياري)` | المورّد (اختياري) | onPressed | 596 |
| PAY-UI-8194AC | حقل إدخال | `TextField` | `تكلفة الوحدة` | تكلفة الوحدة | onPressed | 598 |
| PAY-UI-B640D5 | حقل إدخال | `TextField` | `الاسم التجاري *` | الاسم التجاري * | onChanged | 651 |
| PAY-UI-E1AD13 | حقل إدخال | `TextField` | `الاسم العلمي (اختياري)` | الاسم العلمي (اختياري) | onChanged | 653 |
| PAY-UI-219AA3 | حقل إدخال | `TextField` | `المادة الفعالة (للبدائل)` | المادة الفعالة (للبدائل) | — | 655 |
| PAY-UI-7680D2 | حقل إدخال | `TextField` | `التركيز (للبدائل)` | التركيز (للبدائل) | — | 657 |
| PAY-UI-37D147 | حقل إدخال | `TextField` | `الشكل الدوائي (للبدائل)` | الشكل الدوائي (للبدائل) | — | 659 |
| PAY-UI-345881 | حقل إدخال | `DropdownButtonFormField` | `تصنيف مسجل` | تصنيف مسجل | onChanged | 661 |
| PAY-UI-5BBD66 | حقل إدخال | `TextField` | `أو أضف تصنيفاً جديداً *` | أو أضف تصنيفاً جديداً * | — | 669 |
| PAY-UI-0EF40E | حقل إدخال | `TextField` | `الشركة المصنعة (اختياري)` | الشركة المصنعة (اختياري) | — | 671 |
| PAY-UI-0BCFDA | حقل إدخال | `TextField` | `الوحدة` | الوحدة | — | 673 |
| PAY-UI-8703DC | حقل إدخال | `TextField` | `الباركود (اختياري)` | الباركود (اختياري) | — | 675 |
| PAY-UI-CEE73B | حقل إدخال | `TextField` | `سعر البيع *` | سعر البيع * | — | 677 |
| PAY-UI-952E52 | حقل إدخال | `TextField` | `سعر الشراء للوحدة *` | سعر الشراء للوحدة * | — | 680 |
| PAY-UI-54D2F5 | حقل إدخال | `TextField` | `رقم التشغيلة / الدفعة *` | رقم التشغيلة / الدفعة * | — | 685 |
| PAY-UI-A9FCB3 | حقل إدخال | `TextField` | `الكمية المستلمة *` | الكمية المستلمة * | — | 687 |
| PAY-UI-3F30E0 | حقل إدخال | `TextField` | `تاريخ الإنتاج (إن كان مطبوعاً)` | تاريخ الإنتاج (إن كان مطبوعاً) | onTap | 690 |
| PAY-UI-AF3554 | حقل إدخال | `TextField` | `تاريخ الانتهاء *` | تاريخ الانتهاء * | onTap | 700 |
| PAY-UI-4D8A55 | حقل إدخال | `SwitchListTile` | `يستلزم وصفة طبية` | يستلزم وصفة طبية | onChanged | 723 |
| PAY-UI-3121EA | حقل إدخال | `TextField` | `الاسم الكامل *` | الاسم الكامل * | — | 877 |
| PAY-UI-DD5668 | حقل إدخال | `TextField` | `الهاتف` | الهاتف | onChanged | 879 |
| PAY-UI-1FF529 | حقل إدخال | `TextField` | `الحساسيات` | الحساسيات | — | 897 |
| PAY-UI-F40FCF | حقل إدخال | `TextField` | `أمراض مزمنة` | أمراض مزمنة | onPressed | 905 |
| PAY-UI-43217E | نافذة/ورقة | `showModalBottomSheet` | — | — | استدعاء | 351 |
| PAY-UI-5EB854 | نافذة/ورقة | `showModalBottomSheet` | — | — | استدعاء | 469 |
| PAY-UI-2BF78D | نافذة/ورقة | `showDialog` | `إخراج دفعة منتهية` | إخراج دفعة منتهية | استدعاء | 495 |
| PAY-UI-1AFA78 | نافذة/ورقة | `showDialog` | `سحب تشغيلة من البيع` | سحب تشغيلة من البيع | استدعاء | 529 |
| PAY-UI-156AE0 | نافذة/ورقة | `showDialog` | `رقم الـ Batch *` | رقم الـ Batch * | استدعاء | 557 |
| PAY-UI-34D081 | نافذة/ورقة | `showDatePicker` | — | — | استدعاء | 571 |
| PAY-UI-5CA362 | نافذة/ورقة | `showDatePicker` | — | — | استدعاء | 586 |
| PAY-UI-FA74A4 | نافذة/ورقة | `showDialog` | `منتج جديد ودفعة أولى` | منتج جديد ودفعة أولى | onChanged | 647 |
| PAY-UI-F117B0 | نافذة/ورقة | `showDatePicker` | — | — | استدعاء | 695 |
| PAY-UI-704B55 | نافذة/ورقة | `showDatePicker` | — | — | استدعاء | 705 |
| PAY-UI-969D2E | نافذة/ورقة | `showDialog` | `الاسم الكامل *` | الاسم الكامل * | استدعاء | 874 |
| PAY-UI-3A15E0 | نافذة/ورقة | `Get.dialog` | `تعذّر` | تعذّر | استدعاء | 1160 |
| PAY-UI-E4C238 | نافذة/ورقة | `showModalBottomSheet` | — | — | استدعاء | 1169 |

### `lib/features/pharmacy/screens/pharmacy_sale_screen.dart` — PAY-SCR-FF9517 — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-D9B149 | زر | `TextButton` | `إلغاء البيع` | — | onPressed | 206 |
| PAY-UI-903CA7 | زر | `FilledButton` | `تجاوز التحذير` | — | onPressed | 207 |
| PAY-UI-ED4F4E | زر | `FilledButton` | `تجاوز التحذير` | — | onPressed | 208 |
| PAY-UI-CDDC0F | زر | `IconButton` | `آلة حاسبة` | آلة حاسبة | onPressed | 279 |
| PAY-UI-B08E26 | زر | `IconButton` | `مسح باركود مستمر` | — | onPressed | 286 |
| PAY-UI-88599E | زر | `IconButton` | `إفراغ السلّة` | — | onPressed | 292 |
| PAY-UI-9AF720 | زر | `IconButton` | — | — | onPressed | 319 |
| PAY-UI-368AAA | زر | `IconButton` | — | — | onPressed | 336 |
| PAY-UI-D25066 | زر | `IconButton` | — | — | onChanged | 339 |
| PAY-UI-3C073C | زر | `TextButton` | `إلغاء` | إلغاء | onPressed | 459 |
| PAY-UI-802DF9 | زر | `FilledButton` | `إضافة للسلّة` | — | onPressed | 460 |
| PAY-UI-B5411B | زر | `IconButton` | — | — | onPressed | 500 |
| PAY-UI-E5F535 | زر | `FilledButton` | `تأكيد البيع` | — | onPressed شرطي | 689 |
| PAY-UI-C09F6B | زر | `FilledButton` | — | — | — | 695 |
| PAY-UI-408647 | عنصر قابل للنقر | `InkWell` | — | — | onTap شرطي | 394 |
| PAY-UI-0149E6 | حقل إدخال | `TextField` | `هاتف العميل (اختياري — لاستحضار الحساسيات)` | — | — | 325 |
| PAY-UI-2324B4 | حقل إدخال | `TextField` | `ابحث عن منتج (اسم، باركود، SKU)` | — | onChanged | 348 |
| PAY-UI-B3A058 | حقل إدخال | `TextField` | `الكمية` | — | onPressed | 450 |
| PAY-UI-CE1FB3 | حقل إدخال | `TextField` | `خصم (ر.ي)` | — | onChanged | 562 |
| PAY-UI-A2E1CB | حقل إدخال | `TextField` | `رقم الوصفة *` | — | — | 599 |
| PAY-UI-537FC7 | حقل إدخال | `TextField` | `رقم العميل *` | — | — | 655 |
| PAY-UI-5299BA | حقل إدخال | `TextField` | `اسم العميل (اختياري)` | — | — | 671 |
| PAY-UI-C2144E | نافذة/ورقة | `showDialog` | `⚠️ تحذير حساسية` | — | استدعاء | 165 |
| PAY-UI-38D266 | نافذة/ورقة | `showDialog` | — | — | استدعاء | 444 |
