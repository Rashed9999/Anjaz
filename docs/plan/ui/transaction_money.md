# ملحق — سجل العناصر التفاعلية: وحدة `transaction_money`

> **مولَّد آليًّا** بتاريخ 2026-11-15 من شجرة `1a1d8c65` (مطابقة المحتوى للفرع الافتراضي `claude/project-code-review-yjagv@751ea393`). دليل **ساكن** فقط: لا يثبت تشغيلًا ولا ضغط زر ولا حالة إنتاج. المعرّفات مشتقة من تجزئة ثابتة (لا تتغير بإضافة صفوف). لا يُحذف شيء استنادًا إلى هذا الملف.

استخراج آلي بالتعابير النمطية من مصدر Dart: نوع الودجت، مفتاح/نص التسمية القريب، وترجمته من `assets/language/ar.json` إن وُجد المفتاح. **لا يثبت** الشروط الفعلية ولا رد الخادم ولا حالات التحميل؛ كل صف `INTERACTION_UNVERIFIED`. الأعمدة الناقصة عن القالب المعياري (التحقق/رد النجاح/رد الخطأ) تُملأ عند الفحص التفاعلي — انظر المهمة `PAY-T-012` في `plan.md`.


### `lib/features/transaction_money/controllers/contact_controller.dart` — — — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-62CB71 | نافذة/ورقة | `Get.dialog` | `use_the_information_you` | استخدام المعلومات التي تقدمها بما في ذلك قائمة جهات الاتصال الخاصة بجهاز المستخدم لأغراض مختلفة. لا يتم تخزين قائمة جهات الاتصال في قاعدة البيانات وتستخدم فقط لغرض تنفيذ أي تعليمات يقدمها المستخدم. | استدعاء | 78 |
| PAY-UI-BE704D | نافذة/ورقة | `showCustomSnackBarHelper` | `Please_enter_a_different_customer_number` | يرجى إدخال رقم عميل مختلف لإجراء المعاملة بخلاف الرقم الخاص بك | استدعاء | 334 |

### `lib/features/transaction_money/controllers/transaction_controller.dart` — — — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-F9A1FC | نافذة/ورقة | `showCustomSnackBarHelper` | `request_send_successful` | تم إرسال الطلب بنجاح! | استدعاء | 254 |
| PAY-UI-348A70 | نافذة/ورقة | `showCustomSnackBarHelper` | — | — | استدعاء | 257 |

### `lib/features/transaction_money/screens/amial_send_money_screen.dart` — PAY-SCR-B905B7 — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-C7A1E5 | زر | `FilledButton` | `تأكيد التحويل` | — | onPressed | 284 |
| PAY-UI-8398FF | زر | `FilledButton` | `تأكيد التحويل` | — | onPressed | 286 |
| PAY-UI-81343A | زر | `TextButton` | `إلغاء` | إلغاء | onPressed | 294 |
| PAY-UI-0F9501 | زر | `ElevatedButton` | — | — | onPressed شرطي | 540 |
| PAY-UI-5D9D6C | زر | `ElevatedButton` | — | — | — | 551 |
| PAY-UI-2826D8 | عنصر قابل للنقر | `InkWell` | — | — | onTap | 430 |
| PAY-UI-B91F5E | حقل إدخال | `TextField` | — | — | — | 493 |
| PAY-UI-F86A10 | نافذة/ورقة | `showCustomSnackBarHelper` | — | — | استدعاء | 91 |
| PAY-UI-5EC5D7 | نافذة/ورقة | `showCustomSnackBarHelper` | — | — | استدعاء | 96 |
| PAY-UI-663CBC | نافذة/ورقة | `showCustomSnackBarHelper` | — | — | استدعاء | 125 |
| PAY-UI-F70F0E | نافذة/ورقة | `showCustomSnackBarHelper` | — | — | استدعاء | 206 |
| PAY-UI-066342 | نافذة/ورقة | `showCustomSnackBarHelper` | — | — | استدعاء | 210 |
| PAY-UI-F9144D | نافذة/ورقة | `showModalBottomSheet` | — | — | استدعاء | 220 |

### `lib/features/transaction_money/screens/amial_transfer_holding_screen.dart` — PAY-SCR-5FDFFD — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-BA0F74 | زر | `FilledButton` | `لا، أكمل التحويل` | — | onPressed | 110 |
| PAY-UI-0C47A9 | زر | `FilledButton` | `لا، أكمل التحويل` | — | onPressed | 112 |
| PAY-UI-5C3ACF | زر | `OutlinedButton` | `نعم، تراجع واسترد المبلغ` | — | onPressed | 118 |
| PAY-UI-F8A070 | زر | `OutlinedButton` | `نعم، تراجع واسترد المبلغ` | — | — | 120 |
| PAY-UI-3A613A | زر | `OutlinedButton` | — | — | onPressed شرطي | 276 |
| PAY-UI-82FD3E | زر | `OutlinedButton` | — | — | onPressed | 285 |
| PAY-UI-C16B1F | زر | `FilledButton` | `مشاركة الإيصال` | — | onPressed | 292 |
| PAY-UI-DBEE1C | زر | `FilledButton` | — | — | onPressed | 296 |
| PAY-UI-899104 | زر | `TextButton` | `العودة للرئيسية` | — | onPressed | 302 |
| PAY-UI-F70B29 | زر | `TextButton` | — | — | — | 306 |
| PAY-UI-D7C36B | نافذة/ورقة | `showModalBottomSheet` | — | — | استدعاء | 90 |

### `lib/features/transaction_money/screens/transaction_balance_input_screen.dart` — PAY-SCR-A0D947 — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-BB3E23 | زر | `FloatingActionButton` | `please_input_amount` | أدخل المبلغ | onPressed | 297 |
| PAY-UI-C96B94 | عنصر قابل للنقر | `GestureDetector` | — | — | onTap | 88 |
| PAY-UI-2536DD | حقل إدخال | `DropdownButton` | `please_select_a_method` | اختر طريقة | — | 126 |
| PAY-UI-9C7890 | نافذة/ورقة | `showCustomSnackBarHelper` | `please_input_amount` | أدخل المبلغ | استدعاء | 302 |
| PAY-UI-435010 | نافذة/ورقة | `showCustomSnackBarHelper` | `transaction_amount_must_be` | يجب أن يكون مبلغ المعاملة أكبر من الصفر | استدعاء | 306 |
| PAY-UI-AFDFC5 | نافذة/ورقة | `showCustomSnackBarHelper` | `insufficient_balance` | الرصيد غير كافٍ | استدعاء | 333 |
| PAY-UI-1BF7EF | نافذة/ورقة | `showCustomSnackBarHelper` | — | — | استدعاء | 457 |

### `lib/features/transaction_money/screens/transaction_confirmation_screen.dart` — PAY-SCR-D63C43 — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-347D48 | عنصر قابل للنقر | `GestureDetector` | `please_input_4_digit_pin` | أدخل رمزاً سرياً من 4 أرقام | onTap | 174 |
| PAY-UI-9C2270 | حقل إدخال | `PinCodeTextField` | — | — | onChanged | 150 |
| PAY-UI-663D03 | نافذة/ورقة | `showCustomSnackBarHelper` | `please_input_4_digit_pin` | أدخل رمزاً سرياً من 4 أرقام | استدعاء | 178 |
| PAY-UI-CBAADF | نافذة/ورقة | `showModalBottomSheet` | — | — | استدعاء | 201 |
| PAY-UI-674CAA | نافذة/ورقة | `showModalBottomSheet` | — | — | استدعاء | 235 |

### `lib/features/transaction_money/screens/transaction_money_screen.dart` — PAY-SCR-EEFF7E — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-4282C8 | زر | `CustomButtonWidget` | `next` | التالي | onTap شرطي | 313 |
| PAY-UI-DEA194 | عنصر قابل للنقر | `InkWell` | — | — | onTap | 102 |
| PAY-UI-670BDB | عنصر قابل للنقر | `InkWell` | — | — | onTap | 123 |
| PAY-UI-891C2D | عنصر قابل للنقر | `CustomInkWellWidget` | — | — | onTap | 171 |
| PAY-UI-2970A7 | عنصر قابل للنقر | `CustomInkWellWidget` | — | — | onTap | 216 |
| PAY-UI-BC2B1E | عنصر قابل للنقر | `CustomInkWellWidget` | — | — | onTap | 256 |
| PAY-UI-61328F | حقل إدخال | `CustomTextFieldWidget` | `type_number` | اكتب الرقم | onChanged | 141 |
| PAY-UI-33565E | نافذة/ورقة | `showCustomSnackBarHelper` | `input_field_is_empty` | حقل الإدخال فارغ | استدعاء | 320 |
| PAY-UI-5BB79B | نافذة/ورقة | `showCustomSnackBarHelper` | — | — | استدعاء | 349 |
| PAY-UI-11054C | نافذة/ورقة | `showCustomSnackBarHelper` | — | — | استدعاء | 379 |

### `lib/features/transaction_money/widgets/bottom_sheet_with_slider.dart` — — — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-7D8B4A | عنصر قابل للنقر | `GestureDetector` | — | — | onTap | 97 |
| PAY-UI-AA53BF | عنصر قابل للنقر | `CustomInkWellWidget` | — | — | onTap | 296 |
| PAY-UI-2D1F17 | عنصر قابل للنقر | `CustomInkWellWidget` | — | — | onTap | 322 |

### `lib/features/transaction_money/widgets/contact_list_widget.dart` — — — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-ACBF37 | زر | `TextButton` | `you_have_to_grand_permission_to_see_contact` | يجب منح إذن الوصول إلى جهات الاتصال | onPressed | 51 |
| PAY-UI-4F63D6 | عنصر قابل للنقر | `InkWell` | `please_allow_permission` | يرجى منح الإذن | onTap | 28 |
| PAY-UI-A73066 | عنصر قابل للنقر | `CustomInkWellWidget` | — | — | onTap | 107 |
| PAY-UI-1B5B6B | عنصر قابل للنقر | `ListTile` | — | — | — | 140 |

### `lib/features/transaction_money/widgets/field_item_widget.dart` — — — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-D9D6F8 | حقل إدخال | `CustomTextFieldWidget` | — | — | — | 41 |

### `lib/features/transaction_money/widgets/input_box_widget.dart` — — — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-5C356D | حقل إدخال | `TextField` | — | — | — | 42 |

### `lib/features/transaction_money/widgets/input_field_shimmer_widget.dart` — — — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-E3C10C | حقل إدخال | `TextField` | — | — | — | 22 |

### `lib/features/transaction_money/widgets/next_button_widget.dart` — — — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-52CF8F | عنصر قابل للنقر | `CustomInkWellWidget` | — | — | onTap | 13 |

### `lib/features/transaction_money/widgets/preview_amount_widget.dart` — — — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-620D77 | عنصر قابل للنقر | `InkWell` | — | — | onTap شرطي | 33 |

### `lib/features/transaction_money/widgets/purpose_widget.dart` — — — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-3EA421 | عنصر قابل للنقر | `CustomInkWellWidget` | — | — | onTap شرطي | 87 |

### `lib/features/transaction_money/widgets/scan_button_widget.dart` — — — **غير موصول (UNREACHABLE)**

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-F71A48 | عنصر قابل للنقر | `CustomInkWellWidget` | — | — | onTap | 21 |
