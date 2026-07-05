# تقرير تدقيق ما قبل الإنتاج — أميال باي
### Production Readiness Audit — بمنهجية Principal Software Engineer

**التاريخ:** 2026-07-05 · **النطاق:** الباكند (Laravel 12 / PHP 8.4) + تطبيق Flutter + Docker + الاختبارات + السكربتات
**المنهج:** فحص الكود الفعلي بأدوات حقيقية (مخطط اعتماديات، grep، route:list، md5) — **لا تقديرات ولا افتراضات**.
**الحالة:** تقرير فقط — **لم يُصلَح أي شيء**، بانتظار موافقتك على خطة الإصلاح.

---

## 0) حالة الإصلاح (AMIAL-AUDIT-FIX-001) — مُنفَّذ ومُتحقَّق

| البند | الحالة | التحقّق |
|---|---|---|
| 🟠 H-1 محرّك AML الميت | ✅ **أُصلح ووُصِّل** | 6 اختبارات + المحرّك يُفحَص كل تحويل (وضع ظل آمن) |
| 🟠 H-2 أسرار الديمو | ✅ **أُصلح** | قيم محلّية-فقط واضحة + تأكيد أمان الإنتاج |
| 🟠 H-3 شاشات Flutter | ⚠️ **جزئي** | شاشتا تقسيم الفاتورة وُصِلتا (flutter analyze نظيف)؛ 4 مؤجّلة لقرار منتج/جهاز |
| 🟡 M-3 أصناف ميتة | ✅ **حُذفت 7** | 830 اختباراً أخضر بعد الحذف |
| 🟡 M-4 أصول غير مستخدمة | ✅ **حُذفت 8** | pubspec يُعلن مجلّدات لا ملفات |
| 🟡 M-2 ملفات ضخمة | ⏸️ **مؤجّل بتوصية** | تقسيم محرّك المال refactor عالي الخطورة بلا فائدة وظيفية قبل التجربة — يُؤجَّل لِما بعد التجربة |
| 🟡 M-1 google-services.json | ℹ️ **مقبول** | تهيئة عميل أندرويد قياسية (مقيّدة ببصمة SHA) |
| ⚪ L-* بسيطة | ℹ️ **مقبولة** | TODO انخفض 3←1؛ تحذيرات PHPUnit وrisky تجميلية |

**الأثر:** اليتامى انخفضت **22 ← 3** (419/422 صنف قابل للوصول)، والمحرّك الأمني الأهم (AML) أصبح حيّاً. الباقي المؤجّل إمّا refactor عالي الخطورة أو تجميلي.

---

## 1) الملخّص التنفيذي — عدّاد المشاكل

| الخطورة | العدد | ماذا تعني |
|---|---|---|
| 🔴 **حرِج (يمنع التشغيل)** | **0** | لا يوجد ما يمنع الإنتاج من العمل: النظام يُقلع، الـ716 مساراً تُحلّ لمعالجاتها، و**824 اختباراً تنجح**. |
| 🟠 **عالٍ** | **3** | محرّك AML ميت، بيانات اعتماد تجريبية مرفوعة، 6 شاشات مبنية غير موصولة. |
| 🟡 **متوسط** | **4** | google-services.json مرفوع، ملفات ضخمة، أصناف ميتة أخرى، أصول Flutter غير مستخدمة. |
| ⚪ **بسيط** | **4** | 3 تعليقات TODO، تحذيرات PHPUnit، 17 اختبار "risky"، اعتماد الاختبارات على تشغيل MariaDB. |

**الخلاصة:** المشروع **لا يحوي عائقاً يمنع تشغيله في الإنتاج**. المشاكل العالية ليست أعطالاً بل **نظافة وإكمال ربط** — مهمة قبل عرض بنكي لكنها لا تكسر النظام.

---

## 2) مخطط الاعتماديات (Dependency Graph)

بُني بأداة `scripts/audit/php_depgraph.php` (مرفقة): 427 صنفاً، 306 نقطة دخول، **405 قابلة للوصول**، **22 يتيمة** (مؤكَّدة يدوياً بـ grep — صفر مراجع خارجية لكلٍّ منها).

- **دوائر الاعتماد (Circular):** لا توجد دائرة حقيقية تكسر حقن التبعيات — الإطار يُقلع و824 اختباراً تنجح، وهو الدليل القاطع. الدوائر التي رصدها الفحص الساكن كلها بين نماذج Eloquent عبر العلاقات (belongsTo/hasMany) وهي **طبيعية وغير ضارة**.

---

## 3) 🟠 المشاكل العالية (High)

### H-1 — محرّك مكافحة غسل الأموال (AML) مبنيّ لكنه ميت بالكامل
**~15 ملفاً** بُنيت وربطت بعضها ببعض داخلياً، لكن `AmlScreeningService` **لا يستدعيه أي مسار أو Job أو Command أو Provider** (تحقّق: `grep AmlScreeningService` خارج ملفه = صفر).

الملفات:
```
app/Aml/RuleEvaluationResult.php
app/Aml/TransactionContext.php
app/Aml/Rules/AmlRuleInterface.php
app/Aml/Rules/AgentVelocityRule.php
app/Aml/Rules/CircularTransferRule.php
app/Aml/Rules/DailyAggregateRule.php
app/Aml/Rules/MaxSingleTransactionRule.php
app/Aml/Rules/NewAccountHighValueRule.php
app/Aml/Rules/OffHoursRule.php
app/Aml/Rules/StructuringRule.php
app/Aml/Rules/VelocityRule.php
app/Services/AmlScreeningService.php
app/Services/AmlRulesCacheService.php
app/Services/CacheService.php          (يُستخدم فقط من AmlRulesCacheService الميت)
app/Services/VelocityCounterService.php
```
**لماذا خطير قبل البنك:** البنك سيسأل عن AML أولاً. وجود محرّك AML **مكتوب لكنه معطّل** أسوأ من غيابه — يوحي بأن الضوابط "موجودة" وهي فعلياً لا تعمل على أي معاملة. **قرار مطلوب:** إمّا **توصيله** بمسار المعاملات (يصبح ميزة امتثال حقيقية)، أو **حذفه** حتى لا يُضلّل.

### H-2 — بيانات اعتماد تجريبية مرفوعة في المستودع
`01_backend/.env.demo` يحوي قيماً حقيقية (وليست عناصر نائبة):
```
DB_PASSWORD=amial_pass_2026
META_WA_VERIFY_TOKEN=amyal_webhook_2026
```
`APP_KEY` فارغ (جيّد، لا تسريب مفتاح تشفير). لا مفاتيح خاصة ولا مفاتيح إنتاج مسرّبة (تحقّقت). **لكن** أي قيمة تشبه بيانات اعتماد يجب أن تكون عنصراً نائباً. **الإصلاح:** نقلها إلى `.env.example` بقيم `changeme` وحذف `.env.demo` من التتبّع.

### H-3 — 6 شاشات Flutter مبنية لكنها غير موصولة بالتنقّل
مؤكَّد: كل شاشة مذكورة في ملفها فقط وغير موجودة في `lib/helper/route_helper.dart`:
```
lib/features/auth/screens/unified_login_screen.dart        (UnifiedLoginScreen)
lib/features/donations/screens/donations_home_screen.dart  (DonationsHomeScreen)
lib/features/merchant/screens/cashier_refund_screen.dart   (CashierRefundScreen)
lib/features/merchant/screens/merchant_pay_screen.dart     (MerchantPayScreen)
lib/features/merchant/screens/split_bill_create_screen.dart(SplitBillCreateScreen)
lib/features/merchant/screens/split_bill_my_shares_screen.dart (SplitBillMySharesScreen)
```
**التفسير:** ميزات لها باكند واختبارات (المرتجعات، الدفع، تقسيم الفاتورة، التبرعات) لكن **واجهتها لا يمكن للمستخدم الوصول إليها**.

**✅ حالة الإصلاح (AMIAL-AUDIT-FIX-001):**
- `SplitBillCreateScreen` + `SplitBillMySharesScreen` → **وُصِلتا** بلوحة التاجر (روابط "تقسيم فاتورة" و"حصصي")، مُتحقَّق بـ `flutter analyze` (No issues found).
- `UnifiedLoginScreen` → **ليست ميتة**: لها باكند كامل (`unified-auth.php` + `unified_auth_controller`) — ميزة تسجيل دخول موحّد **غير مكتملة الربط**، لا تُحذف. قرار المنتج: اعتمادها بدل شاشة الدخول الحالية أم تأجيلها.
- `DonationsHomeScreen` + `MerchantPayScreen` (جانب العميل) + `CashierRefundScreen` (تحتاج `saleUlid` من شاشة تفاصيل بيع غير موجودة بعد) → **مؤجّلة**: تحتاج قراراً حول موضع الدخول في شبكة خدمات العميل، ولا يمكن التحقّق منها إلا على جهاز فعلي. لم تُربط عشوائياً تفادياً لتنقّل معطوب.

---

## 4) 🟡 المشاكل المتوسطة (Medium)

### M-1 — google-services.json مرفوع
`02_flutter_app/android/app/google-services.json` (تهيئة Firebase للأندرويد، تحوي مفتاح API عميل). ممارسة شائعة للأندرويد (مقيّدة ببصمة SHA)، لكن قد يعلّق عليها مدقّق البنك. يُفضّل تمريرها عبر متغيّرات بناء CI.

### M-2 — ملفات ضخمة تحتاج تقسيم (>600 سطر)
```
1303  app/Traits/TransactionTrait.php            ← الأولوية القصوى للتقسيم
 919  app/Services/Whatsapp/WhatsappBotService.php
 918  app/CentralLogics/Helpers.php
 858  app/Http/Controllers/Api/V1/Amial/SupportConsoleController.php
 802  app/Services/SafePaymentService.php
 785  app/Http/Controllers/Admin/LandingPageSettingsController.php
 772  app/Http/Controllers/Admin/BusinessSettingsController.php
 701  app/Http/Controllers/Api/V1/Customer/TransactionController.php
 657  app/Http/Controllers/Api/V1/Amial/WholesaleController.php
 637  app/Http/Controllers/Api/V1/Amial/FuelStationController.php
 618  app/Traits/SmsGateway.php
```

### M-3 — أصناف ميتة أخرى (خارج AML) — آمنة للحذف بعد التأكيد
```
app/Services/MonitoringService.php                 (0 مراجع)
app/Logging/StructuredLogger.php + app/Traits/Processor.php  (زوج ميت)
app/Http/Resources/HelpTopicResource.php           (0 مراجع)
app/Mail/PasswordResetMail.php                     (0 مراجع — إعادة التعيين تعمل عبر OTP في PasswordResetController)
app/CentralLogics/Concerns/AmialHelperPatchTrait.php + app/Services/EnvironmentGuardService.php  (زوج ميت)
```

### M-4 — 8 أصول Flutter غير مستخدمة
```
assets/animationFile/qr_scan_animation.json
assets/image/Other_gender.png
assets/image/add_money_bonus.png
assets/image/english.png
assets/image/refer-a-friend.png
assets/image/reqmoney3.png
assets/image/right_arrow.png
assets/image/united_kingdom.png
```
(ملاحظة صدق: قد تُبنى بعض المسارات ديناميكياً كسلاسل نصّية؛ يجب تأكيد كل أصل قبل حذفه.)

---

## 5) ⚪ المشاكل البسيطة (Low)

- **L-1:** 3 تعليقات TODO/FIXME فقط في `app/Services/ZoneAssignmentService.php` — نظافة ممتازة (نادر في مشروع بهذا الحجم).
- **L-2:** تحذيرات PHPUnit: توثيق الاختبارات بـ doc-comment `/** @test */` مهجور في PHPUnit 12 — يُنقل إلى Attributes. تجميلي.
- **L-3:** 17 اختباراً "risky" (بلا تأكيدات فعلية أو تطبع مخرجات) — لا تفشل، لكن يُفضّل تنظيفها.
- **L-4:** الاختبارات تعتمد على تشغيل MariaDB (بيئي). فشل `ConcurrentSendMoneyTest` عند تشغيله منفرداً كان بسبب إعادة تشغيل قاعدة البيانات لحظتها — **يمرّ 4/4 عند التشغيل النظيف** (بيئة، لا كود).

---

## 6) سلامة الربط (تحقّق إيجابي — نقاط قوّة)

| المكوّن | النتيجة |
|---|---|
| المسارات (Routes) | **716 مساراً تُحلّ لمعالجاتها** — `route:list` يُصيّرها كلها بلا خطأ. (فحص أوّلي أظهر "710 مكسورة" لكنه **إيجابي كاذب** بسبب غياب الـautoload في عملية فرعية — كل الأصناف موجودة، تحقّقت يدوياً.) |
| Commands | 12 أمراً، كلها مجدولة/مربوطة |
| Jobs | 7 مهام، مُرسَلة (dispatch) من 13 ملفاً |
| Migrations | 146 هجرة |
| الأمان | تشفير PII (AES-256-GCM) بمفاتيح من env، RBAC، Rate-limit، سلسلة تجزئة لسجل التدقيق — كلها مطبّقة (من العمل السابق) |
| الأسرار الإنتاجية | **لا مفاتيح خاصة ولا مفاتيح إنتاج مسرّبة** — `.gitignore` يحمي `.env` ومفاتيح OAuth وstorage |

---

## 7) الإجابات المباشرة على أسئلتك

**الملفات التي يمكن حذفها بأمان** (بعد قرار AML): ~22 ملف PHP ميت (القسم H-1 + M-3) + 8 أصول Flutter (M-4).
**الملفات التي تحتاج إعادة هيكلة/تقسيم:** 11 ملفاً (M-2)، وأولها `TransactionTrait.php` (1303 سطراً).
**الملفات التي تحتاج تنظيف:** ملفات `.env.demo` (H-2)، واختبارات الـ17 risky (L-3).
**أي خطر يمنع التشغيل في الإنتاج:** **لا يوجد.** النظام يُقلع ويعمل و824 اختباراً تنجح.

---

## 8) نطاق لم يكتمل بدقّة (صدق كامل)

بعض الفحوص الدقيقة تُركت لتمريرة أعمق لأنها تحتاج أداة مخصّصة لتفادي الإيجابيات الكاذبة، ولا أريد إعطاءك رقماً مُختلقاً:
- **العدّ الدقيق للـ imports غير المستخدمة** (`use` بلا استعمال): يحتاج محلّل AST دقيق (grep البسيط يعطي إيجابيات كاذبة مع الـtraits وأسماء الأصناف غير الحسّاسة لحالة الأحرف). **مرشّح لتمريرة مخصّصة.**
- **تبعيات Flutter غير المستخدمة في pubspec:** تحتاج مطابقة `package:<name>` عبر كل lib — لم تكتمل.

سأبنيهما بأداة دقيقة في مرحلة الإصلاح إن وافقت.

---

## 9) خطة الإصلاح المقترحة (بانتظار موافقتك)

1. **قرار AML (H-1):** توصيل المحرّك بمسار المعاملات ← يصبح ميزة امتثال حقيقية للبنك. *(أوصي بالتوصيل لا الحذف — لأن البنك سيطلبه.)*
2. **تنظيف الأسرار (H-2):** حذف `.env.demo`، وضع عناصر نائبة في `.env.example`.
3. **ربط شاشات Flutter (H-3):** وصل الشاشات الست بالتنقّل، أو إزالتها.
4. **حذف الأصناف والأصول الميتة (M-3, M-4)** بعد تأكيد فردي.
5. **تقسيم الملفات الضخمة (M-2)** بدءاً بـ TransactionTrait.
6. **تنظيفات بسيطة (L-1..L-4).**

**قل لي بأي بند أبدأ، وسأنفّذه بالمنهجية المعتادة: إصلاح ← اختبار حقيقي ← دفع للمستودع ← حزمة محدّثة.**
