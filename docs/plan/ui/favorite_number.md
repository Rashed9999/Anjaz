# ملحق — سجل العناصر التفاعلية: وحدة `favorite_number`

> **مولَّد آليًّا** بتاريخ 2026-11-15 من شجرة `1a1d8c65` (مطابقة المحتوى للفرع الافتراضي `claude/project-code-review-yjagv@751ea393`). دليل **ساكن** فقط: لا يثبت تشغيلًا ولا ضغط زر ولا حالة إنتاج. المعرّفات مشتقة من تجزئة ثابتة (لا تتغير بإضافة صفوف). لا يُحذف شيء استنادًا إلى هذا الملف.

استخراج آلي بالتعابير النمطية من مصدر Dart: نوع الودجت، مفتاح/نص التسمية القريب، وترجمته من `assets/language/ar.json` إن وُجد المفتاح. **لا يثبت** الشروط الفعلية ولا رد الخادم ولا حالات التحميل؛ كل صف `INTERACTION_UNVERIFIED`. الأعمدة الناقصة عن القالب المعياري (التحقق/رد النجاح/رد الخطأ) تُملأ عند الفحص التفاعلي — انظر المهمة `PAY-T-012` في `plan.md`.


### `lib/features/favorite_number/screens/add_fav_number_screen.dart` — PAY-SCR-AD96C4 — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-252F87 | زر | `CustomButtonWidget` | — | — | onTap | 170 |
| PAY-UI-F75D81 | عنصر قابل للنقر | `InkWell` | — | — | onTap | 134 |
| PAY-UI-5B105D | حقل إدخال | `CustomTextFieldWidget` | `type_name` | اكتب الاسم | — | 102 |
| PAY-UI-11A578 | حقل إدخال | `CustomTextFieldWidget` | `type_number` | اكتب الرقم | — | 125 |
| PAY-UI-747F23 | نافذة/ورقة | `showCustomSnackBarHelper` | `you_need_to_allow_to_get_contact_list` | تحتاج إلى السماح بالحصول على قائمة جهات الاتصال | استدعاء | 148 |
| PAY-UI-C563E0 | نافذة/ورقة | `showCustomSnackBarHelper` | `favorite_number_updated_successfully` | تم تحديث الرقم المفضل بنجاح | استدعاء | 222 |
| PAY-UI-B977A1 | نافذة/ورقة | `showCustomSnackBarHelper` | `you_can_not_add_your_own_number` | لا يمكنك إضافة رقمك الخاص | استدعاء | 230 |
| PAY-UI-6FD812 | نافذة/ورقة | `showCustomSnackBarHelper` | `favorite_number_added_successfully` | تم إضافة الرقم المفضل بنجاح | استدعاء | 246 |

### `lib/features/favorite_number/screens/amial_favorites_screen.dart` — PAY-SCR-11380F — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-F1BF34 | زر | `IconButton` | `إزالة من المفضّلة` | — | onPressed | 126 |
| PAY-UI-DF2A33 | عنصر قابل للنقر | `ListTile` | — | — | — | 114 |

### `lib/features/favorite_number/screens/favorite_number_screen.dart` — PAY-SCR-5E8C6B — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-11B7BC | عنصر قابل للنقر | `InkWell` | — | — | onTap | 110 |
| PAY-UI-C40DED | نافذة/ورقة | `showDialog` | — | — | استدعاء | 115 |

### `lib/features/favorite_number/screens/search_contact_screen.dart` — PAY-SCR-595BFD — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-D2D33A | عنصر قابل للنقر | `InkWell` | — | — | onTap | 56 |
| PAY-UI-A1D831 | حقل إدخال | `CustomTextFieldWidget` | `search_name_or_number` | ابحث بالاسم أو الرقم | onChanged | 45 |
| PAY-UI-EA4C7C | نافذة/ورقة | `showCustomSnackBarHelper` | `this_contact_is_already_favorite` | هذه الجهة ضمن المفضّلة بالفعل | استدعاء | 77 |

### `lib/features/favorite_number/widgets/amial_favorite_star.dart` — — — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-6EDAEE | زر | `IconButton` | — | — | — | 42 |

### `lib/features/favorite_number/widgets/fav_num_delete_bottom_sheet_widget.dart` — — — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-2E0171 | زر | `CustomButtonWidget` | `no` | لا | onTap | 56 |
| PAY-UI-50F56B | زر | `CustomButtonWidget` | `favorite_number_deleted_successfully` | تم حذف الرقم المفضل بنجاح | onTap | 70 |
| PAY-UI-4E58A9 | عنصر قابل للنقر | `InkWell` | — | — | onTap | 21 |
| PAY-UI-4CB954 | نافذة/ورقة | `showCustomSnackBarHelper` | `favorite_number_deleted_successfully` | تم حذف الرقم المفضل بنجاح | استدعاء | 76 |

### `lib/features/favorite_number/widgets/fav_num_limit_reached_dialog_widget.dart` — — — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-AD94A8 | زر | `CustomButtonWidget` | `okay` | حسناً | onTap | 67 |
| PAY-UI-7ADB4F | عنصر قابل للنقر | `InkWell` | — | — | onTap | 27 |

### `lib/features/favorite_number/widgets/fav_number_list_item_widget.dart` — — — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-B8E8CC | عنصر قابل للنقر | `InkWell` | — | — | onTap شرطي | 55 |
| PAY-UI-A91AB6 | عنصر قابل للنقر | `InkWell` | — | — | onTap | 116 |

### `lib/features/favorite_number/widgets/fav_type_item_widget.dart` — — — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-7E72E3 | عنصر قابل للنقر | `InkWell` | — | — | onTap | 24 |
