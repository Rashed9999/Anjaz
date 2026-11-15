# ملحق — سجل العناصر التفاعلية: وحدة `auth`

> **مولَّد آليًّا** بتاريخ 2026-11-15 من شجرة `1a1d8c65` (مطابقة المحتوى للفرع الافتراضي `claude/project-code-review-yjagv@751ea393`). دليل **ساكن** فقط: لا يثبت تشغيلًا ولا ضغط زر ولا حالة إنتاج. المعرّفات مشتقة من تجزئة ثابتة (لا تتغير بإضافة صفوف). لا يُحذف شيء استنادًا إلى هذا الملف.

استخراج آلي بالتعابير النمطية من مصدر Dart: نوع الودجت، مفتاح/نص التسمية القريب، وترجمته من `assets/language/ar.json` إن وُجد المفتاح. **لا يثبت** الشروط الفعلية ولا رد الخادم ولا حالات التحميل؛ كل صف `INTERACTION_UNVERIFIED`. الأعمدة الناقصة عن القالب المعياري (التحقق/رد النجاح/رد الخطأ) تُملأ عند الفحص التفاعلي — انظر المهمة `PAY-T-012` في `plan.md`.


### `lib/features/auth/controllers/auth_controller.dart` — — — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-462EFB | نافذة/ورقة | `showCustomSnackBarHelper` | — | — | استدعاء | 173 |
| PAY-UI-67F550 | نافذة/ورقة | `showCustomSnackBarHelper` | — | — | استدعاء | 240 |
| PAY-UI-49C245 | نافذة/ورقة | `showCustomSnackBarHelper` | — | — | استدعاء | 245 |
| PAY-UI-FFCECD | نافذة/ورقة | `showCustomSnackBarHelper` | `your_account_remove_successfully` | تم حذف حسابك بنجاح | استدعاء | 316 |

### `lib/features/auth/controllers/create_account_controller.dart` — — — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-189BAC | نافذة/ورقة | `showCustomSnackBarHelper` | `please_give_your_phone_number` | أدخل رقم هاتفك | استدعاء | 39 |
| PAY-UI-63DFAC | نافذة/ورقة | `showCustomSnackBarHelper` | `phone_number_not_contain_characters` | رقم الهاتف لا يحتوي على أحرف | استدعاء | 42 |
| PAY-UI-F98251 | نافذة/ورقة | `showCustomSnackBarHelper` | `phone_number_not_contain_characters` | رقم الهاتف لا يحتوي على أحرف | استدعاء | 45 |
| PAY-UI-6D8BC8 | نافذة/ورقة | `showCustomSnackBarHelper` | `phone_number_not_contain_spatial_characters` | لا يجوز أن يحتوي رقم الهاتف على رموز خاصة | استدعاء | 48 |

### `lib/features/auth/screens/account_review_screen.dart` — PAY-SCR-D9D835 — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-D3FACB | زر | `ElevatedButton` | — | — | onPressed | 126 |
| PAY-UI-731DF2 | زر | `ElevatedButton` | `العودة لتسجيل الدخول` | — | — | 129 |

### `lib/features/auth/screens/activate_account_screen.dart` — PAY-SCR-19D8F4 — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-B00617 | زر | `IconButton` | — | — | onPressed | 123 |
| PAY-UI-2B4DF5 | زر | `FilledButton` | — | — | onPressed شرطي | 180 |
| PAY-UI-B3014E | حقل إدخال | `TextFormField` | `البريد الإلكتروني` | البريد الإلكتروني | — | 89 |
| PAY-UI-F2B8E2 | حقل إدخال | `TextFormField` | `رمز التفعيل (6 أرقام)` | — | — | 101 |
| PAY-UI-846CCF | حقل إدخال | `TextFormField` | `كلمة المرور (8 أحرف فأكثر)` | — | — | 117 |
| PAY-UI-BBA536 | حقل إدخال | `TextFormField` | `تأكيد كلمة المرور` | — | — | 134 |
| PAY-UI-8890C3 | حقل إدخال | `TextFormField` | `رمز PIN للعمليات (4 إلى 6 أرقام)` | — | — | 145 |
| PAY-UI-8D04C7 | حقل إدخال | `TextFormField` | `تأكيد رمز PIN` | — | — | 161 |

### `lib/features/auth/screens/amial_biometric_setup_screen.dart` — PAY-SCR-310B9E — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-0C5B6E | زر | `IconButton` | — | — | onPressed | 86 |
| PAY-UI-EFB54B | زر | `FilledButton` | `تفعيل الآن` | — | onPressed شرطي | 136 |
| PAY-UI-6F0BFD | زر | `FilledButton` | `تخطى الآن` | — | onPressed | 147 |
| PAY-UI-814F5D | زر | `TextButton` | `تخطى الآن` | — | onPressed | 155 |

### `lib/features/auth/screens/quick_registration_screen.dart` — PAY-SCR-5D935B — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-9EC15B | زر | `FilledButton` | `الانتقال إلى موثّق` | — | onPressed | 54 |
| PAY-UI-E18580 | زر | `OutlinedButton` | `لديّ رمز تفعيل` | — | onPressed | 60 |

### `lib/features/auth/screens/unified_login_screen.dart` — PAY-SCR-8B1789 — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-B64CA8 | زر | `IconButton` | — | — | onPressed | 628 |
| PAY-UI-0BE615 | زر | `TextButton` | `نسيت كلمة المرور؟` | — | onPressed | 644 |
| PAY-UI-5513A0 | زر | `TextButton` | `لديّ رمز تفعيل` | — | onPressed | 649 |
| PAY-UI-650936 | زر | `FilledButton` | — | — | onPressed | 661 |
| PAY-UI-F5B20E | زر | `FilledButton` | — | — | — | 677 |
| PAY-UI-D6337A | زر | `OutlinedButton` | — | — | onPressed | 728 |
| PAY-UI-E74022 | زر | `OutlinedButton` | — | — | — | 734 |
| PAY-UI-E61E3F | زر | `OutlinedButton` | `الدخول بالبصمة` | — | onPressed | 749 |
| PAY-UI-DEDBB6 | زر | `OutlinedButton` | — | — | — | 753 |
| PAY-UI-1FFD66 | زر | `TextButton` | `إنشاء حساب جديد` | — | onPressed | 775 |
| PAY-UI-402C57 | عنصر قابل للنقر | `InkWell` | — | — | onTap | 875 |
| PAY-UI-3CFF6B | حقل إدخال | `TextFormField` | — | — | — | 815 |

### `lib/features/auth/widgets/gender_card_widget.dart` — — — **غير موصول (UNREACHABLE)**

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-3727D8 | عنصر قابل للنقر | `InkWell` | — | — | onTap شرطي | 15 |

### `lib/features/auth/widgets/governorate_picker.dart` — — — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-94BCEC | حقل إدخال | `DropdownButtonFormField` | — | — | — | 116 |

### `lib/features/auth/widgets/pin_field_widget.dart` — — — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-8F5EB2 | حقل إدخال | `CustomTextFieldWidget` | `ex:1234` | مثال: 1234 | — | 95 |
| PAY-UI-0D4C4B | حقل إدخال | `CustomTextFieldWidget` | `ex:1234` | مثال: 1234 | — | 126 |

### `lib/features/auth/widgets/sign_up_input_widget.dart` — — — **غير موصول (UNREACHABLE)**

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-86DAC0 | حقل إدخال | `CustomTextFieldWidget` | `enter_first_name` | أدخل الاسم الأول | — | 47 |
| PAY-UI-60EB66 | حقل إدخال | `CustomTextFieldWidget` | `enter_last_name` | أدخل اسم العائلة | — | 63 |
| PAY-UI-00C71B | حقل إدخال | `CustomTextFieldWidget` | `email_address_hint` | Demo.abc@email | — | 77 |
| PAY-UI-F92448 | حقل إدخال | `CustomTextFieldWidget` | `ex:teacher` | مثال: معلّم | — | 90 |

### `lib/features/auth/widgets/signature_pad_widget.dart` — — — **غير موصول (UNREACHABLE)**

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-4A00DD | زر | `TextButton` | `مسح التوقيع` | — | onPressed | 128 |
| PAY-UI-292ABA | زر | `TextButton` | — | — | — | 132 |
