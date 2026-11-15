# ملحق — سجل العناصر التفاعلية: وحدة `forget_pin`

> **مولَّد آليًّا** بتاريخ 2026-11-15 من شجرة `1a1d8c65` (مطابقة المحتوى للفرع الافتراضي `claude/project-code-review-yjagv@751ea393`). دليل **ساكن** فقط: لا يثبت تشغيلًا ولا ضغط زر ولا حالة إنتاج. المعرّفات مشتقة من تجزئة ثابتة (لا تتغير بإضافة صفوف). لا يُحذف شيء استنادًا إلى هذا الملف.

استخراج آلي بالتعابير النمطية من مصدر Dart: نوع الودجت، مفتاح/نص التسمية القريب، وترجمته من `assets/language/ar.json` إن وُجد المفتاح. **لا يثبت** الشروط الفعلية ولا رد الخادم ولا حالات التحميل؛ كل صف `INTERACTION_UNVERIFIED`. الأعمدة الناقصة عن القالب المعياري (التحقق/رد النجاح/رد الخطأ) تُملأ عند الفحص التفاعلي — انظر المهمة `PAY-T-012` في `plan.md`.


### `lib/features/forget_pin/controllers/forget_pin_controller.dart` — — — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-E47A99 | نافذة/ورقة | `showCustomSnackBarHelper` | `please_enter_your_valid_pin` | أدخل رمزاً سرياً صحيحاً | استدعاء | 20 |
| PAY-UI-55A134 | نافذة/ورقة | `showCustomSnackBarHelper` | `pin_should_be_4_digit` | يجب أن يتكوّن الرمز السري من 4 أرقام | استدعاء | 23 |
| PAY-UI-418C33 | نافذة/ورقة | `showCustomSnackBarHelper` | `pin_not_matched` | الرمز السري غير متطابق | استدعاء | 30 |
| PAY-UI-36D871 | نافذة/ورقة | `showCustomSnackBarHelper` | `please_give_your_phone_number` | أدخل رقم هاتفك | استدعاء | 37 |
| PAY-UI-34DFE1 | نافذة/ورقة | `showCustomSnackBarHelper` | `something_error_in_your_phone_number` | خطأ ما في رقم هاتفك | استدعاء | 61 |

### `lib/features/forget_pin/screens/forget_pin_screen.dart` — PAY-SCR-C42B4E — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-9581DB | زر | `FilledButton` | — | — | onPressed شرطي | 359 |
| PAY-UI-CD9261 | زر | `OutlinedButton` | `email_otp_support_code` | لدي رمز أرسله فريق الدعم | onPressed شرطي | 370 |
| PAY-UI-831E9E | زر | `FilledButton` | `email_otp_verify` | تحقق | onPressed شرطي | 407 |
| PAY-UI-412DBC | زر | `TextButton` | `email_otp_resend` | إعادة إرسال الرمز | onPressed شرطي | 414 |
| PAY-UI-644180 | زر | `TextButton` | `email_otp_change_recovery` | تغيير البريد أو نوع الاستعادة | onPressed شرطي | 422 |
| PAY-UI-0C7416 | زر | `IconButton` | — | — | onPressed | 455 |
| PAY-UI-0F522F | زر | `FilledButton` | `email_otp_set_pin` | تعيين رمز PIN | onPressed شرطي | 474 |
| PAY-UI-1B32B4 | زر | `TextButton` | `email_otp_change_recovery` | تغيير البريد أو نوع الاستعادة | onPressed شرطي | 480 |
| PAY-UI-9971B6 | زر | `FilledButton` | `email_otp_back_login` | العودة لتسجيل الدخول | onPressed | 509 |
| PAY-UI-548C71 | حقل إدخال | `TextField` | `email_otp_account_email` | البريد الإلكتروني المسجل في الحساب | — | 345 |
| PAY-UI-F3FD55 | حقل إدخال | `TextField` | `email_otp_code` | رمز التحقق | — | 394 |
| PAY-UI-290BBE | حقل إدخال | `TextField` | `email_otp_new_pin` | رمز PIN الجديد | — | 446 |
| PAY-UI-C0F9D5 | حقل إدخال | `TextField` | `email_otp_confirm_pin` | تأكيد رمز PIN | — | 462 |

### `lib/features/forget_pin/widgets/pin_field_widget.dart` — — — **غير موصول (UNREACHABLE)**

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-DF2994 | حقل إدخال | `CustomPasswordFieldWidget` | `pin` | الرمز السري | — | 55 |
| PAY-UI-2D3BB9 | حقل إدخال | `CustomPasswordFieldWidget` | `Confirm_Password` | تأكيد الرمز السري | — | 67 |
