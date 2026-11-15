# ملحق — سجل العناصر التفاعلية: وحدة `setting`

> **مولَّد آليًّا** بتاريخ 2026-11-15 من شجرة `1a1d8c65` (مطابقة المحتوى للفرع الافتراضي `claude/project-code-review-yjagv@751ea393`). دليل **ساكن** فقط: لا يثبت تشغيلًا ولا ضغط زر ولا حالة إنتاج. المعرّفات مشتقة من تجزئة ثابتة (لا تتغير بإضافة صفوف). لا يُحذف شيء استنادًا إلى هذا الملف.

استخراج آلي بالتعابير النمطية من مصدر Dart: نوع الودجت، مفتاح/نص التسمية القريب، وترجمته من `assets/language/ar.json` إن وُجد المفتاح. **لا يثبت** الشروط الفعلية ولا رد الخادم ولا حالات التحميل؛ كل صف `INTERACTION_UNVERIFIED`. الأعمدة الناقصة عن القالب المعياري (التحقق/رد النجاح/رد الخطأ) تُملأ عند الفحص التفاعلي — انظر المهمة `PAY-T-012` في `plan.md`.


### `lib/features/setting/controllers/edit_profile_controller.dart` — — — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-50A9D6 | نافذة/ورقة | `showCustomSnackBarHelper` | `email_otp_connection_failed` | تعذر الاتصال بالخادم | استدعاء | 65 |
| PAY-UI-9D10FF | نافذة/ورقة | `showCustomSnackBarHelper` | `email_otp_connection_failed` | تعذر الاتصال بالخادم | استدعاء | 71 |

### `lib/features/setting/controllers/profile_screen_controller.dart` — — — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-B1E69C | نافذة/ورقة | `showCustomSnackBarHelper` | `please_input_4_digit_pin` | أدخل رمزاً سرياً من 4 أرقام | استدعاء | 76 |
| PAY-UI-8B046A | نافذة/ورقة | `showCustomSnackBarHelper` | `pin_not_matched` | الرمز السري غير متطابق | استدعاء | 78 |
| PAY-UI-ECB3BC | نافذة/ورقة | `showCustomSnackBarHelper` | `please_select_a_new_pin` | اختر رمزاً سرياً جديداً | استدعاء | 80 |
| PAY-UI-521B7C | نافذة/ورقة | `showCustomSnackBarHelper` | — | — | استدعاء | 102 |
| PAY-UI-23A9D8 | نافذة/ورقة | `showCustomSnackBarHelper` | — | — | استدعاء | 140 |

### `lib/features/setting/screens/account_security_screen.dart` — PAY-SCR-28BC89 — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-E0AA9B | زر | `TextButton` | `إعادة` | — | onPressed | 198 |
| PAY-UI-3B919A | زر | `FilledButton` | — | — | onPressed شرطي | 324 |
| PAY-UI-57F6C7 | عنصر قابل للنقر | `ListTile` | `إعادة` | — | onPressed | 195 |
| PAY-UI-173836 | حقل إدخال | `TextField` | — | — | — | 303 |

### `lib/features/setting/screens/edit_profile_screen.dart` — PAY-SCR-9FA51E — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-C0221A | زر | `OutlinedButton` | `email_identity_add` | إضافة البريد وتوثيقه | onPressed شرطي | 146 |
| PAY-UI-DF5BAA | زر | `FilledButton` | `email_profile_saving` | جارٍ الحفظ… | onPressed شرطي | 174 |
| PAY-UI-A3550C | زر | `FilledButton` | — | — | — | 180 |
| PAY-UI-1DF524 | عنصر قابل للنقر | `InkWell` | — | — | onTap | 121 |
| PAY-UI-A3A7FF | عنصر قابل للنقر | `InkWell` | — | — | onTap | 211 |
| PAY-UI-38072D | حقل إدخال | `TextFormField` | `email_profile_required` | مطلوب | — | 190 |
| PAY-UI-502185 | حقل إدخال | `TextFormField` | — | — | — | 198 |
| PAY-UI-B103BC | نافذة/ورقة | `showDialog` | — | — | استدعاء | 73 |

### `lib/features/setting/screens/profile_screen.dart` — PAY-SCR-A04530 — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-B52945 | عنصر قابل للنقر | `CustomInkWellWidget` | `edit_profile` | تعديل الملف الشخصي | onTap | 142 |
| PAY-UI-2AECDC | عنصر قابل للنقر | `ListTile` | — | — | — | 147 |
| PAY-UI-C51A21 | عنصر قابل للنقر | `ListTile` | — | — | — | 166 |
| PAY-UI-9268D2 | عنصر قابل للنقر | `CustomInkWellWidget` | `withdraw_history` | سجلّ السحوبات | onTap | 194 |
| PAY-UI-BC0D09 | عنصر قابل للنقر | `CustomInkWellWidget` | `المفضّلة` | — | onTap | 212 |
| PAY-UI-40E6CE | عنصر قابل للنقر | `CustomInkWellWidget` | `requests` | الطلبات | onTap | 218 |
| PAY-UI-DCB549 | عنصر قابل للنقر | `CustomInkWellWidget` | `send_requests` | الطلبات المُرسَلة | onTap | 224 |
| PAY-UI-74B733 | عنصر قابل للنقر | `CustomInkWellWidget` | `transaction_limit` | حدود المعاملات | onTap | 229 |
| PAY-UI-93BA0B | عنصر قابل للنقر | `CustomInkWellWidget` | `أمان الحساب — كلمة المرور والرمز` | — | onTap | 242 |
| PAY-UI-BDEDD3 | عنصر قابل للنقر | `CustomInkWellWidget` | `change_language` | تغيير اللغة | onTap | 248 |
| PAY-UI-AC0355 | عنصر قابل للنقر | `CustomInkWellWidget` | `24_support` | دعم 24/7 | onTap | 329 |
| PAY-UI-BB5D00 | عنصر قابل للنقر | `CustomInkWellWidget` | `faq` | الأسئلة الشائعة | onTap | 334 |
| PAY-UI-0B21D1 | عنصر قابل للنقر | `CustomInkWellWidget` | `about_us` | معلومات عنا | onTap | 344 |
| PAY-UI-380518 | عنصر قابل للنقر | `CustomInkWellWidget` | `terms` | شروط الاستخدام | onTap | 349 |
| PAY-UI-E3AF51 | عنصر قابل للنقر | `CustomInkWellWidget` | `privacy_policy` | سياسة الخصوصية | onTap | 357 |
| PAY-UI-284F15 | عنصر قابل للنقر | `CustomInkWellWidget` | `logout` | تسجيل الخروج | onTap | 369 |
| PAY-UI-A4653A | عنصر قابل للنقر | `CustomInkWellWidget` | `delete_account` | حذف الحساب | onTap | 373 |

### `lib/features/setting/screens/public_legal_document_screen.dart` — PAY-SCR-46E717 — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-7AAA15 | زر | `OutlinedButton` | `إعادة المحاولة` | — | onPressed | 71 |

### `lib/features/setting/screens/qr_code_download_or_share_screen.dart` — PAY-SCR-6B1742 — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-DD312B | زر | `FilledButton` | — | — | onPressed شرطي | 223 |
| PAY-UI-B7E99E | زر | `FilledButton` | — | — | — | 233 |
| PAY-UI-1185E3 | زر | `OutlinedButton` | `مشاركة` | — | onPressed شرطي | 243 |
| PAY-UI-8671C4 | زر | `OutlinedButton` | — | — | — | 247 |

### `lib/features/setting/screens/support_screen.dart` — PAY-SCR-52225C — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-8789B6 | زر | `TextButton` | `إعادة المحاولة` | — | onPressed | 164 |
| PAY-UI-40146D | عنصر قابل للنقر | `InkWell` | — | — | onTap | 198 |

### `lib/features/setting/screens/transaction_limit_screen.dart` — PAY-SCR-AA933E — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-EA723E | تبويب | `TabBar` | — | — | — | 55 |

### `lib/features/setting/widgets/change_pin_screen.dart` — PAY-SCR-64EA46 — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-7AA51C | زر | `IconButton` | — | — | onPressed شرطي | 149 |

### `lib/features/setting/widgets/confirm_pin_bottom_sheet.dart` — — — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-AF4B18 | عنصر قابل للنقر | `InkWell` | — | — | onTap | 77 |
| PAY-UI-AFFCBA | حقل إدخال | `PinCodeTextField` | — | — | onChanged | 50 |
| PAY-UI-3A5B54 | نافذة/ورقة | `showCustomSnackBarHelper` | `please_input_4_digit_pin` | أدخل رمزاً سرياً من 4 أرقام | استدعاء | 89 |

### `lib/features/setting/widgets/custom_fag_expansion_tile.dart` — — — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-2B829B | عنصر قابل للنقر | `ListTile` | — | — | onTap | 152 |

### `lib/features/setting/widgets/email_identity_dialog.dart` — — — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-509A58 | زر | `TextButton` | `email_otp_resend` | إعادة إرسال الرمز | onPressed شرطي | 150 |
| PAY-UI-D73875 | زر | `TextButton` | `email_otp_cancel` | إلغاء | onPressed شرطي | 160 |
| PAY-UI-2AF0E0 | زر | `FilledButton` | `email_otp_send` | إرسال رمز التحقق | onPressed | 161 |
| PAY-UI-B1A45B | حقل إدخال | `TextFormField` | `email_identity_address` | البريد الإلكتروني | — | 122 |
| PAY-UI-F1C194 | حقل إدخال | `TextFormField` | `email_identity_password` | كلمة المرور الحالية | — | 132 |
| PAY-UI-58FBFC | حقل إدخال | `TextFormField` | `email_otp_code` | رمز التحقق | — | 141 |

### `lib/features/setting/widgets/faq_screen.dart` — PAY-SCR-F7B999 — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-4AE109 | زر | `OutlinedButton` | `إعادة المحاولة` | — | onPressed | 114 |
| PAY-UI-E85CDB | عنصر قابل للنقر | `GestureDetector` | — | — | onTap | 70 |

### `lib/features/setting/widgets/profile_qr_code_bottomsheet_widget.dart` — — — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-884B6F | زر | `CustomSmallButtonWidget` | `download` | تنزيل | onTap | 201 |
| PAY-UI-92C724 | زر | `CustomSmallButtonWidget` | `share_QR_code` | مشاركة رمز QR | onTap | 223 |
| PAY-UI-B257E7 | زر | `TextButton` | `إعادة المحاولة` | — | onPressed | 351 |

### `lib/features/setting/widgets/profile_shimmer_widget.dart` — — — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-042532 | عنصر قابل للنقر | `ListTile` | — | — | — | 19 |

### `lib/features/setting/widgets/status_menu.dart` — — — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-C3064B | عنصر قابل للنقر | `CustomInkWellWidget` | `4digit_pin` | رمز سري من 4 أرقام | onTap | 26 |

### `lib/features/setting/widgets/usage_limits_bar.dart` — — — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-67E976 | زر | `TextButton` | `ارفع حدودك بتوثيق أعلى` | — | onPressed | 110 |

### `lib/features/setting/widgets/user_info_widget.dart` — — — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-5D7AC4 | عنصر قابل للنقر | `InkWell` | — | — | onTap | 98 |
| PAY-UI-B56309 | نافذة/ورقة | `showModalBottomSheet` | — | — | استدعاء | 99 |
