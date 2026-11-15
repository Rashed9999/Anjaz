# ملحق — سجل العناصر التفاعلية: وحدة `family_fund`

> **مولَّد آليًّا** بتاريخ 2026-11-15 من شجرة `1a1d8c65` (مطابقة المحتوى للفرع الافتراضي `claude/project-code-review-yjagv@751ea393`). دليل **ساكن** فقط: لا يثبت تشغيلًا ولا ضغط زر ولا حالة إنتاج. المعرّفات مشتقة من تجزئة ثابتة (لا تتغير بإضافة صفوف). لا يُحذف شيء استنادًا إلى هذا الملف.

استخراج آلي بالتعابير النمطية من مصدر Dart: نوع الودجت، مفتاح/نص التسمية القريب، وترجمته من `assets/language/ar.json` إن وُجد المفتاح. **لا يثبت** الشروط الفعلية ولا رد الخادم ولا حالات التحميل؛ كل صف `INTERACTION_UNVERIFIED`. الأعمدة الناقصة عن القالب المعياري (التحقق/رد النجاح/رد الخطأ) تُملأ عند الفحص التفاعلي — انظر المهمة `PAY-T-012` في `plan.md`.


### `lib/features/family_fund/screens/create_fund_screen.dart` — PAY-SCR-3BC835 — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-E5F1B5 | زر | `ElevatedButton` | — | — | onPressed شرطي | 152 |
| PAY-UI-CC2A1E | زر | `ElevatedButton` | — | — | — | 154 |
| PAY-UI-81A3B3 | حقل إدخال | `TextFormField` | `اسم الصندوق *` | — | — | 89 |
| PAY-UI-BBBC74 | حقل إدخال | `TextFormField` | `الوصف (اختياري)` | — | — | 106 |
| PAY-UI-4B56AF | حقل إدخال | `TextFormField` | `المبلغ المستهدف (ر.ي) *` | — | — | 119 |
| PAY-UI-27AB0C | حقل إدخال | `SwitchListTile` | `موافقة المالك للصرف` | — | onChanged | 138 |

### `lib/features/family_fund/screens/fund_detail_screen.dart` — PAY-SCR-6062FC — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-7A0FF9 | زر | `ElevatedButton` | `تأكيد المساهمة` | — | onPressed | 76 |
| PAY-UI-98BA85 | زر | `ElevatedButton` | — | — | — | 92 |
| PAY-UI-A9DE43 | زر | `TextButton` | `إلغاء` | إلغاء | onPressed | 146 |
| PAY-UI-E32684 | زر | `ElevatedButton` | `إرسال الدعوة` | — | onPressed | 150 |
| PAY-UI-6495CC | زر | `ElevatedButton` | `إرسال الدعوة` | — | — | 152 |
| PAY-UI-F4F57C | زر | `TextButton` | `إلغاء` | إلغاء | onPressed | 246 |
| PAY-UI-442AD7 | زر | `ElevatedButton` | `صرف` | — | onPressed | 249 |
| PAY-UI-131F42 | زر | `ElevatedButton` | `صرف` | — | — | 254 |
| PAY-UI-B07EB8 | زر | `ElevatedButton` | `مساهمة` | — | onPressed | 375 |
| PAY-UI-8F4650 | زر | `ElevatedButton` | — | — | — | 379 |
| PAY-UI-D57030 | زر | `OutlinedButton` | `دعوة` | — | onPressed | 390 |
| PAY-UI-46E12A | زر | `OutlinedButton` | — | — | — | 394 |
| PAY-UI-D8D2AB | زر | `OutlinedButton` | `صرف` | — | onPressed | 404 |
| PAY-UI-E2C9CA | زر | `OutlinedButton` | — | — | — | 408 |
| PAY-UI-2089A4 | عنصر قابل للنقر | `ListTile` | — | — | — | 584 |
| PAY-UI-34B3D0 | حقل إدخال | `TextFormField` | `المبلغ (ر.ي) *` | — | — | 51 |
| PAY-UI-AF96A8 | حقل إدخال | `TextFormField` | `بيان (اختياري)` | — | — | 65 |
| PAY-UI-1CD7E2 | حقل إدخال | `TextField` | `رقم الجوال` | — | onPressed | 134 |
| PAY-UI-A7E9E9 | حقل إدخال | `DropdownButtonFormField` | `المستفيد` | — | — | 208 |
| PAY-UI-0BE2C9 | حقل إدخال | `TextFormField` | `المبلغ (ر.ي) *` | — | — | 225 |
| PAY-UI-2FBE2F | حقل إدخال | `TextFormField` | `بيان (اختياري)` | — | onPressed | 238 |
| PAY-UI-1FC7F1 | نافذة/ورقة | `showModalBottomSheet` | — | — | استدعاء | 31 |
| PAY-UI-2DBBAA | نافذة/ورقة | `showDialog` | `دعوة عضو للصندوق` | — | استدعاء | 124 |
| PAY-UI-81EAAD | نافذة/ورقة | `showDialog` | `صرف من الصندوق` | — | استدعاء | 201 |

### `lib/features/family_fund/screens/my_funds_screen.dart` — PAY-SCR-3ACE32 — موصول

| UI ID | النوع | الودجت | مفتاح/نص | النص العربي | المعالج | السطر |
|---|---|---|---|---|---|---:|
| PAY-UI-FACACA | زر | `FloatingActionButton` | — | — | onPressed | 43 |
| PAY-UI-338D99 | زر | `ElevatedButton` | `قبول` | — | onPressed | 181 |
| PAY-UI-35E8FF | زر | `ElevatedButton` | `قبول` | — | — | 183 |
| PAY-UI-BDD092 | عنصر قابل للنقر | `InkWell` | — | — | onTap | 210 |
