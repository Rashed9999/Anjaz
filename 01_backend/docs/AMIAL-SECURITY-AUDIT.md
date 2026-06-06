# تقرير التدقيق الأمني — Amial Pay (مراجعة ساكنة للكود)

> تدقيق دفاعي على كودك أنت، بهدف إيجاد الثغرات وإصلاحها قبل الإطلاق
> (التزام §"لا إطلاق قبل إغلاق الأمن"). هذا **تحليل ساكن للكود**، وليس
> اختباراً حيّاً على خادم. لم تُشغَّل أدوات حيّة.

---

## 1) فحص الملفات/الأنماط الضارة — **نظيف ✅**
| الفحص | النتيجة |
|---|---|
| تنفيذ أوامر (eval, exec, shell_exec, system, passthru, popen, proc_open, assert) | ✅ لا شيء |
| تعتيم/فك تشفير مشبوه (gzinflate, str_rot13, hex chains) | ✅ لا شيء (`base64_decode` فقط داخل خدمة التشفير الشرعية) |
| `unserialize` لمدخلات مستخدم | ✅ لا شيء |
| استدعاءات remote خفية (file_get_contents http, curl_exec, fsockopen) | ✅ لا شيء |
| أسرار/مفاتيح مزروعة (hardcoded) | ✅ لا شيء |
| استدعاءات ديناميكية | ✅ آمنة (`$logLevel` من match بقائمة بيضاء؛ `$action` closure داخلي مع تحقق صلاحية) |

**لا أبواب خلفية، لا كود ضار.** الكود واعٍ أمنياً: `EnvironmentGuardService`
يمنع كتابة env في الإنتاج، `BlockInProduction` لمسارات install/update،
`FinancialGuardService` يستخدم `lockForUpdate` + `DB::transaction`، idempotency،
rate limiting، zone policy، فصل PIN/OTP.

---

## 2) الثغرات المكتشفة + حالة الإصلاح

### 🟠 [أُصلِحت] IDOR في عرض الفاتورة المقسّمة
`GET split-bills/{ulid}` كان يعرض الفاتورة + **أرقام هواتف المشاركين ومبالغهم
لأي مستخدم مصادَق**. الإصلاح: تحقّق أن المستدعي تاجر الفاتورة أو موظف POS التابع
أو أحد المشاركين، وإلا 403. *(كودي — أُصلح في `SplitBillController::show`)*

### 🔴 [أُصلِحت] fail-open في حدود إيداع الوكيل
`assertCashInAllowed` كانت **تسمح بإيداع غير محدود** لأي وكيل بلا `AgentProfile`
(`return` مبكر بلا حدود). الإصلاح: fail-safe بسقف افتراضي من
`config('amial.agent.default_single_transaction_limit', 100000)`.
⚠️ **اضبط القيمة في config حسب سوقك، ثم اختبرها.**

### 🟡 [أُصلِحت] وحدة عملة خاطئة
رسائل الحدود كانت تقول **«ر.س» (ريال سعودي)** — بقايا من Cash6 الأصلي.
صُحّحت إلى **«ر.ي»** في KycTier, AgentNetwork, Donations, AmlScreening, AML rules.

### 🟡 [توصية] مسارات الوكيل بلا فرض دور صريح
endpoints الوكيل (`float-dashboard, topup-request, settlements`) خلف `auth:api`
لكن بلا middleware يفرض أن المستخدم **وكيل**. معظمها self-scoped (تُرجع بيانات
المستخدم نفسه) فالخطر محدود، لكن `topup-request` يستحق بوابة دور.
**أُرفق `app/Http/Middleware/EnsureAgent.php` جاهز** — فعّله:
```php
// bootstrap/app.php
$middleware->alias(['amial.agent' => \App\Http\Middleware\EnsureAgent::class]);
// routes/api/amial.php — مجموعة agent
Route::prefix('agent')->middleware('amial.agent')->name('amial.agent.')->group(...)
```
⚠️ تأكّد أولاً أن دور الوكيل = `type 1` في بياناتك، ثم اختبر ألا يُحجب وكيل شرعي.

### 🟡 [ملاحظة معمارية] دوال الـ trait المالية لا تفرض الأدوار
`merchant_payment_transaction` / `customer_cash_out_transaction` تحرّك المال بين
أي user-id بلا فحص دور — تعتمد على المتحكّم/المسار للتفويض. متحكّماتي تفحص
(MerchantProfile, PosUser, ملكية الحصة). **تأكّد أن مسار `cashOut` في
TransactionController الأصلي يفرض PIN العميل ودور الوكيل المستلِم** (الملف خارج
نسخة العمل، لم أتمكّن من فحصه).

---

## 3) شاشة الوكيل تحديداً (سؤالك)
- ✅ السحب (`cash_out`) خلف `amial.zone:cash_out`؛ يخصم العميل ويضيف للوكيل عبر
  `FinancialGuardService` المقفول (آمن من السباق والرصيد السالب).
- ✅ `settlements` تُرشَّح بـ `agent_user_id = المستخدم` (لا IDOR).
- ✅ `distributor-network` يرفض غير الموزّع (403).
- 🔴 الثغرة الحقيقية كانت **fail-open في الحدود** (أُصلحت أعلاه).
- 🟡 لا بوابة دور صريحة (EnsureAgent مرفق).
- ⚠️ **تحقّق في المشروع المدمج**: هل إيداع الوكيل (cash-in) يتطلب تأكيد العميل
  (PIN/OTP)؟ بدونه قد يضيف وكيل رصيداً لمتواطئ دون كاش فعلي (مخاطرة احتيال تشغيلي).
  لم أجد endpoint cash-in في نسخة العمل — تأكّد من وجوده وحمايته.

---

## 4) عن «حذف الأسطر الزائدة»
حذف أسطر جماعي عبر 450+ ملفاً **بلا تشغيل اختبارات خطر** (قد يكسر منطقاً)،
ويخالف مبدأ «لا ادعاء بلا تحقق». لذا اكتفيت بتنظيفات **آمنة ومستهدفة** (العملة،
IDOR، fail-open). للتنظيف الآلي الآمن شغّل عندك:
```
./vendor/bin/pint            # تنسيق PHP + إزالة استيرادات/أسطر زائدة بأمان
flutter analyze              # كشف كود Dart الميت/غير المستخدم
```

---

## 5) توصيات قبل الإطلاق
1. شغّل الاختبارات الـ40 + `pint` + `flutter analyze`، وأكّد النتائج.
2. فعّل `EnsureAgent` بعد التحقق من دور الوكيل.
3. اضبط `config/amial.php`: حدود الوكيل الافتراضية + نسبة الدفع الآمن.
4. تأكّد من حماية مسار cash-in للوكيل (PIN العميل) في المشروع المدمج.
5. أضف اختبار تكامل لـ IDOR في `split-bills/{ulid}` (بـ actingAs).
6. مراجعة أمنية حيّة (DAST/pentest فعلي) على بيئة staging قبل production —
   التحليل الساكن لا يغني عنها.

## التحقق (صدق)
- ✅ فحص بنيوي ناجح لكل الملفات المعدّلة.
- ⚠️ لم تُشغَّل `php artisan test` ولا أدوات حيّة (لا PHP في بيئتي).
