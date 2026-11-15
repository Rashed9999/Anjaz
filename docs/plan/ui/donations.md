# ملحق — سجل العناصر التفاعلية: وحدة `donations`

> **مولَّد آليًّا** بتاريخ 2026-11-15 من شجرة `1a1d8c65` (مطابقة المحتوى للفرع الافتراضي `claude/project-code-review-yjagv@751ea393`). دليل **ساكن** فقط: لا يثبت تشغيلًا ولا ضغط زر ولا حالة إنتاج. المعرّفات مشتقة من تجزئة ثابتة (لا تتغير بإضافة صفوف). لا يُحذف شيء استنادًا إلى هذا الملف.

استخراج آلي بالتعابير النمطية من مصدر Dart: نوع الودجت، مفتاح/نص التسمية القريب، وترجمته من `assets/language/ar.json` إن وُجد المفتاح. **لا يثبت** الشروط الفعلية ولا رد الخادم ولا حالات التحميل؛ كل صف `INTERACTION_UNVERIFIED`. الأعمدة الناقصة عن القالب المعياري (التحقق/رد النجاح/رد الخطأ) تُملأ عند الفحص التفاعلي — انظر المهمة `PAY-T-012` في `plan.md`.


### `lib/features/donations/screens/campaign_detail_screen.dart` — PAY-SCR-90690B — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-4E9C59 | زر | `ElevatedButton` | `تأكيد التبرع` | — | onPressed | 135 |
| PAY-UI-5FFEEC | زر | `ElevatedButton` | — | — | — | 167 |
| PAY-UI-CEE139 | زر | `TextButton` | `حسناً` | — | onPressed | 194 |
| PAY-UI-FA81A4 | زر | `ElevatedButton` | `إعادة المحاولة` | — | onPressed | 224 |
| PAY-UI-AEABE1 | زر | `ElevatedButton` | `تبرع الآن` | — | onPressed | 419 |
| PAY-UI-71777F | زر | `ElevatedButton` | — | — | — | 424 |
| PAY-UI-AEF66F | عنصر قابل للنقر | `InkWell` | — | — | onTap | 75 |
| PAY-UI-109446 | حقل إدخال | `TextFormField` | `المبلغ (ر.ي) *` | — | — | 96 |
| PAY-UI-05ED30 | حقل إدخال | `TextFormField` | `رسالة دعاء/تعليق (اختياري)` | — | — | 111 |
| PAY-UI-07F2F1 | حقل إدخال | `CheckboxListTile` | `تبرع باسم مجهول` | — | onChanged | 120 |
| PAY-UI-13B999 | نافذة/ورقة | `showModalBottomSheet` | — | — | استدعاء | 32 |
| PAY-UI-457D9E | نافذة/ورقة | `showDialog` | `تقبل الله منك` | — | onPressed | 185 |

### `lib/features/donations/screens/campaigns_list_screen.dart` — PAY-SCR-582E35 — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-1BE17B | عنصر قابل للنقر | `InkWell` | — | — | onTap | 46 |

### `lib/features/donations/screens/donations_home_screen.dart` — PAY-SCR-477C2C — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-AB53BE | زر | `IconButton` | `تبرعاتي` | — | onPressed | 38 |
| PAY-UI-B44277 | عنصر قابل للنقر | `InkWell` | — | — | onTap | 180 |
| PAY-UI-1E6648 | عنصر قابل للنقر | `InkWell` | — | — | onTap | 224 |
| PAY-UI-EE3C5C | عنصر قابل للنقر | `InkWell` | — | — | onTap | 299 |

### `lib/features/donations/screens/my_donations_screen.dart` — PAY-SCR-5B25F5 — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-EC4168 | زر | `ElevatedButton` | `إعادة المحاولة` | — | onPressed | 41 |
