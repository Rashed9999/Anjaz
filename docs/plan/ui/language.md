# ملحق — سجل العناصر التفاعلية: وحدة `language`

> **مولَّد آليًّا** بتاريخ 2026-11-15 من شجرة `1a1d8c65` (مطابقة المحتوى للفرع الافتراضي `claude/project-code-review-yjagv@751ea393`). دليل **ساكن** فقط: لا يثبت تشغيلًا ولا ضغط زر ولا حالة إنتاج. المعرّفات مشتقة من تجزئة ثابتة (لا تتغير بإضافة صفوف). لا يُحذف شيء استنادًا إلى هذا الملف.

استخراج آلي بالتعابير النمطية من مصدر Dart: نوع الودجت، مفتاح/نص التسمية القريب، وترجمته من `assets/language/ar.json` إن وُجد المفتاح. **لا يثبت** الشروط الفعلية ولا رد الخادم ولا حالات التحميل؛ كل صف `INTERACTION_UNVERIFIED`. الأعمدة الناقصة عن القالب المعياري (التحقق/رد النجاح/رد الخطأ) تُملأ عند الفحص التفاعلي — انظر المهمة `PAY-T-012` في `plan.md`.


### `lib/features/language/screens/change_language_screen.dart` — PAY-SCR-2EE312 — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-BEB83F | زر | `CustomSmallButtonWidget` | — | — | onTap | 110 |
| PAY-UI-3E43AE | نافذة/ورقة | `showCustomSnackBarHelper` | `select_a_language` | اختر اللغة | استدعاء | 131 |

### `lib/features/language/widgets/amial_language_switch.dart` — — — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-A2FA47 | عنصر قابل للنقر | `ListTile` | — | — | onTap | 53 |
| PAY-UI-E2D107 | عنصر قابل للنقر | `InkWell` | — | — | onTap | 132 |
| PAY-UI-15AE23 | نافذة/ورقة | `showModalBottomSheet` | — | — | استدعاء | 22 |

### `lib/features/language/widgets/language_widget.dart` — — — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-B9506D | عنصر قابل للنقر | `CustomInkWellWidget` | — | — | onTap | 25 |
