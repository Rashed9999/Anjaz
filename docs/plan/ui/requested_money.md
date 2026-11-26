# ملحق — سجل العناصر التفاعلية: وحدة `requested_money`

> **مولَّد آليًّا** بتاريخ 2026-11-15 من شجرة `1a1d8c65` (مطابقة المحتوى للفرع الافتراضي `claude/project-code-review-yjagv@751ea393`). دليل **ساكن** فقط: لا يثبت تشغيلًا ولا ضغط زر ولا حالة إنتاج. المعرّفات مشتقة من تجزئة ثابتة (لا تتغير بإضافة صفوف). لا يُحذف شيء استنادًا إلى هذا الملف.

استخراج آلي بالتعابير النمطية من مصدر Dart: نوع الودجت، مفتاح/نص التسمية القريب، وترجمته من `assets/language/ar.json` إن وُجد المفتاح. **لا يثبت** الشروط الفعلية ولا رد الخادم ولا حالات التحميل؛ كل صف `INTERACTION_UNVERIFIED`. الأعمدة الناقصة عن القالب المعياري (التحقق/رد النجاح/رد الخطأ) تُملأ عند الفحص التفاعلي — انظر المهمة `PAY-T-012` في `plan.md`.


### `lib/features/requested_money/controllers/requested_money_controller.dart` — — — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-86E611 | نافذة/ورقة | `showCustomSnackBarHelper` | `request_denied_successfully` | تم رفض الطلب بنجاح | استدعاء | 186 |

### `lib/features/requested_money/screens/incoming_requests_screen.dart` — PAY-SCR-7802F5 — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-9B2D5D | زر | `FilledButton` | `إعادة المحاولة` | — | onPressed | 60 |
| PAY-UI-F04838 | زر | `OutlinedButton` | `رفض` | — | onPressed | 169 |
| PAY-UI-11C55B | زر | `OutlinedButton` | — | — | onPressed | 175 |
| PAY-UI-0BFB95 | زر | `FilledButton` | — | — | onPressed | 184 |
| PAY-UI-40ED23 | زر | `FilledButton` | — | — | — | 199 |
| PAY-UI-B70B89 | زر | `IconButton` | `إغلاق` | إغلاق | onPressed | 232 |
| PAY-UI-A53312 | زر | `FilledButton` | `متابعة إلى رمز الحماية` | — | onPressed | 283 |
| PAY-UI-24096A | زر | `FilledButton` | `أدخل رمز الحماية لإتمام الدفع` | — | — | 287 |
| PAY-UI-DA4A0A | زر | `IconButton` | `إغلاق` | إغلاق | onPressed | 335 |
| PAY-UI-E03D61 | زر | `FilledButton` | `تأكيد الرفض` | — | onPressed | 355 |
| PAY-UI-429C48 | زر | `FilledButton` | — | — | — | 359 |
| PAY-UI-9716F8 | حقل إدخال | `TextField` | `السبب (اختياري)` | — | onPressed | 345 |
| PAY-UI-1E9A85 | نافذة/ورقة | `showModalBottomSheet` | — | — | استدعاء | 220 |
| PAY-UI-013F02 | نافذة/ورقة | `showModalBottomSheet` | — | — | استدعاء | 323 |

### `lib/features/requested_money/screens/outgoing_requests_screen.dart` — PAY-SCR-84F933 — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-2C3DA7 | زر | `TextButton` | `سحب الطلب` | — | onPressed شرطي | 136 |
| PAY-UI-5AA6CD | زر | `TextButton` | `تراجع` | تراجع | onPressed | 153 |
| PAY-UI-120B98 | زر | `FilledButton` | `اسحب` | — | onPressed | 154 |
| PAY-UI-CAD9D3 | زر | `FilledButton` | `اسحب` | — | — | 156 |
| PAY-UI-750F74 | نافذة/ورقة | `showDialog` | `سحب الطلب؟` | — | onPressed | 147 |

### `lib/features/requested_money/screens/payment_request_create_screen.dart` — PAY-SCR-1EB8E9 — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-8E43ED | زر | `IconButton` | `إغلاق` | إغلاق | onPressed | 146 |
| PAY-UI-0A4721 | زر | `FilledButton` | `تأكيد وإرسال الطلب` | — | onPressed | 203 |
| PAY-UI-C78937 | زر | `FilledButton` | — | — | — | 207 |
| PAY-UI-D48F83 | زر | `AmialButton` | `متابعة وتأكيد` | — | onPressed | 384 |
| PAY-UI-523B8A | حقل إدخال | `TextField` | `777 000 000 أو رقم الحساب` | — | — | 315 |
| PAY-UI-C99621 | حقل إدخال | `TextField` | — | — | — | 339 |
| PAY-UI-74A0DB | حقل إدخال | `TextField` | `مثال: حصتي من فاتورة الكهرباء` | — | — | 369 |
| PAY-UI-9E5E5E | نافذة/ورقة | `showModalBottomSheet` | — | — | استدعاء | 134 |

### `lib/features/requested_money/screens/payment_request_show_screen.dart` — PAY-SCR-14A9C3 — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-5FBF96 | زر | `TextButton` | `تراجع` | تراجع | onPressed | 66 |
| PAY-UI-B5F6E8 | زر | `FilledButton` | `نعم، إلغاء` | — | onPressed | 67 |
| PAY-UI-9329C2 | زر | `FilledButton` | `نعم، إلغاء` | — | onPressed | 68 |
| PAY-UI-3D80CB | زر | `AmialButton` | `طلباتي المرسلة` | — | onPressed | 233 |
| PAY-UI-E90613 | زر | `AmialButton` | `تم` | — | onPressed | 239 |
| PAY-UI-EFB67F | زر | `TextButton` | `سحب الطلب` | — | onPressed شرطي | 247 |
| PAY-UI-C991BA | زر | `AmialButton` | `نسخ الرمز` | — | onPressed | 330 |
| PAY-UI-640912 | زر | `AmialButton` | `مشاركة الرابط` | — | onPressed شرطي | 338 |
| PAY-UI-F419A3 | زر | `TextButton` | `إلغاء الطلب` | — | onPressed شرطي | 348 |
| PAY-UI-079FAE | نافذة/ورقة | `Get.dialog` | `إلغاء الطلب؟` | — | onPressed | 62 |

### `lib/features/requested_money/screens/requested_money_list_screen.dart` — PAY-SCR-34F55C — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-D8F56B | زر | `TextButton` | — | — | onPressed | 151 |
| PAY-UI-A4E94F | زر | `TextButton` | — | — | — | 153 |

### `lib/features/requested_money/widgets/confirmation_dialog.dart` — — — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-653B14 | زر | `CustomButtonWidget` | `no` | لا | onTap | 66 |
| PAY-UI-D58341 | زر | `CustomButtonWidget` | `yes` | نعم | onTap | 71 |
| PAY-UI-F8C498 | حقل إدخال | `CustomPasswordFieldWidget` | — | — | — | 54 |

### `lib/features/requested_money/widgets/custom_button.dart` — — — **غير موصول (UNREACHABLE)**

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-B9EBAD | زر | `TextButton` | — | — | onPressed شرطي | 17 |
| PAY-UI-CB84C2 | زر | `TextButton` | — | — | — | 19 |

### `lib/features/requested_money/widgets/requested_money_card.dart` — — — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-A95443 | عنصر قابل للنقر | `CustomInkWellWidget` | — | — | onTap | 130 |
| PAY-UI-1773A0 | عنصر قابل للنقر | `CustomInkWellWidget` | `are_you_sure_want_to_denied` | هل أنت متأكد أنك تريد رفض الطلب من هذا الرقم؟ | onTap | 162 |
| PAY-UI-2D38CB | نافذة/ورقة | `showCustomSnackBarHelper` | `enter_your_pin` | أدخل رمزك السري | استدعاء | 140 |
| PAY-UI-427503 | نافذة/ورقة | `showDialog` | `are_you_sure_want_to_denied` | هل أنت متأكد أنك تريد رفض الطلب من هذا الرقم؟ | استدعاء | 164 |

### `lib/features/requested_money/widgets/requested_money_screen.dart` — PAY-SCR-7D5611 — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-8F46DB | عنصر قابل للنقر | `ListTile` | — | — | — | 161 |
