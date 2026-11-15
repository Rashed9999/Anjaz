# ملحق — سجل العناصر التفاعلية: وحدة `camera_verification`

> **مولَّد آليًّا** بتاريخ 2026-11-15 من شجرة `1a1d8c65` (مطابقة المحتوى للفرع الافتراضي `claude/project-code-review-yjagv@751ea393`). دليل **ساكن** فقط: لا يثبت تشغيلًا ولا ضغط زر ولا حالة إنتاج. المعرّفات مشتقة من تجزئة ثابتة (لا تتغير بإضافة صفوف). لا يُحذف شيء استنادًا إلى هذا الملف.

استخراج آلي بالتعابير النمطية من مصدر Dart: نوع الودجت، مفتاح/نص التسمية القريب، وترجمته من `assets/language/ar.json` إن وُجد المفتاح. **لا يثبت** الشروط الفعلية ولا رد الخادم ولا حالات التحميل؛ كل صف `INTERACTION_UNVERIFIED`. الأعمدة الناقصة عن القالب المعياري (التحقق/رد النجاح/رد الخطأ) تُملأ عند الفحص التفاعلي — انظر المهمة `PAY-T-012` في `plan.md`.


### `lib/features/camera_verification/controllers/camera_screen_controller.dart` — — — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-A07720 | زر | `TextButton` | — | — | onPressed | 271 |
| PAY-UI-4916B3 | زر | `TextButton` | — | — | onPressed | 297 |

### `lib/features/camera_verification/controllers/qr_code_scanner_controller.dart` — — — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-60E8F7 | عنصر قابل للنقر | `ListTile` | `send_money` | إرسال أموال | onTap | 171 |
| PAY-UI-76F8CC | عنصر قابل للنقر | `ListTile` | `request_money` | طلب أموال | onTap | 183 |
| PAY-UI-A260F4 | نافذة/ورقة | `showCustomSnackBarHelper` | — | — | استدعاء | 76 |
| PAY-UI-A1F794 | نافذة/ورقة | `showCustomSnackBarHelper` | `receiver_must_be_an_agent` | يجب أن يكون المستلم وكيلاً! | استدعاء | 132 |
| PAY-UI-F0E9B6 | نافذة/ورقة | `showCustomSnackBarHelper` | `receiver_must_be_a_customer` | يجب أن يكون المستلم عميلاً! | استدعاء | 135 |

### `lib/features/camera_verification/screens/camera_screen.dart` — PAY-SCR-B5B234 — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-924A41 | زر | `TextButton` | `skip` | تخطٍّ | onPressed | 68 |
